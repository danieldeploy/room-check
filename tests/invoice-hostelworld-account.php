<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/src/Invoices/InvoiceAccounts.php';
require_once dirname(__DIR__).'/src/Invoices/InvoiceVault.php';
function hwAccountCheck(bool $ok,string $message):void { if(!$ok) throw new RuntimeException($message); }
$pdo=new PDO('sqlite::memory:'); $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->exec("CREATE TABLE invoice_accounts (id INTEGER PRIMARY KEY AUTOINCREMENT, portal TEXT, label TEXT, enabled INTEGER DEFAULT 0,
    period_basis TEXT, auth_method TEXT, status TEXT DEFAULT 'not_configured', login_verified_at TEXT, created_at TEXT, sms_token_hash TEXT)");
$pdo->exec("CREATE TABLE invoice_account_properties (account_id INTEGER, property_id TEXT, label TEXT)");
$dir=sys_get_temp_dir().'/hostelworld-account-'.bin2hex(random_bytes(8));mkdir($dir,0700);file_put_contents($dir.'/master.key',random_bytes(32));chmod($dir.'/master.key',0600);
try {
    $accounts=new InvoiceAccounts($pdo); $vault=new InvoiceVault($dir);
    $id=$accounts->create('hostelworld','Welcome Hostel',['welcome'=>'Welcome Guest House'],'email');
    $accounts->saveCredentials($vault,$id,['identifier'=>'manager','password'=>'portal-secret','hostel_number'=>'77759','auth_method'=>'email']);
    $credentials=$accounts->credentials($vault,$id);
    hwAccountCheck(($credentials['identifier']??'')==='manager','portal username retained');
    hwAccountCheck(($credentials['hostel_number']??'')==='77759','Hostelworld account number retained');
    hwAccountCheck(array_intersect(['imap_host','imap_user','imap_password','imap_mailbox'],array_keys($credentials))===[],'mailbox access credentials absent');
    hwAccountCheck((string)$pdo->query('SELECT auth_method FROM invoice_accounts WHERE id='.$id)->fetchColumn()==='email','email 2FA configured');
    // Switching an account previously configured with IMAP to filtered delivery deletes mailbox secrets.
    $old=$credentials+['imap_host'=>'imap.example.test','imap_user'=>'mail-user','imap_password'=>'mail-secret','imap_mailbox'=>'INBOX'];
    $vault->save(InvoiceAccounts::secretName($id,'credentials'),$old);
    $accounts->saveCredentials($vault,$id,['auth_method'=>'email']);
    $credentials=$accounts->credentials($vault,$id);
    hwAccountCheck(array_intersect(['imap_host','imap_user','imap_password','imap_mailbox'],array_keys($credentials))===[],'legacy mailbox secrets purged');
    echo "Hostelworld filtered email account tests passed\n";
} finally {
    foreach (glob($dir.'/*')?:[] as $file) unlink($file);rmdir($dir);
}
