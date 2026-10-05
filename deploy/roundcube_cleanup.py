#!/usr/bin/env python3
"""One-time, fixed-scope cleanup of obsolete Welcome Roundcube test artifacts.

The active SMTP2GO plugin/configuration and production backup are protected by
explicit allowlists. Old public test endpoints and test-only credentials are
moved to cPanel Trash (recoverable). A private backup is created before the
single verified Postmark DNS record is removed.
"""
import json
import os
import re
import sys
import base64
import roundcube_audit as audit

HOME = audit.HOME
PRIVATE = audit.PRIVATE
ROUND_ROOT = audit.ROUND_ROOT
PLUGINS = audit.PLUGINS
ACTIVE_PLUGIN = PLUGINS + '/welcome_smtp2go_api'
CONFIG = ROUND_ROOT + '/config/config.inc.php'
PRODUCTION_BACKUP = PRIVATE + '/config-before-production-20261004.inc.php'
LIVE_JOURNAL = PRIVATE + '/live'
DNS_BACKUP = PRIVATE + '/dns-record-rollback-20261005.json'
DNS_ZONE = 'welcomehostel.pt'
POSTMARK_RECORD = {'name': 'pm-bounces', 'type': 'CNAME', 'data': ['pm.mtasv.net.']}
SMTP2GO_RECORDS = {
    ('em1063225', 'CNAME', ('return.smtp2go.net.',)),
    ('s1063225._domainkey', 'CNAME', ('dkim.smtp2go.net.',)),
}

# All are test-only. The archived sandbox/pilot package is retained separately
# because it is the reversible copy of the two retired plugin trees.
TRASH_PATHS = (
    'postmark-api-test-20261002-1327.txt',
    'resend-connectivity-20261003.txt',
    'smtp2go-connectivity-20261004.txt',
    'smtp2go-sandbox-test',
    'public_html/smtp-port-test.php',
    'public_html/roundcube/gmailports-test.php',
    'public_html/roundcube/pm2525-test.php',
    'public_html/roundcube/plugins/welcome_smtp2go',
    'public_html/roundcube/plugins/welcome_smtp2go_pilot',
    'roundcube-smtp2go-private/before-owner-20261004-pilot.js',
    'roundcube-smtp2go-private/before-owner-20261004-pilot_transport.php',
    'roundcube-smtp2go-private/config-before-live-pilot-20261004.inc.php',
    'roundcube-smtp2go-private/config-before-sandbox-20261004.inc.php',
    'roundcube-smtp2go-private/pilot-20261004-01.json',
    'roundcube-smtp2go-private/pilot-20261004-02.json',
    'roundcube-smtp2go-private/sandbox-confirmed.txt',
    'roundcube-smtp2go-private/sandbox-key.txt',
)

# Extend the read/stat allowlist only with fixed Welcome paths needed here.
audit.LISTABLE.update({ACTIVE_PLUGIN, ROUND_ROOT + '/config'})


class CleanupError(Exception):
    pass


class Client(audit.Client):
    def __init__(self, token, writable=False, opener=None):
        super().__init__(token, opener=opener)
        self.writable = writable

    def dns_rows(self, zone):
        if zone != DNS_ZONE:
            raise CleanupError('dns_zone_not_allowed')
        try:
            data = self.request('/json-api/parse_dns_zone', {'api.version': 1, 'zone': zone}, 1)
        except audit.AuditError as error:
            if 'permission_denied' not in str(error):
                raise
            data = self.request('/json-api/uapi_cpanel', {
                'api.version': 1, 'cpanel.user': 'welcome', 'cpanel.module': 'DNS',
                'cpanel.function': 'parse_zone', 'zone': zone,
            }, 3)
        rows = data if isinstance(data, list) else data.get('payload') if isinstance(data, dict) else None
        if not isinstance(rows, list):
            raise CleanupError('invalid_dns_inventory_response')
        return rows

    def call(self, operation, **values):
        if not self.writable:
            raise CleanupError('read_only_client')
        if operation == 'save_backup':
            content = values.get('content')
            if set(values) != {'content'} or not isinstance(content, str) or len(content) > 8192:
                raise CleanupError('backup_not_allowed')
            return self.request('/json-api/uapi_cpanel', {
                'api.version': 1, 'cpanel.user': 'welcome', 'cpanel.module': 'Fileman',
                'cpanel.function': 'save_file_content', 'dir': PRIVATE,
                'file': DNS_BACKUP.rsplit('/', 1)[1], 'content': content,
                'from_charset': 'UTF-8', 'to_charset': 'UTF-8',
            }, 3)
        if operation == 'chmod_backup':
            if values:
                raise CleanupError('chmod_not_allowed')
            return self.request('/json-api/cpanel', {
                'cpanel_jsonapi_user': 'welcome', 'cpanel_jsonapi_apiversion': 2,
                'cpanel_jsonapi_module': 'Fileman', 'cpanel_jsonapi_func': 'fileop',
                'op': 'chmod', 'sourcefiles': DNS_BACKUP.removeprefix(HOME + '/'),
                'metadata': '0600', 'doubledecode': 0,
            }, 2)
        if operation == 'remove_postmark_dns':
            if set(values) != {'serial', 'line'} or not all(
                    isinstance(values[x], int) and values[x] >= 0 for x in ('serial', 'line')):
                raise CleanupError('dns_remove_not_allowed')
            return self.request('/json-api/uapi_cpanel', {
                'api.version': 1, 'cpanel.user': 'welcome', 'cpanel.module': 'DNS',
                'cpanel.function': 'mass_edit_zone', 'zone': DNS_ZONE,
                'serial': values['serial'], 'remove': values['line'],
            }, 3)
        if operation == 'trash_test_path':
            path = values.get('path')
            if set(values) != {'path'} or path not in TRASH_PATHS:
                raise CleanupError('trash_path_not_allowed')
            return self.request('/json-api/cpanel', {
                'cpanel_jsonapi_user': 'welcome', 'cpanel_jsonapi_apiversion': 2,
                'cpanel_jsonapi_module': 'Fileman', 'cpanel_jsonapi_func': 'fileop',
                'op': 'trash', 'sourcefiles': path, 'doubledecode': 0,
            }, 2)
        raise CleanupError('operation_not_allowed')


