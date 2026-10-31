#!/usr/bin/env python3
"""Read-only WHM check for the cPanel account shell setting.

Uses only accountsummary/listaccts for the exact account `welcome`. Prints
no account secrets, domains, users, keys or files and never changes shell access.
"""
import json
import os
import ssl
import urllib.error
import urllib.parse
import urllib.request

from cpanel_disk_cleanup import HOST, RESELLER, Rejected

ACCOUNT = "welcome"
ALLOWED_FUNCTIONS = {"accountsummary", "listaccts"}


def classify(shell):
    if not isinstance(shell, str) or not shell.strip():
        return "unknown"
    shell = shell.strip()
    if shell == "/usr/local/cpanel/bin/jailshell":
        return "jailed_shell"
    if shell in ("/usr/local/cpanel/bin/noshell", "/bin/false", "/usr/sbin/nologin", "/sbin/nologin"):
        return "shell_disabled"
    if shell in ("/bin/bash", "/bin/sh", "/bin/zsh", "/bin/dash", "/usr/bin/bash"):
        return "normal_shell"
    return "other_shell_type"


def query_api(method):
    if method not in ALLOWED_FUNCTIONS:
        raise Rejected("whm_function_not_allowlisted")
    token = os.environ.get("WHM_API_TOKEN", "")
    if not token or not all(33 <= ord(ch) <= 126 for ch in token):
        raise Rejected("whm_token_missing")
    fields = ({"api.version": "1", "user": ACCOUNT} if method == "accountsummary"
              else {"api.version": "1", "search": ACCOUNT, "searchtype": "user"})
    url = HOST + "/json-api/" + method + "?" + urllib.parse.urlencode(fields)
    request = urllib.request.Request(url, headers={
        "Authorization": "whm " + RESELLER + ":" + token,
        "Accept": "application/json", "Cache-Control": "no-store"
    })
    opener = urllib.request.build_opener(
        urllib.request.ProxyHandler({}),
        urllib.request.HTTPSHandler(context=ssl.create_default_context()))
    try:
        with opener.open(request, timeout=20) as response:
            if response.status != 200 or response.geturl() != url:
                return {"function": method, "api_status": "unexpected_response"}
            body = response.read(180000)
        return {"function": method, "data": json.loads(body)}
    except (urllib.error.URLError, OSError, ValueError):
        return {"function": method, "api_status": "unavailable"}


def summarize(method, response):
    if response.get("api_status"):
        return {"source": method, "status": response["api_status"]}
    data = response.get("data")
    if not isinstance(data, dict) or data.get("metadata", {}).get("result") != 1:
        return {"source": method, "status": "not_authorized_or_unavailable"}
    accounts = data.get("data", {}).get("acct")
    if isinstance(accounts, dict):
        accounts = [accounts]
    if not isinstance(accounts, list):
        return {"source": method, "status": "no_account_records"}
    matches = [row for row in accounts if isinstance(row, dict) and row.get("user") == ACCOUNT]
    if len(matches) != 1:
        return {"source": method, "status": "no_unique_account_record"}
    item = matches[0]
    return {"source": method, "status": "ok",
            "shell_status": classify(item.get("shell")),
            "shell_field_available": isinstance(item.get("shell"), str),
            "has_shell_field": item.get("hasshell") if item.get("hasshell") in (0, 1, "0", "1") else None}


def main():
    results = [summarize(method, query_api(method)) for method in ("accountsummary", "listaccts")]
    known = [x["shell_status"] for x in results if x.get("shell_status") not in (None, "unknown")]
    report = {"ok": True, "read_only": True, "account": ACCOUNT,
              "methods": results, "shell_status": known[0] if known else "not_verified",
              "ssh_connectivity_tested": False,
              "no_shell_settings_changed": True}
    print(json.dumps(report, sort_keys=True), flush=True)


if __name__ == "__main__":
    main()
