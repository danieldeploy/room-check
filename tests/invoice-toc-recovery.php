<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/src/Invoices/InvoiceTocReplyRecovery.php';
function checkRetained(InvoiceVault $vault,object $toc): bool {
    $complete=InvoiceTocReplyRecovery::queueDue($vault);
    $pipe=new InvoiceTocPipe($vault);$pipe->process($toc);
    return $complete && $pipe->status()['pending']===0;
}
function tr(bool $ok,string $message):void {if(!$ok)throw new RuntimeException($message);}
$root=sys_get_temp_dir().'/toc-recovery-'.bin2hex(random_bytes(6));mkdir($root,0700);mkdir($root.'/vault',0700);mkdir($root.'/mail',0700);mkdir($root.'/mail/cur',0700);mkdir($root.'/mail/.Filed',0700);mkdir($root.'/mail/.Filed/new',0700);
file_put_contents($root.'/vault/master.key',random_bytes(32));chmod($root.'/vault/master.key',0600);
$vault=new InvoiceVault($root.'/vault');$token=str_repeat('a',64);
$vault->save('toconline-ledger.enc',[$token=>['document_id'=>'receipt:synthetic','state'=>'toc_submitted']]);
$mail="From: no_reply@toconline.pt\r\nTo: test@example.test\r\nSubject: Ficheiros arquivados com sucesso - Active Lines, Lda\r\nDate: ".gmdate('r')."\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\nreceipt-$token.pdf\r\n";
file_put_contents($root.'/mail/.Filed/new/known',$mail);
file_put_contents($root.'/mail/cur/unrelated',str_replace('no_reply@toconline.pt','private@example.test',$mail));
file_put_contents($root.'/mail/cur/unknown',str_replace($token,str_repeat('b',64),$mail));
file_put_contents($root.'/mail/cur/malformed',"broken header\r\n\r\nunrelated");
file_put_contents($root.'/mail/cur/ambiguous',str_replace("receipt-$token.pdf","receipt-$token.pdf receipt-".str_repeat('b',64).'.pdf',$mail));
file_put_contents($root.'/outside',$mail);symlink($root.'/outside',$root.'/mail/cur/link');
try {
 tr(!InvoiceTocReplyRecovery::queueDue($vault),'a worker cannot report a retained check before registration');
 $noMail=new class {public function receive(...$args):array {return ['result'=>'unmatched'];}};
 try {InvoiceTocReplyRecovery::beforeResend($vault,$noMail);tr(false,'unregistered route must block a resend');}
 catch (RuntimeException $e) {tr($e->getMessage()==='toc_reply_check_pending','pre-send requires a registered mailbox');}
 tr(!$vault->has('toconline-recovery.enc'),'the worker never guesses or registers another account');
 $result=InvoiceTocReplyRecovery::run($vault,$root.'/mail');
 tr($result['complete']&&$result['queued']===1,'only exact outstanding receipt is queued');
 tr(file_get_contents($root.'/mail/.Filed/new/known')===$mail,'mail original untouched');
 tr(count(glob($root.'/vault/toc-pipe-*.enc'))===1,'single encrypted queue item');
 $received=[];$toc=new class($received){public array $received=[];public function __construct(&$r){$this->received=&$r;} public function receive(...$args):array{$this->received[]=$args;return ['result'=>'matched','outcome'=>'toc_review'];}};
 (new InvoiceTocPipe($vault))->process($toc);
 tr(count($received)===1&&$received[0][4]['authentication']['code']==='unsigned','normal authentication remains authoritative');
 rename($root.'/mail/.Filed/new/known',$root.'/mail/cur/renamed');
 $state=$vault->read('toconline-recovery.enc');$state['complete']=false;$vault->save('toconline-recovery.enc',$state);
 $again=InvoiceTocReplyRecovery::run($vault,$root.'/mail');
 tr($again['queued']===0&&count(glob($root.'/vault/toc-pipe-*.enc'))===0,'recovery is idempotent after Maildir flag/name change');
 // A completed legacy scan skipped the QR-created purchase template. Revisit only
 // relevant retained mail through the unchanged authentication/receiver path.
 $purchase='Ficheiros arquivados e associados automaticamente a faturas de compra: Fornecedor NIF: 500000000 Fornecedor: Synthetic e-Fatura: FT TEST/2 Fatura de compra: FC TEST/9 (*) Fatura criada automaticamente via QRCode';
 $purchaseMail=str_replace('receipt-'.$token.'.pdf',$purchase,$mail);
 file_put_contents($root.'/mail/cur/purchase',$purchaseMail);
 $vault->save('receipts-workflow.enc',['documents'=>['synthetic'=>['issuer_nif'=>'500000000','invoice_number'=>'FT TEST/2']]]);
 $vault->save('toconline-recovery.enc',['complete'=>true,'correlation_version'=>InvoiceTocPipe::CORRELATION_VERSION-1,'mail_root'=>$root.'/mail','seen'=>[hash('sha256',$purchaseMail)=>time()]]);
 $upgraded=InvoiceTocReplyRecovery::run($vault,$root.'/mail');
 tr($upgraded['complete']&&$upgraded['queued']===2,'parser upgrade recovers matching filename and fiscal evidence only');
 $pipe=new InvoiceTocPipe($vault);$pipe->process($toc);
 $last=end($received);tr($last[4]['authentication']['code']==='unsigned','fiscal recovery does not bypass signature verification');
 tr(InvoiceTocReplyRecovery::run($vault,$root.'/mail')['queued']===0,'versioned recovery remains one-off');
 // A batch due for expiry gets one read of retained arrivals for its deadline. A manual
 // resend must check again for new mail even when the worker already completed that read.
 unlink($root.'/mail/cur/ambiguous');
 $now=time();
 $entry=['state'=>'toc_no_confirmation','document_id'=>'receipt:synthetic','email_id'=>str_repeat('c',64),'attempted_at'=>gmdate('c',$now-1860)];
 $vault->save('toconline-ledger.enc',[$token=>$entry]);
 $state=$vault->read('toconline-recovery.enc');$state['covered_until']=$now-120;$vault->save('toconline-recovery.enc',$state);
 $later=str_replace('FT TEST/2','FT TEST/3',$purchaseMail);
 file_put_contents($root.'/mail/cur/late',$later);
 $before=count($received);
 tr(checkRetained($vault,$toc) && count($received)===$before+1,'a due deadline drains newly retained fiscal evidence');
 rename($root.'/mail',$root.'/moved');
 tr(checkRetained($vault,$toc),'completed deadline does not reopen the mailbox every worker tick');
 try { InvoiceTocReplyRecovery::beforeResend($vault,$toc);tr(false,'unavailable late mailbox cannot authorize a send'); }
 catch (RuntimeException $e) { tr($e->getMessage()==='toc_reply_check_pending','unavailable late mailbox fails closed'); }
 rename($root.'/moved',$root.'/mail');
 $latest=str_replace('FT TEST/2','FT TEST/4',$purchaseMail);
 file_put_contents($root.'/mail/cur/latest',$latest);
 InvoiceTocReplyRecovery::beforeResend($vault,$toc);
 tr(count($received)===$before+2 && file_get_contents($root.'/mail/cur/latest')===$latest,'pre-resend check reads the later fiscal original');
 InvoiceTocReplyRecovery::beforeResend($vault,$toc);
 tr(count($received)===$before+2,'manual recheck deduplicates previously processed proofs');
 // The bound counts unrelated input too. Persist a cursor and automatically continue,
 // including many equal mtimes. A yielded pre-send check cannot claim completion.
 for ($i=0;$i<260;$i++) {
     $path=$root.'/mail/cur/a'.str_pad((string)$i,3,'0',STR_PAD_LEFT);
     file_put_contents($path,"From: unrelated@example.test\r\n\r\n".$i);
     touch($path,$now);
 }
 $path=$root.'/mail/cur/z';$lastMail=str_replace('FT TEST/2','FT TEST/5',$purchaseMail);
 file_put_contents($path,$lastMail);touch($path,$now+1);
 try { InvoiceTocReplyRecovery::beforeResend($vault,$toc);tr(false,'yielded check cannot permit manager handoff'); }
 catch (RuntimeException $e) { tr($e->getMessage()==='toc_reply_check_pending','bounded pre-send check stops before handoff'); }
 $state=$vault->read('toconline-recovery.enc');
 tr(!$state['complete'] && isset($state['cursor']),'budgeted recovery checkpoint persists after a yield');
 tr(checkRetained($vault,$toc) && count($received)===$before+3,'worker completes a yielded scan exactly once without a new deadline');
 InvoiceTocReplyRecovery::beforeResend($vault,$toc);
 tr(count($received)===$before+3,'a completed busy mailbox recheck does not starve repeated pre-send checks');
 // Exercise the worker's actual delivery decisions and the normal receipt submission path.
 // A historical outage or yielded scan cannot expire September or hold up a fresh month.
 require_once dirname(__DIR__).'/src/Invoices/InvoiceRunner.php';
 $pdo=new PDO('sqlite::memory:');$runner=new InvoiceRunner($pdo,['private_dir'=>$vault->root]);
 $prepare=new ReflectionMethod(InvoiceRunner::class,'prepareDelivery');
 $bytes='%PDF-1.4 synthetic receipt';
 $drive=new class($bytes) {
     public function __construct(private string $bytes) {}
     public function connect():void {} public function receiptsAuthorized():bool{return true;}
     public function verifiedReceipt(array $d):string{return $this->bytes;}
 };
 $sends=0;$toc=new InvoiceToconline($pdo,$vault,$drive,static function() use (&$sends) { $sends++;return true; });
 $flow=new InvoiceReceipts($pdo,$vault,$drive,$toc);
 $vault->save('toconline-settings.enc',['sender'=>'test@welcomehostel.pt','nif'=>'500000000','enabled'=>true,'pilot_verified'=>true]);
 $doc=['id'=>'september','stage'=>'archived','archived_at'=>gmdate('c'),'issued_on'=>'2026-09-01',
     'company_nif'=>'500000000','sha256'=>hash('sha256',$bytes),'size'=>strlen($bytes),'format'=>'pdf',
     'toconline_state'=>'toc_submitted','attempts'=>0,'error'=>'','next_attempt'=>0];
 $oldKey=hash('sha256',"500000000\0receipt\0september\0".$doc['sha256']);
 $vault->save('toconline-ledger.enc',[$oldKey=>['document_id'=>'receipt:september','state'=>'toc_submitted',
     'nif'=>'500000000','sender'=>'test@welcomehostel.pt','attempted_at'=>gmdate('c',time()-3600)]]);
 $oct=array_replace($doc,['id'=>'october','issued_on'=>'2026-10-01','toconline_state'=>'pending']);
 $vault->save(InvoiceReceipts::FILE,['documents'=>['september'=>$doc,'october'=>$oct],'requested'=>false]);
 $busy=$root.'/busy';mkdir($busy,0700);mkdir($busy.'/cur',0700);
 $state=['mail_root'=>$busy,'correlation_version'=>InvoiceTocPipe::CORRELATION_VERSION,'complete'=>false,'seen'=>[],'retry_after'=>time()+300];
 $vault->save('toconline-recovery.enc',$state);
 $ready=$prepare->invoke($runner,$vault,$toc);$flow->run(null,$ready);
 tr($ready && $sends===1 && $flow->status()['documents']['october']['toconline_state']==='toc_submitted','October sends once despite a retained-key outage');
 tr($vault->read('toconline-ledger.enc')[$oldKey]['state']==='toc_submitted'
     && $vault->read(InvoiceReceipts::PHASE_ERRORS)['receipt_retained_replies']['reason']==='toc_auth_dns_unavailable','September remains open and the retained failure has a distinct incident');
 try { InvoiceTocReplyRecovery::beforeResend($vault,$toc);tr(false,'old resend needs a successful retained check'); }
 catch (RuntimeException $e) { tr($e->getMessage()==='toc_reply_check_pending','manual resend still fails closed'); }
 unset($state['retry_after']);$vault->save('toconline-recovery.enc',$state);
 for ($i=0;$i<251;$i++) file_put_contents($busy.'/cur/msg'.$i,"From: nonprovider@example.test\r\n\r\n".$i);
 $s=$flow->status();$s['documents']['november']=array_replace($oct,['id'=>'november','issued_on'=>'2026-11-01']);$vault->save(InvoiceReceipts::FILE,$s);
 $ready=$prepare->invoke($runner,$vault,$toc);$flow->run(null,$ready);
 tr($ready && $sends===2 && $flow->status()['documents']['november']['toconline_state']==='toc_submitted'
     && !$vault->read('toconline-recovery.enc')['complete'] && $vault->read('toconline-ledger.enc')[$oldKey]['state']==='toc_submitted','bounded yield admits an unused November identity and preserves September');
 $ready=$prepare->invoke($runner,$vault,$toc);$flow->run(null,$ready);
 tr($sends===2 && $vault->read('toconline-ledger.enc')[$oldKey]['state']==='toc_no_confirmation','September expires only after the retained scan finishes; no repeats');
 echo "TOConline reply recovery safeguards passed\n";
} finally {
 $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
 foreach($it as $f){if($f->isLink()||$f->isFile())unlink($f->getPathname());else rmdir($f->getPathname());}rmdir($root);
}
