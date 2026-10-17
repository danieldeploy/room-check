#!/usr/bin/env python3
"""Install, inspect or roll back one fixed two-message Roundcube pilot."""
import argparse
import json
import os
import re
import sys
import time
from pathlib import Path
import roundcube_prepare as base
import roundcube_activate as previous
import roundcube_verify as evidence

PLUGIN = base.ROOT + '/plugins/welcome_smtp2go_pilot'
BACKUP = base.PRIVATE + '/config-before-live-pilot-20261004.inc.php'
EXPIRES = 1791133200
FILES = ('welcome_smtp2go_pilot.php', 'pilot_transport.php', 'pilot.js', 'config.inc.php')
JOURNALS = {base.PRIVATE + '/pilot-20261004-' + slot + '.json' for slot in ('01', '02')}
TARGETS = {PLUGIN + '/' + name for name in FILES}


class Client(previous.Client):
    def __init__(self, token, writable=False):
        super().__init__(token)
        self.writable = writable

    def call(self, operation, **values):
        path = values.get('path', '')
        if operation in ('write', 'create_plugin', 'copy_backup', 'chmod'):
            base.require(self.writable, 'read_only_client')
        if operation == 'list':
            base.require(set(values) == {'directory'} and values['directory'] in
                         {base.PRIVATE, base.ROOT + '/plugins', PLUGIN}, 'inventory_not_allowed')
            return self.request('/json-api/uapi_cpanel', {'api.version': 1, 'cpanel.user': 'welcome',
                'cpanel.module': 'Fileman', 'cpanel.function': 'list_files', 'dir': values['directory']}, 3)
        if operation in ('read', 'stat'):
            base.require(set(values) == {'path'}, 'unexpected_parameters')
            if path in TARGETS | JOURNALS | {BACKUP, PLUGIN}:
                base.require(operation == 'stat' or path != PLUGIN, 'directory_read_refused')
                directory, filename = path.rsplit('/', 1)
                if operation == 'read':
                    return self.request('/json-api/uapi_cpanel', {'api.version': 1, 'cpanel.user': 'welcome',
                        'cpanel.module': 'Fileman', 'cpanel.function': 'get_file_content', 'dir': directory,
                        'file': filename, 'from_charset': 'UTF-8', 'to_charset': 'UTF-8'}, 3)
                return self.request('/json-api/cpanel', {'cpanel_jsonapi_user': 'welcome',
                    'cpanel_jsonapi_apiversion': 2, 'cpanel_jsonapi_module': 'Fileman',
                    'cpanel_jsonapi_func': 'statfiles', 'dir': directory, 'files': filename}, 2)
            # The parent has explicit read/stat allowlists and refuses key reads.
            return super().call(operation, **values)
        if operation == 'write':
            base.require(set(values) == {'path', 'content'} and path in TARGETS | {base.CONFIG}, 'write_not_allowed')
            base.require(isinstance(values['content'], str) and len(values['content']) <= base.LIMIT, 'invalid_content')
            directory, filename = path.rsplit('/', 1)
            return self.request('/json-api/uapi_cpanel', {'api.version': 1, 'cpanel.user': 'welcome',
                'cpanel.module': 'Fileman', 'cpanel.function': 'save_file_content', 'dir': directory,
                'file': filename, 'content': values['content'], 'from_charset': 'UTF-8', 'to_charset': 'UTF-8'}, 3, post=True)
        common = {'cpanel_jsonapi_user': 'welcome', 'cpanel_jsonapi_apiversion': 2, 'cpanel_jsonapi_module': 'Fileman'}
        if operation == 'create_plugin':
            base.require(not values, 'mkdir_not_allowed')
            params = {'cpanel_jsonapi_func': 'mkdir', 'path': base.ROOT + '/plugins',
                      'name': 'welcome_smtp2go_pilot', 'permissions': '0755'}
        elif operation == 'copy_backup':
            base.require(not values, 'copy_not_allowed')
            params = {'cpanel_jsonapi_func': 'fileop', 'op': 'copy',
                      'sourcefiles': base.CONFIG.removeprefix('/home/welcome/'), 'destfiles': BACKUP, 'doubledecode': 0}
        elif operation == 'chmod':
            base.require(set(values) == {'path'} and path in TARGETS | {BACKUP}, 'chmod_not_allowed')
            params = {'cpanel_jsonapi_func': 'fileop', 'op': 'chmod',
                      'sourcefiles': path.removeprefix('/home/welcome/'),
                      'metadata': '0600' if path == BACKUP else '0644', 'doubledecode': 0}
        else:
            raise base.PreparationError('operation_not_allowed')
        return self.request('/json-api/cpanel', {**common, **params}, 2)


