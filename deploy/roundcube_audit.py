#!/usr/bin/env python3
"""Read-only, fixed-path inventory of Roundcube email-test leftovers."""
import json
import base64
import os
import re
import ssl
import sys
import urllib.error
import urllib.parse
import urllib.request

ORIGIN = 'https://server50.romania-webhosting.com:2087'
HOME = '/home/welcome'
PUBLIC_HTML = HOME + '/public_html'
ROUND_ROOT = PUBLIC_HTML + '/roundcube'
PLUGINS = HOME + '/public_html/roundcube/plugins'
PRIVATE = HOME + '/roundcube-smtp2go-private'
ARCHIVE = PRIVATE + '/retired-tests-20261004'
TEMP_TEST = HOME + '/smtp2go-sandbox-test'
SANDBOX = PLUGINS + '/welcome_smtp2go'
PILOT = PLUGINS + '/welcome_smtp2go_pilot'
DNS_ZONES = ('welcomehostel.pt', 'citycenterhostel.pt')
ARCHIVE_SANDBOX = ARCHIVE + '/welcome_smtp2go'
ARCHIVE_PILOT = ARCHIVE + '/welcome_smtp2go_pilot'
LISTABLE = {HOME, PUBLIC_HTML, ROUND_ROOT, PLUGINS, PRIVATE, ARCHIVE, TEMP_TEST, SANDBOX, PILOT,
            ARCHIVE_SANDBOX, ARCHIVE_PILOT}
LIMIT = 2 * 1024 * 1024
SAFE_BASENAME = re.compile(r'^[A-Za-z0-9._ -]{1,180}$')
PRIVATE_NAME = re.compile(r'(?i)(roundcube|smtp2go|postmark|resend|sandbox|pilot|config-before|retired-tests|test)')


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
        self.opener = opener or urllib.request.build_opener(
            urllib.request.ProxyHandler({}), NoRedirects(),
            urllib.request.HTTPSHandler(context=ssl.create_default_context()))

    def request(self, path, params, version):
        url = ORIGIN + path + '?' + urllib.parse.urlencode(params)
        request = urllib.request.Request(url, headers={
            'Authorization': 'whm fazenda:' + self.token,
            'Accept': 'application/json', 'Cache-Control': 'no-store',
            'User-Agent': 'Welcome-Roundcube-ReadOnly-Audit/1'})
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

        if version == 3:
            if not isinstance(payload, dict) or payload.get('metadata', {}).get('result') != 1:
                raise AuditError('whm_read_failed')
            result = payload.get('data', {}).get('uapi')
            if not isinstance(result, dict) or result.get('status') != 1 or result.get('errors') not in (None, []):
                raise AuditError('uapi_read_failed')
            return result.get('data')

        if version == 1:
            if not isinstance(payload, dict) or payload.get('metadata', {}).get('result') != 1:
                raise AuditError('whm_read_failed')
            data = payload.get('data')
            if not isinstance(data, dict):
                raise AuditError('whm_dns_read_failed')
            return data

        result = payload.get('cpanelresult') if isinstance(payload, dict) else None
        if not isinstance(result, dict) or result.get('event', {}).get('result') != 1 or result.get('error'):
            raise AuditError('cpanel_read_failed')
        return result.get('data')

    def list_dir(self, directory):
        if directory not in LISTABLE:
            raise AuditError('directory_not_allowed')
        data = self.request('/json-api/uapi_cpanel', {
            'api.version': 1, 'cpanel.user': 'welcome',
            'cpanel.module': 'Fileman', 'cpanel.function': 'list_files',
            'dir': directory,
        }, 3)
        rows = data.get('files') if isinstance(data, dict) else data
        if not isinstance(rows, list) or not all(isinstance(row, dict) for row in rows):
            raise AuditError('invalid_inventory_response')
        names = []
        for row in rows:
            name = row.get('file')
            if not isinstance(name, str) or not SAFE_BASENAME.fullmatch(name) or name in ('.', '..'):
                raise AuditError('invalid_inventory_name')
            names.append(name)
        if len(names) != len(set(names)):
            raise AuditError('duplicate_inventory_name')
        return set(names)

    def stat(self, directory, name):
        if directory not in LISTABLE or not isinstance(name, str) or not SAFE_BASENAME.fullmatch(name) or name in ('.', '..'):
            raise AuditError('stat_not_allowed')
        data = self.request('/json-api/cpanel', {
            'cpanel_jsonapi_user': 'welcome', 'cpanel_jsonapi_apiversion': 2,
            'cpanel_jsonapi_module': 'Fileman', 'cpanel_jsonapi_func': 'statfiles',
            'dir': directory, 'files': name,
        }, 2)
        if not isinstance(data, list) or len(data) != 1 or not isinstance(data[0], dict):
            raise AuditError('invalid_stat_response')
        item = data[0]
        if item.get('exists') in (0, '0', False):
            raise AuditError('listed_item_missing')
        if item.get('exists') not in (1, '1', True):
            raise AuditError('invalid_stat_existence')
        mode = item.get('mode')
        if not isinstance(mode, (int, str)) or not str(mode).isdigit():
            raise AuditError('invalid_stat_mode')
        size = item.get('size', 0)
        try:
            size = int(size)
        except (TypeError, ValueError):
            raise AuditError('invalid_stat_size') from None
        if size < 0:
            raise AuditError('invalid_stat_size')
        raw_mode = int(mode)
        kind_bits = raw_mode & 0o170000
        if kind_bits == 0o040000:
            kind = 'directory'
        elif kind_bits == 0o100000:
            kind = 'file'
        else:
            raise AuditError('unsupported_file_type')
        return {'type': kind, 'permissions': format(raw_mode & 0o777, '04o'), 'size_bytes': size}

    def dns_zone(self, zone):
        if zone not in DNS_ZONES:
            raise AuditError('dns_zone_not_allowed')
        data = self.request('/json-api/parse_dns_zone', {
            'api.version': 1, 'zone': zone,
        }, 1)
        rows = data.get('payload') if isinstance(data, dict) else None
        if not isinstance(rows, list):
            raise AuditError('invalid_dns_inventory_response')
        records = []
        for row in rows:
            if not isinstance(row, dict) or row.get('type') != 'record':
                continue
            try:
                name = base64.b64decode(row['dname_b64'], validate=True).decode('utf-8')
                values = row['data_b64']
                if not isinstance(values, list):
                    raise ValueError
                values = [base64.b64decode(value, validate=True).decode('utf-8') for value in values]
                line_index = int(row['line_index'])
                ttl = int(row['ttl'])
                record_type = row['record_type']
                if not isinstance(record_type, str) or not record_type or line_index < 0 or ttl < 0:
                    raise ValueError
            except (KeyError, TypeError, ValueError, UnicodeDecodeError):
                raise AuditError('invalid_dns_record') from None
            records.append({'line_index': line_index, 'name': name, 'type': record_type,
                            'ttl': ttl, 'data': values})
        return select_dns_records(zone, records)


