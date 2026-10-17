#!/usr/bin/env python3
"""Read-only Sandbox acceptance evidence; no key reads, writes or email sends."""
import json
import os
import re
import sys
from datetime import datetime, timezone
import roundcube_prepare as base
import roundcube_activate as activation

JOURNAL = re.compile(r'[a-f0-9]{64}\.json\Z')
SINCE = datetime(2026, 10, 4, 13, 26, 27, tzinfo=timezone.utc)
UNTIL = datetime.fromtimestamp(activation.EXPIRES, timezone.utc)


class Client(activation.Client):
    def __init__(self, token):
        super().__init__(token)
        self.journals = set()

    def call(self, operation, **values):
        path = values.get('path')
        if operation == 'list':
            base.require(values == {'directory': base.PRIVATE}, 'inventory_not_allowed')
        elif operation in ('read', 'stat'):
            base.require(set(values) == {'path'}, 'unexpected_parameters')
            allowed = {base.PLUGIN + '/' + n for n in base.HASHES} | {activation.TARGET}
            if operation == 'stat':
                allowed |= {base.PRIVATE, base.PLUGIN}
            base.require(path in allowed or path in self.journals, 'path_not_allowed')
            if path in self.journals:
                directory, filename = path.rsplit('/', 1)
                base.require(directory == base.PRIVATE and JOURNAL.fullmatch(filename), 'journal_not_allowed')
                if operation == 'stat':
                    return self.request('/json-api/cpanel', {
                        'cpanel_jsonapi_user': 'welcome', 'cpanel_jsonapi_apiversion': 2,
                        'cpanel_jsonapi_module': 'Fileman', 'cpanel_jsonapi_func': 'statfiles',
                        'dir': directory, 'files': filename}, 2)
                return self.request('/json-api/uapi_cpanel', {
                    'api.version': 1, 'cpanel.user': 'welcome', 'cpanel.module': 'Fileman',
                    'cpanel.function': 'get_file_content', 'dir': directory, 'file': filename,
                    'from_charset': 'UTF-8', 'to_charset': 'UTF-8'}, 3)
        else:
            raise base.PreparationError('operation_not_allowed')
        return super().call(operation, **values)


def stamp(value):
    base.require(isinstance(value, str) and bool(re.fullmatch(
        r'\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d(?:\+00:00|Z)', value)), 'invalid_journal_time')
    try:
        return datetime.fromisoformat(value.replace('Z', '+00:00'))
    except ValueError:
        raise base.PreparationError('invalid_journal_time') from None


def summarize(source):
    base.require(len(source) <= 4096, 'journal_too_large')
    try:
        events = [json.loads(line) for line in source.splitlines() if line.strip()]
    except Exception:
        raise base.PreparationError('invalid_journal_json') from None
    base.require(1 <= len(events) <= 2 and all(isinstance(x, dict) for x in events), 'invalid_journal_events')
    first = events[0]
    base.require(set(first) == {'state', 'time_utc'} and first['state'] == 'attempt_started', 'invalid_journal_start')
    started = stamp(first['time_utc'])
    result = {'state': 'attempt_started', 'started_utc': started.isoformat(),
              'inside_sandbox_window': SINCE <= started < UNTIL}
    if len(events) == 2:
        final = events[1]
        base.require(set(final) == {'state', 'http', 'curl_errno', 'time_utc', 'email_id'}, 'invalid_journal_result')
        ended = stamp(final['time_utc'])
        base.require(ended >= started and final['state'] in {'accepted', 'review_required'}, 'invalid_journal_state')
        base.require(type(final['http']) is int and 0 <= final['http'] <= 599 and
                     type(final['curl_errno']) is int and 0 <= final['curl_errno'] <= 999, 'invalid_journal_transport')
        if final['state'] == 'accepted':
            base.require(final['http'] == 200 and final['curl_errno'] == 0 and
                         isinstance(final['email_id'], str) and 0 < len(final['email_id']) <= 256,
                         'inconsistent_acceptance')
        result.update(state=final['state'], http=final['http'], curl_errno=final['curl_errno'],
                      completed_utc=ended.isoformat(), email_id_present=bool(final['email_id']))
    return result


def verify(client, report):
    base.require(client.stat(base.PRIVATE, 'dir') == 0o700, 'private_directory_requires_0700')
    client.stat(base.PLUGIN, 'dir')
    for name, expected in base.HASHES.items():
        base.require(base.digest(client.read(base.PLUGIN + '/' + name)) == expected, 'plugin_package_changed')
    disabled = client.read(base.PLUGIN + '/config.inc.php.dist')
    expected = disabled.replace("$config['welcome_smtp2go_enabled'] = false;",
                                "$config['welcome_smtp2go_enabled'] = time() < %d;" % activation.EXPIRES)
    base.require(client.read(activation.TARGET) == expected, 'plugin_configuration_changed')
    report['reviewed_plugin_verified'] = True
    data = client.call('list', directory=base.PRIVATE)
    rows = data.get('files') if isinstance(data, dict) else data
    base.require(isinstance(rows, list) and all(isinstance(x, dict) and
                 isinstance(x.get('file'), str) for x in rows), 'invalid_inventory')
    names = sorted({x['file'] for x in rows if JOURNAL.fullmatch(x['file'])})
    base.require(len(names) <= 50, 'too_many_journals')
    client.journals = {base.PRIVATE + '/' + name for name in names}
    results = []
    for path in sorted(client.journals):
        base.require(client.stat(path, 'file') == 0o600, 'journal_requires_0600')
        metadata = client.call('stat', path=path)
        base.require(len(metadata) == 1 and 0 < int(metadata[0].get('size', 0)) <= 4096, 'invalid_journal_size')
        results.append(summarize(client.read(path)))
    relevant = [x for x in results if x['inside_sandbox_window']]
    report.update(ok=True, journals=len(results), window_attempts=len(relevant),
                  accepted=sum(x['state'] == 'accepted' for x in relevant),
                  unresolved=sum(x['state'] != 'accepted' for x in relevant), results=relevant,
                  expires_utc=UNTIL.isoformat())


def main():
    report = {'ok': False, 'mode': 'read-only-sandbox-evidence', 'key_read': False,
              'email_sent': False, 'writes': False}
    try:
        verify(Client(os.environ.get('WHM_API_TOKEN', '')), report)
    except base.PreparationError as error:
        report['error'] = str(error)
    except Exception:
        report['error'] = 'verification_requires_review'
    print(json.dumps(report))
    return 0 if report['ok'] else 1


if __name__ == '__main__':
    sys.exit(main())
