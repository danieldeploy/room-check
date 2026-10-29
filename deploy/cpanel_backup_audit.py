#!/usr/bin/env python3
"""Read-only backup-retention inventory. Never deletes backups."""
import json
from cpanel_disk_cleanup import request_api, Rejected

KEEP_NEWEST = 3
MIN_RETENTION_DAYS = 45
ROOT = "room-check-backups"


def inventory(now):
    rows = request_api("Fileman", "list_files", {"dir": ROOT, "show_hidden": 1})
    if not isinstance(rows, list):
        raise Rejected("invalid_backup_inventory")
    releases = []
    for item in rows:
        if not isinstance(item, dict):
            raise Rejected("invalid_backup_inventory")
        name = item.get("file") or item.get("name")
        if not isinstance(name, str):
            raise Rejected("invalid_backup_inventory")
        if not name.startswith("release-") or item.get("type") != "dir":
            continue
        # Never infer recoverability from directory name or age.
        releases.append(name)
    return {"total_release_directories": len(releases),
            "retention_policy": {"keep_newest": KEEP_NEWEST,
                                 "minimum_days": MIN_RETENTION_DAYS},
            "deleted": 0,
            "reason": "must_validate_manifest_checksums_and_recovery_before_deletion"}


if __name__ == "__main__":
    try:
        print(json.dumps({"ok": True, **inventory(None)}))
    except Rejected as e:
        print(json.dumps({"ok": False, "error": str(e)}))
        raise SystemExit(1)
