"""Retire fixed test artifacts, protect both live installations, aggregate audit only."""
import json, os, re, subprocess, sys
import roundcube_citycenter_smtp2go_dns as dns

def read(c,path):
    parent,name=path.rsplit('/',1)
    d=c.request('/json-api/uapi_cpanel',{'api.version':1,'cpanel.user':c.user,'cpanel.module':'Fileman','cpanel.function':'get_file_content','dir':parent,'file':name,'from_charset':'UTF-8','to_charset':'UTF-8'},api_version=3)
    if not isinstance(d,dict) or not isinstance(d.get('content'),str): raise dns.OperationError('private_read_failed')
    return d['content']

def run():
    result=[]
    for user in ('welcome','city'):
        c=dns.Client(os.environ.get('WHM_API_TOKEN',''));c.user=user
        home='/home/'+user;root=home+'/public_html/roundcube'
        private=home+('/roundcube-smtp2go-private' if user=='welcome' else '/roundcube-smtp2go-citycenter-private')
        plugin='welcome_smtp2go_api' if user=='welcome' else 'citycenter_smtp2go_api'
        cfgpath=root+'/config/config.inc.php';config=read(c,cfgpath)
        # Conservative literal inventory; never evaluate config or includes.
        names=re.findall(r"['\"]([a-z][a-z0-9_]*)['\"]",config)
        if plugin not in names: raise dns.OperationError('live_plugin_not_active')
        protected={cfgpath:config}
        for name in ('api_transport.php',plugin+'.php','config.inc.php'):
            path=root+'/plugins/'+plugin+'/'+name;protected[path]=read(c,path)
        key='sandbox-key.txt' if user=='welcome' else 'api-key.txt'
        keypath=private+'/'+key
        stat=c.file_stat(private,key)
        if not stat or stat.get('nicemode')!='0600': raise dns.OperationError('production_key_missing')
        # Key bytes never read or printed; native metadata alone protects its location.
        candidates=['public_html/smtp-port-test.php','public_html/roundcube/gmailports-test.php','public_html/roundcube/pm2525-test.php']
        if user=='welcome':
            candidates+=['postmark-api-test-20261002-1327.txt','resend-connectivity-20261003.txt','smtp2go-connectivity-20261004.txt','smtp2go-sandbox-test','public_html/roundcube/plugins/welcome_smtp2go','public_html/roundcube/plugins/welcome_smtp2go_pilot']
        removed=0;absent=0
        for rel in candidates:
            path=home+'/'+rel;parent,name=path.rsplit('/',1)
            if rel.startswith('public_html/roundcube/plugins/') and name in names: raise dns.OperationError('test_plugin_still_active')
            if name not in c.file_inventory(parent): absent+=1;continue
            c.api2('fileop',op='trash',sourcefiles=rel,doubledecode=0)
            if name in c.file_inventory(parent): raise dns.OperationError('test_retirement_unverified')
            removed+=1
        if any(read(c,path)!=value for path,value in protected.items()): raise dns.OperationError('production_changed')
        if c.file_stat(private,key)!=stat: raise dns.OperationError('key_metadata_changed')
        proc=subprocess.run(['php','deploy/roundcube_config_summary.php','--mail-summary'],input=config,text=True,capture_output=True,timeout=20)
        if proc.returncode!=0: raise dns.OperationError('mail_analysis_failed')
        mail=json.loads(proc.stdout)
        result.append({'account':user,'test_artifacts_retired':removed,'test_artifacts_already_absent':absent,'production_preserved':True,'key_preserved':True,'postmark_marker_present':'postmark' in config.lower() or 'mtasv' in config.lower(),'resend_marker_present':'resend' in config.lower(),**mail})
    return {'ok':True,'private_contents_published':False,'email_sent':False,'configuration_changed':False,'accounts':result}

if __name__=='__main__':
    try: print(json.dumps(run(),sort_keys=True))
    except dns.OperationError as e: print(json.dumps({'ok':False,'error':str(e),'state':'requires_review'}));sys.exit(1)
    except Exception: print(json.dumps({'ok':False,'error':'cleanup_requires_review'}));sys.exit(1)
