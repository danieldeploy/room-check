<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/src/Invoices/InvoiceReceipts.php';
function buyerCheck(bool $ok,string $message):void { if (!$ok) throw new RuntimeException($message); }
function buyerReject(callable $action):void {
    try { $action(); } catch (RuntimeException $e) { return; }
    throw new LogicException('Unsafe action was allowed');
}
$dir=sys_get_temp_dir().'/buyer-'.bin2hex(random_bytes(8));mkdir($dir,0700);
file_put_contents($dir.'/master.key',random_bytes(32));chmod($dir.'/master.key',0600);
try {
    $v=new InvoiceVault($dir);$pdo=new PDO('sqlite::memory:');
    $pdo->exec('CREATE TABLE invoice_document_delivery(document_id INTEGER,toconline_state TEXT); CREATE TABLE invoice_drive_folders(path_key TEXT PRIMARY KEY,drive_id TEXT,parent_id TEXT,name TEXT)');
    $drive=new class {
        public array $files=[],$trashed=[],$moved=[];public bool $denyTrash=false;
        function receiptsAuthorized():bool{return true;} function connect():void{}
        function verifiedReceipt($d):string{return $this->files[$d['id']];}
        function moveReceipt($d):void{$this->moved[]=$d['id'];}
        function namedItems($p,$n,$f=true):array{return [];}
        function newId():string{return 'folder-fixture';} function folder($id,$p,$n):void{}
        function trashReceiptFile($d):void {if($this->denyTrash)throw new RuntimeException('drive_trash_permission');$this->trashed[]=$d['id'];}
    };
    $v->save('toconline-settings.enc',['nif'=>'500000000','sender'=>'test@welcomehostel.pt','enabled'=>true,'pilot_verified'=>true]);$sent=[];
    $toc=new InvoiceToconline($pdo,$v,$drive,static function($to,$mail)use(&$sent){$sent[]=$mail;return true;});
    $flow=new InvoiceReceipts($pdo,$v,$drive,$toc);
    foreach (['pdf','jpg'] as $format) foreach (['600000000','999999990',''] as $buyer) {
        $bytes=$format==='pdf'?'%PDF-synthetic invoice':base64_decode('/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/2wBDAQkJCQwLDBgNDRgyIRwhMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjL/wAARCAABAAEDASIAAhEBAxEB/8QAHwAAAQUBAQEBAQEAAAAAAAAAAAECAwQFBgcICQoL/8QAtRAAAgEDAwIEAwUFBAQAAAF9AQIDAAQRBRIhMUEGE1FhByJxFDKBkaEII0KxwRVS0fAkM2JyggkKFhcYGRolJicoKSo0NTY3ODk6Q0RFRkdISUpTVFVWV1hZWmNkZWZnaGlqc3R1dnd4eXqDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uHi4+Tl5ufo6erx8vP09fb3+Pn6/8QAHwEAAwEBAQEBAQEBAQAAAAAAAAECAwQFBgcICQoL/8QAtREAAgECBAQDBAcFBAQAAQJ3AAECAxEEBSExBhJBUQdhcRMiMoEIFEKRobHBCSMzUvAVYnLRChYkNOEl8RcYGRomJygpKjU2Nzg5OkNERUZHSElKU1RVVldYWVpjZGVmZ2hpanN0dXZ3eHl6goOEhYaHiImKkpOUlZaXmJmaoqOkpaanqKmqsrO0tba3uLm6wsPExcbHyMnK0tPU1dbX2Nna4uPk5ebn6Onq8vP09fb3+Pn6/9oADAMBAAIRAxEAPwD3+iiigD//2Q==');
        $d=['id'=>'invoice-'.$format,'source_id'=>'master','format'=>$format,'name'=>'fixture.'.$format,'size'=>strlen($bytes),'md5Checksum'=>md5($bytes),'sha256'=>hash('sha256',$bytes),
            'stage'=>'review','error'=>'receipt_company_changed','review_reason'=>'receipt_company_changed','toconline_state'=>'pending','analysis_version'=>2,'pdf_pages_checked'=>true,
            'issuer_name'=>'Synthetic Supplier','issuer_nif'=>'123456789','company_nif'=>$buyer,'issued_on'=>'2026-04-02','invoice_number'=>'FT TEST/1','total'=>'10.00','fiscal_status'=>'N',
            'data_source'=>'qr','qr_fields'=>['A'=>'123456789','B'=>$buyer],'complete'=>true,'uncertain'=>false,'preserved_at'=>'now','pending_parent'=>'pending-folder','drive_parent'=>'pending-folder','root'=>'root'];
        $seed=['analysis_version'=>2,'requested'=>false,'source_cleanup_paused'=>true,'documents'=>[$d['id']=>$d]];
        $v->save(InvoiceReceipts::FILE,$seed);$drive->files[$d['id']]=$bytes;
        buyerReject(fn()=>$flow->confirmBuyer($d['id'],false,9));
        $flow->confirmBuyer($d['id'],true,9);$accepted=$flow->status()['documents'][$d['id']];
        buyerCheck($accepted['stage']==='ready','Explicit buyer confirmation releases otherwise valid '.$format);
        buyerCheck($accepted['company_nif']===$buyer && $accepted['qr_fields']===$d['qr_fields'],'Never rewrite source buyer or QR');
        buyerCheck($accepted['company_confirmation']['actor']===9 && !empty($accepted['company_confirmation']['at']),'Confirmation auditable');
        buyerCheck(!InvoiceReceiptAnalysis::buyerAccepted(array_replace($accepted,['sha256'=>str_repeat('a',64)]),'500000000'),'Changed content invalidates confirmation');
        buyerCheck(!InvoiceReceiptAnalysis::buyerAccepted($accepted,'700000000'),'Changed destination invalidates confirmation');
        buyerReject(fn()=>$flow->confirmBuyer($d['id'],true,9));
        $v->save('toconline-ledger.enc',[]);$before=count($sent);$flow->run();$flow->run();
        buyerCheck(count($sent)===$before+1,'Manually classified invoice sent once through existing transport');
        buyerReject(fn()=>$flow->requestDiscard($d['id'],true,9));
        $v->save('toconline-ledger.enc',[]);
        foreach (['missing','uncertain','fiscal','grouping'] as $blocker) {
            $blocked=$d;
            if($blocker==='missing')$blocked['complete']=false;
            if($blocker==='uncertain')$blocked['uncertain']=true;
            if($blocker==='fiscal')$blocked['fiscal_status']='A';
            if($blocker==='grouping')$blocked['grouping_pending']=true;
            $seed['documents'][$d['id']]=$blocked;$v->save(InvoiceReceipts::FILE,$seed);
            buyerReject(fn()=>$flow->confirmBuyer($d['id'],true,9));$state=$flow->status()['documents'][$d['id']];
            buyerCheck($state['stage']==='review' && empty($state['company_confirmation']),'Incomplete confirmation must be atomic: '.$blocker);
        }
        $seed['documents'][$d['id']]=$d;$v->save(InvoiceReceipts::FILE,$seed);
        buyerReject(fn()=>$flow->review($d['id'],'2026-04-02','FT TEST/1',true,9,'Synthetic Supplier'));
        buyerCheck($flow->status()['documents'][$d['id']]['stage']==='review','Generic review cannot implicitly confirm buyer');
        $incomplete=array_replace($d,['data_source'=>'gemini','issuer_nif'=>'','issued_on'=>'','issuer_name'=>'','invoice_number'=>'']);
        $seed['documents'][$d['id']]=$incomplete;$v->save(InvoiceReceipts::FILE,$seed);
        $beforeReview=$flow->status()['documents'][$d['id']];
        buyerReject(fn()=>$flow->review($d['id'],'02/04/2026','FT TEST/1',true,9,'Synthetic Supplier','',true));
        buyerCheck($flow->status()['documents'][$d['id']]===$beforeReview,'Invalid combined form must not save partial buyer approval');
        buyerCheck(count(InvoiceReceiptAnalysis::reviewFields($incomplete))===4,'First review identifies all four missing fields');
        $flow->review($d['id'],'02/04/2026','FT TEST/1',true,9,'Synthetic Supplier','123456789',true);
        $complete=$flow->status()['documents'][$d['id']];
        buyerCheck($complete['stage']==='ready' && $complete['issued_on']==='2026-04-02' && $complete['issuer_nif']==='123456789','One explicit valid review queues complete details');
        buyerCheck(InvoiceReceiptAnalysis::buyerAccepted($complete,'500000000') && $complete['company_nif']===$buyer,'Configured buyer accepted without overwriting extracted NIF');
        buyerReject(fn()=>$flow->review($d['id'],'02/04/2026','FT TEST/1',true,9,'Synthetic Supplier','123456789',true));
        $complete['stage']='review';$complete['error']='receipt_review_required';$seed['documents'][$d['id']]=$complete;$v->save(InvoiceReceipts::FILE,$seed);
        buyerReject(fn()=>$flow->requestDiscard($d['id'],true,9));
        $seed['documents'][$d['id']]=$d;$v->save(InvoiceReceipts::FILE,$seed);
        buyerReject(fn()=>$flow->review($d['id'],'02/04/2026','FT TEST/1',true,9,'Synthetic Supplier','987654321',true));
        buyerReject(fn()=>$flow->requestRepair($d['id'],true,9));
        buyerReject(fn()=>$flow->requestDiscard($d['id'],false,9));
        $before=count($sent);$flow->requestDiscard($d['id'],true,9);
        buyerCheck($flow->status()['documents'][$d['id']]['stage']==='discarding','Persist deletion intent before touching Drive');
        $drive->denyTrash=true;$flow->run();$state=$flow->status()['documents'][$d['id']];
        buyerCheck(!InvoiceReceiptReport::resolved($state),'Drive denial is not completed deletion');
        $drive->denyTrash=false;$flow->retry($d['id']);$flow->run();$state=$flow->status()['documents'][$d['id']];
        buyerCheck($state['stage']==='discarded' && InvoiceReceiptReport::resolved($state),'Successful trash resolves only local work');
        buyerCheck($state['toconline_state']==='receipt_not_submitted' && count($sent)===$before,'Discarded invoice never submitted');
        buyerCheck(!InvoiceReceiptReport::documents($flow->status(),'legacy','success'),'Deleted is not TOConline accepted');
        buyerReject(fn()=>$flow->preview($d['id']));
        buyerReject(fn()=>$flow->requestDiscard($d['id'],true,9));
    }
    buyerCheck(!in_array('master',$drive->trashed,true),'Mixed master never trashed by invoice deletion');
    $first=$state;$first['id']='deleted-one';$first['process_id']='legacy';
    $second=$d;$second['id']='pending-two';$second['process_id']='legacy';$drive->files['pending-two']=$bytes;
    $master=['id'=>'master','name'=>'mixed.pdf','format'=>'pdf','root'=>'root','source_parent'=>'original-month',
        'stage'=>'split','toconline_state'=>'receipt_per_page','children'=>['deleted-one','pending-two'],'process_id'=>'legacy'];
    $v->save(InvoiceReceipts::FILE,['analysis_version'=>2,'requested'=>false,'documents'=>['master'=>$master,'deleted-one'=>$first,'pending-two'=>$second]]);
    $drive->files['master']='%PDF-synthetic combined original';
    $flow->run();buyerCheck(!in_array('master',$drive->moved,true),'Pending sibling protects the whole source');
    $flow->requestDiscard('pending-two',true,9);$flow->run();$flow->run();
    buyerCheck($flow->status()['documents']['master']['stage']==='source_processed','Source moves only after every invoice resolved, including explicit deletions');
    buyerCheck(!in_array('master',$drive->trashed,true),'Completed mixed source is moved, not trashed');
    buyerCheck(count($drive->trashed)===7,'Repeated worker ticks do not repeat successful deletion');
    buyerCheck(!InvoiceReceiptAnalysis::discardable($master+['sha256'=>str_repeat('a',64)]),'Master with multiple invoices cannot be discarded');
    buyerCheck(!InvoiceReceiptAnalysis::discardable(['stage'=>'review','format'=>'pdf','sha256'=>str_repeat('a',64),'page_count'=>10,'pdf_pages_checked'=>true]),'Legacy multipage source is never mistaken for one invoice');
    foreach ([''=>'receipt_buyer_missing','999999990'=>'receipt_buyer_consumer','600000000'=>'receipt_company_changed'] as $buyer=>$reason) {
        $group=['pages'=>[1],'company_nif'=>(string)$buyer,'issuer_nif'=>'123456789','issuer_name'=>'Synthetic Supplier',
            'invoice_number'=>'FT TEST/1','issued_on'=>'2026-04-02','total'=>'10.00','complete'=>false,'uncertain'=>false];
        $manifest=InvoiceReceiptAnalysis::validate(['groups'=>[$group]],['count'=>1,'pages'=>[['number'=>1,'qr'=>[]]]],'500000000');
        buyerCheck($manifest['groups'][0]['review_reason']===$reason && !$manifest['groups'][0]['complete'],'Analysis keeps the specific buyer error and independent incomplete evidence');
    }
    echo "Buyer confirmation, immutable evidence, independent blockers, single send and scoped discard passed\n";
} finally { foreach(glob($dir.'/*')?:[] as $file)unlink($file);rmdir($dir); }
