<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/src/Invoices/InvoiceWorkspace.php';
// The same cases run with the real MySQL migrations in CI.
$pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->exec("CREATE TABLE invoice_accounts(id INTEGER PRIMARY KEY AUTOINCREMENT,portal TEXT,label TEXT,enabled INTEGER DEFAULT 0,schedule_day INTEGER DEFAULT 5,schedule_time TEXT DEFAULT '04:00',period_basis TEXT DEFAULT 'issue_month',auth_method TEXT DEFAULT 'password',status TEXT,login_verified_at TEXT,sms_token_hash TEXT,created_at TEXT);
CREATE TABLE invoice_account_properties(account_id INTEGER,property_id TEXT,label TEXT,PRIMARY KEY(account_id,property_id));
CREATE TABLE invoice_tasks(id INTEGER PRIMARY KEY AUTOINCREMENT,account_id INTEGER,attempts INTEGER DEFAULT 0,next_attempt_at TEXT,kind TEXT,property_id TEXT,period TEXT,state TEXT DEFAULT 'queued',active_key TEXT UNIQUE,schedule_key TEXT UNIQUE,requested_by INTEGER,created_at TEXT,started_at TEXT,finished_at TEXT,result_code TEXT,imported_count INTEGER DEFAULT 0,duplicate_count INTEGER DEFAULT 0);
CREATE TABLE invoice_documents(id INTEGER PRIMARY KEY AUTOINCREMENT,account_id INTEGER,property_id TEXT,invoice_number TEXT,issued_on TEXT,period TEXT,period_basis TEXT,format TEXT,sha256 TEXT,size_bytes INTEGER,job_id INTEGER,pipeline_state TEXT,created_at TEXT);
CREATE TABLE invoice_document_delivery(document_id INTEGER PRIMARY KEY,company_state TEXT,company_name TEXT,toconline_state TEXT,drive_state TEXT,drive_id TEXT,attempts INTEGER,next_attempt_at TEXT,last_error TEXT,local_deleted_at TEXT);
CREATE TABLE invoice_account_settings(account_id INTEGER PRIMARY KEY,is_active INTEGER DEFAULT 1,archived_at TEXT);
CREATE TABLE invoice_property_settings(account_id INTEGER,property_id TEXT,is_active INTEGER DEFAULT 1,PRIMARY KEY(account_id,property_id));
CREATE TABLE invoice_batches(id INTEGER PRIMARY KEY AUTOINCREMENT,request_key TEXT UNIQUE,period TEXT,source TEXT,requested_by INTEGER,retry_of INTEGER,created_at TEXT);
CREATE TABLE invoice_batch_tasks(batch_id INTEGER,task_id INTEGER,PRIMARY KEY(batch_id,task_id));");
require __DIR__.'/invoice-workspace-cases.php';
// Render each real view in both languages with warnings promoted to failures.
require_once dirname(__DIR__).'/src/I18n/InvoiceText.php';
require_once dirname(__DIR__).'/src/Security/Csrf.php';
require_once dirname(__DIR__).'/admin/partials/invoices/helpers.php';
define('INVOICE_VIEW',true);
set_error_handler(static function(int $n,string $s,string $file,int $line): never {throw new RuntimeException($s.' at '.basename($file).':'.$line);});
$pdo->exec("CREATE TABLE invoice_settings(id INTEGER PRIMARY KEY,browser_ready INTEGER,browser_checked_at TEXT);INSERT INTO invoice_settings VALUES(1,0,NULL)");
$viewRoot=dirname(__DIR__).'/admin/partials/invoices';
$repository=new InvoiceAccounts($pdo);$accounts=$workspace->accounts('2029-02');$properties=[];$readiness=[];
foreach($accounts as $a){$properties[$a['id']]=$repository->properties((int)$a['id']);$readiness[$a['id']]='integration_setup';}
$filters=InvoiceWorkspace::filters(['period'=>'2029-02']);$period=$filters['period'];$stats=$workspace->stats($filters);
$documents=$workspace->documents($filters);$batches=$workspace->batches($filters);$legacyTasks=$workspace->tasks($filters,true);
$settings=['browser_ready'=>0,'browser_checked_at'=>null,'worker_seen_at'=>null];$driveSettings=[];$notifications=[];$alerts=[];$driveAlerts=[];$vault=null;$managers=[];
$newAccount=false;$showArchived=false;$editing=null;$editId=0;
foreach (['pt','en'] as $locale) {
 $_SESSION['locale']=$locale;
 foreach ([true,false] as $isGerente) {
  $canRun=$isGerente;
  foreach (['overview','documents','accounts','activity',...($isGerente?['settings']:[])] as $tab) {
   ob_start();require $viewRoot.'/'.$tab.'.php';$html=ob_get_clean();
   checkWorkspace(!str_contains($html,'<table'),'Cards render all states without wide tables');
   if(!$isGerente) checkWorkspace(!preg_match('/name="action" value="(?:credentials|collect|notifications|archive_account)"/',$html),'View-only pages expose no mutation forms');
  }
 }
 $isGerente=true;$canRun=true;$tab='accounts';
 foreach (['new','booking','airbnb'] as $scenario) {
  $newAccount=$scenario==='new';$editId=$newAccount?0:($scenario==='airbnb'?$air:$wa);
  $editing=null;foreach($accounts as $a)if((int)$a['id']===$editId)$editing=$a;
  ob_start();require $viewRoot.'/accounts.php';$html=ob_get_clean();
  checkWorkspace(!str_contains($html,'not-a-real-password')&&!str_contains($html,'changed-fixture'),'Rendered credentials never contain stored secrets');
  if($scenario==='booking')checkWorkspace(!str_contains($html,'name="hostel_number"'),'Booking form omits Hostelworld-only field');
 }
}
restore_error_handler();
echo "Workspace views render in PT/EN for manager and view-only roles.\n";
