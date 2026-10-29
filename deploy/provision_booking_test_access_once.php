<?php
declare(strict_types=1);
// Owner explicitly authorized reusable test access on 29/09/2026. No password reset or browser bypass.
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
ini_set('display_errors','0'); umask(0077);
$pdo=null; $locked=false; $exitCode=0;
try {
    $home=rtrim((string)getenv('HOME'),'/');
    $root=$home==='' ? false : realpath($home.'/public_html/check');
    if ($root===false || count($argv)!==2 || realpath((string)$argv[1])!==$root) throw new RuntimeException('invalid_request');
    require $root.'/lib.php';
    require_once $root.'/src/Invoices/InvoiceRemoteAgent.php';
    require_once $root.'/src/Invoices/InvoiceTestControl.php';
    $config=require $root.'/config.php'; $pdo=database();
    if ((int)$pdo->query("SELECT GET_LOCK('room_check_invoices',0)")->fetchColumn()!==1) throw new RuntimeException('worker_busy');
    $locked=true;
    $vault=new InvoiceVault($config['invoices']['private_dir']);
    $control=new InvoiceTestControl($pdo,$vault,new InvoiceRemoteAgent($pdo,$config['invoices']));
    echo json_encode(['booking_test_access'=>$control->provisionOnce()],JSON_THROW_ON_ERROR)."\n";
} catch (Throwable) {
    fwrite(STDERR,"Booking test access was not provisioned.\n"); $exitCode=1;
} finally {
    if ($locked && $pdo) $pdo->query("SELECT RELEASE_LOCK('room_check_invoices')");
}

exit($exitCode);
