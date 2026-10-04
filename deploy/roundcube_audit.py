#!/usr/bin/env python3
"""Read-only, fixed-path inventory of Roundcube email-test leftovers."""
import json
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


def _metadata(client, directory, names, hide_size=False):
    result = []
    for name in sorted(names, key=str.casefold):
        meta = client.stat(directory, name)
        entry = {'name': name, **meta}
        if hide_size and name == 'sandbox-key.txt':
            entry.pop('size_bytes', None)
        result.append(entry)
    return result


def audit(client):
    home = client.list_dir(HOME)
    plugin_roots = client.list_dir(PLUGINS)
    result = {'ok': True, 'mode': 'roundcube-read-only-audit', 'writes': False,
              'file_contents_read': False, 'email_sent': False, 'directories': {}}

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
        print(json.dumps(audit(Client(os.environ.get('WHM_API_TOKEN', ''))), sort_keys=True))
        return 0
    except AuditError as error:
        print(json.dumps({'ok': False, 'writes': False, 'error': str(error)}))
        return 1


if __name__ == '__main__':
    sys.exit(main())
