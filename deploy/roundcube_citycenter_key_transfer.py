"""Prepared operation: run only after explicit credential-transfer authorization.

Copies only the existing Welcome SMTP2GO key to City Center private storage.
Never prints the key, raw API responses, private filenames, or credential hashes.
"""
import json, os, re, sys
import roundcube_citycenter_smtp2go_dns as dns

SOURCE_DIR = '/home/welcome/roundcube-smtp2go-private'
SOURCE_FILE = 'sandbox-key.txt'
DEST_DIR = '/home/city/' + dns.BACKUP_DIR
DEST_FILE = 'api-key.txt'

def require(value, error):
    if not value: raise dns.OperationError(error)

def read(client, user, directory, filename):
    require((user, directory, filename) in {('welcome', SOURCE_DIR, SOURCE_FILE), ('city', DEST_DIR, DEST_FILE)}, 'credential_scope_violation')
    data = client.request('/json-api/uapi_cpanel', {'api.version': 1, 'cpanel.user': user,
        'cpanel.module': 'Fileman', 'cpanel.function': 'get_file_content', 'dir': directory,
        'file': filename, 'from_charset': 'UTF-8', 'to_charset': 'UTF-8'}, api_version=3)
    require(isinstance(data, dict) and isinstance(data.get('content'), str), 'credential_read_failed')
    return data['content']

def transfer():
    require(os.environ.get('CITYCENTER_KEY_TRANSFER_AUTHORIZED') == '20261008', 'explicit_transfer_authorization_missing')
    client = dns.Client(os.environ.get('WHM_API_TOKEN', ''))
    require(client.account_for_domain() == 'city', 'destination_scope_mismatch')
    accounts = client.request('/json-api/listaccts', {'api.version': 1, 'api.columns.enable': 1, 'api.columns.a': 'user', 'api.columns.b': 'domain'})
    require(sum(r.get('user') == 'welcome' and r.get('domain') == 'welcomehostel.pt' for r in accounts.get('acct', [])) == 1, 'source_scope_mismatch')
    client.user = 'welcome'
    source_dir = client.file_stat('/home/welcome', 'roundcube-smtp2go-private')
    source_key = client.file_stat(SOURCE_DIR, SOURCE_FILE)
    require(source_dir and source_dir.get('type') == 'dir' and source_dir.get('nicemode') == '0700', 'source_directory_not_private')
    if not source_key or source_key.get('type') != 'file' or source_key.get('nicemode') != '0600':
        print(json.dumps({'source_key_exists': bool(source_key), 'source_key_regular_file': bool(source_key and source_key.get('type') == 'file'), 'source_key_owner_read_only': bool(source_key and source_key.get('nicemode') == '0400'), 'source_key_owner_read_write': bool(source_key and source_key.get('nicemode') == '0600'), 'credential_published': False}))
        raise dns.OperationError('source_key_not_private')
    client.user = 'city'
    dest_dir = client.file_stat('/home/city', dns.BACKUP_DIR)
    require(dest_dir and dest_dir.get('type') == 'dir' and dest_dir.get('nicemode') == '0700', 'destination_not_private')
    key = read(client, 'welcome', SOURCE_DIR, SOURCE_FILE).strip()
    require(bool(re.fullmatch('api-[A-Za-z0-9]{32}', key)), 'source_key_format_invalid')
    exists = DEST_FILE in client.file_inventory(DEST_DIR)
    if exists:
        stat = client.file_stat(DEST_DIR, DEST_FILE)
        require(stat and stat.get('type') == 'file' and stat.get('nicemode') == '0600', 'existing_key_not_private')
        require(read(client, 'city', DEST_DIR, DEST_FILE).strip() == key, 'existing_key_differs')
    else:
        client.request('/json-api/uapi_cpanel', {'api.version': 1, 'cpanel.user': 'city',
            'cpanel.module': 'Fileman', 'cpanel.function': 'save_file_content', 'dir': DEST_DIR,
            'file': DEST_FILE, 'content': key + '\n', 'from_charset': 'UTF-8', 'to_charset': 'UTF-8'}, api_version=3, post=True)
        client.api2('fileop', op='chmod', sourcefiles=(DEST_DIR + '/' + DEST_FILE).removeprefix('/home/city/'), metadata='0600', doubledecode=0)
        stat = client.file_stat(DEST_DIR, DEST_FILE)
        require(stat and stat.get('type') == 'file' and stat.get('nicemode') == '0600', 'destination_key_not_private')
        require(read(client, 'city', DEST_DIR, DEST_FILE).strip() == key, 'key_transfer_readback_failed')
    del key
    return {'ok': True, 'private_key_transfer_verified': True, 'credential_published': False, 'source_key_changed': False, 'email_sent': False}

if __name__ == '__main__':
    try: print(json.dumps(transfer(), sort_keys=True))
    except dns.OperationError as error:
        print(json.dumps({'ok': False, 'error': str(error), 'credential_published': False, 'email_sent': False}));sys.exit(1)
    except Exception:
        print(json.dumps({'ok': False, 'error': 'credential_state_requires_review', 'credential_published': False, 'email_sent': False}));sys.exit(1)
