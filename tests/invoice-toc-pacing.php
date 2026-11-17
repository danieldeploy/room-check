<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/src/Invoices/InvoiceToconline.php';
require_once dirname(__DIR__).'/src/Invoices/InvoiceReceipts.php';
function paceCheck(bool $v,string $why):void {if(!$v)throw new RuntimeException($why);}
$dir=sys_get_temp_dir().'/toc-pace-'.bin2hex(random_bytes(8));mkdir($dir,0700);file_put_contents($dir.'/master.key',random_bytes(32));chmod($dir.'/master.key',0600);
try {
    $v=new InvoiceVault($dir);$pdo=new PDO('sqlite::memory:');
    $pdo->exec('CREATE TABLE invoice_document_delivery(toconline_state TEXT)');
    $v->save('toconline-settings.enc',['nif'=>'500000000','sender'=>'test@welcomehostel.pt','enabled'=>true,'pilot_verified'=>true]);
    $bytes='%PDF-1.4 synthetic';$docs=[];
    for($i=0;$i<12;$i++)$docs['scan-'.$i]=['id'=>'scan-'.$i,'stage'=>'archived','archived_at'=>gmdate('c'),'issued_on'=>'2026-04-01','company_nif'=>'500000000','sha256'=>hash('sha256',$bytes),'size'=>strlen($bytes),'format'=>'pdf','toconline_state'=>'pending','error'=>'','next_attempt'=>0,'attempts'=>0];
    $v->save(InvoiceReceipts::FILE,['documents'=>$docs,'requested'=>false]);
    $drive=new class($bytes){public function __construct(private string $bytes){}public function connect():void{}public function receiptsAuthorized():bool{return true;}public function verifiedReceipt(array $d):string{return $this->bytes;}};
    $sent=0;$toc=new InvoiceToconline($pdo,$v,$drive,static function()use(&$sent){$sent++;return true;});
    // A previously sent portal PDF consumes one slot, regardless of its two aliases.
    $entry=['document_id'=>99,'nif'=>'500000000','sender'=>'test@welcomehostel.pt','state'=>'toc_accepted','attempted_at'=>gmdate('c')];
    $v->save('toconline-ledger.enc',['identity'=>$entry,'content'=>$entry]);
    for($i=0;$i<9;$i++)$toc->submitReceipt($docs['scan-'.$i]);
    try{$toc->submitReceipt($docs['scan-9']);}catch(RuntimeException $e){$wait=$e;}
    paceCheck($sent===9,'shared rolling limit must stop before the next transport call');
    paceCheck(isset($wait)&&$wait instanceof InvoiceTocWait&&$wait->retryAt>time(),'unsent invoice receives a retry deadline');
    paceCheck($toc->receiptUnsent('scan-9'),'throttling must not reserve a send intent');
    $flow=new InvoiceReceipts($pdo,$v,$drive,$toc);$flow->run();
    $d=$flow->status()['documents']['scan-9'];
    paceCheck($d['stage']==='archived'&&$d['next_attempt']>time()&&$d['attempts']===0&&$d['error']==='','pacing is waiting, not failed processing');
    $ledger=$v->read('toconline-ledger.enc');foreach($ledger as &$e)$e['attempted_at']=gmdate('c',time()-3601);unset($e);$v->save('toconline-ledger.enc',$ledger);
    $toc->submitReceipt($docs['scan-9']);$toc->submitReceipt($docs['scan-9']);
    paceCheck($sent===10,'expired budget permits exactly one previously unsent delivery');
    // Twenty-one ready invoices use two actual messages, even across worker restarts.
    require_once dirname(__DIR__).'/src/Invoices/InvoiceTocPipe.php';
    $v->save('toconline-ledger.enc',[]);
    $docs=[];
    for ($i=0;$i<21;$i++) {
        $id='batch-'.$i;
        $docs[$id]=array_replace($d,['id'=>$id,'stage'=>'archived','next_attempt'=>0,'error'=>'','toconline_state'=>'pending']);
    }
    $v->save(InvoiceReceipts::FILE,['documents'=>$docs,'requested'=>false]);
    $messages=[];
    $batched=new InvoiceToconline($pdo,$v,$drive,static function($to,$message) use (&$messages) {
        $messages[]=$message;return true;
    });
    $worker=new InvoiceReceipts($pdo,$v,$drive,$batched);
    $worker->run();
    paceCheck(count($messages)===1 && substr_count($messages[0]['body'],'Content-Disposition: attachment;')===20,'normal receipt worker sends twenty invoices in one email');
    paceCheck($batched->receiptUnsent('batch-20'),'overflow invoice has no send intent');
    $worker->run();
    paceCheck(count($messages)===2 && substr_count($messages[1]['body'],'Content-Disposition: attachment;')===1,'second email contains only overflow');
    preg_match_all('/filename="(receipt-[a-f0-9]{64}\.pdf)"/',$messages[0]['body'],$names);
    paceCheck(count(array_unique($names[1]))===20,'every invoice has an independent submission ID');
    $raw="From: no_reply@toconline.pt\r\nSubject: Arquivo digital\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n"
        ."Ficheiros arquivados com sucesso:\r\n".$names[1][0]."\r\nForam recusados os seguintes ficheiros por já constarem no seu arquivo:\r\n"
        .$names[1][1]."\r\nForam recusados os seguintes ficheiros:\r\n".$names[1][2]."\r\nEstado desconhecido\r\n".$names[1][3];
    $parsed=InvoiceTocPipe::parse($raw);
    $proof=$parsed+['authentication'=>['verified'=>true,'code'=>'verified','recipient'=>'test@welcomehostel.pt','signed_at'=>gmdate('c')]];
    $batched->receive($parsed['from'],'',$parsed['text'],$parsed['hash'],$proof);
    $states=$worker->status()['documents'];
    foreach (['toc_accepted','toc_existing','toc_rejected','toc_review','toc_submitted'] as $i=>$state)
        paceCheck($states['batch-'.$i]['toconline_state']===$state,'mixed reply preserves independent verdict for position '.$i);
    $batched->submitReceipt(reset($docs),$docs);
    paceCheck(count($messages)===2,'replayed request never retransmits a partial batch');

    // Upper bound includes folded base64 transport bytes in addition to raw attachment bytes.
    $fits=new ReflectionMethod(InvoiceToconline::class,'fits');
    paceCheck($fits->invoke(null,[['pdf'=>str_repeat('a',9000000)]],9000000),'18 MB raw fits the configured transport');
    paceCheck(!$fits->invoke(null,[['pdf'=>str_repeat('a',10000000)]],9000000),'encoded size independently splits a batch');
    paceCheck(!$fits->invoke(null,[],20000001),'raw cap applies to an oversized attachment');
    echo "TOConline pacing OK\n";
}finally{foreach(glob($dir.'/*')?:[]as$f)unlink($f);rmdir($dir);}
