#!/usr/bin/env python3
"""One-off, read-only Meta status probe for the WHM disk WhatsApp template.

Runs only in protected GitHub environment with existing WHM token. Reads the
existing WhatsApp Cloud settings in memory, never logs them, and makes GETs
only to graph.facebook.com. No recipients, messages, HTTP error bodies or
credentials are printed. No mutable Meta actions.
"""
import json
import re
import ssl
import sys
import urllib.error
import urllib.parse
import urllib.request

from cpanel_disk_cleanup import Rejected, request_api

DIR = "/home/welcome/room-check-private"
FILENAME = "whatsapp-secrets.json"
TEMPLATE = "whm_storage_alert_v1"
LANGUAGE = "pt_PT"
VERSION = "v23.0"
NUMERIC = re.compile(r"^[0-9]{8,24}$")
MAX_META_REPLY = 262144


class GraphError(Exception):
    def __init__(self, code):
        super().__init__(code)


class NoRedirects(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        raise GraphError("redirect_refused")


def secrets():
    data = request_api("Fileman", "get_file_content",
                       {"dir": DIR, "file": FILENAME, "to_charset": "utf-8"})
    raw = data.get("content") if isinstance(data, dict) else None
    if not isinstance(raw, str) or len(raw) > 16384:
        raise Rejected("whatsapp_config_unavailable")
    try:
        value = json.loads(raw)
    except ValueError:
        raise Rejected("whatsapp_config_invalid") from None
    if not isinstance(value, dict):
        raise Rejected("whatsapp_config_invalid")
    token = value.get("access_token")
    phone = value.get("phone_number_id")
    if not isinstance(token, str) or not 30 <= len(token) <= 4096:
        raise Rejected("whatsapp_auth_unavailable")
    if not isinstance(phone, str) or not NUMERIC.fullmatch(phone):
        raise Rejected("whatsapp_phone_id_unavailable")
    waba = next((value.get(k) for k in
                 ("waba_id", "whatsapp_business_account_id", "business_account_id")
                 if isinstance(value.get(k), str) and NUMERIC.fullmatch(value[k])), None)
    # The token must be kept only in transient Python memory.
    return token, phone, waba


def graph_get(token, path, params=None):
    if not isinstance(path, str) or not re.fullmatch(r"/[0-9a-z_./-]{1,100}", path):
        raise GraphError("invalid_graph_path")
    query = urllib.parse.urlencode(params or {})
    url = "https://graph.facebook.com/" + VERSION + path
    if query:
        url += "?" + query
    req = urllib.request.Request(url, headers={
        "Authorization": "Bearer " + token,
        "Accept": "application/json",
        "Cache-Control": "no-store",
    })
    opener = urllib.request.build_opener(
        urllib.request.ProxyHandler({}),
        urllib.request.HTTPSHandler(context=ssl.create_default_context()), NoRedirects())
    try:
        with opener.open(req, timeout=16) as response:
            if response.status != 200 or response.geturl() != url:
                raise GraphError("unexpected_graph_response")
            raw = response.read(MAX_META_REPLY + 1)
    except urllib.error.HTTPError as error:
        # Do not read or print the response body: it may contain account data.
        code = error.code
        raise GraphError("graph_http_" + str(code if code in (400, 401, 403, 404, 429) else "other")) from None
    except (urllib.error.URLError, OSError, ValueError):
        raise GraphError("graph_connection_unavailable") from None
    if len(raw) > MAX_META_REPLY:
        raise GraphError("graph_response_too_large")
    try:
        obj = json.loads(raw)
    except ValueError:
        raise GraphError("graph_invalid_json") from None
    if not isinstance(obj, dict):
        raise GraphError("graph_invalid_data")
    if "error" in obj:
        raise GraphError("graph_reported_error")
    return obj


def select_waba(token, phone, configured):
    if configured is not None:
        # Validate that the WABA actually owns the configured sending phone.
        try:
            numbers = graph_get(token, "/" + configured + "/phone_numbers",
                                {"fields": "id", "limit": "100"})
            if any(isinstance(x, dict) and x.get("id") == phone
                   for x in numbers.get("data", [])):
                return configured, "configured_verified"
        except GraphError:
            return None, "configured_waba_unverified"
        return None, "configured_waba_phone_mismatch"

    # The existing secret file may only have phone_number_id. First try the
    # phone's parent relation, then a read-only owned account enumeration.
    try:
        result = graph_get(token, "/" + phone,
                           {"fields": "whatsapp_business_account"})
        value = result.get("whatsapp_business_account")
        waba = value.get("id") if isinstance(value, dict) else value
        if isinstance(waba, str) and NUMERIC.fullmatch(waba):
            return waba, "phone_relation"
    except GraphError:
        pass
    try:
        result = graph_get(token, "/me/whatsapp_business_accounts",
                           {"fields": "id", "limit": "50"})
        candidates = result.get("data", [])
        if not isinstance(candidates, list) or len(candidates) > 50:
            return None, "account_enumeration_invalid"
        for c in candidates:
            waba = c.get("id") if isinstance(c, dict) else None
            if not isinstance(waba, str) or not NUMERIC.fullmatch(waba):
                continue
            try:
                numbers = graph_get(token, "/" + waba + "/phone_numbers",
                                    {"fields": "id", "limit": "100"})
                if any(isinstance(p, dict) and p.get("id") == phone
                       for p in numbers.get("data", [])):
                    return waba, "enumerated_verified"
            except GraphError:
                continue
    except GraphError:
        return None, "waba_id_unavailable"
    return None, "waba_not_found_for_phone"


def template_status(token, waba):
    response = graph_get(token, "/" + waba + "/message_templates",
                         {"name": TEMPLATE,
                          "fields": "name,language,status,category,components",
                          "limit": "30"})
    items = response.get("data")
    if not isinstance(items, list) or len(items) > 30:
        raise GraphError("unexpected_template_list")
    matching = [x for x in items if isinstance(x, dict)
                and x.get("name") == TEMPLATE and x.get("language") == LANGUAGE]
    if len(matching) > 1:
        raise GraphError("ambiguous_template")
    if not matching:
        return {"meta_status": "NOT_FOUND", "sendable": False}
    item = matching[0]
    status = item.get("status")
    if not isinstance(status, str) or not re.fullmatch(r"[A-Z_]{2,40}", status):
        raise GraphError("unknown_meta_status")
    category = item.get("category")
    body = [c.get("text", "") for c in item.get("components", [])
            if isinstance(c, dict) and c.get("type") == "BODY"]
    value = body[0] if len(body) == 1 and isinstance(body[0], str) else ""
    placeholders = set(re.findall(r"\{\{([1-9][0-9]?)\}\}", value))
    correct = placeholders == {"1", "2", "3"} and category == "UTILITY"
    return {"meta_status": status, "contract_matches": correct,
            "sendable": status == "APPROVED" and correct}


def main():
    token, phone, configured_waba = secrets()
    waba, source = select_waba(token, phone, configured_waba)
    if waba is None:
        print(json.dumps({"ok": False, "read_only": True, "template": TEMPLATE,
                          "locale": LANGUAGE, "status": "UNVERIFIED",
                          "reason": source}, sort_keys=True))
        return 0
    try:
        status = template_status(token, waba)
        print(json.dumps({"ok": True, "read_only": True, "template": TEMPLATE,
                          "locale": LANGUAGE, "waba_verified_for_phone": True,
                          **status}, sort_keys=True))
    except GraphError as error:
        print(json.dumps({"ok": False, "read_only": True, "template": TEMPLATE,
                          "locale": LANGUAGE, "status": "UNVERIFIED",
                          "reason": str(error)}, sort_keys=True))
    return 0


if __name__ == "__main__":
    try:
        sys.exit(main())
    except Rejected as error:
        print(json.dumps({"ok": False, "read_only": True,
                          "status": "UNVERIFIED", "reason": str(error)}), flush=True)
        sys.exit(1)
    except Exception:
        print('{"ok":false,"read_only":true,"status":"UNVERIFIED","reason":"probe_unavailable"}', flush=True)
        sys.exit(1)
