<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$config = require $root . '/config.php';
require_once $root . '/src/Auth/AdminGuard.php';
require_once $root . '/src/Security/Csrf.php';
require_once $root . '/src/My2N/My2NModeFactory.php';

try {
    $user = AdminGuard::requirePermission($config, 'my2n.schedule');
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') throw new RuntimeException('Método não permitido.', 405);
    Csrf::validate($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
    $payload = json_decode((string) file_get_contents('php://input'), true, 8, JSON_THROW_ON_ERROR);
    $modeKey = (string) ($payload['modeKey'] ?? '');
    $result = My2NModeFactory::create(database(), $config)->activate(
        $modeKey,
        'manual',
        (string) ($user['username'] ?? $user['display_name'] ?? 'utilizador')
    );
    jsonResponse(['ok' => true, 'data' => $result]);
} catch (Throwable $exception) {
    $code = in_array($exception->getCode(), [400, 401, 403, 405, 409, 502], true) ? $exception->getCode() : 500;
    jsonResponse(['ok' => false, 'error' => $exception->getMessage()], $code);
}

