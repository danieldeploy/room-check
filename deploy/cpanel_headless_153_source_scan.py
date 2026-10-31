#!/usr/bin/env python3
"""Fail-closed, read-only check of live PHP/Node scripts for Chrome Shell use.

Search only account-owned application CODE and configuration files. Skip mail,
uploads, backups, guest data, logs and external dependencies. Report counts and
flags only: no paths, file contents, secrets or process details leave the runner.
This audit alone never authorizes deletion while the Linux process list is absent.
"""
import collections
import json
import re
import sys
import time

from cpanel_disk_cleanup import Rejected, request_api
from cpanel_headless_dependency_audit import browser_inventory
from cpanel_whm_runtime_capabilities import script_paths_from_crons, safe_read_script

HOME = "/home/welcome"
ROOTS = (HOME + "/room-check-private", HOME + "/public_html")
MAX_DIRS = 180
MAX_FILES = 500
MAX_BYTES = 9 * 1024 * 1024
MAX_ONE = 160 * 1024
MAX_DEPTH = 5
IGNORED_DIRS = frozenset({
    ".git", "node_modules", "vendor", "backups", "backup", "mail", "cache",
    "tmp", "logs", "log", "storage", "uploads", "upload", "photos", "images",
    "img", "media", "pdf", "pdfs", "sessions", "session", "invoices", "documents",
    "assets", "fonts", "screenshots", "snapshots", ".cache"
})
ALLOWED_SUFFIX = (".php", ".py", ".sh", ".js", ".mjs", ".cjs", ".json", ".yaml",
                  ".yml", ".ini", ".conf", ".env")
SENSITIVE_DENY = re.compile(r"pass(word)?|secret|token|cookie|session|credentials|private.key|\.pem$", re.I)
PUPPETEER_REF = re.compile(r"chrome-headless-shell|headless\s*:\s*['\"]shell['\"]|PUPPETEER_EXECUTABLE_PATH|PUPPETEER_CHROME_HEADLESS_SHELL|linux-153\.0\.8010\.36", re.I)
CHROME_OVERRIDE = re.compile(r"executablePath|PUPPETEER_EXECUTABLE_PATH|browserExecutablePath", re.I)
PUPPETEER_USAGE = re.compile(r"puppeteer|chrome-headless-shell|chromium", re.I)
SOURCE_IS_CODE = re.compile(r"\.(php|py|sh|mjs|js|cjs)$", re.I)
MAX_CHILDREN = 600


def directory_entries(path):
    entries = request_api("Fileman", "list_files", {"dir": path, "show_hidden": 1})
    if not isinstance(entries, list) or len(entries) > MAX_CHILDREN:
        raise Rejected("invalid_code_tree_listing")
    result = []
    names = set()
    for value in entries:
        if not isinstance(value, dict):
            raise Rejected("malformed_code_entry")
        name = value.get("file") or value.get("name")
        if not isinstance(name, str) or not name or "/" in name or name in (".", ".."):
            raise Rejected("invalid_code_basename")
        if name in names:
            raise Rejected("duplicate_code_entry")
        names.add(name)
        result.append((name, value))
    return result


def script_content(path):
    if (not isinstance(path, str) or any(part in (".", "..") for part in path.split("/"))
            or not any(path.startswith(root + "/") for root in ROOTS)):
        raise Rejected("code_path_outside_allowlist")
    dirname, basename = path.rsplit("/", 1)
    if SENSITIVE_DENY.search(basename) and basename not in (".env",):
        raise Rejected("sensitive_file_not_scannable")
    data = request_api("Fileman", "get_file_content", {"dir": dirname, "file": basename})
    if isinstance(data, str):
        text = data
    elif isinstance(data, dict):
        text = next((data[key] for key in ("content", "file_content", "data")
                     if isinstance(data.get(key), str)), None)
    else:
        text = None
    if text is None or len(text) > MAX_ONE:
        raise Rejected("code_content_unavailable")
    return text


