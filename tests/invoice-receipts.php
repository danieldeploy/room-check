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
    rc(Auth::roleHasPermission($pdo,'governanta',Auth::PERMISSION_RECEIPTS_VIEW) && !Auth::roleHasPermission($pdo,'governanta',Auth::PERMISSION_INVOICES_VIEW),'delegation stays narrow');
    $drive=new class($text) {
        public array $files=[]; public array $moved=[]; public array $trashed=[]; public array $folderNames=[];
        public int $ocr=0; public int $discovered=0; public bool $grant=true; public bool $crashMove=false; public bool $crashOcr=false;
        public function __construct(public string $text) {}
        public function add(string $id,string $pdf,string $format='pdf',int $month=10): void { $this->files[$id]=['id'=>$id,'name'=>'scan.'.$format,'mimeType'=>$format==='jpg'?'image/jpeg':'application/pdf','size'=>strlen($pdf),'md5Checksum'=>md5($pdf),'parents'=>['scan-month-'.$month],'pdf'=>$pdf,'trashed'=>false]; }
        public function receiptsAuthorized(): bool { return $this->grant; }
        public function connect(): void {}
        public function metadata(string $id): ?array { return $this->files[$id]??['id'=>$id,'trashed'=>false,'mimeType'=>'application/vnd.google-apps.folder','owners'=>[['emailAddress'=>'daniel.ciorcas@welcomehostel.pt']]]; }
        public function namedItems(string $parent,array $names,bool $folder=true): array { if ($names===['temp_scan']) return [['id'=>'temp-scan-folder']]; if ($parent==='temp-scan-folder') foreach (InvoiceDriveLayout::MONTHS as $month=>$name) if (in_array($name,$names,true)) return [['id'=>'scan-month-'.$month]]; return []; }
        public function scanFiles(string $root): array { $this->discovered++; return array_values(array_map(static function($f) { unset($f['pdf']); return $f; },array_filter($this->files,static fn($f)=>$f['parents']===[$root]))); }
        public function newId(): string { return 'folder-'.bin2hex(random_bytes(8)); }
        public function folder(string $id,string $parent,string $name): void { $this->folderNames[]=$name; }
        public function scanDocument(array $d): string { rc($this->files[$d['id']]['md5Checksum']===$d['md5Checksum'],'source hash'); return $this->files[$d['id']]['pdf']; }
        public function createReceiptOcr(string $pdf,string $id,string $parent,string $mime='application/pdf'): string { $this->ocr++; if ($this->crashOcr) throw new RuntimeException('drive_network'); return 'ocr-'.$id; }
        public function findReceiptOcr(string $source,string $parent): ?string { return null; }
        public function receiptOcrText(string $id): string { return $this->text; }
        public function trashReceiptOcr(string $id,string $source): void {}
        public function moveReceipt(array $d): void { $this->files[$d['id']]['parents']=[$d['drive_parent']]; $this->moved[$d['id']]=true; if ($this->crashMove) { $this->crashMove=false; throw new RuntimeException('drive_network'); } }
        public function verifiedReceipt(array $d): string { rc($this->files[$d['id']]['parents']===[$d['drive_parent']],'archived before email'); return $this->files[$d['id']]['pdf']; }
        public function trashReceiptDuplicate(array $d): void { rc($this->files[$d['id']]['parents']===[$d['drive_parent']],'trash bound to archive'); $this->files[$d['id']]['trashed']=true; $this->trashed[]=$d['id']; }
    };
    $sent=[]; $toc=new InvoiceToconline($pdo,$vault,$drive,static function($to,$message) use (&$sent) { $sent[]=[$to,$message]; return true; });
    $vault->save('toconline-settings.enc',['nif'=>'500000000','sender'=>'test@welcomehostel.pt','enabled'=>true,'pilot_verified'=>true]);
    $flow=new InvoiceReceipts($pdo,$vault,$drive,$toc);
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
    $flow->request(2,2026,[10]); $flow->run(); $toc->reconcile(); $flow->run(); rc(count($sent)===1 && $drive->ocr===1,'repeated intake and cron are idempotent');
    preg_match('/receipt-([a-f0-9]{64})\.pdf/',$sent[0][1]['body'],$key);
    $body='Foram recusados os seguintes ficheiros por já constarem no seu arquivo: receipt-'.$key[1].'.pdf';
    $toc->receive('no_reply@toconline.pt','',$body,hash('sha256','unsigned'));
    $flow->run(); rc(!$drive->trashed,'unverified mail never trashes');
    $proof=['authentication'=>['verified'=>true,'code'=>'verified','recipient'=>'test@welcomehostel.pt','signed_at'=>gmdate('c')],'outcome'=>'toc_existing'];
    $toc->receive('no_reply@toconline.pt','',$body,hash('sha256','signed'),$proof); $flow->run();
    rc($drive->trashed===['scan-original-one'] && $flow->status()['documents']['scan-original-one']['stage']==='duplicate_trashed','signed exact duplicate only removes this scan');
    $flow->run(); rc(count($drive->trashed)===1,'no repeat trash');
    $drive->add('scan-original-two','%PDF-1.4 synthetic receipt two'); $drive->text='Unreadable OCR'; $flow->request(1,2026,[10]); $flow->run();
    rc($flow->status()['documents']['scan-original-two']['stage']==='review' && count($sent)===1,'ambiguous stays in source and unsent');
    rr(fn()=>$flow->review('scan-original-two','2026-10-02','FT2',false,1),'review requires explicit company and single document confirmation');
    $flow->review('scan-original-two','2026-10-30','FT2',true,1); $drive->crashMove=true; $flow->run();
    $s=$flow->status(); $s['documents']['scan-original-two']['next_attempt']=0; $vault->save(InvoiceReceipts::FILE,$s); $flow->run();
    rc(count($sent)===2 && $flow->status()['documents']['scan-original-two']['stage']==='archived','recover unknown move without duplicate upload');
    $drive->add('scan-original-three','%PDF-1.4 synthetic receipt three'); $drive->crashOcr=true; $flow->request(1,2026,[10]); $flow->run();
    $s=$flow->status(); $s['documents']['scan-original-three']['next_attempt']=0; $vault->save(InvoiceReceipts::FILE,$s); $flow->run();
    rc($flow->status()['documents']['scan-original-three']['stage']==='review' && $drive->ocr===3,'unknown OCR creation never repeats');
    $drive->crashOcr=false;
    $drive->add('wrong-period-file','%PDF-1.4 wrong month');
    $drive->text=str_replace('02/10/2026','30/09/2026',$text); $flow->request(1,2026,[10]); $flow->run();
    $wrong=$flow->status()['documents']['wrong-period-file'];
    rc($wrong['stage']==='review' && $wrong['error']==='receipt_period_mismatch' && $drive->files['wrong-period-file']['parents']===['scan-month-10'],'mismatch retained, no archive or deletion');
    rr(fn()=>$flow->review('wrong-period-file','2026-09-30','FT9',true,1),'manager cannot send wrong month');
    unset($drive->files['unselected-september']);
    $drive->files['wrong-period-file']['parents']=['scan-month-9']; $flow->request(1,2026,[9]); $flow->run();
    rc($flow->status()['documents']['wrong-period-file']['stage']==='archived','correct folder selection recovers mismatch without new Drive ID');
    $drive->add('wrong-year-file','%PDF-1.4 wrong year'); $drive->text=str_replace('02/10/2026','02/10/2025',$text);
    $flow->request(1,2026,[10]); $flow->run(); rc($flow->status()['documents']['wrong-year-file']['error']==='receipt_period_mismatch','same month wrong year rejected');
    $flow->request(1,2025,[10]); $flow->run(); $flow->run(); rc($flow->status()['documents']['wrong-year-file']['stage']==='archived','selected year honored');
    $jpg=base64_decode('/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/2wBDAQkJCQwLDBgNDRgyIRwhMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjL/wAARCAABAAEDASIAAhEBAxEB/8QAHwAAAQUBAQEBAQEAAAAAAAAAAAECAwQFBgcICQoL/8QAtRAAAgEDAwIEAwUFBAQAAAF9AQIDAAQRBRIhMUEGE1FhByJxFDKBkaEII0KxwRVS0fAkM2JyggkKFhcYGRolJicoKSo0NTY3ODk6Q0RFRkdISUpTVFVWV1hZWmNkZWZnaGlqc3R1dnd4eXqDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uHi4+Tl5ufo6erx8vP09fb3+Pn6/8QAHwEAAwEBAQEBAQEBAQAAAAAAAAECAwQFBgcICQoL/8QAtREAAgECBAQDBAcFBAQAAQJ3AAECAxEEBSExBhJBUQdhcRMiMoEIFEKRobHBCSMzUvAVYnLRChYkNOEl8RcYGRomJygpKjU2Nzg5OkNERUZHSElKU1RVVldYWVpjZGVmZ2hpanN0dXZ3eHl6goOEhYaHiImKkpOUlZaXmJmaoqOkpaanqKmqsrO0tba3uLm6wsPExcbHyMnK0tPU1dbX2Nna4uPk5ebn6Onq8vP09fb3+Pn6/9oADAMBAAIRAxEAPwD3+iiigD//2Q==');
    rc(InvoiceDriveClient::receiptBytesValid($jpg,'jpg') && !InvoiceDriveClient::receiptBytesValid('%PDF-1.4','jpg'),'JPEG content checked');
    rc(InvoiceDriveClient::receiptFormat(['name'=>'SCAN.JPEG','mimeType'=>'image/jpeg'])==='jpg','uppercase JPEG extension');
    rc(InvoiceDriveClient::receiptFormat(['name'=>'SCAN.pdf','mimeType'=>'image/jpeg'])===null,'extension/MIME mismatch rejected');
    $drive->text=$text; $drive->add('jpeg-original-kept',$jpg,'jpg');
    $flow->request(1,2026,[9,10]); $flow->run(); $flow->run();
    rc($flow->status()['documents']['jpeg-original-kept']['stage']==='archived' && in_array('taloes',$drive->folderNames,true),'mixed JPG routes to taloes');
    $jpegMail=end($sent)[1]['body']; rc(str_contains($jpegMail,'Content-Type: image/jpeg'),'JPG sent without PDF conversion');
    preg_match('/receipt-([a-f0-9]{64})\.jpg/',$jpegMail,$jpgKey);
    $accept='receipt-'.$jpgKey[1].'.jpg'; $proof['outcome']='toc_accepted';
    $toc->receive('no_reply@toconline.pt','',$accept,hash('sha256','accepted jpeg'),$proof);
    $drive->add('jpeg-new-duplicate',$jpg,'jpg'); $flow->request(1,2026,[10]); $flow->run();
    preg_match('/receipt-([a-f0-9]{64})\.jpg/',end($sent)[1]['body'],$newKey);
    rc($newKey[1]!==$jpgKey[1],'distinct new scan gets exact external correlation; accepted original ledger preserved');
    $duplicate='Foram recusados os seguintes ficheiros por já constarem no seu arquivo: receipt-'.$newKey[1].'.jpg';
    $parsed=InvoiceTocPipe::parse("From: no_reply@toconline.pt\r\nSubject: Duplicados\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n".$duplicate);
    rc($parsed['outcome']==='toc_existing','JPEG duplicate reply parsed');
    $ambiguous=InvoiceTocPipe::parse("From: no_reply@toconline.pt\r\nSubject: Duplicados\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n".$duplicate." extra.pdf");
    rc($ambiguous['outcome']==='toc_review','extra unknown file prevents automatic deletion');
    $proof['outcome']='toc_existing'; $toc->receive('no_reply@toconline.pt','',$parsed['text'],hash('sha256','jpeg duplicate'),$proof); $flow->run();
    rc($drive->files['jpeg-new-duplicate']['trashed'] && !$drive->files['jpeg-original-kept']['trashed'],'only new duplicate trashed, historical JPG preserved');
    $saved=$vault->read('toconline-ledger.enc'); unlink($vault->path('toconline-ledger.enc')); rr(fn()=>$toc->reconcile(),'missing transport ledger fails closed'); $vault->save('toconline-ledger.enc',$saved);
    unlink($vault->path(InvoiceReceipts::FILE)); rr(fn()=>$flow->status(),'missing receipt history fails closed');
    echo "Receipt workflow safeguards passed\n";
} finally { foreach (glob($dir.'/*')?:[] as $f) unlink($f); rmdir($dir); }
