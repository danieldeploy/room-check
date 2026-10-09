"""One-shot purge restricted to previously identified email tests already in Trash."""
import json,os,re,sys
import roundcube_citycenter_smtp2go_dns as dns
from roundcube_final_test_cleanup import read
KNOWN={'smtp-port-test.php','gmailports-test.php','pm2525-test.php','postmark-api-test-20261002-1327.txt','resend-connectivity-20261003.txt','smtp2go-connectivity-20261004.txt','smtp2go-sandbox-test','welcome_smtp2go','welcome_smtp2go_pilot'}
SAFE=re.compile(r'[A-Za-z0-9._-]{1,150}')
def walk(c,parent,name,depth=0):
 if depth>6 or not SAFE.fullmatch(name) or name in ('.','..'):raise dns.OperationError('unsafe_test_tree')
 if any(x in name.lower() for x in ('key','backup','before','live')) or name.lower().endswith(('.pem','.p12','.pfx')):raise dns.OperationError('protected_test_tree')
 st=c.file_stat(parent,name)
 if not st or st.get('type') not in ('file','dir'):raise dns.OperationError('nonregular_test_tree')
 path=parent+'/'+name;result=[]
 if st['type']=='dir':
  children=c.file_inventory(path)
  if len(children)>200:raise dns.OperationError('test_tree_too_large')
  for child in sorted(children):result+=walk(c,path,child,depth+1)
 result.append((path,st))
 return result
def run():
 if os.environ.get('EMAIL_TEST_PURGE_ONCE')!='20261009-known-trash-only':raise dns.OperationError('manual_scope_not_enabled')
 c=dns.Client(os.environ.get('WHM_API_TOKEN',''));c.user='welcome'
 home='/home/welcome';root=home+'/public_html/roundcube';private=home+'/roundcube-smtp2go-private';trash=home+'/.trash'
 guard='known-trash-purge-20261009.guard.json'
 if guard in c.file_inventory(private):return {'ok':True,'already_attempted':True,'permanent_deletions_this_run':0}
 protected={root+'/config/config.inc.php':read(c,root+'/config/config.inc.php')}
 for n in ('api_transport.php','welcome_smtp2go_api.php','config.inc.php'):
  path=root+'/plugins/welcome_smtp2go_api/'+n;protected[path]=read(c,path)
 key=c.file_stat(private,'sandbox-key.txt')
 if not key or key.get('type')!='file' or key.get('nicemode')!='0600':raise dns.OperationError('production_key_not_verified')
 backup=c.file_stat(private,'config-before-legacy-smtp-cleanup-20261009.inc.php')
 if not backup or backup.get('nicemode')!='0600':raise dns.OperationError('backup_not_verified')
 original=set(c.file_inventory(trash));plans=[];ignored=0
 for name in sorted(original):
  if not SAFE.fullmatch(name):continue
  norm=re.sub(r'\.[0-9]+$','',name)
  if norm not in KNOWN:continue
  if any(name in content for content in protected.values()):raise dns.OperationError('test_name_referenced_by_production')
  tree=walk(c,trash,name)
  if len(tree)>500:raise dns.OperationError('test_tree_too_large')
  plans.append((name,tree))
 # Guard prevents any re-run from performing deletion again, including partial failures.
 content=json.dumps({'started':True,'scope':'known-email-tests-in-trash-only','candidate_count':len(plans)})
 c.request('/json-api/uapi_cpanel',{'api.version':1,'cpanel.user':'welcome','cpanel.module':'Fileman','cpanel.function':'save_file_content','dir':private,'file':guard,'content':content,'from_charset':'UTF-8','to_charset':'UTF-8'},api_version=3,post=True)
 c.api2('fileop',op='chmod',sourcefiles=(private+'/'+guard).removeprefix(home+'/'),metadata='0600',doubledecode=0)
 if read(c,private+'/'+guard)!=content or c.file_stat(private,guard).get('nicemode')!='0600':raise dns.OperationError('one_shot_guard_not_verified')
 deleted=0
 for name,tree in plans:
  # Verify entire tree immediately before deleting this candidate. Never follow links.
  if walk(c,trash,name)!=tree:raise dns.OperationError('test_tree_changed')
  if any(read(c,path)!=value for path,value in protected.items()) or c.file_stat(private,'sandbox-key.txt')!=key:raise dns.OperationError('production_changed')
  for path,st in tree:
   parent,leaf=path.rsplit('/',1)
   if c.file_stat(parent,leaf)!=st:raise dns.OperationError('test_entry_changed')
   c.api2('fileop',op='unlink',sourcefiles=path.removeprefix(home+'/'),doubledecode=0)
   if leaf in c.file_inventory(parent):raise dns.OperationError('purge_not_verified')
  deleted+=1
 final=set(c.file_inventory(trash))
 removed={name for name,tree in plans}
 if final!=original-removed:raise dns.OperationError('unrelated_trash_changed')
 if any(read(c,path)!=value for path,value in protected.items()) or c.file_stat(private,'sandbox-key.txt')!=key:raise dns.OperationError('production_changed')
 return {'ok':True,'known_test_entries_permanently_deleted':deleted,'unrelated_trash_preserved':True,'production_config_and_plugin_preserved':True,'production_key_preserved':True,'backup_preserved':True,'one_shot_guard':True,'email_sent':False,'private_contents_published':False}
if __name__=='__main__':
 try:print(json.dumps(run(),sort_keys=True))
 except dns.OperationError as e:print(json.dumps({'ok':False,'error':str(e),'automatic_retry_disabled':True}));sys.exit(1)
 except Exception:print(json.dumps({'ok':False,'error':'known_test_purge_requires_review','automatic_retry_disabled':True}));sys.exit(1)
