#!/usr/bin/env python3
"""Scheduled bounded cleanup of known disposable cache files only."""
import json
import time
from cpanel_disk_cleanup import request_api, safe_file, inspect, delete_exact, Rejected

THRESHOLD = 0.90
MIN_AGE = 14 * 86400
MAX_FILES = 40
MAX_DEPTH = 5
MAX_BYTES = 200 * 1024 * 1024


def quota():
    data = request_api("Quota", "get_quota_info", {})
    if not isinstance(data, dict):
        raise Rejected("invalid_quota")
    # Fail closed on undocumented data formats.
    used, limit = data.get("megabytes_used"), data.get("megabyte_limit")
    try:
        used, limit = float(used), float(limit)
    except (ValueError, TypeError):
        raise Rejected("invalid_quota")
    if not (0 <= used <= 1e10 and 0 < limit <= 1e10):
        raise Rejected("invalid_quota")
    return used, limit


def list_files(directory):
    response = request_api("Fileman", "list_files", {"dir": directory, "show_hidden": 1})
    if not isinstance(response, list) or len(response) > 10000:
        raise Rejected("invalid_file_listing")
    return response


def candidates(now):
    stack = [(".npm/_cacache", 0), (".cache/pip", 0), (".cache/uv", 0),
             (".cache/puppeteer/chrome-headless-shell", 0)]
    visited = set()
    while stack:
        directory, depth = stack.pop()
        if directory in visited or depth > MAX_DEPTH:
            continue
        visited.add(directory)
        try:
            entries = list_files(directory)
        except Rejected:
            if directory in (".cache/pip", ".cache/uv"):
                continue  # Optional cache absent or inaccessible.
            raise
        for entry in entries:
            if not isinstance(entry, dict):
                raise Rejected("invalid_file_listing")
            name = entry.get("file") or entry.get("name")
            if not isinstance(name, str) or name in ("", ".", "..") or "/" in name:
                raise Rejected("invalid_file_listing")
            path = directory + "/" + name
            typ = entry.get("type")
            if typ == "dir":
                if depth < MAX_DEPTH:
                    stack.append((path, depth + 1))
                continue
            if typ != "file":
                continue
            safe_file(path)
            try:
                size, mtime = int(entry["size"]), int(entry["mtime"])
            except (KeyError, ValueError, TypeError):
                raise Rejected("invalid_file_listing")
            if 0 <= size <= MAX_BYTES and 0 < mtime < now - MIN_AGE:
                yield path, size, mtime


def main():
    used, limit = quota()
    if used / limit < THRESHOLD:
        print(json.dumps({"ok": True, "status": "below_threshold",
                          "utilization_percent": round(100 * used / limit, 1)}))
        return
    count = freed = 0
    for path, size, mtime in candidates(time.time()):
        if count >= MAX_FILES or freed + size > MAX_BYTES:
            break
        if path.startswith(".cache/puppeteer/"):
            version = path.rsplit("/", 1)[1].split("-chrome-headless-shell-linux64.zip")[0]
            if not version or not any((x.get("file") or x.get("name")) == "linux-" + version and x.get("type") == "dir" for x in list_files(".cache/puppeteer/chrome-headless-shell")):
                continue
        result = delete_exact(path, size, mtime)
        count += 1
        freed += result["bytes"]
    new_used, new_limit = quota()
    print(json.dumps({"ok": True, "status": "cleaned" if count else "no_safe_candidates",
                      "files_deleted": count, "bytes_deleted": freed,
                      "quota_before_percent": round(100 * used / limit, 1),
                      "quota_after_percent": round(100 * new_used / new_limit, 1)}))


if __name__ == "__main__":
    try:
        main()
    except Rejected as err:
        print(json.dumps({"ok": False, "error": str(err)}))
        raise SystemExit(1)
