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
    echo "TOConline pacing OK\n";
}finally{foreach(glob($dir.'/*')?:[]as$f)unlink($f);rmdir($dir);}
