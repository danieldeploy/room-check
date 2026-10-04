#!/usr/local/bin/php -q
<?php
declare(strict_types=1);
// Exim invokes this private executable via STDIN. Never output mail, errors or secrets.
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
ini_set('display_errors','0'); ini_set('log_errors','0'); error_reporting(0); umask(0077);
try {
    $appRoot=dirname(__DIR__,2).'/public_html/check';
    require_once $appRoot.'/src/Invoices/InvoiceTocPipe.php';
    $config=require $appRoot.'/config.php';
    $raw=stream_get_contents(STDIN,InvoiceTocPipe::MAX_BYTES+1);
    if (!is_string($raw)) exit(75);
    $pipe=new InvoiceTocPipe(new InvoiceVault((string)($config['invoices']['private_dir']??'')));
    $pipe->enqueue($raw);
    exit(0);
} catch (Throwable) {
    // EX_TEMPFAIL asks the mail transport to retry; the mailbox copy must be retained by its filter.
    exit(75);
}
