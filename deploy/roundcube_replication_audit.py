#!/usr/bin/env python3
"""Fixed-path read-only audit. No remote writes or raw configuration output."""
import json
import os
import subprocess
from roundcube_audit import Client

KEYS = ['imap_host','default_host','imap_port','default_port','imap_auth_type',
        'username_domain','login_lc','sent_mbox','drafts_mbox','junk_mbox',
        'trash_mbox','archive_mbox','language','timezone','skin','plugins',
        'enable_installer','log_driver','log_dir','temp_dir','imap_conn_options',
        'imap_namespace','imap_delimiter','auto_create_user','create_default_folders']
PHP = r'''$ts=token_get_all(stream_get_contents(STDIN), TOKEN_PARSE);
$t=[]; foreach($ts as $x) {if(is_array($x)&&in_array($x[0],[T_WHITESPACE,T_COMMENT,T_DOC_COMMENT]))continue;$t[]=$x;}
$allow=json_decode($argv[1],true);$out=[];
for($i=0;$i+5<count($t);$i++) {
 if(!is_array($t[$i])||$t[$i][0]!==T_VARIABLE||$t[$i][1]!=='$config'||$t[$i+1]!=='['||!is_array($t[$i+2])||$t[$i+2][0]!==T_CONSTANT_ENCAPSED_STRING||$t[$i+3]!==']'||$t[$i+4]!=='=')continue;
 $k=substr($t[$i+2][1],1,-1);if(!in_array($k,$allow,true))continue;
 $v=$t[$i+5]; if(!is_array($v)||($t[$i+6]??null)!==';') {$out[$k]='[complex: not extracted]';continue;}
 if($v[0]===T_CONSTANT_ENCAPSED_STRING) {$s=substr($v[1],1,-1);if(strlen($s)<=160&&preg_match('/^[a-zA-Z0-9_ .:\/%@+\-]*$/D',$s))$out[$k]=$s;else $out[$k]='[not extracted]';}
 elseif($v[0]===T_LNUMBER)$out[$k]=(int)$v[1];
 elseif($v[0]===T_STRING&&in_array(strtolower($v[1]),['true','false','null'],true))$out[$k]=json_decode(strtolower($v[1]));
}
echo json_encode($out);'''

def main():
    token=os.environ.get('WHM_API_TOKEN','')
    if not token:
        print(json.dumps({'ok':False,'error':'existing_secret_unavailable','writes':False}));return 1
    client=Client(token)
    results=[]
    for user in ('welcome','city'):
        root='/home/'+user+'/public_html/roundcube'
        def call(function,**kwargs):
            return client.request('/json-api/uapi_cpanel',{'api.version':1,
                'cpanel.user':user,'cpanel.module':'Fileman','cpanel.function':function,**kwargs},3)
        row={'installation':user,'writes':False}
        try:
            data=call('get_file_content',dir=root+'/config',file='config.inc.php',from_charset='UTF-8',to_charset='UTF-8')
            if not isinstance(data,dict) or not isinstance(data.get('content'),str):raise ValueError()
            p=subprocess.run(['php','-r',PHP,json.dumps(KEYS)],input=data['content'],text=True,capture_output=True,timeout=15)
            if p.returncode:raise ValueError()
            row['explicit_static_settings']=json.loads(p.stdout)
            plugins=subprocess.run(['php','deploy/roundcube_config_summary.php'],input=data['content'],text=True,capture_output=True,timeout=15)
            row['active_plugins']=json.loads(plugins.stdout)['plugins'] if plugins.returncode==0 else '[not extracted]'
            defaults=call('get_file_content',dir=root+'/config',file='defaults.inc.php',from_charset='UTF-8',to_charset='UTF-8')
            if not isinstance(defaults,dict) or not isinstance(defaults.get('content'),str):raise ValueError()
            parsed=subprocess.run(['php','-r',PHP,json.dumps(KEYS)],input=defaults['content'],text=True,capture_output=True,timeout=15)
            if parsed.returncode:raise ValueError()
            row['installed_static_defaults']=json.loads(parsed.stdout)
            row['note']='Missing values may use defaults; complex expressions and secrets are not extracted.'
            files=call('list_files',dir=root)
            entries=files.get('files') if isinstance(files,dict) else files
            if not isinstance(entries,list):raise ValueError()
            row['installer_directory_present']=any(x.get('file')=='installer' for x in entries)
            row['directory_permissions']={x['file']:x.get('mode',x.get('permissions')) for x in entries if x.get('file') in ('config','logs','temp','plugins')}
        except Exception:
            row['error']='read_or_parse_failed_no_raw_details'
        results.append(row)
    print(json.dumps({'ok':all('error' not in r for r in results),'writes':False,'results':results}))
    return 0 if all('error' not in r for r in results) else 1

if __name__=='__main__':raise SystemExit(main())
