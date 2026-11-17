<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/src/Invoices/InvoiceReceipts.php';
function cc(bool $v,string $m):void{if(!$v)throw new RuntimeException($m);}
$dir=sys_get_temp_dir().'/source-correct-'.bin2hex(random_bytes(8));mkdir($dir,0700);file_put_contents($dir.'/master.key',random_bytes(32));chmod($dir.'/master.key',0600);
try {
$v=new InvoiceVault($dir);$pdo=new PDO('sqlite::memory:');
$pdo->exec('CREATE TABLE invoice_document_delivery(document_id INTEGER,toconline_state TEXT); CREATE TABLE invoice_drive_folders(path_key TEXT PRIMARY KEY,drive_id TEXT,parent_id TEXT,name TEXT)');
$v->save('toconline-settings.enc',['nif'=>'500000000','enabled'=>false]);
$drive=new class {
 public array $files=[];public array $trashed=[];public array $moved=[];public bool $failTrash=false;public function connect():void{} public function receiptsAuthorized():bool{return true;}
 public function newId():string{return 'child-'.bin2hex(random_bytes(8));}
 public function namedItems($parent,$names,$folder=true):array{return [];}
 public function folder($id,$parent,$name):void{}
 public function moveReceipt($d):void{$this->moved[]=$d['id'];}
 public function verifiedReceipt($d):string{return '%PDF-verified';}
 public function trashReceiptFile($d):void{$this->trashed[]=$d['id'];if($this->failTrash){$this->failTrash=false;throw new RuntimeException('drive_network');}}
 public function scanFiles($id):array{cc($id==='month-folder','Bounded original month');return array_values(array_map(static function($f){unset($f['bytes']);return $f;},$this->files));}
 public function scanDocument($d):string{$f=$this->files[$d['id']];cc($f['parents'][0]===$d['source_parent'] && $f['md5Checksum']===$d['md5Checksum'],'Pinned source');return $f['bytes'];}
};
$an=new class($v){
 public int $calls=0;public array $analysed=[];public ?array $result=null;public function __construct(private $v){} public function configured():bool{return true;}
 public function classifySource($bytes):array{$this->calls++;return ['category'=>str_contains($bytes,'receipt')?'taloes':'a4'];}
 public function analyse($bytes,$format,$nif,$manual=null):array{$this->analysed[]=[$bytes,$format,$manual];if($this->result!==null)return $this->result;return ['page_count'=>1,'groups'=>[['pages'=>[1],'complete'=>true,'uncertain'=>false,'review_reason'=>'','issuer_nif'=>'123456789','company_nif'=>$nif,'issuer_name'=>'Synthetic','invoice_number'=>'FT/1','issued_on'=>'2026-04-02','total'=>'10.00']]];}
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
// Unidentified/ambiguous invoice replacement must ignore every old fiscal approval/guess.
$drive->files['good']['parents']=['folder-a4'];
$old=array_replace($pending,['id'=>'old-leaf','source_id'=>'original-master','category_check_required'=>false,
 'error'=>'receipt_review_required','review_reason'=>'receipt_review_required','uncertain'=>true,
 'issued_on'=>'','issuer_name'=>'','invoice_number'=>'','issuer_nif'=>'','company_nif'=>'999999990',
 'data_source'=>'qr','qr_fields'=>['G'=>'OLD'],'fiscal_status'=>'A','manual_groups'=>[[1]],
 'reviewed_at'=>'old-approval','company_confirmation'=>['actor'=>1],'preserved_at'=>'now','drive_parent'=>'pending-folder']);
$master=array_replace($docs['bad'],['id'=>'original-master','children'=>['old-leaf','sibling'],'stage'=>'split','toconline_state'=>'receipt_per_page']);
$sibling=array_replace($docs['good'],['id'=>'sibling','sha256'=>hash('sha256','sibling'),'source_id'=>'original-master','stage'=>'archived','toconline_state'=>'toc_accepted']);
$base=['analysis_version'=>2,'requested'=>false,'documents'=>['original-master'=>$master,'old-leaf'=>$old,'sibling'=>$sibling]];
$v->save('toconline-ledger.enc',[]);$v->save(InvoiceReceipts::FILE,$base);
cc(count(array_filter($flow->correctionCandidates('old-leaf'),fn($f)=>$f['token']!==''))===2,'Unidentified invoice can open picker');
$flow->requestReplacement('old-leaf',[InvoiceReceiptSelection::token($drive->files['good'])],true,1);
$flow->run();$s=$flow->status();$new=$s['documents']['good'];
cc($new['stage']==='queued' && $s['documents']['old-leaf']['stage']==='superseded','New submission queued despite blank and conflicting old data');
foreach(['issued_on','issuer_nif','company_nif','qr_fields','fiscal_status','manual_groups','company_confirmation','reviewed_at','review_reason','source_id','preserved_at','drive_parent'] as $key)cc(!isset($new[$key]),'Old data cannot transfer: '.$key);
cc($s['documents']['original-master']['children']===['good','sibling'] && $s['documents']['sibling']===$sibling,'Replacement retains original master and untouched sibling');
cc($new['legacy_children']===['old-leaf'] && !$drive->trashed && !$drive->moved,'Original derivative retained until correction accepted');
try{$flow->regroup('original-master','1;2',true,1);throw new LogicException('Regroup orphaned replacement');}catch(RuntimeException $e){cc($e->getMessage()==='worker_busy','Cannot regroup during replacement');}
try{$flow->requestReplacement('old-leaf',[InvoiceReceiptSelection::token($drive->files['good'])],true,1);throw new LogicException('Repeated submit');}catch(RuntimeException $e){cc($e->getMessage()==='receipt_review_required','Repeated submission cannot allocate second replacement');}
$before=count($an->analysed);$flow->run();$s=$flow->status();$leaves=$s['documents']['good']['children'];$leaf=$leaves[0];
cc(count($an->analysed)===$before+1 && end($an->analysed)===['%PDF-A4','pdf',null],'Fresh full fiscal analysis, no old grouping');
cc($s['documents']['original-master']['children']===[$leaf,'sibling'] && $s['documents'][$leaf]['stage']==='ready','Every source flattened to new invoice leaves');
cc($s['documents'][$leaf]['invoice_number']==='FT/1','Fresh fiscal identity may differ from unavailable old identity');
// Confirmed synthetic final copies allow both originals to finish; cleanup retries do not reanalyse.
$s['documents'][$leaf]['stage']='archived';$s['documents'][$leaf]['toconline_state']='toc_accepted';$s['documents'][$leaf]['archived_at']='now';
$v->save(InvoiceReceipts::FILE,$s);$flow->run();$drive->failTrash=true;$flow->run();$s=$flow->status();
cc($s['documents']['old-leaf']['retiring']===true && empty($s['documents']['old-leaf']['retired_at']),'Unknown cleanup result retains durable retry');
$s['documents']['good']['next_attempt']=0;$v->save(InvoiceReceipts::FILE,$s);$flow->run();$s=$flow->status();
cc($s['documents']['original-master']['stage']==='source_processed' && $s['documents']['good']['stage']==='source_processed','Both contributing masters finish without touching sibling');
cc(!empty($s['documents']['old-leaf']['retired_at']) && count($an->analysed)===$before+1,'Superseded pending copy retired only after acceptance; no repeat analysis');
// Ambiguous source (before split), JPG replacement, and new ambiguous result remain independently reviewable.
$bytes="\xff\xd8\xffnew scan";$drive->files['jpeg']=['id'=>'jpeg','name'=>'new.jpg','mimeType'=>'image/jpeg','size'=>strlen($bytes),'md5Checksum'=>md5($bytes),'parents'=>['folder-a4'],'source_category'=>'a4','bytes'=>$bytes];
$source=array_replace($old,['id'=>'ambiguous-source','grouping_pending'=>true,'review_reason'=>'receipt_grouping_required']);unset($source['source_id']);
$v->save(InvoiceReceipts::FILE,['analysis_version'=>2,'requested'=>false,'documents'=>['ambiguous-source'=>$source]]);
$flow->requestReplacement('ambiguous-source',[InvoiceReceiptSelection::token($drive->files['jpeg'])],true,1);$flow->run();$beforeClassify=$an->calls;
$an->result=['page_count'=>1,'groups'=>[['pages'=>[1],'complete'=>true,'uncertain'=>true,'review_reason'=>'receipt_review_required']]];
$flow->run();$s=$flow->status();$newLeaf=$s['documents']['jpeg']['children'][0];
cc($an->calls===$beforeClassify && end($an->analysed)===[$bytes,'jpg',null],'JPEG replacement uses normal JPEG analysis without PDF classifier');
cc($s['documents'][$newLeaf]['stage']==='preserving','New ambiguous data still requires review after reprocessing');$an->result=null;
// Multiple invoice leaves replace only the pending slot in every contributing source.
$multi=$base;$multi['documents']['other-master']=array_replace($master,['id'=>'other-master','children'=>['old-leaf']]);
$v->save(InvoiceReceipts::FILE,$multi);$flow->requestReplacement('old-leaf',[InvoiceReceiptSelection::token($drive->files['good'])],true,1);$flow->run();
$group=['complete'=>true,'uncertain'=>true,'review_reason'=>'receipt_review_required'];$an->result=['page_count'=>2,'groups'=>[$group+['pages'=>[1]],$group+['pages'=>[2]]]];
$flow->run();$s=$flow->status();$leaves=$s['documents']['good']['children'];
cc(count($leaves)===2 && $s['documents']['original-master']['children']===array_merge($leaves,['sibling']) && $s['documents']['other-master']['children']===$leaves,'One replacement can reanalyse multiple leaves without orphaning any contributing master');$an->result=null;
// A stale generic replacement must not become a PDF-only category correction.
$generic=array_replace($source,['category_check_required'=>true,'category_check'=>['category'=>'a4'],'source_category'=>'a4','error'=>'receipt_selection_changed','replacement_allow_same_id'=>false]);
cc(!InvoiceReceipts::categoryReview($generic) && InvoiceReceipts::replacementReview($generic),'Stale ambiguous source keeps generic replacement eligibility');
// Changed or already claimed input cannot silently supersede the pending item.
$v->save(InvoiceReceipts::FILE,$base);$flow->requestReplacement('old-leaf',[InvoiceReceiptSelection::token($drive->files['good'])],true,1);
$drive->files['good']['md5Checksum']=md5('changed bytes');$flow->run();$s=$flow->status();
cc($s['documents']['old-leaf']['stage']==='review' && !isset($s['documents']['good']) && $s['documents']['original-master']['children']===$master['children'],'Changed bytes preserve old pending item and topology');
$base['documents']['good']=$sibling;$v->save(InvoiceReceipts::FILE,$base);
try{$flow->requestReplacement('old-leaf',[InvoiceReceiptSelection::token($drive->files['good'])],true,1);throw new LogicException('Claimed input');}catch(RuntimeException $e){cc($e->getMessage()==='receipt_selection_changed','Already claimed input rejected');}
$v->save('toconline-ledger.enc',['sent-fixture'=>['document_id'=>'receipt:old-leaf','state'=>'toc_submitted','status'=>'sent']]);$v->save(InvoiceReceipts::FILE,$base);
try{$flow->requestReplacement('old-leaf',[InvoiceReceiptSelection::token($drive->files['jpeg'])],true,1);throw new LogicException('Previously submitted input');}catch(RuntimeException $e){cc($e->getMessage()==='receipt_review_required','TOConline history prevents replacement of submitted content');}
foreach([$master,$sibling,array_replace($old,['stage'=>'discarded']),array_replace($old,['stage'=>'correcting'])] as $blocked)cc(!InvoiceReceipts::replacementReview($blocked),'Only individual pending input is replaceable');
echo "Source isolation, explicit correction, history and routing passed\n";
}finally{foreach(glob($dir.'/*')?:[] as $f)unlink($f);rmdir($dir);}
