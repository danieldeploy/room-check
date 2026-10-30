#!/usr/bin/env python3
"""One-off bounded npm cache reclamation and metadata-only log audit.

Runs only inside the protected production GitHub Actions environment.
Never reads log contents, mail, installed browsers, live code or credentials.
No directory deletion. File removal uses the existing exact-file guards.
"""
import argparse
import collections
import json
import time

from cpanel_disk_cleanup import Rejected, request_api, safe_file, delete_exact
from cpanel_auto_cleanup import quota

HOME = "/home/welcome/"
NPM_ROOT = HOME + ".npm/_cacache"
LOG_ROOT = HOME + "logs"
AGE_DAYS = 14
MAX_FILES = 40
MAX_BYTES = 200 * 1024 * 1024
MAX_ENTRIES = 10000
MAX_FILE_BYTES = 200 * 1024 * 1024
MB = 1024 * 1024


def scoped_search(root):
    if root not in (NPM_ROOT, LOG_ROOT):
        raise Rejected("root_outside_scope")
    result = request_api(
        "Fileman", "search",
        {"dir": root, "recursive": 1, "mimeinfo": 0,
         "attributes": "size|type|mtime"}, legacy=True)
    rows = result.get("data")
    if not isinstance(rows, list) or len(rows) > MAX_ENTRIES:
        raise Rejected("invalid_scoped_listing")
    checked = set()
    for obj in rows:
        if not isinstance(obj, dict):
            raise Rejected("invalid_scoped_listing")
        path = obj.get("file")
        if not isinstance(path, str) or not (path == root or path.startswith(root + "/")):
            raise Rejected("path_outside_scope")
        if path in checked:
            raise Rejected("duplicate_scoped_path")
        checked.add(path)
        yield obj


def npm_inventory(now, rows):
    candidates = []
    total = eligible = recent = unknown = 0
    file_count = 0
    groups = collections.defaultdict(lambda: {"files": 0, "mb_bytes": 0})
    for row in rows:
        path = row["file"]
        typ = row.get("type")
        if typ == "dir" or path == NPM_ROOT:
            continue
        if typ == "link":
            unknown += 1
            continue
        if typ != "file":
            raise Rejected("unsupported_cache_entry_type")
        relative = path[len(HOME):]
        safe_file(relative)
        try:
            size = int(row["size"])
            modified = int(row["mtime"])
        except (ValueError, KeyError, TypeError):
            raise Rejected("invalid_cache_metadata") from None
        if not (0 <= size <= MAX_FILE_BYTES) or modified <= 0 or modified > now + 3600:
            raise Rejected("invalid_cache_metadata")
        group = relative[len(".npm/_cacache/"):].split("/", 1)[0]
        if group not in ("content-v2", "index-v5", "tmp"):
            group = "other"
        groups[group]["files"] += 1
        groups[group]["mb_bytes"] += size
        file_count += 1
        total += size
        if now - modified >= AGE_DAYS * 86400 and size > 0:
            eligible += size
            candidates.append((relative, size, modified))
        else:
            recent += size
    candidates.sort(key=lambda item: (-item[1], item[0]))
    summary = {
        "ok": True, "cache_only": True, "files": file_count,
        "total_mb": round(total / MB, 3),
        "eligible_files": len(candidates),
        "eligible_mb": round(eligible / MB, 3),
        "recent_or_zero_mb": round(recent / MB, 3),
        "symlinks_skipped": unknown,
        "by_type": {k: {"files": v["files"], "mb": round(v["mb_bytes"] / MB, 3)}
                    for k, v in sorted(groups.items())}
    }
    return summary, candidates


def log_category(path):
    basename = path.rsplit("/", 1)[-1].lower()
    if basename.endswith((".gz", ".zip", ".xz", ".bz2", ".zst")):
        return "compressed_archive"
    if "ssl_log" in basename or "ssl-access" in basename:
        return "https_access"
    if "access" in basename:
        return "http_access"
    if "error" in basename:
        return "error"
    return "other_plain_log"


def log_inventory(now, rows):
    largest = []
    per_kind = collections.defaultdict(lambda: {"files": 0, "size": 0})
    total = files = 0
    for row in rows:
        path = row["file"]
        if row.get("type") != "file":
            continue
        try:
            size, modified = int(row["size"]), int(row["mtime"])
        except (ValueError, KeyError, TypeError):
            raise Rejected("invalid_log_metadata") from None
        if size < 0 or modified <= 0:
            raise Rejected("invalid_log_metadata")
        kind = log_category(path)
        age = max(0, int((now - modified) // 86400))
        per_kind[kind]["files"] += 1
        per_kind[kind]["size"] += size
        total += size
        files += 1
        largest.append((size, kind, age))
    largest.sort(reverse=True)
    return {
        "ok": True, "read_only": True, "contents_read": False,
        "files": files, "total_mb": round(total / MB, 2),
        "by_type": {k: {"files": v["files"], "mb": round(v["size"] / MB, 2)}
                    for k, v in sorted(per_kind.items())},
        "top_three": [{"size_mb": round(size / MB, 2), "category": kind,
                       "age_days": age, "recently_modified": age <= 2}
                      for size, kind, age in largest[:3]],
        "files_deleted": 0
    }


def run(apply):
    start = time.time()
    before_used, limit = quota()
    npm_before, candidates = npm_inventory(start, scoped_search(NPM_ROOT))
    log_report = log_inventory(start, scoped_search(LOG_ROOT))
    print(json.dumps({"stage": "before", "quota_percent": round(100 * before_used / limit, 2),
                      "npm": npm_before, "logs": log_report}, sort_keys=True), flush=True)
    if not apply:
        return
    removed = []
    bytes_removed = 0
    for path, size, modified in candidates:
        if len(removed) >= MAX_FILES or bytes_removed + size > MAX_BYTES:
            break
        if path.startswith(".cache/") or not path.startswith(".npm/_cacache/"):
            raise Rejected("candidate_scope_violation")
        delete_exact(path, size, modified)
        removed.append(path)
        bytes_removed += size
    after, _ = npm_inventory(time.time(), scoped_search(NPM_ROOT))
    # Ensure the completed list cannot be mistaken for a best-effort deletion.
    current = {obj["file"][len(HOME):] for obj in scoped_search(NPM_ROOT)
               if obj.get("type") == "file"}
    if set(removed).intersection(current):
        raise Rejected("removed_file_still_present")
    after_used, after_limit = quota()
    print(json.dumps({"stage": "after", "status": "cleaned" if removed else "no_aged_candidates",
                      "deleted_files": len(removed), "deleted_mb": round(bytes_removed / MB, 3),
                      "quota_before_percent": round(100 * before_used / limit, 2),
                      "quota_after_percent": round(100 * after_used / after_limit, 2),
                      "npm": after}, sort_keys=True), flush=True)


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("--apply", action="store_true")
    args = parser.parse_args()
    try:
        run(args.apply)
    except Rejected as err:
        print(json.dumps({"ok": False, "error": str(err)}), flush=True)
        raise SystemExit(1)
