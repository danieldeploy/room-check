#!/usr/bin/env python3
"""Restricted WHM/cPanel disk inspection and exact-file deletion.

Uses the protected WHM_API_TOKEN from a GitHub Actions environment.
Never accepts tokens in command-line flags. No directory deletion.
"""
import argparse
import json
import os
import re
import ssl
import sys
import urllib.error
import urllib.parse
import urllib.request

HOST = "https://server50.romania-webhosting.com:2087"
RESELLER = "fazenda"
ACCOUNT = "welcome"
# First iteration: only disposable npm cache files, never mail, backups, logs,
# site code or other hidden cache roots without a verified inventory.
SAFE_PREFIX = ".npm/_cacache/"
NAME = re.compile(r"[A-Za-z0-9_./-]{1,300}\\Z")
MAX_BODY = 1048576


class Rejected(Exception):
    pass


def safe_file(path):
    if not isinstance(path, str) or not NAME.fullmatch(path):
        raise Rejected("invalid_path")
    if path.startswith("/") or ".." in path.split("/") or not path.startswith(SAFE_PREFIX):
        raise Rejected("path_not_allowlisted")
    if path.endswith("/"):
        raise Rejected("directories_not_allowed")
    return path


def request_api(module, function, params, *, legacy=False):
    token = os.environ.get("WHM_API_TOKEN", "")
    if not token or any(ord(c) < 33 or ord(c) > 126 for c in token):
        raise Rejected("missing_whm_token")
    if legacy:
        query = {"cpanel_jsonapi_user": ACCOUNT, "cpanel_jsonapi_apiversion": 2,
                 "cpanel_jsonapi_module": module, "cpanel_jsonapi_func": function, **params}
        path = "/json-api/cpanel"
    else:
        query = {"api.version": 1, "cpanel.user": ACCOUNT,
                 "cpanel.module": module, "cpanel.function": function, **params}
        path = "/json-api/uapi_cpanel"
    url = HOST + path + "?" + urllib.parse.urlencode(query)
    req = urllib.request.Request(url, headers={
        "Authorization": "whm " + RESELLER + ":" + token,
        "Accept": "application/json", "Cache-Control": "no-store"})
    opener = urllib.request.build_opener(urllib.request.ProxyHandler({}),
                                        urllib.request.HTTPSHandler(context=ssl.create_default_context()),
                                        NoRedirects())
    try:
        with opener.open(req, timeout=20) as response:
            if response.status != 200 or response.geturl() != url:
                raise Rejected("http_response_unexpected")
            raw = response.read(MAX_BODY + 1)
        if len(raw) > MAX_BODY:
            raise Rejected("response_too_large")
        payload = json.loads(raw)
    except (urllib.error.URLError, ValueError, OSError):
        raise Rejected("api_request_failed") from None
    if legacy:
        result = payload.get("cpanelresult", {})
        if result.get("event", {}).get("result") != 1:
            raise Rejected("legacy_api_failed")
        return result
    if payload.get("metadata", {}).get("result") != 1:
        raise Rejected("whm_api_failed")
    result = payload.get("data", {}).get("uapi", {})
    if result.get("status") != 1 or result.get("errors") not in (None, []):
        raise Rejected("uapi_failed")
    return result.get("data")


class NoRedirects(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        raise Rejected("redirect_refused")


def inspect(path):
    path = safe_file(path)
    # Fileman::get_file_information is read-only and returns file metadata.
    info = request_api("Fileman", "get_file_information", {"path": path})
    if isinstance(info, list) and len(info) == 1:
        info = info[0]
    if not isinstance(info, dict):
        raise Rejected("invalid_file_information")
    if info.get("type") != "file":
        raise Rejected("not_regular_file")
    size = int(info.get("size", -1))
    mtime = int(info.get("mtime", -1))
    if size < 0 or mtime < 0:
        raise Rejected("invalid_file_metadata")
    return {"path": path, "size": size, "mtime": mtime}


def delete_exact(path, expected_size, expected_mtime):
    current = inspect(path)
    if current["size"] != expected_size or current["mtime"] != expected_mtime:
        raise Rejected("file_changed")
    # API 2's 'trash' does not release quota immediately; requires separate
    # safe empty-trash procedure. No command here deletes backups or mail.
    request_api("Fileman", "fileop", {"op": "trash", "sourcefiles": path,
               "doubledecode": 0}, legacy=True)
    return {"state": "moved_to_trash", "bytes": current["size"]}


def main():
    p = argparse.ArgumentParser()
    p.add_argument("action", choices=["inspect", "trash"])
    p.add_argument("--path", required=True)
    p.add_argument("--expected-size", type=int)
    p.add_argument("--expected-mtime", type=int)
    args = p.parse_args()
    try:
        if args.action == "inspect":
            data = inspect(args.path)
        else:
            if args.expected_size is None or args.expected_mtime is None:
                raise Rejected("confirmation_metadata_required")
            data = delete_exact(args.path, args.expected_size, args.expected_mtime)
        print(json.dumps({"ok": True, **data}))
        return 0
    except Rejected as exc:
        print(json.dumps({"ok": False, "error": str(exc)}), file=sys.stderr)
        return 1
    except Exception:
        print('{"ok":false,"error":"unexpected_error"}', file=sys.stderr)
        return 1


if __name__ == "__main__":
    sys.exit(main())
