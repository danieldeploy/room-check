<?php
declare(strict_types=1);

// Separate authenticated machine endpoint; no browser session, no cookies,
// no SQL, files or secrets in URL parameters or diagnostic responses.
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
$locked = false;
$pdo = null;
try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') throw new RuntimeException('invalid_request', 405);
    if (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] === 'off') throw new RuntimeException('https_required', 400);
    if (!empty($_SERVER['QUERY_STRING']) || !preg_match('~\Aapplication/json(?:\s*;|\z)~i', (string)($_SERVER['CONTENT_TYPE'] ?? ''))
        || (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 524288) throw new RuntimeException('invalid_request', 400);
    $authorization = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if (!preg_match('/\ABearer ([a-f0-9]{64})\z/D', $authorization, $match)) throw new RuntimeException('forbidden', 403);
    $raw = file_get_contents('php://input', false, null, 0, 524289);
    if (!is_string($raw) || strlen($raw) < 1 || strlen($raw) > 524288) throw new RuntimeException('invalid_request', 400);
    $request = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
    if (!is_array($request)) throw new RuntimeException('invalid_request', 400);
    require __DIR__ . '/lib.php';
    require_once __DIR__ . '/src/Invoices/InvoiceRemoteAgent.php';
    require_once __DIR__ . '/src/ZKAccess/BackupStore.php';
    $config = require __DIR__ . '/config.php';
    $pdo = database();
    $agent = new InvoiceRemoteAgent($pdo, array_merge($config['invoices'], ['whatsapp' => $config['whatsapp'] ?? []]));
    $agent->authenticate($match[1]);
    if (!function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt')) throw new RuntimeException('backup_unavailable');
    if ((int)$pdo->query("SELECT GET_LOCK('room_check_zk_backups', 0)")->fetchColumn() !== 1) {
        throw new RuntimeException('backup_busy', 409);
    }
    $locked = true;
    $store = new ZKAccessBackupStore(new InvoiceVault((string)$config['invoices']['private_dir']));
    $action = $request['action'] ?? '';
    $response = match ($action) {
        'config' => ['settings' => $store->settings(), 'server_time' => time()],
        'status' => ['backups' => $store->status()],
        'manual_ack' => ['accepted' => $store->acknowledgeNow((string)($request['id'] ?? ''))],
        'begin' => $store->begin($request),
        'chunk' => $store->chunk($request),
        'finish' => $store->finish($request),
        default => throw new RuntimeException('invalid_request', 400)
    };
    echo json_encode($response, JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    $status = in_array($e->getCode(), [400, 403, 405, 409, 413], true) ? $e->getCode() : 503;
    http_response_code($status);
    $allowed = ['invalid_request','https_required','forbidden','backup_busy','backup_conflict',
        'backup_incomplete','backup_not_receiving','invalid_backup','invalid_backup_chunk',
        'invalid_backup_id','backup_storage_limit'];
    echo json_encode(['error' => in_array($e->getMessage(), $allowed, true) ? $e->getMessage() : 'backup_unavailable']);
} finally {
    if ($locked && $pdo) $pdo->query("SELECT RELEASE_LOCK('room_check_zk_backups')");
}
