#!/usr/bin/env python3
"""Read only a fixed private aggregate; never export log content or cursor state."""
import datetime as dt
import hashlib
import json
from pathlib import Path
import time

from cpanel_disk_cleanup import Rejected, request_api
from cpanel_read_private_full_log_audit_once import ALLOWED_SEVERITIES, ALLOWED_REASONS

PRIVATE = "/home/welcome/room-check-private"
COUNTS = {
    "severity_counts": ALLOWED_SEVERITIES,
    "archive_severity_counts": ALLOWED_SEVERITIES,
    "reason_counts": ALLOWED_REASONS,
    "origin_counts": {"management_hub", "wordpress", "roundcube", "unknown"},
}
NUMBERS = ("files_total", "files_unchanged", "resets", "removed_files", "chunks_read", "bytes_read", "undated_chunks", "pending_tail_bytes")


def validate(raw, day):
    if not isinstance(raw, str) or len(raw.encode()) > 12000:
        raise Rejected("daily_log_report_invalid")
    try:
        data = json.loads(raw)
        if (data.get("schema") != "whm-log-daily-v1" or data.get("date_lisbon") != day
                or data.get("status") not in {"baseline", "complete", "partial"}):
            raise ValueError()
        out = {"status": data["status"], "date_lisbon": day}
        for name in NUMBERS:
            n = data[name]
            if type(n) is not int or not 0 <= n <= 10**12:
                raise ValueError()
            out[name] = n
        for name, allowed in COUNTS.items():
            values = {} if data[name] == [] else data[name]
            if not isinstance(values, dict) or not set(values).issubset(allowed):
                raise ValueError()
            if any(type(v) is not int or not 0 <= v <= 10**12 for v in values.values()):
                raise ValueError()
            out[name] = values
        for name in ("first_event_date", "last_event_date"):
            value = data[name]
            if value is not None and (not isinstance(value, str) or dt.date.fromisoformat(value).isoformat() != value):
                raise ValueError()
            out[name] = value
        for name in ("started_utc", "checked_utc", "window_start_utc"):
            value = data[name]
            if dt.datetime.strptime(value, "%Y-%m-%dT%H:%M:%SZ").strftime("%Y-%m-%dT%H:%M:%SZ") != value:
                raise ValueError()
            out[name] = value
        return out
    except (ValueError, KeyError, TypeError, AttributeError):
        raise Rejected("daily_log_report_invalid") from None


def read_daily(day, wait_seconds=690):
    deadline = time.monotonic() + wait_seconds
    expected = hashlib.sha256((Path(__file__).resolve().parents[1] / "cron/whm-log-daily.php").read_bytes()).hexdigest()
    while True:
        # Verify the exact private deployed scanner, not a public login page.
        try:
            source = request_api("Fileman", "get_file_content", {"dir": PRIVATE + "/cron", "file": "whm-log-daily.php"})
            source = source.get("content") if isinstance(source, dict) else None
            installed = isinstance(source, str) and len(source) < 50000 and hashlib.sha256(source.encode()).hexdigest() == expected
            if installed:
                response = request_api("Fileman", "get_file_content", {"dir": PRIVATE, "file": "whm-log-daily.json", "to_charset": "utf-8"})
                result = validate(response.get("content") if isinstance(response, dict) else None, day)
                if result["status"] != "partial" or time.monotonic() >= deadline:
                    return result
        except Rejected:
            pass
        if time.monotonic() >= deadline:
            raise Rejected("daily_log_report_unavailable")
        time.sleep(min(20, max(0, deadline - time.monotonic())))


def read_alert_check(wait_seconds=360):
    """Verify activation and the existing cron's dispatch heartbeat, without IDs."""
    deadline = time.monotonic() + wait_seconds
    while True:
        try:
            activation = request_api("Fileman", "get_file_content", {"dir": PRIVATE, "file": "whm-disk-alert-activation.json"})
            check = request_api("Fileman", "get_file_content", {"dir": PRIVATE, "file": "whm-disk-whatsapp-last-check.json"})
            for item in (activation, check):
                if not isinstance(item, dict) or not isinstance(item.get("content"), str) or len(item["content"]) > 4096:
                    raise ValueError()
            activation = json.loads(activation["content"])
            check = json.loads(check["content"])
            if (activation.get("template") != "whm_storage_alert_v1" or activation.get("language") != "pt_PT"
                    or activation.get("state") != "meta_accepted" or check.get("schema") != "whm-disk-alert-check-v1"):
                raise ValueError()
            when = dt.datetime.strptime(check["checked_utc"], "%Y-%m-%dT%H:%M:%SZ").replace(tzinfo=dt.timezone.utc)
            age = (dt.datetime.now(dt.timezone.utc) - when).total_seconds()
            if not 0 <= age <= 900 or check.get("status") not in {"healthy_no_message", "already_notified", "meta_accepted"}:
                raise ValueError()
            return {"activation": "meta_accepted", "dispatch": check["status"], "checked_utc": check["checked_utc"],
                    "handset_delivery": "not_confirmed"}
        except (Rejected, ValueError, KeyError, TypeError, AttributeError):
            pass
        if time.monotonic() >= deadline:
            raise Rejected("daily_whatsapp_check_unverified")
        time.sleep(min(20, max(0, deadline - time.monotonic())))
