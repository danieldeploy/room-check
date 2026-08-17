<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$config = require $root . '/config.php';
require_once $root . '/src/Auth/AdminGuard.php';
require_once $root . '/src/My2N/My2NClient.php';
require_once $root . '/src/My2N/My2NService.php';

try {
    AdminGuard::requireAdmin($config);
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        header('Allow: GET');
        throw new RuntimeException('Método não permitido.', 405);
    }

    $service = new My2NService(new My2NClient($config['my2n']), $config['my2n']);
    $result = ['ok' => true, 'data' => $service->status()];
    $status = 200;
} catch (Throwable $exception) {
    $candidate = (int) $exception->getCode();
    $status = in_array($candidate, [403, 405, 409, 422, 502, 503], true) ? $candidate : 500;
    $result = ['ok' => false, 'error' => $exception->getMessage()];
    error_log(sprintf('My2N read failed [%d]: %s', $status, $exception->getMessage()));
}

http_response_code($status);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
