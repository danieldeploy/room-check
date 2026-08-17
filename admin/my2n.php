<?php
declare(strict_types=1);

$config = require dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/src/Auth/AdminGuard.php';
require_once dirname(__DIR__) . '/src/Security/Csrf.php';

try {
    $user = AdminGuard::requireAdmin($config);
} catch (RuntimeException $exception) {
    http_response_code(in_array($exception->getCode(), [403, 503], true) ? $exception->getCode() : 403);
    header('Content-Type: text/plain; charset=utf-8');
    echo $exception->getMessage();
    exit;
}
?>
<!doctype html>
<html lang="pt">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>My2N Control — Room Check</title>
    <link rel="stylesheet" href="assets/my2n.css">
    <script>
        window.MY2N_PANEL = <?= json_encode([
            'statusUrl' => 'api/my2n-status.php',
            'csrfToken' => Csrf::token(),
        ], JSON_UNESCAPED_SLASHES) ?>;
    </script>
    <script src="assets/my2n.js" defer></script>
</head>
<body>
    <main class="panel-shell">
        <nav class="topbar" aria-label="Navegação administrativa">
            <div><a href="../index.php">← Gestão de quartos</a><?php if (($user['role'] ?? '') === 'gerente'): ?> · <a href="users.php">Utilizadores</a><?php endif; ?></div>
            <span><?= htmlspecialchars((string) ($user['name'] ?? $user['username'] ?? 'Admin'), ENT_QUOTES, 'UTF-8') ?></span>
        </nav>

        <header class="panel-header">
            <div>
                <p class="eyebrow">Painel administrativo</p>
                <h1>My2N Control</h1>
                <p>Estado dos telemóveis e destinatários atuais da Welcome Bell.</p>
            </div>
            <span id="modeBadge" class="mode-badge">READ-ONLY</span>
        </header>

        <section class="summary" aria-live="polite">
            <div><span>Site</span><strong id="siteId">—</strong></div>
            <div><span>Grupo SIP</span><strong id="groupSip">—</strong></div>
            <div><span>Última leitura</span><strong id="readAt">—</strong></div>
        </section>

        <section class="card">
            <div class="card-heading">
                <div>
                    <h2>Aparelhos MOBILE_VIDEO</h2>
                    <p>Esta primeira versão apenas consulta dados. Não executa PUT.</p>
                </div>
                <button id="refreshButton" type="button">Atualizar</button>
            </div>

            <div id="panelStatus" class="panel-status" role="status">A carregar…</div>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Nome</th>
                            <th>Estado</th>
                            <th>Device ID</th>
                            <th>Member ID</th>
                            <th>SIP</th>
                            <th>No grupo</th>
                        </tr>
                    </thead>
                    <tbody id="deviceRows"></tbody>
                </table>
            </div>
        </section>
    </main>
</body>
</html>
