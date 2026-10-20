#!/usr/bin/env python3
"""Recover only inspected deployment 288; every other failure keeps the normal gate."""
import argparse
import json
import os
import sys
from automated_release import (Config,CpanelAPI,Deployment,WHM_ORIGIN,BRANCH,GitHub,commit,require,
    DeploymentError,release,inspect_release_state,health_check,require_backup_gate)

FAILED_ID="288"
FAILED_SHA="eb45c3be90d12e4295e262f0f10e3916dbe3b962"

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
    row=runner.repository(); current=runner.inspect(row)
    return {"mode":"inspected-hostelworld-recovery","writes":False,"current_commit":current,
            "failed_deploy_id":FAILED_ID,"failed_commit":FAILED_SHA,
            "cause":"hostelworld_account_binding_missing_or_ambiguous"}

def recover(runner,github,expected):
    expected=commit(expected)
    t=latest_task(runner)
    if runner.task_state(t)=="succeeded": return release(runner,github,expected)
    known_failure(runner,t)
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
        print('{"ok":false,"error":"hostelworld_recovery_failed_inspect_server"}',file=sys.stderr)
        sys.exit(1)
