#!/usr/bin/env python3
"""Reconcile exactly the inspected dirty cPanel *staging clone*, not live PHP.

Preserve all changed tracked files and extra regular files in a separate private
quarantine, then restore only the two original public Git blobs. No git reset,
mail, public_html, database, deployment artifact, or .git mutation.
"""
import argparse
import base64
import hashlib
import json
import os
import posixpath
import re
import sys
import urllib.parse

from automated_release import GitHub
from cpanel_api import CpanelAPI, Config, Deployment, WHM_ORIGIN
from cpanel_disk_cleanup import Rejected, request_api
from cpanel_git_worktree_recovery import files_in, file_info, relative, safe_name
from cpanel_tracked_integrity_probe import inspect as integrity_probe

OLD = "ee8c516cb8393aa8b292005d842dca984c03aac0"
BRANCH = "agent/room-item-assignments"
HOME = "/home/welcome"
ROOT = posixpath.normpath(Config(token="x", transport="whm", origin=WHM_ORIGIN).repository)
QUAR = HOME + "/room-check-repo-tracked-rescue-20261030"
TRACKED = (".github/workflows/ci.yml", "docs/PROJECT_STATE.md")
AREAS = (".github", ".github/workflows")
FOLDERS = ("tracked-workflows", "tracked-docs", "extra-github", "extra-workflows")
MAX_TRACKED = 60000
MAX_EXTRA = 100000
MAX_EXTRAS = 2


def require(cond, code):
    if not cond:
        raise Rejected(code)


def private_directory(parent, name):
    require(parent == HOME or parent == QUAR, "directory_out_of_scope")
    require(name == posixpath.basename(QUAR) or name in FOLDERS, "directory_not_allowed")
    response = request_api("Fileman", "mkdir",
                           {"path": parent, "name": name, "permissions": "0700"},
                           legacy=True)
    data = response.get("data")
    require(isinstance(data, list) and len(data) == 1
            and data[0].get("name") == name
            and str(data[0].get("permissions", "")).lstrip("0") == "700",
            "private_directory_not_verified")


def read_text(path):
    require(path.startswith(ROOT + "/") or path.startswith(QUAR + "/"),
            "read_out_of_scope")
    parent, name = posixpath.split(path)
    result = request_api("Fileman", "get_file_content",
                         {"dir": parent, "file": name, "to_charset": "utf-8"})
    require(isinstance(result, dict) and isinstance(result.get("content"), str),
            "file_content_unavailable")
    content = result["content"].encode("utf-8")
    require(len(content) <= MAX_TRACKED, "file_too_large")
    return content


def expected_bytes(github, tree, path):
    require(path in TRACKED, "unapproved_tracked_path")
    entries = tree.get("tree")
    require(tree.get("truncated") is False and isinstance(entries, list),
            "untrusted_git_tree")
    files = [e for e in entries if isinstance(e, dict) and e.get("path") == path
             and e.get("type") == "blob"]
    require(len(files) == 1, "tracked_path_not_in_git")
    info = github.read("/contents/" + urllib.parse.quote(path, safe="/") + "?ref=" + OLD)
    require(info.get("encoding") == "base64"
            and isinstance(info.get("content"), str)
            and info.get("sha") == files[0].get("sha"),
            "untrusted_github_blob")
    try:
        data = base64.b64decode(info["content"], validate=False)
        data.decode("utf-8")
    except (ValueError, UnicodeError):
        raise Rejected("non_utf8_tracked_source") from None
    require(0 < len(data) <= MAX_TRACKED and len(data) == files[0].get("size"),
            "trusted_blob_size_mismatch")
    digest = hashlib.sha1(b"blob " + str(len(data)).encode("ascii") + b"\x00" + data).hexdigest()
    require(digest == files[0]["sha"], "trusted_blob_checksum_mismatch")
    return data


def extra_entries(tree):
    require(tree.get("truncated") is False and isinstance(tree.get("tree"), list),
            "untrusted_git_tree")
    found = []
    for area in AREAS:
        prefix = area + "/"
        expected = {e["path"][len(prefix):].split("/", 1)[0] for e in tree["tree"]
                    if isinstance(e, dict) and isinstance(e.get("path"), str)
                    and e["path"].startswith(prefix)}
        observed = files_in(ROOT + "/" + area)
        require(expected <= set(observed), "tracked_items_missing")
        for name in sorted(set(observed) - expected):
            safe_name(name)
            row = observed[name]
            require(row.get("type") == "file" and not row.get("is_symlink"),
                    "unexpected_git_file_type")
            source = ROOT + "/" + area + "/" + name
            meta = file_info(source)
            require(0 < meta["size"] <= MAX_EXTRA, "unexpected_git_file_size")
            found.append((area, source, meta))
    require(len(found) == MAX_EXTRAS, "unexpected_extra_git_files")
    return found


