import pathlib,sys,unittest
from unittest.mock import Mock
sys.path.insert(0,str(pathlib.Path(__file__).resolve().parents[1]/"deploy"))
import recover_hostelworld_release as r
class Tests(unittest.TestCase):
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
        self.assertEqual(r.FAILED_ID,'400')
        self.assertEqual(r.FAILED_SHA,'6697ccd695990164dea51df1fe5ce092e168b329')
        with self.assertRaises(r.DeploymentError): r.known_failure(runner,self.task('288'))
        task=self.task(); task['repository_state']['branch']='main'
        with self.assertRaises(r.DeploymentError): r.known_failure(runner,task)
    def test_newer_failure_and_active_task_are_not_recovered(self):
        runner=self.runner(); newer=self.task("391"); newer["timestamps"]["failed"]=124
        runner.tasks.return_value=[self.task(),newer]
        with self.assertRaises(r.DeploymentError): r.known_failure(runner,r.latest_task(runner))
        newer["state"]="active"
        with self.assertRaises(r.DeploymentError): r.latest_task(runner)
if __name__=="__main__": unittest.main()
