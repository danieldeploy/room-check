<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/lib.php';
$config = require $root . '/config.php';
require_once $root . '/src/Auth/Auth.php';
require_once $root . '/src/Security/Csrf.php';
require_once $root . '/src/UI/SessionBar.php';

function privateFileReady(string $path, string $publicRoot): bool
{
    if ($path === '' || $path[0] !== '/') {
        return false;
    }
    $resolved = realpath($path);
    $resolvedPublicRoot = realpath($publicRoot);
    if ($resolved === false || !is_file($resolved) || !is_readable($resolved)) {
        return false;
    }
    return $resolvedPublicRoot === false
        || ($resolved !== $resolvedPublicRoot && !str_starts_with($resolved, $resolvedPublicRoot . DIRECTORY_SEPARATOR));
}

function privateJsonConfigReady(string $path, string $publicRoot): bool
{
    if (!privateFileReady($path, $publicRoot)) {
        return false;
    }
    $size = filesize($path);
    if ($size === false || $size < 2 || $size > 65536) {
        return false;
    }
    $contents = file_get_contents($path);
    if ($contents === false) {
        return false;
    }
    try {
        $decoded = json_decode($contents, true, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return false;
    }
    if (!is_array($decoded)) {
        return false;
    }
    foreach (['cloudbeds_dashboard_url', 'zkaccess_url', 'zkaccess_username', 'zkaccess_password'] as $key) {
        if (!isset($decoded[$key]) || !is_string($decoded[$key]) || trim($decoded[$key]) === '') {
            return false;
        }
    }
    return true;
}

try {
    $pdo = database();
    $currentUser = Auth::requirePermission($pdo, $config, Auth::PERMISSION_ZKACCESS_VIEW);
} catch (RuntimeException $exception) {
    if ($exception->getCode() === 401) {
        header('Location: ../login.php');
        exit;
    }
    http_response_code(403);
    exit($exception->getMessage());
}

$canConfigure = Auth::hasPermission($pdo, $currentUser, Auth::PERMISSION_ZKACCESS_CONFIGURE);
$canManageUsers = Auth::hasPermission($pdo, $currentUser, Auth::PERMISSION_USERS_MANAGE);
$canManagePermissions = Auth::hasPermission($pdo, $currentUser, Auth::PERMISSION_PERMISSIONS_MANAGE);
$zkConfig = $config['zkaccess'] ?? [];
$runnerVersion = (string) ($zkConfig['runner_version'] ?? 'V5.1 Direct POST');
$privateConfigReady = privateJsonConfigReady((string) ($zkConfig['private_config_file'] ?? ''), $root);
$runnerStatusReady = privateFileReady((string) ($zkConfig['runner_status_file'] ?? ''), $root);
$settingsStorageAvailable = true;
$message = null;
$error = null;
$settings = [
    'enabled' => 0,
    'dry_run' => 1,
    'schedule_time' => '12:55:00',
    'room_search_term' => 'Room',
    'runner_version' => $runnerVersion,
    'last_run_at' => null,
    'last_status' => null,
    'updated_at' => null,
];

try {
    $storedSettings = $pdo->query('SELECT * FROM zk_automation_settings WHERE id = 1')->fetch();
    if (is_array($storedSettings)) {
        $settings = array_merge($settings, $storedSettings);
    }
} catch (PDOException) {
    $settingsStorageAvailable = false;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        Csrf::validate($_POST['csrf_token'] ?? null);
        if (!$canConfigure) {
            throw new RuntimeException('Não tem permissão para configurar a automação.', 403);
        }
        if (!$settingsStorageAvailable) {
            throw new RuntimeException('A tabela de configuração ainda não existe. Importe a migração 004_portal_permissions.sql.');
        }

        $enabled = isset($_POST['enabled']) ? 1 : 0;
        $dryRun = isset($_POST['dry_run']) ? 1 : 0;
        $scheduleTime = trim((string) ($_POST['schedule_time'] ?? ''));
        $roomSearchTerm = trim((string) ($_POST['room_search_term'] ?? ''));
        if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $scheduleTime)) {
            throw new InvalidArgumentException('Indique uma hora válida.');
        }
        if (!preg_match('/^[\p{L}\p{N} ._-]{1,40}$/u', $roomSearchTerm)) {
            throw new InvalidArgumentException('O termo de pesquisa deve ter 1–40 caracteres válidos.');
        }
        if ($enabled === 1 && !$privateConfigReady) {
            throw new RuntimeException('O executor privado ainda não está configurado; a automação não pode ser ativada.');
        }
        if ($dryRun === 0 && (string) ($_POST['confirm_live'] ?? '') !== '1') {
            throw new RuntimeException('Confirme explicitamente a saída do modo dry-run.');
        }

        $pdo->beginTransaction();
        $statement = $pdo->prepare(
            'INSERT INTO zk_automation_settings
                (id, enabled, dry_run, schedule_time, room_search_term, runner_version, updated_by_user_id)
             VALUES
                (1, :enabled, :dry_run, :schedule_time, :room_search_term, :runner_version, :actor)
             ON DUPLICATE KEY UPDATE
                enabled = VALUES(enabled), dry_run = VALUES(dry_run),
                schedule_time = VALUES(schedule_time), room_search_term = VALUES(room_search_term),
                runner_version = VALUES(runner_version), updated_by_user_id = VALUES(updated_by_user_id)'
        );
        $statement->execute([
            'enabled' => $enabled,
            'dry_run' => $dryRun,
            'schedule_time' => $scheduleTime . ':00',
            'room_search_term' => $roomSearchTerm,
            'runner_version' => $runnerVersion,
            'actor' => (int) $currentUser['id'],
        ]);
        Auth::audit($pdo, (int) $currentUser['id'], 'zk_automation_settings_updated', [
            'enabled' => $enabled === 1,
            'dry_run' => $dryRun === 1,
            'schedule_time' => $scheduleTime,
            'room_search_term' => $roomSearchTerm,
            'runner_version' => $runnerVersion,
        ]);
        $pdo->commit();
        $settings = array_merge($settings, [
            'enabled' => $enabled,
            'dry_run' => $dryRun,
            'schedule_time' => $scheduleTime . ':00',
            'room_search_term' => $roomSearchTerm,
            'runner_version' => $runnerVersion,
        ]);
        $message = 'Configuração da automação guardada.';
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = $exception->getMessage();
    }
}

