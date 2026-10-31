#!/usr/bin/env python3
"""Owner-authorized one-off removal of the abandoned Linux Puppeteer installation.

Only two exact Chrome-for-Testing 153 cache directories and, if verified,
Puppeteer-owned npm packages in the private Linux invoice-runner installation.
The Windows agent, project source, PHP cron, mail, databases and backups are
always outside this operation. The WHM token stays in the protected job.
"""
import argparse
import hashlib
import json
import sys

from cpanel_disk_cleanup import Rejected, request_api
from cpanel_auto_cleanup import quota
from cpanel_headless_dependency_audit import cron_inventory

HOME = "/home/welcome"
CACHE = HOME + "/.cache/puppeteer"
VERSION = "153.0.8010.36"
MI_B = 1024 * 1024
BROWSERS = (
    ("headless_shell_153", CACHE + "/chrome-headless-shell", "linux-" + VERSION,
     240, 275, 260, 420, "/chrome-headless-shell-linux64/chrome-headless-shell"),
    ("chrome_153", CACHE + "/chrome", "linux-" + VERSION,
     375, 410, 275, 460, "/chrome-linux64/chrome"),
)
RUNNER = HOME + "/room-check-private/invoice-runner"
NPM = RUNNER + "/node_modules"
PACKAGES = (("puppeteer", "puppeteer"), ("puppeteer-core", "puppeteer-core"),
            ("@puppeteer/browsers", "@puppeteer/browsers"))


def directory(path):
    items = request_api("Fileman", "list_files", {"dir": path, "show_hidden": 1})
    if not isinstance(items, list) or len(items) > 1200:
        raise Rejected("unsafe_directory_list")
    result = {}
    for item in items:
        if not isinstance(item, dict):
            raise Rejected("malformed_directory_entry")
        name = item.get("file") or item.get("name")
        if not isinstance(name, str) or not name or "/" in name or name in (".", "..") or name in result:
            raise Rejected("malformed_directory_entry")
        result[name] = item
    return result


def search_rows(path, maximum):
    result = request_api("Fileman", "search", {
        "dir": path, "recursive": 1, "mimeinfo": 0,
        "attributes": "size|type|mtime"
    }, legacy=True)
    rows = result.get("data") if isinstance(result, dict) else None
    if not isinstance(rows, list) or len(rows) > maximum:
        raise Rejected("invalid_file_inventory")
    return rows


def snapshot(path, rows, min_mb, max_mb, min_files, max_files, executable=None):
    files = 0
    sizes = 0
    signatures = set()
    executable_found = False
    for item in rows:
        if not isinstance(item, dict):
            raise Rejected("invalid_file_entry")
        p = item.get("file")
        if not isinstance(p, str) or not (p == path or p.startswith(path + "/")):
            raise Rejected("file_outside_exact_target")
        if p in signatures:
            raise Rejected("duplicate_file_entry")
        signatures.add(p)
        kind = item.get("type")
        if kind == "dir":
            continue
        if kind != "file":
            raise Rejected("unexpected_symlink_or_file_type")
        try:
            n = int(item["size"])
            modified = int(item.get("mtime") or 0)
        except (ValueError, TypeError, KeyError):
            raise Rejected("invalid_file_metadata") from None
        if n < 0 or n > max_mb * MI_B or modified < 0:
            raise Rejected("invalid_file_size")
        files += 1
        sizes += n
        executable_found |= bool(executable and p.endswith(executable))
    if not min_files <= files <= max_files or not min_mb * MI_B <= sizes <= max_mb * MI_B:
        raise Rejected("unexpected_target_content")
    if executable and not executable_found:
        raise Rejected("expected_executable_missing")
    signature = hashlib.sha256("\n".join(sorted(signatures)).encode()).hexdigest()
    return {"files": files, "bytes": sizes, "fingerprint": signature}


def inspect_browsers():
    data = search_rows(CACHE, 4000)
    inventory = {}
    for name, parent, basename, min_mb, max_mb, min_files, max_files, executable in BROWSERS:
        current = directory(parent)
        if current.get(basename, {}).get("type") != "dir":
            raise Rejected("expected_browser_missing")
        full = parent + "/" + basename
        rows = [x for x in data if isinstance(x, dict) and isinstance(x.get("file"), str)
                and (x["file"] == full or x["file"].startswith(full + "/"))]
        inventory[name] = snapshot(full, rows, min_mb, max_mb, min_files, max_files, executable)
    return inventory


def check_crons():
    report = cron_inventory()
    if report.get("status") != "read_only" or report.get("tasks", 0) < 1:
        raise Rejected("cron_inspection_incomplete")
    if any(report.get(key, 0) for key in
           ("direct_shell_refs", "direct_chrome_refs", "puppeteer_refs",
            "headless_153_refs")):
        raise Rejected("cron_references_installed_browser")
    return {"tasks_checked": report["tasks"], "direct_browser_refs": 0}


