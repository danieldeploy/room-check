<?php
declare(strict_types=1);
require_once __DIR__.'/../src/Invoices/CloudbedsInbox.php';
require_once __DIR__.'/../src/Invoices/CloudbedsFolderQueue.php';
require_once __DIR__.'/../src/Invoices/InvoiceToconline.php';
require_once __DIR__.'/../src/Invoices/InvoiceTocPipe.php';
function cbCheck(bool $ok,string $label): void { if (!$ok) throw new RuntimeException($label); }
function cbReject(callable $fn,string $label): void { try { $fn(); } catch (Throwable) { return; } throw new RuntimeException($label); }
function cbPdf(string $text): string {
    $stream="BT /F1 12 Tf 40 780 Td 16 TL\n";
    foreach (explode("\n",$text) as $line) $stream.='('.str_replace(['\\','(',')'],['\\\\','\\(','\\)'],$line).") Tj T*\n";
    $stream.="ET\n";
    $objects=['<< /Type /Catalog /Pages 2 0 R >>','<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
        '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>','<< /Length '.strlen($stream).">>\nstream\n".$stream.'endstream'];
    $pdf="%PDF-1.4\n"; $offsets=[];
    foreach ($objects as $i=>$object) { $offsets[]=strlen($pdf); $pdf.=($i+1)." 0 obj\n".$object."\nendobj\n"; }
    $xref=strlen($pdf); $pdf.="xref\n0 6\n0000000000 65535 f \n";
    foreach ($offsets as $offset) $pdf.=sprintf('%010d 00000 n ',$offset)."\n";
    return $pdf."trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF\n";
}
$text="Cloudbeds\nInvoice\nInvoice Number: A1234567\nInvoice Date: September 3, 2026\nActive Lines\nVAT Number: 500000000\nService period: August 2026\nDue Date: October 3, 2026";
$metadata=CloudbedsInvoice::parseText($text,'500000000');
cbCheck($metadata['issued_on']==='2026-09-03' && $metadata['period']==='2026-09','Issue date, not service/due month');
foreach ([str_replace('Active Lines','Other Company',$text),str_replace('500000000','500000019',$text),
    str_replace('Cloudbeds','Other Supplier',$text),str_replace('September 3, 2026','09/03/2026',$text),
    str_replace('September 3, 2026','February 31, 2026',$text),$text."\nInvoice Date: October 3, 2026",
    $text."\nInvoice Number: A7654321","Payment receipt\n".$text] as $bad) cbReject(fn()=>CloudbedsInvoice::parseText($bad,'500000000'),'Reject invalid invoice identity/date/receipt');
$pdf=cbPdf($text);
$body="--test-boundary\r\nContent-Type: text/plain\r\n\r\nYour invoice is attached.\r\n--test-boundary\r\nContent-Type: application/pdf\r\nContent-Transfer-Encoding: base64\r\nContent-Disposition: attachment; filename=\"private.pdf\"\r\n\r\n".chunk_split(base64_encode($pdf),76,"\r\n")."--test-boundary--\r\n";
$headers=['From: billing@cloudbeds.com','To: info@welcomehostel.pt','Subject: Invoice',
    'Date: '.gmdate('D, d M Y H:i:s O'),'Content-Type: multipart/mixed; boundary="test-boundary"'];
