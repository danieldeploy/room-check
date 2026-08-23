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
        'template_name' => 'space_management_reminder',
        'template_languages' => [
            'pt' => 'pt_PT',
            'en' => 'en',
        ],
        'default_country_code' => '351',
    ],
    'translation' => [
        // Free MyMemory REST translation; no API key is required.
        'enabled' => true,
        'endpoint' => 'https://api.mymemory.translated.net/get',
        // Optional identification recommended by the provider.
        'contact_email' => '',
        'timeout_seconds' => 12,
    ],
];
