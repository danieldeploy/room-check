import contextlib
import io
import json
import pathlib
import sys
import unittest
from unittest import mock

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parents[1] / "deploy"))
import whm_meta_template_probe_once as probe


class MetaTemplateProbeTests(unittest.TestCase):
    def test_exact_private_secret_only_and_never_print_token(self):
        sensitive = {"phone_number_id": "123456789012345",
                     "access_token": "ABC" * 30,
                     "waba_id": "987654321012345"}
        with mock.patch.object(probe, "request_api",
                               return_value={"content": json.dumps(sensitive)}) as api:
            token, phone, waba = probe.secrets()
        self.assertEqual(phone, sensitive["phone_number_id"])
        self.assertEqual(waba, sensitive["waba_id"])
        self.assertEqual(token, sensitive["access_token"])
        api.assert_called_once_with("Fileman", "get_file_content", {
            "dir": "/home/welcome/room-check-private",
            "file": "whatsapp-secrets.json", "to_charset": "utf-8"})

    def test_approved_utility_three_parameter_contract(self):
        token, account = "private-token", "987654321012345"
        data = {"data": [{"name": probe.TEMPLATE, "language": "pt_PT",
                           "status": "APPROVED", "category": "UTILITY",
                           "components": [{"type": "BODY", "text":
                                           "a {{1}} b {{2}} c {{3}}"}]}]}
        with mock.patch.object(probe, "graph_get", return_value=data) as graph:
            actual = probe.template_status(token, account)
        self.assertEqual(actual, {
            "meta_status": "APPROVED", "contract_matches": True, "sendable": True})
        self.assertEqual(graph.call_args.args[1], "/" + account + "/message_templates")
        self.assertEqual(graph.call_args.args[2]["name"], probe.TEMPLATE)

    def test_template_not_found(self):
        with mock.patch.object(probe, "graph_get", return_value={"data": []}):
            actual = probe.template_status("token", "987654321012345")
        self.assertEqual(actual["meta_status"], "NOT_FOUND")
        self.assertFalse(actual["sendable"])

    def test_pending_not_sendable(self):
        data = {"data": [{"name": probe.TEMPLATE, "language": "pt_PT",
                           "status": "PENDING", "category": "UTILITY",
                           "components": [{"type": "BODY",
                                           "text": "{{1}} {{2}} {{3}}"}]}]}
        with mock.patch.object(probe, "graph_get", return_value=data):
            self.assertFalse(probe.template_status("token", "987654321012345")["sendable"])

    def test_approved_wrong_parameters_not_sendable(self):
        data = {"data": [{"name": probe.TEMPLATE, "language": "pt_PT",
                           "status": "APPROVED", "category": "UTILITY",
                           "components": [{"type": "BODY", "text":"{{1}} {{2}}"}]}]}
        with mock.patch.object(probe, "graph_get", return_value=data):
            out = probe.template_status("token", "987654321012345")
        self.assertFalse(out["contract_matches"])
        self.assertFalse(out["sendable"])

    def test_unverified_waba_fails_closed(self):
        with mock.patch.object(probe, "graph_get", side_effect=probe.GraphError("graph_http_403")):
            waba, reason = probe.select_waba("token", "123456789012345", None)
        self.assertIsNone(waba)
        self.assertEqual(reason, "waba_id_unavailable")

    def test_main_never_prints_phone_or_token(self):
        private = ("PRIVATE" * 12, "123456789012345", "987654321012345")
        with mock.patch.object(probe, "secrets", return_value=private), \
             mock.patch.object(probe, "select_waba",
                               return_value=("987654321012345", "configured_verified")), \
             mock.patch.object(probe, "template_status",
                               return_value={"meta_status":"APPROVED","sendable": True,
                                             "contract_matches":True}), \
             contextlib.redirect_stdout(io.StringIO()) as out:
            result = probe.main()
        self.assertEqual(result, 0)
        self.assertTrue(json.loads(out.getvalue())["sendable"])
        self.assertNotIn(private[0], out.getvalue())
        self.assertNotIn(private[1], out.getvalue())
        self.assertNotIn(private[2], out.getvalue())


if __name__ == "__main__":
    unittest.main()
