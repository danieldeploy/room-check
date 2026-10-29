#!/usr/bin/env python3
"""One-off, reversible repair for the inspected dirty *staging clone*.

Never touches public_html, mail, live databases, .git, or backup archives.
All moved files are preserved under a private account directory. The normal
WHM Git update/deploy gate still applies after this narrow repair.
"""
import argparse
import json
import os
import posixpath
import re
import sys

from automated_release import GitHub
from cpanel_api import CpanelAPI, Config, DEFAULT_REPOSITORY, Deployment, WHM_ORIGIN
from cpanel_disk_cleanup import Rejected, request_api
from cpanel_repo_readiness import SUBDIRECTORIES, tracked_tree_names, validate_rows

OLD_SHA = "ee8c516cb8393aa8b292005d842dca984c03aac0"
ROOT = posixpath.normpath(DEFAULT_REPOSITORY)
HOME = "/home/welcome"
QUARANTINE = HOME + "/room-check-repo-quarantine-20261029"
EXPECTED_EXTRA = {"": 1, "deploy": 4, "docs": 1, "tests": 3}
MAX_FILES = 9
MAX_SIZE = 2 * 1024 * 1024
MAX_ROWS = 2000
FILENAME = re.compile(r"[A-Za-z0-9_.-]{1,120}\Z")
RESTRICTED = ("password", "secret", "token", "credential", "private", ".env", "config", ".git", "key")
# Never move ignored directories, tracked files, symlinks or protected names.
TARGETS = ("",) + SUBDIRECTORIES


def filenames(tree, subdir):
    return tracked_tree_names(tree, subdir)


def safe_name(value):
    if not isinstance(value, str) or not FILENAME.fullmatch(value) or value in (".", ".."):
        raise Rejected("unsafe_repository_name")
    if value.startswith(".") or any(s in value.lower() for s in RESTRICTED):
        raise Rejected("protected_repository_name")
    return value


def files_in(directory):
    rows = request_api("Fileman", "list_files", {"dir": directory, "show_hidden": 1})
    if not isinstance(rows, list) or len(rows) > MAX_ROWS:
        raise Rejected("invalid_repository_listing")
    result = {}
    for row in rows:
        if not isinstance(row, dict):
            raise Rejected("invalid_repository_listing")
        name = row.get("file") or row.get("name")
        if not isinstance(name, str) or not name or name in (".", "..") or "/" in name or "\\" in name:
            raise Rejected("invalid_repository_listing")
        if name in result:
            raise Rejected("duplicate_repository_name")
        result[name] = row
    return result


def file_info(path):
    result = request_api("Fileman", "get_file_information", {"path": path})
    if isinstance(result, list) and len(result) == 1:
        result = result[0]
    if not isinstance(result, dict) or result.get("type") != "file" or result.get("is_symlink"):
        raise Rejected("non_regular_repository_file")
    try:
        size, mtime = int(result["size"]), int(result["mtime"])
    except (ValueError, TypeError, KeyError):
        raise Rejected("unverifiable_repository_file") from None
    if size < 0 or size > MAX_SIZE or mtime <= 0:
        raise Rejected("unverifiable_repository_file")
    if result.get("user") not in (None, "", "welcome"):
        raise Rejected("unexpected_repository_owner")
    return {"size": size, "mtime": mtime}


def plan(server_tree):
    """Read-only: all discrepancies must match the exact inspected baseline."""
    entries = []
    for subdir in TARGETS:
        directory = ROOT + ("/" + subdir if subdir else "")
        items = files_in(directory)
        expected = filenames(server_tree, subdir)
        absent = expected - set(items)
        if absent:
            raise Rejected("tracked_repository_file_missing")
        extra = set(items) - expected - ({".git"} if not subdir else set())
        if len(extra) != EXPECTED_EXTRA.get(subdir, 0):
            raise Rejected("repository_inventory_changed")
        for name in sorted(extra):
            safe_name(name)
            if items[name].get("type") != "file":
                raise Rejected("untracked_directory_or_symlink")
            source = directory + "/" + name
            meta = file_info(source)
            entries.append({"subdir": subdir or "root", "name": name,
                            "source": source, "metadata": meta})
    if len(entries) != MAX_FILES:
        raise Rejected("repository_inventory_changed")
    return entries


