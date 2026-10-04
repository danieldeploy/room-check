#!/usr/bin/env python3
"""Fixed Welcome production install, private retirement, verification and rollback.

No key reads, arbitrary paths, deletion, SMTP fallback or automatic retries.
"""
import argparse
import json
import os
import re
import sys
from pathlib import Path
import roundcube_owner as owner

p = owner.p
b = owner.base
a = p.previous
PLUGIN = b.ROOT + '/plugins/welcome_smtp2go_api'
FILES = ('api_transport.php', 'welcome_smtp2go_api.php', 'config.inc.php')
TARGETS = {PLUGIN + '/' + n for n in FILES}
BACKUP = b.PRIVATE + '/config-before-production-20261004.inc.php'
JOURNAL = b.PRIVATE + '/live'
ARCHIVE = b.PRIVATE + '/retired-tests-20261004'
OLD = (b.PLUGIN, p.PLUGIN)
MOVED = {x: ARCHIVE + '/' + x.rsplit('/', 1)[1] for x in OLD}
ARCHIVED_FILES = {MOVED[b.PLUGIN] + '/' + n for n in (*b.HASHES, 'config.inc.php')} | {
    MOVED[p.PLUGIN] + '/' + n for n in p.FILES}
DIRECTORIES = {PLUGIN, JOURNAL, ARCHIVE, *MOVED.values()}


def sources():
    return {n: (Path(__file__).with_name('roundcube-live') / n).read_text() for n in FILES}


class Client(owner.Client):
    def call(self, operation, **values):
        path = values.get('path', '')
        if operation == 'list':
            b.require(set(values) == {'directory'} and values['directory'] in
                      {b.PRIVATE, b.ROOT + '/plugins', b.PLUGIN, p.PLUGIN, *DIRECTORIES}, 'inventory_not_allowed')
            return self.request('/json-api/uapi_cpanel', {'api.version': 1, 'cpanel.user': 'welcome',
                'cpanel.module': 'Fileman', 'cpanel.function': 'list_files', 'dir': values['directory']}, 3)
        if operation in ('read', 'stat'):
            b.require(set(values) == {'path'}, 'unexpected_parameters')
            extra = TARGETS | ARCHIVED_FILES | {BACKUP}
            if operation == 'stat': extra |= DIRECTORIES
            if path in extra:
                directory, filename = path.rsplit('/', 1)
                if operation == 'read':
                    return self.request('/json-api/uapi_cpanel', {'api.version': 1, 'cpanel.user': 'welcome',
                        'cpanel.module': 'Fileman', 'cpanel.function': 'get_file_content', 'dir': directory,
                        'file': filename, 'from_charset': 'UTF-8', 'to_charset': 'UTF-8'}, 3)
                return self.request('/json-api/cpanel', {'cpanel_jsonapi_user': 'welcome',
                    'cpanel_jsonapi_apiversion': 2, 'cpanel_jsonapi_module': 'Fileman',
                    'cpanel_jsonapi_func': 'statfiles', 'dir': directory, 'files': filename}, 2)
            return super().call(operation, **values)
        b.require(self.writable, 'read_only_client')
        common = {'cpanel_jsonapi_user': 'welcome', 'cpanel_jsonapi_apiversion': 2,
                  'cpanel_jsonapi_module': 'Fileman'}
        if operation == 'write':
            content = values.get('content')
            b.require(set(values) == {'path', 'content'} and path in TARGETS | {b.CONFIG}
                      and isinstance(content, str) and len(content) <= b.LIMIT, 'write_not_allowed')
            if path in TARGETS:
                b.require(content == sources()[path.rsplit('/', 1)[1]], 'unreviewed_plugin_content')
            directory, filename = path.rsplit('/', 1)
            return self.request('/json-api/uapi_cpanel', {'api.version': 1, 'cpanel.user': 'welcome',
                'cpanel.module': 'Fileman', 'cpanel.function': 'save_file_content', 'dir': directory,
                'file': filename, 'content': content, 'from_charset': 'UTF-8', 'to_charset': 'UTF-8'}, 3, post=True)
        if operation == 'mkdir_live':
            b.require(set(values) == {'path'} and path in {PLUGIN, JOURNAL, ARCHIVE}, 'mkdir_not_allowed')
            parent, name = path.rsplit('/', 1)
            params = {'cpanel_jsonapi_func': 'mkdir', 'path': parent, 'name': name,
                      'permissions': '0755' if path == PLUGIN else '0700'}
        elif operation == 'backup_live':
            b.require(not values, 'copy_not_allowed')
            params = {'cpanel_jsonapi_func': 'fileop', 'op': 'copy',
                      'sourcefiles': b.CONFIG.removeprefix('/home/welcome/'), 'destfiles': BACKUP, 'doubledecode': 0}
        elif operation == 'chmod_live':
            b.require(set(values) == {'path'} and path in TARGETS | {BACKUP}, 'chmod_not_allowed')
            params = {'cpanel_jsonapi_func': 'fileop', 'op': 'chmod',
                      'sourcefiles': path.removeprefix('/home/welcome/'),
                      'metadata': '0600' if path == BACKUP else '0644', 'doubledecode': 0}
        elif operation == 'move_test':
            pair = (values.get('source'), values.get('destination'))
            b.require(set(values) == {'source', 'destination'} and pair in
                      set(MOVED.items()) | {(v, k) for k, v in MOVED.items()}, 'move_not_allowed')
            params = {'cpanel_jsonapi_func': 'fileop', 'op': 'rename',
                      'sourcefiles': pair[0].removeprefix('/home/welcome/'), 'destfiles': pair[1], 'doubledecode': 0}
        else:
            raise b.PreparationError('operation_not_allowed')
        return self.request('/json-api/cpanel', {**common, **params}, 2)


