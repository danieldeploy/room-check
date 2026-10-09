#!/usr/bin/env python3
"""Read only the exact release log and print non-secret Hostelworld readiness."""
import json,os,re,sys,urllib.parse,urllib.request
from cpanel_api import Config,CpanelAPI,WHM_ORIGIN,require
def main():
    cfg=Config(token=os.environ.get("WHM_API_TOKEN",""),transport="whm",origin=WHM_ORIGIN)
    api=CpanelAPI(cfg)
    tasks=api.call("VersionControlDeployment","retrieve")
    sha=os.environ.get("GITHUB_SHA","")
    matches=[t for t in tasks if t.get("repository_state",{}).get("identifier")==sha]
    require(bool(matches),"release_log_not_found")
    task=max(matches,key=lambda t:max(float(v) for v in t["timestamps"].values() if v not in (None,"",0,"0")))
    path=task.get("log_path","")
    require(re.fullmatch(r"/home/welcome/\.cpanel/logs/vc_[A-Za-z0-9_.-]+_git_deploy\.log",path),"release_log_path_not_allowed")
    params={"api.version":1,"cpanel.user":"welcome","cpanel.module":"Fileman","cpanel.function":"get_file_content",
            "dir":"/home/welcome/.cpanel/logs","file":path.rsplit("/",1)[1]}
    request=urllib.request.Request(WHM_ORIGIN+"/json-api/uapi_cpanel?"+urllib.parse.urlencode(params),
        headers={"Authorization":"whm fazenda:"+cfg.token})
    with api.opener.open(request,timeout=20) as response:
        data=json.loads(response.read(2097153))
    result=data["data"]["uapi"]; require(result.get("status")==1,"release_log_read_failed")
    value=result["data"]
    if isinstance(value,list): value=value[0]
    text=value.get("content","")
    known=["hostelworld_account_binding_missing_or_ambiguous","private_root_permissions",
           "hostelworld_account_binding_changed","configuration_error","private_write_failed"]
    print(json.dumps({"hostelworld_release_status":{"deploy_id":str(task["deploy_id"]),
        "classifications":[k for k in known if k in text]}}))
    decoder=json.JSONDecoder()
    for line in text.splitlines():
        marker=line.find('{"hostelworld_capability":')
        if marker<0: continue
        try: payload,_=decoder.raw_decode(line[marker:])
        except ValueError: continue
        c=payload.get("hostelworld_capability",{})
        require(c.get("source") in ("welcome","city") and type(c.get("account_id")) is int,"invalid_capability")
        clean={k:c.get(k) for k in ("source","account_id","credentials_ready","map_exists","map_validated","map_version")}
        clean["credential_fields"]={k:c.get("credential_fields",{}).get(k) is True for k in ("identifier","password","hostel_number")}
        targets=[]
        for t in c.get("link_targets",[]):
            host=t.get("host",""); path=t.get("path","")
            if re.fullmatch(r"(?:[a-z0-9-]+\.)*hostelworld\.com",host,re.I) and re.fullmatch(r"/[a-zA-Z0-9_/.-]{0,200}",path) and not re.search(r"[a-zA-Z0-9_-]{24,}",path):
                targets.append({"host":host,"path":path})
        clean["link_targets"]=targets
        print(json.dumps({"hostelworld_capability":clean}))
if __name__=="__main__":
    try: main()
    except Exception:
        print('{"ok":false,"error":"hostelworld_status_unavailable"}',file=sys.stderr)
        sys.exit(1)
