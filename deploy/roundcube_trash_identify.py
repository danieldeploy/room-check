"""Classify retired tests privately and verify deletion support on a fresh disposable canary only."""
import json,os,re,sys
import roundcube_citycenter_smtp2go_dns as dns
from roundcube_final_test_cleanup import read
KNOWN={'smtp-port-test.php','gmailports-test.php','pm2525-test.php','postmark-api-test-20261002-1327.txt','resend-connectivity-20261003.txt','smtp2go-connectivity-20261004.txt','smtp2go-sandbox-test','welcome_smtp2go','welcome_smtp2go_pilot'}
def run():
 out=[]
 for user in ('welcome','city'):
  c=dns.Client(os.environ.get('WHM_API_TOKEN',''));c.user=user
  home='/home/'+user;root=home+'/public_html/roundcube';trash=home+'/.trash'
  plugin='welcome_smtp2go_api' if user=='welcome' else 'citycenter_smtp2go_api'
  active=[read(c,root+'/config/config.inc.php')]+[read(c,root+'/plugins/'+plugin+'/'+n) for n in ('api_transport.php',plugin+'.php','config.inc.php')]
  counts={'known_tests':0,'provider_named_tests':0,'unidentified_tests':0,'active_references':0,'excluded_protected_entries':0}
  for name in sorted(c.file_inventory(trash)):
   if not re.fullmatch(r'[A-Za-z0-9._-]{1,150}',name):continue
   lower=name.lower()
   if any(x in lower for x in ('key','backup','before','live')):counts['excluded_protected_entries']+=1;continue
   if 'test' not in lower:continue
   if any(name in text for text in active):counts['active_references']+=1;continue
   normalized=re.sub(r'\.[0-9]+$','',name)
   if normalized in KNOWN:counts['known_tests']+=1
   elif any(x in lower for x in ('smtp2go','postmark','resend')):counts['provider_named_tests']+=1
   else:counts['unidentified_tests']+=1
  out.append({'account':user,**counts})
 # Only newly-created empty disposable data is deleted during this capability probe.
 c=dns.Client(os.environ.get('WHM_API_TOKEN',''));c.user='welcome';home='/home/welcome'
 rid=os.environ.get('GITHUB_RUN_ID','')
 if not re.fullmatch(r'[0-9]{1,20}',rid):raise dns.OperationError('invalid_probe_id')
 dirname='roundcube-cleanup-probe-'+rid
 if dirname in c.file_inventory(home):raise dns.OperationError('probe_already_exists')
 c.api2('mkdir',path=home,name=dirname,permissions='0700')
 path=home+'/'+dirname
 c.request('/json-api/uapi_cpanel',{'api.version':1,'cpanel.user':'welcome','cpanel.module':'Fileman','cpanel.function':'save_file_content','dir':path,'file':'empty-canary.txt','content':'','from_charset':'UTF-8','to_charset':'UTF-8'},api_version=3,post=True)
 supported=False
 try:
  c.api2('fileop',op='unlink',sourcefiles=dirname+'/empty-canary.txt',doubledecode=0)
  supported='empty-canary.txt' not in c.file_inventory(path)
 except dns.OperationError:pass
 directory_supported=False
 if supported:
  try:
   c.api2('fileop',op='unlink',sourcefiles=dirname,doubledecode=0)
   directory_supported=dirname not in c.file_inventory(home)
  except dns.OperationError:pass
 if not directory_supported and dirname in c.file_inventory(home):
  c.api2('fileop',op='trash',sourcefiles=dirname,doubledecode=0)
 return {'ok':True,'accounts':out,'normal_file_unlink_supported':supported,'empty_directory_unlink_supported':directory_supported,'existing_user_data_deleted':False,'private_contents_published':False}
if __name__=='__main__':
 try:print(json.dumps(run(),sort_keys=True))
 except dns.OperationError as e:print(json.dumps({'ok':False,'error':str(e)}));sys.exit(1)
 except Exception:print(json.dumps({'ok':False,'error':'test_identification_requires_review'}));sys.exit(1)
