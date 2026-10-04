#!/usr/bin/env python3
"""One dated, bounded Sandbox window. The SMTP2GO key never leaves hosting."""
import json
import difflib
import os
import re
import subprocess
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path
import roundcube_prepare as base
from roundcube_prepare import ORIGIN, LIMIT, require, PreparationError

EXPIRES = 1791121500  # 2026-10-04 13:45 UTC / 14:45 Europe/Lisbon
KEY = base.PRIVATE + '/sandbox-key.txt'
CONFIRM = base.PRIVATE + '/sandbox-confirmed.txt'
TARGET = base.PLUGIN + '/config.inc.php'
ATTESTATION = 'SANDBOXED;AUDITING=OFF\n'
# User confirmed the saved MIME permission at 12:52 UTC; screenshots at 12:50
# show Sandboxed and empty Email Auditing. This is an operator attestation.
CORE_HASHES = {'program/lib/Roundcube/rcube.php': '3ceada3855e31a957c72b6904959432e7e94bd8766461f379633e5725f8a74c9', 'program/lib/Roundcube/rcube_mime.php': 'c9aa94c490221c938831ebc87cf1c998fe486f4ceb4abe8cddbc659100c1dc8a', 'program/actions/mail/send.php': '0fc30896921759735f867338f35087e091924e21da8eb0e77ce7d504ebe0e2e2', 'program/include/rcmail_sendmail.php': '081d7b5403c0e849f632fd69078188be9c550703c6d2beb277a922beba4dcc36'}
DIRECTORIES = {base.ROOT + '/' + p for p in ('program/lib', 'program/lib/Roundcube', 'program/actions', 'program/actions/mail')}
PLUGIN_SOURCE = re.compile(re.escape(base.ROOT) + r'/plugins/([a-z][a-z0-9_]*)/\1\.php\Z')


def allowed_source(path):
    return path in {base.ROOT + '/' + p for p in CORE_HASHES} or bool(PLUGIN_SOURCE.fullmatch(path))


def extra_route(operation, values):
    path = values.get('path', '')
    if operation in ('stat', 'read'):
        base.require(set(values) == {'path'}, 'unexpected_parameters')
        allowed = allowed_source(path) or path == CONFIRM
        if operation == 'stat':
            allowed = allowed or path == KEY or path in DIRECTORIES
        base.require(allowed, 'extra_path_not_allowed')
        directory, filename = path.rsplit('/', 1)
        if operation == 'stat':
            return '/json-api/cpanel', {'cpanel_jsonapi_user': 'welcome', 'cpanel_jsonapi_apiversion': 2,
                'cpanel_jsonapi_module': 'Fileman', 'cpanel_jsonapi_func': 'statfiles',
                'dir': directory, 'files': filename}, 2
        return '/json-api/uapi_cpanel', {'api.version': 1, 'cpanel.user': 'welcome',
            'cpanel.module': 'Fileman', 'cpanel.function': 'get_file_content',
            'dir': directory, 'file': filename, 'from_charset': 'UTF-8', 'to_charset': 'UTF-8'}, 3
    if operation == 'write':
        base.require(set(values) == {'path', 'content'} and path in {base.CONFIG, TARGET, CONFIRM}, 'write_not_allowed')
        base.require(isinstance(values['content'], str) and len(values['content']) <= base.LIMIT, 'content_not_allowed')
        directory, filename = path.rsplit('/', 1)
        return '/json-api/uapi_cpanel', {'api.version': 1, 'cpanel.user': 'welcome',
            'cpanel.module': 'Fileman', 'cpanel.function': 'save_file_content',
            'dir': directory, 'file': filename, 'content': values['content'],
            'from_charset': 'UTF-8', 'to_charset': 'UTF-8'}, 3
    if operation == 'protect_confirmation':
        base.require(not values, 'chmod_not_allowed')
        return '/json-api/cpanel', {'cpanel_jsonapi_user': 'welcome', 'cpanel_jsonapi_apiversion': 2,
            'cpanel_jsonapi_module': 'Fileman', 'cpanel_jsonapi_func': 'fileop', 'op': 'chmod',
            'sourcefiles': CONFIRM.removeprefix('/home/welcome/'), 'metadata': '0600', 'doubledecode': 0}, 2
    raise base.PreparationError('operation_not_allowed')


