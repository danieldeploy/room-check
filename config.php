<?php
declare(strict_types=1);

$localConfigPath = __DIR__ . '/config.local.php';
$localConfig = file_exists($localConfigPath) ? require $localConfigPath : [];

if (!is_array($localConfig)) {
    throw new RuntimeException('config.local.php must return an array.');
}

$serverHome = rtrim((string) (getenv('HOME') ?: ''), DIRECTORY_SEPARATOR);
if ($serverHome === '') {
    $candidateDirectory = realpath(__DIR__) ?: __DIR__;
    while ($candidateDirectory !== dirname($candidateDirectory)) {
        if (basename($candidateDirectory) === 'public_html') {
            $serverHome = dirname($candidateDirectory);
            break;
        }
        $candidateDirectory = dirname($candidateDirectory);
    }
}
$defaultMy2NSecretsFile = $serverHome === ''
    ? ''
    : $serverHome . '/room-check-private/my2n-secrets.json';

return [
    'db' => [
        'host' => $localConfig['db']['host'] ?? getenv('ROOM_CHECK_DB_HOST') ?: 'localhost',
        'port' => (int) ($localConfig['db']['port'] ?? getenv('ROOM_CHECK_DB_PORT') ?: 3306),
        'name' => $localConfig['db']['name'] ?? getenv('ROOM_CHECK_DB_NAME') ?: '',
        'user' => $localConfig['db']['user'] ?? getenv('ROOM_CHECK_DB_USER') ?: '',
        'pass' => $localConfig['db']['pass'] ?? getenv('ROOM_CHECK_DB_PASS') ?: '',
        'charset' => 'utf8mb4',
    ],
    'auth' => [
        'bootstrap' => $localConfig['auth']['bootstrap'] ?? getenv('ROOM_CHECK_AUTH_BOOTSTRAP') ?: '',
        'setup_key' => $localConfig['auth']['setup_key'] ?? getenv('ROOM_CHECK_SETUP_KEY') ?: '',
        'session_idle_seconds' => 28800,
    ],
    'zkaccess' => [
        'runner_version' => 'V5.1 Direct POST',
        'timezone' => 'Europe/Lisbon',
        'private_config_file' => (string) ($localConfig['zkaccess']['private_config_file'] ?? getenv('ZKACCESS_PRIVATE_CONFIG_FILE') ?: ''),
        'runner_status_file' => (string) ($localConfig['zkaccess']['runner_status_file'] ?? getenv('ZKACCESS_RUNNER_STATUS_FILE') ?: ''),
    ],
    'my2n' => [
        'company_id' => (int) ($localConfig['my2n']['company_id'] ?? getenv('MY2N_COMPANY_ID') ?: 70728),
        'site_id' => (int) ($localConfig['my2n']['site_id'] ?? getenv('MY2N_SITE_ID') ?: 408904),
        'timezone' => 'Europe/Lisbon',
        'secrets_file' => $localConfig['my2n']['secrets_file']
            ?? getenv('MY2N_SECRETS_FILE')
            ?: $defaultMy2NSecretsFile,
        'allow_writes' => getenv('MY2N_ALLOW_WRITES') === '1',
        'base_url' => 'https://my2n.com/middleware/api/partner/v1',
        'auth_url' => 'https://auth.my2n.com/self-service/login',
    ],
];
