<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$config = require $root . '/config.php';
require_once $root . '/src/Auth/AdminGuard.php';
require_once $root . '/src/Security/Csrf.php';
require_once $root . '/src/My2N/My2NClient.php';
require_once $root . '/src/My2N/My2NService.php';

$pdo = null;
$lockAcquired = false;
$beforeIds = null;
$requestedIds = null;
$actor = null;

try {
    $user = AdminGuard::requirePermission($config, 'my2n.control');
    $actor = (string) ($user['username'] ?? $user['display_name'] ?? 'utilizador');
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        header('Allow: POST');
        throw new RuntimeException('Método não permitido.', 405);
    }
    Csrf::validate($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
    if (($config['my2n']['allow_writes'] ?? false) !== true) {
        throw new RuntimeException(
            'As alterações My2N estão preparadas, mas continuam bloqueadas no servidor até ao teste autorizado.',
            409
        );
    }

    $raw = file_get_contents('php://input');
    $payload = json_decode($raw === false ? '' : $raw, true, 16, JSON_THROW_ON_ERROR);
    if (!is_array($payload)) {
        throw new InvalidArgumentException('Pedido inválido.');
    }
    $requestedIds = isset($payload['memberIds']) && is_array($payload['memberIds'])
        ? $payload['memberIds']
        : [];
    $expectedIds = isset($payload['expectedMemberIds']) && is_array($payload['expectedMemberIds'])
        ? $payload['expectedMemberIds']
        : [];
    foreach (array_merge($requestedIds, $expectedIds) as $memberId) {
        if ((!is_int($memberId) && !(is_string($memberId) && ctype_digit($memberId)))
            || (int) $memberId < 1) {
            throw new InvalidArgumentException('Member ID inválido.');
        }
    }

    $pdo = database();
    $lock = $pdo->query("SELECT GET_LOCK('room_check_my2n_members', 10)");
    $lockAcquired = (int) $lock->fetchColumn() === 1;
    if (!$lockAcquired) {
        throw new RuntimeException('Já existe outra alteração My2N em curso. Tente novamente.', 409);
    }

    $service = new My2NService(new My2NClient($config['my2n']), $config['my2n']);
    $before = $service->status();
    $beforeIds = array_values(array_map('intval', $before['currentMemberIds']));
    sort($beforeIds, SORT_NUMERIC);

    $expectedNormalized = array_values(array_unique(array_map('intval', $expectedIds)));
    sort($expectedNormalized, SORT_NUMERIC);
    if ($beforeIds !== $expectedNormalized) {
        throw new RuntimeException(
            'Os destinatários foram alterados entretanto. Atualize a lista antes de tentar novamente.',
            409
        );
    }

    $requestedNormalized = array_values(array_unique(array_map('intval', $requestedIds)));
    sort($requestedNormalized, SORT_NUMERIC);
    if ($requestedNormalized !== $beforeIds) {
        $snapshot = $pdo->prepare(
            'INSERT INTO my2n_member_snapshots (member_ids_json, source, created_by)
             VALUES (:members, :source, :actor)'
        );
        $snapshot->execute([
            'members' => json_encode($beforeIds, JSON_THROW_ON_ERROR),
            'source' => 'manual',
            'actor' => $actor,
        ]);
    }

    $result = $service->replaceMembers($requestedIds, $beforeIds);
    $confirmedIds = array_values(array_map('intval', $result['status']['currentMemberIds']));
    sort($confirmedIds, SORT_NUMERIC);

    $audit = $pdo->prepare(
        'INSERT INTO my2n_audit_log
            (action, actor, before_member_ids_json, requested_member_ids_json,
             confirmed_member_ids_json, dry_run, success)
         VALUES (:action, :actor, :before_ids, :requested_ids, :confirmed_ids, 0, 1)'
    );
    $audit->execute([
        'action' => $result['changed'] ? 'members_updated' : 'members_unchanged',
        'actor' => $actor,
        'before_ids' => json_encode($beforeIds, JSON_THROW_ON_ERROR),
        'requested_ids' => json_encode($result['requestedMemberIds'], JSON_THROW_ON_ERROR),
        'confirmed_ids' => json_encode($confirmedIds, JSON_THROW_ON_ERROR),
    ]);

    $response = ['ok' => true, 'data' => $result['status'], 'changed' => $result['changed']];
    $status = 200;
} catch (Throwable $exception) {
    if ($pdo instanceof PDO && $actor !== null && $requestedIds !== null) {
        try {
            $audit = $pdo->prepare(
                'INSERT INTO my2n_audit_log
                    (action, actor, before_member_ids_json, requested_member_ids_json,
                     dry_run, success, error_message)
                 VALUES (:action, :actor, :before_ids, :requested_ids, :dry_run, 0, :error)'
            );
            $audit->execute([
                'action' => 'members_update_failed',
                'actor' => $actor,
                'before_ids' => $beforeIds === null ? null : json_encode($beforeIds, JSON_THROW_ON_ERROR),
                'requested_ids' => json_encode($requestedIds, JSON_THROW_ON_ERROR),
                'dry_run' => ($config['my2n']['allow_writes'] ?? false) === true ? 0 : 1,
                'error' => mb_substr($exception->getMessage(), 0, 500),
            ]);
        } catch (Throwable) {
            // The original error remains authoritative when auditing is unavailable.
        }
    }
    $candidate = ($exception instanceof InvalidArgumentException || $exception instanceof JsonException)
        ? 422
        : (int) $exception->getCode();
    $status = in_array($candidate, [401, 403, 405, 409, 422, 502, 503], true) ? $candidate : 500;
    $response = ['ok' => false, 'error' => $exception->getMessage()];
    error_log(sprintf('My2N members update failed [%d]: %s', $status, $exception->getMessage()));
} finally {
    if ($lockAcquired && $pdo instanceof PDO) {
        try {
            $pdo->query("SELECT RELEASE_LOCK('room_check_my2n_members')");
        } catch (Throwable) {
        }
    }
}

http_response_code($status);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
