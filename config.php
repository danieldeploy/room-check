<?php
declare(strict_types=1);

$localConfigPath = __DIR__ . '/config.local.php';
$localConfig = file_exists($localConfigPath) ? require $localConfigPath : [];

if (!is_array($localConfig)) {
    throw new RuntimeException('config.local.php must return an array.');
}

return [
    'db' => [
        'host' => $localConfig['db']['host'] ?? getenv('ROOM_CHECK_DB_HOST') ?: 'localhost',
        'port' => (int) ($localConfig['db']['port'] ?? getenv('ROOM_CHECK_DB_PORT') ?: 3306),
        'name' => $localConfig['db']['name'] ?? getenv('ROOM_CHECK_DB_NAME') ?: '',
        'user' => $localConfig['db']['user'] ?? getenv('ROOM_CHECK_DB_USER') ?: '',
        'pass' => $localConfig['db']['pass'] ?? getenv('ROOM_CHECK_DB_PASS') ?: '',
        'charset' => 'utf8mb4',
    ],
];
