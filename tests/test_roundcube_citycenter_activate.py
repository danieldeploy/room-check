import sys, unittest
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import patch
sys.path.insert(0,str(Path(__file__).resolve().parents[1]/'deploy'))
import roundcube_citycenter_activate as act

class Client:
    def __init__(self):
        self.files={(act.MAIN_DIR,act.MAIN_FILE):'original', (act.prep.PRIVATE,act.prep.BACKUP):'original', (act.prep.PLUGIN,'config.inc.php'):act.DISABLE}
        for name in ('api_transport.php','citycenter_smtp2go_api.php'):
            self.files[act.prep.PLUGIN,name]=(Path(act.__file__).with_name('roundcube-citycenter-live')/name).read_text()
        self.writes=[];self.fail_main=False
    def account_for_domain(self):return 'city'
    def dns_records(self):return [{'name':r['name'],'type':'CNAME','ttl':r['ttl'],'data':[r['target']]} for r in act.dns.RECORDS]
    def file_stat(self,d,n):
        if n in (act.dns.BACKUP_DIR,'live'):return {'type':'dir','nicemode':'0700'}
        return {'type':'file','nicemode':'0600' if d==act.prep.PRIVATE else '0644'}
    def request(self,path,params,**kwargs):
        key=params['dir'],params['file'];self.files[key]=params['content'];self.writes.append(key)
        if self.fail_main and key==(act.MAIN_DIR,act.MAIN_FILE) and params['content']=='desired':raise act.dns.OperationError('simulated_write_response_failure')

class ActivationTests(unittest.TestCase):
    def run_activation(self,c):
        audit={'production_mailbox_exists':True,'roundcube_version_matches_transport':True,'mail_hook_conflict_count':0}
        with patch.object(act.dns,'Client',return_value=c),patch.object(act.requirements,'read',side_effect=lambda client,d,n:client.files[d,n]),patch.object(act.requirements,'audit',return_value=audit),patch.object(act.validation,'result',return_value={'ok':True,'server_send_accepted':True}),patch.object(act.validation,'scheduled',return_value=[]),patch.object(act,'configuration',return_value='desired'),patch.object(act.subprocess,'run',return_value=SimpleNamespace(returncode=0)):
            return act.activate()
    def test_activates_after_verified_send_and_is_idempotent(self):
        c=Client();r=self.run_activation(c);self.assertTrue(r['plugin_enabled'])
        self.assertEqual(c.files[act.MAIN_DIR,act.MAIN_FILE],'desired');self.assertEqual(c.files[act.prep.PLUGIN,'config.inc.php'],act.ENABLE)
        c.writes.clear();r=self.run_activation(c);self.assertTrue(r['already_active']);self.assertEqual(c.writes,[])
    def test_restores_original_when_write_response_fails_after_mutation(self):
        c=Client();c.fail_main=True
        with self.assertRaisesRegex(act.dns.OperationError,'simulated_write_response_failure'):self.run_activation(c)
        self.assertEqual(c.files[act.MAIN_DIR,act.MAIN_FILE],'original');self.assertEqual(c.files[act.prep.PLUGIN,'config.inc.php'],act.DISABLE)
    def test_conflicting_main_config_is_not_overwritten(self):
        c=Client();c.files[act.MAIN_DIR,act.MAIN_FILE]='unrelated user change'
        with self.assertRaisesRegex(act.dns.OperationError,'main_configuration_conflict'):self.run_activation(c)
        self.assertEqual(c.writes,[])

if __name__=='__main__':unittest.main()
