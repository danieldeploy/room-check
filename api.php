<?php
declare(strict_types=1);

require __DIR__ . '/lib.php';
$config = require __DIR__ . '/config.php';
require_once __DIR__ . '/src/Auth/Auth.php';
require_once __DIR__ . '/src/Security/Csrf.php';
require_once __DIR__ . '/src/I18n/ContentTranslator.php';

try {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $property = trim((string) ($_GET['property'] ?? ''));
    $room = (int) ($_GET['room'] ?? 0);

    if ($method === 'GET') {
        $pdo = database();
        $currentUser = Auth::requirePermission($pdo, $config, Auth::PERMISSION_ROOM_CHECK_VIEW);
        validateSelection($property, $room);
        $listId = (int) ($_GET['list_id'] ?? 0);
        $intervalId = (int) ($_GET['interval_id'] ?? 0);
        $selectedList = itemList($pdo, $listId);
        $listItems = $selectedList['items'];

        $statement = $pdo->prepare(
            'SELECT item_name, problem, problem_en, status
             FROM room_checklist_values
             WHERE list_id = :list_id AND property_name = :property AND room_number = :room'
        );
        $statement->execute(['list_id' => $listId, 'property' => $property, 'room' => $room]);

        $saved = [];
        foreach ($statement->fetchAll() as $row) {
            $saved[$row['item_name']] = [
                'problem' => Translator::localized((string) $row['problem'], (string) $row['problem_en']),
                'status' => $row['status'],
            ];
        }

        $items = array_map(
            static function (string $name) use ($saved, $selectedList, $intervalId): array {
                $listInstructions = (string) ($selectedList['defaults'][$name] ?? '');
                $roomInstructions = (string) ($saved[$name]['problem'] ?? '');

                return [
                    'name' => $name,
                    'problem' => $intervalId > 0
                        ? $listInstructions
                        : ($roomInstructions !== '' ? $roomInstructions : $listInstructions),
                    'status' => $saved[$name]['status'] ?? null,
                    'defaultInstructions' => $listInstructions,
                ];
            },
            $listItems
        );

        $assignments = [];
        $roomAssignmentCounts = [];
        if (Auth::hasPermission($pdo, $currentUser, Auth::PERMISSION_TASK_ASSIGN)) {
            $assignmentStatement = $pdo->prepare(
                "SELECT a.item_name, a.assigned_to_user_id, a.due_date, a.verification_instructions,
                        a.verification_instructions_en, a.completed_at
                 FROM room_item_assignments a
                 WHERE interval_id = :interval_id AND list_id = :list_id AND property_name = :property
                   AND room_number = :room"
            );
            $assignmentStatement->execute([
                'interval_id' => $intervalId,
                'list_id' => $listId,
                'property' => $property,
                'room' => $room,
            ]);
            foreach ($assignmentStatement->fetchAll() as $assignment) {
                $assignments[(string) $assignment['item_name']] = [
                    'employeeId' => (int) $assignment['assigned_to_user_id'],
                    'dueDate' => (string) $assignment['due_date'],
                    'instructions' => Translator::localized(
                        (string) $assignment['verification_instructions'],
                        (string) $assignment['verification_instructions_en']
                    ),
                    'completed' => $assignment['completed_at'] !== null,
                ];
            }
            $roomCountStatement = $pdo->prepare(
                'SELECT room_number, COUNT(*) AS assigned_items FROM room_item_assignments
                 WHERE interval_id = :interval_id AND list_id = :list_id AND property_name = :property
                 GROUP BY room_number'
            );
            $roomCountStatement->execute(['interval_id' => $intervalId, 'list_id' => $listId, 'property' => $property]);
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
    $contentTranslator = new ContentTranslator($pdo, $config['translation'] ?? []);

    if (($payload['action'] ?? '') === 'validate_bilingual_texts') {
        Auth::requireLogin($pdo, $config);
        Csrf::validate(isset($payload['csrfToken']) ? (string) $payload['csrfToken'] : null);
        $fields = $payload['fields'] ?? null;
        if (!is_array($fields) || count($fields) > 100) {
            throw new InvalidArgumentException('Invalid language validation request.');
        }
        $invalidFields = [];
        foreach ($fields as $field) {
            if (!is_array($field)) continue;
            $fieldKey = trim((string) ($field['fieldKey'] ?? ''));
            $text = trim((string) ($field['text'] ?? ''));
            if (mb_strlen($text) > 5000) {
                throw new InvalidArgumentException('Text is too long.');
            }
            try {
                LanguageGuard::assertExpectedLanguage($text, Translator::locale());
            } catch (LanguageValidationException $exception) {
                $invalidFields[] = [
                    'fieldKey' => $fieldKey,
                    'invalidWords' => $exception->invalidWords,
                    'error' => $exception->getMessage(),
                ];
            }
        }
        if ($invalidFields !== []) {
            jsonResponse([
                'ok' => false,
                'validation' => true,
                'error' => (string) $invalidFields[0]['error'],
                'invalidFields' => $invalidFields,
            ], 422);
        }
        jsonResponse(['ok' => true, 'valid' => true]);
    }

    if (($payload['action'] ?? '') === 'save_item_list_instructions') {
        $currentUser = Auth::requirePermission($pdo, $config, Auth::PERMISSION_TASK_ASSIGN);
        Csrf::validate(isset($payload['csrfToken']) ? (string) $payload['csrfToken'] : null);
        $listId = (int) ($payload['listId'] ?? 0);
        $itemId = (int) ($payload['itemId'] ?? 0);
        $textValue = trim((string) ($payload['text'] ?? ''));
        if ($listId < 1 || $itemId < 1) {
            throw new InvalidArgumentException('Item não encontrado.');
        }
        if (mb_strlen($textValue) > 5000) {
            throw new InvalidArgumentException('A descrição da verificação não pode ultrapassar 5000 caracteres.');
        }
        itemList($pdo, $listId);
        $pdo->beginTransaction();
        $currentStatement = $pdo->prepare(
            'SELECT default_instructions, default_instructions_en
             FROM item_list_items WHERE id = :id AND list_id = :list_id FOR UPDATE'
        );
        $currentStatement->execute(['id' => $itemId, 'list_id' => $listId]);
        $currentItem = $currentStatement->fetch();
        if (!is_array($currentItem)) {
            throw new InvalidArgumentException('Item não encontrado.');
        }
        $instructionVersions = $contentTranslator->versions(
            $textValue,
            Translator::locale(),
            (string) ($currentItem['default_instructions'] ?? ''),
            (string) ($currentItem['default_instructions_en'] ?? '')
        );
        $updateStatement = $pdo->prepare(
            'UPDATE item_list_items
             SET default_instructions = :instructions_pt, default_instructions_en = :instructions_en
             WHERE id = :id AND list_id = :list_id'
        );
        $updateStatement->execute([
            'instructions_pt' => $instructionVersions['pt'],
            'instructions_en' => $instructionVersions['en'],
            'id' => $itemId,
            'list_id' => $listId,
        ]);
        Auth::audit($pdo, (int) $currentUser['id'], 'item_list_instructions_updated', [
            'list_id' => $listId,
            'item_id' => $itemId,
        ]);
        $pdo->commit();
        jsonResponse([
            'ok' => true,
            'value' => Translator::localized($instructionVersions['pt'], $instructionVersions['en']),
        ]);
    }

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

        $nameVersions = $contentTranslator->versions($name, Translator::locale());
        $statement = $pdo->prepare(
            'INSERT INTO room_verification_intervals (name, name_en, start_date, end_date, created_by_user_id)
             VALUES (:name_pt, :name_en, :start_date, :end_date, :created_by)'
        );
        $statement->execute([
            'name_pt' => $nameVersions['pt'],
            'name_en' => $nameVersions['en'],
            'start_date' => $startDate,
            'end_date' => $endDate,
            'created_by' => (int) $currentUser['id'],
        ]);
        $intervalId = (int) $pdo->lastInsertId();
        $displayName = Translator::localized($nameVersions['pt'], $nameVersions['en']);
        Auth::audit($pdo, (int) $currentUser['id'], 'room_verification_interval_created', [
            'interval_id' => $intervalId,
            'name_pt' => $nameVersions['pt'],
            'name_en' => $nameVersions['en'],
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]);
        jsonResponse(['ok' => true, 'interval' => [
            'id' => $intervalId, 'name' => $displayName, 'startDate' => $startDate, 'endDate' => $endDate,
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

        $currentIntervalStatement = $pdo->prepare(
            'SELECT name, name_en FROM room_verification_intervals WHERE id = :id'
        );
        $currentIntervalStatement->execute(['id' => $intervalId]);
        $currentInterval = $currentIntervalStatement->fetch();
        if (!$currentInterval) {
            throw new InvalidArgumentException('Intervalo de verificação não encontrado.');
        }
        $nameVersions = $contentTranslator->versions(
            $name,
            Translator::locale(),
            (string) $currentInterval['name'],
            (string) ($currentInterval['name_en'] ?? '')
        );

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
            'UPDATE room_verification_intervals
             SET name = :name_pt, name_en = :name_en, start_date = :start_date, end_date = :end_date
             WHERE id = :id'
        );
        $statement->execute([
            'id' => $intervalId,
            'name_pt' => $nameVersions['pt'],
            'name_en' => $nameVersions['en'],
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]);
        $displayName = Translator::localized($nameVersions['pt'], $nameVersions['en']);
        Auth::audit($pdo, (int) $currentUser['id'], 'room_verification_interval_updated', [
            'interval_id' => $intervalId,
            'name_pt' => $nameVersions['pt'],
            'name_en' => $nameVersions['en'],
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]);
        jsonResponse(['ok' => true, 'interval' => [
            'id' => $intervalId, 'name' => $displayName, 'startDate' => $startDate, 'endDate' => $endDate,
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

    if (($payload['action'] ?? '') === 'get_whatsapp_reminder') {
        Auth::requirePermission($pdo, $config, Auth::PERMISSION_TASK_ASSIGN);
        Csrf::validate(isset($payload['csrfToken']) ? (string) $payload['csrfToken'] : null);
        $employeeId = (int) ($payload['employeeId'] ?? 0); $dueDate = trim((string) ($payload['dueDate'] ?? ''));
        $property = trim((string) ($payload['property'] ?? ''));
        $listId = (int) ($payload['listId'] ?? 0);
        if ($listId < 1) throw new InvalidArgumentException('Escolha a lista.');
        $statement = $pdo->prepare("SELECT TIME_FORMAT(scheduled_at, '%H:%i') AS reminder_time, status FROM whatsapp_assignment_reminders WHERE assigned_to_user_id = :employee_id AND due_date = :due_date AND property_name = :property AND list_id = :list_id");
        $statement->execute(['employee_id' => $employeeId, 'due_date' => $dueDate, 'property' => $property, 'list_id' => $listId]);
        $reminder = $statement->fetch();
        jsonResponse(['ok' => true, 'reminderTime' => $reminder ? (string) $reminder['reminder_time'] : null, 'reminderStatus' => $reminder ? (string) $reminder['status'] : null]);
    }

    if (($payload['action'] ?? '') === 'schedule_whatsapp_reminder') {
        $currentUser = Auth::requirePermission($pdo, $config, Auth::PERMISSION_TASK_ASSIGN);
        Csrf::validate(isset($payload['csrfToken']) ? (string) $payload['csrfToken'] : null);
        $employeeId = (int) ($payload['employeeId'] ?? 0);
        $listId = (int) ($payload['listId'] ?? 0);
        $dueDate = trim((string) ($payload['dueDate'] ?? ''));
        $property = trim((string) ($payload['property'] ?? ''));
        $enabled = filter_var($payload['enabled'] ?? false, FILTER_VALIDATE_BOOL);
        $time = trim((string) ($payload['time'] ?? ''));
        if ($enabled && !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time)) {
            throw new InvalidArgumentException('Escolha uma hora válida para o alerta WhatsApp.');
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueDate) || $employeeId < 1 || $listId < 1 || $property === '') throw new InvalidArgumentException('Escolha o alojamento, a lista, a empregada e a data.');
        $selectedList = itemList($pdo, $listId);
        $pdo->beginTransaction();
        $find = $pdo->prepare(
            'SELECT u.mobile, COUNT(a.id) AS assignment_count FROM users u
             LEFT JOIN room_item_assignments a ON a.assigned_to_user_id = u.id AND a.due_date = :due_date AND a.property_name = :property AND a.list_id = :list_id AND a.completed_at IS NULL
             WHERE u.id = :employee_id GROUP BY u.id, u.mobile'
        );
        $find->execute(['employee_id' => $employeeId, 'due_date' => $dueDate, 'property' => $property, 'list_id' => $listId]);
        $assignment = $find->fetch();
        if (!$assignment || (int) $assignment['assignment_count'] < 1) throw new InvalidArgumentException('Esta empregada não tem itens atribuídos nessa data.');
        if ($enabled && trim((string) $assignment['mobile']) === '') throw new InvalidArgumentException('A empregada não tem telemóvel configurado.');
        if ($enabled) {
            $scheduledAt = $dueDate . ' ' . $time . ':00';
            $save = $pdo->prepare(
                "INSERT INTO whatsapp_assignment_reminders (assigned_to_user_id, list_id, due_date, property_name, scheduled_at, status, created_by_user_id)
                 VALUES (:employee_id, :list_id, :due_date, :property, :scheduled_at, 'pending', :creator)
                 ON DUPLICATE KEY UPDATE scheduled_at = VALUES(scheduled_at), status = 'pending', attempt_count = 0,
                    next_attempt_at = NULL, meta_message_id = NULL, last_error = NULL, sent_at = NULL"
            );
            $save->execute(['employee_id' => $employeeId, 'list_id' => $listId, 'due_date' => $dueDate, 'property' => $property, 'scheduled_at' => $scheduledAt, 'creator' => (int) $currentUser['id']]);
        } else {
            $delete = $pdo->prepare('DELETE FROM whatsapp_assignment_reminders WHERE assigned_to_user_id = :employee_id AND due_date = :due_date AND property_name = :property AND list_id = :list_id');
            $delete->execute(['employee_id' => $employeeId, 'due_date' => $dueDate, 'property' => $property, 'list_id' => $listId]);
        }
        Auth::audit($pdo, (int) $currentUser['id'], 'whatsapp_assignment_reminder_changed', [
            'employee_id' => $employeeId, 'list_id' => $listId, 'list_name' => (string) $selectedList['name'], 'due_date' => $dueDate, 'property' => $property, 'enabled' => $enabled, 'time' => $enabled ? $time : null,
        ]);
        $pdo->commit();
        jsonResponse(['ok' => true, 'reminderTime' => $enabled ? $time : null, 'reminderStatus' => $enabled ? 'pending' : null]);
    }

    if (($payload['action'] ?? '') === 'set_assignments_atomic') {
        $currentUser = Auth::requirePermission($pdo, $config, Auth::PERMISSION_TASK_ASSIGN);
        Csrf::validate(isset($payload['csrfToken']) ? (string) $payload['csrfToken'] : null);
        $property = trim((string) ($payload['property'] ?? ''));
        $room = (int) ($payload['room'] ?? 0);
        $intervalId = (int) ($payload['intervalId'] ?? 0);
        $listId = (int) ($payload['listId'] ?? 0);
        $employeeId = (int) ($payload['employeeId'] ?? 0);
        $dueDate = trim((string) ($payload['dueDate'] ?? ''));
        $changes = $payload['changes'] ?? null;
        validateSelection($property, $room);
        $selectedList = itemList($pdo, $listId);
        $listItems = $selectedList['items'];
        if (!is_array($changes) || $changes === [] || count($changes) > count($listItems)) {
            throw new InvalidArgumentException('Alterações de atribuição inválidas.');
        }
        $dateParts = array_map('intval', explode('-', $dueDate));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueDate)
            || count($dateParts) !== 3
            || !checkdate($dateParts[1], $dateParts[2], $dateParts[0])) {
            throw new InvalidArgumentException('Escolha uma data válida para a verificação.');
        }
        $allowedItems = array_flip($listItems);
        $existingInstructionsStatement = $pdo->prepare(
            'SELECT item_name, verification_instructions, verification_instructions_en
             FROM room_item_assignments
             WHERE interval_id = :interval_id AND list_id = :list_id AND property_name = :property
               AND room_number = :room'
        );
        $existingInstructionsStatement->execute([
            'interval_id' => $intervalId, 'list_id' => $listId,
            'property' => $property, 'room' => $room,
        ]);
        $existingInstructions = [];
        foreach ($existingInstructionsStatement->fetchAll() as $existingInstruction) {
            $existingInstructions[(string) $existingInstruction['item_name']] = $existingInstruction;
        }
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
            $existingInstruction = $existingInstructions[$name] ?? [];
            try {
                $instructionVersions = $contentTranslator->versions(
                    $instructions,
                    Translator::locale(),
                    (string) ($existingInstruction['verification_instructions'] ?? ''),
                    (string) ($existingInstruction['verification_instructions_en'] ?? '')
                );
            } catch (LanguageValidationException $exception) {
                throw $exception->withField($name);
            }
            $normalizedChanges[$name] = [
                'selected' => filter_var($change['selected'] ?? false, FILTER_VALIDATE_BOOL),
                'instructions' => $instructionVersions['pt'],
                'instructions_en' => $instructionVersions['en'],
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
             WHERE interval_id = :interval_id AND list_id = :list_id AND property_name = :property
               AND room_number = :room AND item_name = :item FOR UPDATE'
        );
        $upsert = $pdo->prepare(
            'INSERT INTO room_item_assignments
                (interval_id, list_id, property_name, room_number, item_name, assigned_to_user_id, assigned_by_user_id, due_date, verification_instructions, verification_instructions_en, completed_at, completed_by_user_id)
             VALUES (:interval_id, :list_id, :property, :room, :item, :assignee, :assigner, :due_date, :instructions, :instructions_en, NULL, NULL)
             ON DUPLICATE KEY UPDATE assigned_to_user_id = VALUES(assigned_to_user_id),
                assigned_by_user_id = VALUES(assigned_by_user_id), assigned_at = CURRENT_TIMESTAMP,
                due_date = VALUES(due_date), verification_instructions = VALUES(verification_instructions),
                verification_instructions_en = VALUES(verification_instructions_en),
                completed_at = NULL, completed_by_user_id = NULL'
        );
        $remove = $pdo->prepare(
            'DELETE FROM room_item_assignments WHERE interval_id = :interval_id AND list_id = :list_id
             AND property_name = :property AND room_number = :room AND item_name = :item
             AND assigned_to_user_id = :assignee AND due_date = :due_date AND completed_at IS NULL'
        );
        foreach ($normalizedChanges as $name => $change) {
            $identity = [
                'interval_id' => $intervalId, 'property' => $property,
                'list_id' => $listId, 'room' => $room, 'item' => $name,
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
                    'instructions_en' => $change['instructions_en'],
                ]);
            } else {
                $remove->execute($identity + ['assignee' => $employeeId, 'due_date' => $dueDate]);
            }
        }
        Auth::audit($pdo, (int) $currentUser['id'], 'room_task_assignments_atomic_update', [
            'interval_id' => $intervalId, 'list_id' => $listId, 'property' => $property, 'room' => $room,
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
             AND list_id = :list_id AND property_name = :property AND room_number = :room'
        );
        $roomCount->execute(['interval_id' => $intervalId, 'list_id' => $listId, 'property' => $property, 'room' => $room]);
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
        $listId = (int) ($payload['listId'] ?? 0);
        $employeeId = (int) ($payload['employeeId'] ?? 0);
        $dueDate = trim((string) ($payload['dueDate'] ?? ''));
        $selectedItems = $payload['selectedItems'] ?? null;
        $instructions = $payload['instructions'] ?? [];
        validateSelection($property, $room);
        $selectedList = itemList($pdo, $listId);
        $listItems = $selectedList['items'];
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
        $selected = array_fill_keys(array_values(array_intersect($listItems, array_map('strval', $selectedItems))), true);
        $existingInstructionsStatement = $pdo->prepare(
            'SELECT item_name, verification_instructions, verification_instructions_en
             FROM room_item_assignments
             WHERE interval_id = :interval_id AND list_id = :list_id AND property_name = :property
               AND room_number = :room'
        );
        $existingInstructionsStatement->execute([
            'interval_id' => $intervalId, 'list_id' => $listId,
            'property' => $property, 'room' => $room,
        ]);
        $existingInstructions = [];
        foreach ($existingInstructionsStatement->fetchAll() as $existingInstruction) {
            $existingInstructions[(string) $existingInstruction['item_name']] = $existingInstruction;
        }
        $normalizedInstructions = [];
        foreach (array_keys($selected) as $name) {
            $instruction = trim((string) ($instructions[$name] ?? ''));
            if (mb_strlen($instruction) > 5000) {
                throw new InvalidArgumentException("As instruções de {$name} são demasiado longas.");
            }
            $existingInstruction = $existingInstructions[$name] ?? [];
            $normalizedInstructions[$name] = $contentTranslator->versions(
                $instruction,
                Translator::locale(),
                (string) ($existingInstruction['verification_instructions'] ?? ''),
                (string) ($existingInstruction['verification_instructions_en'] ?? '')
            );
        }
        $pdo->beginTransaction();
        $assignmentLock = $pdo->prepare(
            'SELECT assigned_to_user_id, due_date, completed_at FROM room_item_assignments
             WHERE interval_id = :interval_id AND list_id = :list_id AND property_name = :property
               AND room_number = :room AND item_name = :item FOR UPDATE'
        );
        $upsert = $pdo->prepare(
            'INSERT INTO room_item_assignments
                (interval_id, list_id, property_name, room_number, item_name, assigned_to_user_id, assigned_by_user_id, due_date, verification_instructions, verification_instructions_en, completed_at, completed_by_user_id)
             VALUES (:interval_id, :list_id, :property, :room, :item, :assignee, :assigner, :due_date, :instructions, :instructions_en, NULL, NULL)
             ON DUPLICATE KEY UPDATE assigned_to_user_id = VALUES(assigned_to_user_id),
                assigned_by_user_id = VALUES(assigned_by_user_id), assigned_at = CURRENT_TIMESTAMP,
                due_date = VALUES(due_date), verification_instructions = VALUES(verification_instructions),
                verification_instructions_en = VALUES(verification_instructions_en),
                completed_at = NULL, completed_by_user_id = NULL'
        );
        $remove = $pdo->prepare(
            'DELETE FROM room_item_assignments WHERE interval_id = :interval_id AND list_id = :list_id
             AND property_name = :property AND room_number = :room
             AND item_name = :item AND assigned_to_user_id = :assignee AND due_date = :due_date
             AND completed_at IS NULL'
        );
        foreach ($listItems as $name) {
            $parameters = [
                'interval_id' => $intervalId, 'list_id' => $listId, 'property' => $property, 'room' => $room,
                'item' => $name, 'assignee' => $employeeId,
            ];
            if (isset($selected[$name])) {
                $assignmentLock->execute([
                    'interval_id' => $intervalId, 'list_id' => $listId, 'property' => $property,
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
                    'instructions' => $normalizedInstructions[$name]['pt'],
                    'instructions_en' => $normalizedInstructions[$name]['en'],
                ]);
            } else {
                $remove->execute($parameters + ['due_date' => $dueDate]);
            }
        }
        Auth::audit($pdo, (int) $currentUser['id'], 'room_tasks_assigned', [
            'interval_id' => $intervalId, 'list_id' => $listId, 'property' => $property, 'room' => $room,
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
    $listId = (int) ($payload['listId'] ?? 0);
    $items = $payload['items'] ?? null;

    validateSelection($property, $room);
    $selectedList = itemList($pdo, $listId);
    $listItems = $selectedList['items'];
    if (!is_array($items)) {
        throw new InvalidArgumentException('Dados do checklist inválidos.');
    }

    $allowedItems = array_flip($listItems);
    $existingProblemsStatement = $pdo->prepare(
        'SELECT item_name, problem, problem_en FROM room_checklist_values
         WHERE property_name = :property AND room_number = :room AND list_id = :list_id'
    );
    $existingProblemsStatement->execute(['property' => $property, 'room' => $room, 'list_id' => $listId]);
    $existingProblems = [];
    foreach ($existingProblemsStatement->fetchAll() as $existingProblem) {
        $existingProblems[(string) $existingProblem['item_name']] = $existingProblem;
    }
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

        $existingProblem = $existingProblems[$name] ?? [];
        $existingPt = trim((string) ($existingProblem['problem'] ?? ''));
        $existingEn = trim((string) ($existingProblem['problem_en'] ?? ''));
        $defaultProblem = trim((string) ($selectedList['defaults'][$name] ?? ''));

        // The UI displays default list instructions when no room-specific text
        // exists. That fallback is not new user input. Multi-row autosave sends
        // every visible textarea, so discard an untouched fallback before the
        // language guard/provider sees it. A genuinely edited value differs
        // from the fallback and continues through normal PT/EN persistence.
        if ($existingPt === '' && $existingEn === '' && $problem === $defaultProblem) {
            $problem = '';
        }

        try {
            $problemVersions = $contentTranslator->versions(
                $problem,
                Translator::locale(),
                $existingPt,
                $existingEn
            );
        } catch (LanguageValidationException $exception) {
            throw $exception->withField($name);
        }
        $normalized[$name] = [
            'problem' => $problemVersions['pt'],
            'problem_en' => $problemVersions['en'],
            'status' => $status,
        ];
    }

    $pdo->beginTransaction();

    $statement = $pdo->prepare(
        'INSERT INTO room_checklist_values
            (property_name, room_number, list_id, item_name, problem, problem_en, status)
         VALUES
            (:property, :room, :list_id, :item, :problem, :problem_en, :status)
         ON DUPLICATE KEY UPDATE
            problem = VALUES(problem),
            problem_en = VALUES(problem_en),
            status = VALUES(status),
            updated_at = CURRENT_TIMESTAMP'
    );

    foreach ($listItems as $name) {
        $value = $normalized[$name] ?? ['problem' => '', 'problem_en' => '', 'status' => null];
        $statement->execute([
            'property' => $property,
            'room' => $room,
            'list_id' => $listId,
            'item' => $name,
            'problem' => $value['problem'],
            'problem_en' => $value['problem_en'],
            'status' => $value['status'],
        ]);
    }

    $pdo->commit();
    jsonResponse(['ok' => true, 'savedAt' => gmdate('c')]);
} catch (LanguageValidationException $exception) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    jsonResponse([
        'ok' => false,
        'validation' => true,
        'error' => $exception->getMessage(),
        'invalidWords' => $exception->invalidWords,
        'fieldKey' => $exception->fieldKey,
    ], 422);
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
