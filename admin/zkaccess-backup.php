<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/lib.php';
$config = require $root . '/config.php';
require_once $root . '/src/Auth/Auth.php';
require_once $root . '/src/Security/Csrf.php';
require_once $root . '/src/UI/SessionBar.php';
require_once $root . '/src/ZKAccess/BackupStore.php';
require_once $root . '/src/ZKAccess/BackupDestination.php';

try {
    $pdo = database();
    $user = Auth::requirePermission($pdo, $config, Auth::PERMISSION_ZKACCESS_VIEW);
} catch (RuntimeException $e) {
    if ($e->getCode() === 401) { header('Location: ../login.php'); exit; }
    http_response_code(403); exit('Sem permissão');
}
$canConfigure = Auth::hasPermission($pdo, $user, Auth::PERMISSION_ZKACCESS_CONFIGURE);
$canManageUsers = Auth::hasPermission($pdo, $user, Auth::PERMISSION_USERS_MANAGE);
$canManagePermissions = Auth::hasPermission($pdo, $user, Auth::PERMISSION_PERMISSIONS_MANAGE);
$store = null;
$error = null;
$message = null;
try {
    $store = new ZKAccessBackupStore(new InvoiceVault((string)$config['invoices']['private_dir']));
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        Csrf::validate($_POST['csrf_token'] ?? null);
        if (!$canConfigure) throw new RuntimeException('Sem permissão para configurar backups.', 403);
        if (($_POST['action'] ?? '') === 'run') {
            $store->requestNow();
            Auth::audit($pdo, (int)$user['id'], 'zkaccess_backup_manual_requested', []);
            $message = 'Backup manual solicitado. O executor Windows verificará o pedido.';
        } else {
            $time = ZKAccessBackupStore::validateTime((string)($_POST['time'] ?? ''));
            $enabled = isset($_POST['enabled']) && $_POST['enabled'] === '1';
            $store->saveSettings($enabled, $time);
            Auth::audit($pdo, (int)$user['id'], 'zkaccess_backup_settings_updated',
                ['enabled' => $enabled, 'time' => $time, 'timezone' => 'Europe/Lisbon']);
            $message = 'Configuração do backup guardada.';
        }
    }
    $settings = $store->settings();
    $backups = $store->status();
} catch (Throwable) {
    $error = 'O armazenamento privado dos backups não está disponível.';
    $settings = ['enabled' => false, 'time' => '04:00'];
    $backups = [];
}
header('Cache-Control: no-store');
function backupEsc(string $text): string { return htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html>
<html lang="pt">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>Backup ZKTeco — Management Hub</title>
    <link rel="stylesheet" href="assets/settings.css">
    <link rel="stylesheet" href="../assets/session.css?v=<?= (int)filemtime($root . '/assets/session.css') ?>">
</head>
<body>
<main class="settings-shell">
    <?php SessionBar::render($user, '..', $canManageUsers, $canManagePermissions); ?>
    <header class="page-header compact-page-header">
        <div class="compact-page-heading">
            <p class="eyebrow">Management Hub / ZKTeco</p>
            <h1 class="page-title">Backup da base de dados</h1>
        </div>
    </header>
    <?php if ($error): ?><div class="alert" role="alert"><?= backupEsc($error) ?></div><?php endif; ?>
    <?php if ($message): ?><div class="success" role="status"><?= backupEsc($message) ?></div><?php endif; ?>

    <section class="card">
        <h2>Armazenamento</h2>
        <p>Base de dados <strong>zkeco_db</strong>, com MySQL e bloqueio consistente de tabelas MyISAM.
           O arquivo é cifrado no Hub antes de ser guardado no Google Drive.</p>
        <p><a href="https://drive.google.com/drive/folders/1dAMy6v2WdgcBgho0BiXi0-_tE-KVt9jG" rel="noopener noreferrer">Google Drive / Management Hub / ZKTeco Backup</a></p>
        <div class="status-grid">
            <div class="status-item"><span>Agendamento</span><strong><?= backupEsc($settings['time']) ?> (Lisboa)</strong></div>
            <div class="status-item"><span>Retenção no Drive</span><strong>30 dias</strong></div>
            <div class="status-item"><span>Regra de execução</span><strong><?= $settings['enabled'] ? 'Ativada no Hub' : 'Pausada' ?></strong></div>
            <div class="status-item"><span>Backups confirmados no Drive</span><strong><?= count(array_filter($backups, static fn(array $b): bool => $b['status'] === 'uploaded')) ?></strong></div>
        </div>
    </section>

    <section class="card">
        <h2>Configuração</h2>
        <p>O horário do backup é independente do horário da atualização dos PINs.</p>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= backupEsc(Csrf::token()) ?>">
            <div class="form-grid">
                <label class="field"><span>Hora diária (Europe/Lisbon)</span>
                    <input type="time" name="time" required value="<?= backupEsc($settings['time']) ?>" <?= $canConfigure && !$error ? '' : 'disabled' ?>></label>
                <label class="check-row">
                    <input type="checkbox" name="enabled" value="1" <?= $settings['enabled'] ? 'checked' : '' ?> <?= $canConfigure && !$error ? '' : 'disabled' ?>>
                    <span class="check-copy"><strong>Ativar agendamento</strong>
                        <small>Requer uma tarefa Windows instalada e uma ligação válida ao Hub.</small></span>
                </label>
            </div>
            <div class="form-actions"><button class="primary-button" type="submit" <?= $canConfigure && !$error ? '' : 'disabled' ?>>Guardar configuração</button></div>
        </form>
    </section>

    <section class="card">
        <h2>Execução manual</h2>
        <p>O pedido é enviado ao agente Windows emparelhado, sem abrir o ZKAccess.</p>
        <?php if (!empty($settings['manual_id'])): ?><p>Existe um pedido manual pendente.</p><?php endif; ?>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= backupEsc(Csrf::token()) ?>">
            <input type="hidden" name="action" value="run">
            <div class="form-actions"><button class="primary-button" type="submit" <?= $canConfigure && !$error ? '' : 'disabled' ?>>Fazer backup agora</button></div>
        </form>
    </section>

    <section class="card">
        <h2>Histórico de cópias</h2>
        <?php if (!$backups): ?><p>Ainda não existe uma cópia da base de dados verificada.</p><?php else: ?>
            <table><thead><tr><th>Data UTC</th><th>Tamanho comprimido</th><th>Estado</th></tr></thead>
            <tbody>
            <?php foreach (array_slice($backups,0,30) as $backup): ?>
                <tr><td><?= backupEsc($backup['created_at']) ?></td>
                    <td><?= number_format((int)$backup['size']/1048576, 1, ',', '.') ?> MB</td>
                    <td><?= backupEsc(match($backup['status']) {
                        'uploaded' => 'Verificado no Google Drive',
                        'ready' => 'Cifrado, aguarda envio ao Drive',
                        'receiving' => 'Transferência em curso',
                        default => 'Pendente'
                    }) ?></td></tr>
            <?php endforeach; ?>
            </tbody></table>
        <?php endif; ?>
        <p>O estado Verificado no Google Drive confirma tamanho e checksum do ficheiro cifrado;
            uma recuperação integral requer ainda o teste de restauro independente.</p>
    </section>
</main>
</body>
</html>
