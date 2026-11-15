<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/src/Invoices/InvoiceReceipts.php';
function cc(bool $v,string $m):void{if(!$v)throw new RuntimeException($m);}
$dir=sys_get_temp_dir().'/source-correct-'.bin2hex(random_bytes(8));mkdir($dir,0700);file_put_contents($dir.'/master.key',random_bytes(32));chmod($dir.'/master.key',0600);
try {
$v=new InvoiceVault($dir);$pdo=new PDO('sqlite::memory:');
$pdo->exec('CREATE TABLE invoice_document_delivery(document_id INTEGER,toconline_state TEXT)');
$v->save('toconline-settings.enc',['nif'=>'500000000','enabled'=>false]);
$drive=new class {
 public array $files=[];public function connect():void{} public function receiptsAuthorized():bool{return true;}
 public function newId():string{return 'child-'.bin2hex(random_bytes(8));}
 public function scanFiles($id):array{cc($id==='month-folder','Bounded original month');return array_values($this->files);}
 public function scanDocument($d):string{$f=$this->files[$d['id']];cc($f['parents'][0]===$d['source_parent'] && $f['md5Checksum']===$d['md5Checksum'],'Pinned source');return $f['bytes'];}
};
$an=new class($v){
 public int $calls=0;public function __construct(private $v){} public function configured():bool{return true;}
 public function classifySource($bytes):array{$this->calls++;return ['category'=>str_contains($bytes,'receipt')?'taloes':'a4'];}
 public function analyse($bytes,$format,$nif,$manual=null):array{return ['page_count'=>1,'groups'=>[['pages'=>[1],'complete'=>true,'uncertain'=>false,'review_reason'=>'','issuer_nif'=>'123456789','company_nif'=>$nif,'issuer_name'=>'Synthetic','invoice_number'=>'FT/1','issued_on'=>'2026-04-02','total'=>'10.00']]];}
 public function extract($bytes,$pages):array{$sha=hash('sha256',$bytes);InvoiceVault::atomicWrite($this->v->path('receipt-page-'.$sha.'.pdf'),$bytes);return ['sha256'=>$sha,'size'=>strlen($bytes),'md5Checksum'=>md5($bytes)];}
};
$docs=[];foreach(['bad'=>'%PDF-receipt','good'=>'%PDF-A4'] as $id=>$bytes){$f=['id'=>$id,'name'=>$id.'.pdf','mimeType'=>'application/pdf','size'=>strlen($bytes),'md5Checksum'=>md5($bytes),'parents'=>['folder-a4'],'source_category'=>'a4','source_path'=>'/A4'];$drive->files[$id]=$f+['bytes'=>$bytes];$docs[$id]=$f+['format'=>'pdf','root'=>'root-folder','source'=>'temp-scan','source_parent'=>'folder-a4','source_month'=>'month-folder','expected_period'=>'2026-04','process_id'=>'legacy','requested_by'=>1,'category_check_required'=>true,'stage'=>'queued','error'=>'','toconline_state'=>'pending'];}
$v->save(InvoiceReceipts::FILE,['analysis_version'=>2,'requested'=>false,'documents'=>$docs]);
$toc=new InvoiceToconline($pdo,$v,$drive,static function(){throw new LogicException('Never submit a blocked source');});$flow=new InvoiceReceipts($pdo,$v,$drive,$toc,null,$an);
$flow->run();$s=$flow->status();cc($s['documents']['bad']['stage']==='review' && $s['documents']['bad']['error']==='receipt_category_mismatch','Only mismatched PDF held before split');
cc(empty($s['documents']['bad']['children']),'No invoice children before category confirmation');
try{$flow->correctionCandidates('bad');throw new LogicException('Busy correction');}catch(RuntimeException $e){cc($e->getMessage()==='worker_busy','Correction waits for current processing');}
$flow->run();$s=$flow->status();cc($s['documents']['good']['stage']==='split','Other source continues');
// Isolate settled pending source, as after unrelated work completes.
$v->save(InvoiceReceipts::FILE,['analysis_version'=>2,'requested'=>false,'documents'=>['bad'=>$s['documents']['bad']]]);
$preview=$flow->preview('bad');cc($preview['bytes']==='%PDF-receipt','Blocked PDF preview retained');
$drive->files['bad']['parents']=['folder-receipts'];$drive->files['bad']['source_category']='taloes';$drive->files['bad']['source_path']='/Talões';
$candidates=$flow->correctionCandidates('bad');$selected=array_values(array_filter($candidates,fn($f)=>$f['name']==='bad.pdf'))[0];
cc(str_contains($selected['path'],'Talões'),'Exact source path displayed');
$flow->requestReplacement('bad',[$selected['token']],true,1);
cc(!InvoiceReceiptReport::canStart($flow->status(),false),'Correction is active work');
$flow->run();$s=$flow->status();cc($s['documents']['bad']['stage']==='queued' && $s['documents']['bad']['source_category']==='taloes','Moved original resumes same ID');
cc(count($s['documents']['bad']['correction_history'])===1,'Previous location preserved');
$before=$an->calls;$flow->run();$s=$flow->status();$child=$s['documents']['bad']['children'][0];
cc($an->calls===$before,'Same content reuses classification');cc(InvoiceReceipts::archiveCategory($s['documents'][$child])==='taloes','Receipt PDF children inherit correct archive');
// Different selected PDF is explicitly associated, not rediscovered or silently submitted twice.
$pending=$docs['bad']+['sha256'=>hash('sha256','%PDF-receipt')];$pending['stage']='review';$pending['error']='receipt_category_mismatch';
$v->save(InvoiceReceipts::FILE,['analysis_version'=>2,'requested'=>false,'documents'=>['bad'=>$pending]]);
$f=$drive->files['good'];$flow->requestReplacement('bad',[InvoiceReceiptSelection::token($f)],true,1);$flow->run();$s=$flow->status();
cc($s['documents']['bad']['stage']==='superseded' && $s['documents']['bad']['replacement_id']==='good','Old history linked to replacement');
cc($s['documents']['good']['stage']==='queued' && $s['documents']['good']['replaces']==='bad','New source claimed once');
// File moved after selection is held without processing.
$v->save(InvoiceReceipts::FILE,['analysis_version'=>2,'requested'=>false,'documents'=>['bad'=>$pending]]);
$flow->requestReplacement('bad',[InvoiceReceiptSelection::token($drive->files['good'])],true,1);$drive->files['good']['parents']=['elsewhere'];$flow->run();
cc($flow->status()['documents']['bad']['stage']==='review' && $flow->status()['documents']['bad']['error']==='receipt_selection_changed','Stale selection safely returns to review');
echo "Source isolation, explicit correction, history and routing passed\n";
}finally{foreach(glob($dir.'/*')?:[] as $f)unlink($f);rmdir($dir);}
