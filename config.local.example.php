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
];