def npm_targets():
    # The directory might never have been installed; absence is not an error.
    try:
        private = directory(RUNNER)
    except Rejected:
        return None
    if "node_modules" not in private:
        return []
    if private["node_modules"].get("type") != "dir":
        raise Rejected("unexpected_node_modules_type")
    try:
        modules = directory(NPM)
    except Rejected:
        return None
    found = []
    for subpath, name in PACKAGES:
        if "/" in subpath:
            namespace = subpath.split("/", 1)[0]
            if namespace not in modules:
                continue
            if modules[namespace].get("type") != "dir":
                raise Rejected("unexpected_namespace_type")
            scope = directory(NPM + "/" + namespace)
            basename = subpath.split("/", 1)[1]
            if basename not in scope:
                continue
            entry = scope[basename]
        else:
            if subpath not in modules:
                continue
            entry = modules[subpath]
        if entry.get("type") != "dir":
            raise Rejected("unexpected_npm_package_type")
        full = NPM + "/" + subpath
        contents = request_api("Fileman", "get_file_content",
                               {"dir": full, "file": "package.json"})
        text = contents.get("content") if isinstance(contents, dict) else None
        if not isinstance(text, str) or len(text) > 80000:
            raise Rejected("npm_package_manifest_unreadable")
        try:
            manifest = json.loads(text)
        except ValueError:
            raise Rejected("npm_package_manifest_invalid") from None
        if not isinstance(manifest, dict) or manifest.get("name") != name:
            raise Rejected("npm_package_identity_mismatch")
        files = search_rows(full, 3500)
        found.append((subpath, full, snapshot(full, files, 0, 90, 1, 3500)))
    return found


def delete_dir_verified(parent, basename):
    full = parent + "/" + basename
    if directory(parent).get(basename, {}).get("type") != "dir":
        raise Rejected("target_disappeared_before_delete")
    request_api("Fileman", "delete_file", {"path": full, "skip_trash": 1})
    if basename in directory(parent):
        raise Rejected("target_deletion_unconfirmed")


def main(apply):
    used_before, limit = quota()
    before = inspect_browsers()
    cron_check = check_crons()
    packages = npm_targets()
    print(json.dumps({
        "stage": "preflight", "ok": True, "apply": apply,
        "browser_mib": {k: round(v["bytes"] / MI_B, 2) for k, v in before.items()},
        "chrome_and_shell_file_counts": {k: v["files"] for k, v in before.items()},
        "cron_check": cron_check,
        "private_linux_puppeteer_packages": None if packages is None else [x[0] for x in packages],
        "quota_before_percent": round(100 * used_before / limit, 2),
        "scope": "only_linux_puppeteer_browser153_and_verified_private_npm_packages"
    }, sort_keys=True), flush=True)
    if not apply:
        return
    # Re-read immediately before modifications. Abort if any subtree changed.
    if inspect_browsers() != before:
        raise Rejected("browser_changed_before_delete")
    check_crons()
    deleted_mib = 0
    for name, parent, basename, *_ in BROWSERS:
        delete_dir_verified(parent, basename)
        deleted_mib += before[name]["bytes"] / MI_B
        print(json.dumps({"stage": "verified_browser_removed", "name": name,
                          "estimated_mib": round(before[name]["bytes"] / MI_B, 2)}), flush=True)
    # Do not delete application source, node_modules wholesale, or any other module.
    if packages is not None:
        for subpath, full, old in packages:
            # Package content/identity must still match the original inspection.
            new_packages = npm_targets()
            current = next((x[2] for x in new_packages or [] if x[0] == subpath), None)
            if current != old:
                raise Rejected("npm_package_changed_before_delete")
            parent, basename = full.rsplit("/", 1)
            delete_dir_verified(parent, basename)
            deleted_mib += old["bytes"] / MI_B
            print(json.dumps({"stage": "verified_linux_npm_package_removed",
                              "package": subpath, "estimated_mib": round(old["bytes"] / MI_B, 2)}),
                  flush=True)
    used_after, after_limit = quota()
    if after_limit != limit:
        raise Rejected("quota_limit_changed")
    print(json.dumps({
        "stage": "complete", "ok": True, "linux_browsers_deleted": len(BROWSERS),
        "linux_npm_packages_deleted": [] if packages is None else [x[0] for x in packages],
        "npm_packages_inventory_available": packages is not None,
        "estimated_removed_mib": round(deleted_mib, 2),
        "quota_before_mib": round(used_before / MI_B, 1),
        "quota_after_mib": round(used_after / MI_B, 1),
        "quota_released_mib": round((used_before - used_after) / MI_B, 1),
        "windows_agent_untouched": True, "php_cron_untouched": True
    }, sort_keys=True), flush=True)


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("--apply", action="store_true")
    try:
        main(parser.parse_args().apply)
    except Rejected as error:
        print(json.dumps({"ok": False, "error": str(error)}), flush=True)
        sys.exit(1)
    except Exception:
        print('{"ok":false,"error":"unexpected_failure"}', flush=True)
        sys.exit(1)
