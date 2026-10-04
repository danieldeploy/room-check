import json
import shutil
import subprocess
import unittest
from unittest.mock import patch
import test_roundcube_owner as prior
import roundcube_live as live


class Fake(prior.Fake):
    def __init__(self):
        super().__init__()
        self.dirs = None

    def call(self, operation, **values):
        if self.dirs is None: return super().call(operation, **values)
        if operation == 'list':
            directory = values['directory']
            return [{'file': path.rsplit('/', 1)[1]} for path in set(self.files) | self.dirs
                    if path.rsplit('/', 1)[0] == directory]
        if operation == 'stat': return [{'size': len(self.files.get(values['path'], ''))}]
        self.writes.append((operation, dict(values)))
        if operation == 'backup_live': self.files[live.BACKUP] = self.files[live.b.CONFIG]
        elif operation == 'mkdir_live':
            self.dirs.add(values['path'])
            self.modes[values['path']] = 0o755 if values['path'] == live.PLUGIN else 0o700
        elif operation == 'chmod_live':
            self.modes[values['path']] = 0o600 if values['path'] == live.BACKUP else 0o644
        elif operation == 'write': self.files[values['path']] = values['content']
        elif operation == 'move_test':
            source, dest = values['source'], values['destination']
            self.dirs.remove(source); self.dirs.add(dest)
            for path in list(self.files):
                if path.startswith(source + '/'):
                    target = dest + path[len(source):]
                    self.files[target] = self.files.pop(path)
                    self.modes[target] = self.modes.pop(path, 0o644)
        else: raise AssertionError(operation)


