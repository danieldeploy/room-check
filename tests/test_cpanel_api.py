"""No network or production writes: cPanel API security and lifecycle contracts."""

import contextlib
import copy
import importlib.util
import io
import json
import os
from pathlib import Path
import sys
import unittest
from unittest.mock import patch
import urllib.error
import urllib.parse

SPEC = importlib.util.spec_from_file_location("cpanel_api", Path(__file__).parents[1] / "deploy/cpanel_api.py")
cpanel = importlib.util.module_from_spec(SPEC)
sys.modules[SPEC.name] = cpanel
SPEC.loader.exec_module(cpanel)

OLD = "9a3cc9be6ffe01cacf9703eb9f4156caf65f5c0b"
NEW = "a" * 40
TOKEN = "private-test-token-never-print"


def repository(**changes):
    value = {"repository_root": cpanel.DEFAULT_REPOSITORY, "type": "git",
             "branch": cpanel.BRANCH, "deployable": 1, "last_update": {"identifier": OLD},
             "source_repository": {"remote_name": "origin", "url": "https://github.com/danieldeploy/room-check.git"}}
    value.update(changes)
    return value


def deployment(state="queued", sha=OLD):
    return {"deploy_id": 17, "repository_root": cpanel.DEFAULT_REPOSITORY,
            "repository_state": {"branch": cpanel.BRANCH, "identifier": sha},
            "timestamps": {state: "100.25"}}


class FakeAPI:
    def __init__(self, responses):
        self.responses = list(responses)
        self.calls = []

    def call(self, module, function, **parameters):
        self.calls.append((module, function, parameters))
        expected, result = self.responses.pop(0)
        assert (module, function) == expected, ((module, function), expected)
        if isinstance(result, Exception):
            raise result
        return copy.deepcopy(result)


class Clock:
    now = 0

    def time(self):
        return self.now

    def sleep(self, seconds):
        self.now += seconds


class Response:
    status = 200

    def __init__(self, value, url):
        self.value, self.url = value, url

    def __enter__(self):
        return self

    def __exit__(self, *args):
        return False

    def geturl(self):
        return self.url

    def read(self, limit):
        return json.dumps(self.value).encode()[:limit]


