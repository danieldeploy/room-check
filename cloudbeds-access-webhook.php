<?php
declare(strict_types=1);

// Cloudbeds must receive a 2XX quickly. This endpoint only authenticates,
// normalizes and queues the event; ZKAccess work is performed asynchronously.
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        throw new RuntimeException('method_not_allowed', 405);
    }
    if (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] === 'off') {
        throw new RuntimeException('https_required', 400);
    }
    if (!preg_match('~\Aapplication/json(?:\s*;|\z)~i', (string) ($_SERVER['CONTENT_TYPE'] ?? ''))) {
        throw new RuntimeException('invalid_request', 400);
    }
    if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 65536) {
        throw new RuntimeException('invalid_request', 400);
    }

    $key = $_GET['key'] ?? '';
    if (!is_string($key) || !preg_match('/\A[a-f0-9]{64}\z/D', $key)) {
        throw new RuntimeException('not_found', 404);
    }

    require __DIR__ . '/lib.php';
    require_once __DIR__ . '/src/ZKAccess/CloudbedsAccessConfig.php';
    require_once __DIR__ . '/src/ZKAccess/CloudbedsAccessWebhook.php';

    $config = require __DIR__ . '/config.php';
    $access = CloudbedsAccessConfig::load((string) ($config['zkaccess']['cloudbeds_access_config_file'] ?? ''));
    if (($access['enabled'] ?? false) !== true
        || !is_string($access['webhook_token'] ?? null)
        || !hash_equals($access['webhook_token'], $key)) {
        throw new RuntimeException('not_found', 404);
    }

    $raw = file_get_contents('php://input', false, null, 0, 65537);
    if (!is_string($raw) || strlen($raw) < 2 || strlen($raw) > 65536) {
        throw new RuntimeException('invalid_request', 400);
    }
    $payload = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($payload) || array_is_list($payload)) {
        throw new RuntimeException('invalid_request', 400);
    }

    CloudbedsAccessWebhook::ingest(database(), $payload, $access['properties']);
    http_response_code(202);
    echo '{"accepted":true}';
} catch (Throwable $e) {
    $safe = $e instanceof RuntimeException
        && in_array($e->getMessage(), ['method_not_allowed','https_required','invalid_request','not_found'], true);
    $status = $safe && in_array($e->getCode(), [400,404,405], true) ? $e->getCode() : 503;
    http_response_code($status);
    echo json_encode(['error' => $safe ? $e->getMessage() : 'temporarily_unavailable']);
}