def inventory(client, directory):
    data = client.call('list', directory=directory)
    rows = data.get('files') if isinstance(data, dict) else data
    base.require(isinstance(rows, list) and all(isinstance(row, dict) and
                 isinstance(row.get('file'), str) for row in rows), 'invalid_inventory')
    return {row['file'] for row in rows}


def sources():
    return {name: (Path(__file__).with_name('roundcube-pilot') / name).read_text(encoding='utf-8') for name in FILES}


def configurations(original, summary, disabled):
    before, old_plugin_config = previous.configurations(original, summary, disabled)
    suffix = ('' if summary['php_open'] else '\n<?php\n') + '''
// Two-message SMTP2GO pilot. The plugin rejects pilot sends after its deadline.
if (!in_array('welcome_smtp2go_pilot', (array) ($config['plugins'] ?? []), true)) {
    $config['plugins'][] = 'welcome_smtp2go_pilot';
}
'''
    return before, original + suffix, old_plugin_config


def inspect(client, report, package=None, parser=previous.parse_config):
    package = package or sources()
    for directory in ('/home/welcome/public_html', base.ROOT, base.ROOT + '/config', base.ROOT + '/program', base.ROOT + '/program/include',
                      base.ROOT + '/plugins', base.PLUGIN, *sorted(previous.DIRECTORIES)):
        client.stat(directory, 'dir')
    base.require(client.stat(base.PRIVATE, 'dir') == 0o700, 'private_directory_requires_0700')
    base.require(client.stat(base.BACKUP, 'file') == 0o600, 'original_backup_requires_0600')
    base.require(client.stat(previous.KEY, 'file') == 0o600, 'key_requires_0600')
    base.require(re.search(r"define\s*\(\s*['\"]RCMAIL_VERSION['\"]\s*,\s*['\"]1\.6\.19['\"]\s*\)",
                          client.read(base.VERSION)), 'roundcube_version_changed')
    for name, expected in base.HASHES.items():
        base.require(base.digest(client.read(base.PLUGIN + '/' + name)) == expected, 'sandbox_package_changed')
    for name, expected in previous.CORE_HASHES.items():
        base.require(base.digest(client.read(base.ROOT + '/' + name)) == expected, 'roundcube_core_changed')
    original = client.read(base.BACKUP)
    summary = parser(original)
    base.require('welcome_smtp2go' not in summary['plugins'] and
                 'welcome_smtp2go_pilot' not in summary['plugins'], 'original_plugins_changed')
    for name in summary['plugins']:
        code = client.read(base.ROOT + '/plugins/' + name + '/' + name + '.php')
        base.require(not any(hook in code for hook in ('message_before_send', 'message_ready',
            'message_outgoing_headers', 'message_outgoing_body', 'smtp_connect', 'message_sent')), 'mail_hook_conflict')
    disabled = client.read(base.PLUGIN + '/config.inc.php.dist')
    before, after, old_plugin_config = configurations(original, summary, disabled)
    base.require(client.read(previous.TARGET) == old_plugin_config, 'sandbox_config_changed')
    current = client.read(base.CONFIG)
    base.require(current in (before, after), 'main_config_changed')
    private_names = inventory(client, base.PRIVATE)
    backup_present = BACKUP.rsplit('/', 1)[1] in private_names
    if backup_present:
        base.require(client.stat(BACKUP, 'file') == 0o600, 'pilot_backup_requires_0600')
        base.require(client.read(BACKUP) == before, 'pilot_backup_changed')
    base.require(current != after or backup_present, 'installed_without_backup')
    present = 'welcome_smtp2go_pilot' in inventory(client, base.ROOT + '/plugins')
    names = set()
    if present:
        base.require(client.stat(PLUGIN, 'dir') == 0o755, 'pilot_directory_requires_0755')
        names = inventory(client, PLUGIN) - {'.', '..'}
        base.require(names <= set(FILES), 'unexpected_pilot_files')
        for name in sorted(names):
            base.require(client.stat(PLUGIN + '/' + name, 'file') == 0o644, 'pilot_file_requires_0644')
            base.require(client.read(PLUGIN + '/' + name) == package[name], 'pilot_file_changed')
    base.require(current != after or names == set(FILES), 'registered_pilot_incomplete')
    report.update(core_and_sandbox_verified=True, original_plugins=summary['plugins'],
                  registered=current == after, backup_verified=backup_present, installed_files=len(names))
    return {'before': before, 'after': after, 'original': original, 'present': present,
            'files': names, 'backup': backup_present, 'registered': current == after}


