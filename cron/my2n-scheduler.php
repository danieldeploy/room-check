<?php
declare(strict_types=1);

// Safe skeleton only. It performs no My2N mutation until the scheduling phase
// is implemented, reviewed and MY2N_ALLOW_WRITES=1 is configured externally.
$appRoot = getenv('ROOM_CHECK_APP_ROOT') ?: '';
$home = getenv('HOME') ?: '';
if ($appRoot === '' && $home !== '') {
    $appRoot = rtrim($home, '/') . '/public_html/check';
}
$configPath = rtrim($appRoot, '/') . '/config.php';
if (!is_file($configPath)) {
    fwrite(STDERR, "Portal config not found.\n");
    exit(1);
}

$config = require $configPath;
date_default_timezone_set($config['my2n']['timezone'] ?? 'Europe/Lisbon');

echo sprintf(
    "[%s] My2N scheduler is installed in safe mode; no changes executed.\n",
    (new DateTimeImmutable())->format(DATE_ATOM)
);