class Contracts(unittest.TestCase):
    def test_readiness_classifies_without_returning_server_text(self):
        self.assertEqual(cpanel.Deployment.readiness({}), 'missing')
        for value, expected in ((1, 'integer_ready'), (0, 'integer_not_ready'),
                                (True, 'boolean_ready'), (False, 'boolean_not_ready'),
                                ('1', 'string_ready'), ('0', 'string_not_ready'),
                                ('private-server-text', 'unsupported_format'),
                                ({'status': 1}, 'unsupported_format')):
            self.assertEqual(cpanel.Deployment.readiness({'deployable': value}), expected)

    def runner(self, responses):
        api = FakeAPI(responses)
        clock = Clock()
        config = cpanel.Config(TOKEN, wait_timeout=1, poll_interval=0.5)
        return cpanel.Deployment(api, config, clock.time, clock.sleep), api

    def preflight(self, row):
        return [(('VersionControlDeployment', 'retrieve'), []),
                (('VersionControl', 'retrieve'), [row])]

    def queued_deploy(self):
        return self.preflight(repository()) + [
            (('VersionControlDeployment', 'create'), {"deploy_id": "17", "repository_root": cpanel.DEFAULT_REPOSITORY,
                                                      "timestamps": {"queued": "100"}})]

    def test_mutations_reject_wrong_branch_commit_remote_and_dirty_state(self):
        cases = [(repository(branch="codex/management-hub"), "unexpected_branch"),
                 (repository(last_update={"identifier": NEW}), "unexpected_commit"),
                 (repository(source_repository={"remote_name": "origin", "url": "https://evil.example/repo"}),
                  "unexpected_source_repository"),
                 (repository(deployable=0), "repository_not_deployable"),
                 (repository(tasks=[{"action": "deploy"}]), "repository_busy")]
        for row, error in cases:
            with self.subTest(error=error):
                runner, api = self.runner(self.preflight(row))
                with self.assertRaisesRegex(cpanel.DeploymentError, error):
                    runner.deploy(OLD)
                self.assertTrue(all(function == "retrieve" for _, function, _ in api.calls))

    def test_update_refuses_unexpected_current_sha_before_mutation(self):
        runner, api = self.runner(self.preflight(repository()))
        with self.assertRaisesRegex(cpanel.DeploymentError, "unexpected_commit"):
            runner.update(NEW, NEW)
        self.assertEqual(len(api.calls), 2)

    def test_update_explicit_branch_and_verified_target_without_deploy(self):
        runner, api = self.runner(self.preflight(repository()) + [
            (('VersionControl', 'update'), {}),
            (('VersionControl', 'retrieve'), [repository(last_update={"identifier": NEW})]),
        ])
        result = runner.update(OLD, NEW)
        self.assertEqual(result["commit"], NEW)
        self.assertFalse(result["deployed"])
        self.assertEqual(api.calls[2][2]["branch"], cpanel.BRANCH)
        self.assertFalse(any(function == "create" for _, function, _ in api.calls))

    def test_update_to_unreviewed_target_never_deploys(self):
        runner, api = self.runner(self.preflight(repository()) + [
            (('VersionControl', 'update'), {}),
            (('VersionControl', 'retrieve'), [repository(last_update={"identifier": "b" * 40})]),
        ])
        with self.assertRaisesRegex(cpanel.DeploymentError, "updated_to_unexpected_commit_no_deploy"):
            runner.update(OLD, NEW)
        self.assertEqual(len(api.calls), 4)

    def test_busy_deployment_blocks_new_mutation(self):
        runner, api = self.runner([(('VersionControlDeployment', 'retrieve'), [deployment("active")])])
        with self.assertRaisesRegex(cpanel.DeploymentError, "deployment_busy_or_unknown"):
            runner.deploy(OLD)
        self.assertEqual(len(api.calls), 1)

    def test_queued_is_not_success_waits_for_exact_id_and_commit(self):
        unrelated = deployment("succeeded", NEW)
        unrelated["deploy_id"] = 16
        runner, api = self.runner(self.queued_deploy() + [
            (('VersionControlDeployment', 'retrieve'), [unrelated, deployment("queued")]),
            (('VersionControlDeployment', 'retrieve'), [deployment("active")]),
            (('VersionControlDeployment', 'retrieve'), [deployment("succeeded")]),
            (('VersionControl', 'retrieve'), [repository()]),
        ])
        result = runner.deploy(OLD)
        self.assertEqual(result["state"], "succeeded")
        self.assertEqual(result["deploy_id"], "17")
        self.assertEqual(result["application_functional_test"], "still_required")
        self.assertEqual(sum(function == "create" for _, function, _ in api.calls), 1)

    def test_failure_and_cancel_after_queue_are_errors_without_retry(self):
        for state in ("failed", "canceled"):
            with self.subTest(state=state):
                runner, api = self.runner(self.queued_deploy() + [
                    (('VersionControlDeployment', 'retrieve'), [deployment(state)])])
                with self.assertRaisesRegex(cpanel.DeploymentError, "deployment_" + state) as caught:
                    runner.deploy(OLD)
                self.assertEqual(caught.exception.deploy_id, "17")
                self.assertEqual(sum(function == "create" for _, function, _ in api.calls), 1)

    def test_queue_timeout_is_unknown_not_success_and_keeps_id(self):
        runner, api = self.runner(self.queued_deploy() + [
            (('VersionControlDeployment', 'retrieve'), [deployment()]) for _ in range(3)])
        with self.assertRaisesRegex(cpanel.DeploymentError, "deployment_timeout_state_unknown_no_retry") as caught:
            runner.deploy(OLD)
        self.assertEqual(caught.exception.deploy_id, "17")
        self.assertEqual(sum(function == "create" for _, function, _ in api.calls), 1)

    def test_completed_unexpected_sha_is_not_success(self):
        runner, _ = self.runner(self.queued_deploy() + [
            (('VersionControlDeployment', 'retrieve'), [deployment("succeeded", NEW)])])
        with self.assertRaisesRegex(cpanel.DeploymentError, "deployment_commit_mismatch"):
            runner.deploy(OLD)

    def test_missing_state_cannot_confirm_success(self):
        task = deployment("succeeded")
        del task["repository_state"]
        runner, _ = self.runner(self.queued_deploy() + [(('VersionControlDeployment', 'retrieve'), [task])])
        with self.assertRaisesRegex(cpanel.DeploymentError, "deployment_commit_not_verified"):
            runner.deploy(OLD)

    def test_wait_existing_deployment_is_read_only(self):
        runner, api = self.runner([(('VersionControlDeployment', 'retrieve'), [deployment("succeeded")]),
                                   (('VersionControl', 'retrieve'), [repository()])])
        runner.wait_for_deployment("17", OLD)
        self.assertTrue(all(function == "retrieve" for _, function, _ in api.calls))

    def test_origin_path_and_header_validation(self):
        for origin in ("http://server50.romania-webhosting.com:2083", "https://evil.example:2083", cpanel.ORIGIN + "/"):
            with self.assertRaises(cpanel.DeploymentError):
                cpanel.Config(TOKEN, origin=origin)
        for path in ("/home/other/repo", "/home/welcome/../other/repo", "/home/welcome/repo?token=x"):
            with self.assertRaises(cpanel.DeploymentError):
                cpanel.Config(TOKEN, repository=path)
        with self.assertRaises(cpanel.DeploymentError):
            cpanel.Config("token\r\nExtra: header")
        self.assertNotIn(TOKEN, repr(cpanel.Config(TOKEN)))

    def test_redirects_refused_including_same_host(self):
        with self.assertRaisesRegex(cpanel.DeploymentError, "redirect_refused"):
            cpanel.NoRedirects().redirect_request(None, None, 302, "", {}, cpanel.ORIGIN + "/login")

    def test_api_errors_do_not_echo_body_or_token_and_no_retry(self):
        api = cpanel.CpanelAPI(cpanel.Config(TOKEN))
        calls = []
        def respond(request, timeout):
            calls.append(request)
            self.assertEqual(request.get_header("Authorization"), "cpanel welcome:" + TOKEN)
            self.assertNotIn(TOKEN, request.full_url)
            return Response({"result": {"status": 0, "errors": [TOKEN], "data": None}}, request.full_url)
        with patch.object(api.opener, "open", side_effect=respond):
            with self.assertRaisesRegex(cpanel.DeploymentError, "api_operation_failed") as caught:
                api.call("VersionControl", "retrieve", fields=cpanel.FIELDS)
        self.assertNotIn(TOKEN, str(caught.exception))
        self.assertEqual(len(calls), 1)

    def test_both_documented_uapi_envelopes_and_transport_failure(self):
        api = cpanel.CpanelAPI(cpanel.Config(TOKEN))
        for wrapped in (True, False):
            payload = {"status": 1, "errors": None, "data": []}
            if wrapped:
                payload = {"result": payload}
            with patch.object(api.opener, "open", side_effect=lambda request, timeout: Response(payload, request.full_url)):
                self.assertEqual(api.call("VersionControl", "retrieve"), [])
        with patch.object(api.opener, "open", side_effect=urllib.error.URLError(TOKEN)):
            with self.assertRaisesRegex(cpanel.DeploymentError, "request_failed_no_automatic_retry") as caught:
                api.call("VersionControl", "retrieve")
            self.assertNotIn(TOKEN, str(caught.exception))

    def test_default_command_only_reads_status(self):
        fake = FakeAPI([(('VersionControl', 'retrieve'), [repository()]),
                        (('VersionControlDeployment', 'retrieve'), [])])
        output = io.StringIO()
        with patch.dict(os.environ, {"CPANEL_API_TOKEN": TOKEN}, clear=True), \
                patch.object(cpanel, "CpanelAPI", return_value=fake), contextlib.redirect_stdout(output):
            self.assertEqual(cpanel.main([]), 0)
        self.assertEqual(json.loads(output.getvalue())["command"], "status")
        self.assertNotIn(TOKEN, output.getvalue())
        self.assertTrue(all(function == "retrieve" for _, function, _ in fake.calls))

    def test_mutation_requires_sha_before_constructing_network_client(self):
        with patch.object(cpanel, "CpanelAPI") as constructor, contextlib.redirect_stderr(io.StringIO()):
            self.assertEqual(cpanel.main(["deploy"]), 1)
        constructor.assert_not_called()


