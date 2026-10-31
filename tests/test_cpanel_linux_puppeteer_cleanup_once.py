import contextlib
import io
import pathlib
import sys
import unittest
from unittest import mock

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parents[1] / "deploy"))
import cpanel_linux_puppeteer_cleanup_once as cleanup


class ExactLinuxPuppeteerCleanupTests(unittest.TestCase):
    def test_only_two_specific_browser_directories(self):
        expected = {
            "/home/welcome/.cache/puppeteer/chrome/linux-153.0.8010.36",
            "/home/welcome/.cache/puppeteer/chrome-headless-shell/linux-153.0.8010.36",
        }
        targets = {parent + "/" + name for _, parent, name, *_ in cleanup.BROWSERS}
        self.assertEqual(targets, expected)
        self.assertEqual(cleanup.VERSION, "153.0.8010.36")
        self.assertFalse(any("mail" in path or "backup" in path for path in targets))

    def test_unexpected_symlink_rejected(self):
        path = cleanup.CACHE + "/chrome/linux-" + cleanup.VERSION
        rows = [{"file": path + "/link", "type": "link", "size": 0}]
        with self.assertRaises(cleanup.Rejected):
            cleanup.snapshot(path, rows, 0, 5, 0, 10)

    def test_unexpected_identity_rejected(self):
        path = cleanup.NPM + "/puppeteer"
        with mock.patch.object(cleanup, "directory", side_effect=[
            {"node_modules": {"type": "dir"}},
            {"puppeteer": {"type": "dir"}},
        ]), mock.patch.object(cleanup, "request_api",
                              return_value={"content": '{"name":"a-different-package"}'}):
            with self.assertRaises(cleanup.Rejected):
                cleanup.npm_targets()

    def test_cron_puppeteer_reference_veto(self):
        report = {"status": "read_only", "tasks": 6, "direct_shell_refs": 0,
                  "direct_chrome_refs": 0, "puppeteer_refs": 1,
                  "headless_153_refs": 0}
        with mock.patch.object(cleanup, "cron_inventory", return_value=report):
            with self.assertRaises(cleanup.Rejected):
                cleanup.check_crons()

    def test_no_deletion_in_dry_run(self):
        versions = {n: {"bytes": 250 * cleanup.MI_B, "files": 300, "fingerprint": "a"}
                    for n, *_ in cleanup.BROWSERS}
        with mock.patch.object(cleanup, "quota", return_value=(4800 * cleanup.MI_B, 6000 * cleanup.MI_B)), \
             mock.patch.object(cleanup, "inspect_browsers", return_value=versions), \
             mock.patch.object(cleanup, "check_crons", return_value={"tasks_checked": 5}), \
             mock.patch.object(cleanup, "npm_targets", return_value=[]), \
             mock.patch.object(cleanup, "request_api") as api, \
             contextlib.redirect_stdout(io.StringIO()):
            cleanup.main(False)
        api.assert_not_called()

    def test_abort_if_browser_changes_before_delete(self):
        first = {"a": {"bytes": 1, "files": 1, "fingerprint": "original"}}
        changed = {"a": {"bytes": 1, "files": 1, "fingerprint": "changed"}}
        with mock.patch.object(cleanup, "quota", return_value=(1000, 2000)), \
             mock.patch.object(cleanup, "inspect_browsers", side_effect=[first, changed]), \
             mock.patch.object(cleanup, "check_crons", return_value={"tasks_checked": 5}), \
             mock.patch.object(cleanup, "npm_targets", return_value=[]), \
             mock.patch.object(cleanup, "request_api") as api, \
             contextlib.redirect_stdout(io.StringIO()):
            with self.assertRaises(cleanup.Rejected):
                cleanup.main(True)
        api.assert_not_called()

    def test_only_expected_delete_api(self):
        with mock.patch.object(cleanup, "directory", side_effect=[
            {"linux-153.0.8010.36": {"type": "dir"}}, {}
        ]), mock.patch.object(cleanup, "request_api", return_value=None) as api:
            cleanup.delete_dir_verified(cleanup.CACHE + "/chrome", "linux-153.0.8010.36")
        api.assert_called_once_with("Fileman", "delete_file", {
            "path": cleanup.CACHE + "/chrome/linux-153.0.8010.36",
            "skip_trash": 1
        })


if __name__ == "__main__":
    unittest.main()
