<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/lib.php';
$config = require $root . '/config.php';
require_once $root . '/src/Auth/Auth.php';
require_once $root . '/src/Security/Csrf.php';

try {
    $pdo = database();
    $currentUser = Auth::requirePermission($pdo, $config, Auth::PERMISSION_USERS_MANAGE);
} catch (RuntimeException $exception) {
    if ($exception->getCode() === 401) {
        header('Location: ../login.php');
        exit;
    }
    http_response_code(403);
    exit($exception->getMessage());
}

$message = null;
$error = null;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        Csrf::validate($_POST['csrf_token'] ?? null);
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'create') {
            $username = trim((string) ($_POST['username'] ?? ''));
            $displayName = trim((string) ($_POST['display_name'] ?? ''));
            $role = Auth::validateRole((string) ($_POST['role'] ?? ''));
            $password = (string) ($_POST['password'] ?? '');
            if (!preg_match('/^[a-zA-Z0-9._-]{3,64}$/', $username)) {
                throw new InvalidArgumentException('Utilizador inválido.');
            }
            if ($displayName === '' || mb_strlen($displayName) > 120) {
                throw new InvalidArgumentException('Nome inválido.');
            }
            Auth::validatePassword($password);
            $statement = $pdo->prepare(
                'INSERT INTO users (username, display_name, password_hash, role, is_active)
                 VALUES (:username, :name, :hash, :role, 1)'
            );
            $statement->execute([
                'username' => $username,
                'name' => $displayName,
                'hash' => password_hash($password, PASSWORD_DEFAULT),
                'role' => $role,
            ]);
            Auth::audit($pdo, (int) $currentUser['id'], 'user_created', [
                'target_user_id' => (int) $pdo->lastInsertId(),
                'role' => $role,
            ]);
            $message = 'Utilizador criado.';
        } elseif ($action === 'toggle') {
            $targetId = (int) ($_POST['user_id'] ?? 0);
            if ($targetId === (int) $currentUser['id']) {
                throw new InvalidArgumentException('Não pode desativar a própria conta.');
            }
            $statement = $pdo->prepare('UPDATE users SET is_active = NOT is_active WHERE id = :id');
            $statement->execute(['id' => $targetId]);
            Auth::audit($pdo, (int) $currentUser['id'], 'user_status_toggled', ['target_user_id' => $targetId]);
            $message = 'Estado da conta atualizado.';
        } elseif ($action === 'reset_password') {
            $targetId = (int) ($_POST['user_id'] ?? 0);
            $password = (string) ($_POST['password'] ?? '');
            Auth::validatePassword($password);
            $statement = $pdo->prepare('UPDATE users SET password_hash = :hash WHERE id = :id');
            $statement->execute(['hash' => password_hash($password, PASSWORD_DEFAULT), 'id' => $targetId]);
            Auth::audit($pdo, (int) $currentUser['id'], 'password_reset', ['target_user_id' => $targetId]);
            $message = 'Password atualizada.';
        } elseif ($action === 'change_role') {
            $targetId = (int) ($_POST['user_id'] ?? 0);
            if ($targetId === (int) $currentUser['id']) {
                throw new InvalidArgumentException('Não pode alterar o próprio perfil.');
            }
            $role = Auth::validateRole((string) ($_POST['role'] ?? ''));
            $statement = $pdo->prepare('UPDATE users SET role = :role WHERE id = :id');
            $statement->execute(['role' => $role, 'id' => $targetId]);
            Auth::audit($pdo, (int) $currentUser['id'], 'role_changed', ['target_user_id' => $targetId, 'role' => $role]);
            $message = 'Perfil atualizado.';
        } else {
            throw new InvalidArgumentException('Ação inválida.');
        }
    } catch (Throwable $exception) {
        $error = $exception instanceof PDOException && (string) $exception->getCode() === '23000'
            ? 'Esse nome de utilizador já existe.'
            : $exception->getMessage();
    }
}

$users = $pdo->query(
    'SELECT id, username, display_name, role, is_active, last_login_at, created_at
     FROM users ORDER BY display_name, username'
)->fetchAll();
$canManagePermissions = Auth::hasPermission($pdo, $currentUser, Auth::PERMISSION_PERMISSIONS_MANAGE);
header('Cache-Control: no-store');
?>
<!doctype html>
<html lang="pt">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>Utilizadores — Portal Welcome Hostel</title>
    <link rel="stylesheet" href="../assets/auth.css">
    <link rel="stylesheet" href="assets/users.css">
</head>
<body>
    <main class="users-shell">
        <nav><a href="../index.php">← Portal</a><?php if ($canManagePermissions): ?> · <a href="permissions.php">Permissões</a><?php endif; ?></nav>
        <header><p class="eyebrow">Administração</p><h1>Utilizadores</h1><p>Crie contas e atribua um dos quatro perfis.</p></header>
        <?php if ($message): ?><div class="success"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

        <section class="management-card">
            <h2>Novo utilizador</h2>
            <form method="post" class="create-grid" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Csrf::token(), ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="action" value="create">
                <label><span>Utilizador</span><input name="username" required maxlength="64"></label>
                <label><span>Nome</span><input name="display_name" required maxlength="120"></label>
                <label><span>Perfil</span><select name="role" required><?php foreach (Auth::ROLES as $value => $label): ?><option value="<?= $value ?>"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></label>
                <label><span>Password inicial</span><input type="password" name="password" required minlength="12" autocomplete="new-password"></label>
                <button type="submit">Criar conta</button>
            </form>
        </section>

        <section class="management-card">
            <h2>Contas existentes</h2>
            <div class="user-list">
                <?php foreach ($users as $user): ?>
                    <article class="user-row">
                        <div><strong><?= htmlspecialchars($user['display_name'], ENT_QUOTES, 'UTF-8') ?></strong><span>@<?= htmlspecialchars($user['username'], ENT_QUOTES, 'UTF-8') ?></span></div>
                        <span class="account-state <?= (int) $user['is_active'] === 1 ? 'active' : 'inactive' ?>"><?= (int) $user['is_active'] === 1 ? 'Ativa' : 'Desativada' ?></span>
                        <form method="post" class="inline-form">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Csrf::token(), ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="action" value="change_role"><input type="hidden" name="user_id" value="<?= (int) $user['id'] ?>">
                            <select name="role" <?= (int) $user['id'] === (int) $currentUser['id'] ? 'disabled' : '' ?>><?php foreach (Auth::ROLES as $value => $label): ?><option value="<?= $value ?>" <?= $user['role'] === $value ? 'selected' : '' ?>><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select>
                            <?php if ((int) $user['id'] !== (int) $currentUser['id']): ?><button type="submit">Guardar perfil</button><?php endif; ?>
                        </form>
                        <form method="post" class="inline-form">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Csrf::token(), ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="action" value="reset_password"><input type="hidden" name="user_id" value="<?= (int) $user['id'] ?>">
                            <input type="password" name="password" required minlength="12" placeholder="Nova password" autocomplete="new-password"><button type="submit">Alterar</button>
                        </form>
                        <?php if ((int) $user['id'] !== (int) $currentUser['id']): ?>
                            <form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Csrf::token(), ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="action" value="toggle"><input type="hidden" name="user_id" value="<?= (int) $user['id'] ?>"><button class="secondary" type="submit"><?= (int) $user['is_active'] === 1 ? 'Desativar' : 'Ativar' ?></button></form>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
    </main>
</body>
</html>
