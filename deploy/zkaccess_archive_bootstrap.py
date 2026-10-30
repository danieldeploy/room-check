#!/usr/bin/env python3
"""Safely bootstrap only the private ZKAccess archive worker and 5-minute cron.

Independent of cPanel Git checkout readiness. Never edits public_html, mail,
invoices, live databases, the Git clone, or existing backup archives.
"""
import argparse
import json
import pathlib
import sys

from cpanel_disk_cleanup import Rejected, request_api
from zkaccess_backup_cron import install as install_cron, inspect as inspect_cron

DEST_DIR = "/home/welcome/room-check-private/cron"
DEST_FILE = "zkaccess-backups.php"
SOURCE = pathlib.Path(__file__).resolve().parents[1] / "cron" / DEST_FILE
MAX_SOURCE_BYTES = 8192


def source_text():
    raw = SOURCE.read_bytes()
    if not 150 <= len(raw) <= MAX_SOURCE_BYTES:
        raise Rejected("unexpected_worker_size")
    text = raw.decode("utf-8")
    required = ("<?php", "PHP_SAPI !== 'cli'", "room_check_zk_backups",
                "ZKAccessBackupDrive", "ZKAccessBackupStore")
    if any(marker not in text for marker in required):
        raise Rejected("unexpected_worker_content")
    return text


def installed_text():
    """None when missing. Fail closed on unusual server entries or contents."""
    rows = request_api("Fileman", "list_files", {"dir": DEST_DIR, "show_hidden": 1})
    if not isinstance(rows, list) or len(rows) > 200 or not all(isinstance(row, dict) for row in rows):
        raise Rejected("worker_directory_unverifiable")
    matches = [row for row in rows if (row.get("file") or row.get("name")) == DEST_FILE]
    if len(matches) > 1:
        raise Rejected("worker_duplicate_entries")
    if not matches:
        return None
    if matches[0].get("type") != "file" or matches[0].get("is_symlink"):
        raise Rejected("worker_not_regular_file")
    response = request_api("Fileman", "get_file_content", {
        "dir": DEST_DIR, "file": DEST_FILE,
        "from_charset": "utf-8", "to_charset": "utf-8"
    })
    if not isinstance(response, dict) or not isinstance(response.get("content"), str):
        raise Rejected("worker_readback_unavailable")
    return response["content"]


def ensure_worker():
    expected = source_text()
    previous = installed_text()
    if previous is not None:
        if previous != expected:
            raise Rejected("existing_worker_differs")
        return "unchanged"
    # Fixed private path. Verify actual contents before installing any cron.
    request_api("Fileman", "save_file_content", {
        "dir": DEST_DIR, "file": DEST_FILE, "content": expected
    })
    if installed_text() != expected:
        raise Rejected("worker_write_unverified")
    return "created"


def inspect():
    expected = source_text()
    existing = installed_text()
    if existing is not None and existing != expected:
        raise Rejected("existing_worker_differs")
    return {"worker_present": existing is not None, "cron_present": inspect_cron()}


def install():
    state = ensure_worker()
    if not install_cron() or not inspect_cron():
        raise Rejected("cron_not_verified")
    return {"worker": state, "cron_present": True}


def main(argv=None):
    parser = argparse.ArgumentParser(description="Protected ZKAccess worker bootstrap")
    parser.add_argument("action", choices=("inspect", "install"))
    opts = parser.parse_args(argv)
    try:
        data = inspect() if opts.action == "inspect" else install()
        print(json.dumps({"ok": True, "action": opts.action, **data}, sort_keys=True))
        return 0
    except Rejected as error:
        print(json.dumps({"ok": False, "error": str(error)}), file=sys.stderr)
        return 1
    except Exception:
        print('{"ok":false,"error":"bootstrap_unavailable"}', file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
