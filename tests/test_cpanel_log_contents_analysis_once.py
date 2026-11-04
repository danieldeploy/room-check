import contextlib
import io
import json
import pathlib
import sys
import time
import unittest
from unittest import mock

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parents[1] / "deploy"))
import cpanel_log_contents_analysis_once as audit


class LogContentsAuditTests(unittest.TestCase):
    def test_classifies_messages_without_reproducing_them(self):
        sensitive = "/private/secret/user-name?token=top-secret"
        entries = [
            "PHP Fatal error: Uncaught Error in " + sensitive,
            "PHP Warning: Undefined array key from " + sensitive,
            "PHP Deprecated: Dynamic property in " + sensitive,
            "SQLSTATE[HY000] database failed in " + sensitive,
            "no space left on device",
            "permission denied",
            "ordinary text from " + sensitive
        ]
        result = audit.summarize(entries)
        self.assertEqual(result["lines_sampled"], 7)
        self.assertEqual(result["category_counts"].get("fatal"), 1)
        self.assertEqual(result["category_counts"].get("warning"), 1)
        self.assertEqual(result["category_counts"].get("deprecated"), 1)
        self.assertEqual(result["category_counts"].get("database"), 1)
        self.assertEqual(result["category_counts"].get("disk_full"), 1)
        self.assertNotIn(sensitive, json.dumps(result))

    def test_rejects_untrusted_file_outside_scoped_logs(self):
        with self.assertRaises(audit.Rejected):
            audit.inventory([{"type":"file","file":"/home/welcome/mail/x","size":1,
                              "mtime":int(time.time())}],time.time())
        with self.assertRaises(audit.Rejected):
            audit.inventory([{"type":"file","file":audit.LOG_ROOT+"/log",
                              "size":-1,"mtime":int(time.time())}],time.time())

    def test_never_downloads_large_file_or_compressed_archive(self):
        logs = [
            {"type":"file","file":audit.LOG_ROOT+"/active_error_log",
             "size":110*1024*1024,"mtime":int(time.time())},
            {"type":"file","file":audit.LOG_ROOT+"/archive.gz",
             "size":2*1024*1024,"mtime":int(time.time())},
        ]
        with mock.patch.object(audit,"scoped_search",return_value=logs), \
             mock.patch.object(audit,"domain_list",return_value=[]), \
             mock.patch.object(audit,"request_api",return_value={}) as api, \
             contextlib.redirect_stdout(io.StringIO()) as output:
            audit.analyze()
        data=json.loads(output.getvalue())
        self.assertTrue(data["read_only"])
        self.assertEqual(data["files_total"],2)
        self.assertEqual(data["files_deleted"],0)
        self.assertEqual(data["files"][0]["content"],"large_log_no_whole_file_access")
        self.assertEqual(data["files"][1]["content"],"compressed_archive_not_opened")
        self.assertFalse(any(x.args[0]=="Fileman" for x in api.call_args_list))
        self.assertNotIn("active_error_log",output.getvalue())
        self.assertNotIn("archive.gz",output.getvalue())

    def test_small_file_read_sanitizes_content(self):
        secret="guest@example.com secret-123456"
        with mock.patch.object(audit,"request_api",
                               return_value={"content":"PHP Fatal error: "+secret+"\nNormal "+secret+"\n"}) as api:
            out=audit.read_small_file(audit.LOG_ROOT+"/small_error.log")
        self.assertEqual(out["state"],"complete_plaintext")
        self.assertEqual(out["category_counts"]["fatal"],1)
        self.assertNotIn(secret,json.dumps(out))
        self.assertEqual(api.call_args.args[:2],("Fileman","get_file_content"))

    def test_response_adapter_does_not_stringify_sensitive_dict(self):
        raw={"lines":[{"message":"PHP Warning: cannot read /private/x"},
                      {"message":"plain-text"}]}
        self.assertEqual(audit.text_lines(raw),["PHP Warning: cannot read /private/x","plain-text"])
        self.assertEqual(audit.text_lines({"unfamiliar":{"password":"SECRET"}}),[])
        with self.assertRaises(audit.Rejected):
            audit.text_lines("x"*(audit.MAX_SAMPLE_CHARS+1))

    def test_domain_list_remains_private(self):
        with mock.patch.object(audit,"request_api",
                               return_value={"main_domain":"example.org",
                                             "sub_domains":["check.example.org","BAD DOMAIN"],
                                             "parked_domains":[],"addon_domains":[]}) as api:
            out=audit.domain_list()
        self.assertEqual(out,["check.example.org","example.org"])
        api.assert_called_once_with("DomainInfo","list_domains",{})

    def test_php_tail_aggregation_no_raw_lines(self):
        inputs={"main_domain":"internal.example.org","addon_domains":[],
                "sub_domains":[],"parked_domains":[]}
        calls=[]
        def reply(module,function,params):
            calls.append((module,function))
            if module=="LogManager" and function=="get_settings":
                return {"archive_logs":1,"prune_archive":0,"retention_days":0}
            if module=="DomainInfo":return inputs
            if module=="LogManager" and function=="view_error_log":
                return {"data":{"lines":["PHP Warning: very secret cookie=abcd",
                                         "PHP Warning: same error",
                                         "PHP Fatal error: failed"]}}
            raise AssertionError("unexpected API")
        with mock.patch.object(audit,"scoped_search",return_value=[]), \
             mock.patch.object(audit,"request_api",side_effect=reply), \
             contextlib.redirect_stdout(io.StringIO()) as out:
            audit.analyze()
        data=json.loads(out.getvalue())
        self.assertEqual(data["domains_checked"],1)
        self.assertEqual(data["php_tail_lines_sampled"],9)
        self.assertEqual(data["php_tail_error_counts"]["warning"],6)
        self.assertEqual(data["php_tail_error_counts"]["fatal"],3)
        self.assertNotIn("internal.example.org",out.getvalue())
        self.assertNotIn("cookie=abcd",out.getvalue())
        self.assertEqual(data["archive_settings"]["retention_days"],0)
        self.assertFalse(any(x[1] in ("delete_file","set_settings","save_file_content")
                             for x in calls))

if __name__=="__main__":
    unittest.main()
