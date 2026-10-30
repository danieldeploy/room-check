#!/usr/bin/env python3
"""Read-only exact scope for the final blocked cPanel Git working tree.
Never print unknown server filenames, their contents, or credentials.
"""
import json
import os
import posixpath
import re
import sys
from automated_release import GitHub
from cpanel_api import CpanelAPI, Config, Deployment, WHM_ORIGIN
from cpanel_disk_cleanup import request_api, Rejected

EXPECTED_SHA = "ee8c516cb8393aa8b292005d842dca984c03aac0"
MODIFIED = (".github/workflows/ci.yml", "docs/PROJECT_STATE.md")
AREAS = (".github", ".github/workflows")
ROOT = posixpath.normpath(Config(token="x", transport="whm", origin=WHM_ORIGIN).repository)
BASENAME = re.compile(r"[A-Za-z0-9_.-]{1,160}\Z")

def listing(directory):
    rows = request_api("Fileman", "list_files", {"dir": ROOT + "/" + directory, "show_hidden": 1})
    if not isinstance(rows, list) or len(rows) > 1500:
        raise Rejected("unexpected_git_listing")
    result = {}
    for entry in rows:
        if not isinstance(entry, dict):
            raise Rejected("unexpected_git_listing")
        name = entry.get("file") or entry.get("name")
        if not isinstance(name, str) or not BASENAME.fullmatch(name) or name in (".", "..") or name in result:
            raise Rejected("invalid_git_filename")
        result[name] = entry
    return result

def check(tree, area, rows):
    paths = tree["tree"]
    prefix = area + "/"
    expected = {obj["path"][len(prefix):].split("/", 1)[0] for obj in paths
                if isinstance(obj, dict) and isinstance(obj.get("path"), str)
                and obj["path"].startswith(prefix)}
    extra = set(rows) - expected
    missing = expected - set(rows)
    if missing:
        raise Rejected("tracked_repository_items_missing")
    safe_extra = 0
    other_extra = 0
    for name in extra:
        obj = rows[name]
        if (obj.get("type") == "file" and not obj.get("is_symlink")
                and name.endswith((".yml", ".yaml"))
                and not name.startswith(".")
                and 0 < int(obj.get("size") or -1) <= 500000):
            safe_extra += 1
        else:
            other_extra += 1
    return {"extra": len(extra), "small_regular_yaml": safe_extra, "other": other_extra}

def run():
    config = Config(token=os.environ.get("WHM_API_TOKEN", ""), transport="whm", origin=WHM_ORIGIN)
    status = Deployment(CpanelAPI(config), config).status()
    if status.get("current_commit") != EXPECTED_SHA or status.get("repository_busy"):
        raise Rejected("server_commit_or_activity_changed")
    tree = GitHub(os.environ.get("GITHUB_TOKEN", "")).read("/git/trees/" + EXPECTED_SHA + "?recursive=1")
    if tree.get("truncated") is not False or not isinstance(tree.get("tree"), list):
        raise Rejected("invalid_expected_tree")
    blobs = {obj["path"]: obj for obj in tree["tree"] if isinstance(obj, dict) and obj.get("type") == "blob"}
    changes = {}
    for path in MODIFIED:
        expected = blobs.get(path)
        if not isinstance(expected, dict) or not isinstance(expected.get("size"), int):
            raise Rejected("expected_file_missing")
        info = request_api("Fileman", "get_file_information", {"path": posixpath.relpath(ROOT + "/" + path, "/home/welcome")})
        if isinstance(info, list) and len(info) == 1:
            info = info[0]
        if not isinstance(info, dict) or info.get("type") != "file" or info.get("is_symlink"):
            raise Rejected("actual_file_not_regular")
        actual = int(info.get("size"))
        changes[path] = {"expected_bytes": expected["size"], "actual_bytes": actual,
                         "different_size": actual != expected["size"]}
    areas = {area: check(tree, area, listing(area)) for area in AREAS}
    return {"ok": True, "read_only": True, "installed_sha": EXPECTED_SHA,
            "deployable": status.get("deployable"),
            "tracked": changes, "untracked": areas,
            "changes_authorized": False}

if __name__ == "__main__":
    try:
        print(json.dumps(run(), sort_keys=True))
    except Rejected as error:
        print(json.dumps({"ok": False, "error": str(error)}), file=sys.stderr)
        sys.exit(1)
    except Exception:
        print('{"ok":false,"error":"git_final_diagnostic_unavailable"}', file=sys.stderr)
        sys.exit(1)
