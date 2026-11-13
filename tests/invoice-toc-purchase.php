<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/src/Invoices/InvoiceTocPipe.php';
require_once dirname(__DIR__).'/src/Invoices/InvoiceToconline.php';
require_once dirname(__DIR__).'/src/Invoices/InvoiceReceipts.php';
function purchaseCheck(bool $v,string $why):void{if(!$v)throw new RuntimeException($why);}
$body='Ficheiros arquivados e associados automaticamente a faturas de compra: Fornecedor NIF: 500000000 Fornecedor: Synthetic Supplier e-Fatura: FR TEST/101 Fatura de compra: FC TEST/7 (*) (*) Fatura criada automaticamente via QRCode';
$mail="From: no_reply@toconline.pt\r\nTo: test@welcomehostel.pt\r\nSubject: Ficheiros arquivados com sucesso - Active Lines, Lda\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n".$body;
$p=InvoiceTocPipe::parse($mail);
purchaseCheck(($p['purchase_identity']??[])===['issuer_nif'=>'500000000','invoice_number'=>'FR TEST/101'],'observed automatic purchase template preserves exact fiscal identity');
purchaseCheck($p['outcome']==='toc_accepted'&&$p['text']==='','new template is classified without fabricating filename correlation');
purchaseCheck(!str_contains(json_encode($p),'Synthetic Supplier'),'only necessary fields retained');
purchaseCheck(empty(InvoiceTocPipe::parse($mail.' '.$body)['purchase_identity']),'multiple invoice rows are ambiguous');
purchaseCheck(empty(InvoiceTocPipe::parse(str_replace('Ficheiros arquivados e associados automaticamente a faturas de compra:','Unknown result',$mail))['purchase_identity']),'unknown template is not guessed');
$html=str_replace('Content-Type: text/plain; charset=UTF-8','Content-Type: text/html; charset=UTF-8',$mail);
$html=str_replace($body,'<p>Ficheiros arquivados e associados automaticamente a faturas de compra:</p><table><tr><td>Fornecedor NIF:</td><td>500000000</td></tr><tr><td>Fornecedor:</td><td>Synthetic Supplier</td></tr><tr><td>e-Fatura:</td><td>FR TEST/101</td></tr><tr><td>Fatura de compra:</td><td>FC TEST/7 (*)</td></tr></table><p>(*) Fatura criada automaticamente via QRCode</p>',$html);
purchaseCheck(InvoiceTocPipe::parse($html)['purchase_identity']===$p['purchase_identity'],'HTML table cells without source newlines retain fiscal boundaries');
purchaseCheck((InvoiceTocPipe::parse(str_replace('Fornecedor NIF:','Fornecedor&nbsp;NIF:',$html))['purchase_identity']??[])===$p['purchase_identity'],'provider nonbreaking HTML spaces keep exact fiscal identity');
purchaseCheck((InvoiceTocPipe::parse(str_replace('FR TEST/101','1 TEST/101',$mail))['purchase_identity']['invoice_number']??'')==='1 TEST/101','numeric fiscal series stays an exact complete identifier');
purchaseCheck((InvoiceTocPipe::parse(str_replace(' (*) (*) Fatura criada automaticamente via QRCode','',$mail))['purchase_identity']??[])===$p['purchase_identity'],'existing purchase association also confirms archival without creation footnote');
$dir=sys_get_temp_dir().'/toc-purchase-'.bin2hex(random_bytes(8));mkdir($dir,0700);file_put_contents($dir.'/master.key',random_bytes(32));chmod($dir.'/master.key',0600);
try {
 $v=new InvoiceVault($dir);$pdo=new PDO('sqlite::memory:');$toc=new InvoiceToconline($pdo,$v,new stdClass());
 $v->save('toconline-settings.enc',['nif'=>'500000000','sender'=>'test@welcomehostel.pt','enabled'=>true,'pilot_verified'=>true]);
 $key=str_repeat('a',64);$entry=['document_id'=>'receipt:synthetic','nif'=>'500000000','sender'=>'test@welcomehostel.pt','state'=>'toc_submitted','attempted_at'=>gmdate('c',time()-60)];
 $doc=['id'=>'synthetic','issuer_nif'=>'500000000','company_nif'=>'500000000','invoice_number'=>'FR TEST/101','issued_on'=>'2026-04-01','toconline_state'=>'toc_submitted'];
 $v->save('toconline-ledger.enc',[$key=>$entry]);$v->save(InvoiceReceipts::FILE,['documents'=>['synthetic'=>$doc]]);
 $auth=['verified'=>true,'code'=>'verified','recipient'=>'test@welcomehostel.pt','signed_at'=>gmdate('c')];
 $proof=['authentication'=>$auth,'outcome'=>$p['outcome'],'purchase_identity'=>$p['purchase_identity']];
 $state=static fn()=>$v->read(InvoiceReceipts::FILE)['documents']['synthetic']['toconline_state'];
 $receive=static fn($x,$hash='test')=>$toc->receive('no_reply@toconline.pt','',$p['text'],hash('sha256',$hash),$x);
 $bad=$proof;$bad['authentication']['verified']=false;$receive($bad);purchaseCheck($state()==='toc_submitted','unsigned fiscal identity never changes a submission');
 $bad=$proof;$bad['authentication']['recipient']='other@example.test';$receive($bad);purchaseCheck($state()==='toc_submitted','another recipient never matches');
 $bad=$proof;$bad['authentication']['signed_at']=gmdate('c',time()-3600);$receive($bad);purchaseCheck($state()==='toc_submitted','old receipt cannot confirm a later attempt');
 $bad=$proof;$bad['purchase_identity']['issuer_nif']='600000000';$receive($bad);purchaseCheck($state()==='toc_submitted','invoice number alone is insufficient');
 $bad=$proof;$bad['purchase_identity']['invoice_number']='FR TEST/10';$receive($bad);purchaseCheck($state()==='toc_submitted','partial invoice numbers never match');
 $v->save('toconline-ledger.enc',[$key=>$entry,str_repeat('b',64)=>array_replace($entry,['document_id'=>'receipt:copy'])]);$v->save(InvoiceReceipts::FILE,['documents'=>['synthetic'=>$doc,'copy'=>array_replace($doc,['id'=>'copy'])]]);
 $receive($proof);purchaseCheck($state()==='toc_review','signed reply for identical scans must request review rather than remain silently submitted');
 $v->save('toconline-ledger.enc',[$key=>$entry]);$v->save(InvoiceReceipts::FILE,['documents'=>['synthetic'=>$doc]]);
 purchaseCheck($receive($proof)['outcome']==='toc_accepted'&&$state()==='toc_accepted','signed exact unique identity confirms the real submitted scan');
 $before=$v->read('toconline-ledger.enc');$receive($proof);purchaseCheck($v->read('toconline-ledger.enc')===$before,'replay preserves final evidence');
 $pipe=new InvoiceTocPipe($v);$pipe->enqueue($mail);
 $v->save('toconline-pipe-status.enc',['matched'=>0,'unmatched'=>1,'recent'=>[$p['hash']]]);
 $receiver=new class {public int $calls=0;public function receive(...$args):array{$this->calls++;purchaseCheck($args[4]['purchase_identity']['invoice_number']==='FR TEST/101','queue forwards only parsed identity');return ['result'=>'unmatched'];}};
 $pipe->process($receiver);$pipe->enqueue($mail);$pipe->process($receiver);
 purchaseCheck($receiver->calls===1,'upgraded correlation revisits a previously unmatched parse exactly once');
 echo "TOConline automatic purchase acknowledgement OK\n";
}finally{foreach(glob($dir.'/*')?:[]as$f)unlink($f);rmdir($dir);}
