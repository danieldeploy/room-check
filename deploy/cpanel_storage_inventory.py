#!/usr/bin/env python3
"""Read-only, redacted retention and cache opportunity inventory."""
import datetime as dt
import json
from cpanel_disk_cleanup import request_api, Rejected

ROOTS = ("room-check-backups", ".cache", ".npm")
CUTOFF_DAYS = 45

def safe_listing(path):
    rows = request_api("Fileman", "list_files", {"dir": path, "show_hidden": 1})
    if not isinstance(rows, list) or len(rows) > 10000:
        raise Rejected("invalid_inventory")
    return rows

def classify_backups(rows, now):
    newest = []
    protected = 0
    older = 0
    unknown = 0
    for obj in rows:
        if not isinstance(obj, dict):
            raise Rejected("invalid_inventory")
        name = obj.get("file") or obj.get("name")
        if obj.get("type") != "dir" or not isinstance(name,str) or not name.startswith("release-"):
            continue
        try:
            stamp = dt.datetime.strptime(name[8:23], "%Y%m%d-%H%M%S").replace(tzinfo=dt.timezone.utc)
        except ValueError:
            unknown += 1
            continue
        newest.append(stamp)
        if stamp >= now - dt.timedelta(days=CUTOFF_DAYS):
            protected += 1
        else:
            older += 1
    return {"release_count":len(newest)+unknown,"within_45_days":protected,
            "older_than_45_days":older,"unparseable_names":unknown,
            "deletion_authorized":False}

def cache_summary(rows):
    # Only aggregate first-level entries; directory size cannot be interpreted
    # as recursively occupied bytes.
    groups={}
    for entry in rows:
        if not isinstance(entry,dict):
            raise Rejected("invalid_inventory")
        name=entry.get("file") or entry.get("name")
        if not isinstance(name,str):
            raise Rejected("invalid_inventory")
        groups[name]={"type":str(entry.get("type","unknown"))[:12],
                      "reported_size_bytes":int(entry.get("size",0))}
    return groups

def main():
    now=dt.datetime.now(dt.timezone.utc)
    backup=classify_backups(safe_listing(ROOTS[0]),now)
    cache=cache_summary(safe_listing(ROOTS[1]))
    npm=cache_summary(safe_listing(ROOTS[2]))
    print(json.dumps({"ok":True,"read_only":True,"backup_age":backup,
                      "cache_top_level":cache,"npm_top_level":npm,
                      "directory_sizes_not_recursive":True},sort_keys=True))

if __name__=="__main__":
    try: main()
    except Rejected as exc:
        print(json.dumps({"ok":False,"error":str(exc)}))
        raise SystemExit(1)
