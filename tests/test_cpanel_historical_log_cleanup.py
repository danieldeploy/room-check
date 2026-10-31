import pathlib
import sys
import unittest
from unittest import mock

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parents[1] / "deploy"))
import cpanel_historical_log_cleanup as old

NOW = 1800000000
OLD = old.LOG_ROOT + "/domain-error_log"
ACTIVE = old.LOG_ROOT + "/active-error_log"


def record(path, megabytes, age, file_type="file"):
    return {"file": path, "size": int(megabytes * old.MB),
            "mtime": NOW - age * 86400, "type": file_type}


class HistoricalLogSafetyTests(unittest.TestCase):
    def test_only_old_oversized_error_log_is_a_candidate(self):
        self.assertEqual(old.candidate(record(OLD, 34, 373), NOW)[0], "logs/domain-error_log")
        for item in [
            record(ACTIVE, 111, 1),
            record(ACTIVE, 34, 4),
            record(old.LOG_ROOT + "/access_log", 34, 400),
            record(old.LOG_ROOT + "/error_log.gz", 34, 400),
            record("/home/welcome/mail/error_log", 34, 400),
            record(old.LOG_ROOT + "/nested/error_log", 34, 400),
            record(OLD, 34, 400, "link"),
            record(OLD, 5, 400),
            record(OLD, 61, 400),
        ]:
            self.assertIsNone(old.candidate(item, NOW))

    def test_below_365_days_not_removed(self):
        self.assertIsNone(old.candidate(record(OLD, 34, 364), NOW))
        self.assertIsNotNone(old.candidate(record(OLD, 34, 365), NOW))

    def test_more_than_one_old_error_log_rejected(self):
        with mock.patch.object(old, "scoped_search", return_value=iter([
            record(OLD, 34, 400),
            record(old.LOG_ROOT + "/another-error_log", 33, 500),
        ])):
            with self.assertRaises(old.Rejected):
                old.snapshot(NOW)

    def test_read_only_never_deletes(self):
        with mock.patch.object(old, "scoped_search", return_value=iter([record(OLD, 34, 400)])), \
             mock.patch.object(old, "quota", return_value=(4900, 6000)), \
             mock.patch.object(old, "request_api") as api, \
             mock.patch.object(old.time, "time", return_value=NOW):
            old.main(False)
            api.assert_not_called()

    def test_delete_only_if_metadata_identical_then_confirm_absence(self):
        rows = [record(OLD, 34, 400), record(ACTIVE, 111, 1)]
        after = [record(ACTIVE, 111, 1)]
        data = [iter(rows), iter(rows), iter(after)]
        calls = []
        def remote(module, method, params):
            calls.append((method, params))
            if method == "get_file_information":
                return {"type": "file", "size": rows[0]["size"], "mtime": rows[0]["mtime"]}
            if method == "delete_file":
                return {"ok": True}
            raise AssertionError(method)
        with mock.patch.object(old, "scoped_search", side_effect=lambda root: data.pop(0)), \
             mock.patch.object(old, "quota", side_effect=[(4900, 6000), (4866, 6000)]), \
             mock.patch.object(old, "request_api", side_effect=remote), \
             mock.patch.object(old.time, "time", return_value=NOW):
            old.main(True)
        self.assertEqual([m for m, _ in calls].count("delete_file"), 1)
        self.assertEqual([params["path"] for m, params in calls if m == "delete_file"], ["logs/domain-error_log"])

    def test_changed_metadata_prevents_delete(self):
        rows = [record(OLD, 34, 400)]
        with mock.patch.object(old, "scoped_search", side_effect=lambda root: iter(rows)), \
             mock.patch.object(old, "quota", return_value=(4900, 6000)), \
             mock.patch.object(old, "request_api", return_value={
                 "type": "file", "size": 1, "mtime": rows[0]["mtime"]}) as api, \
             mock.patch.object(old.time, "time", return_value=NOW):
            with self.assertRaises(old.Rejected):
                old.main(True)
            self.assertNotIn("delete_file", [call.args[1] for call in api.call_args_list])


if __name__ == "__main__":
    unittest.main()
