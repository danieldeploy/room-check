#!/usr/bin/env python3
"""Read-only bounded WHM disk inventory. Aggregate only; never expose private filenames."""
import collections
import datetime as dt
import json
import re
from cpanel_disk_cleanup import Rejected, request_api

HOME = "/home/welcome"
BACKUP = HOME + "/room-check-backups/"
BROWSER = HOME + "/.cache/puppeteer/"
RELEASE = re.compile(r"release-(\d{8})-(\d{6})-[a-f0-9]{12}-[a-f0-9]{12}\Z")
BROWSER_DIR = re.compile(r"(?:chrome|chrome-headless-shell)/linux-(\d+(?:\.\d+){3})/")
LIMIT = 12000
MB = 1024 * 1024


def as_int(value):
    try:
        n = int(value)
        if n < 0:
            raise ValueError()
        return n
    except (TypeError, ValueError):
        raise Rejected("invalid_size") from None


def disk_usage():
    result = request_api("DiskUsage", "fetchdiskusagewithextras", {}, legacy=True)
    rows = result.get("data")
    if not isinstance(rows, list) or len(rows) != 1:
        raise Rejected("invalid_disk_usage")
    row = rows[0]
    home = row.get("homedir")
    if not isinstance(home, dict) or not isinstance(home.get("contents"), list):
        raise Rejected("invalid_disk_tree")
    wanted = (".cache", ".npm", "room-check-backups", "logs", "mail",
              "public_html", ".trash", "tmp", "room-check-private")
    entries = {}
    for item in home["contents"]:
        if not isinstance(item, dict):
            raise Rejected("invalid_disk_tree")
        if item.get("name") in wanted:
            entries[item["name"]] = round(as_int(item.get("contained_usage")) / MB, 1)
    return {"quota_used_mb": round(as_int(row.get("quotaused")) / MB, 1),
            "quota_limit_mb": None if row.get("quotalimit") is None
            else round(as_int(row["quotalimit"]) / MB, 1),
            "directories_mb": entries}


def search(root):
    if root not in (BACKUP.rstrip("/"), BROWSER.rstrip("/")):
        raise Rejected("root_not_allowed")
    result = request_api("Fileman", "search", {
        "dir": root, "recursive": 1, "mimeinfo": 0,
        "attributes": "size|type|mtime"
    }, legacy=True)
    rows = result.get("data")
    if not isinstance(rows, list) or len(rows) > LIMIT:
        raise Rejected("invalid_search")
    for row in rows:
        if not isinstance(row, dict):
            raise Rejected("invalid_search")
        path = row.get("file")
        if not isinstance(path, str) or not (path == root or path.startswith(root + "/")):
            raise Rejected("unexpected_search_path")
        if row.get("type") in ("file", "dir"):
            yield path, row.get("type"), as_int(row.get("size") or 0)


def backups(rows, now=None):
    now = now or dt.datetime.now(dt.timezone.utc)
    groups = collections.defaultdict(lambda: {"bytes": 0, "files": 0, "manifest": 0, "database": 0, "archive": 0})
    for path, typ, size in rows:
        if not path.startswith(BACKUP):
            continue
        part = path[len(BACKUP):].split("/", 1)
        if len(part) != 2 or not RELEASE.fullmatch(part[0]):
            continue
        release, name = part
        if typ != "file":
            continue
        groups[release]["files"] += 1
        groups[release]["bytes"] += size
        groups[release]["manifest"] += int(name == "complete.json")
        groups[release]["database"] += int(name == "database.sql")
        groups[release]["archive"] += int(name.endswith(".tar.gz") and "/" not in name)
    ordered = sorted(groups, reverse=True)
    policy = {}
    for days in (7, 14, 21, 30, 45):
        cutoff = now - dt.timedelta(days=days)
        candidates = []
        for release in ordered[3:]:
            match = RELEASE.fullmatch(release)
            creation = dt.datetime.strptime(match.group(1) + match.group(2), "%Y%m%d%H%M%S").replace(tzinfo=dt.timezone.utc)
            if creation < cutoff:
                candidates.append(release)
        policy[str(days)] = {"directories": len(candidates),
                             "potential_mb": round(sum(groups[r]["bytes"] for r in candidates) / MB, 1)}
    complete = sum(1 for g in groups.values()
                   if g["manifest"] == 1 and g["database"] == 1 and g["archive"] >= 1)
    return {"directories_with_files": len(groups), "complete_shape_only": complete,
            "incomplete_shape": len(groups) - complete,
            "approximation": "file sizes only; does not validate checksums or restorability",
            "total_file_mb": round(sum(g["bytes"] for g in groups.values()) / MB, 1),
            "oldest_release_date": ordered[-1][8:16] if ordered else None,
            "newest_release_date": ordered[0][8:16] if ordered else None,
            "retention_scenarios_keep_newest_3": policy,
            "deletion_authorized": False}


def browsers(rows):
    groups = collections.defaultdict(lambda: {"files": 0, "bytes": 0})
    zips = {"count": 0, "mb": 0.0}
    for path, typ, size in rows:
        if not path.startswith(BROWSER) or typ != "file":
            continue
        rel = path[len(BROWSER):]
        match = BROWSER_DIR.search(rel)
        if match:
            kind = "headless" if rel.startswith("chrome-headless-shell/") else "chrome"
            key = kind + " " + match.group(1)
            groups[key]["files"] += 1
            groups[key]["bytes"] += size
        elif re.fullmatch(r"chrome-headless-shell/\d+(?:\.\d+){3}-chrome-headless-shell-linux64\.zip", rel):
            zips["count"] += 1
            zips["mb"] += size / MB
    return {"installed_version_sizes_mb": {k: round(v["bytes"] / MB, 1) for k, v in sorted(groups.items())},
            "archive_count": zips["count"], "archive_mb": round(zips["mb"], 1),
            "installed_browser_removal_approved": False}


def main():
    report = {"ok": True, "read_only": True, "disk": disk_usage()}
    for key, root, fn in (("backups", BACKUP.rstrip("/"), backups),
                          ("puppeteer", BROWSER.rstrip("/"), browsers)):
        try:
            report[key] = fn(list(search(root)))
        except Rejected as error:
            report[key] = {"status": "unavailable", "error": str(error)}
    print(json.dumps(report, sort_keys=True))


if __name__ == "__main__":
    try:
        main()
    except Rejected as error:
        print(json.dumps({"ok": False, "error": str(error)}))
        raise SystemExit(1)
