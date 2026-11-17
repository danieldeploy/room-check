#!/usr/bin/env python3
"""Recover only receipt pause deployment 431 after read-only cause verification."""
import argparse
import json
import os
import sys
import re
import hashlib
from pathlib import Path
from cpanel_disk_cleanup import request_api
from automated_release import (Config,CpanelAPI,Deployment,WHM_ORIGIN,BRANCH,GitHub,commit,require,
    DeploymentError,release,inspect_release_state,health_check,require_backup_gate)

FAILED_ID="431"
FAILED_SHA="dc4eb6eda39d50761f3d8c765698c0152609d090"

def verify_failure_log(task):
    path=task.get('log_path','')
    require(re.fullmatch(r'/home/welcome/\.cpanel/logs/vc_[A-Za-z0-9_.-]+_git_deploy\.log',path), 'release_log_path_not_allowed')
    def read(directory,name):
        value=request_api('Fileman','get_file_content',{'dir':directory,'file':name})
        if isinstance(value,list):
            require(len(value)==1,'release_read_ambiguous');value=value[0]
        require(isinstance(value,dict) and isinstance(value.get('content'),str),'release_read_invalid')
        return value['content']
    text=read('/home/welcome/.cpanel/logs',path.rsplit('/',1)[1])
    require(re.search(r'Cannot (?:redeclare|declare) class TranslationProtection',text)
            and 'public_html/check/src/I18n/TranslationProtection.php' in text
            and '"receipt_wait_pause":' not in text,'receipt_pause_failure_not_confirmed')
    installed=read('/home/welcome/public_html/check/src/I18n','InvoiceText.php')
    expected=(Path(__file__).resolve().parents[1]/'src/I18n/InvoiceText.php').read_bytes()
    require(hashlib.sha256(installed.encode()).digest()==hashlib.sha256(expected).digest(),'installed_receipt_source_changed')

def latest_task(runner):
    tasks=runner.tasks()
    require(bool(tasks),"deployment_history_missing")
    for t in tasks:
        require(runner.task_state(t) in ("succeeded","failed","canceled"),"deployment_busy_or_unknown")
    def stamp(t):
        return max(float(x) for x in t["timestamps"].values() if x not in (None,"",0,"0"))
    latest=max(stamp(t) for t in tasks)
    matches=[t for t in tasks if stamp(t)==latest]
    require(len(matches)==1,"ambiguous_deployment_history")
    return matches[0]

def known_failure(runner,t):
    require(runner.task_id(t)==FAILED_ID and runner.task_state(t)=="failed",
            "uninspected_deployment_failure")
    state=t.get("repository_state",{})
    require(state.get("branch")==BRANCH and state.get("identifier")==FAILED_SHA,
            "failed_deployment_commit_mismatch")

def inspect(runner):
    t=latest_task(runner)
    if runner.task_state(t)=="succeeded": return inspect_release_state(runner)
    known_failure(runner,t)
    verify_failure_log(t)
    row=runner.repository(); current=runner.inspect(row)
    return {"mode":"inspected-receipt-runtime-recovery","writes":False,"current_commit":current,
            "failed_deploy_id":FAILED_ID,"failed_commit":FAILED_SHA,
            "last_successful_commit":row.get('last_deployment',{}).get('repository_state',{}).get('identifier'),
            "installed_invoice_text":"verified","cause":"duplicate_translation_class_before_pause"}

def recover(runner,github,expected):
    expected=commit(expected)
    t=latest_task(runner)
    if runner.task_state(t)=="succeeded": return release(runner,github,expected)
    known_failure(runner,t)
    verify_failure_log(t)
    require_backup_gate()
    github.require_head(expected)
    require(expected!=FAILED_SHA,"corrected_commit_required")
    github.require_fast_forward(FAILED_SHA,expected)
    current=runner.inspect(runner.repository())
    require(current in (FAILED_SHA,expected),"unexpected_recovery_current_commit")
    if current!=expected: runner.update(current,expected)
    # Refuse a new failure or another deployment appearing during the update.
    known_failure(runner,latest_task(runner))
    github.require_head(expected)
    runner.inspect(runner.repository(),expected)
    result=runner.deploy(expected)
    health=health_check()
    github.require_head(expected)
    return {**result,"recovered_deploy_id":FAILED_ID,"health":health}

def main():
    parser=argparse.ArgumentParser()
    group=parser.add_mutually_exclusive_group(required=True)
    group.add_argument("--inspect-state",action="store_true")
    group.add_argument("--expected-commit")
    args=parser.parse_args()
    runner=Deployment(CpanelAPI(Config(token=os.environ.get("WHM_API_TOKEN",""),
        transport="whm",origin=WHM_ORIGIN,wait_timeout=600)),
        Config(token=os.environ.get("WHM_API_TOKEN",""),transport="whm",origin=WHM_ORIGIN,wait_timeout=600))
    result=inspect(runner) if args.inspect_state else recover(runner,GitHub(os.environ.get("GITHUB_TOKEN","")),args.expected_commit)
    print(json.dumps({"ok":True,**result}))

if __name__=="__main__":
    try: main()
    except DeploymentError as e:
        print(json.dumps({"ok":False,"error":str(e),"deploy_id":e.deploy_id}),file=sys.stderr)
        sys.exit(1)
    except Exception:
        print('{"ok":false,"error":"receipt_runtime_recovery_failed_inspect_server"}',file=sys.stderr)
        sys.exit(1)
