#!/usr/bin/env python3
"""Read-only Roundcube and mail DNS inventory for City Center via WHM API."""
import base64
import json
import os
import re
import ssl
import sys
import urllib.error
import urllib.parse
import urllib.request

ORIGIN = 'https://server50.romania-webhosting.com:2087'
DOMAIN = 'citycenterhostel.pt'
SAFE_USER = re.compile(r'^[a-z][a-z0-9]{0,15}$')
SAFE_NAME = re.compile(r'^[A-Za-z0-9._ -]{1,180}$')
LIMIT = 2 * 1024 * 1024


class AuditError(Exception):
    pass


class NoRedirects(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        raise AuditError('redirect_refused')


class Client:
    def __init__(self, token, opener=None):
        if not isinstance(token, str) or not 0 < len(token) <= 4096 or not all(
                33 <= ord(char) <= 126 for char in token):
            raise AuditError('missing_or_invalid_token')
        self.token = token
        self.city_user = None
        self.opener = opener or urllib.request.build_opener(
            urllib.request.ProxyHandler({}), NoRedirects(),
            urllib.request.HTTPSHandler(context=ssl.create_default_context()))

    def request(self, path, params, api_version=1):
        url = ORIGIN + path + '?' + urllib.parse.urlencode(params)
        request = urllib.request.Request(url, headers={
            'Authorization': 'whm fazenda:' + self.token,
            'Accept': 'application/json', 'Cache-Control': 'no-store',
            'User-Agent': 'CityCenter-Roundcube-ReadOnly-Audit/1'})
        try:
            with self.opener.open(request, timeout=20) as response:
                if response.status != 200 or response.geturl() != url:
                    raise AuditError('unexpected_http_response')
                raw = response.read(LIMIT + 1)
            if len(raw) > LIMIT:
                raise AuditError('response_too_large')
            payload = json.loads(raw)
        except AuditError:
            raise
        except urllib.error.HTTPError as error:
            if error.code in (401, 403):
                raise AuditError('api_access_denied_' + str(error.code)) from None
            raise AuditError('http_error_no_retry') from None
        except Exception:
            raise AuditError('request_failed_no_retry') from None
        if not isinstance(payload, dict) or payload.get('metadata', {}).get('result') != 1:
            raise AuditError('whm_read_failed')
        if api_version == 3:
            result = payload.get('data', {}).get('uapi')
            if not isinstance(result, dict) or result.get('status') != 1 or result.get('errors') not in (None, []):
                raise AuditError('uapi_read_failed')
            return result.get('data')
        return payload.get('data')

    def account_for_domain(self):
        data = self.request('/json-api/listaccts', {
            'api.version': 1,
            'api.columns.enable': 1,
            'api.columns.a': 'user',
            'api.columns.b': 'domain',
        })
        rows = data.get('acct') if isinstance(data, dict) else None
        if not isinstance(rows, list):
            raise AuditError('account_inventory_unavailable')
        matches = [row for row in rows if isinstance(row, dict) and
                   str(row.get('domain', '')).rstrip('.').lower() == DOMAIN]
        if len(matches) != 1:
            raise AuditError('citycenter_account_not_uniquely_resolved')
        user = matches[0].get('user')
        if not isinstance(user, str) or not SAFE_USER.fullmatch(user):
            raise AuditError('invalid_account_identifier')
        self.city_user = user
        return user

    def list_dir(self, user, path):
        if not SAFE_USER.fullmatch(user) or user != self.city_user or path not in {
                f'/home/{user}', f'/home/{user}/public_html',
                f'/home/{user}/public_html/roundcube',
                f'/home/{user}/public_html/roundcube/plugins',
                f'/home/{user}/public_html/roundcube/config'}:
            raise AuditError('directory_not_allowed')
        data = self.request('/json-api/uapi_cpanel', {
            'api.version': 1, 'cpanel.user': user,
            'cpanel.module': 'Fileman', 'cpanel.function': 'list_files',
            'dir': path,
        }, api_version=3)
        rows = data.get('files') if isinstance(data, dict) else data
        if not isinstance(rows, list) or not all(isinstance(row, dict) for row in rows):
            raise AuditError('invalid_inventory_response')
        names = []
        for row in rows:
            name = row.get('file')
            if not isinstance(name, str) or not SAFE_NAME.fullmatch(name) or name in ('.', '..'):
                raise AuditError('invalid_inventory_name')
            names.append(name)
        if len(names) != len(set(names)):
            raise AuditError('duplicate_inventory_name')
        return set(names)

    def dns_zone(self, user):
        if not SAFE_USER.fullmatch(user) or user != self.city_user:
            raise AuditError('account_scope_violation')
        data = self.request('/json-api/uapi_cpanel', {
            'api.version': 1, 'cpanel.user': user,
            'cpanel.module': 'DNS', 'cpanel.function': 'parse_zone',
            'zone': DOMAIN,
        }, api_version=3)
        rows = data if isinstance(data, list) else data.get('payload') if isinstance(data, dict) else None
        if not isinstance(rows, list):
            raise AuditError('invalid_dns_inventory_response')
        records = []
        for row in rows:
            if not isinstance(row, dict) or row.get('type') != 'record':
                continue
            try:
                name = base64.b64decode(row['dname_b64'], validate=True).decode('utf-8', 'replace')
                values = row['data_b64']
                if not isinstance(values, list):
                    raise ValueError
                values = [base64.b64decode(value, validate=True).decode('utf-8', 'replace') for value in values]
                index, ttl = int(row['line_index']), int(row['ttl'])
                record_type = row['record_type']
                if index < 0 or ttl < 0 or not isinstance(record_type, str) or not record_type:
                    raise ValueError
            except (KeyError, TypeError, ValueError):
                raise AuditError('invalid_dns_record') from None
            records.append({'line_index': index, 'name': name, 'type': record_type,
                            'ttl': ttl, 'data': values})
        return select_dns_records(records)


def select_dns_records(records):
    apex = DOMAIN
    selected = []
    for record in records:
        name = record.get('name', '').rstrip('.').lower()
        kind = record.get('type', '').upper()
        data = ' '.join(record.get('data', [])).lower()
        relevant = bool(re.search(r'(postmark|resend|smtp2go|pm-bounces)', name + ' ' + data))
        relevant |= name == apex and kind == 'MX'
        relevant |= name == apex and kind == 'TXT' and 'v=spf1' in data
        relevant |= name == '_dmarc.' + apex and kind == 'TXT'
        relevant |= name.endswith('._domainkey.' + apex) and kind in {'TXT', 'CNAME'}
        if relevant:
            selected.append(record)
    return sorted(selected, key=lambda row: (row['line_index'], row['name']))


def flags(name_lists):
    names = [name for group in name_lists for name in group]
    lowered = [name.lower() for name in names]
    return {
        'smtp2go': any('smtp2go' in name for name in lowered),
        'postmark': any('postmark' in name or 'mtasv' in name for name in lowered),
        'resend': any('resend' in name for name in lowered),
        'sandbox_or_pilot': any('sandbox' in name or 'pilot' in name for name in lowered),
        'test_artifact': any('test' in name for name in lowered),
        'backup_artifact': any('backup' in name or 'before-' in name or 'old' in name for name in lowered),
    }


def dns_flags(records):
    names = [record.get('name', '').rstrip('.').lower() for record in records]
    values = [' '.join(record.get('data', [])).lower() for record in records]
    combined = names + values
    return {
        'smtp2go_records': any('smtp2go' in item for item in combined),
        'postmark_records': any('postmark' in item or 'mtasv' in item or 'pm-bounces' in item for item in combined),
        'resend_records': any('resend' in item for item in combined),
        'mx_present': any(record.get('name', '').rstrip('.').lower() == DOMAIN and record.get('type', '').upper() == 'MX' for record in records),
        'spf_present': any(record.get('name', '').rstrip('.').lower() == DOMAIN and record.get('type', '').upper() == 'TXT' and 'v=spf1' in ' '.join(record.get('data', [])).lower() for record in records),
        'dmarc_present': any(record.get('name', '').rstrip('.').lower() == '_dmarc.' + DOMAIN and record.get('type', '').upper() == 'TXT' for record in records),
        'dkim_selector_count': sum(1 for record in records if record.get('name', '').rstrip('.').lower().endswith('._domainkey.' + DOMAIN)),
    }


def audit(client):
    user = client.account_for_domain()
    home = f'/home/{user}'
    public = home + '/public_html'
    root = public + '/roundcube'
    home_names = client.list_dir(user, home)
    public_names = client.list_dir(user, public)
    inventories = [home_names, public_names]
    roundcube_present = False
    config_present = False
    if 'roundcube' in public_names:
        root_names = client.list_dir(user, root)
        inventories.append(root_names)
        roundcube_present = all(name in root_names for name in ('index.php', 'program', 'config', 'plugins'))
        if 'plugins' in root_names:
            plugins = client.list_dir(user, root + '/plugins')
            inventories.append(plugins)
        if 'config' in root_names:
            config = client.list_dir(user, root + '/config')
            inventories.append(config)
            config_present = 'config.inc.php' in config
    records = client.dns_zone(user)
    return {
        'ok': True,
        'mode': 'citycenter-roundcube-read-only-audit',
        'writes': False,
        'file_contents_read': False,
        'email_sent': False,
        'target': DOMAIN,
        'account_resolved': True,
        'roundcube_present': roundcube_present,
        'roundcube_config_file_present': config_present,
        'server_artifact_flags': flags(inventories),
        'mail_dns_flags': dns_flags(records),
    }


def main():
    try:
        result = audit(Client(os.environ.get('WHM_API_TOKEN', '')))
        print(json.dumps(result, sort_keys=True))
        return 0
    except AuditError as error:
        print(json.dumps({'ok': False, 'writes': False, 'email_sent': False,
                          'mode': 'citycenter-roundcube-read-only-audit',
                          'error': str(error)}))
        return 1


if __name__ == '__main__':
    sys.exit(main())
