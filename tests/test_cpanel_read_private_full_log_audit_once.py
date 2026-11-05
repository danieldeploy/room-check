import contextlib
import io
import json
import pathlib
import sys
import unittest
from unittest import mock

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parents[1] / "deploy"))
import cpanel_read_private_full_log_audit_once as reader


def valid():
    return {
        "ok": True, "report_schema": "whm-streamed-logs-v1",
        "scope": "welcome_logs_only", "no_deletions": True,
        "no_retention_change": True, "files_total": 1,
        "files_fully_streamed": 1, "size_mb": 118.01,
        "total_uncompressed_mb": 120.0, "total_lines_read": 10,
        "severity_counts": {"warning": 9, "fatal": 1},
        "reason_counts": {"undefined_key": 9, "database": 1},
        "files": [{"ordinal": 1, "type": "error", "status": "fully_streamed",
                   "size_mb": 110.78, "days_since_modification": 0,
                   "lines": 10, "severity_counts": {"warning": 9, "fatal": 1},
                   "reason_counts": {"undefined_key": 9, "database": 1},
                   "year_counts": {"2026": 10},
                   "top_repeated_line_counts": [{"count": 9, "severity": "warning"}],
                   "raw_secret": "NEVER_EXPORTED", "name": "a-private-host-error.log"}]
    }


class ReadBackTests(unittest.TestCase):
    def test_accepts_empty_php_counter_arrays_without_accepting_nonempty_lists(self):
        payload = valid()
        payload["files"][0]["year_counts"] = []
        self.assertEqual(reader.sanitize(payload)["files"][0]["year_counts"], {})
        self.assertEqual(reader.safe_counts([], reader.ALLOWED_REASONS), {})
        with self.assertRaises(reader.Rejected):
            reader.safe_counts(["private-line"], reader.ALLOWED_REASONS)

    def test_metadata_distinguishes_absence_from_api_failure(self):
        with mock.patch.object(reader, "request_api", return_value=[]):
            self.assertEqual(reader.artifact_state()["report"]["state"], "absent")
        with mock.patch.object(reader, "request_api", side_effect=reader.Rejected("uapi_failed")):
            self.assertEqual(reader.artifact_state()["directory_status"], "unavailable")

    def test_metadata_only_outputs_fixed_artifacts(self):
        rows = [{"file": "private-secret.json", "type": "file", "size": "123"},
                {"file": reader.REPORT, "type": "file", "size": "345", "mtime": "1"},
                {"file": reader.MARKER, "type": "file", "size": "0", "mtime": "2"}]
        with mock.patch.object(reader, "request_api", return_value=rows):
            out = reader.artifact_state()
        self.assertEqual(out["report"]["size_bytes"], 345)
        self.assertEqual(out["marker"]["state"], "file")
        self.assertNotIn("private-secret", json.dumps(out))

    def test_cron_probe_checks_only_known_source_and_never_prints_commands(self):
        script = "/home/welcome/room-check-private/cron/whatsapp-reminders.php"
        rows = [{"command": "SECRET=value /usr/local/bin/php " + script + " > /private/output 2>&1"}]
        def api(module, function, params, **kw):
            if module == "Cron": return {"data": rows}
            self.assertEqual(params["file"], "whatsapp-reminders.php")
            return {"content": "WhmPrivateLogAuditOnce::runIfNeeded SECRET"}
        with mock.patch.object(reader, "request_api", side_effect=api):
            out = reader.cron_state()
        self.assertEqual(out["reminder_entries"], 1)
        self.assertTrue(out["entrypoints"][0]["audit_hook_present"])
        self.assertNotIn("SECRET", json.dumps(out))
        self.assertNotIn("/private/output", json.dumps(out))

    def test_exact_sanitized_projection_no_content_or_filenames(self):
        payload = valid()
        out = reader.sanitize(payload)
        self.assertEqual(out["files_total"], 1)
        self.assertEqual(out["files"][0]["reason_counts"]["undefined_key"], 9)
        self.assertNotIn("NEVER_EXPORTED", json.dumps(out))
        self.assertNotIn("a-private-host-error.log", json.dumps(out))

    def test_rejects_unexpected_types(self):
        x = valid()
        x["files"][0]["severity_counts"]["sensitive-log-line"] = 55
        with self.assertRaises(reader.Rejected):
            reader.sanitize(x)
        x = valid()
        x["files"][0]["age_days"] = -1
        x["files"][0]["days_since_modification"] = "yesterday"
        with self.assertRaises(reader.Rejected):
            reader.sanitize(x)
        x = valid()
        x["scope"] = "other_account"
        with self.assertRaises(reader.Rejected):
            reader.sanitize(x)

    def test_unavailable_file_is_not_mistaken_for_success(self):
        with mock.patch.object(reader, "request_api",
                               side_effect=reader.Rejected("uapi_failed")):
            self.assertIsNone(reader.fetch())

    def test_reads_only_exact_fixed_private_file(self):
        raw = json.dumps(valid())
        with mock.patch.object(reader, "request_api", return_value={"content": raw}) as api:
            result = reader.fetch()
        self.assertEqual(result["files_fully_streamed"], 1)
        api.assert_called_once_with("Fileman", "get_file_content", {
            "dir": "/home/welcome/room-check-private",
            "file": "whm-log-full-20261104.json", "to_charset": "utf-8"})

    def test_no_raw_output_on_ordinary_success(self):
        raw=json.dumps(valid())
        with mock.patch.object(reader, "request_api", return_value={"content": raw}), \
             mock.patch.dict(reader.os.environ, {"WHM_API_TOKEN":"fake-token"}, clear=False), \
             contextlib.redirect_stdout(io.StringIO()) as out:
            reader.main()
        s=out.getvalue()
        self.assertTrue(json.loads(s)["read_only"])
        self.assertNotIn("fake-token", s)
        self.assertNotIn("NEVER_EXPORTED", s)

if __name__ == "__main__":
    unittest.main()
