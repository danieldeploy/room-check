<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/src/Invoices/InvoiceReceipts.php';
function gq(bool $v,string $m): void { if(!$v)throw new RuntimeException($m); }
$dir=sys_get_temp_dir().'/grouped-'.bin2hex(random_bytes(8));mkdir($dir,0700);file_put_contents($dir.'/master.key',random_bytes(32));chmod($dir.'/master.key',0600);
try {
 $vault=new InvoiceVault($dir);$pdo=new PDO('sqlite::memory:');
 $pdo->exec('CREATE TABLE invoice_document_delivery(document_id INTEGER,toconline_state TEXT); CREATE TABLE invoice_drive_folders(path_key TEXT PRIMARY KEY,drive_id TEXT,parent_id TEXT,name TEXT)');
 $drive=new class {
  public array $files=[];public array $trashed=[];public array $names=[];public bool $crash=false;public bool $moveCrash=false;public string $trashDenied='';
  function connect():void{} function receiptsAuthorized():bool{return true;}
  function metadata($id):?array{return $this->files[$id]??null;}
  function namedItems($parent,$names,$folder=true):array{return [];}
  function folder($id,$parent,$name):void{$this->names[]=$name;}
  function newId():string{return 'new-'.bin2hex(random_bytes(8));}
  function scanDocument($d):string{gq(!$this->files[$d['id']]['trashed'],'source retained');return $this->files[$d['id']]['bytes'];}
  function verifiedReceipt($d):string{gq($this->files[$d['id']]['parents']===[$d['drive_parent']] && !$this->files[$d['id']]['trashed'],'verified parent');$b=$this->files[$d['id']]['bytes'];gq(hash('sha256',$b)===$d['sha256'],'verified bytes');return $b;}
  function upload($id,$parent,$name,$path,$format):array{
   $b=file_get_contents($path);$this->files[$id]=['id'=>$id,'bytes'=>$b,'parents'=>[$parent],'trashed'=>false,'mimeType'=>'application/pdf','size'=>strlen($b),'md5Checksum'=>md5($b)];
   if($this->crash){$this->crash=false;throw new RuntimeException('drive_network');}return $this->files[$id];
  }
  function moveReceipt($d):void{$this->files[$d['id']]['parents']=[$d['drive_parent']];if($this->moveCrash){$this->moveCrash=false;throw new RuntimeException('drive_network');}}
  function trashReceiptFile($d):void{gq($this->files[$d['id']]['parents']===[$d['drive_parent']],'bound cleanup');if($this->trashDenied===$d['id'])throw new RuntimeException('drive_trash_permission');$this->files[$d['id']]['trashed']=true;$this->trashed[]=$d['id'];}
 };
 $source='%PDF-1.4 synthetic three pages';$id='original-source';
 $d=['id'=>$id,'name'=>'scan.pdf','format'=>'pdf','mimeType'=>'application/pdf','size'=>strlen($source),'md5Checksum'=>md5($source),'root'=>'accounting-root','source'=>'temp-scan-folder','source_month'=>'month-folder','source_parent'=>'month-folder','expected_period'=>'2026-06','stage'=>'queued','toconline_state'=>'pending','requested_by'=>1,'created_at'=>gmdate('c')];
 $drive->files[$id]=$d+['bytes'=>$source,'parents'=>['month-folder'],'trashed'=>false];
 $vault->save(InvoiceReceipts::FILE,['documents'=>[$id=>$d],'requested'=>false]);
 $vault->save('toconline-settings.enc',['nif'=>'500000000','sender'=>'test@welcomehostel.pt','enabled'=>true,'pilot_verified'=>true]);$sent=[];
 $toc=new InvoiceToconline($pdo,$vault,$drive,static function($to,$mail)use(&$sent){$sent[]=$mail;return true;});
 $analyzer=new class($vault) {
  function __construct(private InvoiceVault $vault){} function configured():bool{return true;}
  function analyse($bytes,$format,$nif):array{return ['version'=>2,'page_count'=>3,'groups'=>[
   ['pages'=>[1,2],'issued_on'=>'2026-04-08','invoice_number'=>'FT1','issuer_name'=>'Supplier','issuer_nif'=>'123456789','company_nif'=>$nif,'total'=>'123.00','data_source'=>'qr','review_reason'=>'','complete'=>true],
   ['pages'=>[3],'issued_on'=>'','invoice_number'=>'','issuer_name'=>'','company_nif'=>$nif,'review_reason'=>'receipt_review_required','complete'=>true]]];}
  function extract($bytes,$pages):array{$b='%PDF-1.4 pages '.implode(',',$pages);$sha=hash('sha256',$b);InvoiceVault::atomicWrite($this->vault->path('receipt-page-'.$sha.'.pdf'),$b);return ['pages'=>$pages,'sha256'=>$sha,'md5Checksum'=>md5($b),'size'=>strlen($b)];}
 };
 $flow=new InvoiceReceipts($pdo,$vault,$drive,$toc,null,$analyzer);
 $flow->run();$s=$flow->status();gq(count($s['documents'][$id]['children']??[])===2,'three pages must become two invoice groups');
 $children=$s['documents'][$id]['children'];gq($s['documents'][$children[0]]['pages']===[1,2],'related pages retained');
 $drive->crash=true;$flow->run();gq(!$drive->files[$id]['trashed'] && count($sent)===0,'unknown upload preserves master and does not send');
 $s=$flow->status();$s['documents'][$children[0]]['next_attempt']=0;$vault->save(InvoiceReceipts::FILE,$s);
 $drive->trashDenied=$id;
 for($n=0;$n<6;$n++)$flow->run();$s=$flow->status();
 gq(count($sent)===1 && in_array('Abril_2026',$drive->names,true),'issue month routing and one grouped submission');
 gq($s['documents'][$children[1]]['stage']==='review' && !empty($s['documents'][$children[1]]['preserved_at']),'unresolved invoice has durable separate copy');
 gq($s['documents'][$id]['stage']==='split' && !$drive->files[$id]['trashed'],'unresolved invoice keeps original in selected month');
 $flow->review($children[1],'2026-05-01','FT2',true,1,'Supplier');$flow->run();$flow->run();
 gq(count($sent)===2 && in_array('Maio_2026',$drive->names,true),'manual correction resumes only unsent invoice');
 $flow->run();gq(count($sent)===2 && $flow->status()['documents'][$id]['stage']==='split','submission without acknowledgement retains original');
 $confirm=function($child)use($vault,$toc){
  foreach($vault->read('toconline-ledger.enc') as $key=>$entry) if($entry['document_id']==='receipt:'.$child){
   $toc->receive('no_reply@toconline.pt','receipt-'.$key.'.pdf','',hash('sha256',$child),['outcome'=>'toc_accepted','authentication'=>['verified'=>true,'code'=>'verified','recipient'=>'test@welcomehostel.pt','signed_at'=>gmdate('c')]]);break;
  }
 };
 foreach($children as$child)$confirm($child);
 InvoiceReceipts::pauseSourceCleanup($vault);$flow->run();gq($drive->files[$id]['parents']===['month-folder'],'pause is respected');
 InvoiceReceipts::enableProcessedSources($vault);$drive->moveCrash=true;$flow->run();$s=$flow->status();
 gq($s['documents'][$id]['stage']==='source_moving','uncertain move retains persisted destination');
 $s['documents'][$id]['next_attempt']=0;$vault->save(InvoiceReceipts::FILE,$s);$flow->run();$s=$flow->status();
 gq($s['documents'][$id]['stage']==='source_processed' && !$drive->files[$id]['trashed'] && in_array('tempo_scan_processed',$drive->names,true),'fully confirmed original moves, never trashed');
 gq($flow->preview($children[1],true)['bytes']===$source,'full original preview remains available after movement');
 gq($flow->preview($children[1])['bytes']==='%PDF-1.4 pages 3','invoice preview remains available after movement');
 $flow->run();gq(count($sent)===2,'cleanup does not resend');
 // Legacy page guesses are replaced only when no external submission exists.
 $legacyId='legacy-source';$oldId='legacy-page';$master=array_merge($d,['id'=>$legacyId,'children'=>[$oldId],'stage'=>'split','toconline_state'=>'receipt_per_page','sha256'=>hash('sha256',$source)]);
 $old=array_merge($d,['id'=>$oldId,'source_id'=>$legacyId,'stage'=>'failed','resume_stage'=>'archived','archived_at'=>'now','drive_parent'=>'wrong-month-folder','issued_on'=>'2026-02-16','sha256'=>hash('sha256',$source)]);
 $drive->files[$legacyId]=$master+['bytes'=>$source,'parents'=>['month-folder'],'trashed'=>false];
 $drive->files[$oldId]=$old+['bytes'=>$source,'parents'=>['wrong-month-folder'],'trashed'=>false];
 $vault->save(InvoiceReceipts::FILE,['documents'=>[$legacyId=>$master,$oldId=>$old],'requested'=>false]);
 $flow->run();$s=$flow->status();gq($s['documents'][$oldId]['stage']==='superseded' && !$drive->files[$oldId]['trashed'],'legacy unsent copy preserved until replacement');
 $fresh=$s['documents'][$legacyId]['children'][0];gq($s['documents'][$fresh]['issued_on']==='2026-04-08','old printed date replaced by validated QR date');
 for($n=0;$n<5;$n++)$flow->run();gq(!$drive->files[$oldId]['trashed'] && !$drive->files[$legacyId]['trashed'],'unconfirmed replacements retain source and unsent stale copies');
 echo "Grouped lifecycle, interrupted upload, confirmation gate, source move and manual resume passed\n";
} finally { foreach(glob($dir.'/*')?:[] as $f)unlink($f);rmdir($dir); }
