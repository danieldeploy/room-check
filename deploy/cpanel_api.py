#!/usr/bin/env python3
"""Guarded UAPI operations, directly or through WHM; Python 3.9+ stdlib only."""

import argparse
import json
import math
import os
import posixpath
import re
import ssl
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
from dataclasses import dataclass, field


BRANCH = "agent/room-item-assignments"
ORIGIN = "https://server50.romania-webhosting.com:2083"
WHM_ORIGIN = "https://server50.romania-webhosting.com:2087"
WHM_USER = "fazenda"
CPANEL_USER = "welcome"
DATABASE = "welcome_roomcheck"
SOURCE_URLS = {
    "https://github.com/danieldeploy/room-check",
    "https://github.com/danieldeploy/room-check.git",
}
DEFAULT_REPOSITORY = "/home/welcome//home/welcome/repositories/room-check"
SHA = re.compile(r"[0-9a-f]{40}\Z")
MAX_RESPONSE = 2 * 1024 * 1024
FIELDS = "type,repository_root,branch,last_update,last_deployment,deployable,source_repository,tasks"
OPERATIONS = {
    ("VersionControl", "retrieve"): {"fields"},
    ("VersionControl", "update"): {"repository_root", "branch"},
    ("VersionControlDeployment", "create"): {"repository_root"},
    ("VersionControlDeployment", "retrieve"): set(),
    ("Features", "list_features_like"): {"pattern", "is_regex"},
    ("Mysql", "get_privileges_on_database"): {"user", "database"},
}
MYSQL_PRIVILEGES = {
    "ALL PRIVILEGES", "ALTER", "ALTER ROUTINE", "CREATE", "CREATE ROUTINE",
    "CREATE TEMPORARY TABLES", "CREATE VIEW", "DELETE", "DROP", "EVENT", "EXECUTE",
    "INDEX", "INSERT", "LOCK TABLES", "REFERENCES", "SELECT", "SHOW VIEW", "TRIGGER", "UPDATE",
}


class DeploymentError(Exception):
    """A fixed, credential-free error code; never a raw server response."""

    def __init__(self, code, deploy_id=None):
        super().__init__(code)
        self.deploy_id = deploy_id


def require(condition, code):
    if not condition:
        raise DeploymentError(code)


def commit(value):
    require(isinstance(value, str) and SHA.fullmatch(value), "invalid_commit")
    return value


def path_key(value):
    require(isinstance(value, str) and value.startswith("/"), "invalid_repository_path")
    require(re.fullmatch(r"/[A-Za-z0-9_./-]+", value), "invalid_repository_path")
    require(not any(part in (".", "..") for part in value.split("/")), "invalid_repository_path")
    return posixpath.normpath(value)


@dataclass(frozen=True)
class Config:
    token: str = field(repr=False)
    user: str = "welcome"
    repository: str = DEFAULT_REPOSITORY
    origin: str = ORIGIN
    request_timeout: float = 20.0
    wait_timeout: float = 180.0
    poll_interval: float = 2.0
    transport: str = "cpanel"
    whm_user: str = WHM_USER

    def __post_init__(self):
        # A protected environment cannot silently send this token to another host.
        require(self.transport in ("cpanel", "whm"), "transport_not_allowed")
        require(self.origin == (WHM_ORIGIN if self.transport == "whm" else ORIGIN),
                "origin_not_allowed")
        require(isinstance(self.user, str) and re.fullmatch(r"[a-z][a-z0-9_]{0,31}", self.user),
                "invalid_cpanel_user")
        if self.transport == "whm":
            require(self.user == CPANEL_USER, "whm_target_account_not_allowed")
            require(self.whm_user == WHM_USER, "whm_reseller_not_allowed")
        require(isinstance(self.token, str) and self.token and len(self.token) <= 4096,
                "missing_or_invalid_token")
        require(all(33 <= ord(c) <= 126 for c in self.token), "missing_or_invalid_token")
        root = path_key(self.repository)
        require(root.startswith("/home/" + self.user + "/"), "repository_outside_account")
        require(all(math.isfinite(n) for n in
                    (self.request_timeout, self.wait_timeout, self.poll_interval)), "invalid_timeout")
        require(1 <= self.request_timeout <= 60 and 1 <= self.wait_timeout <= 600
                and 0.1 <= self.poll_interval <= 10, "invalid_timeout")

    @classmethod
    def from_environment(cls, args):
        whm = args.transport == "whm"
        return cls(
            token=os.environ.get("WHM_API_TOKEN" if whm else "CPANEL_API_TOKEN", ""),
            user=os.environ.get("CPANEL_USER", "welcome"),
            repository=os.environ.get("CPANEL_REPOSITORY_ROOT", DEFAULT_REPOSITORY),
            origin=os.environ.get("WHM_ORIGIN" if whm else "CPANEL_ORIGIN", WHM_ORIGIN if whm else ORIGIN),
            request_timeout=args.request_timeout,
            wait_timeout=args.wait_timeout,
            transport=args.transport,
            whm_user=os.environ.get("WHM_USER", WHM_USER),
        )


