#!/usr/bin/env python3
"""Read-only Puppeteer browser-cache inventory. No profile or file contents."""
import json
from cpanel_disk_cleanup import request_api, Rejected

ROOT = ".cache/puppeteer"
MAX_LEVEL = 3

def run():
    stack=[(ROOT,0)]
    result=[]
    while stack:
        directory, level=stack.pop()
        rows=request_api("Fileman","list_files",{"dir":directory,"show_hidden":1})
        if not isinstance(rows,list) or len(rows)>5000:
            raise Rejected("invalid_listing")
        for row in rows:
            if not isinstance(row,dict):
                raise Rejected("invalid_listing")
            name=row.get("file") or row.get("name")
            if not isinstance(name,str) or not name or "/" in name or name in (".",".."):
                raise Rejected("invalid_name")
            path=directory+"/"+name
            typ=row.get("type")
            if typ=="dir" and level<MAX_LEVEL:
                stack.append((path,level+1))
            if level<=1:
                result.append({"path":path,"type":typ,"size_bytes":int(row.get("size") or 0)})
    print(json.dumps({"ok":True,"read_only":True,"entries":result,
                      "directory_sizes_are_not_recursive":True}))

if __name__=="__main__":
    try: run()
    except Rejected as exc:
        print(json.dumps({"ok":False,"error":str(exc)}))
        raise SystemExit(1)
