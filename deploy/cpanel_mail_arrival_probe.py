#!/usr/bin/env python3
"""Read-only incoming-mail metadata check; never reads a message or its filename aloud."""
import datetime as dt
import json
import os
import sys
from cpanel_disk_cleanup import Rejected, request_api

MAILDIR = {
    "welcome": "mail/welcomehostel.pt/info",
    "city": "mail/citycenterhostel.pt/info",
}
WINDOW_SECONDS = 3600
MAX_ENTRIES = 25000

def count_recent(rows, cutoff):
    if not isinstance(rows, list) or len(rows) > MAX_ENTRIES:
        raise Rejected("maildir_listing_unavailable")
    seen = 0
    latest = 0
    for x in rows:
        if not isinstance(x, dict):
            raise Rejected("maildir_listing_unavailable")
        # Do not emit names. Validate names only to avoid link/path confusion.
        name = x.get("file") or x.get("name")
        if not isinstance(name, str) or "/" in name or name in ("", ".", ".."):
            raise Rejected("invalid_maildir_entry")
        if x.get("type") != "file":
            continue
        try:
            mtime = int(x.get("mtime") or 0)
        except (ValueError, TypeError):
            raise Rejected("invalid_mail_mtime") from None
        if mtime >= cutoff:
            seen += 1
            latest = max(latest, mtime)
    return {"messages_modified_within_one_hour": seen,
            "latest_mtime_utc": dt.datetime.fromtimestamp(latest, dt.timezone.utc).isoformat() if latest else None}

def probe(now=None):
    now = now or dt.datetime.now(dt.timezone.utc)
    cutoff = int(now.timestamp()) - WINDOW_SECONDS
    out = {}
    for mailbox, base in MAILDIR.items():
        subdirs = {}
        for folder in ("new", "cur"):
            try:
                rows = request_api("Fileman", "list_files",
                                   {"dir": base + "/" + folder, "show_hidden": 1})
                subdirs[folder] = count_recent(rows, cutoff)
            except Rejected as exc:
                subdirs[folder] = {"status": "unavailable", "error": str(exc)}
        out[mailbox] = subdirs
    return {"ok": True, "read_only": True, "headers_or_bodies_read": False,
            "mail": out}

if __name__ == "__main__":
    try:
        print(json.dumps(probe(), sort_keys=True))
    except Rejected as err:
        print(json.dumps({"ok": False, "error": str(err)}))
        sys.exit(1)
