import json
import os
import pathlib
import sys
import unittest
from email.message import EmailMessage
from unittest.mock import patch
import urllib.parse

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parents[1] / "deploy"))
import cloudbeds_mail_inspect as c


class Tests(unittest.TestCase):
    def test_native_trace_diagnostic_is_allowlisted_and_does_not_expose_smtp_metadata(self):
        m=EmailMessage();m.set_content('Invoice attached')
        m.add_attachment(b'%PDF-1.4 synthetic',maintype='application',subtype='pdf')
        facts={'native_trace':{'welcome':{'stage':'first_hop','usable':False,'ip':'PRIVATE_IP','mailfrom':'SECRET'},
                              'city':{'stage':'PRIVATE_STAGE','spf':'PRIVATE_RESULT','usable':False}}}
        result=type('Result',(),{'stdout':json.dumps(facts).encode()})()
        with patch.object(c.subprocess,'run',return_value=result):
            report=c.shape(m.as_bytes())
        self.assertEqual(report['authentication']['native_trace_welcome'],{'stage':'first_hop','usable':False})
        self.assertEqual(report['authentication']['native_trace_city'],{'usable':False})
        self.assertNotIn('PRIVATE',json.dumps(report));self.assertNotIn('SECRET',json.dumps(report))

    def test_receipt_shape_does_not_return_private_content(self):
        m = EmailMessage()
        m["From"] = "Private Name <billing@cloudbeds.com>"
        m["To"] = "private-person@example.test"
        m["Subject"] = "Payment receipt PRIVATE_INVOICE_123"
        m.set_content("Thank you for your payment. Active Lines. PRIVATE_AMOUNT_777\n"
                      "https://billing.cloudbeds.com/portal/PRIVATE_ACCOUNT?token=SECRET_TOKEN\n")
        result = c.shape(m.as_bytes())
        self.assertTrue(result["payment_confirmation"])
        self.assertTrue(result["company_mentioned"])
        self.assertEqual(result["mime"]["pdf"], 0)
        self.assertEqual(result["links"][0]["provider"], "cloudbeds.com")
        out = json.dumps(result)
        for forbidden in ("PRIVATE", "SECRET", "private-person", "billing@", "https://", "token", "777"):
            self.assertNotIn(forbidden, out)

    def test_pdf_attachment_is_distinguished_from_receipt_wording(self):
        m = EmailMessage()
        m["From"] = "billing@cloudbeds.com.evil.test"
        m["Subject"] = "Invoice TEST123"
        m.set_content("Your invoice is attached.")
        m.add_attachment(b"%PDF-1.4\nsynthetic only", maintype="application", subtype="pdf", filename="PRIVATE.pdf")
        result = c.shape(m.as_bytes())
        self.assertEqual(result["sender_provider"], "other")
        self.assertEqual(result["mime"]["pdf"], 1)
        self.assertEqual(result["pdf_signature_count"], 1)
        self.assertTrue(result["invoice_attached_wording"])
        self.assertFalse(result["payment_confirmation"])

    def test_link_shape_contains_only_fixed_markers(self):
        m = EmailMessage()
        m.set_content('<a href="https://PRIVATE.example.test/invoice/SECRET.pdf?access=TOKEN#SECRET">Download</a>', subtype="html")
        result = c.shape(m.as_bytes())
        self.assertEqual(result["links"], [{"provider": "other", "path_markers": ["invoice"],
                                          "pdf_path": True, "has_query": True, "has_fragment": True}])
        self.assertNotIn("SECRET", json.dumps(result))

    def test_restricted_paths_and_read_only_operations(self):
        with patch.dict(os.environ, {"WHM_API_TOKEN": "synthetic-test-only"}):
            api = c.MailAPI("welcome")
        with patch.object(api.opener, "open") as opened:
            cases = [("save_file_content", {}),
                     ("get_file_content", {"dir": api.root, "file": "master.key"}),
                     ("list_files", {"dir": c.ROOTS["city"], "show_hidden": 1, "types": "file|dir"}),
                     ("get_file_information", {"path": api.root + "/../other"})]
            for operation, params in cases:
                with self.assertRaises(c.DeploymentError):
                    api.call(operation, **params)
            opened.assert_not_called()
        for name in ("../message", "/etc/passwd", "x\\y", "bad\nname", ".", ".."):
            with self.assertRaises(c.DeploymentError):
                c.child_name({"file": name})

    def test_oversize_and_symlinks_fail_closed(self):
        with self.assertRaises(c.DeploymentError):
            c.shape(b"x" * (c.MAX_MAIL + 1))
        with patch.dict(os.environ, {"WHM_API_TOKEN": "synthetic-test-only"}):
            api = c.MailAPI("city")
        with patch.object(api, "call", return_value=[{"type": "link"}]) as call:
            with self.assertRaises(c.DeploymentError):
                api.listing(api.root)
            self.assertEqual(call.call_count, 1)
            self.assertEqual(call.call_args.kwargs, {"path": api.root, "show_hidden": 1})

    def test_failure_diagnostics_do_not_echo_server_data(self):
        result = c.error_facts(['Missing parameter dir. PRIVATE_FILENAME SECRET_TOKEN'])
        self.assertTrue(result["parameter"])
        self.assertTrue(result["mentions_dir"])
        self.assertNotIn("PRIVATE", json.dumps(result))
        self.assertNotIn("SECRET", json.dumps(result))

    def test_mail_read_explicitly_disables_html_rewriting(self):
        with patch.dict(os.environ, {"WHM_API_TOKEN": "synthetic-test-only"}):
            api = c.MailAPI("welcome")
        params = {"dir": api.root+"/.Cloudbeds/cur", "file": "synthetic",
                  "from_charset": "ISO-8859-1", "to_charset": "UTF-8"}
        api.allowed_files.add((params["dir"], params["file"]))
        with patch.object(api.opener, "open") as opened:
            with self.assertRaises(c.DeploymentError): api.call("get_file_content", **params)
            with self.assertRaises(c.DeploymentError): api.call("get_file_content", **params, update_html_document_encoding=1)
            opened.assert_not_called()
            opened.return_value.__enter__.return_value.read.return_value = json.dumps({
                "metadata": {"result": 1}, "data": {"uapi": {"status": 1, "data": {"content": "synthetic"}}}}).encode()
            api.call("get_file_content", **params, update_html_document_encoding=0)
            body = urllib.parse.parse_qs(opened.call_args.args[0].data.decode())
            self.assertEqual(body["update_html_document_encoding"], ["0"])

    def test_age_classification_is_bounded_and_content_free(self):
        self.assertTrue(c.outside_window("Tue, 01 Jan 2019 00:00:00 +0000"))
        self.assertFalse(c.outside_window("PRIVATE_INVALID_DATE"))
        self.assertFalse(c.outside_window(None))

    def test_pdf_report_never_contains_invoice_values(self):
        result = c.pdf_text_shape("Cloudbeds Invoice A1234567\nInvoice Date: 09/15/2026\n"
                                  "Bill To: Active Lines PT123456789\nPRIVATE_NAME 999.99\n"
                                  "Due Date: 10/15/2026\n")
        self.assertTrue(result["active_lines"])
        self.assertTrue(result["invoice_number_after_label"])
        self.assertEqual(result["dates"]["invoice_date"]["following_formats"], ["numeric_slash"])
        for forbidden in ("1234567", "123456789", "2026", "999.99", "PRIVATE_NAME"):
            self.assertNotIn(forbidden, json.dumps(result))

    def test_spf_diagnostic_distinguishes_aligned_domain_from_shared_provider(self):
        m = EmailMessage()
        m['From'] = 'billing@cloudbeds.com'
        m['Received'] = 'from sender.example.test ([192.0.2.15]) by server50.romania-webhosting.com with esmtps; PRIVATE_DATE'
        m['Received-SPF'] = 'pass (PRIVATE_COMMENT); receiver=server50.romania-webhosting.com; client-ip=192.0.2.15; envelope-from="PRIVATE_TOKEN@bounce.cloudbeds.com";'
        m['Authentication-Results'] = 'server50.romania-webhosting.com; spf=pass smtp.mailfrom=PRIVATE_TOKEN@amazonses.com; dmarc=fail header.from=cloudbeds.com'
        result = c.reception_auth_shape(m)
        self.assertTrue(result['received_spf'][0]['cloudbeds_aligned'])
        self.assertTrue(result['received_spf'][0]['client_ip_in_latest_received'])
        self.assertTrue(result['received'][0]['by_hosting'])
        self.assertFalse(result['authentication_results'][0]['cloudbeds_aligned'])
        self.assertEqual(result['authentication_results'][0]['envelope_provider'], 'amazonses.com')
        self.assertEqual(result['authentication_results'][0]['dmarc'], 'fail')
        for forbidden in ('PRIVATE', '192.0.2.15', 'billing@', 'sender.example.test', 'server50.romania-webhosting.com'):
            self.assertNotIn(forbidden, json.dumps(result))

    def test_spf_diagnostic_records_missing_and_untrusted_claims_without_authenticating(self):
        m = EmailMessage()
        m['From'] = 'billing@cloudbeds.com.evil.test'
        m['Authentication-Results'] = 'forged.example.test; spf=pass smtp.mailfrom=x@cloudbeds.com; dmarc=pass'
        m['Received-SPF'] = 'pass; envelope-from=x@cloudbeds.com.evil.test'
        result = c.reception_auth_shape(m)
        self.assertFalse(result['authentication_results'][0]['authserv_hosting'])
        self.assertFalse(result['authentication_results'][0]['cloudbeds_aligned'])
        self.assertFalse(result['received_spf'][0]['cloudbeds_aligned'])
        self.assertFalse(result['received_spf'][0]['receiver_hosting'])
        self.assertEqual(c.reception_auth_shape(EmailMessage())['authentication_results'], [])
        self.assertNotIn('verified', result)

    def test_spf_diagnostic_redacts_unknown_header_values_and_bounds_header_lists(self):
        m = EmailMessage()
        m['From'] = 'billing@cloudbeds.com'
        for _ in range(8):
            m['Received-SPF'] = 'PRIVATE_RESULT; receiver=PRIVATE_HOST; envelope-from=PRIVATE_TOKEN@private.example.test'
            m['X-PRIVATE-HEADER'] = 'PRIVATE_BODY'
        result = c.reception_auth_shape(m)
        self.assertEqual(result['received_spf_count'], 8)
        self.assertEqual(len(result['received_spf']), 5)
        self.assertEqual(result['received_spf'][0]['result'], 'absent')
        self.assertNotIn('PRIVATE', json.dumps(result))
        self.assertNotIn('private.example.test', json.dumps(result))

    def test_current_spf_probe_uses_envelope_and_original_smtp_peer_without_leaking(self):
        m = EmailMessage()
        m['Return-Path'] = '<PRIVATE_TOKEN@bounce.cloudbeds.com>'
        m['Received'] = 'from welcome by server50.romania-webhosting.com with local; PRIVATE_DATE'
        m['Received'] = ('from smtp.example.test ([192.0.2.19]:1234) by server50.romania-webhosting.com '
                         'with esmtps (envelope-from <PRIVATE_TOKEN@bounce.cloudbeds.com>); PRIVATE_DATE')
        calls = []
        def evaluate(*args):
            calls.append(args)
            return 'pass'
        result = c.current_spf_shape(m, evaluate)
        self.assertEqual(calls, [('192.0.2.19', 'PRIVATE_TOKEN@bounce.cloudbeds.com', 'smtp.example.test')])
        self.assertEqual(result['result'], 'pass')
        self.assertTrue(result['trace_matches_return_path'])
        self.assertFalse(result['historical_authentication'])
        for forbidden in ('PRIVATE', 'private_token', '192.0.2.19', 'smtp.example.test'):
            self.assertNotIn(forbidden, json.dumps(result))

    def test_current_spf_probe_does_not_query_unaligned_or_conflicting_envelopes(self):
        for envelope, path in [('x@amazonses.com', 'x@amazonses.com'),
                               ('x@cloudbeds.com.evil.test', 'x@cloudbeds.com.evil.test'),
                               ('x@cloudbeds.com', 'y@cloudbeds.com')]:
            m = EmailMessage()
            m['Return-Path'] = '<' + path + '>'
            m['Received'] = ('from smtp.example.test ([192.0.2.19]) by receiver.example.test '
                             'with esmtps (envelope-from <' + envelope + '>); PRIVATE_DATE')
            with self.subTest(envelope=envelope), patch.object(c.subprocess, 'run') as called:
                self.assertEqual(c.current_spf_shape(m)['result'], 'not_aligned')
                called.assert_not_called()

    def test_current_spf_probe_bounds_subprocess_and_never_reports_explanations(self):
        m = EmailMessage()
        m['Return-Path'] = '<x@cloudbeds.com>'
        m['Received'] = 'from peer.example.test ([2001:db8::1]) by receiver.example.test with esmtp (envelope-from <x@cloudbeds.com>); PRIVATE_DATE'
        with patch.object(c.subprocess, 'run') as called:
            called.return_value.stdout = b'PRIVATE_EXPLANATION'
            result = c.current_spf_shape(m)
            self.assertEqual(result['result'], 'unavailable')
            self.assertEqual(called.call_args.kwargs['timeout'], 8)
            self.assertNotIn('PRIVATE', json.dumps(result))

        with patch.object(c, 'SPF_PROBE_SECONDS', 90), patch.object(c.subprocess, 'run') as called:
            self.assertEqual(c.current_spf_shape(m)['result'], 'budget_exhausted')
            called.assert_not_called()


if __name__ == "__main__":
    unittest.main()
