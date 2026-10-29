<?php
declare(strict_types=1);
require_once __DIR__.'/../src/Invoices/CloudbedsYearAudit.php';
function yearCheck(bool $ok,string $label): void { if (!$ok) throw new RuntimeException($label); }
$root=sys_get_temp_dir().'/cloudbeds-year-'.bin2hex(random_bytes(8));mkdir($root,0700);
file_put_contents($root.'/master.key',random_bytes(32));chmod($root.'/master.key',0600);
try {
    $vault=new InvoiceVault($root);$pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $pdo->exec("CREATE TABLE invoice_accounts(id INTEGER PRIMARY KEY,portal TEXT);
        INSERT INTO invoice_accounts VALUES(4,'cloudbeds'),(5,'cloudbeds'),(6,'airbnb');
        CREATE TABLE invoice_account_settings(account_id INTEGER,is_active INTEGER,archived_at TEXT);
        CREATE TABLE invoice_account_properties(account_id INTEGER,property_id TEXT,label TEXT);
        CREATE TABLE invoice_property_settings(account_id INTEGER,property_id TEXT,is_active INTEGER);
        INSERT INTO invoice_account_properties VALUES(4,'welcome','Welcome'),(5,'city','City');
        CREATE TABLE invoice_tasks(id INTEGER PRIMARY KEY AUTOINCREMENT,account_id INTEGER,kind TEXT,property_id TEXT,period TEXT,
            active_key TEXT UNIQUE,schedule_key TEXT UNIQUE,requested_by INTEGER,created_at TEXT,state TEXT DEFAULT 'queued');
        CREATE TABLE invoice_batches(id INTEGER PRIMARY KEY AUTOINCREMENT,request_key TEXT UNIQUE,period TEXT,source TEXT,requested_by INTEGER,retry_of INTEGER,created_at TEXT);
        CREATE TABLE invoice_batch_tasks(batch_id INTEGER,task_id INTEGER,PRIMARY KEY(batch_id,task_id));
        CREATE TABLE invoice_documents(id INTEGER PRIMARY KEY,account_id INTEGER,property_id TEXT,invoice_number TEXT,sha256 TEXT,issued_on TEXT);
        CREATE TABLE invoice_document_delivery(document_id INTEGER,drive_state TEXT,company_state TEXT,toconline_state TEXT);
        INSERT INTO invoice_documents VALUES(1,4,'welcome','SYNTHETIC1','hash1','2026-01-03'),(9,4,'welcome','OLD','old','2025-12-01'),
            (10,6,'account','OTHER','other','2026-01-01');
        INSERT INTO invoice_document_delivery VALUES(1,'verified','validated','toc_accepted');");
    $settings=['account_ids'=>['welcome'=>4,'city'=>5]];$audit=new CloudbedsYearAudit($pdo,$vault);
    foreach (['welcome','city'] as $source) {
        $vault->save('cloudbeds-source-'.$source.'.enc',['at'=>time(),'retry'=>0,'processed'=>1,'review'=>0]);
        $vault->save('cloudbeds-inbox-'.$source.'-one.enc',['sha256'=>$source==='welcome'?'hash1':'hash2',
            'metadata'=>['number'=>$source==='welcome'?'SYNTHETIC1':'SYNTHETIC2','issued_on'=>'2026-01-03','period'=>'2026-01']]);
        $vault->save('cloudbeds-inbox-'.$source.'-old.enc',['sha256'=>'old','metadata'=>['number'=>'OLD','issued_on'=>'2025-12-01','period'=>'2025-12']]);
        $vault->save('cloudbeds-inbox-'.$source.'-future.enc',['sha256'=>'future','metadata'=>['number'=>'FUTURE','issued_on'=>'2027-01-01','period'=>'2027-01']]);
    }
    $vault->save('cloudbeds-source-city.enc',['at'=>time()-700,'retry'=>0,'processed'=>0]);
    $audit->run($settings);yearCheck(!$vault->has('cloudbeds-year-audit-2026.enc'),'Wait for both fresh source scans');
    $vault->save('cloudbeds-source-city.enc',['at'=>time(),'retry'=>0,'processed'=>1,'review'=>0]);
    $audit->run($settings);$audit->run($settings);
    yearCheck((int)$pdo->query('SELECT COUNT(*) FROM invoice_tasks')->fetchColumn()===2,'Repeated audit creates one task per source and issue month');
    yearCheck($pdo->query('SELECT DISTINCT period FROM invoice_tasks')->fetchAll(PDO::FETCH_COLUMN)===['2026-01'],'No older/future year tasks');
    $state=$vault->read('cloudbeds-year-audit-2026.enc');$state['batches']=[];$vault->save('cloudbeds-year-audit-2026.enc',$state);
    $audit->run($settings);yearCheck((int)$pdo->query('SELECT COUNT(*) FROM invoice_batches')->fetchColumn()===1,'Crash after batch commit reuses idempotency key');
    $r=$audit->report($settings);
    yearCheck(!$r['complete'] && $r['before']['months']['2026-01']['welcome']['accepted']===1
        && $r['before']['months']['2026-01']['city']['missing']===1,'Before report distinguishes archived and missing invoices');
    yearCheck($r['before']['months']['2026-02']['welcome']['found']===0,'Empty month is explicit, not fabricated');
    $pdo->exec("UPDATE invoice_tasks SET state='completed',active_key=NULL;
        INSERT INTO invoice_documents VALUES(2,5,'city','SYNTHETIC2','hash2','2026-01-03');
        INSERT INTO invoice_document_delivery VALUES(2,'verified','validated','toc_uncertain');");
    $audit->run($settings);$r=$audit->report($settings);
    yearCheck(!$r['complete'] && $r['current']['months']['2026-01']['city']['uncertain']===1,'Uncertain send is preserved and cannot pass acceptance');
    yearCheck($pdo->query('SELECT toconline_state FROM invoice_document_delivery WHERE document_id=2')->fetchColumn()==='toc_uncertain','Audit never resets or resends uncertainty');
    $pdo->exec("UPDATE invoice_document_delivery SET toconline_state='toc_duplicate' WHERE document_id=2");
    $vault->save('toconline-settings.enc',['nif'=>'500000000']);
    [$identity,$content]=InvoiceToconline::keys(['portal'=>'cloudbeds','invoice_number'=>'SYNTHETIC2','sha256'=>'hash2'],'500000000');
    $entry=['document_id'=>7,'state'=>'toc_submitted'];$vault->save('toconline-ledger.enc',[$identity=>$entry,$content=>$entry]);
    yearCheck(!$audit->report($settings)['complete'],'Duplicate of pending original is not confirmed');
    $entry['state']='toc_existing';$vault->save('toconline-ledger.enc',[$identity=>$entry,$content=>$entry]);
    $r=$audit->report($settings);
    yearCheck($r['complete'] && $r['current']['months']['2026-01']['city']['duplicate_confirmed']===1,'Confirmed archive and verified Drive finish recovery');
    yearCheck($r['before']['months']['2026-01']['city']['missing']===1,'Initial missing count survives completion');
    $vault->save('cloudbeds-inbox-city-later.enc',['sha256'=>'later','metadata'=>['number'=>'LATER','issued_on'=>'2026-02-03','period'=>'2026-02']]);
    $audit->run($settings);yearCheck($audit->report($settings)===$r,'Completed one-time audit is immutable; later mail follows normal schedule');
    yearCheck((int)$pdo->query('SELECT COUNT(*) FROM invoice_tasks')->fetchColumn()===2,'No recurring historical backfill');
    echo "Cloudbeds year audit tests passed.\n";
} finally { foreach (glob($root.'/*')?:[] as $file) unlink($file);rmdir($root); }
