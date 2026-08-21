<?php
declare(strict_types=1);

$config = require dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/src/Auth/AdminGuard.php';
require_once dirname(__DIR__) . '/src/Security/Csrf.php';
require_once dirname(__DIR__) . '/src/UI/SessionBar.php';
require_once dirname(__DIR__) . '/src/My2N/My2NClient.php';
require_once dirname(__DIR__) . '/src/My2N/My2NCredentialStore.php';

try {
    $user = AdminGuard::requirePermission($config, 'my2n.view');
} catch (RuntimeException $exception) {
    if ($exception->getCode() === 401) {
        header('Location: ../login.php');
        exit;
    }
    http_response_code(in_array($exception->getCode(), [403, 503], true) ? $exception->getCode() : 500);
    header('Content-Type: text/plain; charset=utf-8');
    echo $exception->getMessage();
    exit;
}
$pdo = database();
$canManageUsers = Auth::hasPermission($pdo, $user, Auth::PERMISSION_USERS_MANAGE);
$canManagePermissions = Auth::hasPermission($pdo, $user, Auth::PERMISSION_PERMISSIONS_MANAGE);
$canManageCredentials = Auth::hasPermission($pdo, $user, Auth::PERMISSION_MY2N_CREDENTIALS);
$canControl = Auth::hasPermission($pdo, $user, Auth::PERMISSION_MY2N_CONTROL);
$credentialStore = new My2NCredentialStore((string) ($config['my2n']['secrets_file'] ?? ''));
$credentialMessage = isset($_GET['credentials']) && $_GET['credentials'] === 'saved'
    ? 'Login My2N guardado e validado.'
    : null;
$credentialError = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        if (!$canManageCredentials) {
            throw new RuntimeException('Não tem permissão para gerir o login My2N.', 403);
        }
        Csrf::validate($_POST['csrf_token'] ?? null);
        if (($_POST['action'] ?? '') !== 'save_credentials') {
            throw new InvalidArgumentException('Ação inválida.');
        }

        $identifier = trim((string) ($_POST['identifier'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $candidateClient = new My2NClient($config['my2n'], [
            'identifier' => $identifier,
            'password' => $password,
        ]);
        $candidateClient->authenticate();
        $credentialStore->save($identifier, $password);
        Auth::audit($pdo, (int) $user['id'], 'my2n_credentials_updated', [
            'identifier_key' => hash('sha256', mb_strtolower($identifier)),
        ]);
        header('Location: my2n.php?credentials=saved');
        exit;
    } catch (Throwable $exception) {
        $credentialError = $exception->getMessage();
    }
}

$maskedIdentifier = $credentialStore->maskedIdentifier();
$credentialsConfigured = $credentialStore->isConfigured();
header('Cache-Control: no-store');
?>
<!doctype html>
<html lang="pt">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>Controlo My2N — Active Lines Unip. Lda.</title>
    <link rel="stylesheet" href="assets/my2n.css?v=<?= (int) filemtime(__DIR__ . '/assets/my2n.css') ?>">
    <link rel="stylesheet" href="../assets/session.css?v=<?= (int) filemtime(dirname(__DIR__) . '/assets/session.css') ?>">
    <script>
        window.MY2N_PANEL = <?= json_encode([
            'statusUrl' => 'api/my2n-status.php',
            'membersUrl' => 'api/my2n-members.php',
            'csrfToken' => Csrf::token(),
            'canControl' => $canControl,
            'writesEnabled' => ($config['my2n']['allow_writes'] ?? false) === true,
        ], JSON_UNESCAPED_SLASHES) ?>;
    </script>
    <script src="assets/my2n.js?v=<?= (int) filemtime(__DIR__ . '/assets/my2n.js') ?>" defer></script>
</head>
<body>
    <main class="panel-shell">
        <?php SessionBar::render($user, '..', $canManageUsers, $canManagePermissions); ?>

        <header class="panel-header">
            <div>
                <p class="eyebrow">Painel administrativo</p>
                <h1>Controlo My2N</h1>
                <p>Campainhas, apartamentos e telemóveis do único Site My2N.</p>
            </div>
            <span id="modeBadge" class="mode-badge">APENAS CONSULTA</span>
        </header>

        <?php if ($canManageCredentials): ?>
            <section class="card credentials-card">
                <div class="card-heading">
                    <div>
                        <h2>Login da conta My2N</h2>
                        <p>Conta técnica utilizada pelo portal. A password fica num ficheiro privado fora de <code>public_html</code>.</p>
                    </div>
                    <span class="credential-state <?= $credentialsConfigured ? 'configured' : '' ?>">
                        <?= $credentialsConfigured ? 'CONFIGURADO' : 'POR CONFIGURAR' ?>
                    </span>
                </div>
                <?php if ($credentialMessage !== null): ?><div class="form-message success"><?= htmlspecialchars($credentialMessage, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
                <?php if ($credentialError !== null): ?><div class="form-message error"><?= htmlspecialchars($credentialError, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
                <?php if ($maskedIdentifier !== null): ?><p class="configured-login">Login atual: <strong><?= htmlspecialchars($maskedIdentifier, ENT_QUOTES, 'UTF-8') ?></strong></p><?php endif; ?>
                <form class="credential-form" method="post" autocomplete="off">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Csrf::token(), ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="action" value="save_credentials">
                    <label><span>Login My2N</span><input type="text" name="identifier" autocomplete="username" maxlength="190" required></label>
                    <label><span>Password My2N</span><input type="password" name="password" autocomplete="current-password" maxlength="2048" required></label>
                    <button type="submit">Validar e guardar</button>
                </form>
                <p class="security-note">Use uma conta My2N própria para a integração e sem MFA. A password nunca volta a ser mostrada.</p>
            </section>
        <?php endif; ?>

        <section class="summary" aria-live="polite">
            <div><span>Site</span><strong id="siteId">—</strong></div>
            <div><span>Campainhas</span><strong id="bellCount">—</strong></div>
            <div><span>Última leitura</span><strong id="readAt">—</strong></div>
        </section>

        <section class="card destination-card">
            <div class="card-heading">
                <div>
                    <h2>Campainhas e destinatários</h2>
                    <p>Campainhas, apartamentos e telemóveis são lidos automaticamente da My2N sempre que atualizar.</p>
                </div>
                <button id="refreshButton" type="button">Atualizar</button>
            </div>

            <div id="panelStatus" class="panel-status" role="status">A carregar…</div>
            <form id="destinationForm">
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Campainha</th>
                                <th>Ap. campainha</th>
                                <th>Telemóvel</th>
                                <th>Ap. telemóvel</th>
                                <th>Estado</th>
                                <th>SIP</th>
                                <th>Recebe chamadas</th>
                            </tr>
                        </thead>
                        <tbody id="deviceRows"></tbody>
                    </table>
                </div>
                <div class="destination-actions">
                    <p id="selectionSummary">Carregue os dados My2N para gerir os destinatários.</p>
                    <?php if ($canControl): ?>
                        <button id="saveMembersButton" type="submit" disabled>Guardar destinatários</button>
                    <?php endif; ?>
                </div>
                <?php if ($canControl && ($config['my2n']['allow_writes'] ?? false) !== true): ?>
                    <p class="write-guard">A seleção está disponível para preparação, mas a gravação continua bloqueada no servidor até ao primeiro teste autorizado.</p>
                <?php endif; ?>
            </form>
        </section>
    </main>
</body>
</html>
