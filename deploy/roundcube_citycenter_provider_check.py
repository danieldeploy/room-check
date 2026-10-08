"""Inspect only City Center sender-domain verification with the authorized key."""
import json, os, re, ssl, sys, urllib.request, urllib.error
import roundcube_citycenter_smtp2go_dns as dns
import roundcube_citycenter_key_transfer as keys

ALLOWED_BOOLEAN_FIELDS = ('verified', 'dkim_verified', 'returnpath_verified', 'spf_verified', 'tracking_verified')

def check():
    client = dns.Client(os.environ.get('WHM_API_TOKEN', ''))
    keys.require(client.account_for_domain() == 'city', 'account_scope_mismatch')
    stat = client.file_stat(keys.DEST_DIR, keys.DEST_FILE)
    keys.require(stat and stat.get('type') == 'file' and stat.get('nicemode') == '0600', 'private_key_not_ready')
    key = keys.read(client, 'city', keys.DEST_DIR, keys.DEST_FILE).strip()
    keys.require(bool(re.fullmatch('api-[A-Za-z0-9]{32}', key)), 'private_key_invalid')
    opener = urllib.request.build_opener(urllib.request.ProxyHandler({}), dns.NoRedirects(), urllib.request.HTTPSHandler(context=ssl.create_default_context()))
    request = urllib.request.Request('https://api.smtp2go.com/v3/domain/view', data=json.dumps({'domain': dns.DOMAIN}).encode(), headers={'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Smtp2go-Api-Key': key})
    try:
        with opener.open(request, timeout=25) as response:
            keys.require(response.status == 200 and response.geturl() == request.full_url, 'provider_response_failed')
            payload = json.loads(response.read(1024 * 1024))
    except urllib.error.HTTPError as error:
        raise dns.OperationError('provider_read_access_denied' if error.code in (400,401,403) else 'provider_http_failure') from None
    del key
    data = payload.get('data', {})
    keys.require(not data.get('error_code'), 'provider_read_failed')
    records = data.get('domains', [])
    if isinstance(records, dict): records = list(records.values())
    if not isinstance(records, list): raise dns.OperationError('provider_domain_shape_unknown')
    matching = [r for r in records if isinstance(r, dict) and str(r.get('domain', '')).rstrip('.').lower() == dns.DOMAIN]
    flags = {}
    if len(matching) == 1:
        for field in ALLOWED_BOOLEAN_FIELDS:
            value = matching[0].get(field)
            if isinstance(value, bool): flags[field] = value
            elif value in ('true', 'false', 0, 1): flags[field] = value in ('true', 1)
    return {'ok': True, 'read_only': True, 'domain_records_found': len(matching),
            'domain_verification_flags': flags, 'credential_published': False, 'email_sent': False,
            'domain_list_shape_recognized': isinstance(data.get('domains'), (list,dict))}

if __name__ == '__main__':
    try: print(json.dumps(check(), sort_keys=True))
    except dns.OperationError as error:
        print(json.dumps({'ok': False, 'read_only': True, 'email_sent': False, 'error': str(error)}));sys.exit(1)
    except Exception:
        print(json.dumps({'ok': False, 'read_only': True, 'email_sent': False, 'error': 'provider_state_requires_review'}));sys.exit(1)