def create_private_directory(parent, name):
    if not parent.startswith(HOME + "/") and parent != HOME:
        raise Rejected("quarantine_outside_account")
    if name not in ("room-check-repo-quarantine-20261029", "root", "deploy", "docs", "tests"):
        raise Rejected("quarantine_name_not_allowed")
    result = request_api("Fileman", "mkdir",
                         {"path": parent, "name": name, "permissions": "0700"}, legacy=True)
    data = result.get("data")
    if (not isinstance(data, list) or len(data) != 1 or data[0].get("name") != name
            or str(data[0].get("permissions", "")).lstrip("0") != "700"):
        raise Rejected("quarantine_creation_failed")


def relative(path):
    if not path.startswith(HOME + "/") or any(piece in (".", "..") for piece in path.split("/")):
        raise Rejected("path_outside_welcome_account")
    return path[len(HOME) + 1:]


def move_verified(item):
    source = item["source"]
    target = QUARANTINE + "/" + item["subdir"] + "/" + item["name"]
    if file_info(source) != item["metadata"]:
        raise Rejected("repository_file_changed_before_move")
    if item["name"] in files_in(posixpath.dirname(target)):
        raise Rejected("quarantine_collision")
    response = request_api("Fileman", "fileop", {
        "op": "move", "sourcefiles": relative(source),
        "destfiles": relative(posixpath.dirname(target)), "doubledecode": 0,
    }, legacy=True)
    rows = response.get("data")
    if not isinstance(rows, list) or len(rows) != 1 or rows[0].get("result") not in (1, "1", True):
        raise Rejected("quarantine_move_unconfirmed")
    if item["name"] in files_in(posixpath.dirname(source)):
        raise Rejected("quarantine_source_not_removed")
    if item["name"] not in files_in(posixpath.dirname(target)):
        raise Rejected("quarantine_destination_not_found")
    if file_info(target) != item["metadata"]:
        raise Rejected("quarantine_metadata_changed")


def recover(apply):
    config = Config(token=os.environ.get("WHM_API_TOKEN", ""), transport="whm",
                    origin=WHM_ORIGIN, wait_timeout=600)
    runner = Deployment(CpanelAPI(config), config)
    status = runner.status()
    if status.get("repository_root") != config.repository or status.get("branch") != "agent/room-item-assignments":
        raise Rejected("unexpected_repository_binding")
    if status["repository_busy"]:
        raise Rejected("deployment_busy")
    runner.no_active_deployment()
    if status["deployable"]:
        return {"state": "already_clean", "moved": 0, "write_operations": False}
    if status.get("current_commit") != OLD_SHA or status.get("last_deployed_commit") != OLD_SHA:
        raise Rejected("uninspected_server_commit")
    github = GitHub(os.environ.get("GITHUB_TOKEN", ""))
    expected = os.environ.get("GITHUB_SHA", "")
    github.require_head(expected)
    github.require_fast_forward(OLD_SHA, expected)
    tree = github.read("/git/trees/" + OLD_SHA + "?recursive=1")
    entries = plan(tree)

    # Allow use without mutation for diagnosis. Unknown files are never logged.
    if not apply:
        return {"state": "planned", "moved": 0, "write_operations": False,
                "regular_untracked": len(entries),
                "sections": {d or "root": n for d, n in EXPECTED_EXTRA.items()}}

    if QUARANTINE.rsplit("/", 1)[1] in files_in(HOME):
        raise Rejected("quarantine_already_exists_inspect_server")
    # The parent is outside the deployment clone and web root; mode 0700.
    create_private_directory(HOME, posixpath.basename(QUARANTINE))
    for subdir in ("root", "deploy", "docs", "tests"):
        create_private_directory(QUARANTINE, subdir)
    moved = 0
    for item in entries:
        move_verified(item)
        moved += 1
    after = runner.status()
    if not after["deployable"] or after["current_commit"] != OLD_SHA or after["repository_busy"]:
        raise Rejected("clone_still_not_deployable_after_quarantine")
    return {"state": "clean_and_verified", "moved": moved, "write_operations": True,
            "repository": "unchanged_head", "quarantine": "private_preserved"}


def main(argv=None):
    parser = argparse.ArgumentParser()
    parser.add_argument("action", choices=("inspect", "apply"))
    args = parser.parse_args(argv)
    try:
        outcome = recover(args.action == "apply")
        print(json.dumps({"ok": True, **outcome}, sort_keys=True))
        return 0
    except Rejected as error:
        print(json.dumps({"ok": False, "error": str(error),
                          "recovery": "inspect_server_before_retry"}), file=sys.stderr)
        return 1
    except Exception:
        print('{"ok":false,"error":"repository_recovery_unavailable","recovery":"inspect_server_before_retry"}',
              file=sys.stderr)
        return 1


if __name__ == "__main__":
    sys.exit(main())
