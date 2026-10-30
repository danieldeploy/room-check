#!/usr/bin/env python3
"""Read-only, bounded cPanel clone-dirty diagnosis against the *installed* SHA.

Never requests file contents, SQL, credentials, Git index contents or secrets.
Outputs only counts, safe directory category and SHA256 indicators of public
tracked paths; these are diagnostic hints, not authorization to overwrite.
"""
import hashlib
import json
import os
import posixpath
import re
import sys
from collections import Counter

from automated_release import GitHub
from cpanel_api import CpanelAPI, Config, Deployment, WHM_ORIGIN
from cpanel_disk_cleanup import Rejected, request_api

OLD_SHA = "ee8c516cb8393aa8b292005d842dca984c03aac0"
MAX_DIRS = 120
MAX_FILES = 1200
MAX_DEPTH = 10


def index_tree(tree):
    if not isinstance(tree, dict) or tree.get("truncated") is not False or not isinstance(tree.get("tree"), list):
        raise Rejected("invalid_installed_git_tree")
    expected = {}
    for obj in tree["tree"]:
        if not isinstance(obj, dict) or obj.get("type") not in ("blob", "tree"):
            raise Rejected("invalid_installed_git_tree")
        name = obj.get("path")
        if not isinstance(name, str) or name.startswith("/") or "\\x00" in name or any(s in (".", "..") for s in name.split("/")):
            raise Rejected("invalid_installed_git_tree")
        if name.startswith(".git/") or name == ".git":
            raise Rejected("unexpected_git_internal_tree")
        if obj["type"] != "blob":
            continue
        size = obj.get("size")
        if not isinstance(size, int) or size < 0:
            raise Rejected("invalid_installed_file_size")
        expected[name] = size
    if not expected or len(expected) > MAX_FILES:
        raise Rejected("installed_tree_outside_bounds")
    dirs = {posixpath.dirname(path) for path in expected}
    if len(dirs) > MAX_DIRS or any(len(d.split("/")) > MAX_DEPTH for d in dirs):
        raise Rejected("installed_tree_outside_bounds")
    return expected, sorted(dirs)


def read_listing(root, directory):
    full = root + ("/" + directory if directory else "")
    rows = request_api("Fileman", "list_files", {"dir": full, "show_hidden": 1})
    if not isinstance(rows, list) or len(rows) > 2000:
        raise Rejected("invalid_repository_listing")
    items = {}
    for obj in rows:
        if not isinstance(obj, dict):
            raise Rejected("invalid_repository_listing")
        name = obj.get("file") or obj.get("name")
        if not isinstance(name, str) or not name or name in (".", "..") or "/" in name or "\\" in name or name in items:
            raise Rejected("invalid_repository_listing")
        if obj.get("type") not in ("file", "dir"):
            raise Rejected("unknown_repository_entry_type")
        items[name] = obj
    return items


def compare(expected, listings):
    matched, size_mismatch, size_unavailable = 0, Counter(), 0
    missing, extra = Counter(), Counter()
    identifiers = []
    for path, length in expected.items():
        directory = posixpath.dirname(path)
        name = posixpath.basename(path)
        rootdir = path.split("/", 1)[0] if "/" in path else "root"
        item = listings[directory].get(name)
        if not item or item.get("type") != "file":
            missing[rootdir] += 1
            continue
        matched += 1
        try:
            server_length = int(item["size"])
        except (KeyError, TypeError, ValueError):
            size_unavailable += 1
            continue
        if server_length < 0:
            size_unavailable += 1
        elif server_length != length:
            size_mismatch[rootdir] += 1
            identifiers.append(hashlib.sha256(path.encode("utf8")).hexdigest()[:12])
    for directory, items in listings.items():
        expected_names = {posixpath.basename(name) for name in expected if posixpath.dirname(name) == directory}
        # Directory names are tracked in tree nodes; extra files only count here.
        for name, obj in items.items():
            if obj.get("type") != "file" or name in expected_names or name == ".git":
                continue
            rootdir = directory.split("/", 1)[0] if directory else "root"
            extra[rootdir] += 1
    return {
        "tracked_examined": len(expected), "tracked_present": matched,
        "size_mismatch_by_area": dict(size_mismatch),
        "size_unknown": size_unavailable,
        "tracked_missing_by_area": dict(missing),
        "untracked_regular_by_area": dict(extra),
        "size_mismatch_path_indicators": sorted(identifiers)[:40],
        "inspection_only": True,
    }


def inspect():
    config = Config(token=os.environ.get("WHM_API_TOKEN", ""), transport="whm", origin=WHM_ORIGIN)
    runner = Deployment(CpanelAPI(config), config)
    current = runner.status()
    if current.get("repository_root") != config.repository or current.get("branch") != "agent/room-item-assignments":
        raise Rejected("unexpected_repository")
    if current.get("current_commit") != OLD_SHA:
        raise Rejected("installed_commit_changed")
    github = GitHub(os.environ.get("GITHUB_TOKEN", ""))
    tree = github.read("/git/trees/" + OLD_SHA + "?recursive=1")
    expected, dirs = index_tree(tree)
    root = posixpath.normpath(config.repository)
    listings = {directory: read_listing(root, directory) for directory in dirs}
    findings = compare(expected, listings)
    # Only filename presence, not .git data; a stale lock is a possible
    # explanation for Git status being unavailable after all tracked files match.
    git_items = read_listing(root, ".git")
    findings["git_lock_presence"] = {
        name: name in git_items for name in ("index.lock", "packed-refs.lock", "HEAD.lock", "shallow.lock")
    }
    findings.update({"ok": True, "installed_commit": OLD_SHA, "deployable": current.get("deployable")})
    return findings


def main():
    try:
        print(json.dumps(inspect(), sort_keys=True))
        return 0
    except Rejected as exc:
        print(json.dumps({"ok": False, "error": str(exc), "inspection_only": True}), file=sys.stderr)
        return 1
    except Exception:
        print('{"ok":false,"error":"tracked_diagnostic_unavailable","inspection_only":true}', file=sys.stderr)
        return 1


if __name__ == "__main__":
    sys.exit(main())
