#!/usr/bin/env python3
"""Install only the two authorized Hostelworld pipes; never read mailboxes."""
import json
import os
import pathlib
import re
import sys
import urllib.parse
import urllib.request
from cpanel_api import Config, CpanelAPI, DeploymentError, require, WHM_ORIGIN

NAME = "Room Check Hostelworld Auth v1"
FILES = {"hostelworld-city-auth-pipe.php", "HostelworldMailMessage.php", "HostelworldBridge.php", "hostelworld-city-auth.key"}

class FilterAPI:
    def __init__(self, account):
        self.account = account
        self.config = Config(token=os.environ.get("WHM_API_TOKEN", ""), transport="whm",
                             origin=WHM_ORIGIN, whm_target_account=account)
        self.opener = CpanelAPI(self.config).opener

    def call(self, module, function, **params):
        allowed = {("Email","list_filters"), ("Email","store_filter"),
                   ("Fileman","get_file_content"), ("Fileman","save_file_content"), ("Fileman","fileop")}
        require((module,function) in allowed, "operation_not_allowed")
        if module == "Fileman" and function != "fileop":
            if self.account == "welcome":
                require(function == "get_file_content" and params == {
                    "dir":"/home/welcome/room-check-private", "file":"hostelworld-filter-provision.json"},
                    "file_read_not_allowed")
            else:
                require(params.get("dir") == "/home/city" and params.get("file") in FILES,
                        "city_path_not_allowed")
        if function == "fileop":
            require(self.account == "city" and params.get("sourcefiles") in FILES
                    and params.get("op") == "chmod" and params.get("metadata") in ("0600","0700")
                    and params.get("doubledecode") == 0, "chmod_not_allowed")
            route = "/json-api/cpanel"
            data = {"cpanel_jsonapi_user":self.account,"cpanel_jsonapi_apiversion":2,
                    "cpanel_jsonapi_module":module,"cpanel_jsonapi_func":function,**params}
        else:
            route = "/json-api/uapi_cpanel"
            data = {"api.version":1,"cpanel.user":self.account,"cpanel.module":module,
                    "cpanel.function":function,**params}
        # File contents and shared secret are in POST bodies, never URL query strings.
        request = urllib.request.Request(WHM_ORIGIN+route,
            data=urllib.parse.urlencode(data).encode(), headers={
            "Authorization":"whm fazenda:"+self.config.token,
            "Content-Type":"application/x-www-form-urlencoded", "Accept":"application/json"})
        with self.opener.open(request,timeout=20) as response:
            require(response.status == 200, "unexpected_http_status")
            raw=response.read(2*1024*1024+1)
        require(len(raw)<=2*1024*1024,"response_too_large")
        p=json.loads(raw)
        if function == "fileop":
            r=p.get("cpanelresult",{})
            require(not r.get("error") and r.get("event",{}).get("result")==1,"chmod_failed")
            return r.get("data")
        require(p.get("metadata",{}).get("result")==1,"whm_operation_failed")
        r=p.get("data",{}).get("uapi",{})
        require(r.get("status")==1 and not r.get("errors"),"uapi_operation_failed_"+self.account+"_"+function+"_"+params.get("file","filter"))
        return r.get("data")

def content(value):
    if isinstance(value,dict) and isinstance(value.get("content"),str):
        return value["content"]
    if isinstance(value,list) and len(value)==1:
        return content(value[0])
    raise DeploymentError("invalid_file_content_response")

def rows(value):
    if isinstance(value,list): return value
    if isinstance(value,dict) and isinstance(value.get("filters"),list): return value["filters"]
    raise DeploymentError("invalid_filter_list_response")

