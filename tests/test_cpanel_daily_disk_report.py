import datetime as dt
import json
import pathlib
import sys
import unittest
from unittest import mock

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parents[1] / "deploy"))
import cpanel_daily_disk_report as report
import cpanel_daily_log_report as daily_logs


class DailyDiskReportTests(unittest.TestCase):
    def log_fixture(self, **changes):
        value = dict(schema="whm-log-daily-v1", date_lisbon="2026-11-05", status="complete",
                     started_utc="2026-11-05T00:00:00Z", checked_utc="2026-11-05T00:00:01Z",
                     window_start_utc="2026-11-04T00:00:00Z", first_event_date=None, last_event_date=None)
        value.update({k: 0 for k in daily_logs.NUMBERS})
        value.update({k: [] for k in daily_logs.COUNTS})
        return dict(value, **changes)

    def test_safe_aggregate_rejects_unknown_names_and_raw_strings(self):
        good = self.log_fixture(severity_counts={"fatal": 2})
        self.assertEqual(daily_logs.validate(json.dumps(good), "2026-11-05")["severity_counts"], {"fatal": 2})
        for change in (dict(severity_counts={"guest@example.org": 2}), dict(bytes_read=True),
                       dict(first_event_date="secret"), dict(date_lisbon="2026-11-04"),
                       dict(origin_counts={"unknown": "SECRET"})):
            with self.subTest(change=change), self.assertRaises(report.Rejected):
                daily_logs.validate(json.dumps(dict(good, **change)), "2026-11-05")

    def test_reader_uses_exact_private_source_and_aggregate_only(self):
        source = (pathlib.Path(__file__).resolve().parents[1] / "cron/whm-log-daily.php").read_text()
        with mock.patch.object(daily_logs, "request_api", side_effect=[
                {"content": source}, {"content": json.dumps(self.log_fixture())}]) as api:
            value = daily_logs.read_daily("2026-11-05", wait_seconds=0)
        self.assertEqual(value["status"], "complete")
        self.assertEqual([c.args[2]["file"] for c in api.call_args_list], ["whm-log-daily.php", "whm-log-daily.json"])
        self.assertTrue(all(c.args[1] == "get_file_content" for c in api.call_args_list))
        with mock.patch.object(daily_logs, "request_api", return_value={"content": "stale source"}):
            with self.assertRaises(report.Rejected):
                daily_logs.read_daily("2026-11-05", wait_seconds=0)

    def test_alert_check_requires_accepted_test_and_fresh_normal_cron(self):
        activation = dict(template="whm_storage_alert_v1", language="pt_PT", state="meta_accepted",
                          message_id="PRIVATE_MESSAGE_ID")
        heartbeat = dict(schema="whm-disk-alert-check-v1", status="healthy_no_message",
                         checked_utc=dt.datetime.now(dt.timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"))
        responses = [{"content": json.dumps(v)} for v in (activation, heartbeat)]
        with mock.patch.object(daily_logs, "request_api", side_effect=responses):
            got = daily_logs.read_alert_check(wait_seconds=0)
        self.assertEqual(got["dispatch"], "healthy_no_message")
        self.assertNotIn("PRIVATE", json.dumps(got))
        self.assertEqual(got["handset_delivery"], "not_confirmed")
        for changes in (dict(status="disabled_until_meta_approval"), dict(checked_utc="2020-01-01T00:00:00Z")):
            responses = [{"content": json.dumps(v)} for v in (activation, dict(heartbeat, **changes))]
            with mock.patch.object(daily_logs, "request_api", side_effect=responses), self.assertRaises(report.Rejected):
                daily_logs.read_alert_check(wait_seconds=0)

    def test_new_active_errors_alert_but_archives_do_not(self):
        good = {"backup": "success", "cache": "success", "inventory": "success"}
        self.assertEqual(report.compute_alert(70, 0, 0, 0, {}, [], good,
            {"severity_counts": {"fatal": 1}})[1], "log_errors")
        self.assertEqual(report.compute_alert(70, 0, 0, 0, {}, [], good,
            {"archive_severity_counts": {"fatal": 100}})[1], "none")
        self.assertEqual(report.compute_alert(98, 0, 0, 0, {}, [], good,
            {"severity_counts": {"fatal": 1}})[1], "quota98")

    def test_unavailable_content_is_written_as_failure_before_exit(self):
        audit = ({"quota_used_mb": 4200, "quota_limit_mb": 6000, "directories_mb": {}}, {}, {}, {}, [])
        with mock.patch.object(report, "directory", return_value={}), \
                mock.patch.object(report, "audit", return_value=audit), \
                mock.patch.object(report, "root_hashes", return_value=""), \
                mock.patch.object(report, "read_daily", side_effect=report.Rejected("failed")), \
                mock.patch.object(report, "outcome", return_value="success"), \
                mock.patch.object(report, "write_once") as write, mock.patch("builtins.print"):
            with self.assertRaises(report.Rejected): report.main()
        txt = write.call_args.args[1]
        self.assertIn("daily_log_content_unavailable", txt)
        self.assertIn("alert_code=maintenance_issue", txt)

    def test_exact_requested_quota_thresholds(self):
        healthy = {"backup": "success", "cache": "success", "inventory": "success"}
        samples = [
            (80, "none", "ok"), (89.99, "none", "ok"),
            (90, "quota90", "warning"), (94.99, "quota90", "warning"),
            (95, "quota95", "warning"), (97.99, "quota95", "warning"),
            (98, "quota98", "critical"), (99.99, "quota98", "critical"),
            (100, "quota100", "critical"),
        ]
        for pct, code, status in samples:
            with self.subTest(percent=pct):
                got = report.compute_alert(pct, None, None, 0, {}, [], healthy)
                self.assertEqual((got[1], got[0]), (code, status))

    def test_quota_tier_overrides_other_incidents(self):
        bad = {"backup": "failure", "cache": "success", "inventory": "success"}
        self.assertEqual(
            report.compute_alert(98.2, 300, 75, 0, {"chrome": 200}, [], bad)[1], "quota98")

    def test_large_growth_and_maintenance_failure_trigger_alerts(self):
        good = {"backup": "success", "cache": "success", "inventory": "success"}
        self.assertEqual(report.compute_alert(70, 251, 10, 0, {}, [], good)[1], "growth")
        self.assertEqual(report.compute_alert(70, 5, 71, 0, {}, [], good)[1], "logs_growth")
        self.assertEqual(report.compute_alert(70, 5, 0, 0, {"chrome 153":391}, [], good)[1],
                         "linux_browser_returned")
        self.assertEqual(report.compute_alert(70, 5, 0, 0, {}, [], dict(good, backup="failure"))[1],
                         "maintenance_issue")
        self.assertEqual(report.compute_alert(70, 0, 0, 1, {}, [], good)[1], "none")

    def test_100_percent_caps_overquota_report_for_php_reader(self):
        now = dt.datetime(2026, 11, 1, 10, tzinfo=report.TZ)
        summary = {"quota_used_mb": 6060, "quota_limit_mb": 6000,
                   "directories_mb": {"logs": 100}}
        body, status, code, pct, _ = report.report(
            now, {}, summary, {}, {"installed_version_sizes_mb": {}}, {},
            [], "", {"backup":"success", "inventory":"success", "cache":"success"})
        self.assertEqual((status,code,pct), ("critical", "quota100", 100))
        self.assertIn("quota_percent=100.00", body)

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
