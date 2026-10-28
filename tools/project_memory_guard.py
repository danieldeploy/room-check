#!/usr/bin/env python3
"""Project-memory guard for architecture-sensitive pull-request changes."""

from __future__ import annotations

import argparse
import fnmatch
import json
import subprocess
import sys
from pathlib import Path
from typing import Iterable


ROOT = Path(__file__).resolve().parents[1]
DEFAULT_CONFIG = ROOT / "docs" / "project-memory.json"


def _matches(path: str, patterns: Iterable[str]) -> bool:
    return any(fnmatch.fnmatchcase(path, pattern) for pattern in patterns)


def load_config(path: Path = DEFAULT_CONFIG) -> dict:
    data = json.loads(path.read_text(encoding="utf-8"))
    if data.get("version") != 1:
        raise ValueError("unsupported project-memory config version")
    if not isinstance(data.get("state_document"), str):
        raise ValueError("missing state document")
    for key in ("ignore", "state_required_for", "domains"):
        if not isinstance(data.get(key), list):
            raise ValueError(f"invalid {key}")
    return data


def evaluate(changed_files: Iterable[str], config: dict) -> list[str]:
    changed = {str(path).replace("\\", "/").lstrip("./") for path in changed_files if str(path).strip()}
    ignored = config["ignore"]
    runtime = {path for path in changed if not _matches(path, ignored)}
    errors: list[str] = []

    state_doc = config["state_document"]
    if any(_matches(path, config["state_required_for"]) for path in runtime) and state_doc not in changed:
        errors.append(
            f"runtime/architecture files changed but {state_doc} was not updated"
        )

    for domain in config["domains"]:
        name = domain.get("name")
        paths = domain.get("paths")
        required_docs = domain.get("required_docs")
        if not isinstance(name, str) or not isinstance(paths, list) or not isinstance(required_docs, list):
            raise ValueError("invalid project-memory domain")
        if any(_matches(path, paths) for path in runtime):
            missing = [doc for doc in required_docs if doc not in changed]
            if missing:
                errors.append(
                    f"domain {name} changed without required documentation: {', '.join(missing)}"
                )

    return errors


def changed_files(base: str, head: str) -> list[str]:
    if not base or not head:
        raise ValueError("base and head are required")
    completed = subprocess.run(
        ["git", "diff", "--name-only", "--diff-filter=ACMR", f"{base}...{head}"],
        cwd=ROOT,
        check=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        text=True,
    )
    return [line.strip() for line in completed.stdout.splitlines() if line.strip()]


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--base", required=True)
    parser.add_argument("--head", required=True)
    parser.add_argument("--config", default=str(DEFAULT_CONFIG))
    args = parser.parse_args()

    try:
        config = load_config(Path(args.config))
        files = changed_files(args.base, args.head)
        errors = evaluate(files, config)
    except Exception as exc:
        print(f"Project memory guard could not run: {exc}", file=sys.stderr)
        return 2

    if errors:
        print("Project memory documentation is incomplete:", file=sys.stderr)
        for error in errors:
            print(f"- {error}", file=sys.stderr)
        print(
            "Update the required project-state/flow documentation in the same PR, "
            "or narrow docs/project-memory.json if the path was mapped incorrectly.",
            file=sys.stderr,
        )
        return 1

    print("Project memory documentation requirements satisfied.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
