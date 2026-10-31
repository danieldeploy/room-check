#!/usr/bin/env python3
"""One-off deletion of a single stale, oversized error log, with exact metadata checks.

The protected WHM workflow supplies credentials. No mail, application files,
backups, Chrome profiles, installed browsers or active logs can be deleted.
No log content, basename, domain or credentials are printed.
"""
import argparse
import json
import re
import time

from cpanel_disk_cleanup import Rejected, request_api
from cpanel_npm_finisher import LOG_ROOT, scoped_search, quota, log_inventory

MB = 1024 * 1024
MIN_AGE_SECONDS = 365 * 86400
MIN_BYTES = 20 * MB
MAX_BYTES = 60 * MB
MAX_LOG_ENTRIES = 150
SAFE_BASENAME = re.compile(r"[A-Za-z0-9_.-]{1,160}\Z")


def candidate(row, now):
    if not isinstance(row, dict) or row.get("type") != "file":
        return None
    full_path = row.get("file")
    if not isinstance(full_path, str) or not full_path.startswith(LOG_ROOT + "/"):
        return None
    basename = full_path[len(LOG_ROOT) + 1:]
    if (not SAFE_BASENAME.fullmatch(basename)
            or "error" not in basename.lower()
            or basename.lower().endswith((".gz", ".zip", ".xz", ".bz2", ".zst"))):
        return None
    try:
        size = int(row["size"])
        modified = int(row["mtime"])
    except (ValueError, TypeError, KeyError):
        raise Rejected("invalid_log_metadata") from None
    if not (MIN_BYTES <= size <= MAX_BYTES and 0 < modified <= now - MIN_AGE_SECONDS):
        return None
    return ("logs/" + basename, size, modified)


def snapshot(now):
    rows = list(scoped_search(LOG_ROOT))
    if len(rows) > MAX_LOG_ENTRIES:
        raise Rejected("too_many_log_entries")
    hits = [x for row in rows if (x := candidate(row, now)) is not None]
    if len(hits) > 1:
        raise Rejected("ambiguous_historical_error_logs")
    return rows, hits


def inspect_exact(path):
    # cPanel UAPI Fileman/get_file_information refuses ~/logs paths on this
    # account. The same account's legacy Fileman/statfiles is read-only and
    # confirmed to work for this exact directory; it also proves ownership.
    if not path.startswith("logs/") or "/" in path[len("logs/"):]:
        raise Rejected("historical_log_outside_scope")
    basename = path[len("logs/"):]
    data = request_api("Fileman", "statfiles",
                       {"dir": "logs", "files": basename}, legacy=True)
    rows = data.get("data") if isinstance(data, dict) else None
    if not isinstance(rows, list):
        raise Rejected("invalid_legacy_statfiles")
    files = [item for item in rows if isinstance(item, dict)
             and item.get("file") == basename]
    if len(files) != 1 or files[0].get("type") != "file":
        raise Rejected("log_not_regular_file")
    item = files[0]
    if item.get("user") != "welcome":
        raise Rejected("historical_log_owner_mismatch")
    try:
        mode = int(item["mode"])
        modified = int(item["mtime"])
        raw_size = item.get("size")
        size = int(raw_size) if raw_size not in (None, "") else None
    except (ValueError, TypeError, KeyError):
        raise Rejected("invalid_log_file_metadata") from None
    # The legacy statfiles API sometimes returns empty sizes, but the
    # independent scoped Fileman/search must still match exactly.
    if not (mode & 0o200) or modified <= 0:
        raise Rejected("historical_log_not_owner_writable")
    return size, modified


def main(apply):
    now = int(time.time())
    before_used, limit = quota()
    rows, candidates = snapshot(now)
    meta = log_inventory(now, rows)
    print(json.dumps({
        "stage": "preflight", "read_only": not apply,
        "total_log_files": meta["files"], "total_logs_mb": meta["total_mb"],
        "stale_error_candidates": len(candidates),
        "candidate_mb": round(candidates[0][1] / MB, 2) if candidates else 0,
        "candidate_minimum_age_days": 365, "quota_percent": round(100 * before_used / limit, 2)
    }, sort_keys=True), flush=True)
    if not apply or not candidates:
        return

    path, size, modified = candidates[0]
    # Guard against a changed log, changed search results, or a symlink.
    _, fresh = snapshot(int(time.time()))
    if fresh != candidates:
        raise Rejected("historical_log_changed_since_preflight")
    legacy_size, legacy_mtime = inspect_exact(path)
    if legacy_mtime != modified or legacy_size not in (None, size):
        raise Rejected("historical_log_file_changed")
    result = request_api("Fileman", "delete_file", {"path": path, "skip_trash": 1})
    # Always verify absence. Never silently retry a potentially succeeded deletion.
    rows_after, hits_after = snapshot(int(time.time()))
    current_names = {row.get("file") for row in rows_after}
    if LOG_ROOT + "/" + path[len("logs/"):] in current_names:
        raise Rejected("deletion_not_confirmed")
    after_used, after_limit = quota()
    print(json.dumps({
        "stage": "complete", "status": "deleted_verified",
        "files_deleted": 1, "deleted_mb": round(size / MB, 2),
        "candidate_remaining": len(hits_after),
        "log_files_remaining": sum(x.get("type") == "file" for x in rows_after),
        "quota_before_percent": round(100 * before_used / limit, 2),
        "quota_after_percent": round(100 * after_used / after_limit, 2)
    }, sort_keys=True), flush=True)


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("--apply", action="store_true")
    args = parser.parse_args()
    try:
        main(args.apply)
    except Rejected as err:
        print(json.dumps({"ok": False, "error": str(err)}), flush=True)
        raise SystemExit(1)
