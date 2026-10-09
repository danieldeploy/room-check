import importlib.util
import pathlib
import sys
import unittest
from unittest.mock import patch
sys.path.insert(0,str(pathlib.Path(__file__).resolve().parents[1]/"deploy"))
import hostelworld_filters as h
class Tests(unittest.TestCase):
    def test_separate_destinations_and_copy(self):
        welcome=h.expected("welcome",11); city=h.expected("city",22)
        self.assertEqual(welcome["dest2"],"room-check-private/cron/hostelworld-auth-pipe.php 11")
        self.assertEqual(city["dest2"],"hostelworld-city-auth-pipe.php")
        self.assertEqual(city["action1"],"save")
        self.assertEqual(city["dest1"],"/home/city/mail/$domain/$local_part/")
        self.assertEqual(welcome["dest1"],"/home/welcome/mail/$domain/$local_part/")
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
        for delivery in ('"'+params["dest1"]+'"',"$home/mail/$domain/$local_part/",
                         '"$home/mail/$domain/$local_part/"',
                         "$home/mail/$domain/$local_part",params["dest1"].rstrip("/")):
            row["actions"][0]["dest"]=delivery
            h.verify_filter(row,params)
        for wrong in ("/home/welcome/mail/$domain/$local_part/","$home/other/",
                      '"'+params["dest1"]+'"; other'):
            row["actions"][0]["dest"]=wrong
            with self.assertRaises(h.DeploymentError): h.verify_filter(row,params)

    def test_whm_rejects_unrelated_file(self):
        with patch.dict("os.environ",{"WHM_API_TOKEN":"test-only"}):
            api=h.FilterAPI("city")
        with self.assertRaises(h.DeploymentError):
            api.call("Fileman","get_file_content",dir="/home/city/mail",file="mailbox")
if __name__=="__main__": unittest.main()
