"""Keep hosting comparisons in the manager's private Hub storage, never logs."""
import json
import os
import re
import time
import urllib.parse
import urllib.request
from cpanel_api import Config, CpanelAPI, WHM_ORIGIN, require

DIRECTORY = '/home/welcome/room-check-private/invoices'
FILENAME = 'hostelworld-city-hosting-status.json'

def summarize(trace, deliveries, now):
    domains = set(re.findall(r'/etc/vfilters/([a-z0-9.-]+)', json.dumps(trace).lower()))
    recipients = set()
    for row in deliveries:
        if not isinstance(row, dict):
            continue
        stamp = str(row.get('actionunixtime', ''))
        match = re.fullmatch(r'/home/city/mail/([a-zA-Z0-9.-]+)/[a-zA-Z0-9_.+-]+/\.HOSTELWORLD/?',
                            str(row.get('deliveredto', '')))
        if match and stamp.isdigit() and now - 21600 <= int(stamp) <= now:
            recipients.add(match[1].lower())
    identified = len(domains) == 1 and len(recipients) == 1
    return {'version': 1, 'account_id': 3, 'at': now,
            'tested_filter_identified': identified,
            'delivery_uses_tested_domain': identified and domains == recipients}

class PrivateStatusAPI:
    def __init__(self):
        self.config = Config(token=os.environ.get('WHM_API_TOKEN', ''), transport='whm', origin=WHM_ORIGIN)
        self.opener = CpanelAPI(self.config).opener

    def call(self, account, module, function, version=3, **params):
        if account == 'city':
            require((module, function, version) in [('Email', 'trace_filter', 3), ('EmailTrack', 'search', 2)],
                    'read_not_allowed')
        else:
            require(account == 'welcome' and module == 'Fileman', 'write_not_allowed')
            if function in ['save_file_content', 'get_file_content']:
                require(version == 3 and params.get('dir') == DIRECTORY and params.get('file') == FILENAME,
                        'private_path_required')
            else:
                require(version == 2 and function == 'fileop' and params == {
                    'op': 'chmod', 'sourcefiles': DIRECTORY + '/' + FILENAME,
                    'metadata': '0600', 'doubledecode': 0}, 'private_mode_required')
        if version == 2:
            route = '/json-api/cpanel'
            args = {'cpanel_jsonapi_user': account, 'cpanel_jsonapi_apiversion': 2,
                    'cpanel_jsonapi_module': module, 'cpanel_jsonapi_func': function, **params}
        else:
            route = '/json-api/uapi_cpanel'
            args = {'api.version': 1, 'cpanel.user': account, 'cpanel.module': module,
                    'cpanel.function': function, **params}
        request = urllib.request.Request(WHM_ORIGIN + route, data=urllib.parse.urlencode(args).encode(),
            headers={'Authorization': 'whm fazenda:' + self.config.token,
                     'Content-Type': 'application/x-www-form-urlencoded'})
        with self.opener.open(request, timeout=20) as response:
            raw = response.read(2097153)
        require(len(raw) <= 2097152, 'response_too_large')
        result = json.loads(raw)
        if version == 2:
            result = result.get('cpanelresult', {})
            require(result.get('event', {}).get('result') == 1 and not result.get('error'), 'api_failed')
        else:
            require(result.get('metadata', {}).get('result') == 1, 'api_failed')
            result = result.get('data', {}).get('uapi', {})
            require(result.get('status') == 1 and not result.get('errors'), 'api_failed')
        return result.get('data')

def main():
    api = PrivateStatusAPI()
    trace = api.call('city', 'Email', 'trace_filter',
        msg='From: diagnostic@hostelworld.com\nTo: diagnostic@example.invalid\nSubject: Login verification\n\nSynthetic test.\n')
    deliveries = api.call('city', 'EmailTrack', 'search', version=2, success=1, defer=1)
    require(isinstance(deliveries, list), 'invalid_delivery_response')
    facts = summarize(trace, deliveries, int(time.time()))
    # Set private permissions before writing any derived production information.
    api.call('welcome', 'Fileman', 'save_file_content', dir=DIRECTORY, file=FILENAME, content='{}')
    api.call('welcome', 'Fileman', 'fileop', version=2, op='chmod',
             sourcefiles=DIRECTORY + '/' + FILENAME, metadata='0600', doubledecode=0)
    payload = json.dumps(facts)
    api.call('welcome', 'Fileman', 'save_file_content', dir=DIRECTORY, file=FILENAME, content=payload)
    value = api.call('welcome', 'Fileman', 'get_file_content', dir=DIRECTORY, file=FILENAME)
    if isinstance(value, list) and len(value) == 1:
        value = value[0]
    require(isinstance(value, dict) and value.get('content') == payload, 'private_status_not_verified')

if __name__ == '__main__':
    try:
        main()
    except Exception:
        print('{"private_hosting_diagnostic":"failed_requires_inspection"}')
        raise SystemExit(1)
    print('{"private_hosting_diagnostic":"stored"}')
