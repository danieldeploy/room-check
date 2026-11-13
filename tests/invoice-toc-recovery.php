<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/src/Invoices/InvoiceTocReplyRecovery.php';
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
 $vault->save('toconline-recovery.enc',['complete'=>true,'seen'=>[hash('sha256',$purchaseMail)=>time()]]);
 $upgraded=InvoiceTocReplyRecovery::run($vault,$root.'/mail');
 tr($upgraded['complete']&&$upgraded['queued']===2,'parser upgrade recovers matching filename and fiscal evidence only');
 $pipe=new InvoiceTocPipe($vault);$pipe->process($toc);
 $last=end($received);tr($last[4]['authentication']['code']==='unsigned','fiscal recovery does not bypass signature verification');
 tr(InvoiceTocReplyRecovery::run($vault,$root.'/mail')['queued']===0,'versioned recovery remains one-off');
 echo "TOConline reply recovery safeguards passed\n";
} finally {
 $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
 foreach($it as $f){if($f->isLink()||$f->isFile())unlink($f->getPathname());else rmdir($f->getPathname());}rmdir($root);
}
