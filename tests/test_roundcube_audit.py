import unittest
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


class AuditTests(unittest.TestCase):
    def test_audit_is_bounded_and_reports_test_names_and_old_backups(self):
        client = FakeClient()
        report = audit.audit(client)
        self.assertTrue(report['ok'])
        self.assertFalse(report['writes'])
        self.assertFalse(report['file_contents_read'])
        self.assertFalse(report['email_sent'])
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

    def test_api_responses_are_summarized_without_error_body(self):
        class Opener:
            def open(self, request, timeout):
                raise OSError('sensitive response body')
        client = audit.Client('fake-token', opener=Opener())
        with self.assertRaisesRegex(audit.AuditError, '^request_failed_no_retry$'):
            client.list_dir(audit.HOME)


if __name__ == '__main__':
    unittest.main()