$automationEnabled = (int) $settings['enabled'] === 1;
$dryRun = (int) $settings['dry_run'] === 1;
header('Cache-Control: no-store');
?>
<!doctype html>
<html lang="pt">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>ZKAccess — Active Lines Unip. Lda.</title>
    <link rel="stylesheet" href="assets/settings.css">
    <link rel="stylesheet" href="../assets/session.css?v=<?= (int) filemtime(dirname(__DIR__) . '/assets/session.css') ?>">
</head>
<body>
    <main class="settings-shell">
        <?php SessionBar::render($currentUser, '..', $canManageUsers, $canManagePermissions); ?>
        <header class="page-header compact-page-header">
            <div class="compact-page-heading">
                <p class="eyebrow">Automação de códigos</p>
                <h1 class="page-title">ZKAccess Control</h1>
            </div>
        </header>

        <?php if (!$settingsStorageAvailable): ?><div class="alert">Importe <code>migrations/004_portal_permissions.sql</code> para ativar esta configuração.</div><?php endif; ?>
        <?php if ($message !== null): ?><div class="success"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
        <?php if ($error !== null): ?><div class="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
        <?php if (!$canConfigure): ?><div class="notice">O seu perfil permite consultar esta página, mas não alterar a configuração.</div><?php endif; ?>

        <section class="card">
            <h2>Estado da integração</h2>
            <p>O portal não guarda nem apresenta utilizadores, passwords ou sessões do Cloudbeds/ZKAccess.</p>
            <div class="status-grid">
                <div class="status-item"><span>Automação</span><strong><span class="badge <?= $automationEnabled ? 'ok' : 'off' ?>"><?= $automationEnabled ? 'Ativa' : 'Desligada' ?></span></strong></div>
                <div class="status-item"><span>Modo</span><strong><?= $dryRun ? 'Dry-run' : 'Live' ?></strong></div>
                <div class="status-item"><span>Executor privado</span><strong><?= $privateConfigReady ? 'Configuração válida detetada' : 'Não ligado' ?></strong></div>
                <div class="status-item"><span>Estado do runner</span><strong><?= $runnerStatusReady ? 'Ficheiro de estado detetado' : 'Sem comunicação' ?></strong></div>
            </div>
        </section>

        <section class="card">
            <h2>Parâmetros operacionais</h2>
            <p>Horário interpretado em Europe/Lisbon. A ativação só é aceite quando existe uma configuração privada fora de <code>public_html</code>.</p>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Csrf::token(), ENT_QUOTES, 'UTF-8') ?>">
                <div class="form-grid">
                    <label class="field"><span>Hora diária</span><input type="time" name="schedule_time" value="<?= htmlspecialchars(substr((string) $settings['schedule_time'], 0, 5), ENT_QUOTES, 'UTF-8') ?>" required <?= $canConfigure && $settingsStorageAvailable ? '' : 'disabled' ?>></label>
                    <label class="field"><span>Termo dos quartos</span><input type="text" name="room_search_term" maxlength="40" value="<?= htmlspecialchars((string) $settings['room_search_term'], ENT_QUOTES, 'UTF-8') ?>" required <?= $canConfigure && $settingsStorageAvailable ? '' : 'disabled' ?>></label>
                    <label class="check-row"><input type="checkbox" name="dry_run" value="1" <?= $dryRun ? 'checked' : '' ?> <?= $canConfigure && $settingsStorageAvailable ? '' : 'disabled' ?>><span class="check-copy"><strong>Dry-run</strong><small>Simula as alterações sem guardar códigos no ZKAccess.</small></span></label>
                    <label class="check-row"><input type="checkbox" name="enabled" value="1" <?= $automationEnabled ? 'checked' : '' ?> <?= $canConfigure && $settingsStorageAvailable && $privateConfigReady ? '' : 'disabled' ?>><span class="check-copy"><strong>Ativar automação</strong><small>Disponível apenas depois de o executor privado estar configurado.</small></span></label>
                    <label class="check-row"><input type="checkbox" name="confirm_live" value="1" <?= $canConfigure && $settingsStorageAvailable ? '' : 'disabled' ?>><span class="check-copy"><strong>Confirmo o modo live</strong><small>Obrigatório em cada gravação quando o dry-run estiver desativado.</small></span></label>
                </div>
                <div class="form-actions"><button class="primary-button" type="submit" <?= $canConfigure && $settingsStorageAvailable ? '' : 'disabled' ?>>Guardar configuração</button></div>
            </form>
        </section>

        <section class="card">
            <h2>Versão preparada</h2>
            <ul class="help-list">
                <li><strong><?= htmlspecialchars($runnerVersion, ENT_QUOTES, 'UTF-8') ?></strong>, com Python e Playwright.</li>
                <li>Leitura das chegadas do dia no Cloudbeds e obtenção do PIN nas notas.</li>
                <li>Atualização Direct POST no ZKAccess, com fallback visual.</li>
                <li>O agendamento real depende de Python, Chromium e sessão Cloudbeds com MFA no servidor privado.</li>
            </ul>
        </section>
    </main>
</body>
</html>
