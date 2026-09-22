#!/usr/bin/env python3
"""Release a CI-approved production SHA using the guarded WHM client.

This process intentionally has no rollback, mutation retry, arbitrary command,
or SQL interface. A failed/uncertain deployment requires inspection before retry.
"""

import argparse
from html.parser import HTMLParser
import json
import math
import os
from pathlib import Path
import re
import ssl
import sys
import urllib.error
import urllib.parse
import urllib.request

if __package__:
    from .cpanel_api import (BRANCH, CpanelAPI, Config, Deployment, DeploymentError,
                            NoRedirects, WHM_ORIGIN, commit, require)
else:
    from cpanel_api import (BRANCH, CpanelAPI, Config, Deployment, DeploymentError,
                           NoRedirects, WHM_ORIGIN, commit, require)


GITHUB_API = "https://api.github.com/repos/danieldeploy/room-check"
HEALTH_URL = "https://check.welcomehostel.pt/login.php"
MAX_RESPONSE = 512 * 1024
TASK_STATES = ("queued", "active", "succeeded", "failed", "canceled")


def require_backup_gate(repo=None):
    root = Path(repo) if repo is not None else Path(__file__).resolve().parents[1]
    tasks = re.findall(r"(?m)^\s+-\s+(.+)$", (root / '.cpanel.yml').read_text())
    require(tasks == ['/bin/bash deploy/release.sh']
            and (root / 'deploy/prepare_release_backup.php').is_file()
            and (root / 'deploy/release.sh').is_file(),
            'release_backup_gate_missing')
    script = (root / 'deploy/release.sh').read_text()
    backup = '/usr/local/bin/php deploy/prepare_release_backup.php'
    require('set -euo pipefail' in script and backup in script and '/bin/cp ' in script
            and script.index(backup) < script.index('/bin/cp '), 'release_backup_gate_missing')


class ReadOnlyHTTP:
    def __init__(self, timeout=20):
        self.timeout = timeout
        self.opener = urllib.request.build_opener(
            urllib.request.ProxyHandler({}), NoRedirects(),
            urllib.request.HTTPSHandler(context=ssl.create_default_context()),
        )

    def get(self, url, headers=None):
        request = urllib.request.Request(url, headers={
            "User-Agent": "Management-Hub-Automated-Release/1",
            "Cache-Control": "no-store", **(headers or {}),
        })
        try:
            with self.opener.open(request, timeout=self.timeout) as response:
                require(response.geturl() == url, "redirect_refused")
                require(response.status == 200, "read_unexpected_http_status")
                content_type = response.headers.get_content_type()
                raw = response.read(MAX_RESPONSE + 1)
            require(len(raw) <= MAX_RESPONSE, "read_response_too_large")
            return raw, content_type
        except DeploymentError:
            raise
        except (urllib.error.URLError, OSError, ValueError, UnicodeError):
            raise DeploymentError("read_request_failed") from None


class GitHub:
    def __init__(self, token, http=None):
        require(isinstance(token, str) and token and len(token) <= 4096
                and all(33 <= ord(c) <= 126 for c in token), "missing_or_invalid_github_token")
        self._token = token
        self.http = http or ReadOnlyHTTP()

    def read(self, path):
        # Only internal callers construct these paths; there is no arbitrary URL argument.
        raw, content_type = self.http.get(GITHUB_API + path, {
            "Authorization": "Bearer " + self._token,
            "Accept": "application/vnd.github+json",
            "X-GitHub-Api-Version": "2022-11-28",
        })
        require(content_type == "application/json", "invalid_github_response")
        try:
            data = json.loads(raw)
        except (ValueError, UnicodeError):
            raise DeploymentError("invalid_github_response") from None
        require(isinstance(data, dict), "invalid_github_response")
        return data

    def require_head(self, expected):
        expected = commit(expected)
        data = self.read("/git/ref/heads/" + urllib.parse.quote(BRANCH, safe=""))
        require(data.get("ref") == "refs/heads/" + BRANCH, "unexpected_github_ref")
        obj = data.get("object")
        require(isinstance(obj, dict) and obj.get("type") == "commit", "invalid_github_response")
        require(commit(obj.get("sha")) == expected, "stale_release_remote_head_changed")

    def require_fast_forward(self, current, expected):
        current, expected = commit(current), commit(expected)
        data = self.read("/compare/" + current + "..." + expected + "?per_page=1")
        base = data.get("merge_base_commit")
        require(isinstance(base, dict) and base.get("sha") == current
                and data.get("status") == "ahead"
                and type(data.get("behind_by")) is int and data["behind_by"] == 0
                and type(data.get("ahead_by")) is int and data["ahead_by"] > 0,
                "release_not_fast_forward")


