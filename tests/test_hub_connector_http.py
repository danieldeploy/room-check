"""Black-box OAuth/MCP test. Disposable MySQL, local HTTP only, synthetic identity."""
import base64
import hashlib
import http.client
from html.parser import HTMLParser
import json
import os
from pathlib import Path
import re
import socket
import subprocess
import tempfile
import time
from urllib.parse import urlencode, urlsplit, parse_qs

ROOT = Path(__file__).resolve().parents[1]
RESOURCE = 'https://check.welcomehostel.pt/mcp.php'
CALLBACK = 'https://chatgpt.com/connector_platform_oauth_redirect'


class Inputs(HTMLParser):
    def __init__(self, text):
        super().__init__()
        self.fields = {}
        self.feed(text)

    def handle_starttag(self, tag, attributes):
        a = dict(attributes)
        if tag == 'input' and 'name' in a and a.get('type') == 'hidden':
            self.fields[a['name']] = a.get('value', '')


def run():
    dsn = os.environ.get('INVOICES_TEST_DSN', '')
    assert 'dbname=' in dsn, 'Disposable database must be configured'
    db = dict(re.findall(r'(host|port|dbname)=([^;]+)', dsn))
    env = os.environ | {'ROOM_CHECK_DB_HOST': db.get('host', '127.0.0.1'),
        'ROOM_CHECK_DB_PORT': db.get('port', '3306'), 'ROOM_CHECK_DB_NAME': db['dbname'],
        'ROOM_CHECK_DB_USER': os.environ.get('INVOICES_TEST_USER', 'root'),
        'ROOM_CHECK_DB_PASS': os.environ.get('INVOICES_TEST_PASSWORD', '')}
    php = os.environ.get('TEST_PHP_BINARY', 'php')
    with socket.socket() as s:
        s.bind(('127.0.0.1', 0))
        port = s.getsockname()[1]
    with tempfile.TemporaryDirectory(prefix='hub-http-test-') as tmp:
        router = Path(tmp) / 'router.php'
        # Emulate the production HTTPS terminator only inside this test server.
        router.write_text("<?php $_SERVER['HTTPS']='on'; return false;")
        with open(Path(tmp)/'server.log', 'wb') as log:
            process = subprocess.Popen([php, '-S', f'127.0.0.1:{port}', '-t', str(ROOT), str(router)],
                cwd=ROOT, env=env, stdout=log, stderr=log)
            cookies = {}

            def request(path, body=None, headers=None, method=None, remember=True):
                assert path.startswith('/') and not path.startswith('//')
                headers = dict(headers or {})
                if remember and cookies:
                    headers['Cookie'] = '; '.join(f'{k}={v}' for k, v in cookies.items())
                conn = http.client.HTTPConnection('127.0.0.1', port, timeout=15)
                conn.request(method or ('POST' if body is not None else 'GET'), path, body, headers)
                response = conn.getresponse()
                status = response.status
                hdr = dict(response.getheaders())
                if remember:
                    for name, value in response.getheaders():
                        if name.lower() == 'set-cookie':
                            pair = value.split(';', 1)[0].split('=', 1)
                            cookies[pair[0]] = pair[1]
                data = response.read().decode()
                conn.close()
                return status, hdr, data

            form_headers = {'Content-Type': 'application/x-www-form-urlencoded'}
            json_headers = {'Content-Type': 'application/json', 'Accept': 'application/json, text/event-stream'}
            try:
                for _ in range(100):
                    try:
                        with socket.create_connection(('127.0.0.1', port), timeout=.1):
                            break
                    except OSError:
                        if process.poll() is not None:
                            raise AssertionError('Test server did not start')
                        time.sleep(.05)
                status, _, data = request('/oauth.php?action=metadata', remember=False)
                assert status == 200 and json.loads(data)['code_challenge_methods_supported'] == ['S256']
                status, _, data = request('/oauth.php?action=register', json.dumps({'redirect_uris':[CALLBACK]}), json_headers, remember=False)
                assert status == 201 and json.loads(data)['client_id'] == 'management-hub-chatgpt'
                initialize = json.dumps({'jsonrpc':'2.0','id':1,'method':'initialize','params':{'protocolVersion':'2025-06-18'}})
                status, hdr, _ = request('/mcp.php', initialize, json_headers, remember=False)
                assert status == 401 and 'resource_metadata=' in hdr['WWW-Authenticate']
                verifier = base64.urlsafe_b64encode(os.urandom(32)).rstrip(b'=').decode()
                challenge = base64.urlsafe_b64encode(hashlib.sha256(verifier.encode()).digest()).rstrip(b'=').decode()
                auth = {'client_id':'management-hub-chatgpt','redirect_uri':CALLBACK,'response_type':'code',
                    'code_challenge':challenge,'code_challenge_method':'S256','state':'http-fixture-state-2026',
                    'resource':RESOURCE,'scope':'hub:read invoices:read'}
                status, hdr, data = request('/oauth-authorize.php?'+urlencode(auth))
                assert status == 200 and 'Set-Cookie' not in hdr and 'continue' in Inputs(data).fields
                status, hdr, _ = request('/oauth-authorize.php?'+urlencode(auth|{'continue':'1'}))
                assert status == 302 and hdr['Location'] == 'login.php'
                status, _, data = request('/login.php')
                login = Inputs(data).fields | {'username':'connector_fixture','password':'another-fixture-password','language':'en'}
                status, hdr, _ = request('/login.php', urlencode(login), form_headers)
                assert status == 302 and hdr['Location'] == 'oauth-authorize.php', 'Native login must preserve OAuth context'
                status, hdr, data = request('/oauth-authorize.php')
                assert status == 200 and 'Authorize connection' in data
                # A successful HTTP 303 is insufficient when a browser's CSP blocks
                # its destination. Check the actual page policy against the callback.
                policy = dict((p.strip().split(' ', 1)+[''])[:2] for p in hdr['Content-Security-Policy'].split(';'))
                destinations = policy['form-action'].split()
                assert 'https://chatgpt.com' in destinations and "'self'" in destinations
                assert '*' not in destinations and 'https:' not in destinations
                consent = Inputs(data).fields | {'decision':'allow','scopes[]':['hub:read','invoices:read']}
                invalid = consent | {'csrf_token':'incorrect'}
                status, hdr, _ = request('/oauth-authorize.php', urlencode(invalid,doseq=True), form_headers)
                assert status == 200 and 'Location' not in hdr, 'CSRF bypass'
                status, hdr, _ = request('/oauth-authorize.php', urlencode(consent,doseq=True), form_headers)
                assert status == 303
                callback = urlsplit(hdr['Location'])
                assert f'{callback.scheme}://{callback.netloc}{callback.path}' == CALLBACK
                params = parse_qs(callback.query)
                assert params['state'] == [auth['state']] and params['iss'] == ['https://check.welcomehostel.pt']
                exchange = {'grant_type':'authorization_code','client_id':auth['client_id'],'resource':RESOURCE,
                    'redirect_uri':CALLBACK,'code':params['code'][0],'code_verifier':verifier}
                status, _, data = request('/oauth.php?action=token', urlencode(exchange), form_headers, remember=False)
                assert status == 200
                tokens = json.loads(data)
                bearer = json_headers | {'Authorization':'Bearer '+tokens['access_token']}
                status, _, data = request('/mcp.php', initialize, bearer, remember=False)
                assert status == 200 and json.loads(data)['result']['protocolVersion'] == '2025-06-18'
                status, _, data = request('/mcp.php', json.dumps({'jsonrpc':'2.0','method':'notifications/initialized'}), bearer, remember=False)
                assert status == 202 and data == ''
                profile = json.dumps({'jsonrpc':'2.0','id':2,'method':'tools/call','params':{'name':'hub_get_profile','arguments':{}}})
                status, _, data = request('/mcp.php', profile, bearer, remember=False)
                assert status == 200
                identity = json.loads(data)['result']['structuredContent']
                assert identity['username'] == 'connector_fixture'
                status, _, _ = request('/mcp.php', profile, bearer|{'Origin':'https://evil.test'}, remember=False)
                assert status == 403
                status, _, data = request('/mcp.php', json.dumps({'jsonrpc':'2.0','id':3,'method':'tools/list'}), bearer, remember=False)
                assert status == 200 and len(json.loads(data)['result']['tools']) == 4
                refresh = {'grant_type':'refresh_token','client_id':auth['client_id'],'resource':RESOURCE,'refresh_token':tokens['refresh_token']}
                status, _, data = request('/oauth.php?action=token', urlencode(refresh), form_headers, remember=False)
                assert status == 200
                renewed = json.loads(data)
                assert renewed['refresh_token'] != tokens['refresh_token']
                status, _, page = request('/admin/connections.php')
                assert status == 200 and 'Authorized connections' in page
                revoke = {'csrf_token':Inputs(page).fields['csrf_token'],'connection_id':identity['connection_id']}
                status, _, _ = request('/admin/connections.php', urlencode(revoke), form_headers)
                assert status == 200
                status, _, _ = request('/mcp.php', profile, json_headers|{'Authorization':'Bearer '+renewed['access_token']}, remember=False)
                assert status == 401
                print('HTTP OAuth native login, CSRF consent, PKCE exchange, MCP, renewal and UI revocation passed.')
            finally:
                process.terminate()
                process.wait(timeout=10)


if __name__ == '__main__':
    run()
