<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$appRoot = getenv('ROOM_CHECK_APP_ROOT') ?: rtrim((string) getenv('HOME'), '/') . '/public_html/check';
require $appRoot . '/lib.php';
require $appRoot . '/src/Invoices/InvoiceRunner.php';
$config = require $appRoot . '/config.php';
$pdo = database();
if ((int) $pdo->query("SELECT GET_LOCK('room_check_invoices', 0)")->fetchColumn() !== 1) {
    exit;
}
try {
    (new InvoiceRunner($pdo, array_merge($config['invoices'] ?? [], ['whatsapp' => $config['whatsapp'] ?? []])))->run();
} catch (Throwable) {
    // Never log portal responses, cookies, credentials or raw exceptions.
    fwrite(STDERR, "Invoice worker failed. Check module status and private setup.\n");
    exit(1);
} finally {
    $pdo->query("SELECT RELEASE_LOCK('room_check_invoices')");
}
