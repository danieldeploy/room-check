import pathlib
import sys
import unittest
from unittest.mock import MagicMock, patch

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parents[1] / "deploy"))
import cpanel_git_worktree_recovery as mod


class CloneRecoveryTests(unittest.TestCase):
    def test_safe_name_rejects_secrets_hidden_paths_and_traversal(self):
        for name in ("", ".", "..", ".env", "config.local.php", "backup-private.txt",
                     "API-KEY", "token.json", "secret.yml", "foo/bar", "../foo", "foo,bar"):
            with self.assertRaises(mod.Rejected):
                mod.safe_name(name)
        for name in ("zkaccess-backups.php", "temp.py", "some-report.md", "diff_check.txt"):
            self.assertEqual(mod.safe_name(name), name)

    def test_inventory_requires_exact_nine_untracked_regular_files(self):
        expected = {
            "": ["AGENTS.md", "a.txt"], "deploy": ["release.sh", "a.py", "b.py", "c.py", "d.py"],
            "docs": ["README.md", "extra.md"], "tests": ["test_existing.py", "a.py", "b.py", "c.py"],
        }
        paths = {mod.ROOT + ("/" + d if d else ""):
                 {n: {"file": n, "type": "file"} for n in names}
                 for d, names in expected.items()}
        with patch.object(mod, "files_in", side_effect=lambda path: paths.get(path, {})), \
             patch.object(mod, "filenames", side_effect=lambda tree, directory:
                          {"": {"AGENTS.md"}, "deploy": {"release.sh"},
                           "docs": {"README.md"}, "tests": {"test_existing.py"}}.get(directory, set())), \
             patch.object(mod, "file_info", return_value={"size": 100, "mtime": 123}):
            result = mod.plan({"truncated": False, "tree": []})
            self.assertEqual(len(result), 9)
            self.assertEqual(sum(x["subdir"] == "deploy" for x in result), 4)

            paths[mod.ROOT]["unknown.extra"] = {"file": "unknown.extra", "type": "file"}
            with self.assertRaises(mod.Rejected):
                mod.plan({})

    def test_inventory_rejects_untracked_directory_and_missing_tracked(self):
        with patch.object(mod, "files_in", return_value={"extra.py": {"type": "dir"}}), \
             patch.object(mod, "filenames", return_value=set()):
            with self.assertRaises(mod.Rejected):
                mod.plan({})
        with patch.object(mod, "files_in", return_value={}), \
             patch.object(mod, "filenames", return_value={"required.php"}):
            with self.assertRaises(mod.Rejected):
                mod.plan({})

    def test_preflight_wrong_server_commit_never_touches_files(self):
        status = {"repository_root": mod.DEFAULT_REPOSITORY,
                  "branch": "agent/room-item-assignments", "repository_busy": False,
                  "deployable": False, "current_commit": "a" * 40,
                  "last_deployed_commit": "a" * 40}
        with patch.object(mod, "Config") as config, patch.object(mod, "CpanelAPI"), \
             patch.object(mod, "Deployment") as deployment, patch.object(mod, "files_in") as files, \
             patch.object(mod, "request_api") as api:
            config.return_value.repository = mod.DEFAULT_REPOSITORY
            deployment.return_value.status.return_value = status
            with self.assertRaises(mod.Rejected):
                mod.recover(True)
            files.assert_not_called()
            api.assert_not_called()

    def test_plan_has_no_remote_mutations(self):
        states = [{"repository_root": mod.DEFAULT_REPOSITORY,
                   "branch": "agent/room-item-assignments", "repository_busy": False,
                   "deployable": False, "current_commit": mod.OLD_SHA,
                   "last_deployed_commit": mod.OLD_SHA}]
        nine = [{"subdir": "root", "name": "temp.py"}] * 9
        with patch.object(mod, "Config") as config, patch.object(mod, "CpanelAPI"), \
             patch.object(mod, "Deployment") as deployment, patch.object(mod, "GitHub") as github, \
             patch.object(mod, "plan", return_value=nine), \
             patch.object(mod, "request_api") as api, \
             patch.dict(mod.os.environ, {"GITHUB_SHA": "b" * 40}):
            config.return_value.repository = mod.DEFAULT_REPOSITORY
            deployment.return_value.status.return_value = states[0]
            result = mod.recover(False)
            self.assertFalse(result["write_operations"])
            self.assertEqual(result["regular_untracked"], 9)
            github.return_value.require_head.assert_called_once_with("b" * 40)
            github.return_value.require_fast_forward.assert_called_once_with(mod.OLD_SHA, "b" * 40)
            api.assert_not_called()

    def test_apply_preserves_files_and_verifies_clean_repository(self):
        before = {"repository_root": mod.DEFAULT_REPOSITORY, "branch": "agent/room-item-assignments",
                  "repository_busy": False, "deployable": False, "current_commit": mod.OLD_SHA,
                  "last_deployed_commit": mod.OLD_SHA}
        after = dict(before, deployable=True)
        nine = [{"subdir": "root", "name": "x.py"}] * 9
        with patch.object(mod, "Config") as config, patch.object(mod, "CpanelAPI"), \
             patch.object(mod, "Deployment") as deployment, patch.object(mod, "GitHub"), \
             patch.object(mod, "plan", return_value=nine), \
             patch.object(mod, "files_in", return_value={}), \
             patch.object(mod, "create_private_directory") as mk, \
             patch.object(mod, "move_verified") as mover, \
             patch.dict(mod.os.environ, {"GITHUB_SHA": "b" * 40}):
            config.return_value.repository = mod.DEFAULT_REPOSITORY
            deployment.return_value.status.side_effect = [before, after]
            result = mod.recover(True)
            self.assertEqual(result["moved"], 9)
            self.assertEqual(mover.call_count, 9)
            self.assertEqual(mk.call_count, 5)

    def test_private_quarantine_read_only_inventory(self):
        data = {
            mod.HOME: {"room-check-repo-quarantine-20261029": {"type": "dir"}},
            mod.QUARANTINE: {sub: {"type": "dir"} for sub in ("root", "deploy", "docs", "tests")},
            mod.QUARANTINE + "/root": {"original.txt": {"type": "file"}},
            mod.QUARANTINE + "/deploy": {},
            mod.QUARANTINE + "/docs": {},
            mod.QUARANTINE + "/tests": {},
        }
        with patch.object(mod, "files_in", side_effect=lambda path: data[path]):
            observed = mod.inspect_quarantine()
        self.assertEqual(observed["total_files"], 1)
        self.assertEqual(observed["sections"]["root"], 1)
        self.assertNotIn("original.txt", str(observed))

    def test_quarantine_invalid_entries_fail_closed(self):
        data = {
            mod.HOME: {"room-check-repo-quarantine-20261029": {"type": "dir"}},
            mod.QUARANTINE: {"root": {"type": "dir"}},
        }
        with patch.object(mod, "files_in", side_effect=lambda path: data[path]):
            with self.assertRaises(mod.Rejected):
                mod.inspect_quarantine()

    def test_path_cannot_escape_welcome_and_quarantine_names_fixed(self):
        for path in ("/home/city/repositories/file", "/home/welcome/../mail", "/etc/passwd"):
            with self.assertRaises(mod.Rejected):
                mod.relative(path)
        with self.assertRaises(mod.Rejected):
            mod.create_private_directory("/home/welcome", "public_html")
        self.assertEqual(mod.relative(mod.ROOT + "/docs/extra.md"),
                         "home/welcome/repositories/room-check/docs/extra.md")


if __name__ == "__main__":
    unittest.main()
