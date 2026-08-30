<?php
declare(strict_types=1);

require __DIR__ . '/lib.php';
$config = require __DIR__ . '/config.php';
require_once __DIR__ . '/src/Auth/Auth.php';
require_once __DIR__ . '/src/Security/Csrf.php';
require_once __DIR__ . '/src/UI/SessionBar.php';

try {
    $pdo = database();
    $currentUser = Auth::requireLogin($pdo, $config);
} catch (RuntimeException) {
    header('Location: login.php');
    exit;
}

$canAssign = Auth::hasPermission($pdo, $currentUser, Auth::PERMISSION_TASK_ASSIGN);
$canViewOwn = Auth::hasPermission($pdo, $currentUser, Auth::PERMISSION_TASK_VIEW_OWN);
if ($canAssign && !$canViewOwn) {
    header('Location: rooms.php');
    exit;
}
if ($canViewOwn) {
    $canAssign = false;
}
if (!$canAssign && !$canViewOwn) {
    http_response_code(403);
    exit('Não tem permissão para consultar tarefas.');
}

$message = null;
$error = null;
$requestData = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' ? $_POST : $_GET;
$property = trim((string) ($requestData['property'] ?? array_key_first(PROPERTIES)));
$room = (int) ($requestData['room'] ?? 1);
$today = new DateTimeImmutable('today', new DateTimeZone('Europe/Lisbon'));
$selectedDateValue = trim((string) ($requestData['date'] ?? $today->format('Y-m-d')));
$selectedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $selectedDateValue, new DateTimeZone('Europe/Lisbon'));
if (!$selectedDate || $selectedDate->format('Y-m-d') !== $selectedDateValue) {
    $selectedDate = $today;
    $selectedDateValue = $today->format('Y-m-d');
}
$calendarMonthValue = trim((string) ($requestData['month'] ?? $selectedDate->format('Y-m')));
$calendarMonth = DateTimeImmutable::createFromFormat('!Y-m', $calendarMonthValue, new DateTimeZone('Europe/Lisbon'));
if (!$calendarMonth || $calendarMonth->format('Y-m') !== $calendarMonthValue) {
    $calendarMonth = $selectedDate->modify('first day of this month');
    $calendarMonthValue = $calendarMonth->format('Y-m');
}

