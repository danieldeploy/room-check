<?php
declare(strict_types=1);

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
require_once rtrim($appRoot, '/') . '/lib.php';
require_once rtrim($appRoot, '/') . '/src/My2N/My2NModeFactory.php';
require_once rtrim($appRoot, '/') . '/src/My2N/My2NScheduleClock.php';
date_default_timezone_set('Europe/Lisbon');

try {
    if (($config['my2n']['allow_writes'] ?? false) !== true) {
        echo sprintf("[%s] My2N scheduler: alterações bloqueadas por MY2N_ALLOW_WRITES.\n", (new DateTimeImmutable())->format(DATE_ATOM));
        exit(0);
    }
    $due = My2NScheduleClock::dueMode(new DateTimeImmutable('now', new DateTimeZone('Europe/Lisbon')));
    $result = My2NModeFactory::create(database(), $config)->activate(
        $due['modeKey'], 'automatic', 'scheduler', $due['localDate']
    );
    echo sprintf("[%s] My2N scheduler: %s.\n", (new DateTimeImmutable())->format(DATE_ATOM),
        ($result['skipped'] ?? false) ? 'sem alteração (' . $result['reason'] . ')' : 'modo confirmado');
} catch (Throwable $exception) {
    fwrite(STDERR, sprintf("[%s] My2N scheduler failed: %s\n", (new DateTimeImmutable())->format(DATE_ATOM), $exception->getMessage()));
    exit(1);
}