def safe_preflight():
    cfg = Config(token=os.environ.get("WHM_API_TOKEN", ""),
                 transport="whm", origin=WHM_ORIGIN)
    runner = Deployment(CpanelAPI(cfg), cfg)
    state = runner.status()
    require(state.get("repository_root") == cfg.repository
            and state.get("branch") == BRANCH and not state.get("repository_busy"),
            "repository_binding_or_activity_changed")
    runner.no_active_deployment()
    if state.get("deployable"):
        return {"state": "already_clean", "write_operations": False}
    require(state.get("current_commit") == OLD
            and state.get("last_deployed_commit") == OLD,
            "installed_commit_changed")

    # The previous protected diagnostic found exactly two different-sized
    # tracked files and two extra regular .github files. Reject any new drift.
    check = integrity_probe()
    require(check.get("installed_commit") == OLD and check.get("tracked_missing_by_area") == {}
            and check.get("size_unknown") == 0
            and check.get("size_mismatch_by_area") == {".github": 1, "docs": 1}
            and check.get("untracked_regular_by_area") == {".github": 2}
            and not any(check.get("git_lock_presence", {}).values()),
            "uninspected_clone_change")

    github = GitHub(os.environ.get("GITHUB_TOKEN", ""))
    target = os.environ.get("GITHUB_SHA", "")
    github.require_head(target)
    github.require_fast_forward(OLD, target)
    tree = github.read("/git/trees/" + OLD + "?recursive=1")
    extra = extra_entries(tree)
    original = {}
    desired = {}
    for path in TRACKED:
        source = ROOT + "/" + path
        info = file_info(source)
        data = read_text(source)
        require(info["size"] == len(data), "tracked_metadata_changed")
        trusted = expected_bytes(github, tree, path)
        require(data != trusted and len(data) != len(trusted),
                "expected_tracked_difference_absent")
        original[path] = (info, hashlib.sha256(data).hexdigest())
        desired[path] = trusted
    require(posixpath.basename(QUAR) not in files_in(HOME),
            "previous_quarantine_requires_reconciliation")
    return {"state": "ready", "write_operations": False,
            "runner": runner, "original": original, "desired": desired,
            "extra": extra}


def verify_copy(src, dst, expected_hash):
    before = file_info(src)
    require(posixpath.basename(dst) not in files_in(posixpath.dirname(dst)),
            "quarantine_name_collision")
    request_api("Fileman", "copy_file",
                {"source": relative(src), "destination": relative(dst)})
    require(file_info(dst)["size"] == before["size"]
            and hashlib.sha256(read_text(dst)).hexdigest() == expected_hash,
            "backup_copy_unverified")
    require(file_info(src) == before, "original_changed_while_copying")


def apply(plan):
    runner = plan["runner"]
    private_directory(HOME, posixpath.basename(QUAR))
    for name in FOLDERS:
        private_directory(QUAR, name)
    for path in TRACKED:
        source = ROOT + "/" + path
        folder = "tracked-workflows" if path.startswith(".github/") else "tracked-docs"
        destination = QUAR + "/" + folder + "/" + posixpath.basename(path)
        original_meta, original_digest = plan["original"][path]
        require(file_info(source) == original_meta
                and hashlib.sha256(read_text(source)).hexdigest() == original_digest,
                "tracked_file_changed")
        verify_copy(source, destination, original_digest)

    # Preserve every unexpected regular file; never delete anything, including
    # the old private quarantine from the first recovery.
    for area, source, meta in plan["extra"]:
        target_folder = "extra-workflows" if area.endswith("/workflows") else "extra-github"
        target = QUAR + "/" + target_folder + "/" + posixpath.basename(source)
        require(file_info(source) == meta
                and posixpath.basename(target) not in files_in(posixpath.dirname(target)),
                "extra_file_changed")
        request_api("Fileman", "move_file",
                    {"source": relative(source), "destination": relative(target)})
        require(posixpath.basename(source) not in files_in(posixpath.dirname(source))
                and file_info(target)["size"] == meta["size"],
                "extra_move_unverified")

    for path in TRACKED:
        source = ROOT + "/" + path
        original_meta, original_digest = plan["original"][path]
        require(file_info(source) == original_meta
                and hashlib.sha256(read_text(source)).hexdigest() == original_digest,
                "tracked_file_changed_before_restore")
        parent, name = posixpath.split(source)
        trusted = plan["desired"][path]
        # cPanel UAPI Fileman::save_file_content writes UTF-8 text.
        # form=True uses POST so source content is not in HTTP URLs/access logs.
        request_api("Fileman", "save_file_content",
                    {"dir": parent, "file": name, "content": trusted.decode("utf-8"),
                     "from_charset": "utf-8", "to_charset": "utf-8"}, form=True)
        require(hashlib.sha256(read_text(source)).digest()
                == hashlib.sha256(trusted).digest()
                and file_info(source)["size"] == len(trusted),
                "restored_tracked_file_not_verified")

    status = runner.status()
    require(status.get("deployable") is True
            and not status.get("repository_busy")
            and status.get("current_commit") == OLD,
            "clone_still_not_deployable")
    return {"state": "clone_clean_and_verified", "files_preserved": 4,
            "tracked_restored": 2, "extra_moved": 2,
            "quarantine": "private", "write_operations": True}


def main(argv=None):
    parser = argparse.ArgumentParser()
    parser.add_argument("action", choices=("inspect", "apply"))
    args = parser.parse_args(argv)
    try:
        plan = safe_preflight()
        if plan["state"] == "already_clean":
            result = plan
        elif args.action == "inspect":
            result = {"state": "ready_to_repair", "write_operations": False,
                      "tracked_to_restore": 2, "extra_to_preserve": 2}
        else:
            result = apply(plan)
        print(json.dumps({"ok": True, **result}, sort_keys=True))
        return 0
    except Rejected as exc:
        print(json.dumps({"ok": False, "error": str(exc),
                          "retry": "read_only_reconciliation_before_mutation"}))
    except Exception:
        print('{"ok":false,"error":"staging_repair_unavailable","retry":"read_only_reconciliation_before_mutation"}')
    return 1


if __name__ == "__main__":
    sys.exit(main())
