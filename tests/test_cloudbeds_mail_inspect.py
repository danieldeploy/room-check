import json
import os
import pathlib
import sys
import unittest
from email.message import EmailMessage
from unittest.mock import patch

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parents[1] / "deploy"))
import cloudbeds_mail_inspect as c


class Tests(unittest.TestCase):
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


if __name__ == "__main__":
    unittest.main()
