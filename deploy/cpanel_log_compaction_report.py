#!/usr/bin/env python3
"""Read only the fixed one-off aggregate, never private recovery manifests or logs."""
import datetime as dt
import json
import time
from cpanel_disk_cleanup import Rejected, request_api
from cpanel_read_private_full_log_audit_once import number, safe_counts, ALLOWED_SEVERITIES

def sanitize(p):
    if (not isinstance(p, dict) or p.get('schema') != 'whm-log-compaction-v1'
            or p.get('ok') is not True or p.get('historical_bytes_preserved') is not True):
        raise Rejected('compaction_incomplete')
    profiles = p.get('profiles')
    if not isinstance(profiles, list) or len(profiles) > 150:
        raise Rejected('invalid_profiles')
    out = {'ok':True, 'historical_bytes_preserved':True,
           'files_compacted':number(p.get('files_compacted'),150),
           'bytes_reclaimed':number(p.get('bytes_reclaimed'),250*1048576), 'profiles':[]}
    for f in profiles:
        if not isinstance(f,dict) or f.get('status') not in {'preserved','compacted'}:
            raise Rejected('invalid_profile')
        item = {'status':f['status'], 'size_bytes':number(f.get('size_bytes'),200*1048576),
                'age_days':number(f.get('age_days'),40000), 'lines':number(f.get('lines')),
                'origin_counts':safe_counts(f.get('origin_counts'),{'roundcube','wordpress','management_hub','unknown'}),
                'severity_counts':safe_counts(f.get('severity_counts'),ALLOWED_SEVERITIES)}
        for k in ('first_date','last_date'):
            v = f.get(k)
            try:
                if v is not None and (not isinstance(v,str) or dt.date.fromisoformat(v).isoformat()!=v):
                    raise ValueError()
            except (ValueError,TypeError): raise Rejected('invalid_profile_date') from None
            item[k] = v
        out['profiles'].append(item)
    if sum(f['status']=='compacted' for f in out['profiles']) != out['files_compacted']:
        raise Rejected('compaction_count_mismatch')
    return out

def main():
    deadline = time.monotonic()+690
    while True:
        try:
            response = request_api('Fileman','get_file_content',{
                'dir':'/home/welcome/room-check-private','file':'whm-log-compaction-20261106.json','to_charset':'utf-8'})
        except Rejected:
            response = None
        if response is not None:
            raw = response.get('content') if isinstance(response,dict) else None
            if not isinstance(raw,str) or len(raw.encode())>120000: raise Rejected('invalid_compaction_report')
            print(json.dumps(sanitize(json.loads(raw)),sort_keys=True),flush=True)
            return
        if time.monotonic() >= deadline: raise Rejected('compaction_report_unavailable_inspect_receipt')
        time.sleep(min(20,max(0,deadline-time.monotonic())))

if __name__=='__main__':
    try: main()
    except Exception:
        print('{"ok":false,"error":"compaction_report_unverified_inspect_private_receipt"}',flush=True)
        raise SystemExit(1)
