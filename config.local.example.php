<?php
declare(strict_types=1);

return [
    'db' => [
        'host' => 'localhost',
        'port' => 3306,
        'name' => 'welcome_roomcheck',
        'user' => 'welcome_roomcheck',
        'pass' => 'COLOQUE_A_PASSWORD_AQUI',
    ],
    'auth' => [
        // Optional absolute path to the existing authentication bootstrap.
        'bootstrap' => '',
        // Remove this value after creating the first Gerente account.
        'setup_key' => 'CRIE_UMA_CHAVE_LONGA_E_ALEATORIA',
    ],
    'zkaccess' => [
        // O portal verifica apenas estes caminhos. O conteúdo e as credenciais
        // ficam fora de public_html e nunca devem ser adicionados ao Git.
        'private_config_file' => '/home/CPANEL_USER/room-check-private/zkaccess/config.json',
        'runner_status_file' => '/home/CPANEL_USER/room-check-private/zkaccess/status.json',
    ],
    'my2n' => [
        // This file contains only the path. Credentials stay outside public_html.
        'secrets_file' => '/home/CPANEL_USER/room-check-private/my2n-secrets.json',
        'company_id' => 0,
        'site_id' => 0,
    ],
    'whatsapp' => [
        'secrets_file' => '/home/CPANEL_USER/room-check-private/whatsapp-secrets.json',
        'graph_version' => 'v23.0',
        // Keep V1 active until Meta approves V2. Then change template_name to
        // space_management_reminder_v2 without changing the reminder code.
        'template_name' => 'space_management_reminder',
        'template_v2_name' => 'space_management_reminder_v2',
        'template_languages' => [
            'pt' => 'pt_PT',
            'en' => 'en',
        ],
        'default_country_code' => '351',
    ],
    'translation' => [
        // Google Cloud Translation Basic. The JSON file contains
        // {"api_key":"..."} and stays outside public_html and Git.
        'enabled' => true,
        'endpoint' => 'https://translation.googleapis.com/language/translate/v2',
        'secrets_file' => '/home/CPANEL_USER/room-check-private/google-translation.json',
        'engine_key' => 'google-basic-nmt-v2',
        'timeout_seconds' => 12,
    ],
];