def names(client, directory):
    return p.inventory(client, directory) - {'.', '..'}


def configurations(original, summary):
    suffix = ('' if summary['php_open'] else '\n<?php\n') + '''
// Verified SMTP2GO MIME transport for the Welcome mailbox.
if (!in_array('welcome_smtp2go_api', (array) ($config['plugins'] ?? []), true)) {
    $config['plugins'][] = 'welcome_smtp2go_api';
}
'''
    return original + suffix


def inspect(client, report, parser=a.parse_config):
    for directory in ('/home/welcome/public_html', b.ROOT, b.ROOT + '/config', b.ROOT + '/program',
                      b.ROOT + '/program/include', b.ROOT + '/plugins', *sorted(a.DIRECTORIES)):
        client.stat(directory, 'dir')
    b.require(client.stat(b.PRIVATE, 'dir') == 0o700, 'private_directory_requires_0700')
    b.require(client.stat(a.KEY, 'file') == 0o600, 'key_requires_0600')
    b.require(client.stat(b.BACKUP, 'file') == 0o600, 'original_backup_requires_0600')
    b.require(re.search(r"define\s*\(\s*['\"]RCMAIL_VERSION['\"]\s*,\s*['\"]1\.6\.19['\"]\s*\)",
                        client.read(b.VERSION)), 'roundcube_version_changed')
    for name, expected in a.CORE_HASHES.items():
        b.require(b.digest(client.read(b.ROOT + '/' + name)) == expected, 'roundcube_core_changed')
    original = client.read(b.BACKUP)
    summary = parser(original)
    b.require(not set(summary['plugins']) & {'welcome_smtp2go', 'welcome_smtp2go_pilot', 'welcome_smtp2go_api'},
              'original_plugins_changed')
    for name in summary['plugins']:
        code = client.read(b.ROOT + '/plugins/' + name + '/' + name + '.php')
        b.require(not any(hook in code for hook in ('message_before_send', 'message_ready',
            'message_outgoing_headers', 'message_outgoing_body', 'smtp_connect', 'message_sent')), 'mail_hook_conflict')
    private = names(client, b.PRIVATE)
    public = names(client, b.ROOT + '/plugins')
    archived = set()
    if ARCHIVE.rsplit('/', 1)[1] in private:
        b.require(client.stat(ARCHIVE, 'dir') == 0o700, 'archive_requires_0700')
        archived = names(client, ARCHIVE)
        b.require(archived <= {x.rsplit('/', 1)[1] for x in OLD}, 'unexpected_archive_content')
    locations = {}
    for folder in OLD:
        name = folder.rsplit('/', 1)[1]
        b.require((name in public) != (name in archived), 'test_folder_missing_or_duplicated')
        locations[folder] = MOVED[folder] if name in archived else folder
        b.require(client.stat(locations[folder], 'dir') == 0o755, 'test_directory_permissions_changed')
    sandbox = locations[b.PLUGIN]
    b.require(names(client, sandbox) == set(b.HASHES) | {'config.inc.php'}, 'unexpected_sandbox_files')
    for name, expected in b.HASHES.items():
        path = sandbox + '/' + name
        b.require(client.stat(path, 'file') == 0o644 and b.digest(client.read(path)) == expected, 'sandbox_package_changed')
    before_sandbox, before, sandbox_config = p.configurations(
        original, summary, client.read(sandbox + '/config.inc.php.dist'))
    b.require(client.stat(sandbox + '/config.inc.php', 'file') == 0o644 and
              client.read(sandbox + '/config.inc.php') == sandbox_config, 'sandbox_config_changed')
    old_package, owner_package = owner.packages()
    pilot = locations[p.PLUGIN]
    b.require(names(client, pilot) == set(p.FILES), 'unexpected_pilot_files')
    for name, expected in owner_package.items():
        path = pilot + '/' + name
        b.require(client.stat(path, 'file') == 0o644 and client.read(path) == expected, 'pilot_package_changed')
    for path, content in {p.BACKUP: before_sandbox, **{owner.BACKUPS[n]: old_package[n] for n in owner.CHANGED}}.items():
        b.require(client.stat(path, 'file') == 0o600 and client.read(path) == content, 'historical_backup_changed')
    for path in sorted(p.JOURNALS):
        b.require(client.stat(path, 'file') == 0o600, 'pilot_journal_requires_0600')
        meta = client.call('stat', path=path)
        b.require(len(meta) == 1 and 0 < int(meta[0].get('size', 0)) <= 4096, 'invalid_pilot_journal_size')
        b.require(p.evidence.summarize(client.read(path))['state'] == 'accepted', 'pilot_not_accepted')
    after = configurations(original, summary)
    current = client.read(b.CONFIG)
    b.require(current in (before, after), 'main_config_changed')
    backup = BACKUP.rsplit('/', 1)[1] in private
    if backup:
        b.require(client.stat(BACKUP, 'file') == 0o600 and client.read(BACKUP) == before, 'production_backup_changed')
    installed = set()
    if PLUGIN.rsplit('/', 1)[1] in public:
        b.require(client.stat(PLUGIN, 'dir') == 0o755, 'production_directory_requires_0755')
        installed = names(client, PLUGIN)
        b.require(installed <= set(FILES), 'unexpected_production_files')
        for name in sorted(installed):
            path = PLUGIN + '/' + name
            b.require(client.stat(path, 'file') == 0o644 and client.read(path) == sources()[name], 'production_file_changed')
    journal = 'live' in private
    if journal: b.require(client.stat(JOURNAL, 'dir') == 0o700, 'journal_requires_0700')
    active = current == after
    b.require(not active or (backup and journal and installed == set(FILES)), 'active_installation_incomplete')
    b.require(not archived or active, 'tests_archived_before_activation')
    report.update(production_active=active, backup_verified=backup, installed_files=len(installed),
                  retired_test_plugins=len(archived), pilot_accepted=2, core_verified=True,
                  original_plugins=summary['plugins'], key_read=False, email_sent=False)
    return {'before': before, 'after': after, 'active': active, 'backup': backup, 'files': installed,
            'plugin_present': PLUGIN.rsplit('/', 1)[1] in public, 'journal': journal,
            'archive_present': ARCHIVE.rsplit('/', 1)[1] in private, 'locations': locations}


