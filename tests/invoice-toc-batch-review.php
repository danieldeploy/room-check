<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/src/Invoices/InvoiceReceipts.php';
require_once dirname(__DIR__).'/src/Invoices/InvoiceTocReplyRecovery.php';

function batchCheck(bool $condition,string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
function batchDenies(callable $call,string $code): void {
    try { $call(); } catch (RuntimeException $error) { batchCheck($error->getMessage()===$code,'expected '.$code.' got '.$error->getMessage());return; }
    throw new RuntimeException('expected refusal '.$code);
}
$dir=sys_get_temp_dir().'/toc-batch-review-'.bin2hex(random_bytes(8));mkdir($dir,0700);
file_put_contents($dir.'/master.key',random_bytes(32));chmod($dir.'/master.key',0600);
$mail=$dir.'/mail';mkdir($mail,0700);mkdir($mail.'/new',0700);mkdir($mail.'/cur',0700);
try {
    $vault=new InvoiceVault($dir);$pdo=new PDO('sqlite::memory:');
    $pdo->exec('CREATE TABLE invoice_document_delivery (document_id INTEGER PRIMARY KEY,toconline_state TEXT)');
    $settings=['nif'=>'500000000','sender'=>'test@welcomehostel.pt','enabled'=>true,'pilot_verified'=>true];
    $vault->save('toconline-settings.enc',$settings);
    $manager=['role'=>'gerente','id'=>4];$sent=[];$key=[];
    $drive=new class {
        public bool $tamper=false;
        public function connect(): void {}
        public function verifiedReceipt(array $doc): string { return '%PDF-1.4 paper '.($this->tamper?'tampered':$doc['id']); }
    };
    $toc=new InvoiceToconline($pdo,$vault,$drive,static function($to,$message) use (&$sent) { $sent[]=$message;return true; });
    $batch=hash('sha256','original mail');$group=hash('sha256','supplier group');
    $fixture=function(bool $metrics=true,bool $complete=true) use($vault,$settings,$batch,$group,&$key): array {
        $docs=[];$ledger=[];
        foreach (['paper-a','paper-b','paper-c','paper-d'] as $i=>$id) {
            $bytes='%PDF-1.4 paper '.$id;
            $docs[$id]=['id'=>$id,'stage'=>'archived','archived_at'=>gmdate('c'),'format'=>'pdf','issued_on'=>'2026-04-01',
                'issuer_nif'=>'511111110','company_nif'=>'500000000','size'=>strlen($bytes),'sha256'=>hash('sha256',$bytes),'error'=>'',
                'process_id'=>$i===3?'p2':'p1','toconline_state'=>$i===0?'toc_accepted':'toc_review','invoice_number'=>'A '.(1+$i)];
            $key[$id]=hash('sha256',$settings['nif']."\0receipt\0".$id."\0".$docs[$id]['sha256']);
            $entry=['document_id'=>'receipt:'.$id,'email_id'=>$batch,'attempted_at'=>gmdate('c',time()-1900),
                'nif'=>$settings['nif'],'sender'=>$settings['sender'],'state'=>$docs[$id]['toconline_state']];
            if ($i===0) $entry+=['confirmation_source'=>'dkim_toconline','toc_confirmation_basis'=>'individual'];
            if ($metrics) $entry+=['toc_supplier_group'=>$group,'toc_supplier_total'=>4,'toc_supplier_confirmed'=>$complete?3:2,
                'toc_supplier_refused'=>1,'toc_supplier_refusal_outcome'=>'toc_existing','toc_supplier_unambiguous'=>true];
            $ledger[$key[$id]]=$entry;
        }
        $vault->save('toconline-ledger.enc',$ledger);
        $vault->save(InvoiceReceipts::FILE,['documents'=>$docs,'processes'=>['p1'=>[],'p2'=>[]]]);
        return $ledger;
    };
    $get=static fn()=>$toc->receiptReviewBatches($manager,'p1')[$batch];
    $apply=static fn($version,$choice,$ids=[],$confirmed=true)=>$toc->reviewReceiptBatch($manager,'p1',$batch,$version,$choice,$ids,$confirmed);
    $fixture();$card=$get();
    batchCheck(count($toc->receiptReviewBatches($manager,'p2')[$batch]['documents'])===4 && count($card['documents'])===4,'actual email includes all receipts across authorized processes');
    batchCheck($card['accepted']===3 && $card['rejected']===1 && $card['unassigned']==='toc_existing','anonymous provider counts are counted once');
    batchCheck(!$card['all_present_eligible'] && !in_array(true,array_column($card['documents'],'resend_eligible'),true),'known refusal requires identification');
    batchDenies(fn()=>$apply($card['version'],'all_present'),'toc_batch_check_required');
    batchDenies(fn()=>$apply($card['version'],'assign_rejection',['paper-a']),'toc_batch_check_required');
    batchDenies(fn()=>$toc->reviewReceiptBatch(['role'=>'governanta','id'=>4],'p1',$batch,$card['version'],'assign_rejection',['paper-b'],true),'forbidden');
    $apply($card['version'],'assign_rejection',['paper-b']);
    $ledger=$vault->read('toconline-ledger.enc');$docs=$vault->read(InvoiceReceipts::FILE)['documents'];
    batchCheck($docs['paper-b']['toconline_state']==='toc_existing' && $docs['paper-c']['toconline_state']==='toc_accepted'
        && $docs['paper-d']['toconline_state']==='toc_accepted' && $docs['paper-b']['toc_confirmation_basis']==='manual','attribution confirms exactly one duplicate and justified peers');
    batchCheck($ledger[$key['paper-a']]['confirmation_source']==='dkim_toconline' && $ledger[$key['paper-b']]['confirmed_by']===4
        && !$toc->receiptDuplicateConfirmed('paper-b'),'retain individual evidence, audit operator and keep manually identified PDF');
    batchCheck(!$toc->receiptReviewBatches($manager,'p1') && !$sent,'completed review leaves the queue without email');
    $fixture(true,false);$card=$get();batchCheck($card['accepted']===2 && $card['rejected']===1 && $card['missing']===1,'incomplete report separates the missing result from known counts');
    $apply($card['version'],'assign_rejection',['paper-b']);
    $docs=$vault->read(InvoiceReceipts::FILE)['documents'];
    batchCheck($docs['paper-c']['toconline_state']==='toc_review','unknown remaining count cannot justify peer acceptance');

    $fixture(false);$card=$get();
    batchDenies(fn()=>$apply($card['version'],'all_present',[],false),'toc_batch_check_required');
    batchDenies(fn()=>$apply($card['version'],'resend_missing',['paper-b','paper-c']),'toc_batch_check_required');
    batchDenies(fn()=>$apply($card['version'],'resend_missing',['paper-a']),'toc_batch_check_required');
    batchDenies(fn()=>$apply($card['version'],'resend_missing',['portal:11']),'toc_batch_check_required');
    batchDenies(fn()=>$apply('old version','all_present'),'toc_batch_changed');
    $apply($card['version'],'all_present');
    batchCheck(!$toc->receiptReviewBatches($manager,'p1') && !$sent,'manual TOConline presence confirms and never sends');
    $base=$fixture(false);$card=$get();
    $drive->tamper=true;batchDenies(fn()=>$apply($card['version'],'resend_missing',['paper-b']),'drive_verify');$drive->tamper=false;
    batchDenies(fn()=>$apply($card['version'],'resend_missing',['paper-b']),'toc_reply_check_pending');
    InvoiceTocReplyRecovery::run($vault,$mail);
    $old=$base[$key['paper-b']];$apply($card['version'],'resend_missing',['paper-b']);
    $new=$vault->read('toconline-ledger.enc')[$key['paper-b']];
    batchCheck(count($sent)===1 && substr_count($sent[0]['body'],'Content-Disposition: attachment;')===1
        && str_contains($sent[0]['body'],base64_encode('%PDF-1.4 paper paper-b')),'only explicitly checked PDF resent');
    batchCheck($new['email_id']!==$batch && $new['previous_attempts']===[$old] && $new['resent_by']===4 && $new['state']==='toc_submitted','distinct send preserves original audit history');
    batchCheck(str_contains($sent[0]['body'],'filename="receipt-'.$new['attachment_key'].'.pdf"') && $new['attachment_key']!==$key['paper-b'],
        'fresh attachment correlation separates a resend reply from the original');
    batchDenies(fn()=>$apply($card['version'],'resend_missing',['paper-b']),'toc_batch_changed');
    $toc->submitReceipt($vault->read(InvoiceReceipts::FILE)['documents']['paper-b']);
    batchCheck(count($sent)===1,'normal workflow and duplicate form never resend');

    $fixture(false);$card=$get();$hash=hash('sha256','synthetic late report');
    $vault->save('toc-pipe-'.$hash.'.enc',['hash'=>$hash,'received_at'=>gmdate('c'),'from'=>'no_reply@toconline.pt',
        'text'=>'receipt-'.$key['paper-b'].'.pdf','attachments'=>[$key['paper-b']=>'toc_accepted'],'outcome'=>'toc_accepted',
        'authentication'=>['verified'=>true,'code'=>'verified','recipient'=>$settings['sender'],'signed_at'=>gmdate('c')]]);
    batchDenies(fn()=>$apply($card['version'],'resend_missing',['paper-b']),'toc_batch_changed');
    batchCheck(count($sent)===1 && $vault->read('toconline-ledger.enc')[$key['paper-b']]['state']==='toc_accepted','late authenticated acceptance wins before handoff');
    $fixture(false);$ledger=$vault->read('toconline-ledger.enc');
    $ledger['portal-record']=['email_id'=>$batch,'document_id'=>11,'state'=>'toc_review']+$settings;
    $vault->save('toconline-ledger.enc',$ledger);
    batchCheck(!$toc->receiptReviewBatches($manager,'p1'),'mixed portal context cannot expose or modify a paper batch');
    $fixture(false);$ledger=$vault->read('toconline-ledger.enc');
    for ($i=0;$i<14;$i++) $ledger[$key['paper-a']]['previous_attempts'][]=array_replace($base[$key['paper-a']],['email_id'=>hash('sha256','past '.$i),'attempted_at'=>gmdate('c')]);
    $vault->save('toconline-ledger.enc',$ledger);$card=$get();
    batchDenies(fn()=>$apply($card['version'],'resend_missing',['paper-b']),'toc_send_wait');
    batchCheck(count($sent)===1,'prior distinct mails consume shared hour budget including overwritten attempts');

    $fixture(false);$ledger=$vault->read('toconline-ledger.enc');
    $docs=$vault->read(InvoiceReceipts::FILE);
    $e=$ledger[$key['paper-d']];$e['document_id']='receipt:paper-e';
    $d=array_replace($docs['documents']['paper-d'],['id'=>'paper-e','size'=>strlen('%PDF-1.4 paper paper-e'),'sha256'=>hash('sha256','%PDF-1.4 paper paper-e')]);
    $docs['documents']['paper-e']=$d;$vault->save(InvoiceReceipts::FILE,$docs);
    $key['paper-e']=hash('sha256',$settings['nif']."\0receipt\0paper-e\0".$d['sha256']);$ledger[$key['paper-e']]=$e;
    foreach ($ledger as &$e) $e+=['toc_supplier_group'=>$group,'toc_supplier_total'=>5,'toc_supplier_confirmed'=>4,
        'toc_supplier_refused'=>0,'toc_supplier_counts_known'=>true,'toc_supplier_unambiguous'=>false,'toc_supplier_refusal_outcome'=>''];
    unset($e);$vault->save('toconline-ledger.enc',$ledger);
    $card=$get();
    batchCheck([$card['accepted'],$card['rejected'],$card['missing']] === [4,0,1] && $card['unassigned']===null,'five sent/four signed acknowledgements/one unknown');
    $toc->reviewReceiptBatch($manager,'p1',$batch,$card['version'],'resend_missing',['paper-c'],true,true);
    $ledger=$vault->read('toconline-ledger.enc');
    foreach (['paper-b','paper-d','paper-e'] as $id) batchCheck($ledger[$key[$id]]['state']==='toc_accepted'
        && $ledger[$key[$id]]['manual_batch_action']==='others_present','other invoices confirmed only by separate operator statement');
    batchCheck($ledger[$key['paper-a']]['confirmation_source']==='dkim_toconline' && $ledger[$key['paper-c']]['state']==='toc_submitted'
        && count($sent)===2,'one missing invoice sent without altering prior signed verdict');

    $fixture(false);$ledger=$vault->read('toconline-ledger.enc');
    foreach ($ledger as &$e) unset($e['email_id']);unset($e);$vault->save('toconline-ledger.enc',$ledger);
    $oldCards=$toc->receiptReviewBatches($manager,'p1');
    batchCheck(count($oldCards)===2 && count($oldCards[$key['paper-b']]['documents'])===1 && count($oldCards[$key['paper-c']]['documents'])===1,
        'legacy single sends retain independent actual message identities despite identical times');
    $toc->reviewReceiptBatch($manager,'p1',$key['paper-b'],$oldCards[$key['paper-b']]['version'],'all_present',[],true);
    batchCheck($vault->read('toconline-ledger.enc')[$key['paper-b']]['state']==='toc_accepted','legacy single mail supports manual verification');

    $fixture(false);$ledger=$vault->read('toconline-ledger.enc');
    foreach ($ledger as &$e) {$e['state']='toc_accepted';$e['confirmation_source']='dkim_toconline';}unset($e);
    $ledger[$key['paper-d']]['state']='toc_rejected';$ledger[$key['paper-d']]['delivery_reason']='toc_provider_wrong_recipient';
    $vault->save('toconline-ledger.enc',$ledger);$card=$get();
    batchCheck(count($card['documents'])===4 && !$card['all_present_eligible'] && !in_array(true,array_column($card['documents'],'resend_eligible'),true),
        'known rejection remains visible together with all signed acceptances and has no resend action');
    $ledger[$key['paper-d']]['state']='toc_review';$ledger[$key['paper-d']]['receipt_review_reason']='toc_provider_preparing';
    $ledger[$key['paper-d']]['attempted_at']=gmdate('c');$vault->save('toconline-ledger.enc',$ledger);
    batchCheck(!$toc->receiptReviewBatches($manager,'p2'),'signed still-preparing remains normal during the confirmation window');
    $started=strtotime($ledger[$key['paper-d']]['attempted_at']);$toc->reconcile($started+305);
    $preparing=$vault->read(InvoiceReceipts::FILE)['documents']['paper-d'];
    batchCheck($preparing['toc_deadline']===$started+1800 && $preparing['toc_next_check']===$started+600,'signed preparing preserves original deadline');
    $toc->reconcile($started+1800);
    $preparing=$vault->read(InvoiceReceipts::FILE)['documents']['paper-d'];
    batchCheck($preparing['toc_next_check']===0 && $preparing['toconline_state']==='toc_review'
        && $preparing['delivery_reason']==='toc_provider_preparing' && $preparing['toc_deadline']===$started+1800,'preparing expires with its actual reply and without resetting wait');
    $ledger[$key['paper-d']]['attempted_at']=gmdate('c',time()-1802);$vault->save('toconline-ledger.enc',$ledger);
    $card=$get();batchCheck(!in_array(true,array_column($card['documents'],'resend_eligible'),true),'still-preparing is never treated as missing');
    $ledger[$key['paper-d']]['receipt_review_reason']='toc_reply_unassigned_refusal';$vault->save('toconline-ledger.enc',$ledger);
    $card=$get();
    batchCheck(!$card['all_present_eligible'] && !$card['unassigned'] && !in_array(true,array_column($card['documents'],'resend_eligible'),true),
        'unallocated refusal with ambiguous supplier does not authorize a missing-file resend or a false full confirmation');
    echo "TOConline explicit batch review passed\n";
} finally {
    foreach (glob($dir.'/*') as $file) if (is_file($file)) unlink($file);
    rmdir($mail.'/cur');rmdir($mail.'/new');rmdir($mail);rmdir($dir);
}
