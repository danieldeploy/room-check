import sys, unittest
from pathlib import Path
from unittest.mock import patch
sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'deploy'))
import roundcube_citycenter_prepare as prep

class MemoryClient:
    def __init__(self):
        self.files = {(prep.ROOT + '/config', 'config.inc.php'): '<?php\n$config["plugins"] = [];\n'}
        self.modes = {}
        self.writes = []
    def account_for_domain(self): return 'city'
    def file_inventory(self, directory):
        return {n for (d,n) in self.files if d == directory} | ({'live'} if directory == prep.PRIVATE else set()) | ({'citycenter_smtp2go_api'} if directory == prep.ROOT + '/plugins' else set())
    def file_stat(self, directory, name):
        if (directory, name) in self.files:
            return {'type': 'file', 'nicemode': self.modes.get((directory, name), '0644')}
        return {'type': 'dir', 'nicemode': '0700' if name in (prep.dns.BACKUP_DIR, 'live') else '0755'}
    def request(self, path, params, **kwargs):
        key = (params['dir'], params['file'])
        self.files[key] = params['content']
        self.writes.append(key)
    def api2(self, function, **params):
        if function == 'fileop':
            path = prep.HOME + '/' + params['sourcefiles']
            directory, name = path.rsplit('/', 1)
            self.modes[directory, name] = params['metadata']

class PreparationTests(unittest.TestCase):
    def run_prepare(self, client):
        audit = {'production_mailbox_exists': True, 'roundcube_version_matches_transport': True, 'mail_hook_conflict_count': 0, 'smtp2go_auth_records': 2}
        with patch.object(prep.requirements, 'audit', return_value=audit), patch.object(prep.dns, 'Client', return_value=client), patch.object(prep.requirements, 'read', side_effect=lambda c,d,n: c.files[d,n]):
            return prep.prepare()
    def test_prepares_disabled_without_changing_main_configuration(self):
        client = MemoryClient()
        main = client.files[prep.ROOT + '/config', 'config.inc.php']
        report = self.run_prepare(client)
        self.assertFalse(report['plugin_enabled'])
        self.assertEqual(client.files[prep.ROOT + '/config', 'config.inc.php'], main)
        self.assertEqual(client.files[prep.PRIVATE, prep.BACKUP], main)
        self.assertEqual(client.modes[prep.PRIVATE, prep.BACKUP], '0600')
        self.assertIn('= false;', client.files[prep.PLUGIN, 'config.inc.php'])
        self.assertNotIn((prep.ROOT + '/config', 'config.inc.php'), client.writes)
        client.writes.clear()
        self.run_prepare(client)
        self.assertEqual(client.writes, [])
    def test_refuses_to_overwrite_original_backup(self):
        client = MemoryClient()
        client.files[prep.PRIVATE, prep.BACKUP] = 'private previous backup'
        client.modes[prep.PRIVATE, prep.BACKUP] = '0600'
        with self.assertRaisesRegex(prep.dns.OperationError, 'existing_backup_does_not_match_config'):
            self.run_prepare(client)
        self.assertEqual(client.writes, [])
    def test_save_cannot_write_main_config_or_key(self):
        client = MemoryClient()
        for directory, name in ((prep.ROOT + '/config','config.inc.php'), (prep.PRIVATE, 'api-key.txt')):
            with self.assertRaisesRegex(prep.dns.OperationError, 'write_scope_violation'):
                prep.save(client, directory, name, 'data')
        self.assertEqual(client.writes, [])

if __name__ == '__main__': unittest.main()
