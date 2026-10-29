#!/usr/bin/env python3
"""One-time, reversible cPanel Git worktree recovery for the inspected clone.

Only objects untracked by the actual installed Git commit are moved, never
deleted. All original bytes remain under a private recovery directory outside
the repository and public_html. No names, contents, secrets or raw server
responses are printed. The narrow installed-SHA and count gate prevents this
from becoming a general-purpose file mover on future deployments.
"""
import argparse
import json
import os
import posixpath
import re
import sys

from automated_release import GitHub
from cpanel_api import CpanelAPI, Config, Deployment, WHM_ORIGIN
from cpanel_disk_cleanup import Rejected, request_api
from cpanel_repo_readiness import (
    CPANEL_REPOSITORY, SUBDIRECTORIES, tracked_tree_names, validate_rows
)

INSTALLED_SHA = "ee8c516cb8393aa8b292005d842dca984c03aac0"
EXPECTED = {"": 1, "deploy": 4, "docs": 1, "tests": 3}
BACKUP_PARENT = "/home/welcome/room-check-backups"
BACKUP_NAME = "git-clone-recovery-" + INSTALLED_SHA[:12]
BACKUP_PATH = BACKUP_PARENT + "/" + BACKUP_NAME
ROOT = posixpath.normpath(CPANEL_REPOSITORY)
SAFE_BASENAME = re.compile(r"[A-Za-z0-9_.-]{1,150}\Z")
SAFE_FLAGS = ("islink", "is_symlink", "symlink")


def safe_name(value):
    if not isinstance(value, str) or not SAFE_BASENAME.fullmatch(value) or value in (".", "..", ".git"):
        raise Rejected("unsafe_repository_entry")
    return value


def listing(directory):
    raw = request_api("Fileman", "list_files", {"dir": directory, "show_hidden": 1})
    return validate_rows(raw)


def private_info(path):
    data = request_api("Fileman", "get_file_information", {"path": path})
    if isinstance(data, list) and len(data) == 1:
        data = data[0]
    if not isinstance(data, dict) or data.get("type") != "file":
        raise Rejected("untracked_not_regular_file")
    if any(data.get(flag) not in (False, 0, "0", None, "") for flag in SAFE_FLAGS):
        raise Rejected("symbolic_link_not_allowed")
    try:
        size, mtime = int(data["size"]), int(data["mtime"])
    except (KeyError, ValueError, TypeError):
        raise Rejected("invalid_file_metadata") from None
    if size < 0 or size > 4 * 1024 * 1024 or mtime <= 0:
        raise Rejected("untracked_file_limits")
    return {"size": size, "mtime": mtime}


def verified_state():
    config = Config(token=os.environ.get("WHM_API_TOKEN", ""), origin=WHM_ORIGIN, transport="whm")
    runner = Deployment(CpanelAPI(config), config)
    state = runner.status()
    if posixpath.normpath(str(state.get("repository_root"))) != ROOT or state.get("branch") != "agent/room-item-assignments":
        raise Rejected("repository_binding_mismatch")
    if state.get("repository_busy") or any(
        task.get("state") not in ("succeeded", "failed", "canceled") for task in state.get("deployments", [])
    ):
        raise Rejected("repository_busy_or_uncertain")
    if state["deployable"]:
        return state, None
    if (state.get("current_commit") != INSTALLED_SHA
        or state.get("last_deployed_commit") != INSTALLED_SHA):
        raise Rejected("inspected_clone_version_changed")
    tree = GitHub(os.environ.get("GITHUB_TOKEN", "")).read(
        "/git/trees/" + INSTALLED_SHA + "?recursive=1")
    if not isinstance(tree, dict) or tree.get("truncated") is not False:
        raise Rejected("invalid_installed_git_tree")
    return state, tree


def build_plan(tree, directory_rows):
    """Return a fixed, small set of regular, untracked files; never tracked paths."""
    if set(directory_rows) != set(("",) + SUBDIRECTORIES):
        raise Rejected("incomplete_repository_inventory")
    plan = []
    for directory, rows in directory_rows.items():
        observed = validate_rows(rows)
        expected_names = tracked_tree_names(tree, directory)
        if directory == "":
            if observed.get(".git", {}).get("type") != "dir" or observed.get(".cpanel.yml", {}).get("type") != "file":
                raise Rejected("git_or_deploy_config_missing")
        missing = expected_names - set(observed)
        if missing:
            raise Rejected("missing_tracked_path")
        untracked = sorted(set(observed) - expected_names - ({".git"} if not directory else set()))
        if len(untracked) != EXPECTED.get(directory, 0):
            raise Rejected("unexpected_untracked_count")
        for name in untracked:
            safe_name(name)
            if observed[name]["type"] != "file":
                raise Rejected("untracked_directory_needs_review")
            filename = (directory + "-" if directory else "root-") + name
            safe_name(filename)
            plan.append((directory, name, filename))
    if len(plan) != sum(EXPECTED.values()) or len({p[2] for p in plan}) != len(plan):
        raise Rejected("invalid_quarantine_plan")
    return plan


