import contextlib
import io
import json
import pathlib
import socket
import ssl
import sys
import unittest
from unittest.mock import MagicMock, patch


sys.path.insert(0, str(pathlib.Path(__file__).resolve().parents[1] / "deploy"))
import hosting_connectivity as connectivity


class ConnectivityTests(unittest.TestCase):
    def setUp(self):
        self.addresses = [(socket.AF_INET, socket.SOCK_STREAM, socket.IPPROTO_TCP,
                           "", ("192.0.2.1", 2087))]
        self.dns = patch.object(connectivity.socket, "getaddrinfo", return_value=self.addresses).start()
        self.socket_factory = patch.object(connectivity.socket, "socket").start()
        self.plain = self.socket_factory.return_value
        self.tls_factory = patch.object(connectivity.ssl, "create_default_context").start()
        self.secure = self.tls_factory.return_value.wrap_socket.return_value
        self.http_factory = patch.object(connectivity.http.client, "HTTPResponse").start()
        self.http = self.http_factory.return_value
        self.http.status = 200
        self.addCleanup(patch.stopall)

    def test_fixed_origin_no_credentials_and_no_response_body(self):
        result = connectivity.probe()
        self.assertTrue(result["reachable"])
        self.dns.assert_called_once_with(
            "server50.romania-webhosting.com", 2087,
            type=socket.SOCK_STREAM, proto=socket.IPPROTO_TCP,
        )
        self.tls_factory.assert_called_once_with()
        self.tls_factory.return_value.wrap_socket.assert_called_once_with(
            self.plain, server_hostname="server50.romania-webhosting.com",
        )
        request = self.secure.sendall.call_args.args[0]
        self.assertTrue(request.startswith(b"HEAD / HTTP/1.1\r\n"))
        self.assertNotIn(b"Authorization", request)
        self.http.read.assert_not_called()
        self.assertNotIn("192.0.2.1", json.dumps(result))
        self.secure.close.assert_called_once()

    def test_authentication_statuses_and_redirects_are_network_success(self):
        for status in (301, 302, 401, 403, 500):
            with self.subTest(status=status):
                self.secure.reset_mock()
                self.http.status = status
                self.assertTrue(connectivity.probe()["reachable"])
                self.secure.sendall.assert_called_once()
                self.http.getheader.assert_not_called()

    def test_dns_failure_is_sanitized_and_stops(self):
        self.dns.side_effect = socket.gaierror("private resolver response")
        self.assertEqual(connectivity.probe(), {
            "dns": "fail", "tcp": "not_run", "tls": "not_run",
            "https": "not_run", "reachable": False,
        })
        self.socket_factory.assert_not_called()

    def test_tcp_failure_stops_before_tls(self):
        self.plain.connect.side_effect = TimeoutError("private address")
        result = connectivity.probe()
        self.assertEqual(result["tcp"], "fail")
        self.assertFalse(result["reachable"])
        self.tls_factory.assert_not_called()
        self.plain.close.assert_called_once()

    def test_certificate_rejection_is_not_bypassed(self):
        self.tls_factory.return_value.wrap_socket.side_effect = ssl.SSLCertVerificationError("secret error")
        result = connectivity.probe()
        self.assertEqual(result["tls"], "fail")
        self.assertEqual(result["https"], "not_run")
        self.http_factory.assert_not_called()
        self.plain.close.assert_called_once()

    def test_malformed_http_does_not_leak_response(self):
        self.http.begin.side_effect = connectivity.http.client.BadStatusLine("private content")
        result = connectivity.probe()
        self.assertEqual(result["https"], "fail")
        self.assertNotIn("private", json.dumps(result))

    def test_process_deadline_is_set_and_cleaned_up(self):
        with patch.object(connectivity.signal, "signal") as handler, \
                patch.object(connectivity.signal, "setitimer") as timer, \
                patch.object(connectivity, "probe", side_effect=TimeoutError("secret error")), \
                contextlib.redirect_stdout(io.StringIO()) as output:
            self.assertEqual(connectivity.main(), 1)
        timer.assert_any_call(connectivity.signal.ITIMER_REAL, connectivity.TIMEOUT_SECONDS)
        timer.assert_any_call(connectivity.signal.ITIMER_REAL, 0)
        self.assertEqual(handler.call_count, 2)
        self.assertEqual(json.loads(output.getvalue()), {
            "reachable": False, "error": "connectivity_timeout",
        })


if __name__ == "__main__":
    unittest.main()
