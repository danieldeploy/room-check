import io
import json
import unittest
import urllib.error
from deploy.roundcube_inspect import (ROOT, DIRECTORIES, LIMIT, ProbeError, build_request,
                            inspect, summarize)


def payload(files):
    return {'metadata': {'result': 1}, 'data': {'uapi': {
        'status': 1, 'errors': None, 'data': {'files': files}}}}


class Response(io.BytesIO):
    status = 200
    def __init__(self, request, body):
        super().__init__(body)
        self.url = request.full_url
    def geturl(self):
        return self.url


class ProbeTests(unittest.TestCase):
    def test_only_fixed_account_and_read_function(self):
        for directory in DIRECTORIES:
            request = build_request('test-token', directory)
            self.assertEqual(request.get_method(), 'GET')
            self.assertNotIn('test-token', request.full_url)
            self.assertIn('cpanel.user=welcome', request.full_url)
            self.assertIn('cpanel.function=list_files', request.full_url)
        for directory in ('/home/other', ROOT + '/../check', ROOT + '/config/..'):
            with self.assertRaises(ProbeError):
                build_request('test-token', directory)

    def test_no_raw_names_contents_or_metadata_in_report(self):
        source = payload([{'file': 'config.inc.php', 'contents': 'PRIVATE-TOKEN'},
                          {'file': 'PRIVATE-ADDRESS', 'size': 'PRIVATE-TOKEN'}])
        result = summarize(source, ROOT + '/config')
        self.assertEqual(result, {'config.inc.php': True})
        self.assertNotIn('PRIVATE', json.dumps(result))

    def test_api_errors_cannot_leak_raw_body(self):
        source = payload([])
        source['data']['uapi']['errors'] = ['PRIVATE-TOKEN']
        with self.assertRaisesRegex(ProbeError, '^uapi_read_failed$'):
            summarize(source, ROOT)

    def test_missing_plugin_skips_its_directory(self):
        calls = []
        class Opener:
            def open(self, request, timeout):
                calls.append(request)
                return Response(request, json.dumps(payload([])).encode())
        result = inspect('test-token', Opener())
        self.assertEqual(len(calls), 3)
        self.assertFalse(result['writes'])
        self.assertFalse(result['file_contents_read'])

    def test_denial_stops_without_retry(self):
        calls = []
        class Opener:
            def open(self, request, timeout):
                calls.append(request)
                raise urllib.error.HTTPError(request.full_url, 403, 'PRIVATE-TOKEN', {}, None)
        with self.assertRaisesRegex(ProbeError, '^api_access_denied_403$'):
            inspect('test-token', Opener())
        self.assertEqual(len(calls), 1)

    def test_size_limit_rejects_response(self):
        class Opener:
            def open(self, request, timeout):
                return Response(request, b'x' * (LIMIT + 1))
        with self.assertRaisesRegex(ProbeError, '^response_too_large$'):
            inspect('test-token', Opener())


if __name__ == '__main__':
    unittest.main()
