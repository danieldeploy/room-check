#!/usr/bin/env python3
"""Bounded, read-only retrieval of one PHP-streamed private WHM log report.

Never prints any raw log content, name, path, domain, URL, token or arbitrary
string from server. Polls only the exact known private JSON file. No writes.
"""
import json
import hashlib
from pathlib import Path
import os
import re
import sys
import time

from cpanel_disk_cleanup import Rejected, request_api

PRIVATE = "/home/welcome/room-check-private"
REPORT = "whm-log-full-20261104.json"
MARKER = "whm-log-full-20261104.started"
MAX_JSON = 60000
WAIT_SECONDS = 690
STEP = 25
KEY = re.compile(r"\A[a-z][a-z_0-9]{0,35}\Z")
ALLOWED_SEVERITIES = {
    "fatal", "parse", "deprecated", "warning", "notice", "http_5xx",
    "http_4xx", "other_error", "other",
}
ALLOWED_REASONS = {
    "disk_full", "permission", "database", "missing_file", "undefined_key",
    "undefined_variable", "memory", "timeout", "network", "ssl",
    "authentication", "deprecated", "unclassified",
}
ALLOWED_TYPES = {"compressed", "error", "access", "other_plaintext"}
ALLOWED_STATES = {
    "fully_streamed", "empty", "not_readable", "gzip_reader_missing",
    "compression_not_supported", "not_scanned",
}

def artifact_state():
    """Read directory metadata, never unknown file contents or names."""
    try:
        rows = request_api("Fileman", "list_files", {"dir": PRIVATE, "show_hidden": 1})
    except Rejected:
        return {"directory_status": "unavailable"}
    if not isinstance(rows, list) or len(rows) > 3000:
        raise Rejected("invalid_private_listing")
    out = {"directory_status": "read"}
    for label, name in (("report", REPORT), ("marker", MARKER), ("temporary", REPORT + ".tmp")):
        matches = [r for r in rows if isinstance(r, dict) and (r.get("file") or r.get("name")) == name]
        if not matches:
            out[label] = {"state": "absent"}
        elif len(matches) == 1:
            row = matches[0]
            out[label] = {"state": "file" if row.get("type") == "file" else "nonregular",
                          "size_bytes": number(int(row.get("size") or 0)),
                          "mtime": number(int(row.get("mtime") or 0), 5000000000)}
        else:
            raise Rejected("ambiguous_private_artifact")
    return out

def cron_state():
    result = request_api("Cron", "listcron", {}, legacy=True)
    rows = result.get("data") if isinstance(result, dict) else None
    if not isinstance(rows, list) or len(rows) > 100:
        raise Rejected("invalid_cron_list")
    known = {
        "private": PRIVATE + "/cron/whatsapp-reminders.php",
        "repository": "/home/welcome/repositories/room-check/cron/whatsapp-reminders.php",
        "public_legacy": "/home/welcome/public_html/check/cron/whatsapp-reminders.php",
    }
    out = {"reminder_entries": 0, "entrypoints": []}
    for row in rows:
        cmd = row.get("command") if isinstance(row, dict) else None
        if cmd is None: continue
        if not isinstance(cmd, str): raise Rejected("invalid_cron_command")
        if "whatsapp-reminders.php" not in cmd: continue
        out["reminder_entries"] += 1
        kind = next((k for k, p in known.items()
                     if re.search(r"(?<![A-Za-z0-9_./-])" + re.escape(p) + r"(?=$|[\s\"'])", cmd)), None)
        item = {"target": kind or "unrecognized", "php_cli_explicit": "/usr/local/bin/php" in cmd}
        if kind:
            path = known[kind]
            try:
                response = request_api("Fileman", "get_file_content", {
                    "dir": path.rsplit("/", 1)[0], "file": "whatsapp-reminders.php"})
                source = response if isinstance(response, str) else next(
                    (response[k] for k in ("content", "file_content", "data")
                     if isinstance(response, dict) and isinstance(response.get(k), str)), None)
                if source is None or len(source.encode()) > 100000:
                    raise Rejected("invalid_cron_source")
                item.update({"source_read": True,
                             "audit_hook_present": "WhmPrivateLogAuditOnce::runIfNeeded" in source,
                             "matches_reviewed_source": hashlib.sha256(source.encode()).hexdigest() ==
                             hashlib.sha256((Path(__file__).resolve().parents[1] /
                                             "cron/whatsapp-reminders.php").read_bytes()).hexdigest()})
            except Rejected:
                item["source_read"] = False
        out["entrypoints"].append(item)
    return out

def inspect_state():
    state = artifact_state()
    print(json.dumps({"read_only": True, "private_artifacts": state,
                      "cron": cron_state()}, sort_keys=True), flush=True)
    result = fetch()
    if result is not None:
        print(json.dumps(result, sort_keys=True), flush=True)
        return
    exists = state.get("report", {}).get("state") == "file"
    if exists:
        raise Rejected("private_report_unreadable")
    # The reviewed release runs the scanner once, independently of reminders.
    # Waiting only reads the fixed report; it never executes or resets a scan.
    main()

def number(x, maximum=1000000000):
    if type(x) is not int or not (0 <= x <= maximum):
        raise Rejected("unsafe_number")
    return x

