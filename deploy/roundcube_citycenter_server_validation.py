"""Run a fixed private one-shot server test and remove its temporary cron job."""
import json, os, sys, time
from pathlib import Path
import roundcube_citycenter_smtp2go_dns as dns
import roundcube_citycenter_requirements as requirements
import roundcube_citycenter_prepare as prep

FILE = 'server-validation-20261008.php'
MARKER = 'server-validation-20261008.json'
COMMAND = '/usr/local/bin/php ' + prep.PRIVATE + '/' + FILE + ' >/dev/null 2>&1'

def cron(client, function, **params):
    prep.require(function in ('fetchcron', 'add_line', 'remove_line'), 'cron_scope_violation')
    return client.request('/json-api/cpanel', {'cpanel_jsonapi_user': 'city', 'cpanel_jsonapi_apiversion': 2,
        'cpanel_jsonapi_module': 'Cron', 'cpanel_jsonapi_func': function, **params}, api_version=2)

def scheduled(client):
    return [r for r in cron(client, 'fetchcron') if r.get('type') == 'command' and r.get('command') == COMMAND]

def result(client):
    if MARKER not in client.file_inventory(prep.PRIVATE): return None
    prep.stat(client, prep.PRIVATE, MARKER, 'file', '0600')
    text = requirements.read(client, prep.PRIVATE, MARKER)
    lines = [json.loads(line) for line in text.splitlines() if line.strip()]
    prep.require(bool(lines) and all(isinstance(r, dict) for r in lines), 'server_validation_record_invalid')
    return lines[-1] if lines[-1].get('state') == 'complete' else None

def run():
    client = dns.Client(os.environ.get('WHM_API_TOKEN', ''))
    prep.require(client.account_for_domain() == 'city', 'account_scope_mismatch')
    prep.stat(client, prep.HOME, dns.BACKUP_DIR, 'dir', '0700')
    prep.stat(client, prep.PRIVATE, 'api-key.txt', 'file', '0600')
    source = Path(__file__).with_suffix('.php').read_text()
    names = client.file_inventory(prep.PRIVATE)
    if FILE not in names:
        client.request('/json-api/uapi_cpanel', {'api.version':1,'cpanel.user':'city','cpanel.module':'Fileman','cpanel.function':'save_file_content',
            'dir':prep.PRIVATE,'file':FILE,'content':source,'from_charset':'UTF-8','to_charset':'UTF-8'}, api_version=3, post=True)
        client.api2('fileop',op='chmod',sourcefiles=(prep.PRIVATE+'/'+FILE).removeprefix('/home/city/'),metadata='0600',doubledecode=0)
    prep.stat(client,prep.PRIVATE,FILE,'file','0600')
    prep.require(requirements.read(client,prep.PRIVATE,FILE)==source,'server_validation_source_mismatch')
    current = scheduled(client)
    prep.require(len(current) <= 1,'duplicate_validation_schedule')
    try:
        finished = result(client)
        if finished is None and MARKER not in names and not current:
            cron(client,'add_line',command=COMMAND,minute='*',hour='*',day='*',month='*',weekday='*')
            prep.require(len(scheduled(client))==1,'validation_schedule_unconfirmed')
        for _ in range(30):
            finished = result(client)
            if finished is not None: break
            time.sleep(5)
        prep.require(finished is not None,'server_validation_timeout_no_retry')
    finally:
        for row in scheduled(client):
            prep.require(str(row.get('line','')).isdigit() and int(row['line'])>0,'validation_schedule_identifier_invalid')
            cron(client,'remove_line',line=int(row['line']))
        prep.require(not scheduled(client),'temporary_cron_removal_unconfirmed')
    prep.require(finished.get('ok') is True and finished.get('server_send_accepted') is True,'server_send_not_confirmed_no_retry')
    # Retire only the one test program. Keep the private result and duplicate guard.
    client.api2('fileop',op='trash',sourcefiles=(prep.PRIVATE+'/'+FILE).removeprefix('/home/city/'),doubledecode=0)
    prep.require(FILE not in client.file_inventory(prep.PRIVATE),'test_program_retirement_unconfirmed')
    return {'ok':True,'server_send_accepted':True,'email_sent':True,'temporary_cron_removed':True,
            'test_program_retired':True,'credential_published':False,'production_config_changed':False}

def diagnose():
    import urllib.parse, urllib.request
    client=dns.Client(os.environ.get('WHM_API_TOKEN',''))
    prep.require(client.account_for_domain()=='city','account_scope_mismatch')
    params={'cpanel_jsonapi_user':'city','cpanel_jsonapi_apiversion':2,'cpanel_jsonapi_module':'Cron','cpanel_jsonapi_func':'fetchcron'}
    url=dns.ORIGIN+'/json-api/cpanel?'+urllib.parse.urlencode(params)
    request=urllib.request.Request(url,headers={'Authorization':'whm fazenda:'+client.token,'Accept':'application/json'})
    with client.opener.open(request,timeout=20) as response:
        prep.require(response.status==200 and response.geturl()==url,'cron_diagnostic_http_failed')
        payload=json.loads(response.read(1024*1024))
    data=payload.get('cpanelresult',{})
    rows=data.get('data',[])
    rows=rows if isinstance(rows,list) else []
    messages=[str(data.get('error',''))]
    for row in rows:
        if isinstance(row,dict):
            messages.extend(str(row.get(k,'')) for k in ('reason','err','statusmsg'))
    message=' '.join(messages).lower()
    names=client.file_inventory(prep.PRIVATE)
    final=result(client)
    return {'ok':True,'read_only':True,'cron_event_success':data.get('event',{}).get('result')==1,
            'cron_reports_no_crontab':'no crontab' in message,'cron_reports_missing_function':any(x in message for x in ('unknown function','does not exist','not found')),
            'cron_reports_permission_denied':any(x in message for x in ('permission denied','not permitted','access denied')),
            'cron_entry_count':sum(r.get('type')=='command' for r in rows if isinstance(r,dict)),
            'temporary_test_cron_count':sum(r.get('command')==COMMAND for r in rows if isinstance(r,dict)),
            'test_program_present':FILE in names,'test_marker_present':MARKER in names,
            'server_send_accepted':bool(final and final.get('server_send_accepted')),'credential_published':False}

if __name__=='__main__':
    try: print(json.dumps(diagnose() if '--diagnose' in sys.argv else run(),sort_keys=True))
    except dns.OperationError as error:
        print(json.dumps({'ok':False,'error':str(error),'delivery_state':'requires_review','automatic_retry':False}));sys.exit(1)
    except Exception:
        print(json.dumps({'ok':False,'error':'server_state_requires_review','automatic_retry':False}));sys.exit(1)
