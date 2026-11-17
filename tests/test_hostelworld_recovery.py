import pathlib,sys,unittest
from unittest.mock import Mock,patch
sys.path.insert(0,str(pathlib.Path(__file__).resolve().parents[1]/"deploy"))
import recover_hostelworld_release as r
from hostelworld_status import receipt_pause_status
class Tests(unittest.TestCase):
    def test_receipt_status_is_allowlisted_and_never_prints_private_fields(self):
        raw='prefix {"receipt_wait_pause":{"outcome":"paused","can_start":true,"automatic":false,"private":"never print"}} suffix'
        self.assertEqual(receipt_pause_status(raw),[{'outcome':'paused','can_start':True,'automatic':False}])
        self.assertEqual(receipt_pause_status(raw.replace('"paused"','"private outcome"')),[])
        self.assertEqual(receipt_pause_status(raw.replace('true','"true"')),[])
    def runner(self):
        runner=Mock()
        runner.task_id.side_effect=lambda t:str(t["deploy_id"])
        runner.task_state.side_effect=lambda t:t["state"]
        return runner
    def task(self,identifier=r.FAILED_ID,sha=r.FAILED_SHA):
        return {"deploy_id":identifier,"state":"failed","repository_state":{"branch":r.BRANCH,"identifier":sha},"timestamps":{"failed":123}}
    def test_only_inspected_failure_is_allowed(self):
        runner=self.runner()
        r.known_failure(runner,self.task())
        with self.assertRaises(r.DeploymentError): r.known_failure(runner,self.task("391"))
        with self.assertRaises(r.DeploymentError): r.known_failure(runner,self.task(sha="a"*40))
    def test_previous_or_unrelated_failure_is_not_authorized(self):
        runner=self.runner()
        self.assertEqual(r.FAILED_ID,'431')
        self.assertEqual(r.FAILED_SHA,'dc4eb6eda39d50761f3d8c765698c0152609d090')
        with self.assertRaises(r.DeploymentError): r.known_failure(runner,self.task('288'))
        task=self.task(); task['repository_state']['branch']='main'
        with self.assertRaises(r.DeploymentError): r.known_failure(runner,task)
    def test_newer_failure_and_active_task_are_not_recovered(self):
        runner=self.runner(); newer=self.task("391"); newer["timestamps"]["failed"]=124
        runner.tasks.return_value=[self.task(),newer]
        with self.assertRaises(r.DeploymentError): r.known_failure(runner,r.latest_task(runner))
        newer["state"]="active"
        with self.assertRaises(r.DeploymentError): r.latest_task(runner)
    def test_recovery_requires_actual_fatal_before_pause_and_installed_source(self):
        task=self.task();task['log_path']='/home/welcome/.cpanel/logs/vc_test_git_deploy.log'
        fatal='Cannot redeclare class TranslationProtection previously in /home/welcome/repositories/room-check/src/I18n/TranslationProtection.php in /home/welcome/public_html/check/src/I18n/TranslationProtection.php'
        source=(pathlib.Path(r.__file__).resolve().parents[1]/'src/I18n/InvoiceText.php').read_text()
        with patch.object(r,'request_api',side_effect=[{'content':fatal},{'content':source}]):r.verify_failure_log(task)
        for text in ('unknown failure',fatal+' {"receipt_wait_pause":"paused"}'):
            with patch.object(r,'request_api',return_value={'content':text}):
                with self.assertRaises(r.DeploymentError):r.verify_failure_log(task)
        with patch.object(r,'request_api',side_effect=[{'content':fatal},{'content':'changed'}]):
            with self.assertRaises(r.DeploymentError):r.verify_failure_log(task)
        task['log_path']='/home/welcome/private/secret'
        with self.assertRaises(r.DeploymentError):r.verify_failure_log(task)
if __name__=="__main__": unittest.main()
