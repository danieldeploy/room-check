"""Isolated CLI acceptance tests. Requires PHP >= 8.1 with mbstring/OpenSSL.

Creates synthetic keys/mail only in a disposable directory. No network or mail send.
"""
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
PHP = shutil.which('php')
TOKEN = 'a' * 64
MAIL = ('From: no_reply@toconline.pt\r\n'
        'Subject: Ficheiros arquivados com sucesso\r\n'
        'Content-Type: text/plain; charset=UTF-8\r\n\r\n'
        'Synthetic private message booking_' + TOKEN + '.pdf\r\n').encode()


class DiagnosticTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory(prefix='toc-isolated-')
        self.addCleanup(self.tmp.cleanup)
        self.account = Path(self.tmp.name)
        self.private = self.account / 'room-check-private'
        self.vault = self.private / 'invoices'
        self.vault.mkdir(parents=True)
        self.private.chmod(0o700)
        self.vault.chmod(0o700)
        (self.vault / 'master.key').write_bytes(b'S' * 32)
        (self.vault / 'master.key').chmod(0o600)
        self.script = self.private / 'cron/toconline-reply.php'
        self.script.parent.mkdir()
        shutil.copyfile(ROOT / 'cron/toconline-reply.php', self.script)
        self.app = self.account / 'public_html/check'
        (self.app / 'src/Invoices').mkdir(parents=True)
        for name in ('InvoiceVault.php', 'InvoiceTocPipe.php', 'InvoiceTocAuthentication.php'):
            shutil.copyfile(ROOT / 'src/Invoices' / name, self.app / 'src/Invoices' / name)
        shutil.copyfile(ROOT / 'config.php', self.app / 'config.php')
        self.status = self.private / 'toconline-pipe-diagnostic.json'

    def invoke(self, mail=MAIL, home=None, disable_mb=False):
        env = dict(os.environ)
        env.pop('INVOICES_PRIVATE_DIR', None)
        env['HOME'] = str(self.account if home is None else home)
        args = [PHP]
        if disable_mb:
            args += ['-d', 'disable_functions=mb_convert_encoding,mb_strtolower']
        result = subprocess.run(args + [str(self.script)], input=mail,
                                capture_output=True, env=env, timeout=15)
        self.assertEqual(result.stdout, b'')
        self.assertEqual(result.stderr, b'')
        data = json.loads(self.status.read_text()) if self.status.is_file() else None
        if data is not None:
            text = self.status.read_text()
            for secret in (str(self.account), TOKEN, 'Synthetic private message', 'S' * 32):
                self.assertNotIn(secret, text)
            self.assertEqual(self.status.stat().st_mode & 0o777, 0o600)
        return result.returncode, data

    def test_queue_and_replay(self):
        for _ in range(2):
            code, data = self.invoke()
            self.assertEqual((code, data['code']), (0, 'queued'))
        self.assertEqual(len(list(self.vault.glob('toc-pipe-*.enc'))), 1)

    def test_exim_envelope_and_replay(self):
        for stamp in (b'15:35:13', b'16:35:13'):
            prefix = b'From private-envelope@example.invalid Sun Oct  4 ' + stamp + b' 2026\n'
            code, data = self.invoke(prefix + MAIL)
            self.assertEqual((code, data['stage'], data['code']), (0, 'complete', 'queued'))
            self.assertTrue(data['facts']['transport_prefix'])
            self.assertEqual(data['facts']['input_bytes'], len(prefix + MAIL))
            self.assertNotIn('private-envelope', self.status.read_text())
        self.assertEqual(len(list(self.vault.glob('toc-pipe-*.enc'))), 1)

    def test_header_failure_is_specific_and_private(self):
        code, data = self.invoke(b'Private invalid header line\n' + MAIL)
        self.assertEqual((code, data['stage'], data['code']), (75, 'queue', 'toc_pipe_header_line'))
        self.assertNotIn('Private invalid', self.status.read_text())
        self.assertFalse(data['facts']['transport_prefix'])
        self.assertEqual(len(list(self.vault.glob('toc-pipe-*.enc'))), 0)

    def test_input_limits_are_specific(self):
        for raw, expected in ((b'x' * 262145, 'toc_pipe_message_size'),
                              (MAIL + b'\x00', 'toc_pipe_message_nul'),
                              (b'From: no_reply@toconline.pt', 'toc_pipe_header_separator'),
                              (b'X-Large: ' + b'x' * 32769 + b'\n\nbody', 'toc_pipe_header_size')):
            with self.subTest(expected=expected):
                code, data = self.invoke(raw)
                self.assertEqual((code, data['code']), (75, expected))
        self.assertEqual(len(list(self.vault.glob('toc-pipe-*.enc'))), 0)

    def test_ignored_sender(self):
        code, data = self.invoke(MAIL.replace(b'no_reply@toconline.pt', b'other@example.invalid'))
        self.assertEqual((code, data['code']), (0, 'ignored'))
        self.assertEqual(len(list(self.vault.glob('toc-pipe-*.enc'))), 0)

    def test_different_home(self):
        code, data = self.invoke(home=self.account / 'mail/example.invalid/test')
        self.assertEqual((code, data['stage'], data['code']),
                         (75, 'vault', 'private_storage_unavailable'))
        self.assertFalse(data['home_matches_account'])
        self.assertFalse(data['facts']['private_dir_exists'])

    def test_explicit_vault_with_different_home(self):
        # A different HOME is not a failure when private_dir is explicit.
        path = str(self.vault).replace("\\", "\\\\").replace("'", "\\'")
        (self.app / 'config.local.php').write_text(
            "<?php return ['invoices'=>['private_dir'=>'" + path + "']];")
        code, data = self.invoke(home=self.account / 'mail/example.invalid/test')
        self.assertEqual((code, data['code']), (0, 'queued'))
        self.assertFalse(data['home_matches_account'])
        self.assertTrue(data['facts']['private_dir_exists'])

    def test_bad_vault_permissions(self):
        self.vault.chmod(0o755)
        code, data = self.invoke()
        self.assertEqual((code, data['code']), (75, 'private_storage_permissions'))

    def test_missing_key(self):
        (self.vault / 'master.key').unlink()
        code, data = self.invoke()
        self.assertEqual((code, data['code']), (75, 'vault_key_unavailable'))

    def test_unavailable_mb_functions(self):
        code, data = self.invoke(disable_mb=True)
        self.assertEqual((code, data['code']), (75, 'runtime_failure'))
        self.assertFalse(data['mb_convert_encoding'])
        self.assertFalse(data['mb_strtolower'])

    def test_diagnostic_refuses_public_directory_without_affecting_queue(self):
        self.private.chmod(0o755)
        code, data = self.invoke()
        self.assertEqual((code, data), (0, None))
        self.assertEqual(len(list(self.vault.glob('toc-pipe-*.enc'))), 1)

    def test_diagnostic_does_not_follow_symlink(self):
        sentinel = self.account / 'sentinel'
        sentinel.write_text('unchanged')
        self.status.symlink_to(sentinel)
        # Invoke without parsing the intentional non-JSON symlink target.
        env = dict(os.environ, HOME=str(self.account))
        env.pop('INVOICES_PRIVATE_DIR', None)
        result = subprocess.run([PHP, str(self.script)], input=MAIL, env=env,
                                capture_output=True, timeout=15)
        self.assertEqual((result.returncode, result.stdout, result.stderr), (0, b'', b''))
        self.assertEqual(sentinel.read_text(), 'unchanged')


if __name__ == '__main__':
    if PHP is None:
        raise SystemExit('NOT RUN: PHP CLI is unavailable.')
    requirements = subprocess.run([PHP, '-r',
        'exit(PHP_VERSION_ID>=80100 && function_exists("mb_convert_encoding")'
        ' && function_exists("mb_strtolower") && function_exists("openssl_encrypt") ? 0 : 1);'])
    if requirements.returncode:
        raise SystemExit('NOT RUN: requires PHP >=8.1, mbstring and OpenSSL.')
    for path in [ROOT / 'cron/toconline-reply.php', ROOT / 'config.php', ROOT / 'src/Invoices/InvoiceVault.php', ROOT / 'src/Invoices/InvoiceTocPipe.php']:
        subprocess.run([PHP, '-l', str(path)], check=True, capture_output=True)
    unittest.main()

