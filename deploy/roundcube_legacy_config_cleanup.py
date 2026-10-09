"""Manual-only reversible removal of retired Postmark settings and unused test directory."""
import json,os,re,subprocess,sys
import roundcube_citycenter_smtp2go_dns as dns
from roundcube_final_test_cleanup import read
from roundcube_remaining_audit import mail_summary
from roundcube_route_audit import summary
def write(c,path,content):
 parent,name=path.rsplit('/',1)
 c.request('/json-api/uapi_cpanel',{'api.version':1,'cpanel.user':c.user,'cpanel.module':'Fileman','cpanel.function':'save_file_content','dir':parent,'file':name,'content':content,'from_charset':'UTF-8','to_charset':'UTF-8'},api_version=3,post=True)
 if read(c,path)!=content:raise dns.OperationError('write_readback_failed')
def plan(c,user,domain):
 home='/home/'+user;root=home+'/public_html/roundcube'
 private=home+('/roundcube-smtp2go-private' if user=='welcome' else '/roundcube-smtp2go-citycenter-private')
 plugin='welcome_smtp2go_api' if user=='welcome' else 'citycenter_smtp2go_api'
 path=root+'/config/config.inc.php';original=read(c,path)
 if mail_summary(original)['smtp_route_provider']!='postmark':raise dns.OperationError('unexpected_current_route')
 defaults=read(c,root+'/config/defaults.inc.php')
 if mail_summary(defaults)['smtp_route_provider']!='local':raise dns.OperationError('default_route_not_verified_local')
 if mail_summary(defaults)['nonplaceholder_smtp_credential_fields']!=0:raise dns.OperationError('default_credentials_not_safe')
 protected={root+'/plugins/'+plugin+'/'+n:read(c,root+'/plugins/'+plugin+'/'+n) for n in ('api_transport.php',plugin+'.php','config.inc.php')}
 if plugin not in original:raise dns.OperationError('production_plugin_not_active')
 key='sandbox-key.txt' if user=='welcome' else 'api-key.txt';stat=c.file_stat(private,key)
 if not stat or stat.get('nicemode')!='0600':raise dns.OperationError('production_key_not_secure')
 proc=subprocess.run(['php','deploy/roundcube_remove_legacy_smtp.php'],input=original,text=True,capture_output=True,timeout=20)
 if proc.returncode or not proc.stdout:raise dns.OperationError('static_transformation_failed')
 desired=proc.stdout
 if mail_summary(desired)['nonplaceholder_smtp_credential_fields']!=0 or mail_summary(desired)['smtp_route_provider']=='postmark':raise dns.OperationError('legacy_settings_remain')
 backup='config-before-legacy-smtp-cleanup-20261009.inc.php'
 return locals()
def apply(p):
 c=p['c'];home=p['home'];private=p['private'];backup=p['backup'];original=p['original'];path=p['path'];desired=p['desired']
 if backup not in c.file_inventory(private):
  c.api2('fileop',op='copy',sourcefiles=path.removeprefix(home+'/'),destfiles=private+'/'+backup,doubledecode=0)
  c.api2('fileop',op='chmod',sourcefiles=(private+'/'+backup).removeprefix(home+'/'),metadata='0600',doubledecode=0)
 if read(c,private+'/'+backup)!=original or c.file_stat(private,backup).get('nicemode')!='0600':raise dns.OperationError('backup_not_verified')
 if read(c,path)!=original:raise dns.OperationError('config_changed_before_write')
 try:
  write(c,path,desired)
  if any(read(c,k)!=v for k,v in p['protected'].items()) or c.file_stat(private,p['key'])!=p['stat']:raise dns.OperationError('production_protection_changed')
 except dns.OperationError:
  current=read(c,path)
  if current==desired:write(c,path,original)
  raise
 retired=0
 for name in sorted(c.file_inventory(private)):
  if re.fullmatch(r'[A-Za-z0-9._-]{1,150}',name) and 'test' in name.lower() and not any(x in name.lower() for x in ('before','backup','live','key')):
   st=c.file_stat(private,name)
   if st and st.get('type')=='dir' and name not in desired and all(name not in value for value in p['protected'].values()):
    c.api2('fileop',op='trash',sourcefiles=(private+'/'+name).removeprefix(home+'/'),doubledecode=0)
    if name in c.file_inventory(private):raise dns.OperationError('test_directory_retirement_failed')
    retired+=1
 return {'account':p['user'],'legacy_smtp_credentials_removed':True,'prior_local_defaults_restored':True,'smtp2go_plugin_and_key_preserved':True,'private_backup_verified':True,'test_directories_retired':retired,'other_mailboxes_use_smtp2go':False,'email_sent':False}
def run():
 plans=[]
 for user,domain in [('welcome','welcomehostel.pt'),('city','citycenterhostel.pt')]:
  c=dns.Client(os.environ.get('WHM_API_TOKEN',''));c.user=user;plans.append(plan(c,user,domain))
 if os.environ.get('ROUNDCUBE_CLEANUP_APPLY')!='1':
  return {'ok':True,'read_only':True,'both_plans_validated':True,'accounts':[{'account':p['user'],'defaults_verified_local':True,'static_cleanup_validated':True,'production_key_preserved':True} for p in plans]}
 return {'ok':True,'accounts':[apply(p) for p in plans],'private_contents_published':False}
if __name__=='__main__':
 try:print(json.dumps(run(),sort_keys=True))
 except dns.OperationError as e:print(json.dumps({'ok':False,'error':str(e)}));sys.exit(1)
 except Exception:print(json.dumps({'ok':False,'error':'legacy_cleanup_requires_review'}));sys.exit(1)
