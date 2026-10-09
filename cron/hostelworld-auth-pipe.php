#!/usr/local/bin/php -q
<?php
declare(strict_types=1);
// cPanel Email Filter invokes this private CLI program through STDIN.
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
ini_set('display_errors','0'); ini_set('log_errors','0'); error_reporting(0); umask(0077);
try {
    $accountId=0;
    if (isset($argv[1])) {
        if (!preg_match('/\A[1-9][0-9]{0,17}\z/',(string)$argv[1])) exit(75);
        $accountId=(int)$argv[1];
    }
    $appRoot=dirname(__DIR__,2).'/public_html/check';
    require_once $appRoot.'/lib.php';
    require_once $appRoot.'/src/Invoices/InvoiceHostelworldPipe.php';
    $config=require $appRoot.'/config.php';
    $privateDir=(string)($config['invoices']['private_dir']??'');
    if ($privateDir==='' || !is_dir($privateDir) || is_link($privateDir)) exit(75);
    $raw=stream_get_contents(STDIN,InvoiceTocPipe::MAX_BYTES+1);
    if (!is_string($raw) || strlen($raw)>InvoiceTocPipe::MAX_BYTES) exit(75);
    $service=new InvoiceAuth(database(),new InvoiceVault($privateDir));
    $message=InvoiceHostelworldPipe::parse($raw,$service->hostelworldLinkTargets($accountId));
    if ($message===null) exit(0);
    try {
        $service->receiveHostelworldEmail($accountId,$message);
        exit(0);
    } catch (Throwable) {
        // Only retry after a valid filtered auth message reached infrastructure handling.
        exit(75);
    }
} catch (Throwable) {
    // Oversized, malformed, or unrelated input is discarded silently.
    exit(0);
}