$raw=implode("\r\n",$headers)."\r\n\r\n".$body;
cbCheck(array_values(CloudbedsMailMessage::attachments($raw))===[$pdf],'Exact attachment bytes preserved');
cbCheck(CloudbedsMailMessage::attachments("From: billing@cloudbeds.com\r\nContent-Type: text/plain\r\n\r\nPayment received, invoice A1234567")===[],'Receipt without attachment is not an invoice');
cbReject(fn()=>CloudbedsMailMessage::attachments(str_replace('--test-boundary--','--missing--',$raw)),'Truncated MIME rejected');
cbReject(fn()=>CloudbedsMailMessage::attachments(str_replace('Content-Transfer-Encoding: base64','Content-Transfer-Encoding: binary',$raw)),'Unsupported attachment encoding rejected');
cbReject(fn()=>CloudbedsMailMessage::attachments(str_repeat('x',4194305)),'Bounded MIME');
cbReject(fn()=>CloudbedsMailMessage::attachments("From: other@example.test\r\n".$raw),'Duplicate headers rejected');
$private=openssl_pkey_new(['private_key_bits'=>2048,'private_key_type'=>OPENSSL_KEYTYPE_RSA]);
$public=openssl_pkey_get_details($private)['key'];
$txt='v=DKIM1; k=rsa; p='.preg_replace('/-----[^-]+-----|\s+/','',$public);
$resolver=static function(string $host)use($txt):array { cbCheck($host==='test._domainkey.cloudbeds.com','DKIM DNS scope'); return [['txt'=>$txt]]; };
$sign=static function(array $headers,string $body,string $extra='')use($private):string {
    $signature='DKIM-Signature: v=1; a=rsa-sha256; c=relaxed/relaxed; d=cloudbeds.com; s=test; h=from:to:subject:date:content-type; bh='.base64_encode(hash('sha256',$body,true)).'; '.$extra.'b=';
    $parts=[];
    foreach ($headers as $header) { [$k,$v]=explode(':',$header,2); $parts[]=strtolower($k).':'.trim($v); }
    $parts[]='dkim-signature:'.trim(substr($signature,15)); openssl_sign(implode("\r\n",$parts),$bytes,$private,OPENSSL_ALGO_SHA256);
    return $signature.base64_encode($bytes)."\r\n".implode("\r\n",$headers)."\r\n\r\n".$body;
};
$signed=$sign($headers,$body);
$packet=CloudbedsBridge::packet($signed,'welcome',$resolver);
cbCheck(CloudbedsBridge::validate($packet,'welcome')===[hash('sha256',$pdf)=>$pdf],'Authenticated PDF packet');
cbCheck(!str_contains(json_encode($packet),'info@') && !str_contains(json_encode($packet),'Subject'),'No mail headers in bridge');
cbReject(fn()=>CloudbedsBridge::packet($signed,'city',$resolver),'City cannot receive Welcome mail');
cbReject(fn()=>CloudbedsBridge::packet($raw,'welcome',$resolver),'Spoofed unsigned sender rejected');
cbReject(fn()=>CloudbedsBridge::packet($signed.'tampered','welcome',$resolver),'Tampered body rejected');
cbReject(fn()=>CloudbedsBridge::packet($sign($headers,$body,'l=10; '),'welcome',$resolver),'Partial signature rejected');
cbReject(fn()=>CloudbedsBridge::packet($sign($headers,$body,'x='.(time()-1).'; '),'welcome',$resolver),'Expired signature rejected');
cbReject(fn()=>CloudbedsBridge::packet(str_replace('info@welcomehostel.pt','info@citycenterhostel.pt',$signed),'city',$resolver),'Recipient tampering rejected');
$key=str_repeat('a',64); $stamp=(string)time(); $json=json_encode($packet); $sig=CloudbedsBridge::signature($json,$stamp,$key);
cbCheck(CloudbedsBridge::verify($json,$stamp,$sig,$key),'Bridge HMAC');
cbCheck(!CloudbedsBridge::verify($json.' ',$stamp,$sig,$key),'Bridge tampering rejected');
cbCheck(!CloudbedsBridge::verify($json,$stamp,$sig,$key,time()+301),'Stale replay rejected');
cbReject(fn()=>CloudbedsBridge::validate($packet,'city'),'Packet binding cannot switch house');
$dir=sys_get_temp_dir().'/cloudbeds-test-'.bin2hex(random_bytes(8)); mkdir($dir,0700);
try {
    file_put_contents($dir.'/master.key',random_bytes(32)); chmod($dir.'/master.key',0600); $vault=new InvoiceVault($dir);
    $vault->save('toconline-settings.enc',['nif'=>'500000000']);
    $vault->save('cloudbeds-settings.enc',['account_ids'=>['welcome'=>4,'city'=>5]]);
    $path=$dir.'/test.pdf'; file_put_contents($path,$pdf);
    cbCheck(CloudbedsInvoice::read($path,'500000000')===$metadata,'Real PDF text extraction');
    cbCheck(CloudbedsInvoice::parseText(InvoicePdfText::read($path,false),'500000000')===$metadata,'Bundled PHP PDF reader');
    $pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $pdo->exec("CREATE TABLE invoice_accounts(id INTEGER PRIMARY KEY,portal TEXT,period_basis TEXT); INSERT INTO invoice_accounts VALUES(4,'cloudbeds','issue_month'),(5,'cloudbeds','issue_month');
        CREATE TABLE invoice_account_settings(account_id INTEGER,is_active INTEGER,archived_at TEXT);
        CREATE TABLE invoice_account_properties(account_id INTEGER,property_id TEXT,label TEXT);
        INSERT INTO invoice_account_properties VALUES(4,'welcome','Welcome'),(5,'city','City');
        CREATE TABLE invoice_tasks(id INTEGER PRIMARY KEY,imported_count INTEGER DEFAULT 0,duplicate_count INTEGER DEFAULT 0);
        INSERT INTO invoice_tasks(id) VALUES(1),(2),(3);
        CREATE TABLE invoice_documents(id INTEGER PRIMARY KEY AUTOINCREMENT,account_id INTEGER,property_id TEXT,invoice_number TEXT,issued_on TEXT,period TEXT,period_basis TEXT,format TEXT,sha256 TEXT,size_bytes INTEGER,job_id INTEGER,pipeline_state TEXT,created_at TEXT);");
    $inbox=new CloudbedsInbox($pdo,$vault); $inbox->receive($packet,'welcome'); $inbox->receive($packet,'welcome');
    cbCheck(count(glob($dir.'/cloudbeds-inbox-*'))===1,'Duplicate deliveries have one encrypted inbox entry');
    cbCheck(!str_contains(file_get_contents(glob($dir.'/cloudbeds-inbox-*')[0]),base64_encode($pdf)),'Inbox encrypts PDFs');
    $inbox->index(); $inbox->heartbeat('welcome',['folder_found'=>true,'processed'=>1,'retry'=>0]);
    $job=['id'=>1,'account_id'=>4,'property_id'=>'welcome','kind'=>'collect','period'=>'2026-08'];
    cbCheck($inbox->collect($job)===['imported'=>0,'duplicates'=>0],'Selected issue month excludes service month');
    $job['period']='2026-09'; $job['id']=2;
    cbCheck($inbox->collect($job)===['imported'=>1,'duplicates'=>0],'Selected month imports invoice');
    $job['id']=3;
    cbCheck($inbox->collect($job)===['imported'=>0,'duplicates'=>1],'Repeat without stored source PDF counts a duplicate');
    cbCheck((int)$pdo->query('SELECT COUNT(*) FROM invoice_documents')->fetchColumn()===1,'No duplicate document');
    cbReject(fn()=> $inbox->collect(array_replace($job,['account_id'=>5])),'Account/property mismatch rejected');
    $d=['invoice_number'=>'A1234567','sha256'=>hash('sha256',$pdf),'portal'=>'cloudbeds','property_id'=>'welcome'];
    cbCheck(InvoiceToconline::keys($d,'500000000')===InvoiceToconline::keys(array_replace($d,['property_id'=>'city']),'500000000'),'Supplier invoice dedup spans both source mailboxes');
    cbReject(fn()=>InvoiceToconline::keys(array_replace($d,['portal'=>'airbnb']),'500000000'),'Airbnb remains excluded');
    $tocKey=InvoiceToconline::keys($d,'500000000')[0];
    cbCheck(str_contains(InvoiceToconline::message($pdf,$tocKey,'info@welcomehostel.pt','cloudbeds')['body'],'cloudbeds-'.$tocKey.'.pdf'),'Cloudbeds attachment correlation');
    $receipt="From: no_reply@toconline.pt\r\nTo: info@welcomehostel.pt\r\nSubject: Ficheiros arquivados com sucesso\r\nDate: ".gmdate('r')."\r\n\r\ncloudbeds_".$tocKey.'.pdf';
    cbCheck(InvoiceTocPipe::parse($receipt)['outcome']==='toc_accepted','Existing TOConline parser supports Cloudbeds identity');
    mkdir($dir.'/mail',0700); mkdir($dir.'/mail/.Cloudbeds',0700); mkdir($dir.'/mail/.Cloudbeds/cur',0700); mkdir($dir.'/mail/.Cloudbeds/new',0700);
    file_put_contents($dir.'/mail/.Cloudbeds/cur/original:2,S',$raw); $called=0;
    $consumer=static function($value)use(&$called,$raw):bool { cbCheck($value===$raw,'Original message content'); $called++; return true; };
    CloudbedsFolderQueue::run($dir.'/mail',$dir,$consumer);
    rename($dir.'/mail/.Cloudbeds/cur/original:2,S',$dir.'/mail/.Cloudbeds/cur/original:2,RS');
    CloudbedsFolderQueue::run($dir.'/mail',$dir,$consumer);
    cbCheck($called===1 && file_get_contents($dir.'/mail/.Cloudbeds/cur/original:2,RS')===$raw,'Mail retained, flags/name changes deduplicate by content');
} finally {
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) $f->isDir()?rmdir($f->getPathname()):unlink($f->getPathname()); rmdir($dir);
}
echo "Cloudbeds authenticated email, PDF, period and deduplication tests passed\n";
