import importlib.util
import pathlib
import sys
import unittest
from unittest.mock import patch
sys.path.insert(0,str(pathlib.Path(__file__).resolve().parents[1]/"deploy"))
import hostelworld_filters as h
class Tests(unittest.TestCase):
    def test_private_hosting_comparison_never_returns_domains_or_mail_data(self):
        from hostelworld_private_status import summarize
        trace={'result':'Testing Exim filter file "/etc/vfilters/main.example.test"'}
        row={'actionunixtime':'10000','deliveredto':'/home/city/mail/mail.example.test/inbox/.HOSTELWORLD/',
             'sender':'private@example.test','message':'PRIVATE_MAIL_CONTENT'}
        result=summarize(trace,[row],10010)
        self.assertTrue(result['tested_filter_identified'])
        self.assertFalse(result['delivery_uses_tested_domain'])
        self.assertEqual(set(result),{'version','account_id','at','tested_filter_identified','delivery_uses_tested_domain'})
        self.assertNotIn('example.test',str(result))
        self.assertNotIn('PRIVATE_MAIL_CONTENT',str(result))
        self.assertTrue(summarize({'trace':'/etc/vfilters/mail.example.test'},[row],10010)['delivery_uses_tested_domain'])
        self.assertFalse(summarize(trace,[row,dict(row,deliveredto='/home/city/mail/other.test/inbox/.HOSTELWORLD/')],10010)['tested_filter_identified'])
        self.assertFalse(summarize(trace,[row],40000)['tested_filter_identified'])

    def test_refresh_only_exact_existing_city_filter(self):
        from unittest.mock import Mock
        from hostelworld_refresh_filter import refresh_city
        import copy
        p=h.expected('city',3)
        current={'filtername':h.NAME,'rules':[
            {'part':p['part1'],'match':p['match1'],'val':p['val1'],'opt':'and'},
            {'part':p['part2'],'match':p['match2'],'val':p['val2']}],
            'actions':[{'action':'save','dest':p['dest1']},{'action':'pipe','dest':p['dest2']}]}
        api=Mock(account='city'); api.call.side_effect=[[current],None,[current]]
        refresh_city(api)
        self.assertEqual(api.call.call_args_list[1].kwargs,dict(p,oldfiltername=h.NAME))
        for prior in ([],[current,{'filtername':'unrelated'}],[dict(current,disabled=1)]):
            api.call.reset_mock(); api.call.side_effect=[prior]
            with self.assertRaises(h.DeploymentError): refresh_city(api)
            self.assertEqual(api.call.call_count,1)
        bad=copy.deepcopy(current); bad['rules'][0]['val']='unrelated.example'
        api.call.reset_mock(); api.call.side_effect=[[bad]]
        with self.assertRaises(h.DeploymentError): refresh_city(api)
        self.assertEqual(api.call.call_count,1)

    def test_migrates_only_exact_old_inbox_filter_and_is_idempotent(self):
        from unittest.mock import Mock
        import copy
        params=h.expected('city',22)
        def row(p):
            return {'filtername':h.NAME,'rules':[
                {'part':p['part1'],'match':p['match1'],'val':p['val1'],'opt':'and'},
                {'part':p['part2'],'match':p['match2'],'val':p['val2']}],
                'actions':[{'action':'save','dest':p['dest1']},{'action':'pipe','dest':p['dest2']}]}
        old=row(dict(params,dest1='/home/city/mail/$domain/$local_part/'))
        api=Mock(account='city')
        api.call.side_effect=[[old],None,[row(params)]]
        h.install(api,22)
        self.assertEqual(api.call.call_args_list[1].kwargs,params)
        api.call.reset_mock(); api.call.side_effect=[[row(params)],[row(params)]]
        h.install(api,22)
        self.assertEqual(api.call.call_count,2)
        for change in ('disabled','pipe','rule'):
            bad=copy.deepcopy(old)
            if change=='disabled': bad['disabled']=1
            if change=='pipe': bad['actions'][1]['dest']='other.php'
            if change=='rule': bad['rules'][0]['val']='other.test'
            api.call.reset_mock(); api.call.side_effect=[[bad]]
            with self.assertRaises(h.DeploymentError): h.install(api,22)
            self.assertEqual(api.call.call_count,1)

    def test_separate_destinations_and_copy(self):
        welcome=h.expected("welcome",11); city=h.expected("city",22)
        self.assertEqual(welcome["dest2"],"room-check-private/cron/hostelworld-auth-pipe.php 11")
        self.assertEqual(city["dest2"],"hostelworld-city-auth-pipe.php")
        self.assertEqual(city["action1"],"save")
        self.assertEqual(city["dest1"],"/home/city/mail/$domain/$local_part/.HOSTELWORLD/")
        self.assertEqual(welcome["dest1"],"/home/welcome/mail/$domain/$local_part/.Hostelworld/")
        with self.assertRaises(h.DeploymentError): h.expected("../other",22)
    def test_existing_other_destination_is_rejected(self):
        params=h.expected("city",22)
        row={"filtername":h.NAME,"rules":[
            {"part":params["part1"],"match":params["match1"],"val":params["val1"],"opt":"and"},
            {"part":params["part2"],"match":params["match2"],"val":params["val2"]}],
            "actions":[{"action":params["action1"],"dest":params["dest1"]},
                       {"action":"deliver","dest":"outside@example.com"}]}
        with self.assertRaises(h.DeploymentError): h.verify_filter(row,params)
    def test_native_absolute_pipe_and_wrong_account(self):
        params=h.expected("welcome",11)
        row={"filtername":h.NAME,"rules":[
            {"part":params["part1"],"match":params["match1"],"val":params["val1"],"opt":"and"},
            {"part":params["part2"],"match":params["match2"],"val":params["val2"]}],
            "actions":[{"action":"save","dest":params["dest1"]},
                       {"action":"pipe","dest":"|/home/welcome/"+params["dest2"]}]}
        h.verify_filter(row,params)
        row["actions"][1]["dest"]="|$home/"+params["dest2"]
        h.verify_filter(row,params)
        for wrong in ("|/home/city/"+params["dest2"],
                      "|/home/welcome/"+params["dest2"]+"; other",
                      "|/home/welcome/"+params["dest2"].replace(" 11"," 12")):
            row["actions"][1]["dest"]=wrong
            with self.assertRaises(h.DeploymentError): h.verify_filter(row,params)

    def test_quoted_delivery_and_home_variable_remain_bound(self):
        params=h.expected("city",22)
        row={"filtername":h.NAME,"rules":[
            {"part":params["part1"],"match":params["match1"],"val":params["val1"],"opt":"and"},
            {"part":params["part2"],"match":params["match2"],"val":params["val2"]}],
            "actions":[{"action":"save","dest":params["dest1"]},
                       {"action":"pipe","dest":params["dest2"]}]}
        for delivery in ('"'+params["dest1"]+'"',"$home/mail/$domain/$local_part/.HOSTELWORLD/",
                         '"$home/mail/$domain/$local_part/.HOSTELWORLD/"',
                         "$home/mail/$domain/$local_part/.HOSTELWORLD",params["dest1"].rstrip("/")):
            row["actions"][0]["dest"]=delivery
            h.verify_filter(row,params)
        for wrong in ("/home/welcome/mail/$domain/$local_part/.Hostelworld/","$home/other/",
                      '"'+params["dest1"]+'"; other'):
            row["actions"][0]["dest"]=wrong
            with self.assertRaises(h.DeploymentError): h.verify_filter(row,params)

    def test_whm_rejects_unrelated_file(self):
        with patch.dict("os.environ",{"WHM_API_TOKEN":"test-only"}):
            api=h.FilterAPI("city")
        with self.assertRaises(h.DeploymentError):
            api.call("Fileman","get_file_content",dir="/home/city/mail",file="mailbox")
if __name__=="__main__": unittest.main()
