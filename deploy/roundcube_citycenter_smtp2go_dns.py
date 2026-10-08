#!/usr/bin/env python3
"""Back up City Center's DNS zone and add only SMTP2GO return-path/DKIM CNAMEs."""
import base64
import json
import os
import re
import ssl
import sys
import urllib.error
import urllib.parse
import urllib.request
from datetime import datetime, timezone

ORIGIN = 'https://server50.romania-webhosting.com:2087'
DOMAIN = 'citycenterhostel.pt'
SAFE_USER = re.compile(r'^[a-z][a-z0-9]{0,15}$')
BACKUP_DIR = 'roundcube-smtp2go-citycenter-private'
BACKUP_FILE = 'dns-before-smtp2go-20261008.json'
RECORDS = (
    {'name': 'em1063225.citycenterhostel.pt', 'target': 'return.smtp2go.net', 'ttl': 3600},
    {'name': 's1063225._domainkey.citycenterhostel.pt', 'target': 'dkim.smtp2go.net', 'ttl': 14400},
)


class OperationError(Exception):
    pass


class NoRedirects(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        raise OperationError('redirect_refused')


class Client:
    def __init__(self, token, opener=None):
        if not isinstance(token, str) or not 0 < len(token) <= 4096 or not all(33 <= ord(c) <= 126 for c in token):
            raise OperationError('missing_or_invalid_token')
        self.token = token
        self.user = None
        self.opener = opener or urllib.request.build_opener(
            urllib.request.ProxyHandler({}), NoRedirects(),
            urllib.request.HTTPSHandler(context=ssl.create_default_context()))

    def request(self, path, params, api_version=1, post=False):
        url = ORIGIN + path
        encoded = urllib.parse.urlencode(params)
        data = encoded.encode() if post else None
        if not post:
            url += '?' + encoded
        request = urllib.request.Request(url, data=data, headers={
            'Authorization': 'whm fazenda:' + self.token,
            'Accept': 'application/json', 'Cache-Control': 'no-store',
            'User-Agent': 'CityCenter-SMTP2GO-DNS-Setup/1'})
        try:
            with self.opener.open(request, timeout=20) as response:
                if response.status != 200 or response.geturl() != url:
                    raise OperationError('unexpected_http_response')
                raw = response.read(2 * 1024 * 1024 + 1)
            if len(raw) > 2 * 1024 * 1024:
                raise OperationError('response_too_large')
            payload = json.loads(raw)
        except OperationError:
            raise
        except urllib.error.HTTPError as error:
            if error.code in (401, 403):
                raise OperationError('api_access_denied') from None
            raise OperationError('http_error') from None
        except Exception:
            raise OperationError('request_failed') from None
        if not isinstance(payload, dict) or payload.get('metadata', {}).get('result') != 1:
            raise OperationError('api_call_failed')
        if api_version == 3:
            result = payload.get('data', {}).get('uapi')
            if not isinstance(result, dict) or result.get('status') != 1 or result.get('errors') not in (None, []):
                raise OperationError('uapi_call_failed')
            return result.get('data')
        if api_version == 2:
            result = payload.get('data', {}).get('cpanelresult')
            if not isinstance(result, dict) or result.get('event', {}).get('result') != 1:
                raise OperationError('cpanel_call_failed')
            rows = result.get('data')
            if isinstance(rows, list) and any(isinstance(row, dict) and row.get('result') == 0 for row in rows):
                raise OperationError('cpanel_call_failed')
            return rows
        return payload.get('data')

    def account_for_domain(self):
        data = self.request('/json-api/listaccts', {
            'api.version': 1, 'api.columns.enable': 1,
            'api.columns.a': 'user', 'api.columns.b': 'domain'})
        rows = data.get('acct') if isinstance(data, dict) else None
        matches = [r for r in rows or [] if isinstance(r, dict) and str(r.get('domain', '')).rstrip('.').lower() == DOMAIN]
        if len(matches) != 1:
            raise OperationError('account_not_unique')
        user = matches[0].get('user')
        if not isinstance(user, str) or not SAFE_USER.fullmatch(user):
            raise OperationError('invalid_account_identifier')
        self.user = user
        return user

    def dns_records(self):
        if self.user is None:
            raise OperationError('account_scope_violation')
        data = self.request('/json-api/uapi_cpanel', {
            'api.version': 1, 'cpanel.user': self.user, 'cpanel.module': 'DNS',
            'cpanel.function': 'parse_zone', 'zone': DOMAIN}, api_version=3)
        rows = data if isinstance(data, list) else data.get('payload') if isinstance(data, dict) else None
        if not isinstance(rows, list):
            raise OperationError('invalid_dns_inventory')
        result = []
        for row in rows:
            if not isinstance(row, dict) or row.get('type') != 'record':
                continue
            try:
                name = base64.b64decode(row['dname_b64'], validate=True).decode().rstrip('.').lower()
                values = row['data_b64']
                if not isinstance(values, list):
                    raise ValueError
                values = [base64.b64decode(v, validate=True).decode().rstrip('.').lower() for v in values]
                record = {'name': name, 'type': str(row['record_type']).upper(),
                          'ttl': int(row['ttl']), 'data': values, 'line_index': int(row['line_index'])}
            except (KeyError, TypeError, ValueError):
                raise OperationError('invalid_dns_record') from None
            if record['line_index'] < 0 or record['ttl'] < 0:
                raise OperationError('invalid_dns_record')
            result.append(record)
        return result

    def file_inventory(self, directory):
        data = self.request('/json-api/uapi_cpanel', {
            'api.version': 1, 'cpanel.user': self.user, 'cpanel.module': 'Fileman',
            'cpanel.function': 'list_files', 'dir': directory}, api_version=3)
        rows = data.get('files') if isinstance(data, dict) else data
        if not isinstance(rows, list) or not all(isinstance(r, dict) and isinstance(r.get('file'), str) for r in rows):
            raise OperationError('invalid_file_inventory')
        return {r['file'] for r in rows}

    def api2(self, function, **params):
        common = {'cpanel_jsonapi_user': self.user, 'cpanel_jsonapi_apiversion': 2,
                  'cpanel_jsonapi_module': 'Fileman', 'cpanel_jsonapi_func': function}
        return self.request('/json-api/cpanel', {**common, **params}, api_version=2)

    def file_stat(self, directory, filename):
        rows = self.api2('statfiles', dir=directory.removeprefix('/home/' + self.user + '/'), files=filename)
        if not isinstance(rows, list) or len(rows) != 1 or rows[0].get('exists') != 1:
            return None
        return rows[0]

    def ensure_private_backup(self, records):
        home = '/home/' + self.user
        directory = home + '/' + BACKUP_DIR
        home_names = self.file_inventory(home)
        if BACKUP_DIR not in home_names:
            self.api2('mkdir', path=home, name=BACKUP_DIR, permissions='0700')
        dstat = self.file_stat(home, BACKUP_DIR)
        if not dstat or dstat.get('type') != 'dir' or dstat.get('nicemode') != '0700':
            raise OperationError('private_backup_directory_not_secure')
        backup_path = directory + '/' + BACKUP_FILE
        existing = self.file_stat(directory, BACKUP_FILE)
        content = json.dumps({'domain': DOMAIN, 'captured_utc': datetime.now(timezone.utc).isoformat(),
                              'records': records}, sort_keys=True, separators=(',', ':'))
        if existing:
            if existing.get('nicemode') != '0600' or existing.get('type') != 'file':
                raise OperationError('existing_backup_not_secure')
            old = self.request('/json-api/uapi_cpanel', {
                'api.version': 1, 'cpanel.user': self.user, 'cpanel.module': 'Fileman',
                'cpanel.function': 'get_file_content', 'dir': directory, 'file': BACKUP_FILE,
                'from_charset': 'UTF-8', 'to_charset': 'UTF-8'}, api_version=3)
            if old != content:
                # A prior partial run has a different timestamp; preserve the first backup.
                try:
                    saved = json.loads(old)
                except Exception:
                    raise OperationError('existing_backup_unreadable') from None
                if saved.get('domain') != DOMAIN or not isinstance(saved.get('records'), list):
                    raise OperationError('existing_backup_invalid')
            expected = {(r['name'], 'CNAME', r['target']) for r in RECORDS}
            baseline = _fingerprints(r for r in records if (r['name'], r['type'], r['data'][0] if r['data'] else '') not in expected)
            if _fingerprints(saved['records']) != baseline:
                raise OperationError('existing_backup_does_not_match_zone')
            return backup_path
        self.request('/json-api/uapi_cpanel', {
            'api.version': 1, 'cpanel.user': self.user, 'cpanel.module': 'Fileman',
            'cpanel.function': 'save_file_content', 'dir': directory, 'file': BACKUP_FILE,
            'content': content, 'from_charset': 'UTF-8', 'to_charset': 'UTF-8'}, api_version=3, post=True)
        self.api2('fileop', op='chmod', sourcefiles=backup_path.removeprefix(home + '/'),
                  metadata='0600', doubledecode=0)
        stat = self.file_stat(directory, BACKUP_FILE)
        if not stat or stat.get('nicemode') != '0600' or stat.get('type') != 'file':
            raise OperationError('backup_permissions_not_secure')
        saved = self.request('/json-api/uapi_cpanel', {
            'api.version': 1, 'cpanel.user': self.user, 'cpanel.module': 'Fileman',
            'cpanel.function': 'get_file_content', 'dir': directory, 'file': BACKUP_FILE,
            'from_charset': 'UTF-8', 'to_charset': 'UTF-8'}, api_version=3)
        try:
            if json.loads(saved) != json.loads(content):
                raise OperationError('backup_verification_failed')
        except (TypeError, ValueError):
            raise OperationError('backup_verification_failed') from None
        return backup_path

    def add_cname(self, rec):
        data = self.request('/json-api/addzonerecord', {
            'api.version': 1, 'domain': DOMAIN, 'name': rec['name'], 'type': 'CNAME',
            'class': 'IN', 'ttl': rec['ttl'], 'cname': rec['target'] + '.'})
        if not isinstance(data, dict) or data.get('result') != 1:
            raise OperationError('dns_add_failed')

    def remove_line(self, line_index):
        data = self.request('/json-api/removezonerecord', {
            'api.version': 1, 'domain': DOMAIN, 'line': line_index})
        if not isinstance(data, dict) or data.get('result') != 1:
            raise OperationError('dns_rollback_failed')


def matching(records, rec):
    return [r for r in records if r['name'] == rec['name'] and r['type'] == 'CNAME' and
            r['data'] == [rec['target']]]


def _fingerprints(records):
    return sorted((r['name'], r['type'], r['ttl'], tuple(r['data'])) for r in records)


def apply(client):
    client.account_for_domain()
    before = client.dns_records()
    pending = []
    for rec in RECORDS:
        at_name = [r for r in before if r['name'] == rec['name']]
        exact = matching(before, rec)
        if exact:
            continue
        if at_name:
            raise OperationError('dns_name_conflict')
        pending.append(rec)
    backup_path = client.ensure_private_backup(before)
    attempted = []
    try:
        for rec in pending:
            attempted.append(rec)
            client.add_cname(rec)
            current = client.dns_records()
            exact = matching(current, rec)
            if len(exact) != 1:
                raise OperationError('dns_write_verification_failed')
        final = client.dns_records()
        if any(len(matching(final, rec)) != 1 for rec in RECORDS):
            raise OperationError('final_dns_verification_failed')
    except OperationError:
        for rec in reversed(attempted):
            current = client.dns_records()
            still_exact = matching(current, rec)
            if len(still_exact) == 1:
                client.remove_line(still_exact[0]['line_index'])
        raise
    return {'ok': True, 'mode': 'citycenter-smtp2go-dns', 'writes': bool(pending),
            'email_sent': False, 'backup_verified': True, 'smtp2go_cname_count': len(RECORDS),
            'records_added': len(pending), 'records_present': len(RECORDS), 'rollback_performed': False}


def main():
    try:
        print(json.dumps(apply(Client(os.environ.get('WHM_API_TOKEN', ''))), sort_keys=True))
        return 0
    except OperationError as error:
        print(json.dumps({'ok': False, 'mode': 'citycenter-smtp2go-dns', 'writes': False,
                          'email_sent': False, 'error': str(error)}))
        return 1
    except Exception:
        print(json.dumps({'ok': False, 'mode': 'citycenter-smtp2go-dns', 'writes': False,
                          'email_sent': False, 'error': 'state_requires_review'}))
        return 1


if __name__ == '__main__':
    sys.exit(main())
