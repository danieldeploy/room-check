<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/src/Invoices/InvoiceReceipts.php';
function dq(bool $v,string $m):void{if(!$v)throw new RuntimeException($m);}
$dir=sys_get_temp_dir().'/duplicates-'.bin2hex(random_bytes(8));mkdir($dir,0700);file_put_contents($dir.'/master.key',random_bytes(32));chmod($dir.'/master.key',0600);
try {
 $v=new InvoiceVault($dir);$pdo=new PDO('sqlite::memory:');
 $v->save('toconline-settings.enc',['nif'=>'500000000','sender'=>'test@welcomehostel.pt','enabled'=>true,'pilot_verified'=>true]);
 $q=InvoiceReceiptAnalysis::qr('A:500000000*B:500000000*C:PT*D:FR*E:N*F:20260401*G:FR TEST/101*H:TEST-101*O:123.00');
 $base=$q+['source_id'=>'source','complete'=>true,'uncertain'=>false,'review_reason'=>'','stage'=>'archived','archived_at'=>'now','toconline_state'=>'toc_submitted','issuer_name'=>'Synthetic Supplier','format'=>'pdf','size'=>5,'drive_parent'=>'final','created_at'=>'2026-01-01','page_count'=>2];
 $a=$base+['id'=>'copy-a','sha256'=>hash('sha256','a'),'page_number'=>1,'legibility'=>40];
 $b=$base+['id'=>'copy-b','sha256'=>hash('sha256','b'),'page_number'=>2,'legibility'=>90];
 $docs=['copy-a'=>$a,'copy-b'=>$b];$v->save(InvoiceReceipts::FILE,['documents'=>$docs,'requested'=>false]);
 $entry=['nif'=>'500000000','sender'=>'test@welcomehostel.pt','state'=>'toc_submitted','attempted_at'=>gmdate('c',time()-60)];
 $ledger=['a'=>$entry+['document_id'=>'receipt:copy-a'],'b'=>$entry+['document_id'=>'receipt:copy-b']];$v->save('toconline-ledger.enc',$ledger);
 $drive=new class {public array $trashed=[];public bool $crash=true;function connect():void{}function receiptsAuthorized():bool{return true;}function verifiedReceipt($d):string{return '%PDF-';}function trashReceiptFile($d):void{$this->trashed[$d['id']]=true;if($this->crash){$this->crash=false;throw new RuntimeException('drive_network');}}};
 $toc=new InvoiceToconline($pdo,$v,$drive);$flow=new InvoiceReceipts($pdo,$v,$drive,$toc);
 dq(!$toc->confirmEquivalentReceipts(['copy-a','copy-b'],'copy-b'),'same QR without acceptance is insufficient');
 $proof=['outcome'=>'toc_accepted','authentication'=>['verified'=>true,'code'=>'verified','recipient'=>'test@welcomehostel.pt','signed_at'=>gmdate('c')],'purchase_identity'=>['issuer_nif'=>'500000000','invoice_number'=>'FR TEST/101']];
 $toc->receive('no_reply@toconline.pt','','',hash('sha256','signed reply'),$proof);
 dq($flow->status()['documents']['copy-a']['toconline_state']==='toc_review','ambiguous response initially remains review');
 $bad=$b;$bad['complete']=false;dq(InvoiceReceiptAnalysis::duplicateKey($bad)===null,'complementary or incomplete page cannot be deleted as duplicate');
 $bad=$b;$bad['qr_fields']['F']='20260501';dq(InvoiceReceiptAnalysis::duplicateKey($bad)!==InvoiceReceiptAnalysis::duplicateKey($a),'differing QR fields are not equivalent');
 $flow->run();$s=$flow->status();dq($s['documents']['copy-b']['toconline_state']==='toc_accepted','clearest complete copy retains accepted invoice');
 dq($s['documents']['copy-a']['stage']==='trashing' && $s['documents']['copy-a']['toconline_state']==='toc_duplicate_local','interrupted deletion keeps durable intent and local classification');
 $s['documents']['copy-a']['next_attempt']=0;$v->save(InvoiceReceipts::FILE,$s);$flow->run();$toc->reconcile();$flow->run();$s=$flow->status();
 dq($s['documents']['copy-a']['stage']==='duplicate_trashed' && count($drive->trashed)===1 && !isset($drive->trashed['copy-b']),'retry and ledger reconciliation preserve sole retained copy');
 dq(!$toc->receiptDuplicateConfirmed('copy-a'),'local consolidation never claims provider duplicate rejection');
 // A third ambiguous candidate with different fiscal contents must block automatic resolution.
 $c=$b;$c['id']='copy-c';$c['qr_fields']['O']='124.00';$v->save(InvoiceReceipts::FILE,['documents'=>$docs+['copy-c'=>$c],'requested'=>false]);
 $v->save('toconline-ledger.enc',$ledger+['c'=>$entry+['document_id'=>'receipt:copy-c']]);$toc->receive('no_reply@toconline.pt','','',hash('sha256','three copies'),$proof);
 dq(!$toc->confirmEquivalentReceipts(['copy-a','copy-b'],'copy-b'),'ambiguous acknowledgement cannot be narrowed to a matching subset');
 echo "Equivalent invoice evidence, readability, interrupted cleanup and ambiguity safeguards passed\n";
}finally{foreach(glob($dir.'/*')?:[]as$f)unlink($f);rmdir($dir);}
