<?php
declare(strict_types=1);

// Dedicated ZKAccess archive worker. Not part of invoice collection.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$appRoot = getenv('ROOM_CHECK_APP_ROOT') ?: rtrim((string) getenv('HOME'), '/') . '/public_html/check';
require $appRoot . '/lib.php';
require_once $appRoot . '/src/ZKAccess/BackupDrive.php';
$config = require $appRoot . '/config.php';
$pdo = database();

$locked = false;
$failed = false;
try {
    // The paired Windows upload uses this same advisory lock.
    // An overlapping cron invocation simply waits for the next five-minute tick.
    if ((int)$pdo->query("SELECT GET_LOCK('room_check_zk_backups', 0)")->fetchColumn() === 1) {
        $locked = true;
        $vault = new InvoiceVault((string)$config['invoices']['private_dir']);
        (new ZKAccessBackupDrive(
            new ZKAccessBackupStore($vault), $vault, new InvoiceDriveClient($vault)
        ))->run();
    }
} catch (Throwable) {
    // Never print tokens, OAuth responses, customer data or private paths.
    // Safe reason/timestamps for pending backups are kept in the encrypted vault.
    fwrite(STDERR, "ZKAccess backup archive failed; check private backup history.\n");
    $failed = true;
} finally {
    if ($locked) {
        $pdo->query("SELECT RELEASE_LOCK('room_check_zk_backups')");
    }
}
if ($failed) exit(1);
