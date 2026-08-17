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
    'my2n' => [
        // This file contains only the path. Credentials stay outside public_html.
        'secrets_file' => '/home/CPANEL_USER/room-check-private/my2n-secrets.json',
        'company_id' => 0,
        'site_id' => 0,
        'intercom_device_id' => 0,
        'contact_list_feature_id' => 0,
        'button_configuration_feature_id' => 0,
        'button_id' => 0,
        'contact_id' => 0,
        'ringing_group_item_id' => 0,
        'ringing_group_sip_number' => '',
    ],
];
