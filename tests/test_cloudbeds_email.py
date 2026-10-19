import os
import pathlib
import sys
import unittest
from unittest.mock import patch
sys.path.insert(0,str(pathlib.Path(__file__).resolve().parents[1]/"deploy"))
import cloudbeds_email as c


class Tests(unittest.TestCase):
    def test_not_started_spf_probe_does_not_hide_the_main_report(self):
        expected={'welcome':{'tasks':[],'delivery':[]},'city':{'tasks':[],'delivery':[]}}
        self.assertEqual(c.safe_pipeline_rows([]),expected)
        self.assertEqual(c.safe_pipeline_rows(None),expected)

    def test_spf_pipeline_status_only_exposes_fixed_counts_and_states(self):
        result=c.safe_pipeline_rows({'welcome':{'spf_indexed':19,'spf_selected':1,'private':'SECRET',
            'tasks':[{'state':'completed','result_code':'ok','invoice':'PRIVATE','duplicate_count':1}],
            'delivery':[{'drive_state':'verified','toconline_state':'toc_existing','private':'SECRET'}]}})
        self.assertEqual(result['welcome']['spf_indexed'],19)
        self.assertNotIn('SECRET',str(result))
        self.assertNotIn('PRIVATE',str(result))

    def test_separate_original_mail_and_delivery_paths(self):
        for account in c.ROOTS:
            p=c.expected(account,c.ROOTS[account]+"/.Cloudbeds/")
            self.assertEqual(p["action1"],"save")
            self.assertIn("/home/"+account+"/mail/",p["dest1"])
            self.assertNotIn("forward",p.values())
            self.assertIn("/home/"+account+"/",c.CRONS[account])
            self.assertEqual("action2" in p,account=="welcome")

    def test_unrelated_files_crons_and_filters_cannot_be_modified(self):
        with patch.dict(os.environ,{"WHM_API_TOKEN":"synthetic-only"}):
            api=c.Hosting("city")
        with patch.object(api.opener,"open") as opened:
            for module,function,params in [
                ("Fileman","save_file_content",dict(dir="/home/city",file="other.php",content="x")),
                ("Fileman","save_file_content",dict(dir="/home/welcome",file="cloudbeds-city.key",content="x")),
                ("Cron","add_line",dict(command="arbitrary command")),
                ("Cron","remove_line",dict(line=1)),
                ("Email","store_filter",dict(filtername="Other")),
                ("Fileman","get_file_content",dict(dir="/home/city",file="password")),
            ]:
                with self.assertRaises(c.DeploymentError): api.call(module,function,**params)
            opened.assert_not_called()
        for path in [c.ROOTS["welcome"]+"/.Cloudbeds/",c.ROOTS["city"]+"/../other",c.ROOTS["city"]+"/.Other/"]:
            with self.assertRaises(c.DeploymentError): c.expected("city",path)

    def test_shared_filter_verification_accepts_only_exact_new_binding(self):
        p=c.expected("city",c.ROOTS["city"]+"/.CLOUDBEDS/")
        row={"filtername":c.NAME,"disabled":False,"rules":[
            {k:p[k+str(i)] for k in (["part","match","val","opt"] if i==1 else ["part","match","val"])}
            for i in (1,2)],"actions":[{"action":"save","dest":p["dest1"]}]}
        c.verify_filter(row,p)
        row["filtername"]="Room Check Hostelworld Auth v1"
        with self.assertRaises(c.DeploymentError): c.verify_filter(row,p)


if __name__=="__main__": unittest.main()
