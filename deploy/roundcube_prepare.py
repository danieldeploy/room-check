#!/usr/bin/env python3
"""Prepare the reviewed Sandbox plugin, disabled, with a private verified backup.

Only fixed file reads, directory creation, server-side copies and permission
restriction are supported. No activation, SMTP credential reads, or email sends.
"""
import hashlib
import json
import os
import re
import ssl
import subprocess
import sys
import tempfile
import urllib.error
import urllib.parse
import urllib.request

ORIGIN = 'https://server50.romania-webhosting.com:2087'
ROOT = '/home/welcome/public_html/roundcube'
PLUGIN = ROOT + '/plugins/welcome_smtp2go'
PRIVATE = '/home/welcome/roundcube-smtp2go-private'
BACKUP = PRIVATE + '/config-before-sandbox-20261004.inc.php'
CONFIG = ROOT + '/config/config.inc.php'
VERSION = ROOT + '/program/include/iniset.php'
HASHES = {
    'welcome_smtp2go.php': '459d2a434747a602ea9748cbc63612ad009fb165d68104ed68da466a874c250f',
    'sandbox_transport.php': '1dc568d8f793efdb76c4f012b9c2477ab9367dd21b024e6076d865987af7b5c2',
    'sandbox.js': 'c9540418a2cf0f975074b71e1b2dc44c690256254332f2054ae4f92c202481fa',
    'config.inc.php.dist': '845c1628930f66e65b6c4831e56f4d3d7debb062cdcf4e54e6ff8e501dd034b2',
}
COPY_PAIRS = ((CONFIG, BACKUP), (PLUGIN + '/config.inc.php.dist', PLUGIN + '/config.inc.php'))
READS = {CONFIG, VERSION, BACKUP, PLUGIN + '/config.inc.php'} | {PLUGIN + '/' + x for x in HASHES}
STATS = READS | {ROOT, ROOT + '/config', ROOT + '/program', ROOT + '/program/include',
                 ROOT + '/plugins', PLUGIN, PRIVATE, '/home/welcome/public_html'}
LIMIT = 2 * 1024 * 1024


class PreparationError(Exception):
    pass


def require(value, code):
    if not value:
        raise PreparationError(code)


