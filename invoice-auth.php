<?php
declare(strict_types=1);
// Device endpoint: no session authentication and no token or OTP in query strings.
header('Content-Type: application/json');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] === 'off') throw new RuntimeException('request', 400);
    if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 4096) throw new RuntimeException('request', 400);
    $authorization = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if (!preg_match('/\ABearer ([a-f0-9]{64})\z/', $authorization, $match)) throw new RuntimeException('auth', 403);
    $raw = file_get_contents('php://input', false, null, 0, 4097);
    if (!$raw || strlen($raw) > 4096) throw new RuntimeException('request', 400);
    $data = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
    if (!is_array($data) || !filter_var($data['account_id'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])) throw new RuntimeException('request', 400);
    require __DIR__ . '/lib.php';
    require_once __DIR__ . '/src/Invoices/InvoiceAuth.php';
    $config = require __DIR__ . '/config.php';
    $service = new InvoiceAuth(database(), new InvoiceVault($config['invoices']['private_dir']));
    $accepted = $service->receiveSms((int) $data['account_id'], $match[1], $data);
    http_response_code(202);
    echo json_encode(['accepted' => $accepted]);
} catch (Throwable $e) {
    http_response_code(in_array($e->getCode(), [400, 403], true) ? $e->getCode() : 400);
    echo '{"accepted":false}';
}
