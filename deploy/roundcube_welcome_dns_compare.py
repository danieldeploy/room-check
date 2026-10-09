"""Read-only comparison of backed-up and current Welcome DNS, aggregate output only."""
import collections,json,os,sys
import roundcube_welcome_dns_cleanup as w
import roundcube_citycenter_smtp2go_dns as dns
from roundcube_final_test_cleanup import read
def run():
 c=dns.Client(os.environ.get('WHM_API_TOKEN',''));c.user='welcome'
 before=json.loads(read(c,w.PRIVATE+'/'+w.BACKUP))['records'];after=w.raw(c)
 a=collections.defaultdict(list);b=collections.defaultdict(list)
 for r in before:
  if not w.target(r):a[(r.get('name'),r.get('type'))].append(r)
 for r in after:b[(r.get('name'),r.get('type'))].append(r)
 changed=collections.Counter();unmatched=0
 for k in set(a)|set(b):
  if len(a[k])!=len(b[k]):unmatched+=1;continue
  for x,y in zip(a[k],b[k]):
   for key in set(x)|set(y):
    if x.get(key)!=y.get(key):changed[key]+=1
 return {'ok':True,'read_only':True,'legacy_records_before':sum(w.target(r) for r in before),'legacy_records_after':sum(w.target(r) for r in after),'unmatched_other_record_groups':unmatched,'changed_field_counts':dict(changed),'record_counts_before':dict(collections.Counter(r.get('type') for r in before)),'record_counts_after':dict(collections.Counter(r.get('type') for r in after))}
if __name__=='__main__':
 try:print(json.dumps(run(),sort_keys=True))
 except Exception:print(json.dumps({'ok':False,'error':'comparison_requires_review'}));sys.exit(1)
