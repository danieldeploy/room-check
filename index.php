<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';
?>
<!doctype html>
<html lang="pt">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#0f766e">
    <title>Room Check</title>
    <link rel="stylesheet" href="assets/app.css">
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
