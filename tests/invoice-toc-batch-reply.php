<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/src/Invoices/InvoiceReceipts.php';
function batchCheck(bool $ok,string $message): void {if (!$ok) throw new RuntimeException($message);}
$dir=sys_get_temp_dir().'/hub-batch-reply-'.bin2hex(random_bytes(7));
mkdir($dir,0700);file_put_contents($dir.'/master.key',random_bytes(32));chmod($dir.'/master.key',0600);
try {
    $vault=new InvoiceVault($dir);$toc=new InvoiceToconline(new PDO('sqlite::memory:'),$vault,new stdClass());
    $settings=['sender'=>'test@welcomehostel.pt','nif'=>'500000000','enabled'=>true,'pilot_verified'=>true];
    $vault->save('toconline-settings.enc',$settings);
    $now=time();$auth=['verified'=>true,'code'=>'verified','recipient'=>$settings['sender'],'signed_at'=>gmdate('c',$now)];
    $ledger=[];$docs=[];$key=static fn(string $id)=>hash('sha256',$id);
    $mail=$key('first-send');$issuer='500000001';$other='500000002';
    $add=function(string $id,string $nif,string $number,string $batch,string $state='toc_submitted',int $offset=-700) use (&$ledger,&$docs,$key,$settings,$now):void {
        $docs[$id]=['id'=>$id,'company_nif'=>$settings['nif'],'issuer_nif'=>$nif,'invoice_number'=>$number,'toconline_state'=>$state];
        $ledger[$key($id)]=['nif'=>$settings['nif'],'sender'=>$settings['sender'],'document_id'=>'receipt:'.$id,
            'state'=>$state,'email_id'=>$batch,'attempted_at'=>gmdate('c',$now+$offset)];
    };
    $store=function() use ($vault,&$ledger,&$docs):void {
        $vault->save('toconline-ledger.enc',$ledger);$vault->save(InvoiceReceipts::FILE,['documents'=>$docs]);
    };
    $receipt=function(array $report,?array $sign=null) use ($auth,$toc):array {
        return $toc->receive('no_reply@toconline.pt','','',hash('sha256',json_encode($report)),['authentication'=>$sign??$auth,'batch_reply'=>$report]);
    };
    $fiscal=static fn($nif,$num,$ref,$status='toc_accepted')=>['issuer_nif'=>$nif,'invoice_number'=>$num,'reference'=>$ref,'status'=>$status];
    $report=static fn(array $named,array $purchases)=>['named'=>$named,'purchases'=>$purchases,'unassigned'=>0,'complete'=>true];
    $add('one',$issuer,'FR LOCAL/1',$mail);$add('two',$issuer,'FR LOCAL/2',$mail);
    $add('three',$other,'FR EXACT/3',$mail);$add('four',$other,'FR LOCAL/4',$mail);
    $mixed=$report([$key('four')=>['state'=>'toc_rejected','reason'=>'toc_provider_format']],[
        $fiscal($issuer,'','FC S/1'),$fiscal($issuer,'','FC S/2'),$fiscal($other,'FR EXACT/3','FC S/3'),
    ]);
    $store();$receipt($mixed);$projected=$vault->read(InvoiceReceipts::FILE)['documents'];
    foreach (['one','two'] as $id) batchCheck($projected[$id]['toconline_state']==='toc_accepted'
        && $projected[$id]['toc_confirmation_basis']==='batch' && $projected[$id]['toc_supplier_confirmed']===2
        && $projected[$id]['toc_supplier_total']===2,'all and only the two supplier files were confirmed by batch');
    batchCheck($projected['three']['toc_confirmation_basis']==='individual'
        && $projected['four']['toconline_state']==='toc_rejected' && $projected['four']['delivery_reason']==='toc_provider_format'
        && $projected['three']['toc_supplier_confirmed']===1,'signed exact fiscal identity and named refusal are independent');
    $before=$vault->read('toconline-ledger.enc');$receipt($mixed);
    batchCheck($before===$vault->read('toconline-ledger.enc'),'identical replay preserves all ledger data');

    $pure=$report([],[$fiscal($issuer,'','FC Y/1'),$fiscal($issuer,'','FC Y/2'),$fiscal($other,'','FC Y/3'),$fiscal($other,'','FC Y/4')]);
    $decide=static function(array $r) use (&$ledger,&$docs,$settings,$now):array {
        return InvoiceTocBatchReply::decide($ledger,$docs,$settings,$now,$r);
    };
    $d=$decide($pure);batchCheck(count($d)===4 && array_column($d,'basis')===array_fill(0,4,'batch'),'unique whole multiset and distinct purchase references identify the send');
    $ledger[$key('one')]['state']='toc_rejected';$d=$decide($pure);
    batchCheck($d['receipt:two']['reason']==='toc_reply_batch_conflict' && !$d['receipt:two']['toc_supplier_counts_known']
        && $d['receipt:three']['state']==='toc_accepted','an anonymous all-positive group cannot contradict a previous refusal or certify a conflicting count');
    $ledger[$key('one')]['state']='toc_submitted';
    $incomplete=$pure;$incomplete['complete']=false;batchCheck($decide($incomplete)===[],'unnamed partial reply cannot select a send');
    $conflict=$pure;$conflict['purchases'][1]['reference']='FC Y/1';batchCheck($decide($conflict)===[],'a repeated purchase reference never counts as a second positive');
    $conflict=$mixed;$conflict['purchases'][0]['invoice_number']='FR LOCAL/1';
    $conflict['purchases'][1]=array_replace($conflict['purchases'][0],['invoice_number'=>'FR LOCAL/2']);
    $d=$decide($conflict);batchCheck($d['receipt:one']['state']==='toc_review' && $d['receipt:two']['state']==='toc_review','conflicting provider references do not confirm two different exact invoice numbers');
    $pending=$pure;$pending['purchases'][3]['status']='pending';$pending['purchases'][3]['reference']='';
    $d=$decide($pending);batchCheck($d['receipt:one']['state']==='toc_accepted'
        && $d['receipt:three']['state']==='toc_review' && $d['receipt:three']['toc_supplier_confirmed']===1 && $d['receipt:three']['reason']==='toc_provider_preparing'
        && $d['receipt:four']['reason']==='toc_provider_preparing','an unattributed pending row protects every possible supplier file from another send');
    $pending['purchases'][3]['invoice_number']='FR LOCAL/4';$d=$decide($pending);
    batchCheck($d['receipt:four']['reason']==='toc_provider_preparing' && $d['receipt:three']['state']==='toc_accepted','specifically identified pending leaves the proven sibling accepted');
    $incomplete=$mixed;array_shift($incomplete['purchases']);
    $d=$decide($incomplete);batchCheck($d['receipt:one']['state']==='toc_review' && $d['receipt:two']['toc_supplier_confirmed']===1 && $d['receipt:two']['toc_supplier_counts_known'],'one anonymous positive cannot select a copy');
    $refusal=$incomplete;$refusal['unassigned']=1;$refusal['refusals']=[['state'=>'toc_rejected','issuer_nif'=>'']];
    $d=$decide($refusal);batchCheck($d['receipt:one']['state']==='toc_review' && $d['receipt:two']['toc_supplier_unambiguous']
        && $d['receipt:two']['toc_supplier_refused']===1 && $d['receipt:two']['toc_supplier_refusal_outcome']==='toc_rejected','a counted unattributed refusal binds to its only possible supplier group for human assignment');
    $refusal['complete']=false;$d=$decide($refusal);batchCheck(!$d['receipt:one']['toc_supplier_unambiguous'] && !$d['receipt:two']['toc_supplier_refused'],'an unparsed refusal does not authorize manual assignment from counts');
    $copy=$ledger;$copyDocs=$docs;
    foreach (['a','b','c','d'] as $i=>$id) $add($id,$i<2?$issuer:$other,'FR OTHER/'.$i,$key('other month'),'toc_accepted',-86400);
    $d=$decide($pure);batchCheck($d['receipt:one']['reason']==='toc_reply_batch_ambiguous' && !isset($d['receipt:one']['toc_supplier_total']),'terminal earlier month with the same signature prevents a time-based choice');
    $pure['purchases'][2]['invoice_number']='FR EXACT/3';$d=$decide($pure);
    batchCheck($d['receipt:one']['basis']==='batch' && $d['receipt:three']['basis']==='individual' && !isset($d['receipt:a']),'a proven fiscal identity breaks a tie between sends');
    $ledger=$copy;$docs=$copyDocs;
    for ($i=1;$i<=4;$i++) $ledger[$key('portal'.$i)]=array_replace(reset($ledger),['document_id'=>$i,'email_id'=>$key('portal')]);
    $d=$decide($pure);batchCheck($d['receipt:one']['state']==='toc_review','unknown portal supplier identities remain competing sends');
    $ledger=$copy;$docs['one']['company_nif']='123456789';$d=$decide($mixed);
    batchCheck(($d['receipt:one']['state']??'')!=='toc_accepted' && $d['receipt:two']['state']==='toc_review','a different buyer blocks anonymous acceptance');
    $docs=$copyDocs;$ledger=$copy;
    $old=$ledger[$key('four')];
    $ledger[$key('four')]=array_replace($old,['email_id'=>$key('manual retry'),'attachment_key'=>$key('fresh filename'),'previous_attempts'=>[$old]]);
    $d=$decide($mixed);batchCheck(!isset($d['receipt:four']),'delayed refusal for the old file cannot reject a deliberate new send');
    $new=$report([$key('fresh filename')=>['state'=>'toc_accepted']],[]);$d=$decide($new);
    batchCheck($d['receipt:four']['state']==='toc_accepted' && count($d)===1,'new wire filename identifies the right manual resend');
    $store();$late=$report([$key('four')=>['state'=>'toc_accepted']],[]);$receipt($late);
    $delivered=$vault->read('toconline-ledger.enc')[$key('four')];
    batchCheck($delivered['state']==='toc_accepted' && $delivered['receipt_attempt_email_id']===$mail
        && $delivered['email_id']!==$mail && $delivered['previous_attempts'][0]['state']==='toc_accepted'
        && $delivered['confirmation_source']==='dkim_toconline_prior_send','late verified positive closes the original attempt and protects the current copy without misattributing delivery');
    $store();$wrong=$auth;$wrong['recipient']='other@example.test';
    batchCheck($receipt($mixed,$wrong)['result']==='unmatched','unverified recipient cannot consume batch evidence');
    $oldAuth=$auth;$oldAuth['signed_at']=gmdate('c',$now-5000);
    batchCheck($receipt($mixed,$oldAuth)['result']==='unmatched','reply cannot predate its batch');
    echo "TOConline authenticated batch reconciliation passed\n";
} finally {
    foreach (glob($dir.'/*')?:[] as $file) unlink($file);
    rmdir($dir);
}
