"""Retire only clearly identified Welcome Postmark DNS; keep full raw backup."""
import json,os,re,sys
import roundcube_citycenter_smtp2go_dns as dns
from roundcube_final_test_cleanup import read
DOMAIN='welcomehostel.pt'
PRIVATE='/home/welcome/roundcube-smtp2go-private'
BACKUP='dns-before-legacy-cleanup-20261009.json'
def zone(c,function,**args):
    return c.request('/json-api/cpanel',{'cpanel_jsonapi_user':'welcome','cpanel_jsonapi_apiversion':2,'cpanel_jsonapi_module':'ZoneEdit','cpanel_jsonapi_func':function,'domain':DOMAIN,**args},api_version=2)
def raw(c):
    rows=zone(c,'fetchzone',customonly=0)
    if len(rows)!=1 or not isinstance(rows[0].get('record'),list): raise dns.OperationError('zone_inventory_invalid')
    return rows[0]['record']
def target(r):
    name=str(r.get('name','')).rstrip('.').lower()
    return (r.get('type')=='TXT' and bool(re.fullmatch(r'[0-9]{14}pm\._domainkey\.welcomehostel\.pt',name))) or (r.get('type')=='CNAME' and name=='pm-bounces.'+DOMAIN and str(r.get('cname','')).rstrip('.').lower()=='pm.mtasv.net')
def fingerprints(rows):
    return sorted(json.dumps({k:v for k,v in r.items() if k not in ('Line','line')},sort_keys=True) for r in rows if r.get('type') not in ('SOA',None))
def run():
    c=dns.Client(os.environ.get('WHM_API_TOKEN',''));c.user='welcome'
    config=read(c,'/home/welcome/public_html/roundcube/config/config.inc.php')
    key=c.file_stat(PRIVATE,'sandbox-key.txt')
    if not key or key.get('nicemode')!='0600' or 'welcome_smtp2go_api' not in config: raise dns.OperationError('production_protection_failed')
    before=raw(c);chosen=[r for r in before if target(r)]
    if len(chosen)>1: raise dns.OperationError('unexpected_legacy_count')
    if BACKUP not in c.file_inventory(PRIVATE):
        content=json.dumps({'domain':DOMAIN,'records':before},sort_keys=True)
        c.request('/json-api/uapi_cpanel',{'api.version':1,'cpanel.user':'welcome','cpanel.module':'Fileman','cpanel.function':'save_file_content','dir':PRIVATE,'file':BACKUP,'content':content,'from_charset':'UTF-8','to_charset':'UTF-8'},api_version=3,post=True)
        c.api2('fileop',op='chmod',sourcefiles='roundcube-smtp2go-private/'+BACKUP,metadata='0600',doubledecode=0)
        if read(c,PRIVATE+'/'+BACKUP)!=content: raise dns.OperationError('backup_readback_failed')
    saved=json.loads(read(c,PRIVATE+'/'+BACKUP))
    if saved.get('domain')!=DOMAIN or not isinstance(saved.get('records'),list) or c.file_stat(PRIVATE,BACKUP).get('nicemode')!='0600': raise dns.OperationError('backup_invalid')
    for r in chosen:
        current=[q for q in raw(c) if target(q)]
        if len(current)!=1 or not isinstance(current[0].get('Line'),int): raise dns.OperationError('dns_changed_before_removal')
        zone(c,'remove_zone_record',line=current[0]['Line'])
    after=raw(c)
    if any(target(r) for r in after) or fingerprints([r for r in before if not target(r)])!=fingerprints(after): raise dns.OperationError('unrelated_dns_requires_review')
    if read(c,'/home/welcome/public_html/roundcube/config/config.inc.php')!=config or c.file_stat(PRIVATE,'sandbox-key.txt')!=key: raise dns.OperationError('production_changed')
    return {'ok':True,'account':'welcome','legacy_dns_removed':len(chosen),'raw_backup_verified':True,'other_dns_preserved':True,'smtp2go_config_and_key_preserved':True,'configuration_changed':False,'email_sent':False}
if __name__=='__main__':
    try: print(json.dumps(run(),sort_keys=True))
    except dns.OperationError as e: print(json.dumps({'ok':False,'error':str(e)}));sys.exit(1)
    except Exception: print(json.dumps({'ok':False,'error':'cleanup_requires_review'}));sys.exit(1)
