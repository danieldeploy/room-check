"""Prepare a disabled City Center plugin with a verified private config backup."""
import json, os, sys
from pathlib import Path
import roundcube_citycenter_smtp2go_dns as dns
import roundcube_citycenter_requirements as requirements

HOME = '/home/city'
ROOT = requirements.ROOT
PRIVATE = HOME + '/' + dns.BACKUP_DIR
BACKUP = 'config-before-smtp2go-20261008.inc.php'
PLUGIN = ROOT + '/plugins/citycenter_smtp2go_api'
FILES = ('api_transport.php', 'citycenter_smtp2go_api.php', 'config.inc.php')

def require(value, message):
    if not value:
        raise dns.OperationError(message)

def stat(client, directory, name, kind, mode=None):
    result = client.file_stat(directory, name)
    require(result and result.get('type') == kind, 'unsafe_or_missing_path')
    if mode is not None:
        require(result.get('nicemode') == mode, 'unsafe_file_permissions')
    return result

def sources():
    folder = Path(__file__).with_name('roundcube-citycenter-live')
    result = {name: (folder / name).read_text() for name in FILES}
    result['config.inc.php'] = "<?php\n$config['citycenter_smtp2go_api_enabled'] = false;\n"
    return result

def save(client, directory, name, content):
    allowed = {(PLUGIN, filename) for filename in FILES} | {(PRIVATE, BACKUP)}
    require((directory, name) in allowed, 'write_scope_violation')
    client.request('/json-api/uapi_cpanel', {'api.version': 1, 'cpanel.user': 'city',
        'cpanel.module': 'Fileman', 'cpanel.function': 'save_file_content', 'dir': directory,
        'file': name, 'content': content, 'from_charset': 'UTF-8', 'to_charset': 'UTF-8'}, api_version=3, post=True)
    client.api2('fileop', op='chmod', sourcefiles=(directory + '/' + name).removeprefix(HOME + '/'),
                metadata='0600' if directory == PRIVATE else '0644', doubledecode=0)
    stat(client, directory, name, 'file', '0600' if directory == PRIVATE else '0644')
    require(requirements.read(client, directory, name) == content, 'file_readback_mismatch')

def prepare():
    audit = requirements.audit()
    require(audit['production_mailbox_exists'] and audit['roundcube_version_matches_transport'] and
            audit['mail_hook_conflict_count'] == 0 and audit['smtp2go_auth_records'] == 2, 'transport_prerequisites_not_met')
    client = dns.Client(os.environ.get('WHM_API_TOKEN', ''))
    require(client.account_for_domain() == 'city', 'account_scope_mismatch')
    for directory, name in ((HOME, dns.BACKUP_DIR), (HOME, 'public_html'), (HOME + '/public_html', 'roundcube'), (ROOT, 'config'), (ROOT, 'plugins')):
        stat(client, directory, name, 'dir', '0700' if name == dns.BACKUP_DIR else None)
    stat(client, ROOT + '/config', 'config.inc.php', 'file')
    original = requirements.read(client, ROOT + '/config', 'config.inc.php')
    require('citycenter_smtp2go_api' not in original, 'plugin_already_registered')
    private_names = client.file_inventory(PRIVATE)
    if BACKUP in private_names:
        stat(client, PRIVATE, BACKUP, 'file', '0600')
        require(requirements.read(client, PRIVATE, BACKUP) == original, 'existing_backup_does_not_match_config')
    else:
        save(client, PRIVATE, BACKUP, original)
    if 'live' not in client.file_inventory(PRIVATE):
        client.api2('mkdir', path=PRIVATE, name='live', permissions='0700')
    stat(client, PRIVATE, 'live', 'dir', '0700')
    if 'citycenter_smtp2go_api' not in client.file_inventory(ROOT + '/plugins'):
        client.api2('mkdir', path=ROOT + '/plugins', name='citycenter_smtp2go_api', permissions='0755')
    stat(client, ROOT + '/plugins', 'citycenter_smtp2go_api', 'dir', '0755')
    names = client.file_inventory(PLUGIN) - {'.', '..'}
    require(names <= set(FILES), 'unrecognized_plugin_files')
    for name, content in sources().items():
        if name in names:
            stat(client, PLUGIN, name, 'file', '0644')
            require(requirements.read(client, PLUGIN, name) == content, 'existing_plugin_file_mismatch')
        else:
            save(client, PLUGIN, name, content)
    require(requirements.read(client, ROOT + '/config', 'config.inc.php') == original, 'main_configuration_changed')
    return {'ok': True, 'mode': 'citycenter-disabled-plugin-preparation', 'private_config_backup_verified': True,
            'plugin_files_verified': len(FILES), 'plugin_enabled': False, 'main_config_changed': False,
            'email_sent': False, 'credentials_read': False, 'private_contents_published': False}

if __name__ == '__main__':
    try:
        print(json.dumps(prepare(), sort_keys=True))
    except dns.OperationError as error:
        print(json.dumps({'ok': False, 'mode': 'citycenter-disabled-plugin-preparation', 'email_sent': False, 'state_requires_review': True, 'error': str(error)}))
        sys.exit(1)
    except Exception:
        print(json.dumps({'ok': False, 'mode': 'citycenter-disabled-plugin-preparation', 'email_sent': False, 'state_requires_review': True, 'error': 'private_state_requires_review'}))
        sys.exit(1)
