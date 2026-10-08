"""Read-only private Roundcube inspection; output aggregate flags only."""
import json, os, re, subprocess, sys
import roundcube_citycenter_smtp2go_dns as dns

ROOT = '/home/city/public_html/roundcube'

def read(client, directory, filename):
    data = client.request('/json-api/uapi_cpanel', {'api.version': 1, 'cpanel.user': 'city', 'cpanel.module': 'Fileman', 'cpanel.function': 'get_file_content', 'dir': directory, 'file': filename, 'from_charset': 'UTF-8', 'to_charset': 'UTF-8'}, api_version=3)
    if not isinstance(data, dict) or not isinstance(data.get('content'), str):
        raise dns.OperationError('private_read_failed')
    return data['content']

def audit():
    client = dns.Client(os.environ.get('WHM_API_TOKEN', ''))
    if client.account_for_domain() != 'city':
        raise dns.OperationError('account_scope_mismatch')
    config = read(client, ROOT + '/config', 'config.inc.php')
    result = subprocess.run(['php', 'deploy/roundcube_config_summary.php'], input=config, text=True, capture_output=True, timeout=20)
    if result.returncode != 0:
        raise dns.OperationError('config_static_analysis_failed')
    summary = json.loads(result.stdout)
    mail_run = subprocess.run(['php', 'deploy/roundcube_config_summary.php', '--mail-summary'], input=config, text=True, capture_output=True, timeout=20)
    if mail_run.returncode != 0: raise dns.OperationError('mail_config_analysis_failed')
    mail_summary = json.loads(mail_run.stdout)
    names = summary['plugins']
    conflicts = 0
    provider_conflicts = 0
    for name in names:
        if not re.fullmatch('[a-z][a-z0-9_]*', name):
            raise dns.OperationError('plugin_scope_mismatch')
        code = read(client, ROOT + '/plugins/' + name, name + '.php')
        conflict = any(x in code for x in ('message_before_send', 'message_outgoing_headers', 'message_outgoing_body', 'smtp_connect', 'message_sent'))
        conflicts += int(conflict)
        provider_conflicts += int(conflict and any(x in name for x in ('postmark', 'resend')))
    iniset = read(client, ROOT + '/program/include', 'iniset.php')
    version = re.search(r"define\s*\(\s*['\"]RCMAIL_VERSION['\"]\s*,\s*['\"]([^'\"]+)['\"]", iniset)
    data = client.request('/json-api/uapi_cpanel', {'api.version': 1, 'cpanel.user': 'city', 'cpanel.module': 'Email', 'cpanel.function': 'list_pops'}, api_version=3)
    rows = data if isinstance(data, list) else data.get('pops', []) if isinstance(data, dict) else []
    private = '/home/city/' + dns.BACKUP_DIR
    filenames = client.file_inventory(private)
    config_backup = client.file_stat(private, 'config-before-smtp2go-20261008.inc.php')
    plugin_dir_exists = 'citycenter_smtp2go_api' in client.file_inventory(ROOT + '/plugins')
    installed_count = len(client.file_inventory(ROOT + '/plugins/citycenter_smtp2go_api') - {'.', '..'}) if plugin_dir_exists else 0
    key_names = ('api-key.txt', 'sandbox-key.txt')
    key_present = False
    for name in key_names:
        if name in filenames:
            stat = client.file_stat(private, name)
            key_present |= bool(stat and stat.get('type') == 'file' and stat.get('nicemode') == '0600')
    return {'ok': True, 'read_only': True, 'private_contents_published': False,
            'config_backup_present': bool(config_backup), 'config_backup_secure': bool(config_backup and config_backup.get('nicemode') == '0600'), 'new_plugin_directory_present': plugin_dir_exists, 'installed_plugin_file_count': installed_count, 'smtp_route_provider': mail_summary['smtp_route_provider'], 'nonplaceholder_smtp_credential_fields': mail_summary['nonplaceholder_smtp_credential_fields'], 'active_plugin_count': len(names), 'mail_hook_conflict_count': conflicts,
            'provider_hook_conflict_count': provider_conflicts,
            'roundcube_version_matches_transport': bool(version and version[1] == '1.6.19'),
            'production_mailbox_exists': any(str(r.get('email', '')).lower() == 'info@citycenterhostel.pt' for r in rows),
            'citycenter_private_key_present': key_present,
            'postmark_config_marker': any(x in config.lower() for x in ('postmark', 'mtasv')),
            'resend_config_marker': 'resend' in config.lower(),
            'smtp2go_auth_records': sum(bool(dns.matching(client.dns_records(), r)) for r in dns.RECORDS)}

if __name__ == '__main__':
    try:
        print(json.dumps(audit(), sort_keys=True))
    except dns.OperationError as error:
        print(json.dumps({'ok': False, 'read_only': True, 'error': str(error)}))
        sys.exit(1)
    except Exception:
        print(json.dumps({'ok': False, 'read_only': True, 'error': 'private_state_requires_review'}))
        sys.exit(1)
