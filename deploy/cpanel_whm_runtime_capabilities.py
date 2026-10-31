#!/usr/bin/env python3
"""Read-only WHM reseller capability and actual cPanel cron script audit.

No arbitrary shell, no writes, no mail, no env/config/credential files,
no private command contents in output. Exact WHM API 1 applist and
scoped Fileman::get_file_content are the only additional calls.
"""
import json
import os
import re
import ssl
import urllib.error
import urllib.request

from cpanel_disk_cleanup import Rejected, request_api, HOST, RESELLER
from cpanel_headless_dependency_audit import browser_inventory
from time import time

HOME = "/home/welcome/"
SCRIPT = re.compile(
    r"(?<![A-Za-z0-9_])(/home/welcome/(?:room-check-private/(?:cron|invoice-runner)|"
    r"repositories/room-check/(?:cron|invoice-runner)|"
    r"public_html/room-check-private/cron)/"
    r"[A-Za-z0-9_.-]+(?:/[A-Za-z0-9_.-]+){0,2}\.(?:php|py|sh|mjs|js))\b")
PATTERN = re.compile(r"chrome-headless-shell|headless:\s*['\"]?shell|executablepath|puppeteer|chrome", re.I)
CONFIG_PATH = re.compile(r"(?:\.env|password|secret|credential|token|cookie|auth|session|config|key)", re.I)
MAX_CRONS = 40
MAX_SCRIPTS = 18
MAX_CONTENT = 300000


def whm_applist():
    token = os.environ.get("WHM_API_TOKEN", "")
    if not token or not all(33 <= ord(ch) <= 126 for ch in token):
        raise Rejected("missing_whm_token")
    url = HOST + "/json-api/applist?api.version=1"
    req = urllib.request.Request(url, headers={
        "Authorization": "whm " + RESELLER + ":" + token,
        "Accept": "application/json",
        "Cache-Control": "no-store"
    })
    op = urllib.request.build_opener(urllib.request.ProxyHandler({}),
                                     urllib.request.HTTPSHandler(context=ssl.create_default_context()))
    try:
        with op.open(req, timeout=20) as res:
            if res.status != 200 or res.geturl() != url:
                raise Rejected("whm_applist_bad_status")
            raw = res.read(900000)
        obj = json.loads(raw)
        if obj.get("metadata", {}).get("result") != 1:
            return {"status": "unauthorized_or_unavailable"}
        # Only public function identifiers; never return the full response.
        funcs = set()
        def visit(value, depth=0):
            if depth > 5: return
            if isinstance(value, dict):
                for key, item in value.items():
                    if key in ("function", "name") and isinstance(item, str) and re.fullmatch(r"[a-z][a-z0-9_]{2,70}", item):
                        funcs.add(item)
                    elif key in ("data", "functions", "function", "applist", "available"):
                        visit(item, depth+1)
            elif isinstance(value, list):
                for item in value[:2500]:
                    visit(item, depth+1)
        visit(obj.get("data", {}))
        matches = sorted(x for x in funcs if re.search(r"proc|process|user.*stat|list.*running|^ps$", x))
        return {"status": "available", "function_count": len(funcs),
                "process_related_api_names": matches[:25],
                "process_snapshot_obtained": False}
    except (urllib.error.URLError, OSError, ValueError) as ex:
        return {"status": "request_unavailable"}


def script_paths_from_crons():
    result = request_api("Cron", "listcron", {}, legacy=True)
    rows = result.get("data")
    if not isinstance(rows, list) or len(rows) > MAX_CRONS:
        raise Rejected("invalid_cron_list")
    paths = set()
    containing_shell = containing_version = 0
    for row in rows:
        cmd = row.get("command")
        if not isinstance(cmd, str):
            raise Rejected("invalid_cron_entry")
        containing_shell += int("chrome-headless-shell" in cmd.lower())
        containing_version += int("148.0.7778.97" in cmd or "153.0.8010.36" in cmd)
        for match in SCRIPT.finditer(cmd):
            path = match.group(1)
            if not CONFIG_PATH.search(path):
                paths.add(path)
    if len(paths) > MAX_SCRIPTS:
        raise Rejected("too_many_cron_scripts")
    return {"crons": len(rows), "cron_direct_headless_refs": containing_shell,
            "cron_direct_version_refs": containing_version,
            "scripts_discovered": len(paths)}, sorted(paths)


def safe_read_script(path):
    if not SCRIPT.fullmatch(path) or CONFIG_PATH.search(path):
        raise Rejected("script_outside_allowlist")
    dirname, base = path.rsplit("/", 1)
    result = request_api("Fileman", "get_file_content", {"dir": dirname, "file": base})
    # cPanel versions may return either a string or a dictionary.
    if isinstance(result, str):
        content = result
        shape = "string"
    elif isinstance(result, dict):
        shape = "object"
        content = next((result[k] for k in ("file_content", "content", "data") if isinstance(result.get(k), str)), None)
    else:
        shape = type(result).__name__
        content = None
    if content is None:
        return {"status": "response_schema_unrecognized", "shape": shape,
                "keys": sorted(result.keys())[:12] if isinstance(result, dict) else []}
    if len(content) > MAX_CONTENT:
        return {"status": "script_too_large"}
    hits = sorted(set(m.group().lower() for m in PATTERN.finditer(content)))
    return {"status": "read", "has_headless_shell": bool(re.search(r"chrome-headless-shell|headless:\s*['\"]?shell",content,re.I)),
            "has_chrome": "chrome" in content.lower(),
            "has_puppeteer": "puppeteer" in content.lower(),
            "has_executable_path_override": bool(re.search(r"executablepath",content,re.I)),
            "has_nested_includes": bool(re.search(r"\b(?:include|require|import|source)\b",content,re.I))}


def main():
    result={"ok":True,"read_only":True,"whm_api_capabilities":whm_applist()}
    commands, paths=script_paths_from_crons()
    result["cron"]=commands
    stats={"scripts_read":0,"scripts_unreadable":0,"scripts_schema_unknown":0,
           "scripts_with_headless_ref":0,"scripts_with_chrome_ref":0,
           "scripts_with_puppeteer_ref":0,"scripts_with_executable_override":0,
           "scripts_with_nested_include":0}
    for path in paths:
        try:
            audit=safe_read_script(path)
        except Rejected:
            stats["scripts_unreadable"]+=1
            continue
        if audit["status"] != "read":
            stats["scripts_schema_unknown"]+=1
            if audit["status"] == "response_schema_unrecognized":
                result.setdefault("file_content_response_shapes",[]).append({"shape":audit["shape"],"keys":audit["keys"]})
            continue
        stats["scripts_read"]+=1
        for key, field in (("scripts_with_headless_ref","has_headless_shell"),
                           ("scripts_with_chrome_ref","has_chrome"),
                           ("scripts_with_puppeteer_ref","has_puppeteer"),
                           ("scripts_with_executable_override","has_executable_path_override"),
                           ("scripts_with_nested_include","has_nested_includes")):
            stats[key]+=int(audit[field])
    result["cron_script_inspection"]=stats
    result["linux_executing_processes_observed"]=False
    result["runtime_configs_verified"]=False
    result["no_browser_deletions"]=True
    print(json.dumps(result,sort_keys=True),flush=True)


if __name__ == "__main__":
    try:
        main()
    except Rejected as exc:
        print(json.dumps({"ok":False,"read_only":True,"error":str(exc)}))
        raise SystemExit(1)
