import unittest
import base64
import sys
from pathlib import Path
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'deploy'))
import roundcube_audit as audit


class FakeClient:
    def __init__(self):
        self.dirs = {
            audit.HOME: {'public_html', 'roundcube-smtp2go-private', 'roundcube-resend-probe.php'},
            audit.PUBLIC_HTML: {'roundcube', 'smtp2go-sandbox-probe.php'},
            audit.ROUND_ROOT: {'config', 'program', 'plugins', 'test-only.sql'},
            audit.PLUGINS: {'archive', 'managesieve', 'welcome_ui', 'welcome_smtp2go', 'welcome_smtp2go_pilot'},
            audit.PRIVATE: {'sandbox-key.txt', 'live', 'retired-tests-20261004',
                            'config-before-production-20261004.inc.php',
                            'config-before-sandbox-20261004.inc.php'},
            audit.ARCHIVE: {'welcome_smtp2go', 'welcome_smtp2go_pilot'},
            audit.ARCHIVE_SANDBOX: {'welcome_smtp2go.php', 'sandbox_transport.php',
                                    'sandbox.js', 'config.inc.php.dist', 'config.inc.php'},
            audit.ARCHIVE_PILOT: {'welcome_smtp2go_pilot.php', 'pilot_transport.php',
                                  'pilot.js', 'config.inc.php'},
            audit.SANDBOX: {'notes.txt'},
            audit.PILOT: set(),
        }
        self.stats = {}
        self.calls = []

    def list_dir(self, path):
        self.calls.append(('list', path))
        if path not in self.dirs:
            raise AssertionError('unexpected directory: ' + path)
        return self.dirs[path]

    def stat(self, path, name):
        self.calls.append(('stat', path, name))
        if name == 'sandbox-key.txt':
            return {'type': 'file', 'permissions': '0600', 'size_bytes': 72}
        return {'type': 'directory' if name in {'live', 'retired-tests-20261004',
                'welcome_smtp2go', 'welcome_smtp2go_pilot'} and path in {audit.HOME, audit.PRIVATE,
                audit.ARCHIVE, audit.PLUGINS} else 'file', 'permissions': '0700', 'size_bytes': 42}

    def dns_zone(self, zone):
        self.calls.append(('dns', zone))
        records = [
            {'line_index': 1, 'name': zone, 'type': 'TXT', 'ttl': 3600,
             'data': ['v=spf1 include:spf.smtp2go.com include:spf.mtasv.net ~all']},
            {'line_index': 2, 'name': zone, 'type': 'MX', 'ttl': 3600,
             'data': ['10 mail.example.test.']},
            {'line_index': 3, 'name': '_dmarc.' + zone, 'type': 'TXT', 'ttl': 3600,
             'data': ['v=DMARC1; p=none']},
            {'line_index': 4, 'name': 'pm._domainkey.' + zone, 'type': 'TXT', 'ttl': 3600,
             'data': ['old-postmark-key']},
            {'line_index': 5, 'name': 'resend._domainkey.' + zone, 'type': 'TXT', 'ttl': 3600,
             'data': ['old-resend-key']},
            {'line_index': 6, 'name': 'smtp2go._domainkey.' + zone, 'type': 'CNAME', 'ttl': 3600,
             'data': ['smtp2go.example.test.']},
            {'line_index': 7, 'name': 'unrelated.' + zone, 'type': 'TXT', 'ttl': 3600,
             'data': ['unrelated']},
        ]
        return audit.select_dns_records(zone, records)


