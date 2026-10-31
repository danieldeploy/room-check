#!/usr/bin/env python3
"""Retire only the obsolete Linux Headless Shell 148 cache directory.

Owner-authorized one-off WHM/cPanel action. WHM token from protected Actions
environment only. The current Chrome 153 + Headless Shell 153 are protected.
No mail, logs, backups, credentials, source or browser profiles are changed.
"""
import argparse
import json
import re
import sys
import time

from cpanel_disk_cleanup import Rejected, request_api
from cpanel_auto_cleanup import quota
from cpanel_whm_runtime_capabilities import script_paths_from_crons, safe_read_script

HOME = "/home/welcome"
PARENT = HOME + "/.cache/puppeteer/chrome-headless-shell"
OLD = PARENT + "/linux-148.0.7778.97"
CURRENT_SHELL = PARENT + "/linux-153.0.8010.36"
CURRENT_CHROME = HOME + "/.cache/puppeteer/chrome/linux-153.0.8010.36"
PRIVATE_RUNNER = HOME + "/room-check-private/invoice-runner"
OLD_VERSION = "148.0.7778.97"
MI_B = 1024 * 1024
MAX_ROWS = 80
EXPECTED_FILES = 3
MIN_SIZE = 45 * MI_B
MAX_SIZE = 60 * MI_B


def directory_files(directory):
    items = request_api("Fileman", "list_files", {"dir": directory, "show_hidden": 1})
    if not isinstance(items, list) or len(items) > 500:
        raise Rejected("unsafe_directory_listing")
    found = {}
    for item in items:
        if not isinstance(item, dict):
            raise Rejected("unsafe_directory_listing")
        name = item.get("file") or item.get("name")
        if not isinstance(name, str) or "/" in name or name in ("", ".", ".."):
            raise Rejected("unsafe_directory_listing")
        if name in found:
            raise Rejected("duplicate_directory_entry")
        found[name] = item
    return found


def old_snapshot():
    parent = directory_files(PARENT)
    for name in ("linux-148.0.7778.97", "linux-153.0.8010.36"):
        if parent.get(name, {}).get("type") != "dir":
            raise Rejected("required_browser_version_missing")
    installed = directory_files(CURRENT_CHROME)
    if not installed:
        raise Rejected("current_chrome_unavailable")
    data = request_api("Fileman", "search", {"dir": OLD, "recursive": 1, "mimeinfo": 0,
                                             "attributes": "size|type|mtime|atime"}, legacy=True)
    rows = data.get("data") if isinstance(data, dict) else None
    if not isinstance(rows, list) or len(rows) > MAX_ROWS:
        raise Rejected("invalid_old_version_inventory")
    sizes = []
    paths = set()
    atimes = []
    for item in rows:
        if not isinstance(item, dict):
            raise Rejected("invalid_browser_entry")
        path = item.get("file")
        if not isinstance(path, str) or not (path == OLD or path.startswith(OLD + "/")):
            raise Rejected("browser_entry_outside_expected_root")
        if path in paths:
            raise Rejected("duplicate_browser_entry")
        paths.add(path)
        kind = item.get("type")
        if kind == "dir":
            continue
        if kind != "file":
            raise Rejected("unexpected_browser_entry_type")
        try:
            size = int(item["size"])
            atime = int(item.get("atime") or 0)
        except (ValueError, TypeError, KeyError):
            raise Rejected("invalid_browser_entry_metadata") from None
        if size < 0 or size > MAX_SIZE:
            raise Rejected("unexpected_browser_file_size")
        sizes.append(size)
        if atime > 0:
            atimes.append(atime)
    if len(sizes) != EXPECTED_FILES or not MIN_SIZE <= sum(sizes) <= MAX_SIZE:
        raise Rejected("old_browser_structure_changed")
    if not any(path.endswith("/chrome-headless-shell") for path in paths):
        raise Rejected("old_shell_executable_missing")
    # Access time is weak evidence. Refuse if any file appears accessed
    # in the last 48h; this is an extra safeguard, never proof of non-use.
    if atimes and max(atimes) > time.time() - 2 * 86400:
        raise Rejected("old_shell_recent_access")
    return {"files": len(sizes), "size_bytes": sum(sizes),
            "has_access_metadata": bool(atimes)}, paths


