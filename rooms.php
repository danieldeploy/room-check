<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';
$config = require __DIR__ . '/config.php';
require_once __DIR__ . '/src/Auth/Auth.php';
require_once __DIR__ . '/src/Security/Csrf.php';
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
?>
<!doctype html>
<html lang="pt">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#0f766e">
    <title>Gestão dos Quartos — Welcome Hostel</title>
    <link rel="stylesheet" href="assets/app.css">
    <link rel="stylesheet" href="assets/session.css">
    <script>
        window.ROOM_CHECK = <?= json_encode([
            'properties' => PROPERTIES,
            'items' => CHECKLIST_ITEMS,
            'canEdit' => $canEdit,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    </script>
    <script src="assets/app.js" defer></script>
</head>
<body>
    <main class="app-shell">
        <nav class="session-bar" aria-label="Sessão">
            <div class="session-user"><strong><?= htmlspecialchars($currentUser['display_name'], ENT_QUOTES, 'UTF-8') ?></strong><span><?= htmlspecialchars(Auth::ROLES[$currentUser['role']], ENT_QUOTES, 'UTF-8') ?></span></div>
            <div class="session-actions">
                <a href="index.php">Portal</a>
                <form method="post" action="logout.php"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Csrf::token(), ENT_QUOTES, 'UTF-8') ?>"><button type="submit">Sair</button></form>
            </div>
        </nav>
        <header class="hero">
            <div>
                <p class="eyebrow">Guest house operations</p>
                <h1>Room Check</h1>
                <p class="subtitle">Lista de verificação dos quartos<?= $canEdit ? '' : ' — apenas consulta' ?></p>
            </div>
            <div id="saveStatus" class="save-status" role="status" aria-live="polite">A carregar…</div>
        </header>
        <section class="selectors" aria-label="Selecionar alojamento e quarto">
            <label><span>Room</span><select id="roomSelect" aria-label="Quarto"></select></label>
            <label class="property-field">
                <span>Guest House</span>
                <select id="propertySelect" aria-label="Alojamento">
                    <?php foreach (PROPERTIES as $property => $roomCount): ?>
                        <option value="<?= htmlspecialchars($property, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($property, ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </section>
        <section class="checklist-card">
            <div class="table-heading" aria-hidden="true"><span>Item to check</span><span>Problema identificado</span><span>Estado</span></div>
            <div id="checklist" class="checklist"></div>
        </section>
        <noscript>Esta aplicação necessita de JavaScript para carregar os dados.</noscript>
    </main>
</body>
</html>
