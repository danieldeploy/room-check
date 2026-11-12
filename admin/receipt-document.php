<?php
declare(strict_types=1);
$root=dirname(__DIR__);
require $root.'/lib.php';
require_once $root.'/src/Auth/Auth.php';
require_once $root.'/src/Invoices/InvoiceReceipts.php';
require_once $root.'/src/Invoices/InvoiceReceiptPdf.php';
require_once $root.'/src/I18n/InvoiceText.php';
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: SAMEORIGIN');
$config=require $root.'/config.php';
try {
    $pdo=database(); $user=Auth::requireLogin($pdo,$config);
    if (!Auth::hasPermission($pdo,$user,Auth::PERMISSION_RECEIPTS_VIEW)) throw new RuntimeException('forbidden',403);
    if (($_SERVER['REQUEST_METHOD']??'GET')!=='GET') throw new RuntimeException('forbidden',405);
    $id=(string)($_GET['id']??'');
    InvoiceDriveClient::assertId($id);
    session_write_close();
    $vault=new InvoiceVault($config['invoices']['private_dir']);
    $drive=new InvoiceDriveClient($vault);
    $receipts=new InvoiceReceipts($pdo,$vault,$drive,new InvoiceToconline($pdo,$vault,$drive),[new InvoiceReceiptPdf($vault,$config['invoices']),'split']);
    $document=$receipts->preview($id,($_GET['original']??'')==='1');
    header('Content-Type: '.$document['type']);
    header('Content-Disposition: inline; filename="document.'.($document['type']==='application/pdf'?'pdf':'jpg').'"');
    header('Content-Length: '.strlen($document['bytes']));
    echo $document['bytes'];
} catch (Throwable $e) {
    http_response_code(in_array($e->getCode(),[401,403,405],true)?$e->getCode():422);
    header('Content-Type: text/plain; charset=utf-8');
    echo InvoiceText::get('receipt_preview_unavailable');
}