def whm_payload(data, outer_status=1, inner_status=1, errors=None):
    return {"metadata": {"result": outer_status, "reason": "OK", "command": "uapi_cpanel", "version": 1},
            "data": {"uapi": {"status": inner_status, "errors": errors, "data": data}}}


class WhmTransport(unittest.TestCase):
    def config(self, **changes):
        values = {"transport": "whm", "origin": cpanel.WHM_ORIGIN}
        values.update(changes)
        return cpanel.Config(TOKEN, **values)

    def test_documented_wrapper_header_and_query_target_exact_account(self):
        api = cpanel.CpanelAPI(self.config())
        def respond(request, timeout):
            self.assertEqual(request.get_header("Authorization"), "whm fazenda:" + TOKEN)
            self.assertNotIn(TOKEN, request.full_url)
            url = urllib.parse.urlsplit(request.full_url)
            self.assertEqual(url.scheme + "://" + url.netloc, cpanel.WHM_ORIGIN)
            self.assertEqual(url.path, "/json-api/uapi_cpanel")
            self.assertEqual(urllib.parse.parse_qs(url.query), {
                "api.version": ["1"], "cpanel.user": ["welcome"], "cpanel.module": ["VersionControl"],
                "cpanel.function": ["retrieve"], "fields": [cpanel.FIELDS]})
            return Response(whm_payload([repository()]), request.full_url)
        with patch.object(api.opener, "open", side_effect=respond) as opened:
            self.assertEqual(api.call("VersionControl", "retrieve", fields=cpanel.FIELDS), [repository()])
        self.assertEqual(opened.call_count, 1)

    def test_outer_success_does_not_hide_inner_failure_or_errors(self):
        api = cpanel.CpanelAPI(self.config())
        payloads = [whm_payload([], inner_status=0, errors=[TOKEN]),
                    whm_payload([], inner_status=1, errors=[TOKEN]),
                    whm_payload([], inner_status=True), whm_payload([], inner_status="1")]
        for payload in payloads:
            with self.subTest(payload_status=payload["data"]["uapi"]["status"]):
                with patch.object(api.opener, "open", side_effect=lambda request, timeout: Response(payload, request.full_url)) as opened:
                    with self.assertRaisesRegex(cpanel.DeploymentError, "api_operation_failed") as caught:
                        api.call("VersionControl", "retrieve")
                    self.assertNotIn(TOKEN, str(caught.exception))
                self.assertEqual(opened.call_count, 1)

    def test_inner_success_cannot_override_whm_failure(self):
        api = cpanel.CpanelAPI(self.config())
        for outer_status in (0, None, "1", True):
            payload = whm_payload([], outer_status=outer_status)
            payload["metadata"]["reason"] = TOKEN
            with self.subTest(outer_status=outer_status), \
                    patch.object(api.opener, "open", side_effect=lambda request, timeout: Response(payload, request.full_url)):
                with self.assertRaisesRegex(cpanel.DeploymentError, "whm_operation_failed") as caught:
                    api.call("VersionControl", "retrieve")
                self.assertNotIn(TOKEN, str(caught.exception))

    def test_undocumented_or_missing_whm_envelope_is_not_success(self):
        api = cpanel.CpanelAPI(self.config())
        for payload in ({"status": 1, "data": []}, {"result": {"status": 1, "data": []}},
                        {"metadata": {"result": 1}, "data": {"cpanelresult": {"status": 1}}},
                        {"metadata": {"result": 1}, "data": None},
                        {"metadata": {"result": 1}, "data": {"uapi": []}}):
            with self.subTest(payload=payload), \
                    patch.object(api.opener, "open", side_effect=lambda request, timeout: Response(payload, request.full_url)):
                with self.assertRaisesRegex(cpanel.DeploymentError, "invalid_whm"):
                    api.call("VersionControl", "retrieve")

    def test_target_reseller_transport_and_origin_spoofing_rejected(self):
        for changes in ({"user": "other", "repository": "/home/other/repo"},
                        {"whm_user": "root"}, {"whm_user": "other"},
                        {"origin": cpanel.ORIGIN}, {"origin": cpanel.WHM_ORIGIN + "/"},
                        {"origin": "http://server50.romania-webhosting.com:2087"},
                        {"origin": "https://server50.romania-webhosting.com.evil.example:2087"},
                        {"transport": "other"}):
            with self.subTest(changes=changes), self.assertRaises(cpanel.DeploymentError):
                self.config(**changes)

    def test_routing_and_extra_operation_parameters_are_rejected_before_network(self):
        api = cpanel.CpanelAPI(self.config())
        attempts = [("VersionControl", "retrieve", {"cpanel.user": "other"}),
                    ("VersionControl", "retrieve", {"api.version": 0}),
                    ("VersionControl", "retrieve", {"cpanel.function": "update"}),
                    ("VersionControl", "update", {"repository_root": cpanel.DEFAULT_REPOSITORY, "branch": "main"}),
                    ("VersionControlDeployment", "create", {"repository_root": "/home/other/repo"}),
                    ("Mysql", "get_privileges_on_database", {"user": "other", "database": "other"}),
                    ("Mysql", "set_privileges_on_database", {"user": cpanel.DATABASE, "database": cpanel.DATABASE}),
                    ("Fileman", "upload_files", {}), ("Tokens", "create_full_access", {}),
                    ("Cron", "add_line", {})]
        with patch.object(api.opener, "open") as opened:
            for module, function, parameters in attempts:
                with self.subTest(operation=(module, function), parameters=parameters), self.assertRaises(cpanel.DeploymentError):
                    api.call(module, function, **parameters)
        opened.assert_not_called()

    def test_whm_cli_selects_only_whm_secret_and_defaults_to_read_only_status(self):
        fake = FakeAPI([(('VersionControl', 'retrieve'), [repository()]),
                        (('VersionControlDeployment', 'retrieve'), [])])
        output = io.StringIO()
        with patch.dict(os.environ, {"WHM_API_TOKEN": TOKEN, "CPANEL_API_TOKEN": "unused"}, clear=True), \
                patch.object(cpanel, "CpanelAPI", return_value=fake) as constructor, contextlib.redirect_stdout(output):
            self.assertEqual(cpanel.main(["--transport", "whm"]), 0)
        config = constructor.call_args.args[0]
        self.assertEqual((config.token, config.origin, config.user, config.whm_user),
                         (TOKEN, cpanel.WHM_ORIGIN, "welcome", "fazenda"))
        self.assertEqual(json.loads(output.getvalue())["command"], "status")
        self.assertNotIn(TOKEN, output.getvalue())
        self.assertTrue(all(function == "retrieve" for _, function, _ in fake.calls))

    def test_whm_does_not_fall_back_to_cpanel_secret(self):
        output = io.StringIO()
        with patch.dict(os.environ, {"CPANEL_API_TOKEN": TOKEN}, clear=True), \
                patch.object(cpanel, "CpanelAPI") as constructor, contextlib.redirect_stderr(output):
            self.assertEqual(cpanel.main(["--transport", "whm"]), 1)
        constructor.assert_not_called()
        self.assertEqual(json.loads(output.getvalue())["error"], "missing_or_invalid_token")
        self.assertNotIn(TOKEN, output.getvalue())

    def test_whm_update_and_deployment_preserve_sha_and_task_verification(self):
        config = self.config()
        api = cpanel.CpanelAPI(config)
        runner = cpanel.Deployment(api, config)
        responses = [[], [repository()], None, [repository(last_update={"identifier": NEW})],
                     [], [repository(last_update={"identifier": NEW})],
                     {"deploy_id": "17", "repository_root": cpanel.DEFAULT_REPOSITORY},
                     [deployment("succeeded", NEW)], [repository(last_update={"identifier": NEW})]]
        calls = []
        def respond(request, timeout):
            query = urllib.parse.parse_qs(urllib.parse.urlsplit(request.full_url).query)
            calls.append(query)
            self.assertEqual(query["cpanel.user"], ["welcome"])
            self.assertEqual(request.get_header("Authorization"), "whm fazenda:" + TOKEN)
            return Response(whm_payload(responses.pop(0)), request.full_url)
        with patch.object(api.opener, "open", side_effect=respond):
            self.assertEqual(runner.update(OLD, NEW)["commit"], NEW)
            self.assertEqual(runner.deploy(NEW)["state"], "succeeded")
        self.assertEqual(calls[2]["branch"], [cpanel.BRANCH])
        self.assertEqual(calls[2]["repository_root"], [cpanel.DEFAULT_REPOSITORY])
        self.assertEqual(sum(query["cpanel.function"] == ["create"] for query in calls), 1)
        self.assertEqual(responses, [])

    def test_doctor_only_queries_existing_features_privileges_and_git(self):
        fake = FakeAPI([(('Features', 'list_features_like'), ["version_control", "api_tokens"]),
                        (('Mysql', 'get_privileges_on_database'), ["ALL PRIVILEGES"]),
                        (('VersionControl', 'retrieve'), [repository()]),
                        (('VersionControlDeployment', 'retrieve'), [])])
        output = io.StringIO()
        with patch.dict(os.environ, {"WHM_API_TOKEN": TOKEN}, clear=True), \
                patch.object(cpanel, "CpanelAPI", return_value=fake), contextlib.redirect_stdout(output):
            self.assertEqual(cpanel.main(["doctor", "--transport", "whm"]), 0)
        result = json.loads(output.getvalue())
        self.assertEqual(result["mysql_permissions"], {"ALTER": True, "CREATE": True, "UPDATE": True})
        self.assertFalse(result["write_operations_validated"])
        self.assertEqual(fake.calls[0][2], {"pattern": ".*", "is_regex": 1})
        self.assertEqual(fake.calls[1][2], {"user": cpanel.DATABASE, "database": cpanel.DATABASE})
        self.assertEqual(len(fake.calls), 4)
        self.assertNotIn(TOKEN, output.getvalue())

    def test_doctor_reports_missing_privileges_without_granting_them(self):
        fake = FakeAPI([(('Features', 'list_features_like'), []),
                        (('Mysql', 'get_privileges_on_database'), ["SELECT", "UPDATE"]),
                        (('VersionControl', 'retrieve'), [repository()]),
                        (('VersionControlDeployment', 'retrieve'), [])])
        result = cpanel.Deployment(fake, self.config()).doctor()
        self.assertEqual(result["mysql_permissions"], {"ALTER": False, "CREATE": False, "UPDATE": True})
        self.assertFalse(result["write_operations_validated"])
        self.assertEqual(len(fake.calls), 4)

    def test_doctor_rejects_unexpected_payloads_without_printing_server_text(self):
        for features, privileges in ((["invalid\n" + TOKEN], []),
                                     (["version_control"], [TOKEN]),
                                     (["version_control"], {"privileges": ["ALL PRIVILEGES"]})):
            fake = FakeAPI([(('Features', 'list_features_like'), features),
                            (('Mysql', 'get_privileges_on_database'), privileges)])
            with self.subTest(features=features, privileges=privileges):
                with self.assertRaises(cpanel.DeploymentError) as caught:
                    cpanel.Deployment(fake, self.config()).doctor()
                self.assertNotIn(TOKEN, str(caught.exception))


if __name__ == "__main__":
    unittest.main()