class AuditTests(unittest.TestCase):
    def test_audit_is_bounded_and_reports_test_names_and_old_backups(self):
        client = FakeClient()
        report = audit.audit(client)
        self.assertTrue(report['ok'])
        self.assertFalse(report['writes'])
        self.assertFalse(report['file_contents_read'])
        self.assertFalse(report['email_sent'])
        self.assertEqual([x['zone'] for x in report['dns']], list(audit.DNS_ZONES))
        self.assertTrue(all(x['ok'] for x in report['dns']))
        dns_names = {x['name'] for x in report['dns'][0]['records']}
        self.assertIn('pm._domainkey.' + audit.DNS_ZONES[0], dns_names)
        self.assertIn('smtp2go._domainkey.' + audit.DNS_ZONES[0], dns_names)
        self.assertNotIn('unrelated.' + audit.DNS_ZONES[0], dns_names)
        self.assertEqual(report['directories'][audit.HOME][0]['name'], 'roundcube-resend-probe.php')
        self.assertEqual({x['name'] for x in report['directories'][audit.PUBLIC_HTML]},
                         {'roundcube', 'smtp2go-sandbox-probe.php'})
        self.assertEqual(report['directories'][audit.ROUND_ROOT][0]['name'], 'test-only.sql')
        self.assertIn({'name': 'notes.txt', 'type': 'file', 'permissions': '0700', 'size_bytes': 42},
                      report['directories'][audit.SANDBOX])
        private = report['directories'][audit.PRIVATE]
        self.assertEqual({x['name'] for x in private}, {
            'sandbox-key.txt', 'live', 'retired-tests-20261004',
            'config-before-production-20261004.inc.php',
            'config-before-sandbox-20261004.inc.php'})
        key = next(x for x in private if x['name'] == 'sandbox-key.txt')
        self.assertNotIn('size_bytes', key)
        self.assertFalse(any(op in {'read', 'write', 'delete'} for op, *_ in client.calls))
        self.assertNotIn(audit.HOME + '/unrelated-directory', [x[1] for x in client.calls if x[0] == 'list'])

    def test_missing_old_directories_are_reported_as_absent(self):
        client = FakeClient()
        client.dirs[audit.HOME].discard('roundcube-smtp2go-private')
        client.dirs[audit.HOME].discard('smtp2go-sandbox-test')
        client.dirs[audit.PLUGINS].discard('welcome_smtp2go')
        client.dirs[audit.PLUGINS].discard('welcome_smtp2go_pilot')
        report = audit.audit(client)
        self.assertEqual(report['directories'][audit.PRIVATE], 'absent')
        self.assertEqual(report['directories'][audit.TEMP_TEST], 'absent')
        self.assertEqual(report['directories'][audit.SANDBOX], 'absent')
        self.assertEqual(report['directories'][audit.PILOT], 'absent')

    def test_api_directory_and_stat_guards_reject_other_paths(self):
        client = audit.Client('fake-token', opener=object())
        with self.assertRaisesRegex(audit.AuditError, 'directory_not_allowed'):
            client.list_dir('/home/welcome/secret')
        with self.assertRaisesRegex(audit.AuditError, 'stat_not_allowed'):
            client.stat(audit.PRIVATE, '../outside')
        with self.assertRaisesRegex(audit.AuditError, 'stat_not_allowed'):
            client.stat('/home/welcome/secret', 'secret.txt')
        with self.assertRaisesRegex(audit.AuditError, 'dns_zone_not_allowed'):
            client.dns_zone('example.org')

    def test_dns_filter_keeps_provider_and_production_mail_records(self):
        client = FakeClient()
        report = audit.audit(client)
        names = {x['name'] for x in report['dns'][0]['records']}
        self.assertIn(audit.DNS_ZONES[0], names)  # SPF and MX at apex
        self.assertIn('_dmarc.' + audit.DNS_ZONES[0], names)
        self.assertIn('pm._domainkey.' + audit.DNS_ZONES[0], names)
        self.assertIn('resend._domainkey.' + audit.DNS_ZONES[0], names)
        self.assertIn('smtp2go._domainkey.' + audit.DNS_ZONES[0], names)
        self.assertNotIn('unrelated.' + audit.DNS_ZONES[0], names)

    def test_dns_read_falls_back_to_cpanel_uapi_when_whm_acl_denies(self):
        class UapiClient(audit.Client):
            def __init__(self):
                super().__init__('fake-token', opener=object())
                self.calls = []

            def request(self, path, params, version):
                self.calls.append((path, params, version))
                if path == '/json-api/parse_dns_zone':
                    raise audit.AuditError('whm_read_failed_permission_denied')
                return [{
                    'type': 'record', 'line_index': 2, 'ttl': 3600, 'record_type': 'TXT',
                    'dname_b64': base64.b64encode(('pm._domainkey.' + audit.DNS_ZONES[0] + '.').encode()).decode(),
                    'data_b64': [base64.b64encode(b'old-postmark-key').decode()],
                }]

        client = UapiClient()
        records = client.dns_zone(audit.DNS_ZONES[0])
        self.assertEqual(records[0]['type'], 'TXT')
        self.assertEqual(client.calls[1][1]['cpanel.module'], 'DNS')
        self.assertEqual(client.calls[1][1]['cpanel.function'], 'parse_zone')

    def test_api_responses_are_summarized_without_error_body(self):
        class Opener:
            def open(self, request, timeout):
                raise OSError('sensitive response body')
        client = audit.Client('fake-token', opener=Opener())
        with self.assertRaisesRegex(audit.AuditError, '^request_failed_no_retry$'):
            client.list_dir(audit.HOME)


if __name__ == '__main__':
    unittest.main()
