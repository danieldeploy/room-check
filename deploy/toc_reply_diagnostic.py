"""Read-only reply diagnosis; no private mail contents/identifiers in output."""
import email.policy
import email.utils
from email.parser import BytesParser
import json
import re
import subprocess
import sys
import time
from cloudbeds_mail_inspect import MailAPI, child_name
from hostelworld_filters import FilterAPI, rows
from cpanel_api import DeploymentError, require
from cpanel_disk_cleanup import request_api

MAIL_ROOT = '/home/welcome/mail/welcomehostel.pt/daniel.ciorcas'
PIPE = 'room-check-private/cron/toconline-reply.php'
MAX_BYTES = 262144
READ_MAX_BYTES = 4 * 1024 * 1024
AUTH = {'verified','unverified','unsigned','unaligned','unsupported','invalid',
        'body_mismatch','signature_mismatch','dns_unavailable','expired'}
OUTCOMES = {'toc_accepted','toc_existing','toc_review'}


def failure_shape(text):
    value=text.lower()
    markers=['spam','quota','blacklist','blocklist','rate limit','too many','max defers',
             'maximum allowed','timeout','timed out','unrouteable','relay','spf','dkim','dmarc',
             'virus','malware','message size','message rejected','retry time not reached',
             'connection refused','greylist','recipient rejected','mailbox unavailable']
    return {'smtp_codes':sorted(set(re.findall(r'\b[45][0-9]{2}\b',value))),
            'enhanced_codes':sorted(set(re.findall(r'\b[45]\.[0-9]{1,3}\.[0-9]{1,3}\b',value))),
            'markers':[word for word in markers if word in value]}


def delivery_shapes(records,now=None):
    now=time.time() if now is None else now
    require(isinstance(records,list) and len(records)<=10000,'delivery_limit')
    shapes=set()
    for row in records:
        if not isinstance(row,dict): continue
        if not re.fullmatch(r'[0-9]{9}@my\.toconline\.pt',str(row.get('recipient','')),re.I): continue
        stamp=str(row.get('actionunixtime',''))
        if not stamp.isdigit() or not now-3*86400<=int(stamp)<=now+300: continue
        shape={'state':row.get('type') if row.get('type') in ('success','defer','failure','inprogress') else 'unknown',
               **failure_shape(str(row.get('message',''))+' '+str(row.get('reason','')))}
        shapes.add(json.dumps(shape,sort_keys=True))
    return [json.loads(s) for s in sorted(shapes)]


def filter_shape(filters):
    report = dict(sender_rule_found=False, correct_pipe_found=False,
                  mailbox_copy_action=False, stopping_action_present=False, disabled_pipe=False)
    for f in rows(filters):
        rule = any(r.get('part') == '$header_from:' and
                   r.get('val','').lower() == 'no_reply@toconline.pt' for r in f.get('rules',[]))
        report['sender_rule_found'] |= rule
        for a in f.get('actions',[]):
            destination = str(a.get('dest','')).strip(' \t\"\'|')
            destination = re.sub(r'^(?:/home/welcome/|\$home/)', '', destination)
            correct = a.get('action') == 'pipe' and destination == PIPE
            report['correct_pipe_found'] |= correct
            report['disabled_pipe'] |= correct and bool(f.get('disabled',False))
            report['mailbox_copy_action'] |= rule and a.get('action') == 'save'
            report['stopping_action_present'] |= a.get('action') in ('finish','discard','fail')
    return report


def verify_message(raw):
    program = ("require 'src/Invoices/InvoiceTocPipe.php'; $r=stream_get_contents(STDIN); "
               "$p=InvoiceTocPipe::parse($r); $a=InvoiceTocAuthentication::verify($r); "
               "echo json_encode(['authentication'=>$a['code'],'outcome'=>$p['outcome']??'toc_review']);")
    result = subprocess.run(['php','-d','display_errors=0','-r',program], input=raw,
                            stdout=subprocess.PIPE,stderr=subprocess.DEVNULL,timeout=25,check=True)
    require(len(result.stdout)<2048,'invalid_verifier_result')
    return json.loads(result.stdout)


