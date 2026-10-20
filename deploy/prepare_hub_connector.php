<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') exit(1);
ini_set('display_errors','0');
try {
    $app=$argv[1]??'';
    if ($app!=='/home/welcome/public_html/check/') throw new RuntimeException('configuration');
    require_once $app.'lib.php';
    $pdo=database();
    // Additive storage only. Deployment never grants access or creates credentials.
    $pdo->exec(file_get_contents(__DIR__.'/../migrations/030_hub_connector.sql'));
    foreach (['hub_oauth_grants','hub_oauth_codes','hub_oauth_tokens'] as $table) $pdo->query('SELECT 1 FROM '.$table.' LIMIT 0');
    echo "Hub connector storage ready; manager consent required.\n";
} catch (Throwable) { fwrite(STDERR,"Hub connector storage preparation failed.\n"); exit(1); }
