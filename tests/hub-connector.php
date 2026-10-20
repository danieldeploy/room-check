<?php
declare(strict_types=1);
require_once __DIR__.'/hub-connector-cases.php';
$pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$pdo->exec('PRAGMA foreign_keys=ON');
hubFixture($pdo); runHubCases($pdo);
echo "Hub connector PKCE, binding, scopes, expiry, rotation, replay, revocation, owner status and redaction passed.\n";