def install(client, report, parser=a.parse_config, lint=b.lint):
    state = inspect(client, report, parser)
    if state['active']:
        report.update(ok=True, already_installed=True)
        return
    for source in (*sources().values(), state['after']): lint(source)
    if not state['backup']:
        b.require(client.read(b.CONFIG) == state['before'], 'config_changed_before_backup')
        report['last_write_attempt'] = 'backup_production_config'
        client.call('backup_live')
        client.call('chmod_live', path=BACKUP)
        b.require(client.stat(BACKUP, 'file') == 0o600 and client.read(BACKUP) == state['before'], 'backup_verification_failed')
    for present, path in ((state['journal'], JOURNAL), (state['plugin_present'], PLUGIN)):
        if not present:
            report['last_write_attempt'] = 'create_' + path.rsplit('/', 1)[1]
            client.call('mkdir_live', path=path)
            b.require(client.stat(path, 'dir') == (0o755 if path == PLUGIN else 0o700), 'mkdir_verification_failed')
    for name, content in sources().items():
        if name not in state['files']:
            path = PLUGIN + '/' + name
            report['last_write_attempt'] = 'install_' + name
            client.call('write', path=path, content=content)
            client.call('chmod_live', path=path)
            b.require(client.stat(path, 'file') == 0o644 and client.read(path) == content, 'write_verification_failed')
    b.require(client.read(b.CONFIG) == state['before'], 'config_changed_before_registration')
    report['last_write_attempt'] = 'activate_production'
    client.call('write', path=b.CONFIG, content=state['after'])
    b.require(inspect(client, report, parser)['active'], 'activation_verification_failed')
    report['ok'] = True