def take_inventory():
    return {directory: list(
        request_api("Fileman", "list_files", {"dir": ROOT + ("/" + directory if directory else ""),
            "show_hidden": 1})) for directory in ("",) + SUBDIRECTORIES}


def home_relative(path):
    prefix = "/home/welcome/"
    if not path.startswith(prefix) or path.startswith("/home/welcome/public_html/"):
        raise Rejected("move_path_outside_allowlist")
    return path[len(prefix):]


def make_private_directory():
    original = listing(BACKUP_PARENT)
    if BACKUP_NAME in original:
        raise Rejected("existing_recovery_requires_inspection")
    response = request_api("Fileman", "mkdir", {
        "path": BACKUP_PARENT, "name": BACKUP_NAME, "permissions": "0700"
    }, legacy=True)
    if not isinstance(response, dict) or response.get("event", {}).get("result") != 1:
        raise Rejected("recovery_directory_creation_unverified")
    actual = listing(BACKUP_PARENT)
    if actual.get(BACKUP_NAME, {}).get("type") != "dir":
        raise Rejected("recovery_directory_creation_unverified")
    if listing(BACKUP_PATH):
        raise Rejected("recovery_directory_not_empty")


def move_verified(plan):
    # A complete preflight must pass before any operation modifies the clone.
    expected_meta = {}
    for directory, name, target in plan:
        src = ROOT + ("/" + directory if directory else "") + "/" + name
        expected_meta[(directory, name)] = private_info(src)
    if sum(info["size"] for info in expected_meta.values()) > 16 * 1024 * 1024:
        raise Rejected("total_quarantine_size_limit")
    make_private_directory()
    moved = 0
    for directory, name, target in plan:
        from_path = ROOT + ("/" + directory if directory else "") + "/" + name
        to_path = BACKUP_PATH + "/" + target
        info = private_info(from_path)
        if info != expected_meta[(directory, name)]:
            raise Rejected("untracked_file_changed_before_move")
        if target in listing(BACKUP_PATH):
            raise Rejected("recovery_target_conflict")
        # UAPI Fileman::move_file: source and destination are home-relative.
        request_api("Fileman", "move_file", {
            "source": home_relative(from_path),
            "destination": home_relative(to_path)
        })
        if name in listing(ROOT + ("/" + directory if directory else "")):
            raise Rejected("move_source_still_present")
        if listing(BACKUP_PATH).get(target, {}).get("type") != "file":
            raise Rejected("move_destination_unverified")
        if private_info(to_path) != info:
            raise Rejected("move_metadata_mismatch")
        moved += 1
    return moved


def run(mode):
    state, tree = verified_state()
    if state["deployable"]:
        print(json.dumps({"ok": True, "state": "already_clean", "mutated": False}))
        return
    if tree is None:
        raise Rejected("installed_tree_unavailable")
    plan = build_plan(tree, take_inventory())
    # Inspections reveal only a safe aggregate. Never names or file contents.
    if mode == "inspect":
        print(json.dumps({"ok": True, "state": "quarantine_candidate", "mutated": False,
            "count": len(plan), "installed_commit": INSTALLED_SHA}))
        return
    moved = move_verified(plan)
    after = verified_state()[0]
    if not after["deployable"]:
        raise Rejected("clone_still_not_deployable_after_quarantine")
    print(json.dumps({"ok": True, "state": "verified_clean", "mutated": True,
        "preserved_private_files": moved, "installed_commit": INSTALLED_SHA}))


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("mode", choices=("inspect", "quarantine"))
    args = parser.parse_args()
    try:
        run(args.mode)
    except Rejected as error:
        print(json.dumps({"ok": False, "state": "not_recovered", "error": str(error)}), file=sys.stderr)
        sys.exit(1)
    except Exception:
        print('{"ok":false,"state":"unknown_inspect_before_retry","error":"recovery_unavailable"}', file=sys.stderr)
        sys.exit(1)


if __name__ == "__main__":
    main()
