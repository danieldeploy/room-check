<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}
ini_set('display_errors', '0');

try {
    $app = $argv[1] ?? '';
    if ($app !== '/home/welcome/public_html/check/') {
        throw new RuntimeException('configuration');
    }
    require_once $app . 'lib.php';
    $pdo = database();
    $pdo->exec(file_get_contents(__DIR__ . '/../migrations/031_cloudbeds_zkaccess.sql'));
    $pdo->query('SELECT 1 FROM zk_access_webhook_events LIMIT 0');
    echo "Cloudbeds ZKAccess queue ready; integration remains disabled until private configuration is enabled.\n";
} catch (Throwable) {
    fwrite(STDERR, "Cloudbeds ZKAccess queue preparation failed.\n");
    exit(1);
}
