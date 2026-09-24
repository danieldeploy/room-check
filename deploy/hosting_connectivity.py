#!/usr/bin/env python3
"""Check the fixed WHM origin without credentials or response content.

Intended for Linux CI runners. A process-wide alarm bounds DNS resolution too;
socket timeouts alone cannot bound a blocked system resolver. No redirects,
environment proxies, authentication, or user-selected destinations are used.
"""

import http.client
import json
import signal
import socket
import ssl
import sys
import time
from urllib.parse import urlsplit

from cpanel_api import WHM_ORIGIN


TIMEOUT_SECONDS = 20
STAGES = ("dns", "tcp", "tls", "https")


def expired(_signum, _frame):
    raise TimeoutError("connectivity_timeout")


def probe():
    """Return only fixed status labels; never include server or exception text."""
    result = {stage: "not_run" for stage in STAGES}
    result["reachable"] = False
    stage = "dns"
    connection = None
    response = None
    deadline = time.monotonic() + TIMEOUT_SECONDS

    def remaining():
        value = deadline - time.monotonic()
        if value <= 0:
            raise TimeoutError("connectivity_timeout")
        return value

    try:
        target = urlsplit(WHM_ORIGIN)
        addresses = socket.getaddrinfo(
            target.hostname, target.port, type=socket.SOCK_STREAM,
            proto=socket.IPPROTO_TCP,
        )
        if not addresses:
            raise OSError("dns_no_result")
        result[stage] = "pass"
        stage = "tcp"
        for family, kind, proto, _, address in addresses:
            attempt = socket.socket(family, kind, proto)
            try:
                attempt.settimeout(min(5, remaining()))
                attempt.connect(address)
            except OSError:
                attempt.close()
                remaining()
                continue
            connection = attempt
            break
        if connection is None:
            raise OSError("tcp_unavailable")
        result[stage] = "pass"
        stage = "tls"
        connection.settimeout(remaining())
        connection = ssl.create_default_context().wrap_socket(
            connection, server_hostname=target.hostname,
        )
        result[stage] = "pass"
        stage = "https"
        connection.settimeout(remaining())
        connection.sendall((
            "HEAD / HTTP/1.1\r\n"
            "Host: " + target.netloc + "\r\n"
            "User-Agent: Management-Hub-Connectivity/1\r\n"
            "Connection: close\r\n\r\n"
        ).encode("ascii"))
        response = http.client.HTTPResponse(connection)
        response.begin()
        # Redirects and authentication requirements prove HTTPS connectivity,
        # but do not prove API authorization. Never follow a Location header.
        if not 200 <= response.status <= 599:
            raise http.client.HTTPException("invalid_http_status")
        result[stage] = "pass"
        result["reachable"] = True
    except (OSError, ValueError, http.client.HTTPException):
        result[stage] = "fail"
    finally:
        if response is not None:
            response.close()
        if connection is not None:
            connection.close()
    return result


def main():
    if not hasattr(signal, "setitimer"):
        print(json.dumps({"reachable": False, "error": "linux_runner_required"}))
        return 1
    old_handler = signal.signal(signal.SIGALRM, expired)
    signal.setitimer(signal.ITIMER_REAL, TIMEOUT_SECONDS)
    try:
        result = probe()
    except OSError:
        result = {"reachable": False, "error": "connectivity_timeout"}
    finally:
        signal.setitimer(signal.ITIMER_REAL, 0)
        signal.signal(signal.SIGALRM, old_handler)
    print(json.dumps(result, sort_keys=True))
    return 0 if result["reachable"] else 1


if __name__ == "__main__":
    sys.exit(main())
