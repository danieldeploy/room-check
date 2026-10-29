#!/usr/bin/env python3
"""Protected, read-only diagnosis of an unavailable cPanel Git deployment.

No shell access, no repository mutations, no file contents, and no token
or unknown private filenames in output. Uses the existing fixed WHM account.
"""
import json
import os
import posixpath
import re
import subprocess
import sys

from cpanel_api import CpanelAPI, Config, Deployment, WHM_ORIGIN
from cpanel_disk_cleanup import Rejected, request_api

CPANEL_REPOSITORY = "/home/welcome//home/welcome/repositories/room-check"
SUBDIRECTORIES = ("deploy", "cron", "invoice-runner", "src", "admin", "windows", "docs", "tests")
SAFE_CATEGORIES = (
    "__pycache__", "node_modules", ".pytest_cache", ".mypy_cache",
    ".phpunit.cache", ".DS_Store", "Thumbs.db",
)
MAX_ROWS = 2000


def validate_rows(raw):
    if not isinstance(raw, list) or len(raw) > MAX_ROWS:
        raise Rejected("invalid_repository_listing")
    result = {}
    for record in raw:
        if not isinstance(record, dict):
            raise Rejected("invalid_repository_listing")
        name = record.get("file") or record.get("name")
        if not isinstance(name, str) or not name or name in (".", "..") or "/" in name or "\\" in name:
            raise Rejected("invalid_repository_name")
        typ = record.get("type")
        if typ not in ("dir", "file"):
            continue
        if name in result:
            raise Rejected("duplicate_repository_name")
        result[name] = {"type": typ, "size": record.get("size")}
    return result


def tracked_names(directory):
    # The local checkout is the approved public GitHub source. This comparison
    # is a diagnostic hint, not permission to remove any server file.
    proc = subprocess.run(["git", "ls-files", "-z"], check=True,
                          stdout=subprocess.PIPE, stderr=subprocess.DEVNULL)
    paths = proc.stdout.decode("utf8").split("\0")
    prefix = "" if not directory else directory + "/"
    return {path[len(prefix):].split("/", 1)[0] for path in paths
            if path.startswith(prefix) and path != prefix}


def compare(directory, rows):
    items = validate_rows(rows)
    expected = tracked_names(directory)
    extra = sorted(set(items) - expected - ({".git"} if not directory else set()))
    missing = sorted(expected - set(items))
    # Only report category, count and hashed path indicators. An untracked name
    # might contain a personal secret and is never logged literally.
    categories = {}
    for name in extra:
        if name in SAFE_CATEGORIES:
            categories[name] = categories.get(name, 0) + 1
        elif name.endswith(".pyc"):
            categories["python_bytecode"] = categories.get("python_bytecode", 0) + 1
        elif name.endswith((".tmp", ".swp", ".bak")):
            categories["temporary_files"] = categories.get("temporary_files", 0) + 1
        else:
            categories["other_untracked"] = categories.get("other_untracked", 0) + 1
    return {"directory": directory or "repository_root", "entries": len(items),
            "untracked_count": len(extra), "missing_tracked_count": len(missing),
            "categories": categories,
            "cpanel_deployment_config_exists": ".cpanel.yml" in items if not directory else None}


def run():
    cfg = Config(token=os.environ.get("WHM_API_TOKEN", ""), transport="whm", origin=WHM_ORIGIN)
    runner = Deployment(CpanelAPI(cfg), cfg)
    state = runner.status()
    root = posixpath.normpath(CPANEL_REPOSITORY)
    if posixpath.normpath(str(state.get("repository_root"))) != root:
        raise Rejected("repository_binding_mismatch")
    if state.get("branch") != "agent/room-item-assignments":
        raise Rejected("repository_branch_mismatch")
    scans = []
    for subdir in ("",) + SUBDIRECTORIES:
        location = root + ("/" + subdir if subdir else "")
        try:
            rows = request_api("Fileman", "list_files",
                               {"dir": location, "show_hidden": 1})
            scans.append(compare(subdir, rows))
        except Rejected as failure:
            scans.append({"directory": subdir or "repository_root",
                          "error": str(failure)})
    output = {"ok": True, "read_only": True,
              "current_commit": state["current_commit"],
              "last_deployed_commit": state["last_deployed_commit"],
              "deployable": state["deployable"],
              "repository_busy": state["repository_busy"],
              "repository_scans": scans}
    print(json.dumps(output, sort_keys=True))


if __name__ == "__main__":
    try:
        run()
    except Rejected as e:
        print(json.dumps({"ok": False, "error": str(e)}), file=sys.stderr)
        sys.exit(1)
    except Exception:
        print('{"ok":false,"error":"repository_readiness_diagnostic_unavailable"}', file=sys.stderr)
        sys.exit(1)
