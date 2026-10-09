"""Read-only inventory of retired email tests; never publish private file contents."""
import json,os,re,sys
import roundcube_citycenter_smtp2go_dns as dns
def run():
 out=[]
 for user in ('welcome','city'):
  c=dns.Client(os.environ.get('WHM_API_TOKEN',''));c.user=user
  home='/home/'+user;trash=home+'/.trash'
  entries=c.file_inventory(trash)
  tests=[];protected=0
  for name in sorted(entries):
   if not re.fullmatch(r'[A-Za-z0-9._-]{1,150}',name):continue
   lower=name.lower()
   if any(x in lower for x in ('key','backup','before','live')):
    protected+=1;continue
   if 'test' not in lower:continue
   st=c.file_stat(trash,name)
   if st and st.get('type') in ('dir','file'):
    tests.append({'name':name,'type':st['type']})
  # Persist no manifest and make no changes. Candidate names remain private in memory.
  out.append({'account':user,'retired_email_test_candidates':len(tests),'test_directories':sum(x['type']=='dir' for x in tests),'protected_trash_entries':protected})
 return {'ok':True,'read_only':True,'accounts':out,'private_contents_published':False,'permanent_deletions':0}
if __name__=='__main__':
 try:print(json.dumps(run(),sort_keys=True))
 except dns.OperationError as e:print(json.dumps({'ok':False,'error':str(e)}));sys.exit(1)
 except Exception:print(json.dumps({'ok':False,'error':'trash_inventory_requires_review'}));sys.exit(1)
