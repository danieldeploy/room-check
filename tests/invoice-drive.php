<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/src/Invoices/InvoiceService.php';
// The same behavioral cases also run on MySQL in invoices-mysql.php.
class DriveTestSqlite extends PDO {
 public function exec(string $statement): int|false {return parent::exec(str_replace('INSERT IGNORE','INSERT OR IGNORE',$statement));}
 public function prepare(string $query,array $options=[]): PDOStatement|false {return parent::prepare(str_replace('INSERT IGNORE','INSERT OR IGNORE',$query),$options);}
}
$pdo=new DriveTestSqlite('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->exec("CREATE TABLE invoice_accounts (id INTEGER PRIMARY KEY AUTOINCREMENT,portal TEXT,label TEXT,auth_method TEXT,period_basis TEXT,created_at TEXT);
CREATE TABLE invoice_account_properties (account_id INTEGER,property_id TEXT,label TEXT,PRIMARY KEY(account_id,property_id));
CREATE TABLE invoice_documents (id INTEGER PRIMARY KEY AUTOINCREMENT,account_id INTEGER,property_id TEXT,invoice_number TEXT,issued_on TEXT,period TEXT,period_basis TEXT,format TEXT,sha256 TEXT,size_bytes INTEGER,job_id INTEGER,pipeline_state TEXT,created_at TEXT);
CREATE TABLE invoice_document_delivery (document_id INTEGER PRIMARY KEY,company_state TEXT DEFAULT 'review',company_name TEXT,toconline_state TEXT DEFAULT 'pending',drive_state TEXT DEFAULT 'pending',drive_id TEXT,drive_parent TEXT,attempts INTEGER DEFAULT 0,next_attempt_at TEXT,last_attempt_at TEXT,last_error TEXT,verified_at TEXT,local_deleted_at TEXT);
CREATE TABLE invoice_drive_settings (id INTEGER PRIMARY KEY,email TEXT,folder_id TEXT,state TEXT,checked_at TEXT);
INSERT INTO invoice_drive_settings (id)VALUES(1);
CREATE TABLE invoice_drive_folders (path_key TEXT PRIMARY KEY,drive_id TEXT,parent_id TEXT,name TEXT);
CREATE TABLE invoice_drive_alerts (id INTEGER PRIMARY KEY AUTOINCREMENT,account_id INTEGER,period TEXT,error_code TEXT,created_at TEXT,UNIQUE(account_id,period,error_code));");
$service=new InvoiceService($pdo);
require __DIR__.'/invoice-drive-cases.php';
