<?php
declare(strict_types=1);

require __DIR__ . '/lib.php';
$config = require __DIR__ . '/config.php';
require_once __DIR__ . '/src/Auth/Auth.php';
require_once __DIR__ . '/src/Security/Csrf.php';

try {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $property = trim((string) ($_GET['property'] ?? ''));
    $room = (int) ($_GET['room'] ?? 0);

    if ($method === 'GET') {
        $pdo = database();
        $currentUser = Auth::requirePermission($pdo, $config, Auth::PERMISSION_ROOM_CHECK_VIEW);
        validateSelection($property, $room);

        $statement = $pdo->prepare(
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

        $assignments = [];
        if (Auth::hasPermission($pdo, $currentUser, Auth::PERMISSION_TASK_ASSIGN)) {
            $intervalId = (int) ($_GET['interval_id'] ?? 0);
            $assignmentStatement = $pdo->prepare(
                'SELECT item_name, assigned_to_user_id, due_date FROM room_item_assignments
                 WHERE interval_id = :interval_id AND property_name = :property
                   AND room_number = :room AND completed_at IS NULL'
            );
            $assignmentStatement->execute([
                'interval_id' => $intervalId,
                'property' => $property,
                'room' => $room,
            ]);
            foreach ($assignmentStatement->fetchAll() as $assignment) {
                $assignments[(string) $assignment['item_name']] = [
                    'employeeId' => (int) $assignment['assigned_to_user_id'],
                    'dueDate' => (string) $assignment['due_date'],
                ];
            }
        }

        jsonResponse(['ok' => true, 'items' => $items, 'assignments' => $assignments]);
    }

    if ($method !== 'POST') {
        Auth::requireLogin(database(), $config);
        header('Allow: GET, POST');
        jsonResponse(['ok' => false, 'error' => 'Método não permitido.'], 405);
    }

    $rawBody = file_get_contents('php://input');
    $payload = json_decode($rawBody ?: '', true, 512, JSON_THROW_ON_ERROR);
    $pdo = database();
    if (($payload['action'] ?? '') === 'create_interval') {
        $currentUser = Auth::requirePermission($pdo, $config, Auth::PERMISSION_TASK_ASSIGN);
        Csrf::validate(isset($payload['csrfToken']) ? (string) $payload['csrfToken'] : null);
        $name = trim((string) ($payload['name'] ?? ''));
        $startDate = trim((string) ($payload['startDate'] ?? ''));
        $endDate = trim((string) ($payload['endDate'] ?? ''));
        if ($name === '' || mb_strlen($name) > 120) {
            throw new InvalidArgumentException('Indique um nome para o intervalo (máximo 120 caracteres).');
        }
        foreach (['inicial' => $startDate, 'final' => $endDate] as $label => $date) {
            $parts = array_map('intval', explode('-', $date));
            if (!preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $date)
                || count($parts) !== 3 || !checkdate($parts[1], $parts[2], $parts[0])) {
                throw new InvalidArgumentException("A data {$label} do intervalo é inválida.");
            }
        }
        if ($endDate < $startDate) {
            throw new InvalidArgumentException('A data final não pode ser anterior à data inicial.');
        }
        $statement = $pdo->prepare(
            'INSERT INTO room_verification_intervals (name, start_date, end_date, created_by_user_id)
             VALUES (:name, :start_date, :end_date, :created_by)'
        );
        $statement->execute([
            'name' => $name, 'start_date' => $startDate, 'end_date' => $endDate,
            'created_by' => (int) $currentUser['id'],
        ]);
        $intervalId = (int) $pdo->lastInsertId();
        Auth::audit($pdo, (int) $currentUser['id'], 'room_verification_interval_created', [
            'interval_id' => $intervalId, 'name' => $name,
            'start_date' => $startDate, 'end_date' => $endDate,
        ]);
        jsonResponse(['ok' => true, 'interval' => [
            'id' => $intervalId, 'name' => $name, 'startDate' => $startDate, 'endDate' => $endDate,
        ]]);
    }

    if (($payload['action'] ?? '') === 'assign_items') {
        $currentUser = Auth::requirePermission($pdo, $config, Auth::PERMISSION_TASK_ASSIGN);
        Csrf::validate(isset($payload['csrfToken']) ? (string) $payload['csrfToken'] : null);
        $property = trim((string) ($payload['property'] ?? ''));
        $room = (int) ($payload['room'] ?? 0);
        $intervalId = (int) ($payload['intervalId'] ?? 0);
        $employeeId = (int) ($payload['employeeId'] ?? 0);
        $dueDate = trim((string) ($payload['dueDate'] ?? ''));
        $selectedItems = $payload['selectedItems'] ?? null;
        validateSelection($property, $room);
        if (!is_array($selectedItems)) {
            throw new InvalidArgumentException('Seleção de itens inválida.');
        }
        $dateParts = array_map('intval', explode('-', $dueDate));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueDate)
            || count($dateParts) !== 3
            || !checkdate($dateParts[1], $dateParts[2], $dateParts[0])) {
            throw new InvalidArgumentException('Escolha uma data válida para a verificação.');
        }
        $intervalCheck = $pdo->prepare(
            'SELECT start_date, end_date FROM room_verification_intervals WHERE id = :id'
        );
        $intervalCheck->execute(['id' => $intervalId]);
        $interval = $intervalCheck->fetch();
        if (!$interval) {
            throw new InvalidArgumentException('Escolha um intervalo de verificação válido.');
        }
        if ($dueDate < (string) $interval['start_date'] || $dueDate > (string) $interval['end_date']) {
            throw new InvalidArgumentException('A data da verificação tem de ficar dentro do intervalo escolhido.');
        }
        $employeeCheck = $pdo->prepare("SELECT id FROM users WHERE id = :id AND role = 'empregada_andares' AND is_active = 1");
        $employeeCheck->execute(['id' => $employeeId]);
        if (!$employeeCheck->fetchColumn()) {
            throw new InvalidArgumentException('Escolha uma Empregada de Andares ativa.');
        }
        $selected = array_fill_keys(array_values(array_intersect(CHECKLIST_ITEMS, array_map('strval', $selectedItems))), true);
        $pdo->beginTransaction();
        $upsert = $pdo->prepare(
            'INSERT INTO room_item_assignments
                (interval_id, property_name, room_number, item_name, assigned_to_user_id, assigned_by_user_id, due_date, completed_at, completed_by_user_id)
             VALUES (:interval_id, :property, :room, :item, :assignee, :assigner, :due_date, NULL, NULL)
             ON DUPLICATE KEY UPDATE assigned_to_user_id = VALUES(assigned_to_user_id),
                assigned_by_user_id = VALUES(assigned_by_user_id), assigned_at = CURRENT_TIMESTAMP,
                due_date = VALUES(due_date),
                completed_at = NULL, completed_by_user_id = NULL'
        );
        $remove = $pdo->prepare(
            'DELETE FROM room_item_assignments WHERE interval_id = :interval_id
             AND property_name = :property AND room_number = :room
             AND item_name = :item AND assigned_to_user_id = :assignee AND due_date = :due_date'
        );
        foreach (CHECKLIST_ITEMS as $name) {
            $parameters = [
                'interval_id' => $intervalId, 'property' => $property, 'room' => $room,
                'item' => $name, 'assignee' => $employeeId,
            ];
            if (isset($selected[$name])) {
                $upsert->execute($parameters + ['assigner' => (int) $currentUser['id'], 'due_date' => $dueDate]);
            } else {
                $remove->execute($parameters + ['due_date' => $dueDate]);
            }
        }
        Auth::audit($pdo, (int) $currentUser['id'], 'room_tasks_assigned', [
            'interval_id' => $intervalId, 'property' => $property, 'room' => $room,
            'assignee' => $employeeId, 'due_date' => $dueDate,
            'assigned_items' => count($selected),
        ]);
        $pdo->commit();
        jsonResponse(['ok' => true, 'assignedItems' => count($selected)]);
    }

    Auth::requirePermission($pdo, $config, Auth::PERMISSION_ROOM_CHECK_EDIT);
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
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    jsonResponse(['ok' => false, 'error' => $exception->getMessage()], 422);
} catch (Throwable $exception) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if ($exception instanceof RuntimeException && in_array($exception->getCode(), [401, 403, 429], true)) {
        jsonResponse(['ok' => false, 'error' => $exception->getMessage()], $exception->getCode());
    }
    error_log((string) $exception);
    jsonResponse(['ok' => false, 'error' => 'Não foi possível aceder à base de dados.'], 500);
}
