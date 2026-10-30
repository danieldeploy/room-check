#!/usr/bin/env python3
"""Read-only diagnosis of the cPanel staging clone after a verified quarantine.

Uses installed commit Git metadata and strictly bounded WHM Fileman searches.
Reports only aggregate counts, never private names or content. Never calls a
filesystem write or an arbitrary command on cPanel.
"""
import json
import os
import posixpath
import re
import sys

from automated_release import GitHub
from cpanel_api import CpanelAPI, Config, DEFAULT_REPOSITORY, Deployment, WHM_ORIGIN
from cpanel_disk_cleanup import Rejected, request_api

INSTALLED_SHA = "ee8c516cb8393aa8b292005d842dca984c03aac0"
ROOT = posixpath.normpath(DEFAULT_REPOSITORY)
SECTIONS = ("deploy", "docs", "tests", "src", "admin",
            "invoice-runner", "cron", "windows", "assets",
            "migrations", ".github", "tools")
MAX_RESULTS = 1700
MAX_TRACKED = 1000
MAX_PATH = 320


def paths_from_tree(tree):
    if not isinstance(tree, dict) or tree.get("truncated") is not False:
        raise Rejected("git_tree_unverifiable")
    rows = tree.get("tree")
    if not isinstance(rows, list) or len(rows) > 1800:
        raise Rejected("git_tree_unverifiable")
    files = {}
    for row in rows:
        if not isinstance(row, dict):
            raise Rejected("git_tree_unverifiable")
        path, kind, size = row.get("path"), row.get("type"), row.get("size")
        if kind != "blob":
            continue
        if (not isinstance(path, str) or len(path) > MAX_PATH or
            path.startswith("/") or ".." in path.split("/") or
            not isinstance(size, int) or size < 0):
            raise Rejected("git_tree_unverifiable")
        files[path] = size
    if len(files) < 100 or len(files) > MAX_TRACKED:
        raise Rejected("git_tree_unverifiable")
    return files


def scan_response(section, records):
    if not isinstance(section, str) or section not in SECTIONS:
        raise Rejected("scan_section_not_allowed")
    if not isinstance(records, list) or len(records) > MAX_RESULTS:
        raise Rejected("scan_response_unverifiable")
    prefix = ROOT + "/" + section
    output = {}
    for row in records:
        if not isinstance(row, dict):
            raise Rejected("scan_response_unverifiable")
        path = row.get("file")
        kind = row.get("type")
        if not isinstance(path, str) or len(path) > MAX_PATH + len(ROOT):
            raise Rejected("scan_response_unverifiable")
        full = posixpath.normpath(path)
        if full != prefix and not full.startswith(prefix + "/"):
            raise Rejected("scan_outside_clone")
        if full == prefix or kind == "dir":
            continue
        if kind not in ("file", "link"):
            raise Rejected("scan_unsupported_file_type")
        rel = full[len(ROOT) + 1:]
        if rel in output:
            raise Rejected("scan_duplicate_path")
        try:
            size = int(row["size"])
        except (ValueError, KeyError, TypeError):
            raise Rejected("scan_size_unverifiable") from None
        if size < 0:
            raise Rejected("scan_size_unverifiable")
        output[rel] = {"size": size, "kind": kind}
    return output


def compare_section(section, installed, observed):
    expected = {p: n for p, n in installed.items() if p.startswith(section + "/")}
    absent = set(expected) - set(observed)
    extra = set(observed) - set(expected)
    size_changed = sum(
        1 for name, n in expected.items()
        if name in observed and observed[name]["size"] != n
    )
    links = sum(1 for item in observed.values() if item["kind"] == "link")
    return {"section": section, "tracked": len(expected), "seen": len(observed),
            "missing": len(absent), "untracked": len(extra),
            "different_size": size_changed, "links": links}


def execute():
    cfg = Config(token=os.environ.get("WHM_API_TOKEN", ""), origin=WHM_ORIGIN,
                 transport="whm", wait_timeout=600)
    runner = Deployment(CpanelAPI(cfg), cfg)
    state = runner.status()
    if (posixpath.normpath(str(state.get("repository_root"))) != ROOT or
        state.get("branch") != "agent/room-item-assignments" or
        state.get("current_commit") != INSTALLED_SHA or
        state.get("repository_busy")):
        raise Rejected("installed_clone_changed")
    # GitHub read only, fixed installed commit, not branch HEAD.
    tree = GitHub(os.environ.get("GITHUB_TOKEN", "")).read(
        "/git/trees/" + INSTALLED_SHA + "?recursive=1")
    installed = paths_from_tree(tree)
    results = []
    for section in SECTIONS:
        data = request_api("Fileman", "search", {
            "dir": ROOT + "/" + section, "recursive": 1,
            "mimeinfo": 0, "attributes": "size|type",
        }, legacy=True)
        # Never emit any file name, even from an unexpected server response.
        if not isinstance(data, dict):
            raise Rejected("scan_response_unverifiable")
        rows = data.get("data")
        observed = scan_response(section, rows)
        results.append(compare_section(section, installed, observed))
    summary = {key: sum(row[key] for row in results) for key in
               ("tracked", "seen", "missing", "untracked", "different_size", "links")}
    print(json.dumps({
        "ok": True, "read_only": True, "installed_commit": INSTALLED_SHA,
        "deployable": state["deployable"], "sections": results,
        "totals": summary, "root_files_not_verified": True,
        "content_hashes_not_verified": True,
    }, sort_keys=True))


if __name__ == "__main__":
    try:
        execute()
    except Rejected as err:
        print(json.dumps({"ok": False, "read_only": True, "error": str(err)}), file=sys.stderr)
        sys.exit(1)
    except Exception:
        print('{"ok":false,"read_only":true,"error":"deep_scan_unavailable"}', file=sys.stderr)
        sys.exit(1)