def source_tree_audit():
    seen_paths = set()
    candidates = []
    checked_dirs = 0
    skipped_dirs = 0
    total_files = 0
    filtered_files = 0
    kinds = collections.Counter()
    for root in ROOTS:
        queue = [(root, 0)]
        while queue:
            dirname, depth = queue.pop(0)
            if dirname in seen_paths:
                raise Rejected("duplicate_code_directory")
            seen_paths.add(dirname)
            checked_dirs += 1
            if checked_dirs > MAX_DIRS:
                raise Rejected("code_tree_too_large")
            for name, metadata in directory_entries(dirname):
                kind = metadata.get("type")
                full = dirname + "/" + name
                if kind == "dir":
                    if name.lower() in IGNORED_DIRS or depth >= MAX_DEPTH:
                        skipped_dirs += 1
                    else:
                        queue.append((full, depth + 1))
                elif kind == "file":
                    total_files += 1
                    if (not name.lower().endswith(ALLOWED_SUFFIX) and name != ".env") or (
                        SENSITIVE_DENY.search(name) and name != ".env"
                    ):
                        filtered_files += 1
                        continue
                    try:
                        size = int(metadata.get("size") or 0)
                    except (TypeError, ValueError):
                        raise Rejected("code_size_invalid") from None
                    if size > MAX_ONE or size < 0:
                        raise Rejected("code_source_exceeds_limit")
                    candidates.append((full, size))
                else:
                    # Symlinks are not followed or read.
                    if kind != "link":
                        raise Rejected("unsupported_code_entry_type")
    if len(candidates) > MAX_FILES or sum(size for _, size in candidates) > MAX_BYTES:
        raise Rejected("code_source_scan_exceeds_limit")
    hits = collections.Counter()
    sizes = 0
    unreadable = 0
    for path, declared_size in candidates:
        try:
            content = script_content(path)
        except Rejected:
            unreadable += 1
            continue
        sizes += len(content)
        hits["scanned_files"] += 1
        hits["shell_explicit"] += int(bool(PUPPETEER_REF.search(content)))
        hits["chrome_override"] += int(bool(CHROME_OVERRIDE.search(content)))
        hits["puppeteer_or_chromium"] += int(bool(PUPPETEER_USAGE.search(content)))
        hits["config_files_scanned"] += int(path.endswith((".env", ".ini", ".json", ".yaml", ".yml", ".conf")))
        hits["shell_in_config"] += int(path.endswith((".env", ".ini", ".json", ".yaml", ".yml", ".conf")) and bool(PUPPETEER_REF.search(content)))
        hits["shell_in_code"] += int(bool(SOURCE_IS_CODE.search(path)) and bool(PUPPETEER_REF.search(content)))
    return {
        "root_count": len(ROOTS), "dirs_examined": checked_dirs,
        "dirs_excluded": skipped_dirs, "all_regular_files_seen": total_files,
        "noncode_files_excluded": filtered_files,
        "candidate_source_files": len(candidates),
        "source_files_read": hits["scanned_files"], "source_read_errors": unreadable,
        "source_bytes_scanned": sizes,
        "explicit_shell_references": hits["shell_explicit"],
        "chrome_executable_overrides": hits["chrome_override"],
        "puppeteer_or_chromium_references": hits["puppeteer_or_chromium"],
        "config_files_read": hits["config_files_scanned"],
        "explicit_shell_refs_in_config": hits["shell_in_config"],
        "explicit_shell_refs_in_code": hits["shell_in_code"],
        "complete_code_scan": unreadable == 0,
        "process_list_still_unavailable": True
    }


def main():
    c, paths = script_paths_from_crons()
    if c["cron_direct_headless_refs"] or c["cron_direct_version_refs"]:
        raise Rejected("cron_direct_shell_ref_found")
    summary = source_tree_audit()
    if not summary["complete_code_scan"]:
        status = "incomplete"
    elif summary["explicit_shell_references"] > 0:
        status = "shell_references_found"
    else:
        status = "no_explicit_shell_reference_in_scanned_files"
    browser = browser_inventory(int(time.time()))
    report = {
        "ok": True, "read_only": True, "code": summary, "status": status,
        "cron_entries": c["crons"], "cron_scripts_identified": len(paths),
        "browsers": browser["version_groups"],
        "processes_and_unlisted_jobs_verified": False,
        "shell_153_safe_to_delete_proven": False
    }
    print(json.dumps(report, sort_keys=True), flush=True)


if __name__ == "__main__":
    try:
        main()
    except Rejected as e:
        print(json.dumps({"ok": False, "read_only": True, "error": str(e)}), flush=True)
        sys.exit(1)