def _metadata(client, directory, names, hide_size=False):
    result = []
    for name in sorted(names, key=str.casefold):
        meta = client.stat(directory, name)
        entry = {'name': name, **meta}
        if hide_size and name == 'sandbox-key.txt':
            entry.pop('size_bytes', None)
        result.append(entry)
    return result


def select_dns_records(zone, records):
    """Keep only mail-routing and mail-authentication DNS records for review/rollback."""
    if zone not in DNS_ZONES or not isinstance(records, list):
        raise AuditError('dns_zone_not_allowed')
    apex = zone.rstrip('.').lower()
    selected = []
    for record in records:
        name = record.get('name', '').rstrip('.').lower()
        record_type = record.get('type', '').upper()
        data = ' '.join(record.get('data', [])).lower()
        relevant = bool(re.search(r'(postmark|resend|smtp2go|pm-bounces)', name + ' ' + data))
        relevant |= name == apex and record_type == 'MX'
        relevant |= name == apex and record_type == 'TXT' and 'v=spf1' in data
        relevant |= name == '_dmarc.' + apex and record_type == 'TXT'
        relevant |= name.endswith('._domainkey.' + apex) and record_type in {'TXT', 'CNAME'}
        if relevant:
            selected.append(record)
    return sorted(selected, key=lambda r: (r['line_index'], r['name']))


def audit(client):
    home = client.list_dir(HOME)
    plugin_roots = client.list_dir(PLUGINS)
    result = {'ok': True, 'mode': 'roundcube-read-only-audit', 'writes': False,
              'file_contents_read': False, 'email_sent': False, 'directories': {}}

    dns = []
    for zone in DNS_ZONES:
        try:
            dns.append({'zone': zone, 'ok': True, 'records': client.dns_zone(zone)})
        except AuditError as error:
            dns.append({'zone': zone, 'ok': False, 'error': str(error)})
            result['ok'] = False
    result['dns'] = dns

    for directory, entries in ((HOME, home), (PUBLIC_HTML, client.list_dir(PUBLIC_HTML)),
                                (ROUND_ROOT, client.list_dir(ROUND_ROOT))):
        relevant = {item for item in entries if PRIVATE_NAME.search(item)}
        result['directories'][directory] = _metadata(
            client, directory, relevant, hide_size=True)

    for name in ('roundcube-smtp2go-private', 'smtp2go-sandbox-test'):
        if name in home:
            path = HOME + '/' + name
            if name == 'roundcube-smtp2go-private':
                entries = client.list_dir(PRIVATE)
                selected = {item for item in entries if PRIVATE_NAME.search(item)}
                # Include all journal count only; never emit individual message IDs.
                if 'live' in entries:
                    selected.add('live')
                result['directories'][path] = _metadata(
                    client, PRIVATE, selected, hide_size=True)
                if 'retired-tests-20261004' in entries:
                    archive_items = client.list_dir(ARCHIVE)
                    archive_meta = _metadata(client, ARCHIVE, archive_items)
                    result['directories'][ARCHIVE] = archive_meta
                    for child in ('welcome_smtp2go', 'welcome_smtp2go_pilot'):
                        if child in archive_items:
                            child_path = ARCHIVE + '/' + child
                            result['directories'][child_path] = _metadata(
                                client, child_path, client.list_dir(child_path))
            else:
                result['directories'][path] = _metadata(
                    client, TEMP_TEST, client.list_dir(TEMP_TEST), hide_size=True)
        else:
            result['directories'][HOME + '/' + name] = 'absent'

    for name in ('welcome_smtp2go', 'welcome_smtp2go_pilot'):
        if name in plugin_roots:
            path = PLUGINS + '/' + name
            result['directories'][path] = _metadata(client, path, client.list_dir(path))
        else:
            result['directories'][PLUGINS + '/' + name] = 'absent'

    return result


def main():
    try:
        result = audit(Client(os.environ.get('WHM_API_TOKEN', '')))
        print(json.dumps(result, sort_keys=True))
        return 0 if result.get('ok') else 1
    except AuditError as error:
        print(json.dumps({'ok': False, 'writes': False, 'error': str(error)}))
        return 1


if __name__ == '__main__':
    sys.exit(main())
