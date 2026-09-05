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
$defaultWhatsAppSecretsFile = $serverHome === '' ? '' : $serverHome . '/room-check-private/whatsapp-secrets.json';
$defaultTranslationSecretsFile = $serverHome === '' ? '' : $serverHome . '/room-check-private/google-translation.json';

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
    'whatsapp' => [
        'timezone' => 'Europe/Lisbon',
        'secrets_file' => $localConfig['whatsapp']['secrets_file'] ?? getenv('WHATSAPP_SECRETS_FILE') ?: $defaultWhatsAppSecretsFile,
        'graph_version' => $localConfig['whatsapp']['graph_version'] ?? getenv('WHATSAPP_GRAPH_VERSION') ?: 'v23.0',
        'template_name' => $localConfig['whatsapp']['template_name'] ?? getenv('WHATSAPP_TEMPLATE_NAME') ?: 'space_management_reminder',
        'template_v2_name' => $localConfig['whatsapp']['template_v2_name'] ?? getenv('WHATSAPP_TEMPLATE_V2_NAME') ?: 'space_management_reminder_v2',
        'template_languages' => $localConfig['whatsapp']['template_languages'] ?? [
            'pt' => 'pt_PT',
            'en' => 'en',
        ],
        'default_country_code' => $localConfig['whatsapp']['default_country_code'] ?? '351',
    ],
    'translation' => [
        // User-authored bilingual content is translated server-side with Google
        // Cloud Translation Basic. The key must never be exposed to browser code.
        'enabled' => (bool) ($localConfig['translation']['enabled'] ?? true),
        'endpoint' => $localConfig['translation']['endpoint']
            ?? getenv('GOOGLE_CLOUD_TRANSLATION_ENDPOINT')
            ?: 'https://translation.googleapis.com/language/translate/v2',
        'api_key' => $localConfig['translation']['api_key']
            ?? getenv('GOOGLE_CLOUD_TRANSLATION_API_KEY')
            ?: '',
        'secrets_file' => $localConfig['translation']['secrets_file']
            ?? getenv('GOOGLE_CLOUD_TRANSLATION_SECRETS_FILE')
            ?: $defaultTranslationSecretsFile,
        'engine_key' => $localConfig['translation']['engine_key']
            ?? 'google-basic-nmt-v2',
        'timeout_seconds' => (int) ($localConfig['translation']['timeout_seconds'] ?? 12),
        // The local cap follows Google's own quota day, which resets at
        // midnight in America/Los_Angeles rather than at midnight in Portugal.
        'daily_character_limit' => (int) ($localConfig['translation']['daily_character_limit']
            ?? getenv('GOOGLE_CLOUD_TRANSLATION_DAILY_LIMIT')
            ?: 15500),
        'quota_timezone' => $localConfig['translation']['quota_timezone']
            ?? 'America/Los_Angeles',
        'display_timezone' => $localConfig['translation']['display_timezone']
            ?? 'Europe/Lisbon',
        // Quota-limited edits remain server-side drafts until both language
        // versions can be committed together by the worker.
        'pending_enabled' => (bool) ($localConfig['translation']['pending_enabled'] ?? true),
        'pending_worker_batch_size' => (int) ($localConfig['translation']['pending_worker_batch_size'] ?? 10),
        'pending_max_attempts' => (int) ($localConfig['translation']['pending_max_attempts'] ?? 5),
        'quota_alert' => [
            // Enable only after the Meta template is approved and the private
            // recipient mobile is configured on the server.
            'enabled' => (bool) ($localConfig['translation']['quota_alert']['enabled']
                ?? (getenv('TRANSLATION_QUOTA_ALERT_ENABLED') === '1')),
            'recipient_mobile' => $localConfig['translation']['quota_alert']['recipient_mobile']
                ?? getenv('TRANSLATION_QUOTA_ALERT_MOBILE')
                ?: '',
            'template_name' => $localConfig['translation']['quota_alert']['template_name']
                ?? 'translation_quota_alert_v1',
            'language' => $localConfig['translation']['quota_alert']['language']
                ?? 'pt_PT',
        ],
    ],
];
