import datetime as dt
import pathlib
import sys
import unittest
from unittest import mock

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parents[1] / "deploy"))
import cpanel_git_final_diagnostic as gitcheck
import cpanel_mail_arrival_probe as mailcheck

class FinalCpanelDiagnosticTests(unittest.TestCase):
    def test_extra_workflows_are_not_disclosed(self):
        tree={"tree":[{"path":".github/workflows/ci.yml","type":"blob"},
                      {"path":".github/workflows/hosting-connectivity.yml","type":"blob"}]}
        rows={"ci.yml":{"type":"file","size":500},"hosting-connectivity.yml":{"type":"file","size":500},
              "new-workflow.yml":{"type":"file","size":90}}
        data=gitcheck.check(tree,".github/workflows",rows)
        self.assertEqual(data,{"extra":1,"small_regular_yaml":1,"other":0})
        self.assertNotIn("new-workflow",str(data))

    def test_suspicious_git_entries_not_approved(self):
        tree={"tree":[{"path":".github/workflows/ci.yml","type":"blob"}]}
        rows={"ci.yml":{"type":"file","size":500},
              "secret.env":{"type":"file","size":100},
              "unsafe.yml":{"type":"dir","size":100}}
        result=gitcheck.check(tree,".github/workflows",rows)
        self.assertEqual(result["small_regular_yaml"],0)
        self.assertEqual(result["other"],2)

    def test_maildir_recent_count_sanitized(self):
        now=int(dt.datetime(2026,10,30,0,50,tzinfo=dt.timezone.utc).timestamp())
        rows=[{"file":"private-mail-filename","type":"file","mtime":now-10},
              {"file":"older","type":"file","mtime":now-4000}]
        result=mailcheck.count_recent(rows,now-3600)
        self.assertEqual(result["messages_modified_within_one_hour"],1)
        self.assertNotIn("private-mail",str(result))

    def test_mail_probe_no_contents(self):
        with mock.patch.object(mailcheck,"request_api",return_value=[]):
            result=mailcheck.probe(dt.datetime(2026,10,30,0,55,tzinfo=dt.timezone.utc))
        self.assertTrue(result["read_only"])
        self.assertFalse(result["headers_or_bodies_read"])
        self.assertEqual(set(result["mail"]),{"welcome","city"})

if __name__=="__main__":
    unittest.main()
