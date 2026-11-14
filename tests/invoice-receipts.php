<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/src/Invoices/InvoiceReceipts.php';
require_once dirname(__DIR__).'/src/Invoices/InvoiceTocPipe.php';
require_once dirname(__DIR__).'/src/Auth/Auth.php';
function rc(bool $v,string $m): void { if (!$v) throw new RuntimeException($m); }
function rr(callable $f,string $m): void { try { $f(); } catch (RuntimeException) { return; } throw new RuntimeException($m); }
$dir=sys_get_temp_dir().'/receipts-'.bin2hex(random_bytes(8)); mkdir($dir,0700); file_put_contents($dir.'/master.key',random_bytes(32)); chmod($dir.'/master.key',0600);
try {
    $vault=new InvoiceVault($dir);
    $pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $pdo->exec("CREATE TABLE invoice_document_delivery(document_id INTEGER,toconline_state TEXT);
        CREATE TABLE invoice_drive_settings(id INTEGER,state TEXT,folder_id TEXT);
        INSERT INTO invoice_drive_settings VALUES(1,'ready','accounting-root');
        CREATE TABLE invoice_drive_folders(path_key TEXT PRIMARY KEY,drive_id TEXT,parent_id TEXT,name TEXT);
        CREATE TABLE role_permissions(role TEXT,permission TEXT);");
    $text="Fatura FT 2026/42\nData de emissão: 02/10/2026\nCliente: Empresa Teste\nNIF: 500000000\nPeríodo de serviços: Setembro 2026";
    $parsed=InvoiceReceipts::classify($text,'500000000'); rc($parsed['issued_on']==='2026-10-02','issue date, never service month');
    rc(InvoiceReceipts::classify($text."\nInvoice date: 03/10/2026",'500000000')===null,'two issue dates require review');
    rc(InvoiceReceipts::classify($text."\nFatura FT 2026/43",'500000000')===null,'two documents require review');
    rc(InvoiceReceipts::classify(str_replace('Cliente:','Fornecedor:',$text),'500000000')===null,'issuer NIF is not recipient evidence');
    rc(InvoiceReceipts::classify(str_replace('02/10/2026','31/02/2026',$text),'500000000')===null,'invalid date');
    rc(InvoiceReceipts::classify('scan_2026_10_02.pdf','500000000')===null,'filename is never evidence');
    rc(InvoiceReceipts::classify($text,'500000001')===null,'wrong company');
    rc(!InvoiceReceipts::validDate('2026-2-1') && !InvoiceReceipts::validDate('2099-01-01'),'exact date and no future month');
    rc(Auth::roleHasPermission($pdo,'gerente',Auth::PERMISSION_RECEIPTS_RUN),'manager permission locked with existing persisted matrix');
    rc(!Auth::roleHasPermission($pdo,'governanta',Auth::PERMISSION_RECEIPTS_RUN),'governanta opt-in');
    $pdo->exec("INSERT INTO role_permissions VALUES('governanta','receipts.run')"); Auth::resetPermissionCache();
    rc(!Auth::roleHasPermission($pdo,'governanta',Auth::PERMISSION_RECEIPTS_VIEW) && !Auth::roleHasPermission($pdo,'governanta',Auth::PERMISSION_RECEIPTS_REVIEW) && !Auth::roleHasPermission($pdo,'governanta',Auth::PERMISSION_INVOICES_VIEW),'running grants summary only');
    $pdo->exec("INSERT INTO role_permissions VALUES('governanta','receipts.view')"); Auth::resetPermissionCache();
    rc(!Auth::roleHasPermission($pdo,'governanta',Auth::PERMISSION_RECEIPTS_VIEW),'legacy auto-added view cannot expose history');
    $pdo->exec("INSERT INTO role_permissions VALUES('governanta','receipts.history')"); Auth::resetPermissionCache();
    rc(Auth::roleHasPermission($pdo,'governanta',Auth::PERMISSION_RECEIPTS_VIEW) && !Auth::roleHasPermission($pdo,'governanta',Auth::PERMISSION_RECEIPTS_REVIEW),'explicit future history grant does not grant corrections');
    $pdo->exec("DELETE FROM role_permissions WHERE permission='receipts.history'; INSERT INTO role_permissions VALUES('governanta','receipts.review')"); Auth::resetPermissionCache();
    rc(Auth::roleHasPermission($pdo,'governanta',Auth::PERMISSION_RECEIPTS_VIEW) && Auth::roleHasPermission($pdo,'governanta',Auth::PERMISSION_RECEIPTS_REVIEW),'review includes the history it needs');
    $pdo->exec("DELETE FROM role_permissions WHERE permission='receipts.review'"); Auth::resetPermissionCache();
    $drive=new class($text) {
        public array $files=[]; public array $moved=[]; public array $trashed=[]; public array $folderNames=[];
        public array $pageTexts=[]; public array $ocrTexts=[]; public int $uploads=0; public bool $crashUpload=false; public int $ocr=0; public int $discovered=0; public bool $grant=true; public bool $crashMove=false; public bool $crashOcr=false;
        public function __construct(public string $text) {}
        public function add(string $id,string $pdf,string $format='pdf',int $month=10): void { $this->files[$id]=['id'=>$id,'name'=>'scan.'.$format,'mimeType'=>$format==='jpg'?'image/jpeg':'application/pdf','size'=>strlen($pdf),'md5Checksum'=>md5($pdf),'parents'=>['scan-month-'.$month],'pdf'=>$pdf,'trashed'=>false]; }
        public function receiptsAuthorized(): bool { return $this->grant; }
        public bool $connected=false; public function connect(): void { $this->connected=true; }
        public function metadata(string $id): ?array { return $this->files[$id]??['id'=>$id,'trashed'=>false,'mimeType'=>'application/vnd.google-apps.folder','owners'=>[['emailAddress'=>'daniel.ciorcas@welcomehostel.pt']]]; }
        public function namedItems(string $parent,array $names,bool $folder=true): array { if ($names===['temp_scan']) return [['id'=>'temp-scan-folder']]; if ($parent==='temp-scan-folder') foreach (InvoiceDriveLayout::MONTHS as $month=>$name) if (in_array($name,$names,true)) return [['id'=>'scan-month-'.$month]]; return []; }
        public function scanFiles(string $root): array { $this->discovered++; return array_values(array_map(static function($f) { unset($f['pdf']); return $f; },array_filter($this->files,static fn($f)=>$f['parents']===[$root]))); }
        public function newId(): string { return 'folder-'.bin2hex(random_bytes(8)); }
        public function folder(string $id,string $parent,string $name): void { $this->folderNames[]=$name; }
        public function scanDocument(array $d): string { rc($this->files[$d['id']]['md5Checksum']===$d['md5Checksum'],'source hash'); return $this->files[$d['id']]['pdf']; }
        public function createReceiptOcr(string $pdf,string $id,string $parent,string $mime='application/pdf'): string { $this->ocr++; $this->ocrTexts['ocr-'.$id]=$this->pageTexts[$pdf]??$this->text; if ($this->crashOcr) throw new RuntimeException('drive_network'); return 'ocr-'.$id; }
        public function findReceiptOcr(string $source,string $parent): ?string { return null; }
        public function receiptOcrText(string $id): string { return $this->ocrTexts[$id]??$this->text; }
        public function trashReceiptOcr(string $id,string $source): void {}
        public function moveReceipt(array $d): void { $this->files[$d['id']]['parents']=[$d['drive_parent']]; $this->moved[$d['id']]=true; if ($this->crashMove) { $this->crashMove=false; throw new RuntimeException('drive_network'); } }
        public function upload(string $id,string $parent,string $name,string $path,string $format): array {
            if (!isset($this->files[$id])) { $this->uploads++; $this->add($id,(string)file_get_contents($path)); $this->files[$id]['parents']=[$parent]; }
            if ($this->crashUpload) { $this->crashUpload=false; throw new RuntimeException('drive_network'); }
            return $this->files[$id];
        }
        public function verifiedReceipt(array $d): string { rc($this->connected,'receipt download requires connected client'); rc(!$this->files[$d['id']]['trashed'] && hash('sha256',$this->files[$d['id']]['pdf'])===$d['sha256'],'verified archive content'); rc($this->files[$d['id']]['parents']===[$d['drive_parent']],'archived before email'); return $this->files[$d['id']]['pdf']; }
        public function trashReceiptFile(array $d): void { rc($this->files[$d['id']]['parents']===[$d['drive_parent']],'trash bound to archive'); $this->files[$d['id']]['trashed']=true; $this->trashed[]=$d['id']; }
    };
    $deliveryDrive=clone $drive;
    $sent=[]; $toc=new InvoiceToconline($pdo,$vault,$drive,static function($to,$message) use (&$sent) { $sent[]=[$to,$message]; return true; });
    $deliveryDrive->files=&$drive->files; $deliveryDrive->moved=&$drive->moved;
    $toc=new InvoiceToconline($pdo,$vault,$deliveryDrive,static function($to,$message) use (&$sent) { $sent[]=[$to,$message]; return true; });
    $vault->save('toconline-settings.enc',['nif'=>'500000000','sender'=>'test@welcomehostel.pt','enabled'=>true,'pilot_verified'=>true]);
    $flow=new InvoiceReceipts($pdo,$vault,$drive,$toc,static fn($bytes)=>['count'=>1,'pages'=>[]]);
    $flow->run(); rc(!$vault->has(InvoiceReceipts::FILE),'deploy never starts intake');
    $drive->grant=false; rr(fn()=>$flow->request(1,2026,[10]),'existing per-file grant is insufficient'); $drive->grant=true;
    rr(fn()=>$flow->request(1,2026,[]),'at least one month');
    rr(fn()=>$flow->request(1,2026,[13]),'invalid month');
    rr(fn()=>$flow->request(1,1999,[10]),'invalid year');
    $drive->add('unselected-september','%PDF-1.4 unselected', 'pdf',9);
    $drive->add('scan-original-one','%PDF-1.4 synthetic receipt one');
    $flow->request(1,2026,[10]); $flow->request(2,2026,[10]); rc($flow->status()['requested_by']===1,'repeat click keeps original request');
    rr(fn()=>$flow->request(1,2026,[9]),'another pending selection is not silently ignored');
    $flow->run(); $d=$flow->status()['documents']['scan-original-one'];
    rc(!isset($flow->status()['documents']['unselected-september']),'unselected months never scanned');
    rc($drive->files['scan-original-one']['parents']!==['scan-month-10'],'successful archive removes original from temp month');
    rc($d['stage']==='archived' && $d['toconline_state']==='toc_submitted','manual intake completes archive and submission');
    rc($drive->folderNames===['2026','Outubro_2026','pdf'],'exact lower-case monthly layout');
    rc(count($sent)===1 && $sent[0][0]==='500000000@my.toconline.pt','existing recipient and transport');
    $flow->retryDiscovery($flow->status()['active_process']); $flow->run(); $toc->reconcile(); $flow->run(); rc(count($sent)===1 && $drive->ocr===1,'repeated intake and cron are idempotent');
    preg_match('/receipt-([a-f0-9]{64})\.pdf/',$sent[0][1]['body'],$key);
    $body='Foram recusados os seguintes ficheiros por já constarem no seu arquivo: receipt-'.$key[1].'.pdf';
    $toc->receive('no_reply@toconline.pt','',$body,hash('sha256','unsigned'));
    $flow->run(); rc(!$drive->trashed,'unverified mail never trashes');
    $proof=['authentication'=>['verified'=>true,'code'=>'verified','recipient'=>'test@welcomehostel.pt','signed_at'=>gmdate('c')],'outcome'=>'toc_existing'];
    $toc->receive('no_reply@toconline.pt','',$body,hash('sha256','signed'),$proof); $flow->run();
    rc($drive->trashed===['scan-original-one'] && $flow->status()['documents']['scan-original-one']['stage']==='duplicate_trashed','signed exact duplicate only removes this scan');
    $flow->run(); rc(count($drive->trashed)===1,'no repeat trash');
    $drive->add('scan-original-two','%PDF-1.4 synthetic receipt two'); $drive->text='Unreadable OCR'; $flow->retryDiscovery($flow->status()['active_process']); $flow->run();
    rc($flow->status()['documents']['scan-original-two']['stage']==='review' && count($sent)===1,'ambiguous stays in source and unsent');
    rr(fn()=>$flow->review('scan-original-two','2026-10-02','FT2',false,1),'review requires explicit company and single document confirmation');
    $flow->review('scan-original-two','2026-09-30','FT2',true,1); $drive->crashMove=true; $flow->run();
    $s=$flow->status(); $s['documents']['scan-original-two']['next_attempt']=0; $vault->save(InvoiceReceipts::FILE,$s); $flow->run();
    rc(count($sent)===2 && $flow->status()['documents']['scan-original-two']['stage']==='archived','recover unknown move without duplicate upload');
    $drive->add('scan-original-three','%PDF-1.4 synthetic receipt three'); $drive->crashOcr=true; $flow->retryDiscovery($flow->status()['active_process']); $flow->run();
    $s=$flow->status(); $s['documents']['scan-original-three']['next_attempt']=0; $vault->save(InvoiceReceipts::FILE,$s); $flow->run();
    rc($flow->status()['documents']['scan-original-three']['stage']==='review' && $drive->ocr===3,'unknown OCR creation never repeats');
    $drive->crashOcr=false;
    $drive->add('wrong-period-file','%PDF-1.4 wrong month');
    $drive->text=str_replace('02/10/2026','30/09/2026',$text); $flow->retryDiscovery($flow->status()['active_process']); $flow->run();
    $wrong=$flow->status()['documents']['wrong-period-file'];
    rc($wrong['stage']==='archived' && $wrong['issued_on']==='2026-09-30' && in_array('Setembro_2026',$drive->folderNames,true),'source October automatically routes to September issue date');
    unset($drive->files['unselected-september']);
    $drive->add('wrong-year-file','%PDF-1.4 wrong year'); $drive->text=str_replace('02/10/2026','02/10/2025',$text);
    $flow->retryDiscovery($flow->status()['active_process']); $flow->run();
    rc($flow->status()['documents']['wrong-year-file']['stage']==='archived' && in_array('Outubro_2025',$drive->folderNames,true),'source year does not override invoice year');
    $jpg=base64_decode('/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/2wBDAQkJCQwLDBgNDRgyIRwhMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjL/wAARCAABAAEDASIAAhEBAxEB/8QAHwAAAQUBAQEBAQEAAAAAAAAAAAECAwQFBgcICQoL/8QAtRAAAgEDAwIEAwUFBAQAAAF9AQIDAAQRBRIhMUEGE1FhByJxFDKBkaEII0KxwRVS0fAkM2JyggkKFhcYGRolJicoKSo0NTY3ODk6Q0RFRkdISUpTVFVWV1hZWmNkZWZnaGlqc3R1dnd4eXqDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uHi4+Tl5ufo6erx8vP09fb3+Pn6/8QAHwEAAwEBAQEBAQEBAQAAAAAAAAECAwQFBgcICQoL/8QAtREAAgECBAQDBAcFBAQAAQJ3AAECAxEEBSExBhJBUQdhcRMiMoEIFEKRobHBCSMzUvAVYnLRChYkNOEl8RcYGRomJygpKjU2Nzg5OkNERUZHSElKU1RVVldYWVpjZGVmZ2hpanN0dXZ3eHl6goOEhYaHiImKkpOUlZaXmJmaoqOkpaanqKmqsrO0tba3uLm6wsPExcbHyMnK0tPU1dbX2Nna4uPk5ebn6Onq8vP09fb3+Pn6/9oADAMBAAIRAxEAPwD3+iiigD//2Q==');
    rc(InvoiceDriveClient::receiptBytesValid($jpg,'jpg') && !InvoiceDriveClient::receiptBytesValid('%PDF-1.4','jpg'),'JPEG content checked');
    rc(InvoiceDriveClient::receiptFormat(['name'=>'SCAN.JPEG','mimeType'=>'image/jpeg'])==='jpg','uppercase JPEG extension');
    rc(InvoiceDriveClient::receiptFormat(['name'=>'SCAN.pdf','mimeType'=>'image/jpeg'])===null,'extension/MIME mismatch rejected');
    $drive->text=$text; $drive->add('jpeg-original-kept',$jpg,'jpg');
    $fixture=$flow->status();$fixture['processes'][$fixture['active_process']]['months']=[9,10];$vault->save(InvoiceReceipts::FILE,$fixture);$flow->retryDiscovery($fixture['active_process']); $flow->run(); $flow->run();
    rc($flow->status()['documents']['jpeg-original-kept']['stage']==='archived' && in_array('taloes',$drive->folderNames,true),'mixed JPG routes to taloes');
    $jpegMail=end($sent)[1]['body']; rc(str_contains($jpegMail,'Content-Type: image/jpeg'),'JPG sent without PDF conversion');
    preg_match('/receipt-([a-f0-9]{64})\.jpg/',$jpegMail,$jpgKey);
    $accept='receipt-'.$jpgKey[1].'.jpg'; $proof['outcome']='toc_accepted';
    $toc->receive('no_reply@toconline.pt','',$accept,hash('sha256','accepted jpeg'),$proof);
    $drive->add('jpeg-new-duplicate',$jpg,'jpg'); $flow->retryDiscovery($flow->status()['active_process']); $flow->run();
    preg_match('/receipt-([a-f0-9]{64})\.jpg/',end($sent)[1]['body'],$newKey);
    rc($newKey[1]!==$jpgKey[1],'distinct new scan gets exact external correlation; accepted original ledger preserved');
    $duplicate='Foram recusados os seguintes ficheiros por já constarem no seu arquivo: receipt-'.$newKey[1].'.jpg';
    $parsed=InvoiceTocPipe::parse("From: no_reply@toconline.pt\r\nSubject: Duplicados\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n".$duplicate);
    rc($parsed['outcome']==='toc_existing','JPEG duplicate reply parsed');
    $ambiguous=InvoiceTocPipe::parse("From: no_reply@toconline.pt\r\nSubject: Duplicados\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n".$duplicate." extra.pdf");
    rc($ambiguous['outcome']==='toc_review','extra unknown file prevents automatic deletion');
    $proof['outcome']='toc_existing'; $toc->receive('no_reply@toconline.pt','',$parsed['text'],hash('sha256','jpeg duplicate'),$proof); $flow->run();
    rc($drive->files['jpeg-new-duplicate']['trashed'] && !$drive->files['jpeg-original-kept']['trashed'],'only new duplicate trashed, historical JPG preserved');
    $oldMd5=$flow->status()['documents']['scan-original-three']['md5Checksum'];
    $drive->add('scan-original-three','%PDF-1.4 replaced contents'); $flow->retryDiscovery($flow->status()['active_process']); $flow->run();
    rc($flow->status()['documents']['scan-original-three']['md5Checksum']===$oldMd5 && !isset($drive->moved['scan-original-three']),'replaced bytes never reuse stale OCR or get silently reclassified');
    // Each PDF page is a distinct invoice; partial failure cannot retire the master.
    $pages=['%PDF-1.4 split page one','%PDF-1.4 split page two','%PDF-1.4 split page three'];
    $splitter=static function(string $bytes) use($vault,$pages): array {
        if ($bytes!=='%PDF-1.4 three-page master') return ['count'=>1,'pages'=>[]];
        $result=['count'=>3,'pages'=>[]];
        foreach ($pages as $n=>$page) {
            $sha=hash('sha256',$page); InvoiceVault::atomicWrite($vault->path('receipt-page-'.$sha.'.pdf'),$page);
            $result['pages'][]=['number'=>$n+1,'size'=>strlen($page),'sha256'=>$sha,'md5Checksum'=>md5($page)];
        }
        return $result;
    };
    $flow=new InvoiceReceipts($pdo,$vault,$drive,$toc,$splitter);
    $drive->pageTexts=[$pages[0]=>$text,$pages[1]=>str_replace('02/10/2026','30/09/2025',$text),$pages[2]=>'Illegible'];
    $drive->add('multipage-master','%PDF-1.4 three-page master'); $before=count($sent);
    $flow->retryDiscovery($flow->status()['active_process']); $flow->run();
    $master=$flow->status()['documents']['multipage-master']; $children=$master['children'];
    rc($master['stage']==='split' && count($children)===3 && count($sent)===$before,'master split once and never submitted');
    $drive->crashUpload=true; $flow->run();
    rc(!$drive->files['multipage-master']['trashed'] && count($sent)===$before,'unknown upload preserves source and does not send');
    $s=$flow->status(); $s['documents'][$children[0]]['next_attempt']=0; $vault->save(InvoiceReceipts::FILE,$s);
    $flow->run(); $flow->run(); $flow->run();
    rc($drive->uploads===2 && count($sent)===$before+2,'resume uses same child ID; each readable page sends once');
    rc($flow->status()['documents'][$children[1]]['issued_on']==='2025-09-30' && in_array('Setembro_2025',$drive->folderNames,true),'pages independently routed across years');
    rc($flow->status()['documents'][$children[2]]['stage']==='review' && !$drive->files['multipage-master']['trashed'],'unreadable page preserves whole original');
    $previewState=$flow->status(); $previewSent=count($sent); $previewMoved=$drive->moved;
    $preview=$flow->preview($children[2]);
    rc($flow->preview($children[2],true)['bytes']===$drive->files['multipage-master']['pdf'],'original preview returns all source pages for context');
    rc($preview['bytes']===$pages[2] && $preview['type']==='application/pdf','pending preview returns only the selected PDF page');
    rc($flow->status()===$previewState && count($sent)===$previewSent && $drive->moved===$previewMoved,'preview cannot change workflow, send or move documents');
    rr(fn()=>$flow->preview('unknown-document'),'unknown IDs cannot read arbitrary Drive files');
    $flow->retryDiscovery($flow->status()['active_process']); $flow->run(); rc($flow->status()['documents']['multipage-master']['children']===$children,'rediscovery cannot split or resend master again');
    preg_match('/receipt-([a-f0-9]{64})\.pdf/',$sent[$before][1]['body'],$pageKey);
    $proof['outcome']='toc_existing';
    $toc->receive('no_reply@toconline.pt','','Foram recusados os seguintes ficheiros por já constarem no seu arquivo: receipt-'.$pageKey[1].'.pdf',hash('sha256','page duplicate'),$proof);
    $flow->run(); rc($drive->files[$children[0]]['trashed'] && !$drive->files['multipage-master']['trashed'],'one confirmed duplicate removes only that page');
    // Missing private derivative is reconstructed from the unchanged master, with identical checksum.
    unlink($vault->path('receipt-page-'.hash('sha256',$pages[2]).'.pdf'));
    $flow->review($children[2],'2026-08-15','FT3',true,1); $flow->run(); $flow->run();
    rc($drive->uploads===3 && count($sent)===$before+3 && !$drive->files['multipage-master']['trashed'],'unconfirmed master is preserved');
    rc($flow->status()['documents']['multipage-master']['stage']==='split','master waits for all TOConline confirmations');
    $flow->run(); rc(count($sent)===$before+3,'finished split never resends');
    rc($flow->preview($children[2])['bytes']===$pages[2],'archived preview reads verified destination after cache removal');
    rr(fn()=>$flow->preview($children[0]),'removed duplicate preview unavailable');
    rc($flow->preview('multipage-master')['bytes']===$drive->files['multipage-master']['pdf'],'pending source preview retained');
    rc($flow->preview($children[2],true)['bytes']===$drive->files['multipage-master']['pdf'],'pending original remains available through child');
    $endpoint=file_get_contents(dirname(__DIR__).'/admin/receipt-document.php');
    rc(strpos($endpoint,'Auth::PERMISSION_RECEIPTS_VIEW')<strpos($endpoint,'->preview('),'endpoint checks history permission before retrieving bytes');
    rc(!str_contains($endpoint,'Auth::PERMISSION_RECEIPTS_RUN'),'run-only permission never grants document endpoint');
    $saved=$vault->read('toconline-ledger.enc'); unlink($vault->path('toconline-ledger.enc')); rr(fn()=>$toc->reconcile(),'missing transport ledger fails closed'); $vault->save('toconline-ledger.enc',$saved);
    unlink($vault->path(InvoiceReceipts::FILE)); rr(fn()=>$flow->status(),'missing receipt history fails closed');
    echo "Receipt workflow safeguards passed\n";
} finally { foreach (glob($dir.'/*')?:[] as $f) unlink($f); rmdir($dir); }
