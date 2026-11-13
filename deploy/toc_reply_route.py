"""Add only the Hub reply pipe; preserve existing mailbox filing and other rules."""
import json
import re
import sys
from hostelworld_filters import FilterAPI, rows
from cpanel_api import DeploymentError, require

NAME='Management Hub - TOConline replies v2'
ACCOUNT='daniel.ciorcas@welcomehostel.pt'
PIPE='room-check-private/cron/toconline-reply.php'


def sender_rule(f):
    rules=f.get('rules',[])
    return not f.get('disabled') and len(rules)==1 and rules[0].get('part')=='$header_from:' and rules[0].get('match') in ('is','contains') and rules[0].get('val','').lower()=='no_reply@toconline.pt'


def pipe_action(a):
    value=str(a.get('dest','')).strip(' \t\"\'|')
    value=re.sub(r'^(?:/home/welcome/|\$home/)', '', value)
    return a.get('action')=='pipe' and value==PIPE


def projection(f):
    return {k:f.get(k) for k in ('filtername','rules','actions','disabled')}


def install(api):
    before=rows(api.call('Email','list_filters',account=ACCOUNT))
    owned=[f for f in before if f.get('filtername')==NAME]
    require(len(owned)<=1,'ambiguous_filter')
    require(not any(a.get('action') in ('finish','discard','fail') for f in before for a in f.get('actions',[])),'stopping_filter')
    require(any(sender_rule(f) and any(a.get('action')=='save' and not str(a.get('dest','')).lstrip().startswith('|') for a in f.get('actions',[])) for f in before),'mail_copy_missing')
    if owned:
        f=owned[0]
        require(sender_rule(f) and len(f.get('actions',[]))==1 and pipe_action(f['actions'][0]),'owned_filter_conflict')
        return
    # An existing pipe must be investigated before adding a second one.
    require(not any(a.get('action')=='pipe' or str(a.get('dest','')).lstrip().startswith('|') for f in before if sender_rule(f) for a in f.get('actions',[])),'existing_pipe_requires_inspection')
    api.call('Email','store_filter',account=ACCOUNT,filtername=NAME,part1='$header_from:',
             match1='contains',val1='no_reply@toconline.pt',opt1='and',action1='pipe',dest1=PIPE)
    after=rows(api.call('Email','list_filters',account=ACCOUNT))
    ours=[f for f in after if f.get('filtername')==NAME]
    require(len(ours)==1 and sender_rule(ours[0]) and len(ours[0].get('actions',[]))==1 and pipe_action(ours[0]['actions'][0]),'pipe_readback_failed')
    require([projection(f) for f in before]==[projection(f) for f in after if f.get('filtername')!=NAME],'existing_filters_changed')


def main():
    if sys.argv[1:]!=['--apply']: return 2
    try:
        install(FilterAPI('welcome'))
        print(json.dumps({'toc_reply_route':'verified','existing_mail_filing':'preserved'}));return 0
    except DeploymentError as e:
        known={'ambiguous_filter','stopping_filter','mail_copy_missing','owned_filter_conflict','existing_pipe_requires_inspection','pipe_readback_failed','existing_filters_changed'}
        print(json.dumps({'toc_reply_route':'blocked','reason':str(e) if str(e) in known else 'hosting_api_error'}));return 1
    except Exception:
        print(json.dumps({'toc_reply_route':'blocked','reason':'hosting_api_error'}));return 1

if __name__=='__main__':sys.exit(main())
