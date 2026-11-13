import copy,json,pathlib,sys,unittest
sys.path.insert(0,str(pathlib.Path(__file__).resolve().parents[1]/'deploy'))
import toc_reply_route as d
from cpanel_api import DeploymentError

class FakeAPI:
    def __init__(self,filters): self.filters=copy.deepcopy(filters); self.writes=0
    def call(self,module,function,**params):
        assert module=='Email' and params['account']==d.ACCOUNT
        if function=='list_filters': return copy.deepcopy(self.filters)
        assert function=='store_filter'; self.writes+=1
        self.filters.append({'filtername':params['filtername'],'rules':[{'part':params['part1'],'match':params['match1'],'val':params['val1'],'opt':'and'}],
                             'actions':[{'action':params['action1'],'dest':'|/home/welcome/'+params['dest1']}]})

def copy_filter():
    return {'filtername':'Private filing rule','rules':[{'part':'$header_from:','match':'contains','val':'no_reply@toconline.pt'}],
            'actions':[{'action':'save','dest':'/home/welcome/mail/example.test/person/.Private/'}]}

class Tests(unittest.TestCase):
    def test_existing_filing_is_preserved_and_pipe_is_idempotent(self):
        original=copy_filter();api=FakeAPI([original]);d.install(api)
        self.assertEqual(api.filters[0],original);self.assertEqual(api.writes,1)
        d.install(api);self.assertEqual(api.writes,1)
    def test_missing_copy_or_stopping_rule_prevents_mutation(self):
        for filters in ([],[dict(copy_filter(),disabled=1)], [copy_filter(),{'actions':[{'action':'finish'}]}]):
            api=FakeAPI(filters)
            with self.assertRaises(DeploymentError): d.install(api)
            self.assertEqual(api.writes,0)
    def test_conflicting_named_filter_is_not_overwritten(self):
        f=copy_filter();f['filtername']=d.NAME;api=FakeAPI([f])
        with self.assertRaises(DeploymentError): d.install(api)
        self.assertEqual(api.writes,0)
    def test_other_pipe_on_sender_requires_diagnosis(self):
        f=copy_filter();f['actions'].append({'action':'pipe','dest':'unknown.php'});api=FakeAPI([f])
        with self.assertRaises(DeploymentError):d.install(api)
        self.assertEqual(api.writes,0)
