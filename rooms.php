<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';
$config = require __DIR__ . '/config.php';
require_once __DIR__ . '/src/Auth/Auth.php';
require_once __DIR__ . '/src/Security/Csrf.php';
require_once __DIR__ . '/src/UI/SessionBar.php';
try {
    $pdo = database();
    $currentUser = Auth::requirePermission($pdo, $config, Auth::PERMISSION_ROOM_CHECK_VIEW);
} catch (RuntimeException $exception) {
    if ($exception->getCode() === 401) {
        header('Location: login.php');
        exit;
    }
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit($exception->getMessage());
}
$canEdit = Auth::hasPermission($pdo, $currentUser, Auth::PERMISSION_ROOM_CHECK_EDIT);
$canAssign = Auth::hasPermission($pdo, $currentUser, Auth::PERMISSION_TASK_ASSIGN);
$employees = $canAssign ? $pdo->query(
    "SELECT id, display_name FROM users
     WHERE role = 'empregada_andares' AND is_active = 1
     ORDER BY display_name, username"
)->fetchAll() : [];
$intervals = $canAssign ? $pdo->query(
    'SELECT id, name, start_date, end_date
     FROM room_verification_intervals
     ORDER BY start_date DESC, id DESC'
)->fetchAll() : [];
$canManageUsers = Auth::hasPermission($pdo, $currentUser, Auth::PERMISSION_USERS_MANAGE);
$canManagePermissions = Auth::hasPermission($pdo, $currentUser, Auth::PERMISSION_PERMISSIONS_MANAGE);
$initialProperty = trim((string) ($_GET['property'] ?? array_key_first(PROPERTIES)));
$initialRoom = (int) ($_GET['room'] ?? 1);
try {
    validateSelection($initialProperty, $initialRoom);
} catch (InvalidArgumentException) {
    $initialProperty = (string) array_key_first(PROPERTIES);
    $initialRoom = 1;
}
?>
<!doctype html>
<html lang="pt">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#0f766e">
    <title>Gestão dos Quartos — Active Lines Unip. Lda.</title>
    <link rel="stylesheet" href="assets/app.css">
    <link rel="stylesheet" href="assets/session.css">
    <script>
        window.ROOM_CHECK = <?= json_encode([
            'properties' => PROPERTIES,
            'items' => CHECKLIST_ITEMS,
            'canEdit' => $canEdit,
            'canAssign' => $canAssign,
            'employees' => $employees,
            'intervals' => array_map(static fn(array $interval): array => [
                'id' => (int) $interval['id'],
                'name' => (string) $interval['name'],
                'startDate' => (string) $interval['start_date'],
                'endDate' => (string) $interval['end_date'],
            ], $intervals),
            'csrfToken' => Csrf::token(),
            'today' => (new DateTimeImmutable('now', new DateTimeZone('Europe/Lisbon')))->format('Y-m-d'),
            'initialProperty' => $initialProperty,
            'initialRoom' => $initialRoom,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    </script>
    <script src="assets/app.js" defer></script>
</head>
<body>
    <main class="app-shell">
        <?php SessionBar::render($currentUser, '', $canManageUsers, $canManagePermissions); ?>
        <header class="hero">
            <div>
                <p class="eyebrow">Operações do Alojamento</p>
                <h1>Gestão dos Quartos</h1>
                <p class="subtitle">Lista de verificação dos quartos<?= $canEdit ? '' : ' — apenas consulta' ?></p>
            </div>
            <div id="saveStatus" class="save-status" role="status" aria-live="polite">A carregar…</div>
        </header>
        <?php if ($canAssign): ?>
            <details class="interval-creator">
                <summary>Criar intervalo de verificação</summary>
                <div class="interval-fields">
                    <label><span>Nome</span><input id="intervalName" type="text" maxlength="120" placeholder="Ex.: Verificação semanal"></label>
                    <label><span>Data inicial</span><input id="intervalStart" type="date"></label>
                    <label><span>Data final</span><input id="intervalEnd" type="date"></label>
                    <button id="createInterval" type="button">Criar intervalo</button>
                </div>
            </details>
        <?php endif; ?>
        <section class="selectors<?= $canAssign ? ' has-assignment' : '' ?>" aria-label="Selecionar alojamento, quarto e atribuição">
            <label class="property-field">
                <span>Alojamento</span>
                <select id="propertySelect" aria-label="Alojamento">
                    <?php foreach (PROPERTIES as $property => $roomCount): ?>
                        <option value="<?= htmlspecialchars($property, ENT_QUOTES, 'UTF-8') ?>" <?= $property === $initialProperty ? 'selected' : '' ?>><?= htmlspecialchars($property, ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label><span>Quarto</span><select id="roomSelect" aria-label="Quarto"></select></label>
            <?php if ($canAssign): ?>
                <label class="interval-field"><span>Intervalo</span>
                    <select id="intervalSelect" aria-label="Intervalo de verificação">
                        <option value="">Escolher intervalo</option>
                        <?php foreach ($intervals as $interval): ?>
                            <option value="<?= (int) $interval['id'] ?>"><?= htmlspecialchars((string) $interval['name'], ENT_QUOTES, 'UTF-8') ?> — <?= (new DateTimeImmutable((string) $interval['start_date']))->format('d/m/Y') ?> a <?= (new DateTimeImmutable((string) $interval['end_date']))->format('d/m/Y') ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="assignment-field"><span>Atribuir</span>
                    <select id="employeeSelect" aria-label="Empregada de Andares">
                        <option value="">Escolher empregada</option>
                        <?php foreach ($employees as $employee): ?>
                            <option value="<?= (int) $employee['id'] ?>"><?= htmlspecialchars((string) $employee['display_name'], ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="assignment-date-field"><span>Data da verificação</span><input id="assignmentDate" type="date" value="<?= (new DateTimeImmutable('now', new DateTimeZone('Europe/Lisbon')))->format('Y-m-d') ?>" aria-label="Data da verificação"></label>
            <?php endif; ?>
        </section>
        <section class="checklist-card">
            <div class="table-heading"><span>Item a verificar</span><span>Problema <strong>a identificar</strong></span><span>Estado</span><?php if ($canAssign): ?><label class="assignment-check select-all"><input id="selectAllItems" type="checkbox" aria-label="Selecionar todos os itens"><span></span></label><?php endif; ?></div>
            <div id="checklist" class="checklist"></div>
            <?php if ($canAssign): ?><div id="assignmentActions" class="assignment-actions" hidden><button id="saveAssignments" type="button">Guardar atribuição</button></div><?php endif; ?>
        </section>
        <noscript>Esta aplicação necessita de JavaScript para carregar os dados.</noscript>
    </main>
</body>
</html>
