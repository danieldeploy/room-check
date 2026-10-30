import pathlib
import sys
import unittest
from unittest import mock

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parents[1] / "deploy"))
import cpanel_npm_finisher as finish

NOW = 1_800_000_000


def record(relative, size=50, age_days=16, typ="file"):
    return {"file": finish.HOME + relative, "size": size,
            "mtime": NOW - age_days * 86400, "type": typ}


class NpmFinishTests(unittest.TestCase):
    def test_scoped_listing_rejects_escape(self):
        with mock.patch.object(finish, "request_api", return_value={
            "data": [{"file": "/home/welcome/mail/important", "type": "file"}]}):
            with self.assertRaises(finish.Rejected):
                list(finish.scoped_search(finish.NPM_ROOT))

    def test_no_other_scoped_root(self):
        with self.assertRaises(finish.Rejected):
            list(finish.scoped_search("/home/welcome/mail"))

    def test_only_aged_regular_cache_files_eligible(self):
        rows = [record(".npm/_cacache/content-v2/sha512/aa/bb/old", 300),
                record(".npm/_cacache/index-v5/cc/dd/new", 400, 1),
                record(".npm/_cacache/tmp/symlink", 500, 40, "link"),
                record(".npm/_cacache/index-v5/cc/dd/empty", 0, 40)]
        summary, candidates = finish.npm_inventory(NOW, rows)
        self.assertEqual(summary["files"], 3)
        self.assertEqual(summary["symlinks_skipped"], 1)
        self.assertEqual(summary["eligible_files"], 1)
        self.assertEqual(candidates[0][1], 300)

    def test_invalid_cache_path_rejected(self):
        with self.assertRaises(finish.Rejected):
            finish.npm_inventory(NOW, [record(".npm/_cacache/../../mail/secret", 100)])

    def test_apply_never_operates_on_logs_or_recent_files(self):
        old = record(".npm/_cacache/content-v2/sha512/aa/bb/safe", 100)
        new = record(".npm/_cacache/index-v5/aa/bb/new", 200, 1)
        sequence = [iter([old, new]), iter([new]), iter([new])]
        def search(path):
            if path == finish.LOG_ROOT:
                return iter([record("logs/domain-ssl_log", 1000)])
            return sequence.pop(0)
        with mock.patch.object(finish, "scoped_search", side_effect=search), \
             mock.patch.object(finish, "quota", side_effect=[(4900, 6000), (4899, 6000)]), \
             mock.patch.object(finish, "delete_exact", return_value={"bytes": 100}) as deleting, \
             mock.patch.object(finish.time, "time", return_value=NOW):
            finish.run(True)
        deleting.assert_called_once_with(".npm/_cacache/content-v2/sha512/aa/bb/safe",
                                         100, NOW - 16 * 86400)

    def test_log_metadata_without_reading_contents(self):
        rows = [record("logs/sample-ssl_log", 5000000, 0),
                record("logs/rotated.gz", 1000000, 35)]
        result = finish.log_inventory(NOW, rows)
        self.assertEqual(result["files_deleted"], 0)
        self.assertEqual(result["by_type"]["https_access"]["files"], 1)
        self.assertEqual(result["by_type"]["compressed_archive"]["files"], 1)
        self.assertTrue(result["top_three"][0]["recently_modified"])

    def test_fails_closed_on_unexpected_listing(self):
        with mock.patch.object(finish, "request_api", return_value={"data": [{"file": finish.NPM_ROOT + "/evil", "type": "socket"}]}):
            with self.assertRaises(finish.Rejected):
                finish.npm_inventory(NOW, finish.scoped_search(finish.NPM_ROOT))


if __name__ == "__main__":
    unittest.main()