class LiveTests(unittest.TestCase):
    def fixture(self):
        f = Fake()
        prior.pilot_tests.PilotTests().run_command(f)
        t = prior.OwnerTests()
        t.journal(f, '01'); t.run_command(f); t.journal(f, '02')
        f.dirs = {live.b.PLUGIN, live.p.PLUGIN, live.b.PRIVATE}
        f.writes.clear()
        self.hashes = {n: live.b.digest(f.files[live.b.PLUGIN + '/' + n]) for n in live.b.HASHES}
        return f

    def run_command(self, f, command=live.install):
        args = {'parser': lambda x: {'plugins': ['welcome_ui'], 'php_open': True}}
        if command == live.install: args['lint'] = lambda x: None
        report = {}
        core = {n: live.b.digest(n) for n in live.a.CORE_HASHES}
        with patch.object(live.b, 'HASHES', self.hashes), patch.object(live.a, 'CORE_HASHES', core):
            command(f, report, **args)
        return report

    def test_activation_preserves_original_and_journals_backup_first_registration_last(self):
        f = self.fixture(); before = dict(f.files)
        self.run_command(f)
        self.assertEqual(f.writes[0][0], 'backup_live')
        self.assertEqual(f.writes[-1][1]['path'], live.b.CONFIG)
        self.assertEqual(f.files[live.BACKUP], before[live.b.CONFIG])
        for path, value in before.items():
            if path != live.b.CONFIG: self.assertEqual(f.files[path], value)
        self.assertNotIn(live.a.KEY, f.reads)
        f.writes.clear(); self.run_command(f); self.assertEqual(f.writes, [])

    def test_retirement_then_rollback_restores_folders_before_main(self):
        f = self.fixture(); before = dict(f.files)
        self.run_command(f); result = self.run_command(f, live.retire)
        self.assertEqual(result['retired_test_plugins'], 2)
        self.assertFalse(set(live.OLD) & f.dirs)
        f.writes.clear(); self.run_command(f, live.verify); self.assertEqual(f.writes, [])
        self.run_command(f, live.retire); self.assertEqual(f.writes, [])
        self.run_command(f, live.rollback)
        self.assertEqual([x[0] for x in f.writes], ['move_test', 'move_test', 'write'])
        for path, value in before.items(): self.assertEqual(f.files[path], value)
        self.assertIn(live.JOURNAL, f.dirs)

    def test_retirement_requires_active_production(self):
        f = self.fixture()
        with self.assertRaisesRegex(live.b.PreparationError, 'must_be_active'): self.run_command(f, live.retire)
        self.assertEqual(f.writes, [])

    def test_partial_move_failure_is_inspectable_and_reversible_without_repeating_move(self):
        f = self.fixture(); self.run_command(f)
        call = f.call
        def interrupt(op, **v):
            result = call(op, **v)
            if op == 'move_test': raise live.b.PreparationError('uncertain_network_result')
            return result
        with patch.object(f, 'call', side_effect=interrupt), self.assertRaises(live.b.PreparationError):
            self.run_command(f, live.retire)
        f.writes.clear(); result = self.run_command(f, live.inspect)
        self.assertEqual(result['retired_test_plugins'], 1); self.assertEqual(f.writes, [])
        self.run_command(f, live.rollback)
        self.assertEqual([x[0] for x in f.writes], ['move_test', 'write'])

    def test_unknown_files_configs_backups_and_permissions_block_mutation(self):
        for case in ('main', 'backup', 'sandbox_extra', 'pilot_extra', 'live_extra', 'key_mode', 'original', 'journal'):
            f = self.fixture()
            if case == 'main': f.files[live.b.CONFIG] = 'foreign'
            elif case == 'backup': f.files[live.BACKUP] = 'foreign'; f.modes[live.BACKUP] = 0o600
            elif case == 'sandbox_extra': f.files[live.b.PLUGIN + '/foreign.php'] = 'foreign'
            elif case == 'pilot_extra': f.files[live.p.PLUGIN + '/foreign.php'] = 'foreign'
            elif case == 'live_extra': f.dirs.add(live.PLUGIN); f.files[live.PLUGIN + '/foreign.php'] = 'foreign'
            elif case == 'key_mode': f.modes[live.a.KEY] = 0o644
            elif case == 'original': f.files[live.owner.BACKUPS['config.inc.php']] = 'foreign'
            elif case == 'journal':
                path = sorted(live.p.JOURNALS)[1]
                f.files[path] = f.files[path].splitlines()[0] + '\n'
            with self.subTest(case=case), self.assertRaises(live.b.PreparationError): self.run_command(f)
            self.assertEqual(f.writes, [])

    def test_uncertain_registration_is_read_only_inspectable(self):
        f = self.fixture(); call = f.call
        def interrupt(op, **v):
            result = call(op, **v)
            if op == 'write' and v['path'] == live.b.CONFIG: raise live.b.PreparationError('network_timeout')
            return result
        with patch.object(f, 'call', side_effect=interrupt), self.assertRaises(live.b.PreparationError): self.run_command(f)
        f.writes.clear(); result = self.run_command(f, live.inspect)
        self.assertTrue(result['production_active']); self.assertEqual(f.writes, [])

    def test_client_allowlists_prevent_key_access_deletion_and_arbitrary_moves(self):
        for writable in (False, True):
            client = live.Client('fixture-token', writable=writable)
            with patch.object(client, 'request') as network:
                cases = [('read', {'path': live.a.KEY}), ('delete', {'path': live.b.CONFIG}),
                    ('write', {'path': live.a.KEY, 'content': 'no'}),
                    ('write', {'path': live.PLUGIN + '/api_transport.php', 'content': 'foreign'}),
                    ('mkdir_live', {'path': '/home/welcome/public_html'}),
                    ('move_test', {'source': live.b.PLUGIN, 'destination': '/tmp/other'}),
                    ('copy_owner_backup', {'name': 'config.inc.php'})]
                for op, values in cases:
                    with self.subTest(op=op), self.assertRaises(live.b.PreparationError): client.call(op, **values)
                network.assert_not_called()

    @unittest.skipUnless(shutil.which('php'), 'PHP runtime exercised in CI')
    def test_php_transport_and_hook_contract(self):
        subprocess.run(['php', 'tests/test_roundcube_live.php'], check=True, capture_output=True)


if __name__ == '__main__': unittest.main()
