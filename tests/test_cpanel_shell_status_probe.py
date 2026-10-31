import pathlib
import sys
import unittest
from unittest import mock

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parents[1] / "deploy"))
import cpanel_shell_status_probe as probe


class ShellStatusTests(unittest.TestCase):
    def test_jailed_shell_vs_disabled_vs_normal(self):
        self.assertEqual(probe.classify("/usr/local/cpanel/bin/jailshell"), "jailed_shell")
        self.assertEqual(probe.classify("/usr/local/cpanel/bin/noshell"), "shell_disabled")
        self.assertEqual(probe.classify("/bin/bash"), "normal_shell")
        self.assertEqual(probe.classify(None), "unknown")

    def test_whm_response_only_exact_account(self):
        result = probe.summarize("listaccts", {"data":{
            "metadata":{"result":1},"data":{"acct":[
                {"user":"other", "shell":"/bin/bash", "domain":"private"},
                {"user":"welcome", "shell":"/usr/local/cpanel/bin/jailshell",
                 "domain":"welcomehostel.pt", "email":"secret@example.com"}]}}})
        self.assertEqual(result["shell_status"], "jailed_shell")
        self.assertNotIn("welcomehostel", str(result))
        self.assertNotIn("secret@example.com", str(result))

    def test_not_queried_does_not_mean_disabled(self):
        result = probe.summarize("accountsummary", {"function":"accountsummary",
            "api_status":"unavailable"})
        self.assertNotIn("shell_status", result)
        self.assertEqual(result["status"], "unavailable")

    def test_no_shell_change_functions_in_probe(self):
        with mock.patch.object(probe, "query_api", return_value={"api_status":"unavailable"}) as q, \
             mock.patch("builtins.print") as printing:
            probe.main()
        self.assertEqual(q.call_count,2)
        self.assertIn('"no_shell_settings_changed": true', printing.call_args[0][0])
        self.assertIn('"shell_status": "not_verified"', printing.call_args[0][0])


if __name__ == "__main__":
    unittest.main()
