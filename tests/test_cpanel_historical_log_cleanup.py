import pathlib
import sys
import unittest
from unittest import mock

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parents[1] / "deploy"))
import cpanel_historical_log_cleanup as old

NOW = 1800000000
OLD = old.LOG_ROOT + "/domain-error_log"
ACTIVE = old.LOG_ROOT + "/active-error_log"


def record(path, megabytes, age_days, kind="file"):
    return {"file": path, "size": int(megabytes * old.MB),
            "mtime": NOW - age_days * 86400, "type": kind}


class StaleLogTests(unittest.TestCase):
    def test_only_one_year_old_large_uncompressed_error_log(self):
        self.assertEqual(old.candidate(record(OLD, 34, 400), NOW)[0],
                         "logs/domain-error_log")
        for row in (
            record(ACTIVE, 111, 1), record(ACTIVE, 34, 3),
            record(OLD, 34, 364), record(OLD, 34, 500, "link"),
            record(old.LOG_ROOT + "/access_log", 34, 400),
            record(old.LOG_ROOT + "/error_log.gz", 34, 400),
            record(old.LOG_ROOT + "/nested/error_log", 34, 400),
            record("/home/welcome/mail/error_log", 34, 400),
            record(OLD, 5, 500), record(OLD, 65, 500),
        ):
            self.assertIsNone(old.candidate(row, NOW))

    def test_rejects_ambiguous_historical_logs(self):
        rows = [record(OLD, 34, 400),
                record(old.LOG_ROOT + "/another-error_log", 33, 500)]
        with mock.patch.object(old, "scoped_search", return_value=iter(rows)):
            with self.assertRaises(old.Rejected):
                old.snapshot(NOW)

    def test_dry_run_makes_no_file_api_changes(self):
        rows = [record(OLD, 34, 400)]
        with (
            mock.patch.object(old, "scoped_search", side_effect=lambda _: iter(rows)),
            mock.patch.object(old, "quota", return_value=(4900, 6000)),
            mock.patch.object(old, "request_api") as api,
            mock.patch.object(old.time, "time", return_value=NOW),
        ):
            old.main(False)
        api.assert_not_called()

    def test_applies_using_legacy_stat_and_confirms_removal(self):
        rows = [record(OLD, 34, 400), record(ACTIVE, 111, 1)]
        after = [record(ACTIVE, 111, 1)]
        sequence = [rows, rows, after]
        calls = []
        def api(module, method, params, legacy=False):
            calls.append((method, params))
            if method == "statfiles":
                self.assertTrue(legacy)
                return {"data": [{"file": "domain-error_log", "type": "file",
                                  "user": "welcome", "mode": 33188,
                                  "size": "", "mtime": rows[0]["mtime"]}]}
            if method == "delete_file":
                self.assertEqual(params, {"path": "logs/domain-error_log",
                                          "skip_trash": 1})
                return None
            raise AssertionError(method)
        with (
            mock.patch.object(old, "scoped_search",
                              side_effect=lambda _: iter(sequence.pop(0))),
            mock.patch.object(old, "quota",
                              side_effect=[(4900, 6000), (4866, 6000)]),
            mock.patch.object(old, "request_api", side_effect=api),
            mock.patch.object(old.time, "time", return_value=NOW),
        ):
            old.main(True)
        self.assertEqual([name for name, _ in calls].count("delete_file"), 1)

    def test_rejects_size_changed_in_legacy_stat(self):
        rows = [record(OLD, 34, 400)]
        with (
            mock.patch.object(old, "scoped_search", side_effect=lambda _: iter(rows)),
            mock.patch.object(old, "quota", return_value=(4900, 6000)),
            mock.patch.object(old, "request_api", return_value={"data": [{
                "file": "domain-error_log", "type": "file", "user": "welcome",
                "mode": 33188, "size": 1, "mtime": rows[0]["mtime"]}]}) as api,
            mock.patch.object(old.time, "time", return_value=NOW),
        ):
            with self.assertRaises(old.Rejected):
                old.main(True)
        self.assertNotIn("delete_file", [c.args[1] for c in api.call_args_list])

    def test_rejects_wrong_file_owner(self):
        with mock.patch.object(old, "request_api", return_value={"data": [{
            "file": "domain-error_log", "type": "file",
            "user": "root", "mode": 33188, "mtime": 1, "size": 20 * old.MB,
        }]}):
            with self.assertRaises(old.Rejected):
                old.inspect_exact("logs/domain-error_log")


if __name__ == "__main__":
    unittest.main()
