"""Read-only static analysis of prior SMTP settings and private test metadata."""
import json,os,re,sys
import roundcube_citycenter_smtp2go_dns as dns
from roundcube_final_test_cleanup import read
PAT=re.compile(r"""\$config\s*\[\s*(['"])(smtp_[a-z_]+)\1\s*\]\s*=\s*(['"])([^'"\r\n]*)\3\s*;""")
def summary(s,domain):
 settings={m[2]:m[4] for m in PAT.finditer(s)}
 host=settings.get('smtp_host',settings.get('smtp_server',''))
 category='none'
 if host:
  if any(x in host.lower() for x in ('postmark','mtasv')):category='postmark'
  elif any(x in host.lower() for x in ('localhost','127.0.0.1')):category='local'
  elif 'server50.romania-webhosting.com' in host.lower():category='clausweb_server'
  elif domain in host.lower():category='own_domain'
  elif '%h' in host:category='imap_host_placeholder'
  else:category='other'
 return {'route_class':category,'username_placeholder':settings.get('smtp_user')=='%u','password_placeholder':settings.get('smtp_pass')=='%p','has_host':bool(host),'has_smtp_port':bool(re.search(r"\$config\s*\[\s*['\"]smtp_port['\"]",s))}
def run():
 accounts=[]
 for user,domain in [('welcome','welcomehostel.pt'),('city','citycenterhostel.pt')]:
  c=dns.Client(os.environ.get('WHM_API_TOKEN',''));c.user=user
  home='/home/'+user;root=home+'/public_html/roundcube';private=home+('/roundcube-smtp2go-private' if user=='welcome' else '/roundcube-smtp2go-citycenter-private')
  backups=[]
  for directory in [root+'/config',private]:
   for name in sorted(c.file_inventory(directory)):
    if re.fullmatch(r'[A-Za-z0-9._-]{1,150}',name) and ('backup' in name or 'before-' in name) and name.endswith('.php'):
     backups.append(summary(read(c,directory+'/'+name),domain))
  artifacts=[]
  for name in sorted(c.file_inventory(private)):
   if re.search(r'postmark|resend|pilot|test',name,re.I):
    st=c.file_stat(private,name)
    artifacts.append({'production_backup':'backup' in name or 'before-' in name,'validation_result':'validation' in name,'test_marker':'test' in name,'legacy_provider':'postmark' in name or 'resend' in name,'type':st.get('type') if st else None,'mode':st.get('nicemode') if st else None})
  accounts.append({'account':user,'current':summary(read(c,root+'/config/config.inc.php'),domain),'backups':backups,'private_artifact_classifications':artifacts})
 return {'ok':True,'read_only':True,'accounts':accounts}
if __name__=='__main__':
 try:print(json.dumps(run(),sort_keys=True))
 except Exception:print(json.dumps({'ok':False,'error':'route_audit_requires_review'}));sys.exit(1)
