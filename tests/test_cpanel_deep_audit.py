import datetime as dt
import pathlib
import sys
import unittest
from unittest import mock

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parents[1] / "deploy"))
import cpanel_deep_audit as audit


class DiskAuditTests(unittest.TestCase):
    def test_backup_age_and_shape(self):
        base = audit.BACKUP
        recent = "release-20261028-100000-" + "a" * 12 + "-" + "b" * 12
        old = "release-20261001-100000-" + "c" * 12 + "-" + "d" * 12
        rows = [(base + r + "/", "dir", 4096) for r in (recent, old)]
        for r in (recent, old):
            for file in ("complete.json", "database.sql", "application.tar.gz"):
                rows.append((base + r + "/" + file, "file", 1000))
        result = audit.backups(rows, dt.datetime(2026, 10, 29, tzinfo=dt.timezone.utc))
        self.assertEqual(result["directories_with_files"], 2)
        self.assertEqual(result["complete_shape_only"], 2)
        self.assertEqual(result["retention_scenarios_keep_newest_3"]["7"]["directories"], 0)
        self.assertFalse(result["deletion_authorized"])

    def test_browser_grouping_without_paths(self):
        result = audit.browsers([
            (audit.BROWSER + "chrome/linux-153.0.8010.36/chrome-linux64/chrome", "file", 2000000),
            (audit.BROWSER + "chrome-headless-shell/linux-148.0.7778.97/chrome-headless-shell-linux64/chrome-headless-shell", "file", 1000000),
            (audit.BROWSER + "chrome-headless-shell/148.0.7778.97-chrome-headless-shell-linux64.zip", "file", 118677114),
        ])
        self.assertIn("chrome 153.0.8010.36", result["installed_version_sizes_mb"])
        self.assertEqual(result["archive_count"], 1)
        self.assertFalse(result["installed_browser_removal_approved"])

    def test_reject_search_outside_scoped_directory(self):
        with mock.patch.object(audit, "request_api", return_value={
            "data": [{"file": "/home/welcome/mail/secret", "type": "file", "size": 123}]
        }):
            with self.assertRaises(audit.Rejected):
                list(audit.search(audit.BACKUP.rstrip("/")))

    def test_log_candidates_only_rotated_old_archives(self):
        data = [
            {"file": "access.log.gz", "type": "file", "size": 2000, "mtime": 1},
            {"file": "live.log", "type": "file", "size": 5000, "mtime": 1},
            {"file": "recent.gz", "type": "file", "size": 9000, "mtime": 1793000000},
        ]
        with mock.patch.object(audit, "request_api", return_value=data):
            found = audit.logs(dt.datetime(2026,10,29,tzinfo=dt.timezone.utc))
        self.assertEqual(found["rotated_archives_older_30_days"], 1)
        self.assertFalse(found["deletion_authorized"])

    def test_disk_usage_aggregates_only_named_categories(self):
        with mock.patch.object(audit, "request_api", return_value={
            "data": [{"quotaused": 5500000000, "quotalimit": 6000000000, "homedir": {
                "contents": [{"name": ".cache", "contained_usage": 12345678},
                             {"name": "secret", "contained_usage": 999}]} }]
        }):
            data = audit.disk_usage()
            self.assertIn(".cache", data["directories_mb"])
            self.assertNotIn("secret", data["directories_mb"])


if __name__ == "__main__":
    unittest.main()
