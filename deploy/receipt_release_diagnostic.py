#!/usr/bin/env python3
"""Read-only diagnosis of the exact failed scan-PDF release; no raw logs/config output."""
import hashlib,json,re,sys
from cpanel_disk_cleanup import request_api,Rejected
FAILED_ID='390'
FAILED_SHA='372a1f13a4adf84e1096448e7a908fe57372a887'
APP='/home/welcome/public_html/check'

def content(path):
    value=request_api('Fileman','get_file_content',{'dir':path.rsplit('/',1)[0],'file':path.rsplit('/',1)[1]})
    if isinstance(value,list): value=value[0]
    if not isinstance(value,dict) or not isinstance(value.get('content'),str): raise Rejected('file_content_unavailable')
    return value['content']

def metadata(path):
    try:
        value=request_api('Fileman','get_file_information',{'path':path})
        if isinstance(value,list): value=value[0]
        if not isinstance(value,dict): raise Rejected('metadata_unavailable')
        return {'available':True,'exists':bool(value.get('exists')),'mode':str(value.get('nicemode')) if re.fullmatch('[0-7]{3,4}',str(value.get('nicemode'))) else None}
    except Rejected: return {'available':False}

def summarize_log(text):
    facts={'classifications':[code for code in ['receipt_split_unavailable','receipt_split_failed','receipt_split_limit','private_storage_unavailable','private_storage_permissions','private_write_failed','Parse error','Fatal error','Permission denied','No such file'] if code in text]}
    for line in text.splitlines():
        marker=line.find('{')
        if marker<0: continue
        try: value,_=json.JSONDecoder().raw_decode(line[marker:])
        except ValueError: continue
        if not isinstance(value,dict): continue
        if value.get('receipt_pdf_split') in ('passed','failed'):
            facts['receipt_pdf_split']=value['receipt_pdf_split']
        if value.get('stage') in ('preconditions','application_backup','database_backup'):
            facts['backup_stage']=value['stage']; facts['backup_ok']=value.get('ok') is True
    return facts

def configured_node(text):
    match=re.search(r"['\"]node_binary['\"]\s*=>\s*['\"]([^'\"]+)['\"]",text)
    value=match[1] if match else None
    if value and re.fullmatch(r'/(?:usr|opt|home/welcome)/[A-Za-z0-9_./-]{1,200}/node',value) and '..' not in value.split('/'):
        return value
    return None

def cron_nodes(rows):
    if not isinstance(rows,list) or len(rows)>100: return []
    paths=set()
    for row in rows:
        cmd=row.get('command','') if isinstance(row,dict) else ''
        if not isinstance(cmd,str): continue
        for match in re.finditer(r"(?:^|[=\s\"'])(/(?:usr|opt|home/welcome)/[A-Za-z0-9_./-]{1,200}/node)(?=$|[\s\"'])",cmd):
            if '..' not in match[1].split('/'): paths.add(match[1])
    return sorted(paths)[:10]

def main():
    tasks=request_api('VersionControlDeployment','retrieve',{})
    matches=[t for t in tasks if str(t.get('deploy_id'))==FAILED_ID and t.get('repository_state',{}).get('identifier')==FAILED_SHA]
    if len(matches)!=1: raise Rejected('exact_failed_deployment_missing')
    task=matches[0]; log=task.get('log_path','')
    if not re.fullmatch(r'/home/welcome/\.cpanel/logs/vc_[A-Za-z0-9_.-]+_git_deploy\.log',log): raise Rejected('release_log_not_allowed')
    print(json.dumps({'read_only':True,'deploy_id':FAILED_ID,'commit':FAILED_SHA,'release':summarize_log(content(log))}))
    try:
        text=content(APP+'/config.local.php'); node=configured_node(text)
        print(json.dumps({'node_config':{'literal_absolute_path':node is not None,'field_present':'node_binary' in text}})); del text
    except Rejected: node=None; print(json.dumps({'node_config':{'readable':False}}))
    try: cron_paths=cron_nodes(request_api('Cron','listcron',{},legacy=True).get('data'))
    except Rejected: cron_paths=[]
    print(json.dumps({'cron_node_paths':cron_paths}))
    candidates=list(dict.fromkeys(([node] if node else [])+cron_paths+['/usr/bin/node','/usr/local/bin/node','/opt/cpanel/ea-nodejs22/bin/node','/opt/cpanel/ea-nodejs24/bin/node']))
    for path in candidates: print(json.dumps({'node_path':path,**metadata(path)}))
    print(json.dumps({'installed_receipt_pdf_class':metadata(APP+'/src/Invoices/InvoiceReceiptPdf.php')}))
    print(json.dumps({'invoice_private_directory':metadata('/home/welcome/room-check-private/invoices')}))
    print(json.dumps({'installed_receipts_sha256':hashlib.sha256(content(APP+'/src/Invoices/InvoiceReceipts.php').encode()).hexdigest()}))

if __name__=='__main__':
    try: main()
    except Exception: print('{"ok":false,"error":"receipt_release_diagnostic_unavailable","read_only":true}');sys.exit(1)
