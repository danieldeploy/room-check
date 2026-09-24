<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/src/Invoices/BookingLoginSmoke.php';
require_once dirname(__DIR__).'/src/Invoices/InvoiceAlerts.php';

$pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->exec("CREATE TABLE invoice_accounts (id INTEGER PRIMARY KEY,portal TEXT);
    INSERT INTO invoice_accounts VALUES (1,'booking');
    CREATE TABLE invoice_account_settings (account_id INTEGER PRIMARY KEY,is_active INTEGER,archived_at TEXT);
    INSERT INTO invoice_account_settings VALUES (1,1,NULL);
    CREATE TABLE invoice_account_properties (account_id INTEGER,property_id TEXT,label TEXT);
    INSERT INTO invoice_account_properties VALUES (1,'1140306','Welcome'),(1,'539828','City');
    CREATE TABLE invoice_property_settings (account_id INTEGER,property_id TEXT,is_active INTEGER);
    CREATE TABLE invoice_tasks (id INTEGER PRIMARY KEY AUTOINCREMENT,account_id INTEGER,kind TEXT,
        property_id TEXT,period TEXT,state TEXT DEFAULT 'queued',active_key TEXT UNIQUE,
        schedule_key TEXT UNIQUE,requested_by INTEGER,created_at TEXT);
    CREATE TABLE invoice_failure_alerts (id INTEGER PRIMARY KEY AUTOINCREMENT,task_id INTEGER,
        dedupe_key TEXT UNIQUE,created_at TEXT);");
$tmp=sys_get_temp_dir().'/booking-smoke-'.bin2hex(random_bytes(8));
mkdir($tmp,0700);
$check=static function(bool $condition,string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$reject=static function(callable $run,string $message) use ($check): void {
    try { $run(); } catch (RuntimeException $e) { $check($e->getMessage()===$message,'Unexpected refusal'); return; }
    throw new RuntimeException('Expected refusal');
};
try {
    file_put_contents($tmp.'/master.key',random_bytes(32)); chmod($tmp.'/master.key',0600);
    $vault=new InvoiceVault($tmp);
    $vault->save('windows-agent.enc',['mode'=>'windows','token_hash'=>hash('sha256',random_bytes(32)),
        'probe_code'=>'ok']);
    $vault->save('account-1-credentials.enc',['identifier'=>bin2hex(random_bytes(12)),
        'password'=>bin2hex(random_bytes(24))]);
    $agent=new InvoiceRemoteAgent($pdo,['private_dir'=>$tmp]);

    // The previous release's false challenge must not suppress this corrected run.
    $pdo->exec("INSERT INTO invoice_tasks (account_id,kind,property_id,period,state,schedule_key)
        VALUES (1,'login','1140306','2026-08','needs_auth','booking-login-smoke:1:2026-09-24')");
    $previous=(int)$pdo->lastInsertId();

    $first=BookingLoginSmoke::enqueue($pdo,$vault,$agent);
    $check($first['created'] && $first['state']==='queued' && $first['id']!==$previous,
        'Corrected deployment queues a fresh login after the previous failed attempt');
    $check($first['id']===BookingLoginSmoke::enqueue($pdo,$vault,$agent)['id'],
        'Repeated deployment returns the same task');
    $check((int)$pdo->query('SELECT COUNT(*) FROM invoice_tasks')->fetchColumn()===2,
        'Repeated deployment does not insert another task');
    $pdo->exec("UPDATE invoice_tasks SET state='failed',active_key=NULL");
    $check($first['id']===BookingLoginSmoke::enqueue($pdo,$vault,$agent)['id'],
        'Completed or failed login retains its one-off idempotency key');

    $pdo->exec("UPDATE invoice_accounts SET portal='airbnb' WHERE id=1");
    $reject(fn()=>BookingLoginSmoke::enqueue($pdo,$vault,$agent),'account_mismatch');
    $pdo->exec("UPDATE invoice_accounts SET portal='booking' WHERE id=1; DELETE FROM invoice_tasks");
    $vault->save('windows-agent.enc',['mode'=>'paused','token_hash'=>hash('sha256',random_bytes(32)),
        'probe_code'=>'ok']);
    $reject(fn()=>BookingLoginSmoke::enqueue($pdo,$vault,$agent),'agent_test_required');
    $vault->save('windows-agent.enc',['mode'=>'windows','token_hash'=>hash('sha256',random_bytes(32)),
        'probe_code'=>'ok']);
    unlink($vault->path('account-1-credentials.enc'));
    $reject(fn()=>BookingLoginSmoke::enqueue($pdo,$vault,$agent),'auth_unconfigured');
    $check((int)$pdo->query('SELECT COUNT(*) FROM invoice_tasks')->fetchColumn()===0,
        'Failed preflight does not enqueue');
    $vault->save('account-1-credentials.enc',['identifier'=>bin2hex(random_bytes(12)),
        'password'=>bin2hex(random_bytes(24))]);

    $service=new InvoiceService($pdo);
    $manual=$service->enqueue('login','1140306',BookingLoginSmoke::PERIOD,null);
    foreach (['running','waiting_auth'] as $state) {
        $pdo->exec("UPDATE invoice_tasks SET state='$state'");
        $reject(fn()=>BookingLoginSmoke::enqueue($pdo,$vault,$agent),'login_already_active');
        $check($pdo->query('SELECT schedule_key FROM invoice_tasks')->fetchColumn()===null,
            'A claimed task cannot acquire a fresh-profile key after its lease was issued');
    }
    $pdo->exec("UPDATE invoice_tasks SET state='queued'");
    $adopted=BookingLoginSmoke::enqueue($pdo,$vault,$agent);
    $check(!$adopted['created'] && $adopted['id']===$manual,
        'Existing account login is adopted without a second attempt');
    $check($pdo->query('SELECT schedule_key FROM invoice_tasks')->fetchColumn()===BookingLoginSmoke::KEY,
        'Existing task receives the durable idempotency key');
    $pdo->exec("UPDATE invoice_tasks SET state='completed',active_key=NULL");
    $check(BookingLoginSmoke::enqueue($pdo,$vault,$agent)['id']===$manual,
        'Adopted task remains unique after completion');

    $pdo->exec('DELETE FROM invoice_tasks');
    $service->enqueue('login','1140306','2026-07',null);
    $reject(fn()=>BookingLoginSmoke::enqueue($pdo,$vault,$agent),'login_already_active');
    $check((int)$pdo->query('SELECT COUNT(*) FROM invoice_tasks')->fetchColumn()===1,
        'Different active login period does not create another attempt');
    $alerts=new InvoiceAlerts($pdo,[]);
    $captcha=['id'=>71,'kind'=>'login','account_id'=>1,'period'=>BookingLoginSmoke::PERIOD];
    $alerts->queue($captcha,'human_verification');
    $alerts->queue($captcha,'human_verification');
    $check((int)$pdo->query('SELECT COUNT(*) FROM invoice_failure_alerts')->fetchColumn()===1,
        'The same CAPTCHA task queues one WhatsApp alert');
    $later=$captcha; $later['id']=72;
    $alerts->queue($later,'human_verification');
    $check((int)$pdo->query('SELECT COUNT(*) FROM invoice_failure_alerts')->fetchColumn()===2,
        'A later CAPTCHA task in the same month can alert again');
    $alerts->queue($later,'auth_invalid');
    $check((int)$pdo->query('SELECT COUNT(*) FROM invoice_failure_alerts')->fetchColumn()===2,
        'Login failures other than human verification do not send WhatsApp alerts');
    echo "Booking one-off login queue checks passed.\n";
} finally {
    foreach (glob($tmp.'/*') ?: [] as $file) if (is_file($file) || is_link($file)) unlink($file);
    rmdir($tmp);
}
