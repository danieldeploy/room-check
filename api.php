<?php
declare(strict_types=1);

require __DIR__ . '/lib.php';
$config = require __DIR__ . '/config.php';
require_once __DIR__ . '/src/Auth/Auth.php';

try {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $property = trim((string) ($_GET['property'] ?? ''));
    $room = (int) ($_GET['room'] ?? 0);

    if ($method === 'GET') {
        Auth::requirePermission(database(), $config, Auth::PERMISSION_ROOM_CHECK_VIEW);
        validateSelection($property, $room);

        $statement = database()->prepare(
            'SELECT item_name, problem, status
             FROM room_checklist_values
             WHERE property_name = :property AND room_number = :room'
        );
        $statement->execute(['property' => $property, 'room' => $room]);

        $saved = [];
        foreach ($statement->fetchAll() as $row) {
            $saved[$row['item_name']] = [
                'problem' => (string) $row['problem'],
                'status' => $row['status'],
            ];
        }

        $items = array_map(
            static fn(string $name): array => [
                'name' => $name,
                'problem' => $saved[$name]['problem'] ?? '',
                'status' => $saved[$name]['status'] ?? null,
            ],
            CHECKLIST_ITEMS
        );

        jsonResponse(['ok' => true, 'items' => $items]);
    }

    if ($method !== 'POST') {
        Auth::requireLogin(database(), $config);
        header('Allow: GET, POST');
        jsonResponse(['ok' => false, 'error' => 'Método não permitido.'], 405);
    }

    Auth::requirePermission(database(), $config, Auth::PERMISSION_ROOM_CHECK_EDIT);
    $rawBody = file_get_contents('php://input');
    $payload = json_decode($rawBody ?: '', true, 512, JSON_THROW_ON_ERROR);
    $property = trim((string) ($payload['property'] ?? ''));
    $room = (int) ($payload['room'] ?? 0);
    $items = $payload['items'] ?? null;

    validateSelection($property, $room);
    if (!is_array($items)) {
        throw new InvalidArgumentException('Dados do checklist inválidos.');
    }

    $allowedItems = array_flip(CHECKLIST_ITEMS);
    $normalized = [];

    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }

        $name = trim((string) ($item['name'] ?? ''));
        if (!isset($allowedItems[$name])) {
            continue;
        }

        $problem = trim((string) ($item['problem'] ?? ''));
        if (mb_strlen($problem) > 5000) {
            throw new InvalidArgumentException("O texto de {$name} é demasiado longo.");
        }

        $status = $item['status'] ?? null;
        if ($status === '') {
            $status = null;
        }
        if (!in_array($status, [null, 'wrong', 'ok'], true)) {
            throw new InvalidArgumentException("Estado inválido em {$name}.");
        }

        $normalized[$name] = ['problem' => $problem, 'status' => $status];
    }

    $pdo = database();
    $pdo->beginTransaction();

    $statement = $pdo->prepare(
        'INSERT INTO room_checklist_values
            (property_name, room_number, item_name, problem, status)
         VALUES
            (:property, :room, :item, :problem, :status)
         ON DUPLICATE KEY UPDATE
            problem = VALUES(problem),
            status = VALUES(status),
            updated_at = CURRENT_TIMESTAMP'
    );

    foreach (CHECKLIST_ITEMS as $name) {
        $value = $normalized[$name] ?? ['problem' => '', 'status' => null];
        $statement->execute([
            'property' => $property,
            'room' => $room,
            'item' => $name,
            'problem' => $value['problem'],
            'status' => $value['status'],
        ]);
    }

    $pdo->commit();
    jsonResponse(['ok' => true, 'savedAt' => gmdate('c')]);
} catch (JsonException | InvalidArgumentException $exception) {
    jsonResponse(['ok' => false, 'error' => $exception->getMessage()], 422);
} catch (Throwable $exception) {
    if ($exception instanceof RuntimeException && in_array($exception->getCode(), [401, 403, 429], true)) {
        jsonResponse(['ok' => false, 'error' => $exception->getMessage()], $exception->getCode());
    }
    error_log((string) $exception);
    jsonResponse(['ok' => false, 'error' => 'Não foi possível aceder à base de dados.'], 500);
}