def ensure_no_direct_runtime_reference():
    crons, paths = script_paths_from_crons()
    if crons["cron_direct_headless_refs"] or crons["cron_direct_version_refs"]:
        raise Rejected("cron_has_direct_browser_reference")
    if len(paths) < 1:
        raise Rejected("no_cron_scripts_verified")
    checked = 0
    wrappers = 0
    for path in paths:
        x = safe_read_script(path)
        if x.get("status") != "read":
            raise Rejected("cron_script_unreadable")
        checked += 1
        wrappers += bool(x.get("has_nested_includes"))
        if x.get("has_headless_shell") or x.get("has_executable_path_override"):
            raise Rejected("script_may_reference_shell")
    # Invoice runner may use a runtime executablePath (not present in Git).
    # If its live JSON exists, inspect it without publishing any contents.
    entries = directory_files(PRIVATE_RUNNER)
    runtime = entries.get("invoice-runtime.json")
    if runtime is not None:
        if runtime.get("type") != "file":
            raise Rejected("invalid_runtime_config_type")
        raw = request_api("Fileman", "get_file_content", {
            "dir": PRIVATE_RUNNER, "file": "invoice-runtime.json"})
        contents = raw.get("content") if isinstance(raw, dict) else None
        if not isinstance(contents, str) or len(contents) > 50000:
            raise Rejected("runtime_config_unreadable")
        try:
            obj = json.loads(contents)
        except ValueError:
            raise Rejected("runtime_config_invalid") from None
        if not isinstance(obj, dict):
            raise Rejected("runtime_config_invalid")
        if OLD_VERSION in contents or "chrome-headless-shell" in contents.lower():
            raise Rejected("runtime_references_headless_shell")
    return {"cron_scripts_inspected": checked, "nested_include_scripts": wrappers,
            "runtime_config_present": runtime is not None,
            "process_list_available": False}


def main(apply):
    before, limit = quota()
    snap, _ = old_snapshot()
    refs = ensure_no_direct_runtime_reference()
    print(json.dumps({"step": "preflight", "ok": True, "apply_requested": apply,
                      "old_version_files": snap["files"],
                      "old_version_mib": round(snap["size_bytes"] / MI_B, 2),
                      "current_chrome_and_shell_preserved": True,
                      "direct_reference_check": refs,
                      "quota_before_percent": round(100 * before / limit, 2)}),
          flush=True)
    if not apply:
        return
    # Repeat every check immediately before the only destructive operation.
    again, _ = old_snapshot()
    if again["size_bytes"] != snap["size_bytes"] or again["files"] != snap["files"]:
        raise Rejected("target_changed_before_delete")
    ensure_no_direct_runtime_reference()
    request_api("Fileman", "delete_file", {"path": OLD, "skip_trash": 1})
    parent = directory_files(PARENT)
    if "linux-148.0.7778.97" in parent:
        raise Rejected("browser_delete_not_confirmed")
    if parent.get("linux-153.0.8010.36", {}).get("type") != "dir":
        raise Rejected("current_headless_shell_missing_after_delete")
    if not directory_files(CURRENT_CHROME):
        raise Rejected("current_chrome_missing_after_delete")
    after, after_limit = quota()
    print(json.dumps({"step": "verified_after", "ok": True,
                      "deleted_version": OLD_VERSION,
                      "removed_files": snap["files"],
                      "estimated_removed_mib": round(snap["size_bytes"] / MI_B, 2),
                      "quota_after_percent": round(100 * after / after_limit, 2),
                      "current_chrome_and_shell_preserved": True}), flush=True)


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("--apply", action="store_true")
    a = parser.parse_args()
    try:
        main(a.apply)
    except Rejected as err:
        print(json.dumps({"ok": False, "error": str(err)}), flush=True)
        sys.exit(1)
    except Exception:
        print('{"ok":false,"error":"unexpected_failure"}', flush=True)
        sys.exit(1)
