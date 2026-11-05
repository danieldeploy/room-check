<?php
declare(strict_types=1);
// Owner-authorized test after observing whm_storage_alert_v1 active, Utility,
// Portuguese (POR), three parameters in the existing Meta session on 2026-11-05.
// Fixed account/app, existing configured manager, no credential/config export.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
try {
    $appRoot = $argv[1] ?? '';
    if (realpath($appRoot) !== '/home/welcome/public_html/check') throw new RuntimeException('invalid_app');
    require $appRoot . '/lib.php';
    require_once $appRoot . '/src/Notifications/WhatsAppCloudClient.php';
    require_once $appRoot . '/src/Notifications/WhatsAppDiskHealthNotifier.php';
    $config = require $appRoot . '/config.php';
    $notifier = new WhatsAppDiskHealthNotifier(database(), $config['whatsapp'], '/home/welcome/room-check-private');
    $status = $notifier->activateApprovedTemplateOnce();
    echo json_encode(['whm_alert_activation' => $status]), PHP_EOL;
    exit($status === 'meta_accepted' ? 0 : 1);
} catch (Throwable) {
    echo '{"whm_alert_activation":"failed_inspect_private_receipt"}', PHP_EOL;
    exit(1);
}
