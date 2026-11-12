<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/src/Invoices/InvoiceReceiptReport.php';
require_once dirname(__DIR__).'/src/Invoices/InvoiceReceipts.php';
function reportCheck(bool $ok,string $message): void { if (!$ok) throw new RuntimeException($message); }
$documents=[
    'private-master-id'=>['name'=>'SECRET-MASTER.pdf','children'=>['private-page-id'],'stage'=>'split_completed','toconline_state'=>'receipt_per_page'],
    'private-page-id'=>['source_id'=>'private-master-id','name'=>'SECRET-PAGE.pdf','stage'=>'duplicate_trashed','archived_at'=>'now','toconline_state'=>'toc_existing','issuer_name'=>'Supplier <script>bad()</script>','invoice_number'=>'FT 42','issued_on'=>'2026-10-02','drive_parent'=>'PRIVATE-PARENT'],
    'private-normal-id'=>['name'=>'SECRET-NORMAL.jpg','stage'=>'archived','archived_at'=>'now','issued_on'=>'2026-10-01','invoice_number'=>'SECRET-NORMAL-NUMBER','issuer_name'=>'SECRET-NORMAL-COMPANY','toconline_state'=>'toc_accepted'],
    'private-error-id'=>['name'=>'SECRET-ERROR.jpg','stage'=>'review','sha256'=>'private-sha','format'=>'jpg','error'=>'receipt_review_required','toconline_state'=>'pending'],
    'private-old-duplicate'=>['name'=>'SECRET-OLD.pdf','stage'=>'duplicate_trashed','archived_at'=>'now','invoice_number'=>'FT 43','issued_on'=>'2026-09-01','toconline_state'=>'toc_existing'],
];
$summary=InvoiceReceiptReport::summary($documents);
reportCheck($summary['files']===4 && $summary['documents']===4 && $summary['processed']===3,'Split masters must not double-count invoices');
reportCheck(count($summary['duplicates'])===2 && count($summary['errors'])===1,'Duplicate/error totals');
$html=InvoiceReceiptReport::render($summary);
foreach (['SECRET-','private-','PRIVATE-PARENT','drive.google.com','type="date"','name="id"','name="action"','<script>bad()'] as $private) reportCheck(!str_contains($html,$private),'Operator report leaked private document data');
foreach (['FT 42','2026-10-02','FT 43','2026-09-01','Supplier &lt;script&gt;','Empresa não identificada',InvoiceText::get('receipt_review_required')] as $public) reportCheck(str_contains($html,$public),'Required duplicate/date/error detail missing');
reportCheck(InvoiceReceipts::issuerName("Fornecedor: Empresa ABC\nCliente: Active Lines\nFornecedor: Not the supplier") === 'Empresa ABC','Do not mistake customer-section content for supplier');
reportCheck(InvoiceReceipts::issuerName("Loja Exemplo, Lda.\nNIF: 123456789\nCliente: Active Lines") === 'Loja Exemplo, Lda.', 'Recognize unique legal supplier heading');
reportCheck(InvoiceReceipts::issuerName("Cliente\nActive Lines, Lda.") === '', 'Bare customer heading must never become supplier');
reportCheck(InvoiceReceipts::issuerName("Loja Exemplo, Lda.\nCliente\nActive Lines, Lda.") === 'Loja Exemplo, Lda.', 'Bare customer heading bounds the legal supplier header');
reportCheck(InvoiceReceipts::issuerName("Active Lines, Lda.\nNIF: 500000000") === '', 'Unlabelled legal company alone is not supplier evidence');
reportCheck(InvoiceReceipts::issuerName("Cliente: Active Lines\nNIF: 500000000") === '', 'Never infer supplier from customer');
reportCheck(InvoiceReceipts::issuerName("Fornecedor: ABC\nEmitente: DEF") === '', 'Ambiguous suppliers remain unidentified');

