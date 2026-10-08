"""Activate the fixed City Center transport after verified server send, with rollback."""
import json, os, subprocess, sys
from pathlib import Path
import roundcube_citycenter_smtp2go_dns as dns
import roundcube_citycenter_requirements as requirements
import roundcube_citycenter_prepare as prep
import roundcube_citycenter_server_validation as validation

MAIN_DIR = prep.ROOT + '/config'
MAIN_FILE = 'config.inc.php'
ENABLE = "<?php\n$config['citycenter_smtp2go_api_enabled'] = true;\n"
DISABLE = prep.sources()['config.inc.php']

def configuration(original):
    parsed = subprocess.run(['php','deploy/roundcube_config_summary.php'], input=original, text=True, capture_output=True, timeout=20)
    prep.require(parsed.returncode==0,'original_config_analysis_failed')
    summary=json.loads(parsed.stdout)
    prep.require('citycenter_smtp2go_api' not in summary['plugins'],'backup_already_contains_plugin')
    return original + ('' if summary['php_open'] else '\n<?php\n') + "\n// City Center SMTP2GO transport, limited to its verified production mailbox.\n$config['plugins'][] = 'citycenter_smtp2go_api';\n"

def write(client, directory, filename, content):
    prep.require((directory,filename) in {(MAIN_DIR,MAIN_FILE),(prep.PLUGIN,'config.inc.php')},'activation_write_scope_violation')
    client.request('/json-api/uapi_cpanel',{'api.version':1,'cpanel.user':'city','cpanel.module':'Fileman','cpanel.function':'save_file_content',
        'dir':directory,'file':filename,'content':content,'from_charset':'UTF-8','to_charset':'UTF-8'},api_version=3,post=True)
    prep.require(requirements.read(client,directory,filename)==content,'activation_readback_mismatch')

def activate():
    client=dns.Client(os.environ.get('WHM_API_TOKEN',''))
    prep.require(client.account_for_domain()=='city','account_scope_mismatch')
    for parent,name,kind,mode in ((prep.HOME,dns.BACKUP_DIR,'dir','0700'),(prep.PRIVATE,prep.BACKUP,'file','0600'),(prep.PRIVATE,'api-key.txt','file','0600'),(prep.PRIVATE,'live','dir','0700')):
        prep.stat(client,parent,name,kind,mode)
    records=client.dns_records()
    prep.require(all(len(dns.matching(records,record))==1 for record in dns.RECORDS),'dns_authentication_incomplete')
    report=validation.result(client)
    prep.require(report and report.get('ok') is True and report.get('server_send_accepted') is True,'server_send_verification_required')
    prep.require(not validation.scheduled(client),'temporary_cron_still_present')
    original=requirements.read(client,prep.PRIVATE,prep.BACKUP)
    desired=configuration(original)
    current=requirements.read(client,MAIN_DIR,MAIN_FILE)
    setting=requirements.read(client,prep.PLUGIN,'config.inc.php')
    prep.require(current in (original,desired),'main_configuration_conflict')
    prep.require(setting in (DISABLE,ENABLE),'plugin_setting_conflict')
    for name in ('api_transport.php','citycenter_smtp2go_api.php'):
        prep.stat(client,prep.PLUGIN,name,'file','0644')
        expected=(Path(__file__).with_name('roundcube-citycenter-live')/name).read_text()
        prep.require(requirements.read(client,prep.PLUGIN,name)==expected,'plugin_source_mismatch')
    if current==desired and setting==ENABLE:
        return {'ok':True,'plugin_enabled':True,'already_active':True,'server_send_verified':True,'backup_verified':True,'other_mailboxes_changed':False}
    prep.require(current==original and setting==DISABLE,'partial_activation_requires_review')
    # Recheck the current system, including version and active hooks, immediately before the switch.
    audit=requirements.audit()
    prep.require(audit['production_mailbox_exists'] and audit['roundcube_version_matches_transport'] and audit['mail_hook_conflict_count']==0,'activation_prerequisites_changed')
    # Lint the full configuration privately; never print PHP errors containing source values.
    import tempfile
    with tempfile.TemporaryDirectory() as folder:
        path=Path(folder)/'config.php';path.write_text(desired)
        lint=subprocess.run(['php','-l',str(path)],capture_output=True,timeout=20)
        prep.require(lint.returncode==0,'desired_config_lint_failed')
    attempted=False
    try:
        write(client,prep.PLUGIN,'config.inc.php',ENABLE)
        prep.require(requirements.read(client,MAIN_DIR,MAIN_FILE)==original,'config_changed_before_activation')
        attempted=True
        write(client,MAIN_DIR,MAIN_FILE,desired)
        prep.require(requirements.read(client,prep.PLUGIN,'config.inc.php')==ENABLE,'enable_setting_verification_failed')
    except dns.OperationError:
        if attempted:
            state=requirements.read(client,MAIN_DIR,MAIN_FILE)
            prep.require(state in (original,desired),'rollback_conflict_requires_review')
            if state==desired: write(client,MAIN_DIR,MAIN_FILE,original)
        write(client,prep.PLUGIN,'config.inc.php',DISABLE)
        raise
    return {'ok':True,'plugin_enabled':True,'main_config_verified':True,'server_send_verified':True,
            'backup_verified':True,'other_mailboxes_changed':False,'email_sent_during_activation':False,'private_contents_published':False}

if __name__=='__main__':
    try:print(json.dumps(activate(),sort_keys=True))
    except dns.OperationError as error:
        print(json.dumps({'ok':False,'error':str(error),'activation_state':'requires_review'}));sys.exit(1)
    except Exception:
        print(json.dumps({'ok':False,'error':'activation_state_requires_review'}));sys.exit(1)
