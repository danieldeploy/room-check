import contextlib
import io
import pathlib
import sys
import unittest
from unittest import mock

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parents[1] / "deploy"))
import cpanel_whm_runtime_capabilities as scope


class WHMRuntimeScopeTests(unittest.TestCase):
    def test_script_paths_restrict_to_code_subfolders(self):
        commands=[{"command":"php /home/welcome/room-check-private/cron/daily.php"},
                  {"metadata":"cron_header_without_command"},
                  {"command":None},
                  {"command":"node /home/welcome/mail/secret.js"},
                  {"command":"bash /home/welcome/room-check-private/cron/worker.sh"}]
        with mock.patch.object(scope,"request_api",return_value={"data":commands}):
            report,paths=scope.script_paths_from_crons()
        self.assertEqual(report["scripts_discovered"],2)
        self.assertEqual(len(paths),2)
        self.assertFalse(any("mail/" in path for path in paths))

    def test_safe_read_does_not_return_script_content(self):
        path="/home/welcome/room-check-private/cron/daily.php"
        content="SECRET=NEVER_RETURN_ME; executablePath=/home/welcome/.cache/puppeteer/chrome-headless-shell/linux-148.0.7778.97/"
        with mock.patch.object(scope,"request_api",return_value={"content":content}):
            result=scope.safe_read_script(path)
        self.assertTrue(result["has_headless_shell"])
        self.assertTrue(result["has_executable_path_override"])
        self.assertNotIn("NEVER_RETURN_ME",str(result))

    def test_reject_config_and_mail(self):
        for path in ["/home/welcome/mail/inbox.js",
                     "/home/welcome/room-check-private/cron/.env",
                     "/home/welcome/room-check-private/cron/config.js",
                     "/home/welcome/room-check-private/cron/../../mail/messages.js"]:
            with self.assertRaises(scope.Rejected):
                scope.safe_read_script(path)

    def test_cron_code_audit_does_not_delete_or_expose_commands(self):
        crons={"crons":1,"scripts_discovered":1}
        fname="/home/welcome/room-check-private/cron/secretly_named.php"
        buf=io.StringIO()
        with mock.patch.object(scope,"whm_applist",return_value={"status":"available"}), \
             mock.patch.object(scope,"script_paths_from_crons",return_value=(crons,[fname])), \
             mock.patch.object(scope,"safe_read_script",return_value={
                 "status":"read","has_headless_shell":False,"has_chrome":False,
                 "has_puppeteer":True,"has_executable_path_override":False,
                 "has_nested_includes":True}), \
             contextlib.redirect_stdout(buf):
            scope.main()
        self.assertIn('"scripts_with_puppeteer_ref": 1',buf.getvalue())
        self.assertNotIn("secretly_named",buf.getvalue())


if __name__ == "__main__":
    unittest.main()