def expected(account, identifier):
    destination = ("room-check-private/cron/hostelworld-auth-pipe.php "+str(identifier)
                   if account=="welcome" else "hostelworld-city-auth-pipe.php")
    return dict(filtername=NAME,part1="$header_from:",match1="matches",
        val1=r"(?i)@(?:[a-z0-9-]+\.)*hostelworld\.com(?:[> \t]|$)",opt1="and",
        part2="$header_subject:",match2="matches",
        val2=r"(?i)(login|sign[ _-]*in|security|verification|code|authentication)",
        action1="save",dest1="$home/mail/$domain/$local_part/",
        action2="pipe",dest2=destination)

def verify_filter(row, params):
    require(row.get("filtername")==NAME and not row.get("disabled"),"filter_disabled")
    rules=row.get("rules",[])
    actions=row.get("actions",[])
    require(len(rules)==2 and len(actions)==2,"filter_conflict")
    for i,r in enumerate(rules,1):
        for key in ("part","match","val"):
            require(r.get(key)==params[key+str(i)],"filter_rule_conflict")
    require(rules[0].get("opt")=="and","filter_rule_conflict")
    for i,a in enumerate(actions,1):
        require(a.get("action")==params["action"+str(i)] and
                a.get("dest")==params["dest"+str(i)],"filter_destination_conflict")

def install(api, identifier):
    params=expected(api.account,identifier)
    matches=[r for r in rows(api.call("Email","list_filters")) if r.get("filtername")==NAME]
    require(len(matches)<=1,"ambiguous_filter")
    if matches: verify_filter(matches[0],params)
    else: api.call("Email","store_filter",**params)
    matches=[r for r in rows(api.call("Email","list_filters")) if r.get("filtername")==NAME]
    require(len(matches)==1,"filter_missing_after_creation")
    verify_filter(matches[0],params)
    print(json.dumps({"account":api.account,"filter":NAME,"state":"verified"}))

def main():
    welcome=FilterAPI("welcome"); city=FilterAPI("city")
    provision=json.loads(content(welcome.call("Fileman","get_file_content",
        dir="/home/welcome/room-check-private",file="hostelworld-filter-provision.json")))
    require(provision.get("version")==1 and re.fullmatch(r"[a-f0-9]{64}",provision.get("key","")),"invalid_provision")
    ids=provision.get("account_ids",{})
    require(set(ids)=={"welcome","city"} and all(type(v) is int and v>0 for v in ids.values())
            and ids["welcome"]!=ids["city"],"invalid_account_bindings")
    print("::add-mask::"+provision["key"])
    root=pathlib.Path(__file__).resolve().parent.parent
    files={
        "hostelworld-city-auth-pipe.php":(root/"deploy/hostelworld-city-auth-pipe.php").read_text(),
        "HostelworldMailMessage.php":(root/"src/Invoices/HostelworldMailMessage.php").read_text(),
        "HostelworldBridge.php":(root/"src/Invoices/HostelworldBridge.php").read_text(),
        "hostelworld-city-auth.key":provision["key"],
    }
    # Create the key empty first; restrict permissions before putting a secret in it.
    for name,value in files.items():
        if name.endswith(".key"):
            city.call("Fileman","save_file_content",dir="/home/city",file=name,content="pending\n")
        else:
            city.call("Fileman","save_file_content",dir="/home/city",file=name,content=value)
        mode="0700" if name.endswith("-pipe.php") else "0600"
        city.call("Fileman","fileop",op="chmod",sourcefiles=name,metadata=mode,doubledecode=0)
        if name.endswith(".key"):
            city.call("Fileman","save_file_content",dir="/home/city",file=name,content=value)
            city.call("Fileman","fileop",op="chmod",sourcefiles=name,metadata=mode,doubledecode=0)
        require(content(city.call("Fileman","get_file_content",dir="/home/city",file=name))==value,"city_file_verify_failed")
    install(welcome,ids["welcome"])
    install(city,ids["city"])

if __name__=="__main__":
    try: main()
    except Exception as error:
        print(json.dumps({"ok":False,"error":str(error) if isinstance(error,DeploymentError) else "hostelworld_filter_setup_failed"}),file=sys.stderr)
        sys.exit(1)
