import importlib.util
import json
import shutil
import subprocess
import sys
import unittest
from pathlib import Path
from unittest.mock import patch
sys.path.insert(0, str(Path('deploy').resolve()))
import roundcube_activate as a
b = a.base


class Fake:
    def __init__(self):
        self.files = {b.VERSION: "define('RCMAIL_VERSION', '1.6.19');", b.CONFIG: '<?php private original',
                      b.BACKUP: '<?php private original'}
        self.files.update({b.PLUGIN + '/' + name: name for name in b.HASHES})
        self.files[b.PLUGIN + '/config.inc.php.dist'] = "<?php\n$config['welcome_smtp2go_enabled'] = false;\n"
        self.files[a.TARGET] = self.files[b.PLUGIN + '/config.inc.php.dist']
        self.files.update({b.ROOT + '/' + name: name for name in a.CORE_HASHES})
        self.files[b.ROOT + '/plugins/welcome_ui/welcome_ui.php'] = '<?php // UI only'
        self.modes = {b.PRIVATE: 0o700, b.BACKUP: 0o600, a.KEY: 0o600}
        self.writes = []
        self.reads = []

    def stat(self, path, kind):
        return self.modes.get(path, 0o755 if kind == 'dir' else 0o644)

    def read(self, path):
        self.reads.append(path)
        if path == a.KEY: raise AssertionError('Secret must never be read')
        return self.files[path]

    def call(self, operation, **values):
        if operation == 'stat': return [{'size': 37}]
        if operation == 'list':
            return [{'file': path.rsplit('/', 1)[1]} for path in self.files if path.startswith(b.PRIVATE + '/')]
        self.writes.append((operation, values.get('path')))
        if operation == 'write': self.files[values['path']] = values['content']
        if operation == 'protect_confirmation': self.modes[a.CONFIRM] = 0o600


class ActivationTests(unittest.TestCase):
    def run_activation(self, fake, now=lambda: a.EXPIRES-1000):
        report = {}
        hashes = {name: b.digest(fake.files[b.PLUGIN + '/' + name]) for name in b.HASHES}
        core = {name: b.digest(name) for name in a.CORE_HASHES}
        with patch.object(b, 'HASHES', hashes), patch.object(a, 'CORE_HASHES', core):
            a.activate(fake, report, now=now, lint_check=lambda x: None,
                       parser=lambda x: {'plugins': ['welcome_ui'], 'php_open': True})
        return report

    def test_timed_activation_preserves_original_and_never_reads_key(self):
        fake = Fake()
        original = fake.files[b.CONFIG]
        report = self.run_activation(fake)
        self.assertTrue(report['ok'])
        self.assertFalse(report['email_sent'])
        self.assertEqual(fake.files[b.BACKUP], original)
        self.assertTrue(fake.files[b.CONFIG].startswith(original))
        self.assertIn('time() < '+str(a.EXPIRES), fake.files[a.TARGET])
        self.assertNotIn(a.KEY, fake.reads)
        self.assertEqual(fake.writes[-1], ('write', a.TARGET))
        fake.writes.clear()
        self.run_activation(fake)
        self.assertEqual(fake.writes, [])

    def test_expiry_prevents_any_mutation(self):
        fake = Fake()
        with self.assertRaisesRegex(b.PreparationError, 'expired'):
            self.run_activation(fake, now=lambda: a.EXPIRES)
        self.assertEqual(fake.writes, [])

    def test_key_permissions_prevent_activation(self):
        fake = Fake()
        fake.modes[a.KEY] = 0o644
        with self.assertRaisesRegex(b.PreparationError, 'key_requires'):
            self.run_activation(fake)
        self.assertEqual(fake.writes, [])

    def test_changed_main_config_preserved(self):
        fake = Fake()
        fake.files[b.CONFIG] = 'concurrent change'
        with self.assertRaisesRegex(b.PreparationError, 'main_config_changed'):
            self.run_activation(fake)
        self.assertEqual(fake.writes, [])

    def test_conflicting_mail_hook_blocks_activation(self):
        fake = Fake()
        fake.files[b.ROOT + '/plugins/welcome_ui/welcome_ui.php'] = 'message_before_send'
        with self.assertRaisesRegex(b.PreparationError, 'mail_hook'):
            self.run_activation(fake)
        self.assertEqual(fake.writes, [])

    def test_changed_core_blocks_activation(self):
        fake = Fake()
        fake.files[b.ROOT + '/program/lib/Roundcube/rcube.php'] = 'changed'
        with self.assertRaisesRegex(b.PreparationError, 'core_differs'):
            self.run_activation(fake)
        self.assertEqual(fake.writes, [])

    def test_key_reads_and_writes_rejected(self):
        for operation, values in [('read', {'path': a.KEY}), ('write', {'path': a.KEY, 'content': 'no'}),
                                  ('write', {'path': '/tmp/unrelated', 'content': 'no'})]:
            with self.assertRaises(b.PreparationError): a.extra_route(operation, values)

    def test_config_write_uses_post(self):
        client = a.Client('test-token')
        with patch.object(client, 'request', return_value={}) as request:
            client.call('write', path=a.TARGET, content='test configuration')
            self.assertTrue(request.call_args.kwargs['post'])
            self.assertNotIn('content', request.call_args.args[0])

    @unittest.skipUnless(shutil.which('php'), 'PHP is validated in CI')
    def test_php_parser_never_executes_config(self):
        source = "<?php $config['plugins'] = ['archive', 'welcome_ui']; file_put_contents('/tmp/should-never-exist', 'bad');"
        parsed = a.parse_config(source)
        self.assertEqual(parsed, {'plugins': ['archive', 'welcome_ui'], 'php_open': True})
        self.assertFalse(Path('/tmp/should-never-exist').exists())
        with self.assertRaises(b.PreparationError):
            a.parse_config("<?php $config['plugins'] = getenv('PLUGINS');")