def move(client, source, destination, report):
    parent, name = destination.rsplit('/', 1)
    b.require(name not in names(client, parent), 'archive_destination_exists')
    source_parent, source_name = source.rsplit('/', 1)
    b.require(source_name in names(client, source_parent), 'archive_source_missing')
    report['last_write_attempt'] = 'move_' + source_name
    client.call('move_test', source=source, destination=destination)
    b.require(source_name not in names(client, source_parent) and name in names(client, parent), 'move_requires_review')


def retire(client, report, parser=a.parse_config):
    state = inspect(client, report, parser)
    b.require(state['active'], 'production_must_be_active_before_retirement')
    if not state['archive_present']:
        report['last_write_attempt'] = 'create_private_archive'
        client.call('mkdir_live', path=ARCHIVE)
        b.require(client.stat(ARCHIVE, 'dir') == 0o700, 'archive_requires_0700')
    for source, destination in MOVED.items():
        if state['locations'][source] == source:
            b.require(client.read(b.CONFIG) == state['after'], 'config_changed_before_retirement')
            move(client, source, destination, report)
    verify(client, report, parser)


def verify(client, report, parser=a.parse_config):
    state = inspect(client, report, parser)
    b.require(state['active'] and report['retired_test_plugins'] == 2, 'production_or_retirement_incomplete')
    report.update(ok=True, production_verified=True)


def rollback(client, report, parser=a.parse_config):
    state = inspect(client, report, parser)
    # Restore both old directories before selecting the backed-up configuration.
    for public, archived in MOVED.items():
        if state['locations'][public] == archived: move(client, archived, public, report)
    if state['active']:
        b.require(client.read(b.CONFIG) == state['after'], 'config_changed_before_rollback')
        report['last_write_attempt'] = 'restore_pre_production_config'
        client.call('write', path=b.CONFIG, content=state['before'])
    b.require(not inspect(client, report, parser)['active'], 'rollback_verification_failed')
    report.update(ok=True, rollback_verified=True)


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('mode', choices=['inspect', 'install', 'retire', 'verify', 'rollback'])
    mode = parser.parse_args().mode
    report = {'ok': False, 'mode': 'roundcube-production-' + mode, 'key_read': False,
              'email_sent': False, 'last_write_attempt': None}
    try:
        client = Client(os.environ.get('WHM_API_TOKEN', ''), writable=mode in {'install', 'retire', 'rollback'})
        globals()[mode](client, report)
        report['ok'] = True
    except b.PreparationError as error:
        report.update(ok=False, error=str(error))
    except Exception:
        report.update(ok=False, error='production_state_requires_review')
    print(json.dumps(report))
    return 0 if report['ok'] else 1


if __name__ == '__main__':
    sys.exit(main())
