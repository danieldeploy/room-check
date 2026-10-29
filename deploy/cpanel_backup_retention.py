#!/usr/bin/env python3
"""Bounded WHM-only backup retention; always restore-test before deletion.

Never reads mail, app config or production DB; test restore runs in an
ephemeral GitHub-hosted MySQL service. All output consists of fixed codes
and aggregate numbers only. No account data in logs or artifacts.
"""
import argparse
import datetime as dt
import hashlib
import json
import os
import re
import subprocess
import sys
from zoneinfo import ZoneInfo

from cpanel_disk_cleanup import Rejected, request_api
from cpanel_auto_cleanup import quota

ROOT = "/home/welcome/room-check-backups"
RELEASE = re.compile(r"release-(\d{8})-(\d{6})-[0-9a-f]{12}-[0-9a-f]{12}\Z")
ARTIFACT = re.compile(r"(?:application|private-(?:cron|invoice-runner|migrations))\.tar\.gz\Z")
HASH = re.compile(r"[a-f0-9]{64}\Z")
MIN_AGE_DAYS = 2
MAX_DELETIONS = 25
MAX_DELETE_BYTES = 200 * 1024 * 1024
MAX_MANIFEST_BYTES = 32768
MAX_SQL_BYTES = 950000
UTC = dt.timezone.utc
LISBON = ZoneInfo('Europe/Lisbon')


def release_time(name):
    match = RELEASE.fullmatch(name)
    if not match:
        raise Rejected("invalid_release_name")
    try:
        value = dt.datetime.strptime(match.group(1) + match.group(2), "%Y%m%d%H%M%S")
        return value.replace(tzinfo=UTC)
    except ValueError:
        raise Rejected("invalid_release_date") from None


def listing(dirpath):
    response = request_api("Fileman", "list_files",
                           {"dir": dirpath, "show_hidden": 1})
    if not isinstance(response, list) or len(response) > 10000:
        raise Rejected("invalid_backup_listing")
    result = {}
    for item in response:
        if not isinstance(item, dict):
            raise Rejected("invalid_backup_listing")
        name = item.get("file") or item.get("name")
        if not isinstance(name, str) or not name or "/" in name or name in (".", ".."):
            raise Rejected("invalid_backup_listing")
        if name in result:
            raise Rejected("duplicate_backup_listing")
        result[name] = item
    return result


def releases():
    result = listing(ROOT)
    names = []
    for name, item in result.items():
        if name == "release-backup.lock":
            if item.get("type") != "file":
                raise Rejected("invalid_backup_lock")
            continue
        if name.startswith("release-"):
            release_time(name)
            if item.get("type") != "dir":
                raise Rejected("release_not_directory")
            names.append(name)
    return sorted(names, reverse=True)


