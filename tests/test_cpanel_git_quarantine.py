import pathlib
import sys
import unittest
from unittest.mock import patch

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parents[1] / "deploy"))
import cpanel_git_quarantine as recovery


class QuarantinePlanningTests(unittest.TestCase):
    @staticmethod
    def case():
        tree = {
            "truncated": False,
            "tree": [
                {"type": "blob", "path": ".cpanel.yml"},
                {"type": "blob", "path": "deploy/release.sh"},
                {"type": "blob", "path": "docs/PROJECT_STATE.md"},
                {"type": "blob", "path": "tests/run.php"},
                {"type": "blob", "path": "cron/invoices.php"},
            ],
        }
        rows = {directory: [] for directory in ("",) + recovery.SUBDIRECTORIES}
        rows[""] = [
            {"file": ".git", "type": "dir"},
            {"file": ".cpanel.yml", "type": "file"},
            {"file": "deploy", "type": "dir"},
            {"file": "docs", "type": "dir"},
            {"file": "tests", "type": "dir"},
            {"file": "cron", "type": "dir"},
            {"file": "local-note.tmp", "type": "file"},
        ]
        rows["deploy"] = [{"file": "release.sh", "type": "file"}] + [
            {"file": "scratch%d.txt" % i, "type": "file"} for i in range(4)]
        rows["docs"] = [{"file": "PROJECT_STATE.md", "type": "file"},
                        {"file": "untracked-draft.txt", "type": "file"}]
        rows["tests"] = [{"file": "run.php", "type": "file"}] + [
            {"file": "local-test%d.php" % i, "type": "file"} for i in range(3)]
        rows["cron"] = [{"file": "invoices.php", "type": "file"}]
        return tree, rows

    def test_only_nine_untracked_regular_files_are_eligible(self):
        tree, rows = self.case()
        plan = recovery.build_plan(tree, rows)
        self.assertEqual(len(plan), 9)
        self.assertEqual(len({p[2] for p in plan}), 9)
        self.assertFalse(any(n in {"release.sh", ".cpanel.yml", "run.php"} for _, n, _ in plan))

    def test_tracked_missing_aborts_before_any_mutation(self):
        tree, rows = self.case()
        rows["deploy"] = [r for r in rows["deploy"] if r["file"] != "release.sh"]
        with self.assertRaises(recovery.Rejected):
            recovery.build_plan(tree, rows)

    def test_unknown_directory_aborts(self):
        tree, rows = self.case()
        rows["docs"][1]["type"] = "dir"
        with self.assertRaises(recovery.Rejected):
            recovery.build_plan(tree, rows)

    def test_changes_in_untracked_count_abort(self):
        tree, rows = self.case()
        rows["docs"].append({"file": "unexpected", "type": "file"})
        with self.assertRaises(recovery.Rejected):
            recovery.build_plan(tree, rows)

    def test_invalid_git_metadata_aborts(self):
        tree, rows = self.case()
        tree["truncated"] = True
        with self.assertRaises(recovery.Rejected):
            recovery.build_plan(tree, rows)

    def test_never_quarantine_symlink_or_oversized_file(self):
        with patch.object(recovery, "request_api", return_value={
            "type": "file", "size": 180, "mtime": 1234, "is_symlink": True
        }):
            with self.assertRaises(recovery.Rejected):
                recovery.private_info("/home/welcome/example")
        with patch.object(recovery, "request_api", return_value={
            "type": "file", "size": 50000000, "mtime": 1234
        }):
            with self.assertRaises(recovery.Rejected):
                recovery.private_info("/home/welcome/example")

    def test_only_pinned_paths_can_be_relocated(self):
        self.assertEqual(
            recovery.home_relative("/home/welcome/room-check-backups/clone/file"),
            "room-check-backups/clone/file",
        )
        with self.assertRaises(recovery.Rejected):
            recovery.home_relative("/etc/passwd")
        with self.assertRaises(recovery.Rejected):
            recovery.home_relative("/home/welcome/public_html/config.php")

    def test_inspect_is_read_only_and_recovery_not_invoked(self):
        tree, rows = self.case()
        state = {"deployable": False, "current_commit": recovery.INSTALLED_SHA}
        with (
            patch.object(recovery, "verified_state", return_value=(state, tree)),
            patch.object(recovery, "take_inventory", return_value=rows),
            patch.object(recovery, "move_verified") as move,
        ):
            recovery.run("inspect")
            move.assert_not_called()


if __name__ == "__main__":
    unittest.main()
