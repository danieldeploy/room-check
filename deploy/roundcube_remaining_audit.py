"""Read-only, aggregate-only inventory of remaining email cleanup on both accounts."""
import base64, json, os, re, subprocess, sys
import roundcube_citycenter_smtp2go_dns as dns
from roundcube_final_test_cleanup import read

def mail_summary(source):
    p=subprocess.run(['php','deploy/roundcube_config_summary.php','--mail-summary'],input=source,text=True,capture_output=True,timeout=20)
    if p.returncode: raise dns.OperationError('static_config_analysis_failed')
    return json.loads(p.stdout)

def run():
    accounts=[]
    for user,domain in [('welcome','welcomehostel.pt'),('city','citycenterhostel.pt')]:
        c=dns.Client(os.environ.get('WHM_API_TOKEN',''));c.user=user
        home='/home/'+user; root=home+'/public_html/roundcube'
        private=home+('/roundcube-smtp2go-private' if user=='welcome' else '/roundcube-smtp2go-citycenter-private')
        config=read(c,root+'/config/config.inc.php')
        inventories={label:c.file_inventory(path) for label,path in [('home',home),('config',root+'/config'),('private',private),('temp',root+'/temp')]}
        backups=[]
        for label,path in [('config',root+'/config'),('private',private)]:
            for name in sorted(inventories[label]):
                if re.fullmatch(r'[A-Za-z0-9._-]{1,150}',name) and ('before-' in name or 'backup' in name) and name.endswith('.php'):
                    backups.append(mail_summary(read(c,path+'/'+name)))
                    if len(backups)>20: raise dns.OperationError('too_many_backups')
        zone=c.request('/json-api/uapi_cpanel',{'api.version':1,'cpanel.user':user,'cpanel.module':'DNS','cpanel.function':'parse_zone','zone':domain},api_version=3)
        rows=zone if isinstance(zone,list) else zone.get('payload',[])
        counts={'postmark':0,'resend':0,'smtp2go':0}
        for r in rows:
            if r.get('type')!='record': continue
            value=' '.join(base64.b64decode(v).decode() for v in [r['dname_b64']]+r['data_b64']).lower()
            for provider,pattern in [('postmark',r'postmark|mtasv|pm-bounces|pm\._domainkey'),('resend',r'resend'),('smtp2go',r'smtp2go')]:
                if re.search(pattern,value): counts[provider]+=1
        pops=c.request('/json-api/uapi_cpanel',{'api.version':1,'cpanel.user':user,'cpanel.module':'Email','cpanel.function':'list_pops'},api_version=3)
        pops=pops if isinstance(pops,list) else pops.get('pops',[])
        plugin='welcome_smtp2go_api' if user=='welcome' else 'citycenter_smtp2go_api'
        code=read(c,root+'/plugins/'+plugin+'/'+plugin+'.php')
        includes=len(re.findall(r'\b(?:include|require)(?:_once)?\b',config))
        accounts.append({'account':user,**mail_summary(config),'mailbox_count':len(pops),'plugin_scoped_to_info':('info@'+domain) in code,'config_include_markers':includes,'backup_route_summaries':backups,'dns_provider_record_counts':counts,'private_legacy_named_file_count':sum(bool(re.search(r'postmark|resend|pilot|test',n,re.I)) for n in inventories['private']),'home_legacy_named_file_count':sum(bool(re.search(r'postmark|resend|pilot|test',n,re.I)) for n in inventories['home']),'temp_entry_count':len(inventories['temp']- {'.','..','.htaccess'}),'production_config_unchanged':read(c,root+'/config/config.inc.php')==config})
    return {'ok':True,'read_only':True,'private_contents_published':False,'accounts':accounts}

if __name__=='__main__':
    try: print(json.dumps(run(),sort_keys=True))
    except dns.OperationError as e: print(json.dumps({'ok':False,'error':str(e)}));sys.exit(1)
    except Exception: print(json.dumps({'ok':False,'error':'audit_requires_review'}));sys.exit(1)
