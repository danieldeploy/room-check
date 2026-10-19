<?php
declare(strict_types=1);
require_once __DIR__.'/../src/Invoices/CloudbedsManualUpload.php';
require_once __DIR__.'/../src/Invoices/CloudbedsInbox.php';
function manualCheck(bool $ok,string $label): void { if (!$ok) throw new RuntimeException($label); }
function manualReject(callable $fn,string $code): void {
    try { $fn(); } catch (Throwable $e) { manualCheck($e->getMessage()===$code,'Expected safe rejection');return; }
    throw new RuntimeException('Expected rejection');
}
function manualPdf(string $text): string {
    $stream="BT /F1 12 Tf 40 780 Td 16 TL\n";
    foreach (explode("\n",$text) as $line) $stream.='('.str_replace(['\\','(',')'],['\\\\','\\(','\\)'],$line).") Tj T*\n";
    $stream.="ET\n";
    $objects=['<< /Type /Catalog /Pages 2 0 R >>','<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
        '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>','<< /Length '.strlen($stream).">>\nstream\n".$stream.'endstream'];
    $pdf="%PDF-1.4\n";$offsets=[];
    foreach ($objects as $i=>$object) { $offsets[]=strlen($pdf);$pdf.=($i+1)." 0 obj\n".$object."\nendobj\n"; }
    $xref=strlen($pdf);$pdf.="xref\n0 6\n0000000000 65535 f \n";
    foreach ($offsets as $offset) $pdf.=sprintf('%010d 00000 n ',$offset)."\n";
    return $pdf."trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF\n";
}
$root=sys_get_temp_dir().'/cloudbeds-upload-'.bin2hex(random_bytes(8));mkdir($root,0700);
file_put_contents($root.'/master.key',random_bytes(32));chmod($root.'/master.key',0600);
try {
    $vault=new InvoiceVault($root);$pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $pdo->exec("CREATE TABLE invoice_accounts(id INTEGER PRIMARY KEY,portal TEXT,period_basis TEXT);
        INSERT INTO invoice_accounts VALUES(4,'cloudbeds','issue_month'),(5,'cloudbeds','issue_month');
        CREATE TABLE invoice_account_settings(account_id INTEGER,is_active INTEGER,archived_at TEXT);
        CREATE TABLE invoice_account_properties(account_id INTEGER,property_id TEXT,label TEXT);
        CREATE TABLE invoice_property_settings(account_id INTEGER,property_id TEXT,is_active INTEGER);
        INSERT INTO invoice_account_properties VALUES(4,'welcome','Welcome'),(5,'city','City');
        CREATE TABLE invoice_tasks(id INTEGER PRIMARY KEY AUTOINCREMENT,account_id INTEGER,kind TEXT,property_id TEXT,period TEXT,
            active_key TEXT UNIQUE,schedule_key TEXT UNIQUE,requested_by INTEGER,created_at TEXT,state TEXT DEFAULT 'queued',
            imported_count INTEGER DEFAULT 0,duplicate_count INTEGER DEFAULT 0);
        CREATE TABLE invoice_batches(id INTEGER PRIMARY KEY AUTOINCREMENT,request_key TEXT UNIQUE,period TEXT,source TEXT,requested_by INTEGER,retry_of INTEGER,created_at TEXT);
        CREATE TABLE invoice_batch_tasks(batch_id INTEGER,task_id INTEGER,PRIMARY KEY(batch_id,task_id));
        CREATE TABLE invoice_documents(id INTEGER PRIMARY KEY AUTOINCREMENT,account_id INTEGER,property_id TEXT,invoice_number TEXT,issued_on TEXT,period TEXT,period_basis TEXT,format TEXT,sha256 TEXT,size_bytes INTEGER,job_id INTEGER,pipeline_state TEXT,created_at TEXT);");
    $vault->save('cloudbeds-settings.enc',['account_ids'=>['welcome'=>4,'city'=>5]]);
    $vault->save('toconline-settings.enc',['nif'=>'500000000']);
    $upload=new CloudbedsManualUpload($pdo,$vault);$user=['id'=>1,'role'=>'gerente'];$path=$root.'/sample.pdf';
    $text="Cloudbeds\nE-Invoice\nInvoice Number A1234567\nInvoice Date Jul 25, 2026\nTax ID: 500000000\nLegal Name\nActive Lines Lda\nWelcome Guest House\nService Period Jul 25, 2026 - Aug 24, 2026\nTotal amount paid on Aug 04, 2026";
    $pdf=manualPdf($text);file_put_contents($path,$pdf);
    manualReject(fn()=> $upload->store($path,'welcome',['id'=>2,'role'=>'gestor'],true),'forbidden');
    manualReject(fn()=> $upload->store($path,'welcome',$user,false),'https_required');
    manualReject(fn()=> $upload->store($path,'city',$user,true),'account_mismatch');
    manualReject(fn()=> $upload->installUpload(['error'=>UPLOAD_ERR_OK,'size'=>strlen($pdf),'tmp_name'=>$path],'welcome',$user,true),'invalid_document');
    foreach ([str_replace('500000000','500000019',$text),$text."\nCity Center Guest House"] as $bad) {
        file_put_contents($path,manualPdf($bad));manualReject(fn()=> $upload->store($path,'welcome',$user,true),'account_mismatch');
    }
    file_put_contents($path,manualPdf("Payment receipt\n".$text));manualReject(fn()=> $upload->store($path,'welcome',$user,true),'invalid_document');
    file_put_contents($path,$pdf);$result=$upload->store($path,'welcome',$user,true);
    manualCheck($result['period']==='2026-07' && $result['account_id']===4,'Issue month and source come from verified PDF and binding');
    manualCheck($upload->store($path,'welcome',$user,true)===$result,'Repeat upload reuses request');
    $item=$vault->read('cloudbeds-inbox-welcome-'.hash('sha256',$pdf).'.enc');
    manualCheck($item['authentication']==='manager_upload' && $item['submitted_by']===1,'Owner provenance never claims DKIM/SPF');
    manualCheck(!str_contains(file_get_contents($vault->path('cloudbeds-inbox-welcome-'.hash('sha256',$pdf).'.enc')),base64_encode($pdf)),'PDF encrypted at rest');
    $inbox=new CloudbedsInbox($pdo,$vault);$inbox->heartbeat('welcome',['folder_found'=>true,'processed'=>0,'retry'=>0,'review'=>0]);
    $job=$pdo->query('SELECT * FROM invoice_tasks')->fetch(PDO::FETCH_ASSOC);
    manualCheck($inbox->collect($job)===['imported'=>1,'duplicates'=>0],'Uploaded PDF uses normal import pipeline');
    manualCheck($inbox->collect($job)===['imported'=>0,'duplicates'=>1],'Collection repeat does not import again');
    $upload->store($path,'welcome',$user,true);
    manualCheck(!isset($vault->read('cloudbeds-inbox-welcome-'.hash('sha256',$pdf).'.enc')['pdf']),'Repeat upload preserves existing provenance and cleanup');
    manualCheck((int)$pdo->query('SELECT COUNT(*) FROM invoice_documents')->fetchColumn()===1,'One document for repeated uploads');
    echo "Cloudbeds manual upload tests passed.\n";
} finally { foreach (glob($root.'/*')?:[] as $file) unlink($file);rmdir($root); }