class NoRedirects(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        raise PreparationError('redirect_refused')


def route(operation, values):
    """Explicit route and payload allowlist; no arbitrary path/operation input."""
    if operation == 'read':
        require(set(values) == {'path'} and values['path'] in READS, 'read_not_allowed')
        directory, filename = values['path'].rsplit('/', 1)
        return '/json-api/uapi_cpanel', {'api.version': 1, 'cpanel.user': 'welcome',
            'cpanel.module': 'Fileman', 'cpanel.function': 'get_file_content',
            'dir': directory, 'file': filename, 'from_charset': 'UTF-8', 'to_charset': 'UTF-8'}, 3
    common = {'cpanel_jsonapi_user': 'welcome', 'cpanel_jsonapi_apiversion': 2,
              'cpanel_jsonapi_module': 'Fileman'}
    if operation == 'stat':
        require(set(values) == {'path'} and values['path'] in STATS, 'stat_not_allowed')
        directory, filename = values['path'].rsplit('/', 1)
        params = {'cpanel_jsonapi_func': 'statfiles', 'dir': directory, 'files': filename}
    elif operation == 'mkdir':
        require(not values, 'mkdir_not_allowed')
        params = {'cpanel_jsonapi_func': 'mkdir', 'path': '/home/welcome',
                  'name': 'roundcube-smtp2go-private', 'permissions': '0700'}
    elif operation == 'copy':
        require(set(values) == {'source', 'destination'} and
                (values['source'], values['destination']) in COPY_PAIRS, 'copy_not_allowed')
        params = {'cpanel_jsonapi_func': 'fileop', 'op': 'copy',
                  'sourcefiles': values['source'].removeprefix('/home/welcome/'),
                  'destfiles': values['destination'], 'doubledecode': 0}
    elif operation == 'chmod':
        require(set(values) == {'path'} and values['path'] == BACKUP, 'chmod_not_allowed')
        params = {'cpanel_jsonapi_func': 'fileop', 'op': 'chmod',
                  'sourcefiles': BACKUP.removeprefix('/home/welcome/'),
                  'metadata': '0600', 'doubledecode': 0}
    else:
        raise PreparationError('operation_not_allowed')
    return '/json-api/cpanel', {**common, **params}, 2


class Client:
    def __init__(self, token):
        require(isinstance(token, str) and 0 < len(token) <= 4096 and
                all(33 <= ord(c) <= 126 for c in token), 'missing_or_invalid_token')
        self.token = token
        self.opener = urllib.request.build_opener(urllib.request.ProxyHandler({}),
            NoRedirects(), urllib.request.HTTPSHandler(context=ssl.create_default_context()))

    def call(self, operation, **values):
        path, params, version = route(operation, values)
        url = ORIGIN + path + '?' + urllib.parse.urlencode(params)
        request = urllib.request.Request(url, headers={
            'Authorization': 'whm fazenda:' + self.token, 'Accept': 'application/json',
            'Cache-Control': 'no-store', 'User-Agent': 'Welcome-Roundcube-Sandbox-Preparation/1'})
        try:
            with self.opener.open(request, timeout=30) as response:
                require(response.status == 200 and response.geturl() == url, 'unexpected_http_response')
                raw = response.read(LIMIT + 1)
            require(len(raw) <= LIMIT, 'response_too_large')
            payload = json.loads(raw)
            if version == 3:
                require(payload.get('metadata', {}).get('result') == 1, 'whm_operation_failed')
                result = payload['data']['uapi']
                require(result.get('status') == 1 and not result.get('errors'), 'uapi_operation_failed')
            else:
                result = payload.get('cpanelresult')
                require(isinstance(result, dict) and result.get('event', {}).get('result') == 1
                        and not result.get('error'), 'cpanel_operation_failed')
                for item in result.get('data') or []:
                    require(not item.get('reason') and not item.get('err')
                            and item.get('result', 1) in (1, '1', True), 'file_operation_failed')
            return result.get('data')
        except PreparationError:
            raise
        except urllib.error.HTTPError as error:
            if error.code in (401, 403):
                raise PreparationError('api_access_denied_' + str(error.code)) from None
            raise PreparationError('http_error_state_requires_review') from None
        except Exception:
            raise PreparationError('request_or_response_failed_state_requires_review') from None

    def stat(self, path, kind, optional=False):
        data = self.call('stat', path=path)
        require(isinstance(data, list) and len(data) == 1, 'invalid_stat_response')
        item = data[0]
        if item.get('exists') in (0, '0', False):
            require(optional, 'required_path_missing')
            return None
        require(item.get('exists') in (1, '1', True) and item.get('type') == kind,
                'unexpected_file_type_or_symlink')
        mode = item.get('mode')
        require(isinstance(mode, (str, int)) and str(mode).isdigit(), 'unsupported_file_mode')
        mode = int(mode)
        require(mode & 0o170000 == (0o040000 if kind == 'dir' else 0o100000), 'symlink_refused')
        return mode & 0o777

    def read(self, path):
        self.stat(path, 'file')
        data = self.call('read', path=path)
        require(isinstance(data, dict) and isinstance(data.get('content'), str), 'invalid_content_response')
        return data['content']


def digest(value):
    return hashlib.sha256(value.encode('utf-8')).hexdigest()


def lint(source):
    with tempfile.TemporaryDirectory() as directory:
        path = os.path.join(directory, 'plugin.php')
        with open(path, 'w', encoding='utf-8', newline='') as handle:
            handle.write(source)
        result = subprocess.run(['php', '-l', path], capture_output=True, timeout=20)
        require(result.returncode == 0, 'plugin_php_lint_failed')


def prepare(client, report, lint_check=lint):
    for path in ('/home/welcome/public_html', ROOT, ROOT + '/config', ROOT + '/program',
                 ROOT + '/program/include', ROOT + '/plugins', PLUGIN):
        client.stat(path, 'dir')
    version = client.read(VERSION)
    require(re.search(r"define\s*\(\s*['\"]RCMAIL_VERSION['\"]\s*,\s*['\"]1\.6\.19['\"]\s*\)", version),
            'roundcube_version_not_validated')
    report['roundcube_version'] = '1.6.19'
    sources = {}
    for filename, expected in HASHES.items():
        source = client.read(PLUGIN + '/' + filename)
        require(digest(source) == expected, 'installed_plugin_differs_from_reviewed_package')
        if filename.endswith('.php') or filename.endswith('.php.dist'):
            lint_check(source)
        sources[filename] = source
    report['plugin_package_verified'] = True
    report['runner_php_lint'] = 'passed'
    original = client.read(CONFIG)
    # Only recognize the known plugin name; never log configuration content.
    report['sandbox_plugin_name_in_main_config'] = bool(re.search(r"['\"]welcome_smtp2go['\"]", original))
    private_mode = client.stat(PRIVATE, 'dir', optional=True)
    if private_mode is None:
        report['last_write_attempt'] = 'create_private_directory'
        client.call('mkdir')
        private_mode = client.stat(PRIVATE, 'dir')
    require(private_mode == 0o700, 'private_directory_requires_0700')
    report['private_directory_verified'] = True
    backup_mode = client.stat(BACKUP, 'file', optional=True)
    if backup_mode is None:
        # Check again immediately before copy; copy result and bytes are verified.
        require(client.read(CONFIG) == original, 'config_changed_before_backup')
        report['last_write_attempt'] = 'copy_config_backup'
        client.call('copy', source=CONFIG, destination=BACKUP)
        report['last_write_attempt'] = 'restrict_backup_permissions'
        client.call('chmod', path=BACKUP)
    require(client.stat(BACKUP, 'file') == 0o600, 'backup_requires_0600')
    require(client.read(BACKUP) == original, 'backup_not_equal_to_current_config')
    report['backup_verified'] = True
    target = PLUGIN + '/config.inc.php'
    if client.stat(target, 'file', optional=True) is None:
        require(client.read(CONFIG) == original, 'config_changed_before_preparation')
        report['last_write_attempt'] = 'copy_disabled_plugin_config'
        client.call('copy', source=PLUGIN + '/config.inc.php.dist', destination=target)
    require(client.read(target) == sources['config.inc.php.dist'], 'existing_plugin_config_requires_review')
    require(client.read(CONFIG) == original, 'main_config_changed_during_preparation')
    report.update(ok=True, disabled_config_verified=True, main_config_unchanged=True,
                  key_read=False, email_sent=False, plugin_activated=False,
                  hosted_php_lint=False, roundcube_integration_tested=False)


def main():
    report = {'ok': False, 'mode': 'prepare-disabled-sandbox', 'last_write_attempt': None}
    try:
        prepare(Client(os.environ.get('WHM_API_TOKEN', '')), report)
    except PreparationError as error:
        report['error'] = str(error)
    except Exception:
        report['error'] = 'unexpected_error_state_requires_review'
    print(json.dumps(report))
    return 0 if report['ok'] else 1


if __name__ == '__main__':
    sys.exit(main())