def _decoded(value):
    return base64.b64decode(value, validate=True).decode('utf-8', 'replace')


def _target_dns(rows):
    records = []
    serials = []
    for row in rows:
        if not isinstance(row, dict) or row.get('type') != 'record':
            continue
        try:
            name = _decoded(row['dname_b64']).rstrip('.').lower()
            data = [_decoded(x) for x in row['data_b64']]
            kind = row['record_type'].upper()
            line = int(row['line_index'])
            ttl = int(row['ttl'])
        except (KeyError, TypeError, ValueError):
            raise CleanupError('invalid_dns_record') from None
        record = {'name': name, 'type': kind, 'data': data, 'line_index': line, 'ttl': ttl}
        if kind == 'SOA' and name == DNS_ZONE:
            if len(data) < 3 or not data[2].isdigit():
                raise CleanupError('invalid_zone_serial')
            serials.append(int(data[2]))
        if name == POSTMARK_RECORD['name'] and kind == POSTMARK_RECORD['type'] and data == POSTMARK_RECORD['data']:
            records.append(record)
    if len(serials) != 1:
        raise CleanupError('zone_serial_not_unique')
    if len(records) > 1:
        raise CleanupError('duplicate_postmark_record')
    return serials[0], records


def _record_key(record):
    return (record['name'], record['type'], tuple(record['data']))


def _assert_production_preserved(client):
    if client.stat(HOME, PRIVATE.rsplit('/', 1)[1])['permissions'] != '0700':
        raise CleanupError('private_directory_changed')
    backup = client.stat(PRIVATE, PRODUCTION_BACKUP.rsplit('/', 1)[1])
    journal = client.stat(PRIVATE, LIVE_JOURNAL.rsplit('/', 1)[1])
    config = client.stat(ROUND_ROOT + '/config', 'config.inc.php')
    active = client.list_dir(ACTIVE_PLUGIN)
    if backup['type'] != 'file' or backup['permissions'] != '0600':
        raise CleanupError('production_backup_missing_or_permissions_changed')
    if journal['type'] != 'directory' or journal['permissions'] != '0700':
        raise CleanupError('production_journal_missing_or_permissions_changed')
    if config['type'] != 'file' or 'api_transport.php' not in active or 'welcome_smtp2go_api.php' not in active:
        raise CleanupError('production_installation_missing')
    return {'production_backup': 'preserved', 'live_journal': 'preserved',
            'active_plugin_files': sorted(active), 'roundcube_config': 'present'}


def _dns_backup(client):
    private = client.list_dir(PRIVATE)
    if DNS_BACKUP.rsplit('/', 1)[1] in private:
        raise CleanupError('dns_backup_already_exists')
    rows = client.dns_rows(DNS_ZONE)
    serial, matches = _target_dns(rows)
    if len(matches) != 1:
        raise CleanupError('postmark_record_missing_or_changed')
    record = matches[0]
    backup = {'purpose': 'rollback-only; inactive DNS record', 'zone': DNS_ZONE,
              'record': record, 'serial_before': serial}
    content = json.dumps(backup, sort_keys=True) + '\n'
    client.call('save_backup', content=content)
    client.call('chmod_backup')
    meta = client.stat(PRIVATE, DNS_BACKUP.rsplit('/', 1)[1])
    if meta['type'] != 'file' or meta['permissions'] != '0600':
        raise CleanupError('dns_backup_verification_failed')
    # Re-read after saving; use only a fresh serial and exact current line.
    current_serial, current_matches = _target_dns(client.dns_rows(DNS_ZONE))
    if len(current_matches) != 1 or _record_key(current_matches[0]) != _record_key(record):
        raise CleanupError('postmark_record_changed_before_removal')
    return current_serial, current_matches[0], record


