import pathlib
import sys
import unittest
from unittest.mock import patch

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parents[1] / "deploy"))
import cpanel_tracked_integrity_probe as probe


class TrackedIntegrityReadOnlyTests(unittest.TestCase):
    def test_size_mismatch_without_reading_file_content(self):
        tree = {"truncated": False, "tree": [
            {"path": "AGENTS.md", "type": "blob", "size": 200},
            {"path": "docs/doc.md", "type": "blob", "size": 300},
            {"path": "deploy/runner.py", "type": "blob", "size": 10},
            {"path": "deploy", "type": "tree"},
        ]}
        files, dirs = probe.index_tree(tree)
        self.assertEqual(files["AGENTS.md"], 200)
        self.assertEqual(dirs, ["", "deploy", "docs"])
        listings = {
            "": {"AGENTS.md": {"type": "file", "size": "200"}},
            "docs": {"doc.md": {"type": "file", "size": "500"}},
            "deploy": {"runner.py": {"type": "file", "size": "10"}, "other.py": {"type": "file", "size": 6}},
        }
        result = probe.compare(files, listings)
        self.assertEqual(result["tracked_examined"], 3)
        self.assertEqual(result["size_mismatch_by_area"], {"docs": 1})
        self.assertEqual(result["untracked_regular_by_area"], {"deploy": 1})
        self.assertNotIn("docs/doc.md", str(result))
        self.assertNotIn("other.py", str(result))

    def test_reject_untrusted_git_tree(self):
        for tree in [
            {"truncated": True, "tree": []},
            {"truncated": False, "tree": None},
            {"truncated": False, "tree": [{"path": "../s", "type": "blob", "size": 3}]},
            {"truncated": False, "tree": [{"path": ".git/HEAD", "type": "blob", "size": 3}]},
            {"truncated": False, "tree": [{"path": "a.txt", "type": "blob", "size": -1}]},
        ]:
            with self.assertRaises(probe.Rejected):
                probe.index_tree(tree)

    def test_malformed_server_listing_refused(self):
        with patch.object(probe, "request_api", return_value=[
            {"file": "../unsafe", "type": "file", "size": 1}]):
            with self.assertRaises(probe.Rejected):
                probe.read_listing("/home/welcome/repositories/room-check", "deploy")

    def test_no_content_api_invoked(self):
        with patch.object(probe, "request_api", return_value=[{"file": "a.txt", "type": "file", "size": 5}]) as api:
            result = probe.read_listing("/home/welcome/repositories/room-check", "docs")
            self.assertIn("a.txt", result)
            api.assert_called_once()
            self.assertEqual(api.call_args.args[1], "list_files")


if __name__ == "__main__":
    unittest.main()
