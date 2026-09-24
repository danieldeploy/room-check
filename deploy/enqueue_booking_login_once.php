<?php
declare(strict_types=1);
// The reviewed release runs this once after publishing the application files.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ini_set('display_errors','0');
umask(0077);
$pdo=null; $locked=false; $exitCode=0;
try {
    $home=rtrim((string)getenv('HOME'),'/');
    $expected=$home==='' ? false : realpath($home.'/public_html/check');
    if ($expected===false || count($argv)!==2 || realpath((string)$argv[1])!==$expected) {
        throw new RuntimeException('invalid_request');
    }
    require $expected.'/lib.php';
    require_once $expected.'/src/Invoices/BookingLoginSmoke.php';
    $config=require $expected.'/config.php';
    $pdo=database();
    if ((int)$pdo->query("SELECT GET_LOCK('room_check_invoices',0)")->fetchColumn()!==1) {
        throw new RuntimeException('worker_busy');
    }
    $locked=true;
    $vault=new InvoiceVault((string)($config['invoices']['private_dir'] ?? ''));
    $agent=new InvoiceRemoteAgent($pdo,$config['invoices']);
    $result=BookingLoginSmoke::enqueue($pdo,$vault,$agent);
    echo json_encode(['booking_login_task'=>$result['id'],'state'=>$result['state'],
        'created'=>$result['created']],JSON_THROW_ON_ERROR)."\n";
} catch (Throwable $e) {
    $known=['invalid_request','worker_busy','account_mismatch','account_inactive','properties_required',
        'agent_test_required','auth_unconfigured','login_already_active'];
    $code=in_array($e->getMessage(),$known,true)?$e->getMessage():'unavailable';
    fwrite(STDERR,"Booking login task not queued: $code\n");
    $exitCode=1;
} finally {
    if ($locked && $pdo) {
        try { $pdo->query("SELECT RELEASE_LOCK('room_check_invoices')"); } catch (Throwable) {}
    }
}
exit($exitCode);
