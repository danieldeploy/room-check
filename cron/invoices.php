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
$invoiceFailed = false;
try {
    (new InvoiceRunner($pdo, array_merge($config['invoices'] ?? [], ['whatsapp' => $config['whatsapp'] ?? []])))->run();
} catch (Throwable) {
    // Never log portal responses, cookies, credentials or raw exceptions.
    fwrite(STDERR, "Invoice worker failed. Check module status and private setup.\n");
    $invoiceFailed = true;
} finally {
    $pdo->query("SELECT RELEASE_LOCK('room_check_invoices')");
}

// Keep backup delivery independent from invoice scheduling, locks and failures.
$backupLocked = false;
try {
    if ((int)$pdo->query("SELECT GET_LOCK('room_check_zk_backups',0)")->fetchColumn() === 1) {
        $backupLocked = true;
        require_once $appRoot . '/src/ZKAccess/BackupDrive.php';
        $vault = new InvoiceVault((string)$config['invoices']['private_dir']);
        (new ZKAccessBackupDrive(new ZKAccessBackupStore($vault), $vault,
            new InvoiceDriveClient($vault)))->run();
    }
} catch (Throwable) {
    // The private manifest retains pending uploads for subsequent cron runs.
    fwrite(STDERR, "ZKAccess backup delivery pending. Check private backup status.\n");
} finally {
    if ($backupLocked) $pdo->query("SELECT RELEASE_LOCK('room_check_zk_backups')");
}
if ($invoiceFailed) exit(1);
