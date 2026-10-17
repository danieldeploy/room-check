import sys
import unittest
from pathlib import Path
from unittest.mock import patch
sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'deploy'))
import roundcube_pilot as p
from test_roundcube_activate import Fake as SandboxFake


class Fake(SandboxFake):
    def __init__(self):
        super().__init__()
        self.files[p.base.CONFIG], self.files[p.previous.TARGET] = p.previous.configurations(
            self.files[p.base.BACKUP], {'php_open': True}, self.files[p.base.PLUGIN + '/config.inc.php.dist'])
        self.directory = False

    def call(self, operation, **values):
        if operation == 'list':
            directory = values['directory']
            if directory == p.base.ROOT + '/plugins':
                return [{'file': 'welcome_smtp2go_pilot'}] if self.directory else []
            return [{'file': path.rsplit('/', 1)[1]} for path in self.files if path.rsplit('/', 1)[0] == directory]
        if operation == 'stat': return [{'size': len(self.files.get(values['path'], ''))}]
        self.writes.append((operation, values.get('path')))
        if operation == 'copy_backup':
            self.files[p.BACKUP] = self.files[p.base.CONFIG]
        elif operation == 'create_plugin': self.directory = True
        elif operation == 'write': self.files[values['path']] = values['content']
        elif operation == 'chmod': self.modes[values['path']] = 0o600 if values['path'] == p.BACKUP else 0o644


class PilotTests(unittest.TestCase):
    def run_command(self, fake, command=p.install, now=lambda: p.EXPIRES-1000):
        report = {}
        hashes = {name: p.base.digest(fake.files[p.base.PLUGIN + '/' + name]) for name in p.base.HASHES}
        core = {name: p.base.digest(name) for name in p.previous.CORE_HASHES}
        with patch.object(p.base, 'HASHES', hashes), patch.object(p.previous, 'CORE_HASHES', core):
            args = {'package': p.sources(), 'parser': lambda x: {'plugins': ['welcome_ui'], 'php_open': True}}
            if command == p.install: args.update(lint=lambda x: None, now=now)
            command(fake, report, **args)
        return report

    def test_installs_only_after_backup_and_registers_last(self):
        fake = Fake(); original = fake.files[p.base.CONFIG]
        report = self.run_command(fake)
        self.assertTrue(report['ok'])
        self.assertEqual(fake.files[p.BACKUP], original)
        self.assertEqual(fake.writes[0][0], 'copy_backup')
        self.assertEqual(fake.writes[-1], ('write', p.base.CONFIG))
        self.assertNotIn(p.previous.KEY, fake.reads)
        self.assertFalse(report['ordinary_mail_transport_changed'])
        fake.writes.clear(); self.run_command(fake)
        self.assertEqual(fake.writes, [])

    def test_rollback_restores_exact_pre_pilot_configuration(self):
        fake = Fake(); original = fake.files[p.base.CONFIG]
        self.run_command(fake)
        fake.writes.clear()
        report = self.run_command(fake, p.rollback)
        self.assertTrue(report['rollback_verified'])
        self.assertEqual(fake.files[p.base.CONFIG], original)
        self.assertEqual(fake.writes, [('write', p.base.CONFIG)])

    def test_missing_or_changed_backup_is_never_overwritten(self):
        fake = Fake(); fake.files[p.BACKUP] = 'foreign'; fake.modes[p.BACKUP] = 0o600
        with self.assertRaisesRegex(p.base.PreparationError, 'pilot_backup_changed'):
            self.run_command(fake)
        self.assertEqual(fake.writes, [])

    def test_unknown_main_config_blocks_all_writes(self):
        fake = Fake(); fake.files[p.base.CONFIG] = 'foreign config'
        with self.assertRaisesRegex(p.base.PreparationError, 'main_config_changed'):
            self.run_command(fake)
        self.assertEqual(fake.writes, [])

    def test_existing_unknown_plugin_file_blocks_all_writes(self):
        fake = Fake(); fake.directory = True
        fake.files[p.PLUGIN + '/foreign.php'] = 'foreign'
        with self.assertRaisesRegex(p.base.PreparationError, 'unexpected_pilot_files'):
            self.run_command(fake)
        self.assertEqual(fake.writes, [])

    def test_expired_installation_is_refused(self):
        fake = Fake()
        with self.assertRaisesRegex(p.base.PreparationError, 'window_unavailable'):
            self.run_command(fake, now=lambda: p.EXPIRES)
        self.assertEqual(fake.writes, [])

    def test_read_only_verification_has_no_writes(self):
        fake = Fake(); self.run_command(fake); fake.writes.clear()
        report = self.run_command(fake, p.verify)
        self.assertEqual(report['attempts'], 0)
        self.assertEqual(fake.writes, [])

    def test_client_routes_block_keys_and_unrelated_mutations(self):
        client = p.Client('fixture-token', writable=True)
        with patch.object(client.opener, 'open') as network:
            for operation, values in [('read', {'path': p.previous.KEY}),
                    ('write', {'path': p.previous.KEY, 'content': 'no'}),
                    ('chmod', {'path': p.previous.KEY}), ('mkdir', {}),
                    ('copy', {'source': p.base.CONFIG, 'destination': p.BACKUP}),
                    ('list', {'directory': '/home/welcome'})]:
                with self.subTest(operation=operation), self.assertRaises(p.base.PreparationError):
                    client.call(operation, **values)
            network.assert_not_called()

    def test_default_client_cannot_mutate(self):
        client = p.Client('fixture-token')
        for operation, values in [('write', {'path': p.base.CONFIG, 'content': 'no'}),
                                   ('create_plugin', {}), ('copy_backup', {}), ('chmod', {'path': p.BACKUP})]:
            with self.assertRaisesRegex(p.base.PreparationError, 'read_only_client'):
                client.call(operation, **values)


if __name__ == '__main__': unittest.main()
