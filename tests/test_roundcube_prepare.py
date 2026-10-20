import importlib.util
import unittest
from unittest.mock import patch

spec = importlib.util.spec_from_file_location('prep', 'deploy/roundcube_prepare.py')
p = importlib.util.module_from_spec(spec)
spec.loader.exec_module(p)


class Fake:
    def __init__(self):
        self.files = {p.VERSION: "define('RCMAIL_VERSION', '1.6.19');", p.CONFIG: '<?php // private config'}
        self.files.update({p.PLUGIN + '/' + name: name for name in p.HASHES})
        self.modes = {}
        self.writes = []

    def stat(self, path, kind, optional=False):
        if path in self.modes:
            return self.modes[path]
        if optional and path not in self.files:
            return None
        return 0o755 if kind == 'dir' else 0o644

    def read(self, path):
        return self.files[path]

    def call(self, operation, **values):
        p.route(operation, values)
        self.writes.append(operation)
        if operation == 'mkdir':
            self.modes[p.PRIVATE] = 0o700
        elif operation == 'copy':
            self.files[values['destination']] = self.files[values['source']]
        elif operation == 'chmod':
            self.modes[p.BACKUP] = 0o600


class SafetyTests(unittest.TestCase):
    def run_prepare(self, fake):
        report = {}
        hashes = {name: p.digest(name) for name in p.HASHES}
        with patch.object(p, 'HASHES', hashes):
            p.prepare(fake, report, lint_check=lambda source: None)
        return report

    def test_verified_backup_and_disabled_copy_idempotent(self):
        fake = Fake()
        report = self.run_prepare(fake)
        self.assertTrue(report['ok'])
        self.assertFalse(report['email_sent'])
        self.assertFalse(report['plugin_activated'])
        self.assertEqual(fake.files[p.BACKUP], fake.files[p.CONFIG])
        self.assertEqual(fake.modes[p.BACKUP], 0o600)
        self.assertEqual(fake.writes, ['mkdir', 'copy', 'chmod', 'copy'])
        fake.writes.clear()
        self.run_prepare(fake)
        self.assertEqual(fake.writes, [])

    def test_version_mismatch_no_writes(self):
        fake = Fake()
        fake.files[p.VERSION] = "define('RCMAIL_VERSION', '1.6.18');"
        with self.assertRaisesRegex(p.PreparationError, 'version'):
            self.run_prepare(fake)
        self.assertEqual(fake.writes, [])

    def test_modified_package_no_writes(self):
        fake = Fake()
        fake.files[p.PLUGIN + '/sandbox.js'] = 'changed'
        with self.assertRaisesRegex(p.PreparationError, 'differs'):
            self.run_prepare(fake)
        self.assertEqual(fake.writes, [])

    def test_insecure_private_directory_no_writes(self):
        fake = Fake()
        fake.modes[p.PRIVATE] = 0o755
        with self.assertRaisesRegex(p.PreparationError, '0700'):
            self.run_prepare(fake)
        self.assertEqual(fake.writes, [])

    def test_existing_backup_never_overwritten(self):
        fake = Fake()
        fake.modes[p.PRIVATE] = 0o700
        fake.modes[p.BACKUP] = 0o600
        fake.files[p.BACKUP] = 'different backup'
        with self.assertRaisesRegex(p.PreparationError, 'backup_not_equal'):
            self.run_prepare(fake)
        self.assertEqual(fake.writes, [])

    def test_existing_plugin_config_never_overwritten(self):
        fake = Fake()
        self.run_prepare(fake)
        fake.writes.clear()
        fake.files[p.PLUGIN + '/config.inc.php'] = 'custom configuration'
        with self.assertRaisesRegex(p.PreparationError, 'requires_review'):
            self.run_prepare(fake)
        self.assertEqual(fake.writes, [])

    def test_arbitrary_paths_and_operations_rejected(self):
        for op, values in [('read', {'path': p.PRIVATE + '/sandbox-key.txt'}),
                           ('copy', {'source': p.CONFIG, 'destination': '/tmp/public'}),
                           ('chmod', {'path': p.CONFIG}), ('delete', {})]:
            with self.assertRaises(p.PreparationError):
                p.route(op, values)

    def test_symlinks_rejected(self):
        client = p.Client('test-token')
        with patch.object(client, 'call', return_value=[{'exists': 1, 'type': 'file', 'mode': 0o120777}]):
            with self.assertRaisesRegex(p.PreparationError, 'symlink'):
                client.stat(p.CONFIG, 'file')

    def test_missing_optional_path_uses_parent_inventory(self):
        client = p.Client('test-token')
        with patch.object(client, 'call', return_value=[]) as call:
            self.assertIsNone(client.stat(p.PRIVATE, 'dir', optional=True))
            call.assert_called_once_with('list', directory='/home/welcome')

    def test_existing_optional_path_still_checks_permissions(self):
        client = p.Client('test-token')
        with patch.object(client, 'call', side_effect=[
                [{'file': 'roundcube-smtp2go-private'}],
                [{'exists': 1, 'type': 'dir', 'mode': 0o40700}]]):
            self.assertEqual(client.stat(p.PRIVATE, 'dir', optional=True), 0o700)

    def test_failed_inventory_never_means_missing(self):
        client = p.Client('test-token')
        with patch.object(client, 'call', return_value=None):
            with self.assertRaisesRegex(p.PreparationError, 'inventory'):
                client.stat(p.PRIVATE, 'dir', optional=True)


if __name__ == '__main__':
    unittest.main()
