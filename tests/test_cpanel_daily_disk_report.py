import datetime as dt
import pathlib
import sys
import unittest
from unittest import mock

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parents[1] / "deploy"))
import cpanel_daily_disk_report as report


class DailyDiskReportTests(unittest.TestCase):
    def test_warning_and_critical_thresholds(self):
        self.assertEqual(report.compute_alert(79.9, None, None, 0, {}, [], {
            "backup": "success", "cache": "success", "inventory": "success"})[1], "none")
        self.assertEqual(report.compute_alert(80, None, None, 0, {}, [], {
            "backup": "success", "cache": "success", "inventory": "success"})[1], "quota80")
        self.assertEqual(report.compute_alert(90, None, None, 0, {}, [], {
            "backup": "success", "cache": "success", "inventory": "success"})[1], "quota90")

    def test_large_growth_and_maintenance_failure_trigger_alerts(self):
        good = {"backup": "success", "cache": "success", "inventory": "success"}
        self.assertEqual(report.compute_alert(70, 251, 10, 0, {}, [], good)[1], "growth")
        self.assertEqual(report.compute_alert(70, 5, 71, 0, {}, [], good)[1], "logs_growth")
        self.assertEqual(report.compute_alert(70, 5, 0, 0, {"chrome 153":391}, [], good)[1],
                         "linux_browser_returned")
        self.assertEqual(report.compute_alert(70, 5, 0, 0, {}, [], dict(good, backup="failure"))[1],
                         "maintenance_issue")
        self.assertEqual(report.compute_alert(70, 0, 0, 1, {}, [], good)[1], "none")

    def test_report_does_not_contain_protected_names_or_content(self):
        now = dt.datetime(2026, 11, 1, 10, tzinfo=report.TZ)
        summary = {"quota_used_mb": 4300, "quota_limit_mb": 6000,
                   "directories_mb": {".cache": 0, ".npm": 3.6,
                                      "mail": 3500, "room-check-backups": 250,
                                      "logs": 116, "room-check-private": 145,
                                      "public_html": 48}}
        previous = {"usage_mb": 4250, "logs_mb": 110, "root_hashes": set()}
        txt, status, code, pct, new_roots = report.report(
            now, previous, summary, {"directories_with_files": 40,
                "same_day_redundant_candidates": {"directories": 8}},
            {"installed_version_sizes_mb": {}},
            {"bytes_recursive_mb":116}, [], "1234567890abcdef",
            {"backup":"success","inventory":"success","cache":"success"})
        self.assertEqual((status,code,new_roots), ("ok","none",1))
        self.assertIn("schema=whm-disk-v1",txt)
        self.assertIn("usage_change_mb=+50.0",txt)
        self.assertNotIn("/home/welcome",txt)
        self.assertNotIn("recipient",txt)
        self.assertNotIn("token",txt)
        self.assertLess(len(txt),8000)

    def test_only_exact_dated_filename_can_be_saved(self):
        with self.assertRaises(report.Rejected):
            report.write_once("../../mail/secrets.txt", "xyz", {})
        with mock.patch.object(report, "request_api", side_effect=[
            None, {"content":"safe\n"}
        ]) as api:
            report.write_once("whm-disk-2026-11-01.txt","safe\n",{})
        self.assertEqual(api.call_count,2)
        self.assertEqual(api.call_args_list[0].args[0:2],("Fileman","save_file_content"))
        self.assertTrue(api.call_args_list[0].kwargs.get("form"))
        self.assertEqual(api.call_args_list[0].args[2]["dir"],report.PRIVATE)

    def test_unverified_remote_file_fails_closed(self):
        with mock.patch.object(report,"request_api", side_effect=[
            None, {"content":"tampered"}
        ]):
            with self.assertRaises(report.Rejected):
                report.write_once("whm-disk-2026-11-01.txt","safe\n",{})

    def test_different_date_compares_prior_daily_report(self):
        entries = {"whm-disk-2026-10-31.txt":{"type":"file"},
                   "whm-disk-2026-11-01.txt":{"type":"file"},
                   "whm-disk-2026-10-30.txt":{"type":"file"},
                   "unexpected.txt":{"type":"file"}}
        with mock.patch.object(report,"request_api", return_value={"content":
            "schema=whm-disk-v1\nusage_mb=4200\nlogs_mb=100\nroot_hashes=abcdef0123456789\n"
        }) as api:
            old = report.previous_report(entries,"2026-11-01")
        self.assertEqual(old["usage_mb"],4200)
        self.assertEqual(old["root_hashes"],{"abcdef0123456789"})
        self.assertEqual(api.call_args.args[2]["file"],"whm-disk-2026-10-31.txt")

    def test_report_does_not_touch_mail_on_listing(self):
        with mock.patch.object(report,"request_api", return_value=[{
            "file":"room-check-private","type":"dir"
        },{"file":"mail","type":"dir"},{"file":"unexpected_new","type":"dir"}]):
            x=report.root_hashes()
        self.assertEqual(len(x),16)
        self.assertNotIn("unexpected_new",x)


if __name__ == "__main__":
    unittest.main()