def plan(names, now):
    """Keep latest 3, daily for 45d, weekly to 180d, monthly to 365d,
    and quarterly thereafter. Never destroy the oldest recovery point.
    """
    if len(names) < 4:
        return set(names), []
    keep = set(names[:3])
    keep.add(names[-1])
    buckets = set()
    for name in names:
        created = release_time(name)
        age = (now - created).total_seconds() / 86400
        if age < -1:
            raise Rejected("future_release_date")
        local = created.astimezone(LISBON)
        if age < 45:
            key = ("daily", local.date().isoformat())
        elif age < 180:
            iso = local.isocalendar()
            key = ("weekly", iso.year, iso.week)
        elif age < 365:
            key = ("monthly", local.year, local.month)
        else:
            key = ("quarterly", local.year, (local.month - 1)//3)
        if key not in buckets:
            keep.add(name)
            buckets.add(key)
    deleted = [name for name in names if name not in keep
               and (now - release_time(name)).total_seconds() >= MIN_AGE_DAYS * 86400]
    return keep, deleted


def content(directory, filename):
    if not (directory == ROOT or (directory.startswith(ROOT + "/")
           and RELEASE.fullmatch(directory.rsplit("/", 1)[1]))):
        raise Rejected("content_scope_denied")
    if filename not in ("complete.json", "database.sql"):
        raise Rejected("content_type_denied")
    response = request_api("Fileman", "get_file_content",
                           {"dir": directory, "file": filename, "to_charset": "utf-8"})
    if not isinstance(response, dict) or not isinstance(response.get("content"), str):
        raise Rejected("invalid_file_content")
    data = response["content"].encode("utf-8")
    if len(data) > (MAX_MANIFEST_BYTES if filename == "complete.json" else MAX_SQL_BYTES):
        raise Rejected("content_too_large")
    return data


def verified_manifest(name):
    release_time(name)
    directory = ROOT + "/" + name
    entries = listing(directory)
    raw = content(directory, "complete.json")
    try:
        data = json.loads(raw)
    except (ValueError, UnicodeError):
        raise Rejected("invalid_manifest") from None
    if not isinstance(data, dict) or data.get("format") not in (1, 2):
        raise Rejected("invalid_manifest")
    digests = data.get("sha256")
    if not isinstance(digests, dict) or len(digests) < 2 or len(digests) > 6:
        raise Rejected("invalid_manifest")
    db = "database.sql" if data["format"] == 1 else "database.sql.gz"
    if db not in digests or "application.tar.gz" not in digests:
        raise Rejected("missing_required_artifact")
    bytes_total = 0
    for filename, sha in digests.items():
        if filename != db and not ARTIFACT.fullmatch(filename):
            raise Rejected("unexpected_artifact")
        if not isinstance(sha, str) or not HASH.fullmatch(sha):
            raise Rejected("invalid_manifest_digest")
        row = entries.get(filename)
        if not isinstance(row, dict) or row.get("type") != "file":
            raise Rejected("artifact_missing_or_not_regular")
        try:
            size = int(row.get("size"))
        except (TypeError, ValueError):
            raise Rejected("invalid_artifact_size") from None
        if not (0 < size <= 1024 * 1024 * 1024):
            raise Rejected("invalid_artifact_size")
        bytes_total += size
    # Never recursively remove a directory containing any unlisted data.
    if set(entries) != set(digests) | {"complete.json"}:
        raise Rejected("unexpected_backup_contents")
    if entries.get("complete.json", {}).get("type") != "file":
        raise Rejected("manifest_not_regular")
    return data, bytes_total


def restore_sql_in_ephemeral_mysql(sql):
    """Use isolated Actions MySQL service, never the production DB."""
    if not isinstance(sql, bytes) or not sql:
        raise Rejected("invalid_restore_input")
    if os.environ.get("GITHUB_ACTIONS") != "true" or os.environ.get("MYSQL_EPHEMERAL") != "1":
        raise Rejected("restore_environment_missing")
    pw = os.environ.get("RESTORE_MYSQL_PASSWORD")
    if not pw or len(pw) > 100:
        raise Rejected("restore_environment_missing")
    try:
        ps = subprocess.run(["docker", "ps", "--format", "{{.ID}} {{.Image}}"],
                            capture_output=True, text=True, timeout=10, check=True)
        ids = [line.split()[0] for line in ps.stdout.splitlines()
               if len(line.split()) == 2 and line.split()[1].startswith("mysql:8")]
        if len(ids) != 1:
            raise Rejected("restore_mysql_not_unique")
        docker = ["docker", "exec", "-i", "-e", "MYSQL_PWD=" + pw, ids[0],
                  "mysql", "-u", "root"]
        init = subprocess.run(docker + ["-e", "CREATE DATABASE restore_check"],
                              capture_output=True, timeout=15)
        if init.returncode != 0:
            raise Rejected("restore_schema_unavailable")
        result = subprocess.run(docker + ["restore_check"], input=sql,
                                stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
                                timeout=90)
        if result.returncode != 0:
            raise Rejected("restore_sql_failed")
        verify = subprocess.run(docker + ["-N", "-e",
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='restore_check'"],
            capture_output=True, timeout=15)
        if verify.returncode != 0 or int(verify.stdout.strip() or b"0") <= 0:
            raise Rejected("restore_empty_schema")
    except (subprocess.TimeoutExpired, OSError, ValueError):
        raise Rejected("restore_mysql_unavailable") from None
    finally:
        # MySQL service is an ephemeral container on the GitHub runner.
        pass


def restore_latest_backup(name):
    data, _ = verified_manifest(name)
    if data["format"] != 1:
        raise Rejected("latest_dump_not_plain_sql")
    sql = content(ROOT + "/" + name, "database.sql")
    if hashlib.sha256(sql).hexdigest() != data["sha256"]["database.sql"]:
        raise Rejected("sql_checksum_mismatch")
    restore_sql_in_ephemeral_mysql(sql)


def process(apply=False, now=None):
    now = now or dt.datetime.now(UTC)
    names = releases()
    keep, remove = plan(names, now)
    if not names:
        raise Rejected("no_restorable_backups")
    # Verify the manifest and expected artifact structure of *every* kept
    # backup before considering a single deletion. Fail closed if even one
    # retained recovery point is malformed.
    for name in sorted(keep, reverse=True):
        verified_manifest(name)
    restore_latest_backup(names[0])
    if not apply:
        print(json.dumps({"ok": True, "mode": "verified_dry_run",
                          "total": len(names), "retained": len(keep),
                          "candidates": len(remove), "sql_restore": "passed",
                          "deleted": 0}))
        return
    before_used, before_limit = quota()
    # Apply only after SQL restore passed. Each deletion is path-scoped and
    # preceded by a fresh root listing and verified manifest. Never delete
    # the only snapshot of any retention bucket.
    count = 0
    bytes_removed = 0
    for name in remove:
        if count >= MAX_DELETIONS:
            break
        if releases() != names:
            raise Rejected("backup_roster_changed")
        _, size = verified_manifest(name)
        if bytes_removed + size > MAX_DELETE_BYTES:
            continue
        directory = ROOT + "/" + name
        request_api("Fileman", "delete_file",
                    {"path": directory, "skip_trash": 1})
        if name in releases():
            raise Rejected("deletion_not_confirmed")
        count += 1
        bytes_removed += size
        # Continuously update the expected snapshot without retrying deletes.
        names.remove(name)
    after_used, after_limit = quota()
    print(json.dumps({"ok": True, "mode": "apply",
                      "quota_before_percent": round(100 * before_used / before_limit, 1),
                      "quota_after_percent": round(100 * after_used / after_limit, 1),
                      "total_remaining": len(names), "retained": len(keep),
                      "deleted": count, "estimated_bytes_deleted": bytes_removed,
                      "mysql_restore": "passed"}))


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--apply", action="store_true")
    args = parser.parse_args()
    try:
        process(args.apply)
    except Rejected as err:
        print(json.dumps({"ok": False, "error": str(err)}))
        return 1
    except Exception:
        print('{"ok":false,"error":"retention_unexpected"}')
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main())