def message_shape(raw, verifier=None):
    require(len(raw)<=READ_MAX_BYTES,'message_too_large')
    m = BytesParser(policy=email.policy.default).parsebytes(raw)
    senders = email.utils.getaddresses(m.get_all('From',[]))
    if len(senders)!=1: return None
    sender=senders[0][1].lower()
    toc=sender=='no_reply@toconline.pt'
    bounce=sender.split('@')[0] in ('mailer-daemon','postmaster','mailnull')
    if not toc and not bounce: return None
    parts=list(m.walk()); require(len(parts)<=64,'mime_parts')
    text='\n'.join(p.get_content() for p in parts if p.get_content_type() in ('text/plain','text/html')
                   and p.get_content_disposition()!='attachment')
    if bounce:
        original=bool(re.search(r'toc-[a-f0-9]{64}@check\.welcomehostel\.pt',text+'\n'+raw.decode('latin-1'),re.I))
        if not original and not re.search(r'[0-9]{9}@my\.toconline\.pt',text,re.I): return None
        return {'kind':'delivery_failure','original_identifier':original,**failure_shape(text)}
    oversized=len(raw)>MAX_BYTES
    proof={} if oversized else (verifier or verify_message)(raw)
    filenames=re.findall(r'[a-zA-Z0-9._-]+\.(?:pdf|jpe?g)',text,re.I)
    return {'receipt_identifier':bool(re.search(r'receipt[-_][a-f0-9]{64}\.(?:pdf|jpe?g)',text,re.I)),
            'portal_identifier':bool(re.search(r'(?:booking|hostelworld|cloudbeds)[-_][a-f0-9]{64}\.pdf',text,re.I)),
            'over_pipe_limit':oversized,
            'success_subject':str(m.get('Subject','')).strip()=='Ficheiros arquivados com sucesso - Active Lines, Lda',
            'duplicate_phrase':'por já constarem no seu arquivo' in text.lower(),
            'other_document_name':any(not re.fullmatch(r'(?:booking|hostelworld|cloudbeds|receipt)[-_][a-f0-9]{64}\.(?:pdf|jpe?g)',n,re.I) for n in filenames),
            'document_attachment':any(p.get_content_type() in ('application/pdf','image/jpeg') for p in parts),
            'authentication':'not_checked_size' if oversized else proof.get('authentication') if proof.get('authentication') in AUTH else 'unrecognized',
            'outcome':'not_checked_size' if oversized else proof.get('outcome') if proof.get('outcome') in OUTCOMES else 'unrecognized'}


class TocMailAPI(MailAPI):
    def __init__(self):
        super().__init__('welcome')
        self.root=MAIL_ROOT
        self.allowed_dirs={MAIL_ROOT}

    def inspect_replies(self):
        cutoff=time.time()-3*86400
        # PHP mail's envelope failures can return to the cPanel account mailbox.
        # Inspect only its cur/new, never other domain/account subdirectories.
        folders=[self.root,'/home/welcome/mail']
        for row in self.listing(self.root):
            if row.get('file') in ('.','..'): continue
            name=child_name(row)
            if row.get('type')=='dir' and name.startswith('.'): folders.append(self.root+'/'+name)
        require(len(folders)<=100,'folder_limit')
        candidates=[]; oversized=False
        for folder in folders:
            self.allowed_dirs.add(folder)
            for row in self.listing(folder):
                if row.get('type')!='dir' or row.get('file') not in ('cur','new'): continue
                directory=folder+'/'+row['file']; self.allowed_dirs.add(directory)
                for entry in self.listing(directory):
                    if entry.get('type')!='file' or float(entry.get('mtime',0))<cutoff: continue
                    name=child_name(entry); size=int(entry.get('size',0))
                    oversized |= size>READ_MAX_BYTES
                    if 0<size<=READ_MAX_BYTES: candidates.append((float(entry['mtime']),directory,name,size))
        budget=32*1024*1024; shapes=set(); scanned=0
        for _,directory,name,size in sorted(candidates,reverse=True)[:200]:
            if budget<size: break
            budget-=size; scanned+=1
            self.allowed_files.add((directory,name))
            value=self.call('get_file_content',dir=directory,file=name,
                            from_charset='ISO-8859-1',to_charset='UTF-8',update_html_document_encoding=0)
            if isinstance(value,list) and len(value)==1: value=value[0]
            require(isinstance(value,dict) and isinstance(value.get('content'),str),'invalid_content')
            raw=value['content'].encode('latin-1'); require(len(raw)<=READ_MAX_BYTES,'message_too_large')
            shape=message_shape(raw)
            if shape: shapes.add(json.dumps(shape,sort_keys=True))
            del raw,value
        return {'recent_messages_present':bool(candidates),'scan_complete':scanned==len(candidates),
                'oversized_messages_skipped':oversized,'reply_shapes':[json.loads(s) for s in sorted(shapes)]}


def main():
    if sys.argv[1:]: return 2
    stage='filters'
    try:
        api=FilterAPI('welcome')
        print(json.dumps({'filters':filter_shape(api.call('Email','list_filters',
                          account='daniel.ciorcas@welcomehostel.pt'))}),flush=True)
        stage='transport'
        result=request_api('EmailTrack','search',{'success':1,'defer':1,'failure':1,'inprogress':1},legacy=True)
        print(json.dumps({'transport':{'overflowed':bool(result.get('metadata',{}).get('overflowed')),
                         'shapes':delivery_shapes(result.get('data',[]))}}),flush=True)
        stage='mailbox'
        print(json.dumps({'mailbox':TocMailAPI().inspect_replies()}),flush=True)
        return 0
    except Exception as error:
        print(json.dumps({'failed_stage':stage,'error_type':type(error).__name__}))
        return 1

if __name__=='__main__': sys.exit(main())
