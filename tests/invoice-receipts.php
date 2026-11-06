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
        public function add(string $id,string $pdf): void { $this->files[$id]=['id'=>$id,'name'=>'scan.pdf','mimeType'=>'application/pdf','size'=>strlen($pdf),'md5Checksum'=>md5($pdf),'parents'=>['temp-scan-folder'],'pdf'=>$pdf,'trashed'=>false]; }
        public function receiptsAuthorized(): bool { return $this->grant; }
        public function connect(): void {}
        public function metadata(string $id): ?array { return $this->files[$id]??['id'=>$id,'trashed'=>false,'mimeType'=>'application/vnd.google-apps.folder','owners'=>[['emailAddress'=>'daniel.ciorcas@welcomehostel.pt']]]; }
        public function namedItems(string $parent,array $names,bool $folder=true): array { return $names===['temp_scan']?[['id'=>'temp-scan-folder']]:[]; }
        public function scanFiles(string $root): array { $this->discovered++; return array_values(array_map(static function($f) { unset($f['pdf']); return $f; },array_filter($this->files,static fn($f)=>$f['parents']===['temp-scan-folder']))); }
        public function newId(): string { return 'folder-'.bin2hex(random_bytes(8)); }
        public function folder(string $id,string $parent,string $name): void { $this->folderNames[]=$name; }
        public function scanPdf(array $d): string { rc($this->files[$d['id']]['md5Checksum']===$d['md5Checksum'],'source hash'); return $this->files[$d['id']]['pdf']; }
        public function createReceiptOcr(string $pdf,string $id,string $parent): string { $this->ocr++; if ($this->crashOcr) throw new RuntimeException('drive_network'); return 'ocr-'.$id; }
        public function findReceiptOcr(string $source,string $parent): ?string { return null; }
        public function receiptOcrText(string $id): string { return $this->text; }
        public function trashReceiptOcr(string $id,string $source): void {}
        public function moveReceipt(array $d): void { $this->files[$d['id']]['parents']=[$d['drive_parent']]; $this->moved[$d['id']]=true; if ($this->crashMove) { $this->crashMove=false; throw new RuntimeException('drive_network'); } }
        public function verifiedPdf(array $d): string { rc($this->files[$d['drive_id']]['parents']===[$d['drive_parent']],'archived before email'); return $this->files[$d['drive_id']]['pdf']; }
        public function trashReceiptDuplicate(array $d): void { rc($this->files[$d['id']]['parents']===[$d['drive_parent']],'trash bound to archive'); $this->files[$d['id']]['trashed']=true; $this->trashed[]=$d['id']; }
    };
    $sent=[]; $toc=new InvoiceToconline($pdo,$vault,$drive,static function($to,$message) use (&$sent) { $sent[]=[$to,$message]; return true; });
    $vault->save('toconline-settings.enc',['nif'=>'500000000','sender'=>'test@welcomehostel.pt','enabled'=>true,'pilot_verified'=>true]);
    $flow=new InvoiceReceipts($pdo,$vault,$drive,$toc);
    $flow->run(); rc(!$vault->has(InvoiceReceipts::FILE),'deploy never starts intake');
    $drive->grant=false; rr(fn()=>$flow->request(1),'existing per-file grant is insufficient'); $drive->grant=true;
    $drive->add('scan-original-one','%PDF-1.4 synthetic receipt one');
    $flow->request(1); $flow->request(2); rc($flow->status()['requested_by']===1,'repeat click keeps original request');
    $flow->run(); $d=$flow->status()['documents']['scan-original-one'];
    rc($d['stage']==='archived' && $d['toconline_state']==='toc_submitted','manual intake completes archive and submission');
    rc($drive->folderNames===['2026','Outubro_2026','taloes'],'exact lower-case monthly layout');
    rc(count($sent)===1 && $sent[0][0]==='500000000@my.toconline.pt','existing recipient and transport');
    $flow->request(2); $flow->run(); $toc->reconcile(); $flow->run(); rc(count($sent)===1 && $drive->ocr===1,'repeated intake and cron are idempotent');
    preg_match('/receipt-([a-f0-9]{64})\.pdf/',$sent[0][1]['body'],$key);
    $body='Foram recusados os seguintes ficheiros por já constarem no seu arquivo: receipt-'.$key[1].'.pdf';
    $toc->receive('no_reply@toconline.pt','',$body,hash('sha256','unsigned'));
    $flow->run(); rc(!$drive->trashed,'unverified mail never trashes');
    $proof=['authentication'=>['verified'=>true,'code'=>'verified','recipient'=>'test@welcomehostel.pt','signed_at'=>gmdate('c')],'outcome'=>'toc_existing'];
    $toc->receive('no_reply@toconline.pt','',$body,hash('sha256','signed'),$proof); $flow->run();
    rc($drive->trashed===['scan-original-one'] && $flow->status()['documents']['scan-original-one']['stage']==='duplicate_trashed','signed exact duplicate only removes this scan');
    $flow->run(); rc(count($drive->trashed)===1,'no repeat trash');
    $drive->add('scan-original-two','%PDF-1.4 synthetic receipt two'); $drive->text='Unreadable OCR'; $flow->request(1); $flow->run();
    rc($flow->status()['documents']['scan-original-two']['stage']==='review' && count($sent)===1,'ambiguous stays in source and unsent');
    rr(fn()=>$flow->review('scan-original-two','2026-10-02','FT2',false,1),'review requires explicit company and single document confirmation');
    $flow->review('scan-original-two','2026-09-30','FT2',true,1); $drive->crashMove=true; $flow->run();
    $s=$flow->status(); $s['documents']['scan-original-two']['next_attempt']=0; $vault->save(InvoiceReceipts::FILE,$s); $flow->run();
    rc(count($sent)===2 && $flow->status()['documents']['scan-original-two']['stage']==='archived','recover unknown move without duplicate upload');
    $drive->add('scan-original-three','%PDF-1.4 synthetic receipt three'); $drive->crashOcr=true; $flow->request(1); $flow->run();
    $s=$flow->status(); $s['documents']['scan-original-three']['next_attempt']=0; $vault->save(InvoiceReceipts::FILE,$s); $flow->run();
    rc($flow->status()['documents']['scan-original-three']['stage']==='review' && $drive->ocr===3,'unknown OCR creation never repeats');
    $saved=$vault->read('toconline-ledger.enc'); unlink($vault->path('toconline-ledger.enc')); rr(fn()=>$toc->reconcile(),'missing transport ledger fails closed'); $vault->save('toconline-ledger.enc',$saved);
    unlink($vault->path(InvoiceReceipts::FILE)); rr(fn()=>$flow->status(),'missing receipt history fails closed');
    echo "Receipt workflow safeguards passed\n";
} finally { foreach (glob($dir.'/*')?:[] as $f) unlink($f); rmdir($dir); }
