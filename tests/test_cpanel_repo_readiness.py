import pathlib
import sys
import unittest
from unittest.mock import patch

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parents[1] / "deploy"))
import cpanel_repo_readiness as mod


class Tests(unittest.TestCase):
    def test_rejects_names_with_path_segments_and_duplicate_entries(self):
        for rows in [[{"file": "../private", "type": "file"}],
                     [{"file": "secret/secret", "type": "file"}],
                     [{"file": "ok", "type": "file"}, {"file": "ok", "type": "file"}]]:
            with self.assertRaises(mod.Rejected):
                mod.validate_rows(rows)

    def test_filename_unknown_is_reported_only_as_category(self):
        with patch.object(mod, "tracked_names", return_value={"tracked.php", ".cpanel.yml"}):
            result = mod.compare("", [
                {"file": "tracked.php", "type": "file", "size": 2},
                {"file": ".cpanel.yml", "type": "file", "size": 60},
                {"file": "private_password_secret.txt", "type": "file", "size": 4096},
                {"file": "__pycache__", "type": "dir", "size": 4096},
                {"file": ".git", "type": "dir", "size": 4096},
            ])
        self.assertEqual(result["untracked_count"], 2)
        self.assertEqual(result["categories"]["__pycache__"], 1)
        self.assertEqual(result["categories"]["other_untracked"], 1)
        self.assertTrue(result["cpanel_deployment_config_exists"])
        self.assertNotIn("private_password_secret", str(result))

    def test_installed_git_tree_is_baseline_not_newer_github_head(self):
        tree = {"truncated": False, "tree": [
            {"path": ".cpanel.yml", "type": "blob"},
            {"path": "deploy/old.py", "type": "blob"},
            {"path": "cron/invoices.php", "type": "blob"},
            {"path": "deploy", "type": "tree"},
        ]}
        self.assertEqual(mod.tracked_tree_names(tree, "deploy"), {"old.py"})
        self.assertEqual(mod.tracked_tree_names(tree, ""), {".cpanel.yml", "deploy", "cron"})
        observed = [{"file": "old.py", "type": "file"}]
        # HEAD might have ten extra tracked files; they are not dirty worktree
        # entries in the actual server commit.
        result = mod.compare("deploy", observed, mod.tracked_tree_names(tree, "deploy"))
        self.assertEqual(result["missing_tracked_count"], 0)
        self.assertEqual(result["untracked_count"], 0)

    def test_server_tree_validation_fails_closed(self):
        for tree in [
            {"truncated": True, "tree": []},
            {"truncated": False, "tree": [{"path": "../secret", "type": "blob"}]},
            {"truncated": False, "tree": [{"path": "/root/private", "type": "blob"}]},
            {"truncated": False, "tree": None},
        ]:
            with self.assertRaises(mod.Rejected):
                mod.tracked_tree_names(tree, "")

    def test_root_missing_deploy_config_is_detected(self):
        with patch.object(mod, "tracked_names", return_value={".cpanel.yml", "AGENTS.md"}):
            result = mod.compare("", [{"file": "AGENTS.md", "type": "file"}])
        self.assertEqual(result["missing_tracked_count"], 1)
        self.assertFalse(result["cpanel_deployment_config_exists"])


if __name__ == "__main__":
    unittest.main()
