#!/usr/bin/env python3
"""Provision the reviewed Cloudbeds pipes using the existing protected WHM secret."""
import json
import os
import pathlib
import re
import sys
import time
import urllib.parse
import urllib.request
from cpanel_api import Config, CpanelAPI, WHM_ORIGIN, DeploymentError, require
from cloudbeds_mail_inspect import MailAPI, ROOTS, child_name
from hostelworld_filters import content, rows, verify_filter

NAME = "Room Check Cloudbeds Invoices v1"
CITY_FILES = {"cloudbeds-mail-pipe.php", "CloudbedsBridge.php", "CloudbedsMailMessage.php",
              "CloudbedsMailAuthentication.php", "CloudbedsSmtpTrace.php", "CloudbedsFolderQueue.php", "cloudbeds-city.key"}
CRONS = {
    "welcome": "cd /home/welcome && /opt/cpanel/ea-php81/root/usr/bin/php -q /home/welcome/room-check-private/cron/cloudbeds-mail-pipe.php --poll >/dev/null 2>&1",
    "city": "cd /home/city && /opt/cpanel/ea-php81/root/usr/bin/php -q /home/city/cloudbeds-mail-pipe.php --poll >/dev/null 2>&1",
}


class Hosting:
    def __init__(self, account):
        require(account in ROOTS, "account_not_allowed")
        self.account = account
        self.config = Config(token=os.environ.get("WHM_API_TOKEN", ""), transport="whm", origin=WHM_ORIGIN,
                             whm_target_account=account)
        self.opener = CpanelAPI(self.config).opener

    def call(self, module, function, **params):
        allowed = {("Email", "list_filters"), ("Email", "store_filter"),
                   ("Cron", "listcron"), ("Cron", "add_line"),
                   ("Fileman", "get_file_content"), ("Fileman", "save_file_content"), ("Fileman", "fileop")}
        require((module, function) in allowed, "operation_not_allowed")
        if module == "Cron":
            require((function == "listcron" and not params) or (function == "add_line" and params == dict(
                command=CRONS[self.account], minute="*", hour="*", day="*", month="*", weekday="*")), "cron_not_allowed")
        if module == "Fileman":
            if function == "fileop":
                require(self.account == "city" and params.get("sourcefiles") in CITY_FILES and params == dict(
                    op="chmod", sourcefiles=params["sourcefiles"], metadata="0600", doubledecode=0), "chmod_not_allowed")
            elif function == "save_file_content":
                require(self.account == "city" and set(params) == {"dir", "file", "content"}
                        and params["dir"] == "/home/city" and params["file"] in CITY_FILES, "write_not_allowed")
            else:
                reads = ({("/home/welcome/room-check-private", "cloudbeds-filter-provision.json"),
                          ("/home/welcome/room-check-private", "cloudbeds-status.json"),
                          ("/home/welcome/room-check-private", "cloudbeds-mail-status.json")} if self.account == "welcome"
                         else {("/home/city", "cloudbeds-mail-status.json")} | {("/home/city", f) for f in CITY_FILES})
                require(set(params) == {"dir", "file"} and (params["dir"], params["file"]) in reads, "read_not_allowed")
        if module == "Email":
            require((function == "list_filters" and not params) or (function == "store_filter"
                    and params.get("filtername") == NAME and "oldfiltername" not in params
                    and params == expected(self.account, params.get("dest1", ""))), "filter_not_allowed")
        api2 = module == "Cron" or function == "fileop"
        if api2:
            route = "/json-api/cpanel"
            data = {"cpanel_jsonapi_user": self.account, "cpanel_jsonapi_apiversion": 2,
                    "cpanel_jsonapi_module": module, "cpanel_jsonapi_func": function, **params}
        else:
            route = "/json-api/uapi_cpanel"
            data = {"api.version": 1, "cpanel.user": self.account, "cpanel.module": module, "cpanel.function": function, **params}
        request = urllib.request.Request(WHM_ORIGIN+route, data=urllib.parse.urlencode(data).encode(),
            headers={"Authorization": "whm fazenda:"+self.config.token, "Content-Type": "application/x-www-form-urlencoded"})
        with self.opener.open(request, timeout=30) as response:
            raw = response.read(1048577)
        require(len(raw) <= 1048576, "response_limit")
        payload = json.loads(raw)
        if api2:
            result = payload.get("cpanelresult", {})
            require(not result.get("error") and result.get("event", {}).get("result") == 1, "hosting_operation_failed")
        else:
            require(payload.get("metadata", {}).get("result") == 1, "hosting_operation_failed")
            result = payload.get("data", {}).get("uapi", {})
            require(result.get("status") == 1 and not result.get("errors"), "hosting_operation_failed")
        return result.get("data")


