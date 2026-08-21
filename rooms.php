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
        <section class="selectors" aria-label="Selecionar alojamento e quarto">
            <label><span>Quarto</span><select id="roomSelect" aria-label="Quarto"></select></label>
            <label class="property-field">
                <span>Alojamento</span>
                <select id="propertySelect" aria-label="Alojamento">
                    <?php foreach (PROPERTIES as $property => $roomCount): ?>
                        <option value="<?= htmlspecialchars($property, ENT_QUOTES, 'UTF-8') ?>" <?= $property === $initialProperty ? 'selected' : '' ?>><?= htmlspecialchars($property, ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </section>
        <section class="checklist-card">
            <div class="table-heading" aria-hidden="true"><span>Item a verificar</span><span>Problema identificado</span><span>Estado</span></div>
            <div id="checklist" class="checklist"></div>
        </section>
        <noscript>Esta aplicação necessita de JavaScript para carregar os dados.</noscript>
    </main>
</body>
</html>
