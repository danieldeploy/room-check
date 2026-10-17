#!/usr/bin/env python3
"""Read-only Roundcube inventory through the existing protected WHM credential.

No file contents, writes, retries, user-selected routes, or raw server logs.
"""
import json
import os
import ssl
import sys
import urllib.error
import urllib.parse
import urllib.request

ORIGIN = 'https://server50.romania-webhosting.com:2087'
ROOT = '/home/welcome/public_html/roundcube'
DIRECTORIES = (ROOT, ROOT + '/config', ROOT + '/plugins',
               ROOT + '/plugins/welcome_smtp2go')
EXPECTED = {
    ROOT: ('index.php', 'program', 'config', 'plugins'),
    ROOT + '/config': ('config.inc.php',),
    ROOT + '/plugins': ('welcome_smtp2go', 'welcome_ui'),
    ROOT + '/plugins/welcome_smtp2go': (
        'welcome_smtp2go.php', 'sandbox_transport.php', 'sandbox.js',
        'config.inc.php.dist', 'config.inc.php'),
}
LIMIT = 2 * 1024 * 1024


class ProbeError(Exception):
    pass


class NoRedirects(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        raise ProbeError('redirect_refused')


def build_request(token, directory):
    if directory not in DIRECTORIES:
        raise ProbeError('directory_not_allowed')
    if not isinstance(token, str) or not token or len(token) > 4096 or not all(
            33 <= ord(char) <= 126 for char in token):
        raise ProbeError('missing_or_invalid_token')
    query = urllib.parse.urlencode({
        'api.version': 1, 'cpanel.user': 'welcome',
        'cpanel.module': 'Fileman', 'cpanel.function': 'list_files',
        'dir': directory,
    })
    return urllib.request.Request(ORIGIN + '/json-api/uapi_cpanel?' + query,
        headers={'Authorization': 'whm fazenda:' + token,
                 'Accept': 'application/json', 'Cache-Control': 'no-store',
                 'User-Agent': 'Welcome-Roundcube-ReadOnly-Inspection/1'})


def summarize(payload, directory):
    if directory not in DIRECTORIES:
        raise ProbeError('directory_not_allowed')
    if not isinstance(payload, dict) or payload.get('metadata', {}).get('result') != 1:
        raise ProbeError('whm_read_failed')
    result = payload.get('data', {}).get('uapi')
    if not isinstance(result, dict) or result.get('status') != 1 or result.get('errors') not in (None, []):
        raise ProbeError('uapi_read_failed')
    data = result.get('data')
    rows = data.get('files') if isinstance(data, dict) else data
    if not isinstance(rows, list) or not all(isinstance(row, dict) for row in rows):
        raise ProbeError('unsupported_inventory_response')
    # Only match fixed known basenames. Never print arbitrary names or metadata.
    names = {row.get('file') for row in rows if isinstance(row.get('file'), str)}
    return {name: name in names for name in EXPECTED[directory]}


def inspect(token, opener=None):
    opener = opener or urllib.request.build_opener(
        urllib.request.ProxyHandler({}), NoRedirects(),
        urllib.request.HTTPSHandler(context=ssl.create_default_context()))
    inventory = {}
    for directory in DIRECTORIES:
        if directory.endswith('/welcome_smtp2go') and not inventory[ROOT + '/plugins']['welcome_smtp2go']:
            continue
        request = build_request(token, directory)
        try:
            with opener.open(request, timeout=20) as response:
                if response.status != 200 or response.geturl() != request.full_url:
                    raise ProbeError('unexpected_http_response')
                raw = response.read(LIMIT + 1)
            if len(raw) > LIMIT:
                raise ProbeError('response_too_large')
            inventory[directory] = summarize(json.loads(raw), directory)
        except urllib.error.HTTPError as error:
            if error.code in (401, 403):
                raise ProbeError('api_access_denied_' + str(error.code)) from None
            raise ProbeError('http_error_no_retry') from None
        except (urllib.error.URLError, OSError, ValueError, UnicodeError):
            raise ProbeError('request_failed_no_retry') from None
    return {'ok': True, 'mode': 'roundcube-read-only', 'writes': False,
            'file_contents_read': False, 'inventory': inventory,
            'integration_tested': False}


def main():
    try:
        result = inspect(os.environ.get('WHM_API_TOKEN', ''))
    except ProbeError as error:
        result = {'ok': False, 'writes': False, 'error': str(error)}
    except Exception:
        result = {'ok': False, 'writes': False, 'error': 'unexpected_response_no_retry'}
    print(json.dumps(result))
    return 0 if result['ok'] else 1


if __name__ == '__main__':
    sys.exit(main())
