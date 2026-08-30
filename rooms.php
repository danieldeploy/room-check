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
$navigationAreas = ['rooms', 'shared_bathrooms', 'corridors', 'kitchens', 'terraces'];
$navigationArea = (string) ($_GET['area'] ?? 'rooms');
if (!in_array($navigationArea, $navigationAreas, true)) {
    $navigationArea = 'rooms';
}
$lists = array_values(array_filter(
    itemLists($pdo),
    static fn(array $list): bool => $list['area'] === $navigationArea
));
foreach ($lists as &$list) {
    $list['displayName'] = Translator::localized(
        (string) ($list['name'] ?? ''),
        (string) ($list['nameEn'] ?? '')
    );
}
unset($list);
$initialListId = (int) ($_GET['list_id'] ?? ($lists[0]['id'] ?? 0));
if (!array_filter($lists, static fn(array $list): bool => $list['id'] === $initialListId)) {
    $initialListId = (int) ($lists[0]['id'] ?? 0);
}
$initialList = array_values(array_filter(
    $lists, static fn(array $list): bool => $list['id'] === $initialListId
))[0] ?? ['items' => [], 'defaults' => []];
$employees = $canAssign ? $pdo->query(
    "SELECT id, display_name FROM users
     WHERE role = 'empregada_andares' AND is_active = 1
     ORDER BY display_name, username"
)->fetchAll() : [];
$intervals = $canAssign ? $pdo->query(
    'SELECT interval_row.id, interval_row.name, interval_row.name_en,
            interval_row.start_date, interval_row.end_date,
            MIN(assignment.due_date) AS first_due_date,
            MAX(assignment.due_date) AS last_due_date
     FROM room_verification_intervals interval_row
     LEFT JOIN room_item_assignments assignment ON assignment.interval_id = interval_row.id
     GROUP BY interval_row.id, interval_row.name, interval_row.name_en,
              interval_row.start_date, interval_row.end_date
     ORDER BY interval_row.start_date DESC, interval_row.id DESC'
)->fetchAll() : [];
foreach ($intervals as &$interval) {
    $interval['name'] = Translator::localized(
        (string) $interval['name'],
        (string) ($interval['name_en'] ?? '')
    );
}
unset($interval);
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
    <title>Gestão dos Espaços — Active Lines Unip. Lda.</title>
    <link rel="stylesheet" href="assets/app.css?v=<?= (int) filemtime(__DIR__ . '/assets/app.css') ?>-area-navigation-1">
    <link rel="stylesheet" href="assets/session.css?v=<?= (int) filemtime(__DIR__ . '/assets/session.css') ?>">
    <script>
        window.ROOM_CHECK = <?= json_encode([
            'properties' => PROPERTIES,
            'items' => $initialList['items'],
            'itemDefaults' => $initialList['defaults'],
            'lists' => $lists,
            'initialListId' => $initialListId,
            'area' => $navigationArea,
            'canEdit' => $canEdit,
            'canAssign' => $canAssign,
            'employees' => $employees,
            'intervals' => array_map(static fn(array $interval): array => [
                'id' => (int) $interval['id'],
                'name' => (string) $interval['name'],
                'startDate' => (string) $interval['start_date'],
                'endDate' => (string) $interval['end_date'],
                'firstDueDate' => $interval['first_due_date'] !== null ? (string) $interval['first_due_date'] : null,
                'lastDueDate' => $interval['last_due_date'] !== null ? (string) $interval['last_due_date'] : null,
            ], $intervals),
            'csrfToken' => Csrf::token(),
            'locale' => Translator::locale(),
            'languageDecisionMessage' => SiteTranslations::text(
                'Existe texto incorretamente escrito em Português. Quer corrigir ou anular a edição?',
                'There is text incorrectly written in English. Do you want to correct it or cancel the edit?'
            ),
            'languageDecisionCorrect' => SiteTranslations::text('Corrigir', 'Correct'),
            'unsavedEditDecisionMessage' => SiteTranslations::text(
                'Tem uma edição não guardada. Quer continuar a editar ou anular a edição?',
                'There is an unsaved edit. Do you want to continue editing or cancel the edit?'
            ),
            'unsavedEditDecisionContinue' => SiteTranslations::text('Continuar a editar', 'Continue editing'),
            'languageDecisionCancel' => SiteTranslations::text('Anular edição', 'Cancel edit'),
            'today' => (new DateTimeImmutable('now', new DateTimeZone('Europe/Lisbon')))->format('Y-m-d'),
            'initialProperty' => $initialProperty,
            'initialRoom' => $initialRoom,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    </script>
    <script src="assets/app.js?v=<?= (int) filemtime(__DIR__ . '/assets/app.js') ?>-area-lists-1" defer></script>
</head>
<body>
    <main class="app-shell">
        <?php SessionBar::render($currentUser, '', $canManageUsers, $canManagePermissions); ?>
        <header class="hero compact-page-header">
            <div class="compact-page-heading">
                <p class="eyebrow">GESTÃO DOS ESPAÇOS</p>
                <nav class="module-tabs" aria-label="Áreas da gestão dos espaços">
                    <a class="<?= $navigationArea === 'rooms' ? 'active' : '' ?>" href="rooms.php" <?= $navigationArea === 'rooms' ? 'aria-current="page"' : '' ?>>QUARTOS</a>
                    <a class="<?= $navigationArea === 'shared_bathrooms' ? 'active' : '' ?>" href="rooms.php?area=shared_bathrooms" <?= $navigationArea === 'shared_bathrooms' ? 'aria-current="page"' : '' ?>>CASAS DE BANHO COMUNS</a>
                    <a class="<?= $navigationArea === 'corridors' ? 'active' : '' ?>" href="rooms.php?area=corridors" <?= $navigationArea === 'corridors' ? 'aria-current="page"' : '' ?>>CORREDORES</a>
                    <a class="<?= $navigationArea === 'kitchens' ? 'active' : '' ?>" href="rooms.php?area=kitchens" <?= $navigationArea === 'kitchens' ? 'aria-current="page"' : '' ?>>COZINHAS</a>
                    <a class="<?= $navigationArea === 'terraces' ? 'active' : '' ?>" href="rooms.php?area=terraces" <?= $navigationArea === 'terraces' ? 'aria-current="page"' : '' ?>>TERRAÇOS</a>
                    <?php if ($canAssign): ?><a href="item-lists.php">LISTAS DE ITENS</a><?php endif; ?>
                </nav>
            </div>
            <div id="saveStatus" class="status-announcer" role="status" aria-live="polite">A carregar…</div>
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
            <details class="interval-creator interval-editor">
                <summary>Editar intervalo de verificação</summary>
                <div id="intervalManager" class="interval-fields" aria-label="Editar intervalo de verificação">
                    <label><span>Intervalo a editar</span><select id="editIntervalSelect" aria-label="Intervalo a editar">
                        <option value="">Escolher intervalo</option>
                        <?php foreach ($intervals as $interval): ?>
                            <option value="<?= (int) $interval['id'] ?>"><?= htmlspecialchars((string) $interval['name'], ENT_QUOTES, 'UTF-8') ?> — <?= (new DateTimeImmutable((string) $interval['start_date']))->format('d/m/Y') ?> a <?= (new DateTimeImmutable((string) $interval['end_date']))->format('d/m/Y') ?></option>
                        <?php endforeach; ?>
                    </select></label>
                    <label><span>Nome</span><input id="editIntervalName" type="text" maxlength="120" disabled></label>
                    <label><span>Data inicial</span><input id="editIntervalStart" type="date" disabled></label>
                    <label><span>Data final</span><input id="editIntervalEnd" type="date" disabled></label>
                    <div class="interval-manager-actions"><button id="saveInterval" type="button" disabled>Guardar intervalo</button><button id="deleteInterval" class="danger" type="button" disabled>Apagar intervalo</button></div>
                </div>
                <p>As datas só podem ser reduzidas até continuarem a incluir todos os itens já atribuídos.</p>
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
            <?php if ($canAssign): ?>
                <label class="interval-field"><span>Intervalo</span>
                    <select id="intervalSelect" aria-label="Intervalo de verificação">
                        <option value="">Escolher intervalo</option>
                        <?php foreach ($intervals as $interval): ?>
                            <option value="<?= (int) $interval['id'] ?>"><?= htmlspecialchars((string) $interval['name'], ENT_QUOTES, 'UTF-8') ?> — <?= (new DateTimeImmutable((string) $interval['start_date']))->format('d/m/Y') ?> a <?= (new DateTimeImmutable((string) $interval['end_date']))->format('d/m/Y') ?></option>
                        <?php endforeach; ?>
                    </select>
                    <small id="intervalDates" class="interval-dates" aria-live="polite"></small>
                </label>
                <label class="room-field"><span>Quarto</span><select id="roomSelect" aria-label="Quarto"></select></label>
                <label class="assignment-field"><span>Atribuir</span>
                    <select id="employeeSelect" aria-label="Empregada de Andares">
                        <option value="">Escolher empregada</option>
                        <?php foreach ($employees as $employee): ?>
                            <option value="<?= (int) $employee['id'] ?>"><?= htmlspecialchars((string) $employee['display_name'], ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="assignment-date-field"><span>Data da verificação</span><input id="assignmentDate" type="date" value="<?= (new DateTimeImmutable('now', new DateTimeZone('Europe/Lisbon')))->format('Y-m-d') ?>" aria-label="Data da verificação"></label>
                <label class="whatsapp-reminder-field"><span>Alerta WhatsApp</span><span class="whatsapp-reminder-group"><input id="whatsappReminderEnabled" type="checkbox" aria-label="Enviar alerta WhatsApp"><input id="whatsappReminderTime" type="time" value="09:00" step="300" disabled aria-label="Hora do alerta WhatsApp"></span><small id="whatsappReminderSaved" class="row-save-feedback">Guardado</small></label>
            <?php else: ?>
                <label class="room-field"><span>Quarto</span><select id="roomSelect" aria-label="Quarto"></select></label>
            <?php endif; ?>
        </section>
        <section class="checklist-card">
            <div class="table-heading"><label class="list-heading-select"><select id="listSelect" aria-label="Lista de itens" <?= $lists === [] ? 'disabled' : '' ?>><?php if ($lists === []): ?><option value="">Sem listas nesta área</option><?php endif; ?><?php foreach ($lists as $list): ?><option value="<?= $list['id'] ?>" <?= $list['id'] === $initialListId ? 'selected' : '' ?>><?= htmlspecialchars((string) $list['displayName'], ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></label><span>Problema a identificar</span><?php if ($canAssign): ?><div class="assignment-heading-control" hidden><label class="assignment-check select-all"><input id="selectAllItems" type="checkbox" aria-label="Selecionar todos os itens"><span></span></label></div><?php else: ?><span>Estado</span><?php endif; ?></div>
            <div id="checklist" class="checklist"></div>
        </section>
        <noscript>Esta aplicação necessita de JavaScript para carregar os dados.</noscript>
    </main>
</body>
</html>