def nonnegative_float(x, maximum=10000):
    if type(x) not in (float, int) or not (0 <= x <= maximum):
        raise Rejected("unsafe_float")
    return round(float(x), 2)

def safe_counts(obj, allowed):
    if obj == []:
        obj = {}  # PHP json_encode represents an empty counter array as [].
    if not isinstance(obj, dict) or len(obj) > len(allowed):
        raise Rejected("unsafe_counters")
    result = {}
    for k, v in obj.items():
        if k not in allowed:
            raise Rejected("unexpected_counter_key")
        result[k] = number(v)
    return result

def sanitize(data):
    if not isinstance(data, dict) or data.get("report_schema") != "whm-streamed-logs-v1":
        raise Rejected("unrecognized_report")
    if data.get("ok") is not True or data.get("scope") != "welcome_logs_only":
        raise Rejected("incomplete_or_wrong_scope")
    if data.get("no_deletions") is not True or data.get("no_retention_change") is not True:
        raise Rejected("unexpected_mutation")
    files = data.get("files")
    if not isinstance(files, list) or len(files) > 150:
        raise Rejected("invalid_file_summary")
    result = {
        "ok": True, "read_only": True,
        "files_total": number(data.get("files_total"), 150),
        "files_fully_streamed": number(data.get("files_fully_streamed"), 150),
        "total_size_mb": nonnegative_float(data.get("size_mb"), 250),
        "total_uncompressed_mb": nonnegative_float(data.get("total_uncompressed_mb"), 450),
        "total_lines_read": number(data.get("total_lines_read")),
        "severity_counts": safe_counts(data.get("severity_counts"), ALLOWED_SEVERITIES),
        "reason_counts": safe_counts(data.get("reason_counts"), ALLOWED_REASONS),
        "files": [],
    }
    if result["files_total"] != len(files):
        raise Rejected("file_count_mismatch")
    for f in files:
        if not isinstance(f, dict):
            raise Rejected("invalid_file_item")
        kind = f.get("type")
        status = f.get("status")
        if kind not in ALLOWED_TYPES or status not in ALLOWED_STATES:
            raise Rejected("unknown_file_type_or_status")
        item = {
            "ordinal": number(f.get("ordinal"), 150),
            "type": kind, "status": status,
            "size_mb": nonnegative_float(f.get("size_mb"), 200),
            "age_days": number(f.get("days_since_modification"), 40000),
        }
        if status == "fully_streamed":
            item["lines_read"] = number(f.get("lines"))
            item["severity_counts"] = safe_counts(f.get("severity_counts"), ALLOWED_SEVERITIES)
            item["reason_counts"] = safe_counts(f.get("reason_counts"), ALLOWED_REASONS)
            item["year_counts"] = {}
            years = f.get("year_counts", {})
            if years == []:
                years = {}
            if not isinstance(years, dict) or len(years) > 50:
                raise Rejected("invalid_year_counts")
            for k, v in years.items():
                if not isinstance(k, str) or not re.fullmatch(r"20[0-3][0-9]", k):
                    raise Rejected("invalid_year")
                item["year_counts"][k] = number(v)
            top = f.get("top_repeated_line_counts", [])
            if not isinstance(top, list) or len(top) > 6:
                raise Rejected("invalid_repetition")
            item["top_repeated"] = []
            for row in top:
                if (not isinstance(row, dict)
                        or row.get("severity") not in ALLOWED_SEVERITIES):
                    raise Rejected("invalid_repetition")
                item["top_repeated"].append({
                    "count": number(row.get("count")),
                    "severity": row["severity"],
                })
        result["files"].append(item)
    return result

def fetch():
    try:
        result = request_api("Fileman", "get_file_content", {
            "dir": PRIVATE, "file": REPORT, "to_charset": "utf-8"})
    except Rejected as e:
        if str(e) == "uapi_failed":
            return None
        raise
    raw = result.get("content") if isinstance(result, dict) else None
    if not isinstance(raw, str) or len(raw.encode("utf-8")) > MAX_JSON:
        raise Rejected("private_result_invalid")
    try:
        document = json.loads(raw)
    except ValueError:
        raise Rejected("invalid_result_json") from None
    return sanitize(document)

def main():
    if not os.environ.get("WHM_API_TOKEN"):
        raise Rejected("missing_whm_token")
    for i in range(0, WAIT_SECONDS + 1, STEP):
        result = fetch()
        if result is not None:
            print(json.dumps(result, sort_keys=True), flush=True)
            return
        if i in (0, 300):
            print(json.dumps({"status": "waiting_for_private_cron_log_review",
                              "elapsed_seconds": i}), flush=True)
        if i < WAIT_SECONDS:
            time.sleep(STEP)
    raise Rejected("private_report_not_generated_before_timeout")

if __name__ == "__main__":
    try:
        inspect_state() if sys.argv[1:] == ["--inspect-state"] else main()
    except Rejected as e:
        print(json.dumps({"ok": False, "read_only": True,
                          "error_code": str(e)}), flush=True)
        sys.exit(1)
    except Exception:
        print('{"ok":false,"read_only":true,"error_code":"private_report_read_failed"}', flush=True)
        sys.exit(1)