class LoginForm(HTMLParser):
    """Recognize the actual login form without retaining values or response text."""
    def __init__(self):
        super().__init__()
        self.fields = None
        self.valid = False

    def handle_starttag(self, tag, attrs):
        attrs = dict(attrs)
        if tag == "form":
            self.fields = set() if attrs.get("method", "").lower() == "post" else None
        elif tag == "input" and self.fields is not None:
            name, kind = attrs.get("name"), attrs.get("type", "text").lower()
            if (name, kind) in (("username", "text"), ("password", "password"),
                                 ("csrf_token", "hidden")):
                self.fields.add(name)

    def handle_endtag(self, tag):
        if tag == "form":
            self.valid = self.valid or self.fields == {"username", "password", "csrf_token"}
            self.fields = None


def health_check(http=None):
    raw, content_type = (http or ReadOnlyHTTP()).get(HEALTH_URL, {"Accept": "text/html"})
    require(content_type == "text/html", "health_unexpected_content_type")
    try:
        document = raw.decode("utf-8")
    except UnicodeError:
        raise DeploymentError("health_invalid_response") from None
    form = LoginForm()
    form.feed(document)
    form.close()
    require(form.valid, "health_login_form_missing")
    return {"https_login": "passed", "authenticated_workflows": "not_tested"}


def latest_successful_task(runner):
    """Reject unfinished/unknown work and a latest failed/canceled operation.

    Historical failures may remain in the task list after a later successful
    manual repair. Use documented state timestamps, not task ID ordering.
    """
    tasks = runner.tasks()
    ranked = []
    identifiers = set()
    for task in tasks:
        identifier = runner.task_id(task)
        require(identifier not in identifiers, "ambiguous_deployment_history")
        identifiers.add(identifier)
        state = runner.task_state(task)
        require(state in ("succeeded", "failed", "canceled"), "deployment_busy_or_unknown")
        stamps = task.get("timestamps") or {}
        values = []
        for key in TASK_STATES:
            value = stamps.get(key)
            if value in (None, "", 0, "0"):
                continue
            try:
                number = float(value)
            except (TypeError, ValueError):
                raise DeploymentError("invalid_deployment_timestamps") from None
            require(math.isfinite(number) and number > 0, "invalid_deployment_timestamps")
            values.append(number)
        require(bool(values), "invalid_deployment_timestamps")
        ranked.append((max(values), state, task))
    if not ranked:
        return None
    latest_stamp = max(item[0] for item in ranked)
    matches = [item for item in ranked if item[0] == latest_stamp]
    require(len(matches) == 1, "ambiguous_deployment_history")
    _, state, task = matches[0]
    require(state == "succeeded", "previous_deployment_failed_inspect_server")
    return task


def deployed_commit(row):
    last = row.get("last_deployment")
    if last in (None, {}):
        return None
    require(isinstance(last, dict), "invalid_last_deployment")
    state = last.get("repository_state")
    # VersionControl's summary does not promise a branch; deployment tasks do.
    # The caller still verifies the task branch and its SHA against this summary.
    require(isinstance(state, dict) and ("branch" not in state or state["branch"] == BRANCH),
            "invalid_last_deployment")
    return commit(state.get("identifier"))


