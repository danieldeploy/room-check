"""Final read-only audit of remaining test entries; aggregate evidence only."""
import json,os,re,sys
import roundcube_citycenter_smtp2go_dns as dns
from roundcube_final_test_cleanup import read
from roundcube_known_trash_purge_once import KNOWN,SAFE
def inspect(c,path,depth=0):
 info={'files':0,'directories':0,'protected_names':0,'provider_content_markers':0}
 if depth>4:return {**info,'bounded_scan':True}
 children=c.file_inventory(path)
 for name in sorted(children):
  if not SAFE.fullmatch(name) or name in ('.','..'):continue
  if any(x in name.lower() for x in ('key','before','backup','live')):
   info['protected_names']+=1;continue
  st=c.file_stat(path,name)
  if not st:continue
  if st.get('type')=='dir':
   info['directories']+=1
   sub=inspect(c,path+'/'+name,depth+1)
   for k in info:info[k]+=sub.get(k,0)
  elif st.get('type')=='file':
   info['files']+=1
   if info['files']>20:break
   if name.endswith(('.json','.php','.txt','.log')) and int(st.get('size',0))<65536:
    value=read(c,path+'/'+name)
    if re.search(r'(?i)postmark|resend|smtp2go|roundcube',value):info['provider_content_markers']+=1
 return info
def run():
 out=[]
 for user in ('welcome','city'):
  c=dns.Client(os.environ.get('WHM_API_TOKEN',''));c.user=user;trash='/home/'+user+'/.trash'
  known=0;unknown=[]
  for name in sorted(c.file_inventory(trash)):
   if not SAFE.fullmatch(name) or 'test' not in name.lower() or any(x in name.lower() for x in ('key','backup','before','live')):continue
   if re.sub(r'\.[0-9]+$','',name) in KNOWN:known+=1;continue
   st=c.file_stat(trash,name)
   evidence={'type':st.get('type') if st else 'missing','generic_test_name':bool(re.fullmatch(r'tests?(?:[._-][0-9]+)?',name))}
   if st and st.get('type')=='dir':evidence.update(inspect(c,trash+'/'+name))
   unknown.append(evidence)
  out.append({'account':user,'known_test_entries_remaining':known,'unidentified_test_entries':len(unknown),'unidentified_evidence':unknown})
 return {'ok':True,'read_only':True,'accounts':out,'private_contents_published':False}
if __name__=='__main__':
 try:print(json.dumps(run(),sort_keys=True))
 except dns.OperationError as e:print(json.dumps({'ok':False,'error':str(e)}));sys.exit(1)
 except Exception:print(json.dumps({'ok':False,'error':'final_trash_audit_requires_review'}));sys.exit(1)
