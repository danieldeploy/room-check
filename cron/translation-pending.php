<?php
declare(strict_types=1);

$appRoot = getenv('ROOM_CHECK_APP_ROOT') ?: rtrim((string) getenv('HOME'), '/') . '/public_html/check';
if (!is_file($appRoot . '/config.php')) {
    fwrite(STDERR, "Portal config not found.\n");
    exit(1);
}

require $appRoot . '/lib.php';
require $appRoot . '/src/I18n/PendingTranslationProcessor.php';
$config = require $appRoot . '/config.php';
$pdo = database();
$lock = (int) $pdo->query("SELECT GET_LOCK('room_check_pending_translations', 0)")->fetchColumn();
if ($lock !== 1) {
    exit(0);
}

try {
    $processor = new PendingTranslationProcessor($pdo, $config['translation'] ?? []);
    $summary = $processor->run();
    if ($summary['processed'] > 0) {
        fwrite(STDOUT, json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
    }
} catch (Throwable $exception) {
    fwrite(STDERR, "Pending translations: {$exception->getMessage()}\n");
    exit(1);
} finally {
    $pdo->query("SELECT RELEASE_LOCK('room_check_pending_translations')");
}
