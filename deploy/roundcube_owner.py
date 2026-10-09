#!/usr/bin/env python3
"""Retarget the unused second pilot to the owner's Microsoft test mailboxes."""
import argparse
import json
import os
import sys
import time
import roundcube_pilot as p

base = p.base
EXPIRES = 1791140400  # 2026-10-04 19:00 UTC / 20:00 Lisbon
CHANGED = ('pilot_transport.php', 'pilot.js', 'config.inc.php')
BACKUPS = {name: base.PRIVATE + '/before-owner-20261004-' + name for name in CHANGED}
OLD_HASHES = {
    'config.inc.php': 'ba39f427c056ea9b3b6e44cff26d3a5db93785decc641e12e0655cffe6173866',
    'pilot.js': '776bd4bdaee644b443eac1b8eae21ae513730314796b444cb3c30554798d0ee4',
    'pilot_transport.php': 'ad89d2065bc9b75ced50ef77909cb481fb55667745aed68871659d6588dc987b',
    'welcome_smtp2go_pilot.php': '651d4e257379e1e0e23bdcaa186a670e344ac907e7232b726cc00859ad02284d',
}


def packages():
    old = p.sources()
    base.require({n: base.digest(v) for n, v in old.items()} == OLD_HASHES, 'base_package_changed')
    new = dict(old)
    new['config.inc.php'] = old['config.inc.php'].replace(str(p.EXPIRES), str(EXPIRES)).replace(
        '325997a38120f2105b227f63c6e0dcf2a3c377d15496e9e4f406d4c8053cae4c',
        'f6ee0d33357832750fba58b555c59ed0e2452d9d84988d8fb80be7227a795207').replace(
        '\n];', "\n    'hotmail' => '7c641f8fd8d88183e151d20aa53ca3c73dc99d6f00e8d15f151aef248735bc03',\n];")
    new['pilot_transport.php'] = old['pilot_transport.php'].replace(str(p.EXPIRES), str(EXPIRES)).replace(
        '17:00 UTC / 18:00 Lisbon', '19:00 UTC / 20:00 Lisbon').replace(
        "['gmail', 'outlook'] as", "['gmail', 'outlook', 'hotmail'] as").replace(
        "$recipients['gmail'] === $recipients['outlook']", "count(array_unique($recipients)) !== 3").replace(
        "$slot = $slots[$subject];", "$slot = $slots[$subject];\n        if ($slot !== '02') {\n            throw new welcome_smtp2go_pilot_error('O primeiro teste já terminou. Usa apenas o teste 02.');\n        }").replace(
        "'cc' => [$recipients['gmail']], 'bcc' => []", "'cc' => [$recipients['gmail'], $recipients['hotmail']], 'bcc' => []").replace(
        'teste 01 Para Gmail + BCC Outlook; teste 02 Para Outlook + CC Gmail.',
        'teste 02 Para Outlook + CC Gmail e Hotmail, por esta ordem.')
    new['pilot.js'] = """if (window.rcmail) {
    rcmail.addEventListener('init', function () {
        rcmail.display_message('PILOTO REAL SMTP2GO até 20:00 de 04/10: apenas WELCOME-PILOT-20261004-02. Uma tentativa, Para Outlook + CC Gmail e Hotmail definidos. Não repetir após enviar.', 'warning', 0);
    });
}
"""
    return old, new


class Client(p.Client):
    def call(self, operation, **values):
        path = values.get('path', '')
        if operation in ('read', 'stat') and path in BACKUPS.values():
            base.require(set(values) == {'path'}, 'unexpected_parameters')
            directory, filename = path.rsplit('/', 1)
            if operation == 'read':
                return self.request('/json-api/uapi_cpanel', {'api.version': 1, 'cpanel.user': 'welcome',
                    'cpanel.module': 'Fileman', 'cpanel.function': 'get_file_content', 'dir': directory,
                    'file': filename, 'from_charset': 'UTF-8', 'to_charset': 'UTF-8'}, 3)
            return self.request('/json-api/cpanel', {'cpanel_jsonapi_user': 'welcome',
                'cpanel_jsonapi_apiversion': 2, 'cpanel_jsonapi_module': 'Fileman',
                'cpanel_jsonapi_func': 'statfiles', 'dir': directory, 'files': filename}, 2)
        if operation in ('read', 'stat', 'list'):
            return super().call(operation, **values)
        base.require(self.writable, 'read_only_client')
        old, new = packages()
        if operation == 'write':
            name = path.rsplit('/', 1)[-1]
            base.require(set(values) == {'path', 'content'} and name in CHANGED and
                         path == p.PLUGIN + '/' + name and values['content'] in (old[name], new[name]),
                         'write_not_allowed')
            return super().call(operation, **values)
        common = {'cpanel_jsonapi_user': 'welcome', 'cpanel_jsonapi_apiversion': 2,
                  'cpanel_jsonapi_module': 'Fileman', 'cpanel_jsonapi_func': 'fileop', 'doubledecode': 0}
        if operation == 'copy_owner_backup':
            name = values.get('name')
            base.require(set(values) == {'name'} and name in CHANGED, 'copy_not_allowed')
            return self.request('/json-api/cpanel', {**common, 'op': 'copy',
                'sourcefiles': (p.PLUGIN + '/' + name).removeprefix('/home/welcome/'),
                'destfiles': BACKUPS[name]}, 2)
        if operation == 'chmod':
            base.require(set(values) == {'path'} and path in BACKUPS.values(), 'chmod_not_allowed')
            return self.request('/json-api/cpanel', {**common, 'op': 'chmod',
                'sourcefiles': path.removeprefix('/home/welcome/'), 'metadata': '0600'}, 2)
        raise base.PreparationError('operation_not_allowed')


