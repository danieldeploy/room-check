import pathlib
import sys
import unittest
from unittest import mock

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parents[1] / "deploy"))
import cpanel_headless_153_source_scan as inspect


class SourceScanTests(unittest.TestCase):
    def test_rejects_private_content_outside_roots(self):
        with self.assertRaises(inspect.Rejected):
            inspect.script_content("/home/welcome/mail/private.txt")
        with self.assertRaises(inspect.Rejected):
            inspect.script_content("/home/welcome/room-check-private/../../mail/private.php")

    def test_detect_shell_mode_and_override_without_returning_code(self):
        path = "/home/welcome/room-check-private/cron/worker.php"
        def listing(dir):
            if dir == inspect.ROOTS[0]:
                return [("cron",{"type":"dir"}),("uploads",{"type":"dir"})]
            if dir == inspect.ROOTS[0] + "/cron":
                return [("worker.php",{"type":"file","size":200})]
            if dir == inspect.ROOTS[1]:
                return []
            raise AssertionError(dir)
        with mock.patch.object(inspect,"directory_entries",side_effect=listing), \
             mock.patch.object(inspect,"script_content",return_value="$browser = puppeteer.launch({ headless: 'shell' });"):
            summary=inspect.source_tree_audit()
        self.assertEqual(summary["explicit_shell_references"],1)
        self.assertEqual(summary["dirs_excluded"],1)
        self.assertTrue(summary["complete_code_scan"])

    def test_no_refs_not_proof_no_running_processes(self):
        with mock.patch.object(inspect,"script_paths_from_crons",return_value=(
            {"crons":5,"cron_direct_headless_refs":0,"cron_direct_version_refs":0},[])), \
             mock.patch.object(inspect,"source_tree_audit",return_value={
                 "complete_code_scan":True,"explicit_shell_references":0}), \
             mock.patch.object(inspect,"browser_inventory",return_value={"version_groups":{}}), \
             mock.patch("builtins.print") as printmock:
            inspect.main()
        result=printmock.call_args[0][0]
        self.assertIn('"shell_153_safe_to_delete_proven": false',result)

    def test_file_contents_never_exposed_in_summary(self):
        secret = "SUPER_PRIVATE_PASSWORD=abc"
        def listing(dir):
            return [("config.env",{"type":"file","size":len(secret)})] if dir==inspect.ROOTS[0] else []
        with mock.patch.object(inspect,"directory_entries",side_effect=listing), \
             mock.patch.object(inspect,"script_content",return_value=secret):
            result=inspect.source_tree_audit()
        self.assertNotIn("SUPER_PRIVATE_PASSWORD",str(result))

    def test_symlink_is_never_followed(self):
        def listing(dir):
            return [("external.php",{"type":"link","size":999})] if dir==inspect.ROOTS[0] else []
        with mock.patch.object(inspect,"directory_entries",side_effect=listing), \
             mock.patch.object(inspect,"script_content") as read:
            result=inspect.source_tree_audit()
        read.assert_not_called()
        self.assertEqual(result["candidate_source_files"],0)


if __name__ == "__main__":
    unittest.main()