try {
    validateSelection($property, $room);
} catch (InvalidArgumentException) {
    $property = (string) array_key_first(PROPERTIES);
    $room = 1;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        Csrf::validate($_POST['csrf_token'] ?? null);
        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'save_assignments') {
            if (!$canAssign) {
                throw new RuntimeException('Não tem permissão para atribuir tarefas.', 403);
            }
            validateSelection($property, $room);
            $submitted = $_POST['assignee'] ?? [];
            if (!is_array($submitted)) {
                throw new InvalidArgumentException('Atribuições inválidas.');
            }

            $employeeIds = array_map('intval', $pdo->query(
                "SELECT id FROM users WHERE role = 'empregada_andares' AND is_active = 1"
            )->fetchAll(PDO::FETCH_COLUMN));
            $allowedEmployees = array_fill_keys($employeeIds, true);
            $pdo->beginTransaction();
            $upsert = $pdo->prepare(
                'INSERT INTO room_item_assignments
                    (property_name, room_number, item_name, assigned_to_user_id, assigned_by_user_id, completed_at, completed_by_user_id)
                 VALUES (:property, :room, :item, :assignee, :assigner, NULL, NULL)
                 ON DUPLICATE KEY UPDATE
                    completed_at = IF(assigned_to_user_id = VALUES(assigned_to_user_id), completed_at, NULL),
                    completed_by_user_id = IF(assigned_to_user_id = VALUES(assigned_to_user_id), completed_by_user_id, NULL),
                    assigned_to_user_id = VALUES(assigned_to_user_id),
                    assigned_by_user_id = VALUES(assigned_by_user_id),
                    assigned_at = CURRENT_TIMESTAMP'
            );
            $remove = $pdo->prepare(
                'DELETE FROM room_item_assignments
                 WHERE property_name = :property AND room_number = :room AND item_name = :item'
            );
            $assignedCount = 0;
            foreach (CHECKLIST_ITEMS as $item) {
                $assignee = (int) ($submitted[$item] ?? 0);
                if ($assignee === 0) {
                    $remove->execute(['property' => $property, 'room' => $room, 'item' => $item]);
                    continue;
                }
                if (!isset($allowedEmployees[$assignee])) {
                    throw new InvalidArgumentException('Foi selecionada uma empregada inválida ou inativa.');
                }
                $upsert->execute([
                    'property' => $property,
                    'room' => $room,
                    'item' => $item,
                    'assignee' => $assignee,
                    'assigner' => (int) $currentUser['id'],
                ]);
                $assignedCount++;
            }
            Auth::audit($pdo, (int) $currentUser['id'], 'room_tasks_assigned', [
                'property' => $property,
                'room' => $room,
                'assigned_items' => $assignedCount,
            ]);
            $pdo->commit();
            $message = 'Atribuições guardadas.';
        } elseif ($action === 'complete') {
            if (!$canViewOwn) {
                throw new RuntimeException('Não tem permissão para concluir tarefas.', 403);
            }
            $assignmentId = (int) ($_POST['assignment_id'] ?? 0);
            $statement = $pdo->prepare(
                'UPDATE room_item_assignments
                 SET completed_at = UTC_TIMESTAMP(), completed_by_user_id = :user_id
                 WHERE id = :id AND assigned_to_user_id = :user_id AND completed_at IS NULL'
            );
            $statement->execute(['id' => $assignmentId, 'user_id' => (int) $currentUser['id']]);
            if ($statement->rowCount() !== 1) {
                throw new InvalidArgumentException('A tarefa já foi concluída ou não lhe está atribuída.');
            }
            Auth::audit($pdo, (int) $currentUser['id'], 'room_task_completed', ['assignment_id' => $assignmentId]);
            $message = 'Item marcado como concluído.';
        } else {
            throw new InvalidArgumentException('Ação inválida.');
        }
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = $exception->getMessage();
    }
}