class Client(base.Client):
    def call(self, operation, **values):
        if operation in ('write', 'protect_confirmation') or (
                operation in ('stat', 'read') and values.get('path') not in
                (base.STATS if operation == 'stat' else base.READS)):
            path, params, version = extra_route(operation, values)
            # Configuration contents are POST body data, never a URL query.
            return self.request(path, params, version, post=operation == 'write')
        return super().call(operation, **values)


    def request(self, path, params, version, post=False):
        encoded = urllib.parse.urlencode(params)
        url = ORIGIN + path + ('' if post else '?' + encoded)
        request = urllib.request.Request(url, data=encoded.encode('utf-8') if post else None, headers={
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


def parse_config(source):
    check = subprocess.run(['php', str(Path(__file__).with_name('roundcube_config_summary.php'))],
                           input=source.encode(), capture_output=True, timeout=20)
    base.require(check.returncode == 0, 'dynamic_plugin_configuration_requires_review')
    data = json.loads(check.stdout)
    base.require(isinstance(data.get('plugins'), list) and isinstance(data.get('php_open'), bool), 'invalid_config_summary')
    return data


def core_diagnostic(filename, source, expected):
    # Public fixed reference download carries no hosting token or private content.
    url = 'https://raw.githubusercontent.com/roundcube/roundcubemail/1.6.19/' + filename
    opener = urllib.request.build_opener(urllib.request.ProxyHandler({}), base.NoRedirects())
    with opener.open(url, timeout=20) as response:
        raw = response.read(base.LIMIT + 1)
    base.require(len(raw) <= base.LIMIT, 'reference_too_large')
    reference = raw.decode('utf-8')
    base.require(base.digest(reference) == expected, 'upstream_reference_changed')
    summaries = []
    for value in (reference, source):
        result = subprocess.run(['php', str(Path(__file__).with_name('roundcube_config_summary.php')), '--core-summary'],
                                input=value.encode(), capture_output=True, timeout=20)
        base.require(result.returncode == 0, 'core_syntax_requires_review')
        summaries.append(json.loads(result.stdout))
    before, after = summaries
    delta = list(difflib.unified_diff(before['redacted'].splitlines(), after['redacted'].splitlines(), n=2))
    return {'reference_length': len(reference),
            'semantic_tokens_identical': before['semantic_sha256'] == after['semantic_sha256'],
            'redacted_structure_diff': delta[:80], 'diff_truncated': len(delta) > 80}


def configurations(original, summary, disabled):
    suffix = ('' if summary['php_open'] else '\n<?php\n') + '''
// WELCOME SMTP2GO dated Sandbox window; automatically inactive after expiry.
if (time() < %d && !in_array('welcome_smtp2go', (array) ($config['plugins'] ?? []), true)) {
    $config['plugins'][] = 'welcome_smtp2go';
}
''' % EXPIRES
    enabled = disabled.replace("$config['welcome_smtp2go_enabled'] = false;",
                               "$config['welcome_smtp2go_enabled'] = time() < %d;" % EXPIRES)
    base.require(enabled != disabled, 'disabled_config_not_recognized')
    return original + suffix, enabled


def activate(client, report, now=time.time, lint_check=base.lint, parser=parse_config):
    base.require(now() < EXPIRES - 300, 'sandbox_window_expired_or_too_short')
    for path in ('/home/welcome/public_html', base.ROOT, base.ROOT + '/config', base.ROOT + '/program',
                 base.ROOT + '/program/include', base.ROOT + '/plugins', base.PLUGIN, *sorted(DIRECTORIES)):
        client.stat(path, 'dir')
    base.require(client.stat(base.PRIVATE, 'dir') == 0o700, 'private_directory_requires_0700')
    base.require(client.stat(KEY, 'file') == 0o600, 'key_requires_0600')
    keymeta = client.call('stat', path=KEY)
    base.require(isinstance(keymeta, list) and len(keymeta) == 1 and
                 36 <= int(keymeta[0].get('size', 0)) <= 128, 'key_file_size_invalid')
    report['key_permissions_verified_without_reading'] = True
    base.require(re.search(r"define\s*\(\s*['\"]RCMAIL_VERSION['\"]\s*,\s*['\"]1\.6\.19['\"]\s*\)",
                          client.read(base.VERSION)), 'unexpected_roundcube_version')
    sources = {}
    for filename, expected in base.HASHES.items():
        source = client.read(base.PLUGIN + '/' + filename)
        base.require(base.digest(source) == expected, 'plugin_package_changed')
        if filename.endswith(('.php', '.php.dist')): lint_check(source)
        sources[filename] = source
    core_checks = {}
    for filename, expected in CORE_HASHES.items():
        source = client.read(base.ROOT + '/' + filename)
        core_checks[filename] = {
            'sha256': base.digest(source),
            'expected_sha256': expected,
            'normalized_newlines_sha256': base.digest(source.replace('\r\n', '\n').rstrip() + '\n'),
            'length': len(source),
        }
    report['core_file_checks'] = core_checks
    for filename, details in core_checks.items():
        if details['sha256'] != details['expected_sha256']:
            details['diagnostic'] = core_diagnostic(filename, client.read(base.ROOT + '/' + filename), details['expected_sha256'])
    base.require(all(item['sha256'] == item['expected_sha256'] for item in core_checks.values()),
                 'installed_core_differs_from_upstream')
    report['core_and_plugin_verified'] = True
    base.require(client.stat(base.BACKUP, 'file') == 0o600, 'backup_requires_0600')
    original = client.read(base.BACKUP)
    summary = parser(original)
    report['existing_plugins'] = summary['plugins']
    for plugin in summary['plugins']:
        base.require(plugin != 'welcome_smtp2go', 'sandbox_already_in_original_configuration')
        source = client.read(base.ROOT + '/plugins/' + plugin + '/' + plugin + '.php')
        base.require(not any(hook in source for hook in ('message_before_send', 'message_ready', 'message_outgoing_headers',
                     'message_outgoing_body', 'smtp_connect', 'message_sent')), 'existing_mail_hook_requires_review')
    expected_main, enabled = configurations(original, summary, sources['config.inc.php.dist'])
    lint_check(expected_main)
    lint_check(enabled)
    current_main = client.read(base.CONFIG)
    current_plugin = client.read(TARGET)
    base.require(current_main in (original, expected_main), 'main_config_changed_requires_review')
    base.require(current_plugin in (sources['config.inc.php.dist'], enabled), 'plugin_config_changed_requires_review')
    if current_main == expected_main and current_plugin == enabled:
        base.require(client.stat(CONFIRM, 'file') == 0o600 and client.read(CONFIRM) == ATTESTATION, 'confirmation_requires_review')
        report.update(ok=True, already_prepared=True, expires_utc='2026-10-04T13:45:00Z')
        return
    # Fresh operator attestation is bounded by the same fixed expiry; never extend it.
    inventory = client.call('list', directory=base.PRIVATE)
    rows = inventory.get('files') if isinstance(inventory, dict) else inventory
    base.require(isinstance(rows, list), 'invalid_private_inventory')
    if any(row.get('file') == 'sandbox-confirmed.txt' for row in rows):
        base.require(client.stat(CONFIRM, 'file') == 0o600 and client.read(CONFIRM) == ATTESTATION, 'existing_confirmation_requires_review')
    else:
        report['last_write_attempt'] = 'write_sandbox_attestation'
        client.call('write', path=CONFIRM, content=ATTESTATION)
        report['last_write_attempt'] = 'protect_sandbox_attestation'
        client.call('protect_confirmation')
        base.require(client.stat(CONFIRM, 'file') == 0o600 and client.read(CONFIRM) == ATTESTATION, 'confirmation_verification_failed')
    base.require(now() < EXPIRES - 300, 'sandbox_window_expired_or_too_short')
    if current_main == original:
        base.require(client.read(base.CONFIG) == original, 'main_config_changed_before_write')
        report['last_write_attempt'] = 'register_timed_sandbox_plugin'
        client.call('write', path=base.CONFIG, content=expected_main)
        base.require(client.read(base.CONFIG) == expected_main, 'main_write_requires_review')
    if current_plugin != enabled:
        base.require(client.read(TARGET) == sources['config.inc.php.dist'], 'plugin_config_changed_before_write')
        report['last_write_attempt'] = 'enable_timed_sandbox'
        client.call('write', path=TARGET, content=enabled)
    base.require(client.read(TARGET) == enabled and client.read(base.CONFIG) == expected_main,
                 'activation_requires_review')
    report.update(ok=True, expires_utc='2026-10-04T13:45:00Z', rollback='automatic_time_limit',
                  php_lint='passed', hosted_php_tested=False, email_sent=False, integration_tested=False)


def main():
    report = {'ok': False, 'mode': 'dated-sandbox-window', 'last_write_attempt': None, 'key_read': False}
    try:
        activate(Client(os.environ.get('WHM_API_TOKEN', '')), report)
    except base.PreparationError as error:
        report['error'] = str(error)
    except Exception:
        report['error'] = 'unexpected_error_state_requires_review'
    print(json.dumps(report))
    return 0 if report['ok'] else 1


if __name__ == '__main__': sys.exit(main())
