<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/lib.php';
$config = require $root . '/config.php';
require_once $root . '/src/Auth/Auth.php';
require_once $root . '/src/Security/Csrf.php';
require_once $root . '/src/UI/SessionBar.php';

try {
    $pdo = database();
    $currentUser = Auth::requirePermission($pdo, $config, Auth::PERMISSION_PERMISSIONS_MANAGE);
} catch (RuntimeException $exception) {
    if ($exception->getCode() === 401) {
        header('Location: ../login.php');
        exit;
    }
    http_response_code(403);
    exit($exception->getMessage());
}

$storageAvailable = Auth::permissionStorageAvailable($pdo);
$message = null;
$error = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        Csrf::validate($_POST['csrf_token'] ?? null);
        if (!$storageAvailable) {
            throw new RuntimeException('A tabela de permissões ainda não existe. Importe a migração 004_portal_permissions.sql.');
        }

        $submitted = isset($_POST['permissions']) && is_array($_POST['permissions'])
            ? $_POST['permissions']
            : [];
        $selectedByRole = [];
        foreach (array_keys(Auth::ROLES) as $role) {
            $roleValues = [];
            if (isset($submitted[$role]) && is_array($submitted[$role])) {
                foreach ($submitted[$role] as $value) {
                    if (is_string($value)) {
                        $roleValues[] = $value;
                    }
                }
            }
            $selectedByRole[$role] = Auth::normalizePermissions(array_merge(
                $roleValues,
                Auth::LOCKED_ROLE_PERMISSIONS[$role] ?? []
            ));
        }

        $pdo->beginTransaction();
        $pdo->exec('DELETE FROM role_permissions');
        $insert = $pdo->prepare(
            'INSERT INTO role_permissions (role, permission, updated_by_user_id)
             VALUES (:role, :permission, :actor)'
        );
        foreach ($selectedByRole as $role => $permissions) {
            foreach ($permissions as $permission) {
                $insert->execute([
                    'role' => $role,
                    'permission' => $permission,
                    'actor' => (int) $currentUser['id'],
                ]);
            }
        }
        Auth::audit($pdo, (int) $currentUser['id'], 'role_permissions_updated', [
            'permission_counts' => array_map('count', $selectedByRole),
        ]);
        $pdo->commit();
        Auth::resetPermissionCache();
        $storageAvailable = Auth::permissionStorageAvailable($pdo);
        $message = 'Permissões atualizadas.';
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = $exception->getMessage();
    }
}

$rolePermissions = [];
foreach (array_keys(Auth::ROLES) as $role) {
    $rolePermissions[$role] = Auth::permissionsForRole($pdo, $role);
}
$canManageUsers = Auth::hasPermission($pdo, $currentUser, Auth::PERMISSION_USERS_MANAGE);
header('Cache-Control: no-store');
?>
<!doctype html>
<html lang="pt">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>Permissões — Active Lines Unip. Lda.</title>
    <link rel="stylesheet" href="assets/settings.css">
    <link rel="stylesheet" href="../assets/session.css">
</head>
<body>
    <main class="settings-shell">
        <?php SessionBar::render($currentUser, '..', $canManageUsers, true); ?>
        <header class="page-header">
            <p class="eyebrow">Administração</p>
            <h1>Permissões dos perfis</h1>
            <p>Defina os módulos e ações disponíveis para cada um dos quatro perfis. As verificações são feitas no servidor, incluindo nas APIs.</p>
        </header>

        <?php if (!$storageAvailable): ?><div class="alert">Importe <code>migrations/004_portal_permissions.sql</code> para ativar a configuração persistente. Até lá, a aplicação usa a matriz padrão abaixo.</div><?php endif; ?>
        <?php if ($message !== null): ?><div class="success"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
        <?php if ($error !== null): ?><div class="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

        <section class="card">
            <h2>Matriz de acesso</h2>
            <p>Permissões de alteração incluem automaticamente a permissão de consulta do mesmo módulo.</p>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Csrf::token(), ENT_QUOTES, 'UTF-8') ?>">
                <div class="permission-wrap">
                    <table class="permission-table">
                        <thead><tr><th>Permissão</th><?php foreach (Auth::ROLES as $label): ?><th><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></th><?php endforeach; ?></tr></thead>
                        <tbody>
                            <?php foreach (Auth::PERMISSIONS as $permission => $meta): ?>
                                <tr>
                                    <td><span class="permission-name"><strong><?= htmlspecialchars($meta['label'], ENT_QUOTES, 'UTF-8') ?></strong><small><?= htmlspecialchars($meta['group'], ENT_QUOTES, 'UTF-8') ?></small></span></td>
                                    <?php foreach (Auth::ROLES as $role => $label): ?>
                                        <?php $locked = in_array($permission, Auth::LOCKED_ROLE_PERMISSIONS[$role] ?? [], true); ?>
                                        <td>
                                            <input type="checkbox" name="permissions[<?= htmlspecialchars($role, ENT_QUOTES, 'UTF-8') ?>][]" value="<?= htmlspecialchars($permission, ENT_QUOTES, 'UTF-8') ?>" <?= in_array($permission, $rolePermissions[$role], true) ? 'checked' : '' ?> <?= (!$storageAvailable || $locked) ? 'disabled' : '' ?> aria-label="<?= htmlspecialchars($label . ': ' . $meta['label'], ENT_QUOTES, 'UTF-8') ?>">
                                            <?php if ($locked): ?><input type="hidden" name="permissions[<?= htmlspecialchars($role, ENT_QUOTES, 'UTF-8') ?>][]" value="<?= htmlspecialchars($permission, ENT_QUOTES, 'UTF-8') ?>"><span class="locked">Obrigatória</span><?php endif; ?>
                                        </td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="form-actions"><button class="primary-button" type="submit" <?= $storageAvailable ? '' : 'disabled' ?>>Guardar permissões</button></div>
            </form>
        </section>
    </main>
</body>
</html>
