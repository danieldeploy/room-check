"""Fixed-scope legacy DNS cleanup; preserve all transport and private backups."""
import json, os, sys
import roundcube_citycenter_smtp2go_dns as dns
import roundcube_citycenter_requirements as req

PRIVATE='/home/city/'+dns.BACKUP_DIR
BACKUP='dns-before-legacy-cleanup-20261008.json'

def target(r):
    name=dns.canonical_name(str(r.get('name','')))
    return (r.get('type')=='CNAME' and name=='pm-bounces.citycenterhostel.pt' and str(r.get('cname','')).rstrip('.').lower()=='pm.mtasv.net') or (r.get('type')=='TXT' and name=='20261001180640pm._domainkey.citycenterhostel.pt')

def run():
    c=dns.Client(os.environ.get('WHM_API_TOKEN',''))
    if c.account_for_domain()!='city': raise dns.OperationError('account_mismatch')
    config=req.read(c,req.ROOT+'/config','config.inc.php')
    enabled=req.read(c,req.ROOT+'/plugins/citycenter_smtp2go_api','config.inc.php')
    if "citycenter_smtp2go_api" not in config or '= true;' not in enabled: raise dns.OperationError('production_not_active')
    key=c.file_stat(PRIVATE,'api-key.txt')
    if not key or key.get('nicemode')!='0600': raise dns.OperationError('key_not_secure')
    before=c.dns_records()
    if not all(len(dns.matching(before,r))==1 for r in dns.RECORDS): raise dns.OperationError('production_dns_missing')
    raw=c.legacy_zone()
    if BACKUP not in c.file_inventory(PRIVATE):
        content=json.dumps({'domain':dns.DOMAIN,'records':raw},sort_keys=True)
        c.request('/json-api/uapi_cpanel',{'api.version':1,'cpanel.user':'city','cpanel.module':'Fileman','cpanel.function':'save_file_content','dir':PRIVATE,'file':BACKUP,'content':content,'from_charset':'UTF-8','to_charset':'UTF-8'},api_version=3,post=True)
        c.api2('fileop',op='chmod',sourcefiles=(PRIVATE+'/'+BACKUP).removeprefix('/home/city/'),metadata='0600',doubledecode=0)
        if req.read(c,PRIVATE,BACKUP)!=content: raise dns.OperationError('backup_readback_failed')
    saved=json.loads(req.read(c,PRIVATE,BACKUP))
    if saved.get('domain')!=dns.DOMAIN or not isinstance(saved.get('records'),list): raise dns.OperationError('backup_invalid')
    stat=c.file_stat(PRIVATE,BACKUP)
    if not stat or stat.get('nicemode')!='0600': raise dns.OperationError('backup_insecure')
    removed=0
    while True:
        rows=c.legacy_zone(); chosen=[r for r in rows if target(r)]
        if not chosen: break
        if removed>=3 or not isinstance(chosen[0].get('Line'),int): raise dns.OperationError('unexpected_legacy_records')
        c.zone_api2('remove_zone_record',line=chosen[0]['Line']);removed+=1
    after=c.dns_records()
    keep=lambda rows:[r for r in rows if not (r['name']=='pm-bounces.citycenterhostel.pt' and r['type']=='CNAME' and r['data']==['pm.mtasv.net']) and not (r['name']=='20261001180640pm._domainkey.citycenterhostel.pt' and r['type']=='TXT')]
    if dns._fingerprints(keep(before))!=dns._fingerprints(after): raise dns.OperationError('unrelated_dns_changed')
    if req.read(c,req.ROOT+'/config','config.inc.php')!=config or req.read(c,req.ROOT+'/plugins/citycenter_smtp2go_api','config.inc.php')!=enabled: raise dns.OperationError('configuration_changed')
    pops=c.request('/json-api/uapi_cpanel',{'api.version':1,'cpanel.user':'city','cpanel.module':'Email','cpanel.function':'list_pops'},api_version=3)
    rows=pops if isinstance(pops,list) else pops.get('pops',[])
    return {'ok':True,'legacy_dns_removed':removed,'raw_case_preserving_backup_verified':True,'smtp2go_preserved':True,'incoming_mail_preserved':True,'private_contents_published':False,'mailbox_count':len(rows),'legacy_global_smtp_config_retained_for_other_mailbox_review':True}

if __name__=='__main__':
    try: print(json.dumps(run(),sort_keys=True))
    except dns.OperationError as e: print(json.dumps({'ok':False,'error':str(e),'state':'requires_verification'}));sys.exit(1)
    except Exception: print(json.dumps({'ok':False,'error':'cleanup_requires_review'}));sys.exit(1)
