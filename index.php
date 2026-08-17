<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';
$config = require __DIR__ . '/config.php';
require_once __DIR__ . '/src/Auth/Auth.php';
require_once __DIR__ . '/src/Security/Csrf.php';
try {
    $currentUser = Auth::requireLogin(database(), $config);
} catch (RuntimeException) {
    header('Location: login.php');
    exit;
}
?>
<!doctype html>
<html lang="pt">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#0f766e">
    <title>Room Check</title>
    <link rel="stylesheet" href="assets/app.css">
    <link rel="stylesheet" href="assets/session.css">
    <script>
        window.ROOM_CHECK = <?= json_encode([
            'properties' => PROPERTIES,
            'items' => CHECKLIST_ITEMS,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    </script>
    <script src="assets/app.js" defer></script>
</head>
<body>
    <main class="app-shell">
        <nav class="session-bar" aria-label="Sessão">
            <div class="session-user"><strong><?= htmlspecialchars($currentUser['display_name'], ENT_QUOTES, 'UTF-8') ?></strong><span><?= htmlspecialchars(Auth::ROLES[$currentUser['role']], ENT_QUOTES, 'UTF-8') ?></span></div>
            <div class="session-actions">
                <?php if ($currentUser['role'] === 'gerente'): ?><a href="admin/users.php">Utilizadores</a><?php endif; ?>
                <?php if (in_array($currentUser['role'], ['gerente', 'governanta'], true)): ?><a href="admin/my2n.php">My2N</a><?php endif; ?>
                <form method="post" action="logout.php"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Csrf::token(), ENT_QUOTES, 'UTF-8') ?>"><button type="submit">Sair</button></form>
            </div>
        </nav>
        <header class="hero">
            <div>
                <p class="eyebrow">Guest house operations</p>
                <h1>Room Check</h1>
                <p class="subtitle">Lista de verificação dos quartos</p>
            </div>
            <div id="saveStatus" class="save-status" role="status" aria-live="polite">A carregar…</div>
        </header>

        <section class="selectors" aria-label="Selecionar alojamento e quarto">
            <label>
                <span>Room</span>
                <select id="roomSelect" aria-label="Quarto"></select>
            </label>
            <label class="property-field">
                <span>Guest House</span>
                <select id="propertySelect" aria-label="Alojamento">
                    <?php foreach (PROPERTIES as $property => $roomCount): ?>
                        <option value="<?= htmlspecialchars($property, ENT_QUOTES, 'UTF-8') ?>">
                            <?= htmlspecialchars($property, ENT_QUOTES, 'UTF-8') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
        </section>

        <section class="checklist-card">
            <div class="table-heading" aria-hidden="true">
                <span>Item to check</span>
                <span>Problema identificado</span>
                <span>Estado</span>
            </div>
            <div id="checklist" class="checklist"></div>
        </section>

        <noscript>Esta aplicação necessita de JavaScript para guardar os dados.</noscript>
    </main>
</body>
</html>
