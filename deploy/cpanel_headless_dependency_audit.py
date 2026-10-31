#!/usr/bin/env python3
"""Read-only Puppeteer installed-version and scheduled-task dependency audit.

Do not print cron commands, filenames, domains, tokens, process details, or
browser profiles. No API writes. Absence of a cron reference alone is NOT proof
that a binary is unused (runtime executablePath may be configured elsewhere).
"""
import collections
import json
import re
import time

from cpanel_disk_cleanup import Rejected, request_api

ROOT = "/home/welcome/.cache/puppeteer"
VERSIONS = ("148.0.7778.97", "153.0.8010.36")
MB = 1024 * 1024
MAX_ROWS = 12000
SHELL = re.compile(r"/chrome-headless-shell/linux-(148\.0\.7778\.97|153\.0\.8010\.36)/")
CHROME = re.compile(r"/chrome/linux-153\.0\.8010\.36/")
BINARY = re.compile(r"/chrome-headless-shell-linux64/chrome-headless-shell\Z")


def browser_inventory(now):
    result = request_api("Fileman", "search", {
        "dir": ROOT, "recursive": 1, "mimeinfo": 0,
        "attributes": "size|type|mtime|atime|user"
    }, legacy=True)
    rows = result.get("data")
    if not isinstance(rows, list) or len(rows) > MAX_ROWS:
        raise Rejected("invalid_browser_inventory")
    totals = collections.defaultdict(lambda: {"files": 0, "mb_bytes": 0,
                                              "exe_count": 0, "exe_atime_days": None})
    unexpected = 0
    for row in rows:
        if not isinstance(row, dict):
            raise Rejected("invalid_browser_row")
        path = row.get("file")
        if not isinstance(path, str) or not (path == ROOT or path.startswith(ROOT + "/")):
            raise Rejected("browser_path_outside_scope")
        if row.get("type") == "dir":
            continue
        if row.get("type") != "file":
            unexpected += 1
            continue
        match = SHELL.search(path)
        if match:
            group = "headless_shell_" + match.group(1)
        elif CHROME.search(path):
            group = "chrome_153"
        else:
            group = "other"
        try:
            size = int(row.get("size") or 0)
        except (ValueError, TypeError):
            raise Rejected("invalid_browser_size") from None
        if size < 0:
            raise Rejected("invalid_browser_size")
        totals[group]["mb_bytes"] += size
        totals[group]["files"] += 1
        if group.startswith("headless_shell_") and BINARY.search(path):
            totals[group]["exe_count"] += 1
            try:
                atime = int(row.get("atime") or 0)
            except (ValueError, TypeError):
                atime = 0
            # atime is not reliable on noatime/relatime-mounted filesystems.
            totals[group]["exe_atime_days"] = None if atime <= 0 else max(0, int((now-atime)//86400))
    return {
        "read_only": True, "nonregular_skipped": unexpected,
        "version_groups": {
            k: {"files": v["files"], "mb": round(v["mb_bytes"] / MB, 2),
                "shell_executable_count": v["exe_count"],
                "shell_executable_last_access_days_unreliable": v["exe_atime_days"]}
            for k, v in sorted(totals.items())
        },
        "no_usage_proof_from_atime": True
    }


def cron_inventory():
    try:
        result = request_api("Cron", "listcron", {}, legacy=True)
    except Rejected as error:
        return {"status": "unavailable", "error_code": str(error)}
    rows = result.get("data")
    if not isinstance(rows, list) or len(rows) > 500:
        raise Rejected("invalid_cron_listing")
    report = {"status": "read_only", "tasks": 0,
              "direct_shell_refs": 0, "direct_chrome_refs": 0,
              "puppeteer_refs": 0, "node_task_refs": 0,
              "opaque_wrappers": 0, "headless_148_refs": 0,
              "headless_153_refs": 0}
    for row in rows:
        if not isinstance(row, dict):
            raise Rejected("invalid_cron_entry")
        cmd = row.get("command")
        if cmd is None:
            continue
        if not isinstance(cmd, str):
            raise Rejected("invalid_cron_entry")
        report["tasks"] += 1
        lowered = cmd.lower()
        report["direct_shell_refs"] += int("chrome-headless-shell" in lowered)
        report["direct_chrome_refs"] += int("chrome" in lowered)
        report["puppeteer_refs"] += int("puppeteer" in lowered)
        report["node_task_refs"] += int("node " in lowered or "/node" in lowered)
        report["opaque_wrappers"] += int(
            any(key in lowered for key in (" php ", ".php", "sh ", "bash", ".sh"))
            and "chrome-headless-shell" not in lowered)
        report["headless_148_refs"] += int("148.0.7778.97" in lowered)
        report["headless_153_refs"] += int("153.0.8010.36" in lowered)
    report["does_not_prove_unreferenced_binaries_unused"] = True
    return report


def main():
    now = int(time.time())
    report = {"ok": True, "read_only": True, "puppeteer": browser_inventory(now),
              "account_crons": cron_inventory(),
              "runtime_processes_verified": False,
              "runtime_environment_verified": False,
              "installed_browser_removal_authorized": False}
    print(json.dumps(report, sort_keys=True), flush=True)


if __name__ == "__main__":
    try:
        main()
    except Rejected as error:
        print(json.dumps({"ok": False, "read_only": True, "error": str(error)}))
        raise SystemExit(1)
