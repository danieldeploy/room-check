<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/src/Invoices/InvoiceToconline.php';
require_once dirname(__DIR__).'/src/Invoices/InvoiceTocMailbox.php';
function tocCheck(bool $ok,string $label): void { if (!$ok) throw new RuntimeException($label); }
function tocReject(callable $fn,string $label): void {try {$fn();} catch (RuntimeException) {return;} throw new RuntimeException($label);}
$dir=sys_get_temp_dir().'/toc-'.bin2hex(random_bytes(8)); mkdir($dir,0700); file_put_contents($dir.'/master.key',random_bytes(32)); chmod($dir.'/master.key',0600);
try {
    $vault=new InvoiceVault($dir);
    $pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $pdo->exec("CREATE TABLE invoice_accounts(id INTEGER PRIMARY KEY,portal TEXT);
        INSERT INTO invoice_accounts VALUES(1,'booking'),(2,'booking'),(3,'airbnb');
        CREATE TABLE invoice_documents(id INTEGER PRIMARY KEY,account_id INTEGER,invoice_number TEXT,format TEXT,sha256 TEXT,size_bytes INTEGER);
        CREATE TABLE invoice_document_delivery(document_id INTEGER PRIMARY KEY,drive_state TEXT,company_state TEXT,drive_id TEXT,drive_parent TEXT,toconline_state TEXT);");
    $pdf='%PDF-1.4 synthetic test only';
    $add=static function(int $id,int $account,string $number,string $pdf,string $driveState='verified',string $company='validated') use ($pdo): void {
        $pdo->prepare('INSERT INTO invoice_documents VALUES(?,?,?,?,?,?)')->execute([$id,$account,$number,'pdf',hash('sha256',$pdf),strlen($pdf)]);
        $pdo->prepare('INSERT INTO invoice_document_delivery VALUES(?,?,?,?,?,?)')->execute([$id,$driveState,$company,'drive-test-id','parent-test-id','pending']);
    };
    $state=static function(int $id) use($pdo): string {return (string)$pdo->query('SELECT toconline_state FROM invoice_document_delivery WHERE document_id='.$id)->fetchColumn();};
    $drive=new class($pdf) {
        public bool $fail=false;
        public function __construct(public string $pdf) {}
        public function connect(): void {}
        public function verifiedPdf(array $d): string {if($this->fail)throw new RuntimeException('network');return $this->pdf;}
    };
    $sent=[];
    $transport=static function(string $to,array $message) use (&$sent): bool {$sent[]=[$to,$message];return true;};
    $toc=new InvoiceToconline($pdo,$vault,$drive,$transport);
    // Synthetic checksum-valid NIF; no external traffic occurs in tests.
    $settings=['toc_nif'=>'500000000','toc_sender'=>'test@welcomehostel.pt','toc_authorized'=>1];
    tocReject(fn()=>InvoiceToconline::destination('500000001'),'invalid NIF');
    tocReject(fn()=>$toc->configure($settings+['toc_enabled'=>1]),'pilot required');
    tocReject(fn()=>$toc->configure(array_replace($settings,['toc_sender'=>"a@welcomehostel.pt\r\nBcc: x@example.org"])), 'header injection');
    $toc->configure($settings);
    $mailbox=new InvoiceTocMailbox($vault,$toc);
    tocCheck(!$mailbox->status()['configured'] && !$mailbox->status()['enabled'],'mailbox opt-in');
    $mailbox->poll();
    tocReject(fn()=>$mailbox->configure(['toc_mail_password'=>'synthetic','toc_mail_folders'=>"INBOX}evil"]),'mailbox path injection blocked');
    tocReject(fn()=>$mailbox->configure(['toc_mail_password'=>'','toc_mail_folders'=>'INBOX']),'mailbox requires secure credential');
    $add(1,1,'INV-1',$pdf); $add(2,2,'INV-1',$pdf.' changed'); $add(3,2,'OTHER',$pdf);
    $add(4,1,'NOT-ARCHIVED',$pdf,'pending'); $add(5,1,'REVIEW',$pdf,'verified','review'); $add(6,3,'AIRBNB',$pdf);
    $toc->run(); tocCheck(count($sent)===0,'disabled sends nothing');
    $toc->run(1); tocCheck(count($sent)===1 && $state(1)==='toc_submitted','pilot submitted only');
    tocCheck($sent[0][0]==='500000000@my.toconline.pt','exact recipient');
    tocCheck(str_contains($sent[0][1]['body'],base64_encode($pdf)),'original PDF attached');
    $toc->run(1); tocCheck(count($sent)===1,'repeated pilot blocked');
    $toc->confirm(1); tocCheck($state(1)==='toc_accepted','manual receipt recorded');
    $toc->configure($settings+['toc_enabled'=>1]); $toc->run();
    tocCheck(count($sent)===1 && $state(2)==='toc_duplicate' && $state(3)==='toc_duplicate','identity and hash across accounts');
    tocCheck($state(4)==='pending' && $state(5)==='pending' && $state(6)==='pending','eligibility gates');
    $next='%PDF-1.4 second test'; $add(7,1,'INV-7',$next); $drive->pdf='tampered'; $toc->run();
    tocCheck(count($sent)===1 && $state(7)==='toc_retry','changed PDF never sent');
    $drive->pdf=$next;
    $uncertain=new InvoiceToconline($pdo,$vault,$drive,static function(): never {throw new RuntimeException('simulated interruption');});
    $uncertain->run(); tocCheck($state(7)==='toc_uncertain','unknown transport outcome');
    $toc->run(); $toc->run(7); tocCheck(count($sent)===1,'unknown never resent');
    // Simulate a crash after intent persistence but before a DB state update.
    $third='%PDF-1.4 third test'; $add(8,1,'INV-8',$third);
    $d=['portal'=>'booking','invoice_number'=>'INV-8','sha256'=>hash('sha256',$third)];
    [$a,$b]=InvoiceToconline::keys($d,'500000000'); $ledger=$vault->read('toconline-ledger.enc');
    $ledger[$a]=$ledger[$b]=['document_id'=>8,'nif'=>'500000000','sender'=>$settings['toc_sender'],'state'=>'toc_sending'];
    $vault->save('toconline-ledger.enc',$ledger); $toc->run();
    tocCheck($state(8)==='toc_uncertain' && count($sent)===1,'crash intent blocks duplicate');
    // A uniquely correlated email is evidence for review, not an authenticated acceptance.
    $fourth='%PDF-1.4 receipt test'; $add(9,1,'INV-9',$fourth); $drive->pdf=$fourth; $toc->run(9);
    [$key]=InvoiceToconline::keys(['portal'=>'booking','invoice_number'=>'INV-9','sha256'=>hash('sha256',$fourth)],'500000000');
    $body='Foram recusados os seguintes ficheiros por já constarem no seu arquivo: booking-'.$key.'.pdf';
    $hash=hash('sha256','synthetic receipt');
    tocCheck(str_contains(end($sent)[1]['body'],'booking-'.$key.'.pdf'),'unique correlation filename');
    tocCheck($toc->receive('attacker@example.org','',$body,$hash)['result']==='ignored','wrong sender ignored');
    tocCheck($toc->receive('no_reply@toconline.pt','','invoice.pdf',$hash)['result']==='unmatched','generic filename never guessed');
    tocCheck($toc->receive('no_reply@toconline.pt','',$body,$hash)['document_id']===9 && $state(9)==='toc_review','correlated receipt requires review');
    tocCheck($toc->receive('no_reply@toconline.pt','',str_replace('booking-','booking_',$body),hash('sha256','normalized receipt'))['document_id']===9 && $state(9)==='toc_review','normalized filename matches exact identity without accepting');
    $toc->confirm(9,'toc_existing',42); tocCheck($state(9)==='toc_existing','external duplicate distinct from accepted');
    $before=count($sent); $toc->run(9); tocCheck(count($sent)===$before,'existing never resent');
    $toc->receive('no_reply@toconline.pt','',$body,$hash); tocCheck($state(9)==='toc_existing','later receipt cannot undo final confirmation');
    tocReject(fn()=>$toc->confirm(9,'toc_accepted',42),'final outcome cannot silently change');
    // Receipt contains multiple document identities: never choose one arbitrarily.
    tocCheck($toc->receive('no_reply@toconline.pt','<toc-'.$a.'@check.welcomehostel.pt>',$body,$hash)['result']==='unmatched','ambiguous reference blocked');
    $fifth='%PDF-1.4 expiry'; $add(10,1,'INV-10',$fifth); $drive->pdf=$fifth; $toc->run(10);
    $toc->configure($settings); $toc->reconcile(time()+73*3600);
    tocCheck($state(10)==='toc_no_confirmation','expires after 72h with sending disabled');
    $before=count($sent); $toc->run(10); tocCheck(count($sent)===$before,'no confirmation never resent');
    $toc->confirm(10,'toc_rejected',42); tocCheck($state(10)==='toc_rejected','rejected distinct');
    $toc->run(10); tocCheck(count($sent)===$before,'rejected never blindly resent');
    // A duplicate receipt must not unlock first successful new-archive pilot.
    $saved=$toc->settings(); $saved['pilot_verified']=false; $saved['enabled']=false; $vault->save('toconline-settings.enc',$saved);
    $toc->confirm(7,'toc_existing',42); tocCheck(!$toc->settings()['pilot_verified'],'duplicate does not validate pilot');
    tocReject(fn()=>$toc->configure($settings+['toc_enabled'=>1]),'duplicate pilot cannot enable automatic sending');
    $toc->configure(array_replace($settings,['toc_sender'=>'other@welcomehostel.pt']));
    tocCheck(!$toc->settings()['pilot_verified'],'sender change invalidates pilot');
    tocReject(fn()=>$toc->confirm(1),'old sender receipt cannot validate new sender');
    unlink($vault->path('toconline-ledger.enc'));
    tocReject(fn()=>$toc->run(7),'missing ledger fails closed');
    echo "TOConline email safeguards passed\n";
} finally {
    foreach(glob($dir.'/*') as $file) unlink($file); rmdir($dir);
}

