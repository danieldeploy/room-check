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

$zkStatus = 'Desligada';
$zkTone = 'amber';
try {
    $zkSettings = $pdo->query('SELECT enabled, dry_run FROM zk_automation_settings WHERE id = 1')->fetch();
    if (is_array($zkSettings) && (int) $zkSettings['enabled'] === 1) {
        $zkStatus = (int) $zkSettings['dry_run'] === 1 ? 'Ativa · dry-run' : 'Ativa · live';
        $zkTone = (int) $zkSettings['dry_run'] === 1 ? 'blue' : 'green';
    }
} catch (PDOException) {
    $zkStatus = 'Por configurar';
}

$modules = [
    [
        'permission' => Auth::PERMISSION_ZKACCESS_VIEW,
        'eyebrow' => 'Automação de códigos',
        'title' => 'ZKAccess Control',
        'description' => 'Configuração e estado da automação Cloudbeds → ZKAccess V5.1 Direct POST.',
        'href' => 'admin/zkaccess.php',
        'status' => $zkStatus,
        'tone' => $zkTone,
    ],
    [
        'permission' => Auth::PERMISSION_ROOM_CHECK_VIEW,
        'eyebrow' => 'Operação diária',
        'title' => 'Gestão dos Quartos',
        'description' => 'Verificação dos quartos do City Center Guest House e Welcome Guest House.',
        'href' => 'rooms.php',
        'status' => 'Disponível',
        'tone' => 'green',
    ],
    [
        'permission' => Auth::PERMISSION_TASK_VIEW_OWN,
        'eyebrow' => 'Equipa de limpeza',
        'title' => 'Os meus itens a verificar',
        'description' => 'Consulte os itens que a Governanta lhe atribuiu.',
        'href' => 'tasks.php',
        'status' => 'Disponível',
        'tone' => 'green',
    ],
    [
        'permission' => Auth::PERMISSION_MY2N_VIEW,
        'eyebrow' => 'Campainha',
        'title' => 'My2N',
        'description' => 'Estado dos telemóveis e configuração dos destinatários da Welcome Bell.',
        'href' => 'admin/my2n.php',
        'status' => 'Consulta read-only',
        'tone' => 'blue',
    ],
];

$visibleModules = array_values(array_filter(
    $modules,
    static function (array $module) use ($pdo, $currentUser): bool {
        if (isset($module['permission'])) {
            return Auth::hasPermission($pdo, $currentUser, $module['permission']);
        }
        foreach ($module['permissions_any'] ?? [] as $permission) {
            if (Auth::hasPermission($pdo, $currentUser, $permission)) {
                return true;
            }
        }
        return false;
    }
));
$canManageUsers = Auth::hasPermission($pdo, $currentUser, Auth::PERMISSION_USERS_MANAGE);
$canManagePermissions = Auth::hasPermission($pdo, $currentUser, Auth::PERMISSION_PERMISSIONS_MANAGE);
header('Cache-Control: no-store');
?>
<!doctype html>
<html lang="pt">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <meta name="theme-color" content="#102a43">
    <title>Portal de Operações — Active Lines Unip. Lda.</title>
    <link rel="stylesheet" href="assets/portal.css">
    <link rel="stylesheet" href="assets/session.css">
</head>
<body>
    <main class="portal-shell">
        <?php SessionBar::render($currentUser, '', $canManageUsers, $canManagePermissions); ?>
        <header class="portal-header">
            <p class="eyebrow">Portal de Operações</p>
            <h1>O que pretende gerir?</h1>
            <p>As opções apresentadas dependem das permissões do seu perfil.</p>
        </header>
        <?php if ($visibleModules === []): ?>
            <section class="empty-state"><h2>Sem módulos atribuídos</h2><p>O seu perfil está ativo, mas ainda não tem acesso a nenhum módulo.</p></section>
        <?php else: ?>
            <section class="module-grid" aria-label="Módulos disponíveis">
                <?php foreach ($visibleModules as $module): ?>
                    <a class="module-card" href="<?= htmlspecialchars($module['href'], ENT_QUOTES, 'UTF-8') ?>">
                        <div class="module-heading">
                            <p class="eyebrow"><?= htmlspecialchars($module['eyebrow'], ENT_QUOTES, 'UTF-8') ?></p>
                            <span class="status <?= htmlspecialchars($module['tone'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($module['status'], ENT_QUOTES, 'UTF-8') ?></span>
                        </div>
                        <h2><?= htmlspecialchars($module['title'], ENT_QUOTES, 'UTF-8') ?></h2>
                        <p><?= htmlspecialchars($module['description'], ENT_QUOTES, 'UTF-8') ?></p>
                        <span class="open-link">Abrir módulo →</span>
                    </a>
                <?php endforeach; ?>
            </section>
        <?php endif; ?>
    </main>
</body>
</html>
