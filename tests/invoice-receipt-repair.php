<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/src/Invoices/InvoiceReceipts.php';
function rq(bool $v,string $m):void{if(!$v)throw new RuntimeException($m);}
$dir=sys_get_temp_dir().'/repair-'.bin2hex(random_bytes(8));mkdir($dir,0700);file_put_contents($dir.'/master.key',random_bytes(32));chmod($dir.'/master.key',0600);
try {
 $v=new InvoiceVault($dir);$pdo=new PDO('sqlite::memory:');
 $pdo->exec('CREATE TABLE invoice_document_delivery(document_id INTEGER,toconline_state TEXT);CREATE TABLE invoice_drive_folders(path_key TEXT PRIMARY KEY,drive_id TEXT,parent_id TEXT,name TEXT)');
 $drive=new class {
  public array $files=[],$newFiles=[];public int $sends=0;
  function receiptsAuthorized():bool{return true;}function connect():void{}
  function newId():string{return 'new-'.bin2hex(random_bytes(8));}
  function scanFiles($id):array{rq($id==='month-folder','Search only original month');return $this->newFiles;}
  function scanDocument($d):string{return $this->files[$d['id']]['bytes'];}
  function verifiedReceipt($d):string{$b=$this->files[$d['id']]['bytes'];rq(hash('sha256',$b)===$d['sha256'],'Content pinned');return $b;}
  function namedItems($p,$n,$f=true):array{return [];}
  function folder($id,$parent,$name):void{}
  function upload($id,$parent,$name,$path,$format):array{$b=file_get_contents($path);return $this->files[$id]=['id'=>$id,'bytes'=>$b,'parents'=>[$parent],'mimeType'=>'application/pdf','trashed'=>false,'size'=>strlen($b),'md5Checksum'=>md5($b)];}
  function moveReceipt($d):void{$this->files[$d['id']]['parents']=[$d['drive_parent']];}
  function trashReceiptFile($d):void{$this->files[$d['id']]['trashed']=true;}
 };
 $v->save('toconline-settings.enc',['nif'=>'500000000','sender'=>'test@welcomehostel.pt','enabled'=>true,'pilot_verified'=>true]);$sent=[];
 $toc=new InvoiceToconline($pdo,$v,$drive,static function($to,$mail)use(&$sent){$sent[]=$mail;return true;});
 $an=new class($v){
  public bool $wrong=false,$duplicate=false,$mixed=false;
  function __construct(private InvoiceVault $v){}function configured():bool{return true;}
  function analyse($bytes,$format,$nif):array{
   $joined=str_contains($bytes,'joined');
   $g=['pages'=>$joined?[1,2]:[1],'issuer_nif'=>$this->wrong?'999999999':'123456789','company_nif'=>$nif,'issuer_name'=>'Synthetic Supplier','invoice_number'=>'FT TEST/1','issued_on'=>'2026-04-02','total'=>'10.00','data_source'=>'qr','complete'=>$joined&&!$this->duplicate,'uncertain'=>false,'review_reason'=>$joined&&!$this->duplicate?'':'receipt_missing_pages',
     'page_evidence'=>$joined?[['source_page'=>1,'printed_page'=>2,'total_pages'=>2],['source_page'=>2,'printed_page'=>1,'total_pages'=>2]]:[['source_page'=>1,'printed_page'=>1,'total_pages'=>2]]];
   return ['page_count'=>$joined?2:1,'groups'=>$this->mixed?[$g,$g]:[$g]];
  }
  function assemble($parts,$order=[]):array{$b='%PDF-joined-'.implode(',',$order);$sha=hash('sha256',$b);InvoiceVault::atomicWrite($this->v->path('receipt-page-'.$sha.'.pdf'),$b);return ['sha256'=>$sha,'md5Checksum'=>md5($b),'size'=>strlen($b),'count'=>2,'pages'=>$order?:[1,2]];}
 };
 $b='%PDF-original page two';$extra='%PDF-missing page one';
 $base=['format'=>'pdf','mimeType'=>'application/pdf','root'=>'accounting-root','source'=>'temp-scan','source_parent'=>'month-folder','source_month'=>'month-folder','expected_period'=>'2026-04','process_id'=>'legacy','requested_by'=>1,'created_at'=>gmdate('c'),'attempts'=>0,'analysis_version'=>2];
 $master=$base+['id'=>'source-one','name'=>'source.pdf','children'=>['pending-one','other-pending'],'stage'=>'split','toconline_state'=>'receipt_per_page','size'=>strlen($b),'md5Checksum'=>md5($b),'sha256'=>hash('sha256',$b)];
 $d=$base+['id'=>'pending-one','source_id'=>'source-one','name'=>'source_f001.pdf','pages'=>[2],'page_number'=>2,'page_count'=>3,'size'=>strlen($b),'md5Checksum'=>md5($b),'sha256'=>hash('sha256',$b),'drive_parent'=>'pending-folder','preserved_at'=>'now','stage'=>'review','error'=>'receipt_missing_pages','review_reason'=>'receipt_missing_pages','toconline_state'=>'pending','issuer_nif'=>'123456789','company_nif'=>'500000000','invoice_number'=>'FT TEST/1','issued_on'=>'2026-04-02','total'=>'10.00','data_source'=>'qr'];
 $f=['id'=>'new-scan','name'=>'extra.pdf','mimeType'=>'application/pdf','parents'=>['month-folder'],'size'=>strlen($extra),'md5Checksum'=>md5($extra)];
 $drive->files=['source-one'=>['bytes'=>$b],'pending-one'=>['bytes'=>$b],'new-scan'=>['bytes'=>$extra]];
 $drive->newFiles=[$f];
 $seed=['analysis_version'=>2,'requested'=>false,'documents'=>['source-one'=>$master,'pending-one'=>$d,'other-pending'=>array_merge($d,['id'=>'other-pending','invoice_number'=>'FT TEST/2'])]];
 $v->save(InvoiceReceipts::FILE,$seed);$flow=new InvoiceReceipts($pdo,$v,$drive,$toc,null,$an);
 $flow->review('pending-one','2026-04-02','FT TEST/1',true,1,'Synthetic Supplier');
 rq($flow->status()['documents']['pending-one']['stage']==='review','Saving details cannot approve a known missing page');
 $v->save(InvoiceReceipts::FILE,$seed);
 $flow->requestRepair('pending-one',true,1);rq($flow->status()['documents']['pending-one']['stage']==='repairing','Explicit action queues verification');
 rq(!InvoiceReceiptReport::canStart($flow->status()),'Intake cannot steal repair candidates');
 try{$flow->retryDiscovery('legacy');throw new LogicException('discovery raced repair');}catch(RuntimeException $e){rq($e->getMessage()==='worker_busy','Discovery retry respects active repair');}
 $flow->run();$s=$flow->status();$new=$s['documents']['pending-one']['replacement_id'];
 rq($s['documents'][$new]['stage']==='ready' && $s['documents'][$new]['repair_order']===[2,1],'Complete matching pages sorted into one invoice');
 rq($s['documents']['other-pending']===$seed['documents']['other-pending'],'Other pending invoice unchanged');
 rq($s['documents']['new-scan']['children']===[$new] && $s['documents'][$new]['process_id']==='legacy','New source claimed in original process');
 rq($s['documents']['source-one']['children']===[$new,'other-pending'],'Only targeted child replaced');
 rq(count($sent)===0,'Repair verification does not duplicate submission');
 unlink($v->path('receipt-page-'.$s['documents'][$new]['sha256'].'.pdf'));
 $flow->run();rq(count($sent)===1,'Ready replacement follows normal archive and send');
 $flow->run();rq(count($sent)===1,'Unconfirmed submission is not repeated');
 rq($flow->status()['documents']['new-scan']['stage']==='split','New source retained before confirmation');
 foreach($v->read('toconline-ledger.enc') as$key=>$entry)if($entry['document_id']==='receipt:'.$new)$toc->receive('no_reply@toconline.pt','receipt-'.$key.'.pdf','',hash('sha256',$new),['outcome'=>'toc_accepted','authentication'=>['verified'=>true,'code'=>'verified','recipient'=>'test@welcomehostel.pt','signed_at'=>gmdate('c')]]);
 $flow->run();rq($flow->status()['documents']['new-scan']['stage']==='source_processed','Resolved additional source can move');
 rq($flow->status()['documents']['source-one']['stage']==='split','Other pending invoice still preserves original');
 foreach(['wrong','duplicate','mixed'] as $mode){
  $v->save(InvoiceReceipts::FILE,$seed);$an->$mode=true;
  $flow->requestRepair('pending-one',true,1);$flow->run();$s=$flow->status();
  rq($s['documents']['pending-one']['stage']==='review' && !empty($s['documents']['pending-one']['error']),'Uncertain repair stays pending: '.$mode);
  rq(!isset($s['documents']['new-scan']),'Unresolved candidates are not consumed: '.$mode);$an->$mode=false;
 }
 $v->save(InvoiceReceipts::FILE,$seed);$drive->newFiles=[];
 $flow->requestRepair('pending-one',true,1);$flow->run();
 rq($flow->status()['documents']['pending-one']['error']==='receipt_repair_not_found','No matching new scan gives actionable pending reason');
 $v->save(InvoiceReceipts::FILE,$seed);$drive->newFiles=[$f];
 $v->save('toconline-ledger.enc',['sent-fixture'=>['document_id'=>'receipt:pending-one','state'=>'toc_submitted','status'=>'sent']]);
 try{$flow->requestRepair('pending-one',true,1);throw new LogicException('submitted invoice was editable');}catch(RuntimeException $e){rq($e->getMessage()==='receipt_review_required','Sent history immutable');}
 echo "Targeted repair, identity/completeness gates, source ownership and single submission passed\n";
}finally{foreach(glob($dir.'/*')?:[]as$f)unlink($f);rmdir($dir);}