def expected(account, destination):
    require(account in ROOTS and re.fullmatch(re.escape(ROOTS[account]) + r"/\.(?:INBOX\.)?CLOUDBEDS/?", destination, re.I), "folder_not_allowed")
    params = dict(filtername=NAME, part1="$header_from:", match1="matches",
                  val1=r"(?i)@cloudbeds\.com(?:[> \t]|$)", opt1="and", part2="$header_to:", match2="contains",
                  val2="info@" + ("welcomehostel.pt" if account == "welcome" else "citycenterhostel.pt"),
                  action1="save", dest1=destination)
    if account == "welcome":
        params.update(action2="pipe", dest2="room-check-private/cron/cloudbeds-mail-pipe.php")
    return params


def install(api):
    mail = MailAPI(api.account)
    folders = [child_name(r) for r in mail.listing(mail.root) if r.get("type") == "dir"
               and re.fullmatch(r"\.(?:INBOX\.)?CLOUDBEDS", r.get("file", ""), re.I)]
    require(len(folders) == 1, "folder_binding_ambiguous")
    params = expected(api.account, mail.root + "/" + folders[0] + "/")
    found = [r for r in rows(api.call("Email", "list_filters")) if r.get("filtername") == NAME]
    require(len(found) <= 1, "filter_binding_ambiguous")
    if not found:
        api.call("Email", "store_filter", **params)
    found = [r for r in rows(api.call("Email", "list_filters")) if r.get("filtername") == NAME]
    require(len(found) == 1, "filter_missing")
    # Shared comparison uses the fixed rule name supplied by params.
    verify_filter(found[0], params)
    def current():
        entries = api.call("Cron", "listcron")
        require(isinstance(entries, list), "invalid_cron_list")
        matches = [r for r in entries if "cloudbeds-mail-pipe.php" in str(r.get("command", ""))]
        require(len(matches) <= 1, "cron_ambiguous")
        if matches:
            require(matches[0].get("command") == CRONS[api.account] and all(str(matches[0].get(k)) == "*"
                for k in ("minute", "hour", "day", "month", "weekday")), "cron_conflict")
        return matches
    if not current():
        api.call("Cron", "add_line", command=CRONS[api.account], minute="*", hour="*", day="*", month="*", weekday="*")
    require(len(current()) == 1, "cron_missing")
    print(json.dumps({"cloudbeds_source": api.account, "filter": "verified", "folder_worker": "verified"}), flush=True)


def safe_pipeline_rows(source_rows):
    if not isinstance(source_rows, dict): source_rows = {}
    safe={}
    for source in ROOTS:
        row=source_rows.get(source,{})
        safe[source]={k:v for k,v in row.items() if k in ("indexed","invalid","selected","spf_indexed","spf_selected") and type(v) is int}
        safe[source]["tasks"]=[{k:v for k,v in task.items() if
            (k in ("imported_count","duplicate_count") and str(v).isdigit()) or
            (k == "state" and v in ("completed","failed","retry","running","queued")) or
            (k == "result_code" and v in ("ok","no_invoices","invalid_document","account_mismatch","network_error","verification_failed","invoice_conflict","cloudbeds_mail_review"))}
            for task in row.get("tasks",[])[:2]]
        allowed=("verified","pending","retry","failed","validated","review","toc_submitted","toc_accepted","toc_existing","toc_duplicate","toc_rejected","toc_retry","toc_review","toc_no_confirmation","toc_uncertain","toc_sending")
        safe[source]["delivery"]=[{k:v for k,v in item.items() if
            (k == "total" and str(v).isdigit()) or (k in ("drive_state","company_state","toconline_state") and v in allowed)}
            for item in row.get("delivery",[])[:20]]
    return safe


def safe_year_audit(value):
    if not isinstance(value, dict): return {}
    safe={k:value.get(k) is True for k in ('started','complete')}
    if re.fullmatch(r'2026-(?:0[1-9]|1[0-2])',str(value.get('through',''))): safe['through']=value['through']
    safe['tasks']={k:v for k,v in value.get('tasks',{}).items() if k in ('completed','pending','failed') and type(v) is int and 0<=v<=24}
    counts=('found','missing','registered','drive_verified','company_validated','accepted','existing','duplicate_confirmed','submitted','uncertain','rejected','pending')
    for stage in ('before','current'):
        row=value.get(stage,{})
        safe[stage]={k:v for k,v in row.items() if k in ('review','invalid','unindexed') and type(v) is int and 0<=v<=10000}
        safe[stage]['scans_ready']=row.get('scans_ready') is True
        safe[stage]['months']={period:{source:{k:v for k,v in sources.get(source,{}).items()
            if k in counts and type(v) is int and 0<=v<=2000} for source in ROOTS}
            for period,sources in row.get('months',{}).items() if re.fullmatch(r'2026-(?:0[1-9]|1[0-2])',period)}
    return safe


