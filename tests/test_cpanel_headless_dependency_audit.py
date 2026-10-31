import contextlib
import io
import pathlib
import sys
import unittest
from unittest import mock

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parents[1] / "deploy"))
import cpanel_headless_dependency_audit as audit

BASE = audit.ROOT


class BrowserEvidenceTests(unittest.TestCase):
    def test_versions_group_without_paths(self):
        rows = [
            {"file": BASE + "/chrome/linux-153.0.8010.36/chrome-linux64/chrome",
             "type": "file", "size": 390 * audit.MB, "atime": 0},
            {"file": BASE + "/chrome-headless-shell/linux-148.0.7778.97/chrome-headless-shell-linux64/chrome-headless-shell",
             "type": "file", "size": 52 * audit.MB, "atime": 1_000_000},
            {"file": BASE + "/chrome-headless-shell/linux-153.0.8010.36/chrome-headless-shell-linux64/chrome-headless-shell",
             "type": "file", "size": 259 * audit.MB, "atime": 1_000_000},
        ]
        with mock.patch.object(audit, "request_api", return_value={"data": rows}):
            result = audit.browser_inventory(1_800_000_000)
        self.assertEqual(result["version_groups"]["chrome_153"]["files"], 1)
        self.assertEqual(result["version_groups"]["headless_shell_148.0.7778.97"]["shell_executable_count"], 1)
        self.assertEqual(result["version_groups"]["headless_shell_153.0.8010.36"]["mb"], 259)
        self.assertTrue(result["no_usage_proof_from_atime"])

    def test_scoped_search_rejects_out_of_scope(self):
        with mock.patch.object(audit, "request_api", return_value={"data": [{
            "file": "/home/welcome/mail/important", "type": "file", "size": 50
        }]}):
            with self.assertRaises(audit.Rejected):
                audit.browser_inventory(1_800_000_000)

    def test_cron_commands_never_printed_and_only_aggregate_flags(self):
        rows = [
            {"command": "secret=NEVER_SHOW_ME php /private/cron/invoice.php"},
            {"command": "node /private/browser.sh --executable-path=chrome-headless-shell/148.0.7778.97"}
        ]
        with mock.patch.object(audit, "request_api", return_value={"data": rows}):
            result = audit.cron_inventory()
        self.assertEqual(result["tasks"], 2)
        self.assertEqual(result["direct_shell_refs"], 1)
        self.assertEqual(result["headless_148_refs"], 1)
        self.assertNotIn("NEVER_SHOW_ME", str(result))

    def test_no_cron_refs_is_not_proof_unused(self):
        with mock.patch.object(audit, "request_api", return_value={"data": [{"command": "php daily.php"}]}):
            result = audit.cron_inventory()
        self.assertEqual(result["direct_shell_refs"], 0)
        self.assertTrue(result["does_not_prove_unreferenced_binaries_unused"])

    def test_read_only_workflow_never_requests_delete(self):
        with mock.patch.object(audit, "browser_inventory", return_value={"read_only": True}), \
             mock.patch.object(audit, "cron_inventory", return_value={"status": "read_only"}), \
             contextlib.redirect_stdout(io.StringIO()) as out:
            audit.main()
        self.assertIn('"installed_browser_removal_authorized": false', out.getvalue())


if __name__ == "__main__":
    unittest.main()
