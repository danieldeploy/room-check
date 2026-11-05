<?php
declare(strict_types=1);

// Dedicated ZKAccess archive worker. Not part of invoice collection.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

// Reuse this existing tick before DB/Drive work. Own lock, bounded read-only
// pass once per Lisbon day; failures never interrupt backup delivery.
require_once __DIR__ . '/whm-log-daily.php';
WhmDailyLogAudit::runIfDue();

$appRoot = getenv('ROOM_CHECK_APP_ROOT') ?: rtrim((string) getenv('HOME'), '/') . '/public_html/check';
require $appRoot . '/lib.php';
require_once $appRoot . '/src/ZKAccess/BackupDrive.php';
$config = require $appRoot . '/config.php';
$pdo = database();

// WHM incident notifications use the verified tick, independent of reminders.
// Keep their lock and errors separate from the ZKAccess archive worker.
$alertLocked = false;
try {
    if ((int)$pdo->query("SELECT GET_LOCK('room_check_whm_disk_alert', 0)")->fetchColumn() === 1) {
        $alertLocked = true;
        require_once $appRoot . '/src/Notifications/WhatsAppCloudClient.php';
        require_once $appRoot . '/src/Notifications/WhatsAppDiskHealthNotifier.php';
        (new WhatsAppDiskHealthNotifier($pdo, $config['whatsapp'],
            '/home/welcome/room-check-private'))->runAndRecord();
    }
} catch (Throwable) {
    fwrite(STDERR, "WHM alert check failed; inspect private maintenance report.\n");
} finally {
    if ($alertLocked) $pdo->query("SELECT RELEASE_LOCK('room_check_whm_disk_alert')");
}

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