def report(require_year=False):
    for account in ROOTS:
        api=Hosting(account)
        directory="/home/city" if account == "city" else "/home/welcome/room-check-private"
        try:
            state=json.loads(content(api.call("Fileman", "get_file_content", dir=directory, file="cloudbeds-mail-status.json")))
            safe={k:v for k,v in state.items() if (k in ("at","processed","retry","historical","review","http_status") and type(v) is int)
                  or (k == "folder_found" and type(v) is bool)
                  or (k == "stage" and v in ("scan_complete","delivery_retry","auth_invalid","invalid_document","document_limit","parse_retry","configuration_retry"))
                  or (k == "auth_code" and v in ("recipient_mismatch","unsigned","unsupported","unaligned","expired","unsigned_fields","dns_unavailable","body_mismatch","signature_mismatch","unverified"))}
            print(json.dumps({"cloudbeds_mail": account, "status": safe}))
        except Exception:
            print(json.dumps({"cloudbeds_mail": account, "status": "not_available"}))
    # The Hub's report is generated from fixed status fields only. Do not print arbitrary file content.
    try:
        state=json.loads(content(Hosting("welcome").call("Fileman", "get_file_content",
            dir="/home/welcome/room-check-private", file="cloudbeds-status.json")))
        safe={"sources":safe_pipeline_rows(state.get("sources",{})),
              "spf_sources":safe_pipeline_rows(state.get("spf_sources",{}))}
        safe['validated']=state.get('validated') is True
        safe['spf_validated']=state.get('spf_validated') is True
        for key in ('period','spf_period'):
            if re.fullmatch(r'20\d{2}-(?:0[1-9]|1[0-2])',str(state.get(key,''))): safe[key]=state[key]
        safe['audit_2026']=safe_year_audit(state.get('audit_2026'))
        fresh=type(state.get('at')) is int and time.time()-300<=state['at']<=time.time()+30
        safe['fresh']=fresh
        print(json.dumps({"cloudbeds_pipeline":safe}),flush=True)
        return safe['validated'] and safe['spf_validated'] and fresh and (not require_year or safe['audit_2026'].get('complete') is True)
    except Exception:
        print('{"cloudbeds_pipeline":"not_available"}')
    return False


def main():
    if sys.argv[1:] in (["--wait-status"],["--wait-year-status"]):
        require_year=sys.argv[1:] == ["--wait-year-status"]
        for _ in range(24 if require_year else 12):
            if report(require_year): return
            time.sleep(30)
        raise DeploymentError("functional_verification_pending")
    if sys.argv[1:] == ["--status"]:
        report(); return
    require(not sys.argv[1:], "invalid_arguments")
    welcome=Hosting("welcome"); city=Hosting("city")
    provision=json.loads(content(welcome.call("Fileman", "get_file_content",
        dir="/home/welcome/room-check-private", file="cloudbeds-filter-provision.json")))
    require(provision.get("version") == 1 and set(provision.get("account_ids",{})) == set(ROOTS), "invalid_provision")
    key=provision.get("keys",{}).get("city","")
    require(re.fullmatch(r"[a-f0-9]{64}",key) is not None, "invalid_provision")
    print("::add-mask::"+key)
    root=pathlib.Path(__file__).resolve().parent.parent
    # Install the trace parser before the bridge that requires it.
    for name in sorted(CITY_FILES, key=lambda name: (name != 'CloudbedsSmtpTrace.php', name)):
        value=(key if name.endswith(".key") else
               (root/("cron" if name.endswith("-pipe.php") else "src/Invoices")/name).read_text())
        # Set private permissions before writing the bridge credential.
        city.call("Fileman", "save_file_content", dir="/home/city", file=name, content="pending\n" if name.endswith(".key") else value)
        city.call("Fileman", "fileop", op="chmod", sourcefiles=name, metadata="0600", doubledecode=0)
        if name.endswith(".key"):
            city.call("Fileman", "save_file_content", dir="/home/city", file=name, content=value)
        require(content(city.call("Fileman", "get_file_content", dir="/home/city", file=name)) == value, "file_verify_failed")
    install(welcome); install(city)


if __name__ == "__main__":
    try:
        main()
    except Exception:
        print('{"cloudbeds_configuration":"failed"}',file=sys.stderr)
        sys.exit(1)
