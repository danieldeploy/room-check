"""Read only the missing production key's recovery metadata, never its value."""
import json, os, sys
import roundcube_citycenter_smtp2go_dns as dns

def inspect():
    c = dns.Client(os.environ.get('WHM_API_TOKEN', ''))
    data = c.request('/json-api/listaccts', {'api.version': 1, 'api.columns.enable': 1, 'api.columns.a': 'user', 'api.columns.b': 'domain'})
    if sum(r.get('user') == 'welcome' and r.get('domain') == 'welcomehostel.pt' for r in data.get('acct', [])) != 1:
        raise dns.OperationError('source_scope_mismatch')
    c.user = 'welcome'
    source = c.file_stat('/home/welcome/roundcube-smtp2go-private', 'sandbox-key.txt')
    names = c.file_inventory('/home/welcome/.trash')
    candidate = c.file_stat('/home/welcome/.trash', 'sandbox-key.txt') if 'sandbox-key.txt' in names else None
    return {'ok': True, 'read_only': True, 'production_key_present': bool(source),
            'exact_trash_candidate_present': bool(candidate), 'exact_trash_candidate_regular_file': bool(candidate and candidate.get('type') == 'file'),
            'exact_trash_candidate_owner_only': bool(candidate and candidate.get('nicemode') in ('0400','0600')),
            'same_basename_trash_candidate_count': sum(n == 'sandbox-key.txt' or n.startswith('sandbox-key.txt.') for n in names), 'credential_read': False}

if __name__ == '__main__':
    try: print(json.dumps(inspect(), sort_keys=True))
    except dns.OperationError as error:
        print(json.dumps({'ok': False, 'read_only': True, 'credential_read': False, 'error': str(error)}));sys.exit(1)
    except Exception:
        print(json.dumps({'ok': False, 'read_only': True, 'credential_read': False, 'error': 'recovery_metadata_requires_review'}));sys.exit(1)