def inspect(client, report, parser=p.previous.parse_config):
    old, new = packages()
    current = dict(old)
    for name in CHANGED:
        current[name] = client.read(p.PLUGIN + '/' + name)
        base.require(current[name] in (old[name], new[name]), 'owner_package_changed')
    p.verify(client, report, package=current, parser=parser)
    names = p.inventory(client, base.PRIVATE)
    present = set()
    for name, path in BACKUPS.items():
        if path.rsplit('/', 1)[-1] in names:
            base.require(client.stat(path, 'file') == 0o600 and client.read(path) == old[name],
                         'owner_backup_changed')
            present.add(name)
        base.require(current[name] == old[name] or name in present, 'changed_without_owner_backup')
    report.update(owner_updated=current == new, owner_backup_count=len(present),
                  expires_utc='2026-10-04T19:00:00Z' if current == new else report['expires_utc'])
    return old, new, current, present


def remaining_attempt(client):
    names = p.inventory(client, base.PRIVATE)
    base.require('pilot-20261004-02.json' not in names, 'second_test_already_attempted')
    first = base.PRIVATE + '/pilot-20261004-01.json'
    base.require(first.rsplit('/', 1)[-1] in names and client.stat(first, 'file') == 0o600,
                 'first_test_acceptance_required')
    base.require(p.evidence.summarize(client.read(first))['state'] == 'accepted',
                 'first_test_acceptance_required')


def retarget(client, report, parser=p.previous.parse_config, lint=base.lint, now=time.time):
    old, new, current, present = inspect(client, report, parser)
    if current == new:
        report.update(ok=True, already_updated=True)
        return
    base.require(now() < EXPIRES - 300, 'owner_window_unavailable')
    remaining_attempt(client)
    for name in CHANGED:
        if name.endswith('.php'): lint(new[name])
    for name in CHANGED:
        if name not in present:
            base.require(client.read(p.PLUGIN + '/' + name) == old[name], 'changed_before_backup')
            report['last_write_attempt'] = 'backup_' + name
            client.call('copy_owner_backup', name=name)
            client.call('chmod', path=BACKUPS[name])
            base.require(client.stat(BACKUPS[name], 'file') == 0o600 and
                         client.read(BACKUPS[name]) == old[name], 'owner_backup_verification_failed')
    # Transport first, config last: differing fixed deadlines fail closed between writes.
    for name in CHANGED:
        if current[name] != new[name]:
            remaining_attempt(client)
            base.require(now() < EXPIRES - 300, 'owner_window_unavailable')
            base.require(client.read(p.PLUGIN + '/' + name) == current[name], 'concurrent_owner_change')
            report['last_write_attempt'] = 'update_' + name
            client.call('write', path=p.PLUGIN + '/' + name, content=new[name])
            base.require(client.read(p.PLUGIN + '/' + name) == new[name], 'owner_write_verification_failed')
    verify(client, report, parser)


def verify(client, report, parser=p.previous.parse_config):
    inspect(client, report, parser)
    base.require(report['owner_updated'] and report['owner_backup_count'] == len(CHANGED), 'owner_update_incomplete')
    report.update(ok=True, maximum_remaining_attempts=max(0, 2-report['attempts']), email_sent=False)


def rollback(client, report, parser=p.previous.parse_config):
    old, new, current, present = inspect(client, report, parser)
    for name in reversed(CHANGED):
        if current[name] != old[name]:
            base.require(name in present and client.read(p.PLUGIN + '/' + name) == current[name],
                         'rollback_state_changed')
            report['last_write_attempt'] = 'restore_' + name
            client.call('write', path=p.PLUGIN + '/' + name, content=old[name])
    inspect(client, report, parser)
    base.require(all(client.read(p.PLUGIN + '/' + n) == old[n] for n in CHANGED), 'rollback_incomplete')
    report.update(ok=True, rollback_verified=True)


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('mode', choices=['inspect', 'retarget', 'verify', 'rollback'])
    mode = parser.parse_args().mode
    report = {'ok': False, 'mode': 'roundcube-owner-' + mode, 'key_read': False,
              'email_sent': False, 'last_write_attempt': None}
    try:
        client = Client(os.environ.get('WHM_API_TOKEN', ''), writable=mode in ('retarget', 'rollback'))
        {'inspect': inspect, 'retarget': retarget, 'verify': verify, 'rollback': rollback}[mode](client, report)
        report['ok'] = True
    except base.PreparationError as error:
        report['ok'] = False
        report['error'] = str(error)
    except Exception:
        report['ok'] = False
        report['error'] = 'owner_state_requires_review'
    print(json.dumps(report))
    return 0 if report['ok'] else 1


if __name__ == '__main__':
    sys.exit(main())