class NoRedirects(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        raise DeploymentError("redirect_refused")


class CpanelAPI:
    def __init__(self, config):
        self.config = config
        # TLS verification is mandatory. Environment HTTP proxies are not used.
        self.opener = urllib.request.build_opener(
            urllib.request.ProxyHandler({}), NoRedirects(),
            urllib.request.HTTPSHandler(context=ssl.create_default_context()),
        )

    def call(self, module, function, **parameters):
        require((module, function) in OPERATIONS, "operation_not_allowed")
        # Reject routing/impersonation overrides, including cpanel.user and api.version.
        require(set(parameters) <= OPERATIONS[(module, function)], "parameter_not_allowed")
        if (module, function) == ("VersionControl", "update"):
            require(parameters == {"repository_root": self.config.repository, "branch": BRANCH},
                    "update_parameters_not_allowed")
        if (module, function) == ("VersionControlDeployment", "create"):
            require(parameters == {"repository_root": self.config.repository},
                    "deploy_parameters_not_allowed")
        if (module, function) == ("Features", "list_features_like"):
            require(parameters == {"pattern": ".*", "is_regex": 1}, "feature_query_not_allowed")
        if (module, function) == ("Mysql", "get_privileges_on_database"):
            require(parameters == {"user": DATABASE, "database": DATABASE},
                    "database_not_allowed")
        if self.config.transport == "whm":
            query = urllib.parse.urlencode({"api.version": 1, "cpanel.user": CPANEL_USER,
                                           "cpanel.module": module, "cpanel.function": function,
                                           **parameters})
            url = self.config.origin + "/json-api/uapi_cpanel"
            authorization = "whm " + self.config.whm_user + ":" + self.config.token
        else:
            query = urllib.parse.urlencode(parameters)
            url = self.config.origin + "/execute/" + module + "/" + function
            authorization = "cpanel " + self.config.user + ":" + self.config.token
        if query:
            url += "?" + query
        # Both official interfaces document GET. The token is only in the header.
        request = urllib.request.Request(url, headers={
            "Authorization": authorization,
            "Accept": "application/json",
            "Cache-Control": "no-store",
            "User-Agent": "Management-Hub-cPanel-Deployment/1",
        })
        try:
            with self.opener.open(request, timeout=self.config.request_timeout) as response:
                require(response.geturl() == url, "redirect_refused")
                require(response.status == 200, "unexpected_http_status")
                raw = response.read(MAX_RESPONSE + 1)
            require(len(raw) <= MAX_RESPONSE, "response_too_large")
            payload = json.loads(raw)
        except DeploymentError:
            raise
        except urllib.error.HTTPError as error:
            # HTTPError is also a URLError. Keep the status (not its body or
            # headers) so authentication and access failures are actionable.
            if error.code in (401, 403):
                raise DeploymentError("api_http_access_denied_" + str(error.code)) from None
            if error.code in (404, 405):
                raise DeploymentError("api_http_endpoint_unavailable_" + str(error.code)) from None
            raise DeploymentError("api_http_error_no_automatic_retry") from None
        except (urllib.error.URLError, OSError, ValueError, UnicodeError):
            # Never expose an exception/body that might echo an Authorization header.
            raise DeploymentError("request_failed_no_automatic_retry") from None
        require(isinstance(payload, dict), "invalid_api_response")
        if self.config.transport == "whm":
            metadata = payload.get("metadata")
            require(isinstance(metadata, dict), "invalid_whm_response")
            require(type(metadata.get("result")) is int and metadata["result"] == 1,
                    "whm_operation_failed")
            data = payload.get("data")
            require(isinstance(data, dict) and isinstance(data.get("uapi"), dict),
                    "invalid_whm_uapi_response")
            result = data["uapi"]
        else:
            # HTTPS /execute responses may omit the UAPI CLI wrapper.
            result = payload.get("result", payload)
        require(isinstance(result, dict), "invalid_api_response")
        require(type(result.get("status")) is int and result["status"] == 1
                and result.get("errors") in (None, []), "api_operation_failed")
        return result.get("data")


class Deployment:
    def __init__(self, api, config, clock=time.monotonic, sleep=time.sleep):
        self.api, self.config, self.clock, self.sleep = api, config, clock, sleep

    def same_path(self, value):
        return path_key(value) == path_key(self.config.repository)

    def repository(self):
        rows = self.api.call("VersionControl", "retrieve", fields=FIELDS)
        require(isinstance(rows, list), "invalid_repository_response")
        matches = [row for row in rows if isinstance(row, dict)
                   and self.same_path(row.get("repository_root"))]
        require(len(matches) == 1, "repository_missing_or_ambiguous")
        return matches[0]

    @staticmethod
    def is_deployable(row):
        # Some cPanel responses encode the documented flag as the string "1".
        # Accept only exact ready encodings; truthiness would also admit "0".
        value = row.get("deployable")
        return (type(value) is int and value == 1) or value is True or (
            type(value) is str and value == "1")

    def inspect(self, row, expected_commit=None, idle=True):
        require(row.get("type") == "git", "unexpected_repository_type")
        require(self.same_path(row.get("repository_root")), "unexpected_repository_path")
        require(row.get("branch") == BRANCH, "unexpected_branch")
        source = row.get("source_repository") or {}
        require(isinstance(source, dict) and source.get("remote_name") == "origin"
                and source.get("url") in SOURCE_URLS, "unexpected_source_repository")
        head = row.get("last_update") or {}
        require(isinstance(head, dict), "invalid_repository_response")
        sha = commit(head.get("identifier"))
        if expected_commit is not None:
            require(sha == commit(expected_commit), "unexpected_commit")
        tasks = row.get("tasks") or []
        require(isinstance(tasks, list), "invalid_repository_response")
        if idle:
            require(not tasks, "repository_busy")
            # This is cPanel's combined clean-tree / branch / .cpanel.yml check.
            require(self.is_deployable(row), "repository_not_deployable")
        return sha

    def tasks(self):
        rows = self.api.call("VersionControlDeployment", "retrieve")
        require(isinstance(rows, list), "invalid_deployment_response")
        return [row for row in rows if isinstance(row, dict)
                and self.same_path(row.get("repository_root"))]

    @staticmethod
    def task_state(row):
        stamps = row.get("timestamps") or {}
        require(isinstance(stamps, dict), "invalid_deployment_timestamps")
        for state in ("failed", "canceled", "succeeded", "active", "queued"):
            stamp = stamps.get(state)
            if stamp not in (None, "", 0, "0"):
                try:
                    number = float(stamp)
                except (TypeError, ValueError):
                    raise DeploymentError("invalid_deployment_timestamps") from None
                require(math.isfinite(number) and number > 0, "invalid_deployment_timestamps")
                return state
        return "unknown"

    @staticmethod
    def task_id(row):
        value = str(row.get("deploy_id", ""))
        require(re.fullmatch(r"[0-9]{1,20}", value), "invalid_deployment_id")
        return value

    def no_active_deployment(self):
        require(all(self.task_state(row) in ("succeeded", "failed", "canceled")
                    for row in self.tasks()), "deployment_busy_or_unknown")

    def status(self):
        row = self.repository()
        last = row.get("last_deployment") or {}
        last_state = last.get("repository_state") or {}
        sha = (row.get("last_update") or {}).get("identifier")
        previous = last_state.get("identifier")
        tasks = self.tasks()
        # Print a small allowlist of operational fields, not commit authors/messages/logs.
        return {"command": "status", "repository_root": self.config.repository,
                "branch": row.get("branch"), "expected_branch": BRANCH,
                "current_commit": commit(sha) if sha else None,
                "last_deployed_commit": commit(previous) if previous else None,
                "deployable": self.is_deployable(row),
                "deployment_readiness": self.readiness(row),
                "repository_busy": bool(row.get("tasks")),
                "deployments": [{"deploy_id": self.task_id(item),
                                 "state": self.task_state(item)} for item in tasks]}

    @staticmethod
    def readiness(row):
        # Report only fixed classifications, never arbitrary server data.
        if "deployable" not in row:
            return "missing"
        value = row["deployable"]
        if type(value) is int and value in (0, 1):
            return "integer_ready" if value == 1 else "integer_not_ready"
        if type(value) is bool:
            return "boolean_ready" if value else "boolean_not_ready"
        if type(value) is str and value in ("0", "1"):
            return "string_ready" if value == "1" else "string_not_ready"
        return "unsupported_format"

    def doctor(self):
        require(self.config.user == CPANEL_USER, "doctor_account_not_allowed")
        features = self.api.call("Features", "list_features_like", pattern=".*", is_regex=1)
        require(isinstance(features, list) and all(
            isinstance(value, str) and re.fullmatch(r"[A-Za-z0-9_.:-]{1,160}", value)
            for value in features), "invalid_features_response")
        privileges = self.api.call("Mysql", "get_privileges_on_database",
                                   user=DATABASE, database=DATABASE)
        require(isinstance(privileges, list) and all(
            isinstance(value, str) and value in MYSQL_PRIVILEGES for value in privileges),
            "invalid_privileges_response")
        status = self.status()
        # Capability evidence only: reads cannot prove permission to mutate or deploy.
        return {"command": "doctor", "transport": self.config.transport,
                "cpanel_user": self.config.user,
                "enabled_feature_ids": sorted(set(features)),
                "database": DATABASE, "database_user": DATABASE,
                "mysql_privileges": sorted(set(privileges)),
                "mysql_permissions": {name: name in privileges or "ALL PRIVILEGES" in privileges
                                      for name in ("ALTER", "CREATE", "UPDATE")},
                "repository": status, "write_operations_validated": False}

    def update(self, current_commit, target_commit):
        commit(current_commit)
        commit(target_commit)
        self.no_active_deployment()
        self.inspect(self.repository(), current_commit)
        self.api.call("VersionControl", "update", repository_root=self.config.repository, branch=BRANCH)
        deadline = self.clock() + self.config.wait_timeout
        while True:
            row = self.repository()
            head = self.inspect(row, idle=False)
            if not row.get("tasks"):
                require(head == target_commit, "updated_to_unexpected_commit_no_deploy")
                self.inspect(row, target_commit)
                return {"command": "update", "state": "verified", "commit": head,
                        "deployed": False}
            require(self.clock() < deadline, "update_timeout_state_unknown_no_retry")
            self.sleep(min(self.config.poll_interval, max(0, deadline - self.clock())))

    def deploy(self, expected_commit):
        commit(expected_commit)
        self.no_active_deployment()
        self.inspect(self.repository(), expected_commit)
        created = self.api.call("VersionControlDeployment", "create",
                                repository_root=self.config.repository)
        require(isinstance(created, dict), "invalid_deployment_response")
        require(self.same_path(created.get("repository_root")), "unexpected_repository_path")
        identifier = self.task_id(created)
        # A queued response is not completion. Follow this exact deployment ID.
        try:
            return self.wait_for_deployment(identifier, expected_commit)
        except DeploymentError as error:
            raise DeploymentError(str(error), deploy_id=identifier) from None

    def wait_for_deployment(self, identifier, expected_commit):
        self.task_id({"deploy_id": identifier})
        commit(expected_commit)
        deadline = self.clock() + self.config.wait_timeout
        while True:
            matches = [row for row in self.tasks() if self.task_id(row) == str(identifier)]
            require(len(matches) <= 1, "ambiguous_deployment_id")
            if matches:
                row = matches[0]
                state = self.task_state(row)
                require(state not in ("failed", "canceled"), "deployment_" + state)
                repository_state = row.get("repository_state") or {}
                require(isinstance(repository_state, dict), "invalid_deployment_response")
                if repository_state:
                    require(repository_state.get("branch") == BRANCH
                            and repository_state.get("identifier") == expected_commit,
                            "deployment_commit_mismatch_inspect_server")
                if state == "succeeded":
                    require(bool(repository_state), "deployment_commit_not_verified")
                    self.inspect(self.repository(), expected_commit)
                    return {"command": "deploy", "state": "succeeded", "deploy_id": str(identifier),
                            "commit": expected_commit,
                            "application_functional_test": "still_required"}
            require(self.clock() < deadline, "deployment_timeout_state_unknown_no_retry")
            self.sleep(min(self.config.poll_interval, max(0, deadline - self.clock())))


def main(argv=None):
    parser = argparse.ArgumentParser(description="Management Hub: UAPI por cPanel ou WHM; estado por defeito.")
    parser.add_argument("command", choices=("status", "doctor", "update", "deploy", "wait"), nargs="?", default="status")
    parser.add_argument("--transport", choices=("cpanel", "whm"), default="cpanel",
                        help="cpanel: token da conta, porta 2083; whm: token fazenda, porta 2087")
    parser.add_argument("--expected-commit", help="SHA completo revisto: destino do update ou HEAD a publicar")
    parser.add_argument("--expected-current-commit", help="SHA atual no cPanel, obrigatório para update")
    parser.add_argument("--deploy-id", help="ID existente; wait apenas consulta, sem criar novo deployment")
    parser.add_argument("--request-timeout", type=float, default=20)
    parser.add_argument("--wait-timeout", type=float, default=180)
    args = parser.parse_args(argv)
    config = None
    try:
        require(args.command in ("status", "doctor") or args.expected_commit, "expected_commit_required")
        require(args.command != "update" or args.expected_current_commit, "expected_current_commit_required")
        require(args.command != "wait" or args.deploy_id, "deploy_id_required")
        config = Config.from_environment(args)
        runner = Deployment(CpanelAPI(config), config)
        if args.command == "status":
            result = runner.status()
        elif args.command == "doctor":
            result = runner.doctor()
        elif args.command == "update":
            result = runner.update(args.expected_current_commit, args.expected_commit)
        elif args.command == "deploy":
            result = runner.deploy(args.expected_commit)
        else:
            result = runner.wait_for_deployment(args.deploy_id, args.expected_commit)
        output = json.dumps({"ok": True, **result}, ensure_ascii=False)
        print(output.replace(config.token, "[REDACTED]"))
        return 0
    except DeploymentError as error:
        result = {"ok": False, "error": str(error)}
        if error.deploy_id:
            result["deploy_id"] = error.deploy_id
        print(json.dumps(result), file=sys.stderr)
        return 1
    except KeyboardInterrupt:
        print('{"ok": false, "error": "interrupted_state_unknown_no_retry"}', file=sys.stderr)
        return 130
    except Exception:
        # Includes malformed API data. Never dump the token/config or an HTTP exception.
        print('{"ok": false, "error": "unexpected_error_inspect_server"}', file=sys.stderr)
        return 1


if __name__ == "__main__":
    sys.exit(main())