// Render the actual endpoint with real Auth, Csrf, vault and report code against a
// temporary SQLite fixture. The fixture adapter replaces only database/bootstrap.
$root=dirname(__DIR__); $tmp=sys_get_temp_dir().'/receipt-report-'.bin2hex(random_bytes(8));
mkdir($tmp,0700); mkdir($tmp.'/web',0700); mkdir($tmp.'/web/admin',0700); mkdir($tmp.'/vault',0700);
symlink($root.'/src',$tmp.'/web/src'); symlink($root.'/assets',$tmp.'/web/assets'); symlink($root.'/admin/assets',$tmp.'/web/admin/assets');
copy($root.'/admin/receipts.php',$tmp.'/web/admin/receipts.php');
copy($root.'/admin/receipt-history.php',$tmp.'/web/admin/receipt-history.php');
copy($root.'/admin/receipt-document.php',$tmp.'/web/admin/receipt-document.php');
file_put_contents($tmp.'/vault/master.key',random_bytes(32)); chmod($tmp.'/vault/master.key',0600);
$vault=new InvoiceVault($tmp.'/vault'); $vault->save(InvoiceReceipts::FILE,['documents'=>$documents,'requested'=>false,'year'=>2026,'months'=>[10]]);
file_put_contents($tmp.'/web/config.php','<?php return '.var_export(['invoices'=>['private_dir'=>$tmp.'/vault']],true).';');
$lib=<<<'PHP'
<?php
require_once __DIR__.'/src/Security/Csrf.php';
function jsonResponse(array $payload,int $status=200):never{http_response_code($status);echo json_encode(SiteTranslations::localizePayload($payload));exit;}
function database(): PDO {
    static $pdo;
    if ($pdo) return $pdo;
    $pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $pdo->exec("CREATE TABLE users(id INTEGER,username TEXT,display_name TEXT,role TEXT,is_active INTEGER,last_login_at TEXT,preferred_language TEXT);
        CREATE TABLE role_permissions(role TEXT,permission TEXT);
        INSERT INTO users VALUES(1,'fixture','Fixture User','".$GLOBALS['fixture_role']."',1,NULL,'pt');");
    foreach ($GLOBALS['fixture_permissions'] as $permission) $pdo->prepare('INSERT INTO role_permissions VALUES(?,?)')->execute([$GLOBALS['fixture_role'],$permission]);
    return $pdo;
}
PHP;
file_put_contents($tmp.'/web/lib.php',$lib);
$render=static function(string $role,array $permissions,string $action='',bool $preview=false,bool $status=false) use ($tmp): array {
    $code='$GLOBALS["fixture_role"]='.var_export($role,true).';$GLOBALS["fixture_permissions"]='.var_export($permissions,true).';'
        .'session_save_path('.var_export($tmp,true).');session_start();$_SESSION=["user_id"=>1,"last_activity"=>time(),"csrf_token"=>"fixture-csrf"];'
        .'$_SERVER["REQUEST_METHOD"]='.var_export($action===''?'GET':'POST',true).';$_POST=["action"=>'.var_export($action,true).',"id"=>"private-error-id","csrf_token"=>"fixture-csrf"];'
        .'$_GET["status"]='.var_export($status?'1':'',true).';'
        .'ob_start();register_shutdown_function(static function(){echo json_encode(["status"=>http_response_code(),"html"=>ob_get_clean()]);});'
        .'require '.var_export($tmp.'/web/admin/'.($preview?'receipt-document.php':'receipts.php'),true).';';
    $process=proc_open([PHP_BINARY,'-d','display_errors=stderr','-r',$code],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    fclose($pipes[0]); $out=stream_get_contents($pipes[1]); fclose($pipes[1]); $err=stream_get_contents($pipes[2]); fclose($pipes[2]);
    reportCheck(proc_close($process)===0 && $err==='', 'Actual receipt endpoint failed: '.$err);
    return json_decode($out,true,512,JSON_THROW_ON_ERROR);
};
try {
    $operator=$render('governanta',['receipts.run','receipts.view']);
    reportCheck($operator['status']!==403 && str_contains($operator['html'],'FT 42') && str_contains($operator['html'],'2026-10-02'),'Existing operator must see reduced report');
    foreach (['SECRET-','private-master-id','private-page-id','PRIVATE-PARENT','receipt-document.php?id=','name="issuer_name"','name="confirmed"','value="review"','value="retry"'] as $private) reportCheck(!str_contains($operator['html'],$private),'Actual operator HTML leaked individual history/corrections');
    $status=json_decode($render('governanta',['receipts.run'],'',false,true)['html'],true,512,JSON_THROW_ON_ERROR);
    reportCheck($status['history']===null && !str_contains(json_encode($status),'SECRET-') && !str_contains(json_encode($status),'private-page-id'),'Polling report never exposes documents');
    foreach (['review','retry','regroup'] as $action) reportCheck($render('governanta',['receipts.run'],$action)['status']===403,'Operator forged individual action was not rejected');
    reportCheck($render('governanta',[])['status']===403 && $render('governanta',['receipts.view'])['status']===403,'No/legacy-only access must fail closed');
    $manager=$render('gerente',[]);
    reportCheck(str_contains($manager['html'],'SECRET-NORMAL-NUMBER') && str_contains($manager['html'],'receipt-document.php?id=') && str_contains($manager['html'],'name="confirmed"'),'Manager detailed history/review regressed: '.json_encode([str_contains($manager['html'],'SECRET-NORMAL-NUMBER'),str_contains($manager['html'],'receipt-document.php?id='),str_contains($manager['html'],'name="confirmed"')]));
    reportCheck($render('governanta',['receipts.run'],'',true)['status']===403,'Actual document endpoint denies run-only operator');
    reportCheck($render('gerente',[],'',true)['status']===422,'Actual document endpoint rejects missing document ID');
    $history=$render('governanta',['receipts.history']);
    reportCheck(str_contains($history['html'],'SECRET-NORMAL-NUMBER') && !str_contains($history['html'],'name="confirmed"'),'Future history permission must not imply corrections');
    $review=$render('governanta',['receipts.review']);
    reportCheck(str_contains($review['html'],'name="confirmed"'),'Future explicit review permission not usable');
    echo "Receipt report: totals, duplicate company/number/date, safe errors, actual endpoint role isolation and denied forged POSTs passed.\n";
} finally {
    foreach (glob($tmp.'/sess_*')?:[] as $sessionFile) unlink($sessionFile);
    foreach ([$tmp.'/web/admin/assets',$tmp.'/web/src',$tmp.'/web/assets'] as $link) unlink($link);
    foreach (glob($tmp.'/vault/*')?:[] as $file) unlink($file);
    unlink($tmp.'/web/admin/receipt-history.php'); unlink($tmp.'/web/admin/receipt-document.php'); unlink($tmp.'/web/admin/receipts.php'); unlink($tmp.'/web/lib.php'); unlink($tmp.'/web/config.php');
    rmdir($tmp.'/web/admin'); rmdir($tmp.'/web'); rmdir($tmp.'/vault'); rmdir($tmp);
}
