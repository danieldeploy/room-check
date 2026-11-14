<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/src/Invoices/InvoiceReceipts.php';
require_once dirname(__DIR__).'/src/Invoices/InvoiceReceiptReport.php';
function pq(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
$s=['requested'=>false,'requested_at'=>'2026-01-02T10:00:00Z','year'=>2026,'months'=>[4,5], 'documents'=>[
 'source'=>['id'=>'source','stage'=>'split','children'=>['a','b']],
 'a'=>['id'=>'a','source_id'=>'source','stage'=>'archived','toconline_state'=>'toc_accepted'],
 'b'=>['id'=>'b','source_id'=>'source','stage'=>'review','toconline_state'=>'pending']]];
$s=InvoiceReceiptReport::withProcesses($s);
pq(count($s['processes'])===1,'legacy batch retained as one dated process');
pq(InvoiceReceiptReport::processPending($s,'legacy'),'one unresolved invoice keeps process pending');
pq(count(InvoiceReceiptReport::documents($s,'legacy','problems'))===1,'problem filter isolates invoice needing action');
pq(count(InvoiceReceiptReport::documents($s,'legacy','success'))===1,'success filter isolates accepted invoice');
$dir=sys_get_temp_dir().'/processes-'.bin2hex(random_bytes(8));mkdir($dir,0700);file_put_contents($dir.'/master.key',random_bytes(32));chmod($dir.'/master.key',0600);
try{
 $v=new InvoiceVault($dir);$v->save(InvoiceReceipts::FILE,$s);$pdo=new PDO('sqlite::memory:');
 $drive=new class{function receiptsAuthorized():bool{return true;}};
 $flow=new InvoiceReceipts($pdo,$v,$drive,new InvoiceToconline($pdo,$v,$drive));
 try{$flow->request(1,2026,[4]);pq(false,'overlapping pending month refused');}catch(RuntimeException $e){pq($e->getMessage()==='receipt_process_overlap','explicit overlap explanation');}
 $flow->request(1,2026,[6]);$s=$flow->status();pq(count($s['processes'])===2,'different month creates independent process');
 pq(count(InvoiceReceiptReport::documents($s,'legacy','all'))===3,'old documents retain process membership');
 $s['documents']['b']['toconline_state']='toc_duplicate_local';$s['documents']['b']['stage']='duplicate_trashed';
 $s['documents']['source']['stage']='source_processed';
 pq(!InvoiceReceiptReport::processPending($s,'legacy'),'accepted plus resolved duplicate and moved source complete process');
 echo "Receipt process migration, isolation, overlap and completion passed\n";
}finally{foreach(glob($dir.'/*')?:[]as$f)unlink($f);rmdir($dir);}
