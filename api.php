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
        $roomAssignmentCounts = [];
        if (Auth::hasPermission($pdo, $currentUser, Auth::PERMISSION_TASK_ASSIGN)) {
            $intervalId = (int) ($_GET['interval_id'] ?? 0);
            $assignmentStatement = $pdo->prepare(
                'SELECT item_name, assigned_to_user_id, due_date, verification_instructions, completed_at FROM room_item_assignments
                 WHERE interval_id = :interval_id AND property_name = :property
                   AND room_number = :room'
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
                    'instructions' => (string) $assignment['verification_instructions'],
                    'completed' => $assignment['completed_at'] !== null,
                ];
            }
            $roomCountStatement = $pdo->prepare(
                'SELECT room_number, COUNT(*) AS assigned_items FROM room_item_assignments
                 WHERE interval_id = :interval_id AND property_name = :property
                 GROUP BY room_number'
            );
            $roomCountStatement->execute(['interval_id' => $intervalId, 'property' => $property]);
            foreach ($roomCountStatement->fetchAll() as $roomCount) {
                $roomAssignmentCounts[(string) $roomCount['room_number']] = (int) $roomCount['assigned_items'];
            }
        }

        jsonResponse([
            'ok' => true, 'items' => $items, 'assignments' => $assignments,
            'roomAssignmentCounts' => $roomAssignmentCounts,
        ]);
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

    if (($payload['action'] ?? '') === 'update_interval') {
        $currentUser = Auth::requirePermission($pdo, $config, Auth::PERMISSION_TASK_ASSIGN);
        Csrf::validate(isset($payload['csrfToken']) ? (string) $payload['csrfToken'] : null);
        $intervalId = (int) ($payload['intervalId'] ?? 0);
        $name = trim((string) ($payload['name'] ?? ''));
        $startDate = trim((string) ($payload['startDate'] ?? ''));
        $endDate = trim((string) ($payload['endDate'] ?? ''));
        if ($name === '' || mb_strlen($name) > 120) {
            throw new InvalidArgumentException('Indique um nome para o intervalo (máximo 120 caracteres).');
        }
        foreach (['inicial' => $startDate, 'final' => $endDate] as $label => $date) {
            $parts = array_map('intval', explode('-', $date));
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)
                || count($parts) !== 3 || !checkdate($parts[1], $parts[2], $parts[0])) {
                throw new InvalidArgumentException("A data {$label} do intervalo é inválida.");
            }
        }
        if ($endDate < $startDate) {
            throw new InvalidArgumentException('A data final não pode ser anterior à data inicial.');
        }
        $boundsStatement = $pdo->prepare(
            'SELECT MIN(due_date) AS first_due_date, MAX(due_date) AS last_due_date, COUNT(*) AS assignment_count
             FROM room_item_assignments WHERE interval_id = :id'
        );
        $boundsStatement->execute(['id' => $intervalId]);
        $bounds = $boundsStatement->fetch();
        if ((int) ($bounds['assignment_count'] ?? 0) > 0) {
            $firstDueDate = (string) $bounds['first_due_date'];
            $lastDueDate = (string) $bounds['last_due_date'];
            if ($startDate > $firstDueDate || $endDate < $lastDueDate) {
                throw new InvalidArgumentException(
                    "O intervalo tem itens atribuídos entre {$firstDueDate} e {$lastDueDate}. As novas datas têm de incluir todo esse período."
                );
            }
        }
        $statement = $pdo->prepare(
            'UPDATE room_verification_intervals SET name = :name, start_date = :start_date, end_date = :end_date
             WHERE id = :id'
        );
        $statement->execute(['id' => $intervalId, 'name' => $name, 'start_date' => $startDate, 'end_date' => $endDate]);
        if ($statement->rowCount() === 0) {
            $exists = $pdo->prepare('SELECT id FROM room_verification_intervals WHERE id = :id');
            $exists->execute(['id' => $intervalId]);
            if (!$exists->fetchColumn()) {
                throw new InvalidArgumentException('Intervalo de verificação não encontrado.');
            }
        }
        Auth::audit($pdo, (int) $currentUser['id'], 'room_verification_interval_updated', [
            'interval_id' => $intervalId, 'name' => $name, 'start_date' => $startDate, 'end_date' => $endDate,
        ]);
        jsonResponse(['ok' => true, 'interval' => [
            'id' => $intervalId, 'name' => $name, 'startDate' => $startDate, 'endDate' => $endDate,
        ]]);
    }

    if (($payload['action'] ?? '') === 'delete_interval') {
        $currentUser = Auth::requirePermission($pdo, $config, Auth::PERMISSION_TASK_ASSIGN);
        Csrf::validate(isset($payload['csrfToken']) ? (string) $payload['csrfToken'] : null);
        $intervalId = (int) ($payload['intervalId'] ?? 0);
        $pdo->beginTransaction();
        $intervalStatement = $pdo->prepare('SELECT name FROM room_verification_intervals WHERE id = :id FOR UPDATE');
        $intervalStatement->execute(['id' => $intervalId]);
        $intervalName = $intervalStatement->fetchColumn();
        if ($intervalName === false) {
            throw new InvalidArgumentException('Intervalo de verificação não encontrado.');
        }
        $deleteAssignments = $pdo->prepare('DELETE FROM room_item_assignments WHERE interval_id = :id');
        $deleteAssignments->execute(['id' => $intervalId]);
        $deletedAssignments = $deleteAssignments->rowCount();
        $deleteInterval = $pdo->prepare('DELETE FROM room_verification_intervals WHERE id = :id');
        $deleteInterval->execute(['id' => $intervalId]);
        Auth::audit($pdo, (int) $currentUser['id'], 'room_verification_interval_deleted', [
            'interval_id' => $intervalId, 'name' => (string) $intervalName,
            'deleted_assignments' => $deletedAssignments,
        ]);
        $pdo->commit();
        jsonResponse(['ok' => true, 'deletedAssignments' => $deletedAssignments]);
    }

    if (($payload['action'] ?? '') === 'set_assignments_atomic') {
        $currentUser = Auth::requirePermission($pdo, $config, Auth::PERMISSION_TASK_ASSIGN);
        Csrf::validate(isset($payload['csrfToken']) ? (string) $payload['csrfToken'] : null);
        $property = trim((string) ($payload['property'] ?? ''));
        $room = (int) ($payload['room'] ?? 0);
        $intervalId = (int) ($payload['intervalId'] ?? 0);
        $employeeId = (int) ($payload['employeeId'] ?? 0);
        $dueDate = trim((string) ($payload['dueDate'] ?? ''));
        $changes = $payload['changes'] ?? null;
        validateSelection($property, $room);
        if (!is_array($changes) || $changes === [] || count($changes) > count(CHECKLIST_ITEMS)) {
            throw new InvalidArgumentException('Alterações de atribuição inválidas.');
        }
        $dateParts = array_map('intval', explode('-', $dueDate));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueDate)
            || count($dateParts) !== 3
            || !checkdate($dateParts[1], $dateParts[2], $dateParts[0])) {
            throw new InvalidArgumentException('Escolha uma data válida para a verificação.');
        }
        $allowedItems = array_flip(CHECKLIST_ITEMS);
        $normalizedChanges = [];
        foreach ($changes as $change) {
            if (!is_array($change)) {
                throw new InvalidArgumentException('Alteração de atribuição inválida.');
            }
            $name = trim((string) ($change['itemName'] ?? ''));
            if (!isset($allowedItems[$name]) || isset($normalizedChanges[$name])) {
                throw new InvalidArgumentException('Item de atribuição inválido ou repetido.');
            }
            $instructions = trim((string) ($change['instructions'] ?? ''));
            if (mb_strlen($instructions) > 5000) {
                throw new InvalidArgumentException("As instruções de {$name} são demasiado longas.");
            }
            $normalizedChanges[$name] = [
                'selected' => filter_var($change['selected'] ?? false, FILTER_VALIDATE_BOOL),
                'instructions' => $instructions,
            ];
        }

        $pdo->beginTransaction();
        $intervalCheck = $pdo->prepare(
            'SELECT start_date, end_date FROM room_verification_intervals WHERE id = :id FOR UPDATE'
        );
        $intervalCheck->execute(['id' => $intervalId]);
        $interval = $intervalCheck->fetch();
        if (!$interval) {
            throw new InvalidArgumentException('Escolha um intervalo de verificação válido.');
        }
        if ($dueDate < (string) $interval['start_date'] || $dueDate > (string) $interval['end_date']) {
            throw new InvalidArgumentException('A data da verificação tem de ficar dentro do intervalo escolhido.');
        }
        $employeeCheck = $pdo->prepare(
            "SELECT id FROM users WHERE id = :id AND role = 'empregada_andares' AND is_active = 1 FOR UPDATE"
        );
        $employeeCheck->execute(['id' => $employeeId]);
        if (!$employeeCheck->fetchColumn()) {
            throw new InvalidArgumentException('Escolha uma Empregada de Andares ativa.');
        }
        $assignmentLock = $pdo->prepare(
            'SELECT assigned_to_user_id, due_date, completed_at FROM room_item_assignments
             WHERE interval_id = :interval_id AND property_name = :property
               AND room_number = :room AND item_name = :item FOR UPDATE'
        );
        $upsert = $pdo->prepare(
            'INSERT INTO room_item_assignments
                (interval_id, property_name, room_number, item_name, assigned_to_user_id, assigned_by_user_id, due_date, verification_instructions, completed_at, completed_by_user_id)
             VALUES (:interval_id, :property, :room, :item, :assignee, :assigner, :due_date, :instructions, NULL, NULL)
             ON DUPLICATE KEY UPDATE assigned_to_user_id = VALUES(assigned_to_user_id),
                assigned_by_user_id = VALUES(assigned_by_user_id), assigned_at = CURRENT_TIMESTAMP,
                due_date = VALUES(due_date), verification_instructions = VALUES(verification_instructions),
                completed_at = NULL, completed_by_user_id = NULL'
        );
        $remove = $pdo->prepare(
            'DELETE FROM room_item_assignments WHERE interval_id = :interval_id
             AND property_name = :property AND room_number = :room AND item_name = :item
             AND assigned_to_user_id = :assignee AND due_date = :due_date AND completed_at IS NULL'
        );
        foreach ($normalizedChanges as $name => $change) {
            $identity = [
                'interval_id' => $intervalId, 'property' => $property,
                'room' => $room, 'item' => $name,
            ];
            $assignmentLock->execute($identity);
            $existing = $assignmentLock->fetch();
            if ($existing
                && ((int) $existing['assigned_to_user_id'] !== $employeeId
                    || (string) $existing['due_date'] !== $dueDate
                    || $existing['completed_at'] !== null)) {
                throw new InvalidArgumentException(
                    "O item {$name} já está atribuído ou concluído noutra data deste intervalo."
                );
            }
            if ($change['selected']) {
                $upsert->execute($identity + [
                    'assignee' => $employeeId, 'assigner' => (int) $currentUser['id'],
                    'due_date' => $dueDate, 'instructions' => $change['instructions'],
                ]);
            } else {
                $remove->execute($identity + ['assignee' => $employeeId, 'due_date' => $dueDate]);
            }
        }
        Auth::audit($pdo, (int) $currentUser['id'], 'room_task_assignments_atomic_update', [
            'interval_id' => $intervalId, 'property' => $property, 'room' => $room,
            'assignee' => $employeeId, 'due_date' => $dueDate,
            'changed_items' => array_keys($normalizedChanges),
        ]);
        $boundsStatement = $pdo->prepare(
            'SELECT MIN(due_date) AS first_due_date, MAX(due_date) AS last_due_date
             FROM room_item_assignments WHERE interval_id = :interval_id'
        );
        $boundsStatement->execute(['interval_id' => $intervalId]);
        $intervalBounds = $boundsStatement->fetch();
        $roomCount = $pdo->prepare(
            'SELECT COUNT(*) FROM room_item_assignments WHERE interval_id = :interval_id
             AND property_name = :property AND room_number = :room'
        );
        $roomCount->execute(['interval_id' => $intervalId, 'property' => $property, 'room' => $room]);
        $roomAssignedItems = (int) $roomCount->fetchColumn();
        $pdo->commit();
        jsonResponse([
            'ok' => true,
            'roomAssignedItems' => $roomAssignedItems,
            'intervalBounds' => [
                'firstDueDate' => $intervalBounds['first_due_date'] !== null
                    ? (string) $intervalBounds['first_due_date'] : null,
                'lastDueDate' => $intervalBounds['last_due_date'] !== null
                    ? (string) $intervalBounds['last_due_date'] : null,
            ],
        ]);
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
        $instructions = $payload['instructions'] ?? [];
        validateSelection($property, $room);
        if (!is_array($selectedItems)) {
            throw new InvalidArgumentException('Seleção de itens inválida.');
        }
        if (!is_array($instructions)) {
            throw new InvalidArgumentException('Instruções de verificação inválidas.');
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
        $normalizedInstructions = [];
        foreach (array_keys($selected) as $name) {
            $instruction = trim((string) ($instructions[$name] ?? ''));
            if (mb_strlen($instruction) > 5000) {
                throw new InvalidArgumentException("As instruções de {$name} são demasiado longas.");
            }
            $normalizedInstructions[$name] = $instruction;
        }
        $pdo->beginTransaction();
        $assignmentLock = $pdo->prepare(
            'SELECT assigned_to_user_id, due_date, completed_at FROM room_item_assignments
             WHERE interval_id = :interval_id AND property_name = :property
               AND room_number = :room AND item_name = :item FOR UPDATE'
        );
        $upsert = $pdo->prepare(
            'INSERT INTO room_item_assignments
                (interval_id, property_name, room_number, item_name, assigned_to_user_id, assigned_by_user_id, due_date, verification_instructions, completed_at, completed_by_user_id)
             VALUES (:interval_id, :property, :room, :item, :assignee, :assigner, :due_date, :instructions, NULL, NULL)
             ON DUPLICATE KEY UPDATE assigned_to_user_id = VALUES(assigned_to_user_id),
                assigned_by_user_id = VALUES(assigned_by_user_id), assigned_at = CURRENT_TIMESTAMP,
                due_date = VALUES(due_date), verification_instructions = VALUES(verification_instructions),
                completed_at = NULL, completed_by_user_id = NULL'
        );
        $remove = $pdo->prepare(
            'DELETE FROM room_item_assignments WHERE interval_id = :interval_id
             AND property_name = :property AND room_number = :room
             AND item_name = :item AND assigned_to_user_id = :assignee AND due_date = :due_date
             AND completed_at IS NULL'
        );
        foreach (CHECKLIST_ITEMS as $name) {
            $parameters = [
                'interval_id' => $intervalId, 'property' => $property, 'room' => $room,
                'item' => $name, 'assignee' => $employeeId,
            ];
            if (isset($selected[$name])) {
                $assignmentLock->execute([
                    'interval_id' => $intervalId, 'property' => $property,
                    'room' => $room, 'item' => $name,
                ]);
                $existingAssignment = $assignmentLock->fetch();
                if ($existingAssignment
                    && ((int) $existingAssignment['assigned_to_user_id'] !== $employeeId
                        || (string) $existingAssignment['due_date'] !== $dueDate
                        || $existingAssignment['completed_at'] !== null)) {
                    throw new InvalidArgumentException(
                        "O item {$name} já está atribuído ou concluído noutra data deste intervalo."
                    );
                }
                $upsert->execute($parameters + [
                    'assigner' => (int) $currentUser['id'], 'due_date' => $dueDate,
                    'instructions' => $normalizedInstructions[$name],
                ]);
            } else {
                $remove->execute($parameters + ['due_date' => $dueDate]);
            }
        }
        Auth::audit($pdo, (int) $currentUser['id'], 'room_tasks_assigned', [
            'interval_id' => $intervalId, 'property' => $property, 'room' => $room,
            'assignee' => $employeeId, 'due_date' => $dueDate,
            'assigned_items' => count($selected),
        ]);
        $boundsStatement = $pdo->prepare(
            'SELECT MIN(due_date) AS first_due_date, MAX(due_date) AS last_due_date
             FROM room_item_assignments WHERE interval_id = :interval_id'
        );
        $boundsStatement->execute(['interval_id' => $intervalId]);
        $intervalBounds = $boundsStatement->fetch();
        $pdo->commit();
        jsonResponse([
            'ok' => true,
            'assignedItems' => count($selected),
            'intervalBounds' => [
                'firstDueDate' => $intervalBounds['first_due_date'] !== null
                    ? (string) $intervalBounds['first_due_date'] : null,
                'lastDueDate' => $intervalBounds['last_due_date'] !== null
                    ? (string) $intervalBounds['last_due_date'] : null,
            ],
        ]);
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