def install(client, report, package=None, parser=previous.parse_config, lint=base.lint, now=time.time):
    base.require(previous.EXPIRES <= now() < EXPIRES - 300, 'pilot_window_unavailable')
    package = package or sources()
    state = inspect(client, report, package, parser)
    for name, source in package.items():
        if name.endswith('.php'): lint(source)
    lint(state['after'])
    if state['registered']:
        report.update(ok=True, already_installed=True)
        return
    if not state['backup']:
        base.require(client.read(base.CONFIG) == state['before'], 'config_changed_before_backup')
        report['last_write_attempt'] = 'copy_private_backup'
        client.call('copy_backup')
        report['last_write_attempt'] = 'protect_private_backup'
        client.call('chmod', path=BACKUP)
        base.require(client.stat(BACKUP, 'file') == 0o600 and client.read(BACKUP) == state['before'], 'backup_verification_failed')
    if not state['present']:
        report['last_write_attempt'] = 'create_separate_plugin_directory'
        client.call('create_plugin')
        base.require(client.stat(PLUGIN, 'dir') == 0o755, 'pilot_directory_requires_0755')
    for name in FILES:
        if name not in state['files']:
            path = PLUGIN + '/' + name
            report['last_write_attempt'] = 'write_' + name
            client.call('write', path=path, content=package[name])
            client.call('chmod', path=path)
            base.require(client.stat(path, 'file') == 0o644 and client.read(path) == package[name], 'file_verification_failed')
    base.require(previous.EXPIRES <= now() < EXPIRES - 300, 'pilot_window_unavailable')
    base.require(client.read(base.CONFIG) == state['before'], 'config_changed_before_registration')
    report['last_write_attempt'] = 'register_restricted_pilot'
    client.call('write', path=base.CONFIG, content=state['after'])
    final = inspect(client, report, package, parser)
    base.require(final['registered'] and client.read(base.BACKUP) == state['original'], 'installation_verification_failed')
    report.update(ok=True, expires_utc='2026-10-04T17:00:00Z', provider_status_verified=False,
                  maximum_attempts=2, ordinary_mail_transport_changed=False)


def verify(client, report, package=None, parser=previous.parse_config):
    state = inspect(client, report, package, parser)
    base.require(state['registered'], 'pilot_not_registered')
    names = inventory(client, base.PRIVATE)
    results = []
    for path in sorted(JOURNALS):
        if path.rsplit('/', 1)[1] not in names: continue
        base.require(client.stat(path, 'file') == 0o600, 'journal_requires_0600')
        meta = client.call('stat', path=path)
        base.require(len(meta) == 1 and 0 < int(meta[0].get('size', 0)) <= 4096, 'invalid_journal_size')
        item = evidence.summarize(client.read(path))
        item.pop('inside_sandbox_window', None)
        item['slot'] = path[-7:-5]
        results.append(item)
    report.update(ok=True, attempts=len(results), accepted=sum(x['state'] == 'accepted' for x in results),
                  unresolved=sum(x['state'] != 'accepted' for x in results), results=results,
                  expires_utc='2026-10-04T17:00:00Z', provider_status_verified=False)


def rollback(client, report, package=None, parser=previous.parse_config):
    state = inspect(client, report, package, parser)
    if state['registered']:
        base.require(client.read(base.CONFIG) == state['after'], 'config_changed_before_rollback')
        report['last_write_attempt'] = 'restore_pre_pilot_config'
        client.call('write', path=base.CONFIG, content=state['before'])
    base.require(client.read(base.CONFIG) == state['before'], 'rollback_verification_failed')
    report.update(ok=True, registered=False, rollback_verified=True)


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('mode', choices=['inspect', 'install', 'verify', 'rollback'])
    mode = parser.parse_args().mode
    report = {'ok': False, 'mode': 'roundcube-pilot-' + mode, 'key_read': False,
              'email_sent': False, 'last_write_attempt': None}
    try:
        client = Client(os.environ.get('WHM_API_TOKEN', ''), writable=mode in ('install', 'rollback'))
        if mode == 'inspect':
            inspect(client, report)
            report['ok'] = True
        else:
            {'install': install, 'verify': verify, 'rollback': rollback}[mode](client, report)
    except base.PreparationError as error:
        report['error'] = str(error)
    except Exception:
        report['error'] = 'pilot_state_requires_review'
    print(json.dumps(report))
    return 0 if report['ok'] else 1


if __name__ == '__main__':
    sys.exit(main())
