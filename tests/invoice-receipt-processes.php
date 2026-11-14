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
 $pending=$s;
 $flow->request(1,2026,[4]);$s=$flow->status();pq(count($s['processes'])===2,'same month can start when older run only needs review');
 pq($s['documents']===$pending['documents'],'new request never resets old invoice states');
 $before=$s;$flow->request(2,2026,[4]);pq($flow->status()===$before,'repeat queued request stays idempotent');
 foreach ([['stage'=>'queued'],['stage'=>'reading','next_attempt'=>time()+45],['stage'=>'source_moving'],['stage'=>'archived','toconline_state'=>'pending']] as $active) {
  $busy=$pending;$busy['documents']['b']=array_merge($busy['documents']['b'],$active);$v->save(InvoiceReceipts::FILE,$busy);
  $v->save('toconline-settings.enc',['enabled'=>true,'pilot_verified'=>true]);
  try{$flow->request(1,2026,[6]);pq(false,'active run must block another month');}catch(RuntimeException $e){pq($e->getMessage()==='receipt_process_running','clear active run explanation');}
  pq($flow->status()===$busy,'blocked request preserves history');
 }
 $v->save(InvoiceReceipts::FILE,$pending);$flow->request(1,2026,[6]);$s=$flow->status();pq(count($s['processes'])===2,'different month can start after pending run');
 pq(count(InvoiceReceiptReport::documents($s,'legacy','all'))===3,'old documents retain process membership');
 $s['documents']['b']['toconline_state']='toc_duplicate_local';$s['documents']['b']['stage']='duplicate_trashed';
 $s['documents']['source']['stage']='source_processed';
 pq(!InvoiceReceiptReport::processPending($s,'legacy'),'accepted plus resolved duplicate and moved source complete process');
 // Repeating June discovers only new IDs; even a relocated manual-review scan
 // and an unprocessed legacy master retain their original run and all fields.
 $pdo->exec("CREATE TABLE invoice_drive_settings(id INTEGER,state TEXT,folder_id TEXT); INSERT INTO invoice_drive_settings VALUES(1,'ready','accounting-root')");
 $old=['id'=>'old-manual-scan','process_id'=>'legacy','stage'=>'review','toconline_state'=>'pending','format'=>'jpg','md5Checksum'=>md5('old jpg'),'expected_period'=>'2026-05','source_parent'=>'old-month','error'=>'receipt_review_required'];
 $pending['documents']['old-manual-scan']=$old;$v->save(InvoiceReceipts::FILE,$pending);
 $drive=new class {
  public array $read=[];
  function receiptsAuthorized():bool{return true;}function connect():void{}
  function metadata($id):array{return ['id'=>$id,'trashed'=>false,'mimeType'=>'application/vnd.google-apps.folder','owners'=>[['emailAddress'=>'daniel.ciorcas@welcomehostel.pt']]];}
  function namedItems($parent,$names,$folders):array{return [['id'=>$names===['temp_scan']?'temp-scan-folder':'month-folder']];}
  function scanFiles($parent):array{return [
   ['id'=>'old-manual-scan','name'=>'old.jpg','mimeType'=>'image/jpeg','parents'=>[$parent],'size'=>7,'md5Checksum'=>md5('old jpg')],
   ['id'=>'new-june-scan','name'=>'new.jpg','mimeType'=>'image/jpeg','parents'=>[$parent],'size'=>7,'md5Checksum'=>md5('new jpg')]];}
  function scanDocument($d):string{$this->read[]=$d['id'];throw new RuntimeException('drive_network');}
 };
 $v->save('toconline-ledger.enc',['accepted-proof'=>['document_id'=>'receipt:a','state'=>'toc_accepted']]);
 $analysis=new class{function configured():bool{return true;}};
 $flow=new InvoiceReceipts($pdo,$v,$drive,new InvoiceToconline($pdo,$v,$drive),null,$analysis);
 $flow->request(1,2026,[6]);$pid=$flow->status()['active_process'];$flow->run();$current=$flow->status();
 foreach($pending['documents'] as $id=>$d)pq($current['documents'][$id]===$d,'older pending documents must not be read, reassigned or reset');
 pq($drive->read===['new-june-scan'],'only new scan reaches document processing');
 pq($current['documents']['new-june-scan']['process_id']===$pid,'new scan belongs only to new June run');
 echo "Receipt process migration, pending restart, active exclusion, isolation and completion passed\n";
}finally{foreach(glob($dir.'/*')?:[]as$f)unlink($f);rmdir($dir);}
