import base64
import sys
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'deploy'))
import roundcube_citycenter_audit as audit


class FakeClient:
    def __init__(self):
        self.calls = []
        self.dirs = {
            '/home/ccenter': {'public_html', 'roundcube-postmark-backup', 'smtp2go-test'},
            '/home/ccenter/public_html': {'roundcube', 'postmark-test.php'},
            '/home/ccenter/public_html/roundcube': {'index.php', 'program', 'config', 'plugins', 'old-test.sql'},
            '/home/ccenter/public_html/roundcube/plugins': {'archive', 'managesieve', 'citycenter_postmark', 'welcome_ui'},
            '/home/ccenter/public_html/roundcube/config': {'config.inc.php', 'config.inc.php.old'},
        }

    def account_for_domain(self):
        self.calls.append(('account', audit.DOMAIN))
        return 'ccenter'

    def list_dir(self, user, path):
        self.calls.append(('list', user, path))
        return self.dirs[path]

    def dns_zone(self, user):
        self.calls.append(('dns', user, audit.DOMAIN))
        records = [
            {'line_index': 1, 'name': audit.DOMAIN + '.', 'type': 'MX', 'ttl': 3600, 'data': ['10 mail.example.test.']},
            {'line_index': 2, 'name': audit.DOMAIN + '.', 'type': 'TXT', 'ttl': 3600, 'data': ['v=spf1 include:spf.mtasv.net ~all']},
            {'line_index': 3, 'name': '_dmarc.' + audit.DOMAIN + '.', 'type': 'TXT', 'ttl': 3600, 'data': ['v=DMARC1; p=none']},
            {'line_index': 4, 'name': 'pm._domainkey.' + audit.DOMAIN + '.', 'type': 'TXT', 'ttl': 3600, 'data': ['postmark public key']},
            {'line_index': 5, 'name': 'unrelated.' + audit.DOMAIN + '.', 'type': 'TXT', 'ttl': 3600, 'data': ['leave intact']},
        ]
        return audit.select_dns_records(records)


class CityCenterAuditTests(unittest.TestCase):
    def test_audit_is_read_only_and_uses_citycenter_account(self):
        client = FakeClient()
        report = audit.audit(client)
        self.assertTrue(report['ok'])
        self.assertTrue(report['roundcube_present'])
        self.assertFalse(report['writes'])
        self.assertFalse(report['file_contents_read'])
        self.assertFalse(report['email_sent'])
        self.assertEqual(report['account'], {'domain': audit.DOMAIN, 'home': '/home/ccenter'})
        self.assertEqual(report['directories']['/home/ccenter/public_html/roundcube/config'],
                         ['config.inc.php'])
        self.assertIn('citycenter_postmark', report['directories']['/home/ccenter/public_html/roundcube/plugins'])
        self.assertTrue(all(call[0] != 'read' and call[0] != 'write' for call in client.calls))
        self.assertTrue(all(call[1] == 'ccenter' for call in client.calls if call[0] in {'list', 'dns'}))

    def test_account_lookup_requires_exactly_one_exact_domain_match(self):
        class Lookup(audit.Client):
            def __init__(self, rows):
                self.rows = rows
                self.token = 'x'
                self.opener = None

            def request(self, path, params, api_version=1):
                self.asserted = (path, params, api_version)
                return {'acct': self.rows}

        lookup = Lookup([{'user': 'ccenter', 'domain': audit.DOMAIN},
                         {'user': 'other', 'domain': 'not-' + audit.DOMAIN}])
        self.assertEqual(lookup.account_for_domain(), 'ccenter')
        self.assertEqual(lookup.asserted[0], '/json-api/listaccts')
        for rows in ([], [{'user': 'one', 'domain': audit.DOMAIN},
                          {'user': 'two', 'domain': audit.DOMAIN}],
                     [{'user': 'bad/name', 'domain': audit.DOMAIN}]):
            with self.assertRaises(audit.AuditError):
                Lookup(rows).account_for_domain()

    def test_paths_are_fixed_to_derived_citycenter_home(self):
        client = audit.Client('token', opener=object())
        client.city_user = 'ccenter'
        with self.assertRaisesRegex(audit.AuditError, 'directory_not_allowed'):
            client.list_dir('ccenter', '/home/ccenter/etc')
        with self.assertRaisesRegex(audit.AuditError, 'directory_not_allowed'):
            client.list_dir('welcome', '/home/welcome/public_html/roundcube')

    def test_dns_filter_keeps_mail_and_provider_records_only(self):
        selected = audit.select_dns_records([
            {'line_index': 1, 'name': audit.DOMAIN + '.', 'type': 'MX', 'ttl': 3600, 'data': ['mx']},
            {'line_index': 2, 'name': 'resend._domainkey.' + audit.DOMAIN, 'type': 'TXT', 'ttl': 3600, 'data': ['key']},
            {'line_index': 3, 'name': 'unrelated.' + audit.DOMAIN, 'type': 'TXT', 'ttl': 3600, 'data': ['keep']},
        ])
        self.assertEqual([row['line_index'] for row in selected], [1, 2])

    def test_uapi_dns_response_is_decoded(self):
        class FakeApi(audit.Client):
            def __init__(self):
                self.token = 'x'
                self.opener = None
                self.city_user = 'ccenter'

            def request(self, path, params, api_version=1):
                self.asserted = (path, params, api_version)
                record = {'type': 'record', 'line_index': 9, 'ttl': 3600, 'record_type': 'CNAME',
                          'dname_b64': base64.b64encode(('em1063225.' + audit.DOMAIN + '.').encode()).decode(),
                          'data_b64': [base64.b64encode(b'return.smtp2go.net.').decode()]}
                return {'payload': [record]}

        api = FakeApi()
        result = api.dns_zone('ccenter')
        self.assertEqual(result[0]['data'], ['return.smtp2go.net.'])
        self.assertEqual(api.asserted[1]['cpanel.user'], 'ccenter')
        self.assertEqual(api.asserted[1]['cpanel.function'], 'parse_zone')


if __name__ == '__main__':
    unittest.main()