$employees = [];
$assignments = [];
$tasks = [];
$taskDates = [];
$taskGroups = [];
if ($canAssign) {
    $employees = $pdo->query(
        "SELECT id, display_name FROM users
         WHERE role = 'empregada_andares' AND is_active = 1
         ORDER BY display_name, username"
    )->fetchAll();
    $statement = $pdo->prepare(
        'SELECT item_name, assigned_to_user_id, completed_at
         FROM room_item_assignments
         WHERE property_name = :property AND room_number = :room'
    );
    $statement->execute(['property' => $property, 'room' => $room]);
    foreach ($statement->fetchAll() as $assignment) {
        $assignments[(string) $assignment['item_name']] = $assignment;
    }
} else {
    $statement = $pdo->prepare(
        'SELECT a.id, a.list_id, a.property_name, a.room_number,
                a.item_name AS canonical_item_name,
                CASE WHEN :locale_item = \'en\' THEN
                    COALESCE(NULLIF(TRIM(item.name_en), \'\'), a.item_name)
                ELSE COALESCE(NULLIF(TRIM(item.name), \'\'), a.item_name) END AS item_name,
                a.assigned_at, a.due_date,
                CASE WHEN :locale_instructions = \'en\' THEN
                    COALESCE(NULLIF(TRIM(a.verification_instructions_en), \'\'), NULLIF(TRIM(item.default_instructions_en), \'\'), a.verification_instructions, item.default_instructions, \'\')
                ELSE COALESCE(NULLIF(TRIM(a.verification_instructions), \'\'), NULLIF(TRIM(item.default_instructions), \'\'), a.verification_instructions_en, item.default_instructions_en, \'\') END AS verification_instructions,
                CASE WHEN :locale_list = \'en\' THEN
                    COALESCE(NULLIF(TRIM(list_row.name_en), \'\'), list_row.name)
                ELSE COALESCE(NULLIF(TRIM(list_row.name), \'\'), list_row.name_en) END AS list_name,
                list_row.area,
                CASE WHEN :locale_problem = \'en\' THEN
                    COALESCE(NULLIF(TRIM(v.problem_en), \'\'), v.problem, \'\')
                ELSE COALESCE(NULLIF(TRIM(v.problem), \'\'), v.problem_en, \'\') END AS problem,
                v.status
         FROM room_item_assignments a
         INNER JOIN item_lists list_row ON list_row.id = a.list_id
         LEFT JOIN item_list_items item
           ON item.list_id = a.list_id
          AND item.name = a.item_name
         LEFT JOIN room_checklist_values v
           ON v.list_id = a.list_id
          AND v.property_name = a.property_name
          AND v.room_number = a.room_number
          AND v.item_name = a.item_name
         WHERE a.assigned_to_user_id = :user_id AND a.completed_at IS NULL AND a.due_date = :due_date
         ORDER BY a.property_name, a.room_number, list_row.name, a.item_name'
    );
    $locale = Translator::locale();
    $statement->execute([
        'locale_item' => $locale,
        'locale_instructions' => $locale,
        'locale_list' => $locale,
        'locale_problem' => $locale,
        'user_id' => (int) $currentUser['id'],
        'due_date' => $selectedDateValue,
    ]);
    $tasks = $statement->fetchAll();
    $dateStatement = $pdo->prepare(
        'SELECT DISTINCT due_date FROM room_item_assignments
         WHERE assigned_to_user_id = :user_id AND completed_at IS NULL
         ORDER BY due_date'
    );
    $dateStatement->execute(['user_id' => (int) $currentUser['id']]);
    $taskDates = array_fill_keys(array_map('strval', $dateStatement->fetchAll(PDO::FETCH_COLUMN)), true);
    foreach ($tasks as $task) {
        $groupKey = (string) $task['property_name'] . "\0" . (string) $task['room_number'];
        if (!isset($taskGroups[$groupKey])) {
            $taskGroups[$groupKey] = [
                'property' => (string) $task['property_name'],
                'room' => (int) $task['room_number'],
                'items' => [],
            ];
        }
        $taskGroups[$groupKey]['items'][] = $task;
    }
}

$monthNames = [1 => 'Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];
$calendarStart = $calendarMonth->modify('first day of this month');
$calendarDays = (int) $calendarStart->format('t');
$calendarOffset = (int) $calendarStart->format('N') - 1;
$previousMonth = $calendarStart->modify('-1 month')->format('Y-m');
$nextMonth = $calendarStart->modify('+1 month')->format('Y-m');

$canManageUsers = Auth::hasPermission($pdo, $currentUser, Auth::PERMISSION_USERS_MANAGE);
$canManagePermissions = Auth::hasPermission($pdo, $currentUser, Auth::PERMISSION_PERMISSIONS_MANAGE);
header('Cache-Control: no-store');
function taskEscape(string $value): string { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html>
<html lang="pt">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <meta name="theme-color" content="#0f766e">
    <title>Tarefas dos Quartos — <?= htmlspecialchars(PortalBrand::name(), ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="stylesheet" href="assets/tasks.css?v=<?= (int) filemtime(__DIR__ . '/assets/tasks.css') ?>-employee-calendar-1">
    <link rel="stylesheet" href="assets/session.css?v=<?= (int) filemtime(__DIR__ . '/assets/session.css') ?>">
</head>
<body>
<main class="tasks-shell">
    <?php SessionBar::render($currentUser, '', $canManageUsers, $canManagePermissions); ?>
    <header class="tasks-header compact-page-header">
        <div class="compact-page-heading">
            <p class="eyebrow">Operações de verificação</p>
            <h1 class="page-title"><?= $canAssign ? 'Atribuir itens a verificar' : 'Os meus itens a verificar' ?></h1>
        </div>
    </header>
    <?php if ($message): ?><div class="notice success" role="status"><?= taskEscape($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="notice error" role="alert"><?= taskEscape($error) ?></div><?php endif; ?>

    <?php if ($canAssign): ?>
        <form method="get" class="selectors">
            <label><span>Alojamento</span><select name="property" onchange="this.form.submit()">
                <?php foreach (PROPERTIES as $name => $count): ?><option value="<?= taskEscape($name) ?>" <?= $name === $property ? 'selected' : '' ?>><?= taskEscape($name) ?></option><?php endforeach; ?>
            </select></label>
            <label><span>Quarto</span><select name="room" onchange="this.form.submit()">
                <?php for ($number = 1; $number <= PROPERTIES[$property]; $number++): ?><option value="<?= $number ?>" <?= $number === $room ? 'selected' : '' ?>><?= $number ?></option><?php endfor; ?>
            </select></label>
        </form>
        <?php if ($employees === []): ?>
            <section class="empty-state"><h2>Sem empregadas ativas</h2><p>Crie ou ative uma conta com o perfil Empregada de Andares antes de atribuir itens.</p></section>
        <?php else: ?>
            <form method="post" class="assignment-card">
                <input type="hidden" name="csrf_token" value="<?= taskEscape(Csrf::token()) ?>">
                <input type="hidden" name="action" value="save_assignments">
                <input type="hidden" name="property" value="<?= taskEscape($property) ?>">
                <input type="hidden" name="room" value="<?= $room ?>">
                <div class="assignment-heading"><span>Item</span><span>Empregada responsável</span></div>
                <?php foreach (CHECKLIST_ITEMS as $item): $assignment = $assignments[$item] ?? null; ?>
                    <label class="assignment-row">
                        <span class="item-name"><?= taskEscape($item) ?><?php if (($assignment['completed_at'] ?? null) !== null): ?><small>Concluído</small><?php endif; ?></span>
                        <select name="assignee[<?= taskEscape($item) ?>]">
                            <option value="0">Não atribuído</option>
                            <?php foreach ($employees as $employee): ?><option value="<?= (int) $employee['id'] ?>" <?= (int) ($assignment['assigned_to_user_id'] ?? 0) === (int) $employee['id'] ? 'selected' : '' ?>><?= taskEscape((string) $employee['display_name']) ?></option><?php endforeach; ?>
                        </select>
                    </label>
                <?php endforeach; ?>
                <div class="save-bar"><button type="submit">Guardar atribuições</button></div>
            </form>
        <?php endif; ?>
    <?php else: ?>
        <section class="task-calendar" aria-label="Calendário de itens atribuídos">
            <div class="calendar-heading">
                <a href="tasks.php?date=<?= taskEscape($selectedDateValue) ?>&amp;month=<?= $previousMonth ?>" aria-label="Mês anterior">‹</a>
                <h2><?= $monthNames[(int) $calendarStart->format('n')] ?> <?= $calendarStart->format('Y') ?></h2>
                <a href="tasks.php?date=<?= taskEscape($selectedDateValue) ?>&amp;month=<?= $nextMonth ?>" aria-label="Mês seguinte">›</a>
            </div>
            <div class="calendar-weekdays" aria-hidden="true"><span>Seg</span><span>Ter</span><span>Qua</span><span>Qui</span><span>Sex</span><span>Sáb</span><span>Dom</span></div>
            <div class="calendar-days">
                <?php for ($blank = 0; $blank < $calendarOffset; $blank++): ?><span class="calendar-blank"></span><?php endfor; ?>
                <?php for ($day = 1; $day <= $calendarDays; $day++):
                    $dayValue = $calendarStart->setDate((int) $calendarStart->format('Y'), (int) $calendarStart->format('n'), $day)->format('Y-m-d');
                    $hasTasks = isset($taskDates[$dayValue]);
                    $isSelected = $dayValue === $selectedDateValue;
                    $isToday = $dayValue === $today->format('Y-m-d');
                ?>
                    <a class="calendar-day<?= $hasTasks ? ' has-tasks' : '' ?><?= $isSelected ? ' selected' : '' ?><?= $isToday ? ' today' : '' ?>" href="tasks.php?date=<?= $dayValue ?>&amp;month=<?= $calendarMonthValue ?>" <?= $isSelected ? 'aria-current="date"' : '' ?>><?= $day ?></a>
                <?php endfor; ?>
            </div>
            <p class="calendar-legend"><span></span> Dia com itens atribuídos</p>
        </section>
        <div class="selected-day-heading">
            <span>Itens de</span>
            <strong><?= taskEscape($selectedDate->format('d/m/Y')) ?><?= $selectedDateValue === $today->format('Y-m-d') ? ' — Hoje' : '' ?></strong>
        </div>
        <?php if ($tasks === []): ?>
            <section class="empty-state"><h2>Sem itens para esta data</h2><p>Escolha no calendário um dia assinalado a verde para consultar os itens atribuídos.</p></section>
        <?php else: ?>
            <div class="room-task-groups">
            <?php foreach ($taskGroups as $group): ?>
                <section class="room-task-group" aria-label="<?= taskEscape($group['property']) ?>, quarto <?= $group['room'] ?>">
                    <header class="room-group-heading"><span><?= taskEscape($group['property']) ?></span><strong>Quarto <?= $group['room'] ?></strong><small><?= count($group['items']) ?> <?= count($group['items']) === 1 ? 'item' : 'itens' ?></small></header>
                    <div class="task-list">
                <?php foreach ($group['items'] as $task): ?>
                    <article class="task-card">
                        <p class="task-list-name"><?= taskEscape((string) $task['list_name']) ?></p>
                        <h2><?= taskEscape((string) $task['item_name']) ?></h2>
                        <?php if (trim((string) ($task['verification_instructions'] ?? '')) !== ''): ?><div class="instructions"><strong>Instruções da verificação</strong><p><?= nl2br(taskEscape((string) $task['verification_instructions'])) ?></p></div><?php endif; ?>
                        <?php
                            $taskProblem = trim((string) ($task['problem'] ?? ''));
                            $taskInstructions = trim((string) ($task['verification_instructions'] ?? ''));
                        ?>
                        <?php if ($taskProblem !== '' && $taskProblem !== $taskInstructions): ?><p class="problem"><?= nl2br(taskEscape($taskProblem)) ?></p><?php endif; ?>
                        <div class="task-actions">
                            <a href="rooms.php?area=<?= rawurlencode((string) $task['area']) ?>&amp;property=<?= rawurlencode((string) $task['property_name']) ?>&amp;room=<?= (int) $task['room_number'] ?>&amp;list_id=<?= (int) $task['list_id'] ?>">Abrir espaço</a>
                            <form method="post">
                                <input type="hidden" name="csrf_token" value="<?= taskEscape(Csrf::token()) ?>">
                                <input type="hidden" name="action" value="complete">
                                <input type="hidden" name="assignment_id" value="<?= (int) $task['id'] ?>">
                                <input type="hidden" name="date" value="<?= taskEscape($selectedDateValue) ?>">
                                <input type="hidden" name="month" value="<?= taskEscape($calendarMonthValue) ?>">
                                <button type="submit">Marcar concluído</button>
                            </form>
                        </div>
                    </article>
                <?php endforeach; ?>
                    </div>
                </section>
            <?php endforeach; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</main>
</body>
</html>
