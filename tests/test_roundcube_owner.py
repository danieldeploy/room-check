import json
import shutil
import subprocess
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch
import test_roundcube_pilot as pilot_tests
import roundcube_owner as o


class Fake(pilot_tests.Fake):
    def call(self, operation, **values):
        if operation == 'copy_owner_backup':
            name = values['name']
            self.writes.append((operation, name))
            self.files[o.BACKUPS[name]] = self.files[o.p.PLUGIN + '/' + name]
        elif operation == 'chmod' and values['path'] in o.BACKUPS.values():
            self.writes.append((operation, values['path']))
            self.modes[values['path']] = 0o600
        else:
            return super().call(operation, **values)


class OwnerTests(unittest.TestCase):
    def fixture(self):
        f = Fake()
        pilot_tests.PilotTests().run_command(f)
        self.journal(f, '01')
        f.writes.clear()
        return f

    def journal(self, f, slot, accepted=True):
        events = [{'state': 'attempt_started', 'time_utc': '2026-10-04T15:48:02+00:00'}]
        if accepted:
            events.append({'state': 'accepted', 'time_utc': '2026-10-04T15:48:03+00:00',
                           'http': 200, 'curl_errno': 0, 'email_id': 'private-fixture'})
        path = o.base.PRIVATE + '/pilot-20261004-' + slot + '.json'
        f.files[path] = '\n'.join(json.dumps(e) for e in events) + '\n'
        f.modes[path] = 0o600

    def run_command(self, f, command=o.retarget, **kwargs):
        hashes = {n: o.base.digest(f.files[o.base.PLUGIN + '/' + n]) for n in o.base.HASHES}
        core = {n: o.base.digest(n) for n in o.p.previous.CORE_HASHES}
        report = {}
        with patch.object(o.base, 'HASHES', hashes), patch.object(o.p.previous, 'CORE_HASHES', core):
            args = {'parser': lambda x: {'plugins': ['welcome_ui'], 'php_open': True}}
            if command == o.retarget: args.update(lint=lambda x: None, now=lambda: o.EXPIRES-1000)
            args.update(kwargs)
            command(f, report, **args)
        return report

    def test_backups_before_writes_config_last_and_journals_preserved(self):
        f = self.fixture(); original = dict(f.files)
        result = self.run_command(f)
        self.assertTrue(result['owner_updated'])
        writes = [x for x in f.writes if x[0] == 'write']
        self.assertEqual(writes, [('write', o.p.PLUGIN + '/' + n) for n in o.CHANGED])
        self.assertEqual(f.writes[:6:2], [('copy_owner_backup', n) for n in o.CHANGED])
        for path, content in original.items():
            if path not in {o.p.PLUGIN+'/'+n for n in o.CHANGED}: self.assertEqual(f.files[path], content)
        self.assertNotIn(o.p.previous.KEY, f.reads)
        f.writes.clear(); self.run_command(f); self.assertEqual(f.writes, [])

    def test_missing_first_or_any_second_attempt_prevents_mutation(self):
        for case in ('missing', 'uncertain', 'second_started', 'second_accepted', 'second_empty'):
            f = self.fixture()
            if case == 'missing': del f.files[o.base.PRIVATE+'/pilot-20261004-01.json']
            elif case == 'uncertain': self.journal(f, '01', False)
            else:
                self.journal(f, '02', case == 'second_accepted')
                if case == 'second_empty': f.files[o.base.PRIVATE+'/pilot-20261004-02.json'] = ''
            with self.subTest(case=case), self.assertRaises(o.base.PreparationError): self.run_command(f)
            self.assertEqual(f.writes, [])

    def test_unknown_backup_or_source_prevents_mutation(self):
        for path in (o.BACKUPS['config.inc.php'], o.p.PLUGIN+'/pilot_transport.php'):
            f = self.fixture(); f.files[path] = 'foreign'; f.modes[path] = 0o600
            with self.assertRaises(o.base.PreparationError): self.run_command(f)
            self.assertEqual(f.writes, [])

    def test_expiry_refuses_update(self):
        f = self.fixture()
        with self.assertRaisesRegex(o.base.PreparationError, 'window_unavailable'):
            self.run_command(f, now=lambda: o.EXPIRES)
        self.assertEqual(f.writes, [])

    def test_verify_after_send_and_rollback_preserve_attempts(self):
        f = self.fixture(); original = dict(f.files)
        self.run_command(f); self.journal(f, '02'); f.writes.clear()
        result = self.run_command(f, o.verify)
        self.assertEqual(result['accepted'], 2); self.assertEqual(f.writes, [])
        self.assertNotIn('private-fixture', json.dumps(result))
        self.run_command(f, o.rollback)
        for n in o.CHANGED: self.assertEqual(f.files[o.p.PLUGIN+'/'+n], original[o.p.PLUGIN+'/'+n])
        self.assertIn(o.base.PRIVATE+'/pilot-20261004-02.json', f.files)

    def test_partial_update_is_readable_and_reversible(self):
        f = self.fixture(); self.run_command(f)
        f.files[o.p.PLUGIN+'/config.inc.php'] = o.packages()[0]['config.inc.php']
        f.writes.clear(); self.run_command(f, o.inspect)
        self.assertEqual(f.writes, [])
        self.run_command(f, o.rollback)

    def test_narrow_client_rejects_secrets_other_files_and_unapproved_content(self):
        for writable in (False, True):
            client = o.Client('fixture-token', writable=writable)
            with patch.object(client, 'request') as network:
                for op, values in [('read', {'path': o.p.previous.KEY}),
                    ('write', {'path': o.base.CONFIG, 'content': 'no'}),
                    ('write', {'path': o.p.PLUGIN+'/config.inc.php', 'content': 'no'}),
                    ('copy_backup', {}), ('create_plugin', {}), ('chmod', {'path': o.p.previous.KEY})]:
                    with self.subTest(op=op), self.assertRaises(o.base.PreparationError): client.call(op, **values)
                network.assert_not_called()
        client = o.Client('fixture-token')
        with self.assertRaisesRegex(o.base.PreparationError, 'read_only_client'):
            client.call('write', path=o.p.PLUGIN+'/config.inc.php', content=o.packages()[1]['config.inc.php'])

    @unittest.skipUnless(shutil.which('php'), 'PHP is validated in CI')
    def test_generated_php_envelope_deadline_and_repeat_guard(self):
        with tempfile.TemporaryDirectory() as directory:
            for name, content in o.packages()[1].items():
                path = Path(directory)/name; path.write_text(content)
                if name.endswith('.php'): subprocess.run(['php', '-l', str(path)], check=True, capture_output=True)
            subprocess.run(['php', 'tests/test_roundcube_owner.php', directory], check=True, capture_output=True)


if __name__ == '__main__': unittest.main()