def _remove_postmark_dns(client, serial, record):
    result = client.call('remove_postmark_dns', serial=serial, line=record['line_index'])
    rows = client.dns_rows(DNS_ZONE)
    after_serial, remaining = _target_dns(rows)
    if remaining:
        raise CleanupError('postmark_dns_removal_unverified')
    selected = audit.select_dns_records(DNS_ZONE, [
        {'line_index': int(row['line_index']),
         'name': _decoded(row['dname_b64']),
         'type': row['record_type'],
         'ttl': int(row['ttl']),
         'data': [_decoded(x) for x in row['data_b64']]}
        for row in rows if isinstance(row, dict) and row.get('type') == 'record'
    ])
    after_keys = {_record_key({'name': r['name'].rstrip('.').lower(), 'type': r['type'].upper(), 'data': r['data']})
                  for r in selected}
    if not SMTP2GO_RECORDS <= after_keys:
        raise CleanupError('smtp2go_dns_record_missing_after_cleanup')
    if not isinstance(result, dict):
        raise CleanupError('dns_api_result_invalid')
    return {'postmark_cname': 'removed', 'smtp2go_records': 'preserved',
            'serial_after': after_serial}


def _trash_files(client):
    removed = []
    skipped = []
    for relative in TRASH_PATHS:
        full = HOME + '/' + relative
        parent, name = full.rsplit('/', 1)
        try:
            present = name in client.list_dir(parent)
        except audit.AuditError as error:
            # For directories below the public plugins folder, API inventory can
            # be absent if the parent itself has already been removed.
            if 'directory_not_allowed' in str(error):
                skipped.append(relative)
                continue
            raise
        if not present:
            skipped.append(relative)
            continue
        response = client.call('trash_test_path', path=relative)
        data = response.get('data') if isinstance(response, dict) else None
        if isinstance(data, list) and data and data[0].get('result') not in (1, '1', True):
            raise CleanupError('trash_operation_failed')
        if isinstance(response, dict) and response.get('event', {}).get('result') not in (1, '1', True):
            raise CleanupError('trash_operation_failed')
        if name in client.list_dir(parent):
            raise CleanupError('trash_verification_failed')
        removed.append(relative)
    return {'moved_to_recoverable_trash': removed, 'already_absent': skipped}


def cleanup(client):
    if not client.writable:
        raise CleanupError('read_only_client')
    production = _assert_production_preserved(client)
    serial, record, old_record = _dns_backup(client)
    files = _trash_files(client)
    dns = _remove_postmark_dns(client, serial, record)
    after = _assert_production_preserved(client)
    # Final DNS verification, including every production SMTP2GO record.
    final_rows = client.dns_rows(DNS_ZONE)
    _, final_pm = _target_dns(final_rows)
    if final_pm:
        raise CleanupError('postmark_record_reappeared')
    final_records = audit.select_dns_records(DNS_ZONE, [
        {'line_index': int(row['line_index']), 'name': _decoded(row['dname_b64']),
         'type': row['record_type'], 'ttl': int(row['ttl']),
         'data': [_decoded(x) for x in row['data_b64']]}
        for row in final_rows if isinstance(row, dict) and row.get('type') == 'record'
    ])
    keys = {_record_key({'name': r['name'].rstrip('.').lower(), 'type': r['type'].upper(), 'data': r['data']})
            for r in final_records}
    if not SMTP2GO_RECORDS <= keys:
        raise CleanupError('smtp2go_dns_record_missing_after_cleanup')
    return {'ok': True, 'mode': 'welcome-roundcube-cleanup', 'writes': True,
            'email_sent': False, 'file_contents_read': False,
            'production_before': production, 'production_after': after,
            'dns': dns, 'dns_backup': DNS_BACKUP, 'files': files,
            'retired_test_archive': 'retained_for_rollback'}


def main():
    if os.environ.get('CONFIRM_CLEANUP') != 'CLEANUP-WELCOME-EMAIL-TESTS':
        print(json.dumps({'ok': False, 'writes': False, 'error': 'explicit_cleanup_confirmation_required'}))
        return 2
    try:
        result = cleanup(Client(os.environ.get('WHM_API_TOKEN', ''), writable=True))
        print(json.dumps(result, sort_keys=True))
        return 0
    except (CleanupError, audit.AuditError) as error:
        print(json.dumps({'ok': False, 'writes': False, 'error': str(error)}))
        return 1
    except Exception:
        print(json.dumps({'ok': False, 'writes': False, 'error': 'cleanup_state_requires_review'}))
        return 1


if __name__ == '__main__':
    sys.exit(main())
