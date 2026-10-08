import os, sys, unittest
from pathlib import Path
from unittest.mock import patch
sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'deploy'))
import roundcube_citycenter_key_transfer as keys

class FakeClient:
    def __init__(self):
        self.user = None
        self.files = {('/home/welcome/.trash', 'sandbox-key.txt'): 'api-' + 'A' * 32}
        self.modes = {('/home/welcome/.trash', 'sandbox-key.txt'): '0600'}
        self.writes = []
        self.key_reads = 0
    def account_for_domain(self): self.user = 'city'; return self.user
    def file_inventory(self, directory): return {n for d,n in self.files if d == directory}
    def file_stat(self, directory, name):
        if name in ('roundcube-smtp2go-private', keys.dns.BACKUP_DIR): return {'type':'dir', 'nicemode':'0700'}
        if (directory,name) not in self.files: return None
        return {'type':'file', 'nicemode':self.modes[directory,name]}
    def request(self, path, params, **kwargs):
        if path.endswith('/listaccts'): return {'acct':[{'user':'welcome','domain':'welcomehostel.pt'}]}
        if params.get('file') == 'api_transport.php':
            return {'content': "const PRIVATE_DIR = '/home/welcome/roundcube-smtp2go-private'; $keyfile = $dir . '/sandbox-key.txt';"}
        pair = params['dir'], params['file']
        if params['cpanel.function'] == 'save_file_content':
            self.files[pair] = params['content']; self.modes[pair] = '0644'; self.writes.append(('save',pair)); return {}
        self.key_reads += 1
        return {'content':self.files[pair]}
    def api2(self, function, **params):
        if params['op'] == 'rename':
            src = ('/home/welcome/.trash','sandbox-key.txt')
            dst = (keys.SOURCE_DIR,'sandbox-key.txt')
            self.files[dst] = self.files.pop(src); self.modes[dst] = self.modes.pop(src); self.writes.append(('restore',dst))
        else:
            self.modes[keys.DEST_DIR,keys.DEST_FILE] = params['metadata']

class TransferTests(unittest.TestCase):
    def run_transfer(self, client):
        with patch.dict(os.environ, {'CITYCENTER_KEY_TRANSFER_AUTHORIZED':'20261008','WHM_API_TOKEN':'test-token'}), patch.object(keys.dns,'Client',return_value=client):
            return keys.transfer()
    def test_unique_private_key_is_restored_and_copied_without_modifying_value(self):
        c = FakeClient(); result = self.run_transfer(c)
        self.assertTrue(result['source_key_restored_from_trash'])
        self.assertEqual(c.files[keys.SOURCE_DIR,'sandbox-key.txt'], 'api-' + 'A' * 32)
        self.assertEqual(c.files[keys.DEST_DIR,keys.DEST_FILE].strip(), c.files[keys.SOURCE_DIR,'sandbox-key.txt'])
        self.assertEqual(c.modes[keys.DEST_DIR,keys.DEST_FILE], '0600')
        c.writes.clear(); self.run_transfer(c); self.assertEqual(c.writes, [])
    def test_ambiguous_trash_candidate_is_not_read_or_moved(self):
        c = FakeClient(); c.files['/home/welcome/.trash','sandbox-key.txt.1'] = 'different'
        with self.assertRaisesRegex(keys.dns.OperationError,'source_recovery_not_unique'): self.run_transfer(c)
        self.assertEqual(c.writes,[]);self.assertEqual(c.key_reads,0)
    def test_insecure_source_is_not_read_or_copied(self):
        c=FakeClient();c.modes['/home/welcome/.trash','sandbox-key.txt']='0644'
        with self.assertRaisesRegex(keys.dns.OperationError,'source_recovery_not_private'):self.run_transfer(c)
        self.assertEqual(c.key_reads,0);self.assertEqual(c.writes,[])
    def test_authorization_required_before_any_api_access(self):
        with patch.dict(os.environ,{},clear=True),patch.object(keys.dns,'Client') as client:
            with self.assertRaisesRegex(keys.dns.OperationError,'explicit_transfer_authorization_missing'):keys.transfer()
            client.assert_not_called()

if __name__=='__main__':unittest.main()