def release(runner, github, expected, check_health=health_check, check_backup=require_backup_gate):
    expected = commit(expected)
    check_backup()
    github.require_head(expected)
    diagnostic = runner.doctor()
    require(diagnostic.get("transport") == "whm"
            and diagnostic.get("cpanel_user") == "welcome", "release_account_not_allowed")
    row = runner.repository()
    current = runner.inspect(row)
    latest = latest_successful_task(runner)
    deployed = deployed_commit(row)
    if latest is not None:
        state = latest.get("repository_state")
        require(isinstance(state, dict) and state.get("branch") == BRANCH,
                "deployment_history_commit_not_verified")
        require(commit(state.get("identifier")) == deployed,
                "deployment_history_commit_mismatch")
    if current == expected and deployed == expected:
        github.require_head(expected)
        health = check_health()
        return {"state": "already_deployed", "commit": expected, "mutated": False,
                "health": health, "backup_gate": "not_executed_already_deployed"}
    if current != expected:
        github.require_fast_forward(current, expected)
        github.require_head(expected)
        runner.update(current, expected)
    # cPanel pulls the remote branch rather than accepting an atomic target SHA.
    # Recheck both sides and the existing client's own pre/postconditions. The
    # workflow must serialize deployments; concurrent manual writes remain unsafe.
    github.require_head(expected)
    runner.inspect(runner.repository(), expected)
    latest_successful_task(runner)
    result = runner.deploy(expected)
    try:
        health = check_health()
        github.require_head(expected)
    except DeploymentError as error:
        raise DeploymentError(str(error), deploy_id=result['deploy_id']) from None
    return {"state": "succeeded", "commit": expected, "mutated": True,
            "deploy_id": result["deploy_id"], "health": health,
            "backup_gate": "deployment_cli_passed",
            "backup_restore_test": "not_performed"}


def main(argv=None):
    parser = argparse.ArgumentParser(description="Publish the exact CI-approved production commit through WHM.")
    mode = parser.add_mutually_exclusive_group(required=True)
    mode.add_argument("--expected-commit")
    mode.add_argument("--diagnostic", action="store_true")
    args = parser.parse_args(argv)
    secrets = [os.environ.get("WHM_API_TOKEN", ""), os.environ.get("GITHUB_TOKEN", "")]

    def output(data, error=False):
        value = json.dumps(data, ensure_ascii=False)
        for secret in secrets:
            if secret:
                value = value.replace(secret, "[REDACTED]")
        print(value, file=sys.stderr if error else sys.stdout)

    try:
        config = Config(token=secrets[0], transport="whm", origin=WHM_ORIGIN, wait_timeout=600)
        runner = Deployment(CpanelAPI(config), config)
        if args.diagnostic:
            diagnostic = runner.doctor()
            readiness = diagnostic.get('repository', {}).get('deployment_readiness')
            allowed = {'missing', 'integer_ready', 'integer_not_ready', 'boolean_ready',
                       'boolean_not_ready', 'string_ready', 'string_not_ready', 'unsupported_format'}
            output({'ok': True, 'mode': 'diagnostic', 'authenticated_reads': 'passed', 'writes': False,
                    'deployment_readiness': readiness if readiness in allowed else 'unavailable'})
            return 0
        result = release(runner, GitHub(secrets[1]), args.expected_commit)
        output({"ok": True, **result})
        return 0
    except DeploymentError as error:
        value = {"ok": False, "error": str(error)}
        if error.deploy_id:
            value["deploy_id"] = error.deploy_id
        output(value, error=True)
        return 1
    except KeyboardInterrupt:
        output({"ok": False, "error": "interrupted_state_unknown_no_retry"}, error=True)
        return 130
    except Exception:
        output({"ok": False, "error": "unexpected_error_inspect_server"}, error=True)
        return 1


if __name__ == "__main__":
    sys.exit(main())
