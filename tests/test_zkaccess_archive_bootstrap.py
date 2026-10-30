import pathlib
import sys
import unittest
from unittest.mock import patch

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parents[1] / "deploy"))
import zkaccess_archive_bootstrap as bootstrap


class ProtectedBootstrapTests(unittest.TestCase):
    def test_worker_is_private_and_cli_only(self):
        self.assertEqual(bootstrap.DEST_DIR, "/home/welcome/room-check-private/cron")
        source = bootstrap.source_text()
        self.assertIn("PHP_SAPI !== 'cli'", source)
        self.assertIn("room_check_zk_backups", source)

    def test_existing_identical_is_unchanged(self):
        expected = bootstrap.source_text()
        def api(module, func, params):
            if func == "list_files":
                return [{"file": bootstrap.DEST_FILE, "type": "file"}]
            if func == "get_file_content":
                return {"content": expected}
            self.fail("unexpected write")
        with patch.object(bootstrap, "request_api", side_effect=api):
            self.assertEqual(bootstrap.ensure_worker(), "unchanged")

    def test_absent_creates_and_verifies_before_cron(self):
        state = {"value": None, "calls": []}
        def api(module, func, params):
            state["calls"].append(func)
            if func == "list_files":
                return [] if state["value"] is None else [{"file": bootstrap.DEST_FILE, "type": "file"}]
            if func == "save_file_content":
                self.assertEqual(params["dir"], bootstrap.DEST_DIR)
                state["value"] = params["content"]
                return {"success": True}
            if func == "get_file_content":
                return {"content": state["value"]}
            self.fail("unexpected API")
        with patch.object(bootstrap, "request_api", side_effect=api), patch.object(bootstrap, "install_cron", return_value=True), patch.object(bootstrap, "inspect_cron", return_value=True):
            self.assertEqual(bootstrap.install(), {"worker": "created", "cron_present": True})
        self.assertEqual(state["calls"].count("save_file_content"), 1)

    def test_unexpected_file_fails_closed_without_cron(self):
        def api(module, func, params):
            if func == "list_files":
                return [{"file": bootstrap.DEST_FILE, "type": "file"}]
            if func == "get_file_content":
                return {"content": "unexpected production file"}
            self.fail("unexpected write")
        with patch.object(bootstrap, "request_api", side_effect=api), patch.object(bootstrap, "install_cron") as cron:
            with self.assertRaises(bootstrap.Rejected):
                bootstrap.install()
            cron.assert_not_called()

    def test_symlink_and_failed_readback_rejected(self):
        with patch.object(bootstrap, "request_api", return_value=[{"file": bootstrap.DEST_FILE, "type": "symlink"}]):
            with self.assertRaises(bootstrap.Rejected):
                bootstrap.ensure_worker()
        state = {"created": False}
        def api(module, func, params):
            if func == "list_files":
                return [{"file": bootstrap.DEST_FILE, "type": "file"}] if state["created"] else []
            if func == "save_file_content":
                state["created"] = True
                return {}
            if func == "get_file_content":
                return {"content": "mismatched readback"}
            self.fail("unexpected API")
        with patch.object(bootstrap, "request_api", side_effect=api), patch.object(bootstrap, "install_cron") as cron:
            with self.assertRaises(bootstrap.Rejected):
                bootstrap.install()
            cron.assert_not_called()


if __name__ == "__main__":
    unittest.main()
