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
function validateUserEmail(string $email): string
{
    $email = mb_strtolower(trim($email));
    if ($email === '' || mb_strlen($email) > 190 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        throw new InvalidArgumentException('Email inválido.');
    }
    return $email;
}
function validateUserMobile(string $mobile): string
{
    $mobile = trim($mobile);
    if ($mobile === '' || mb_strlen($mobile) > 32 || !preg_match('/^[0-9+().\s-]+$/', $mobile)
        || strlen((string) preg_replace('/\D+/', '', $mobile)) < 6) {
        throw new InvalidArgumentException('Telemóvel inválido.');
    }
    return $mobile;
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        Csrf::validate($_POST['csrf_token'] ?? null);
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'create') {
            $username = trim((string) ($_POST['username'] ?? ''));
            $displayName = trim((string) ($_POST['display_name'] ?? ''));
            $email = validateUserEmail((string) ($_POST['email'] ?? ''));
            $mobile = validateUserMobile((string) ($_POST['mobile'] ?? ''));
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
                'INSERT INTO users (username, display_name, email, mobile, password_hash, role, is_active)
                 VALUES (:username, :name, :email, :mobile, :hash, :role, 1)'
            );
            $statement->execute([
                'username' => $username,
                'name' => $displayName,
                'email' => $email,
                'mobile' => $mobile,
                'hash' => password_hash($password, PASSWORD_DEFAULT),
                'role' => $role,
            ]);
            Auth::audit($pdo, (int) $currentUser['id'], 'user_created', [
                'target_user_id' => (int) $pdo->lastInsertId(),
                'role' => $role,
            ]);
            $message = 'Utilizador criado.';
        } elseif ($action === 'save_user') {
            $targetId = (int) ($_POST['user_id'] ?? 0);
            $username = trim((string) ($_POST['username'] ?? ''));
            $displayName = trim((string) ($_POST['display_name'] ?? ''));
            $email = validateUserEmail((string) ($_POST['email'] ?? ''));
            $mobile = validateUserMobile((string) ($_POST['mobile'] ?? ''));
            if (!preg_match('/^[a-zA-Z0-9._-]{3,64}$/', $username)) {
                throw new InvalidArgumentException('Utilizador inválido.');
            }
            if ($displayName === '' || mb_strlen($displayName) > 120) {
                throw new InvalidArgumentException('Nome inválido.');
            }
            $parameters = [
                'id' => $targetId, 'username' => $username, 'name' => $displayName,
                'email' => $email, 'mobile' => $mobile,
            ];
            if ($targetId === (int) $currentUser['id']) {
                $statement = $pdo->prepare(
                    'UPDATE users SET username = :username, display_name = :name, email = :email, mobile = :mobile
                     WHERE id = :id'
                );
            } else {
                $role = Auth::validateRole((string) ($_POST['role'] ?? ''));
                $parameters['role'] = $role;
                $statement = $pdo->prepare(
                    'UPDATE users SET username = :username, display_name = :name, email = :email,
                        mobile = :mobile, role = :role WHERE id = :id'
                );
            }
            $statement->execute($parameters);
            $exists = $pdo->prepare('SELECT id FROM users WHERE id = :id');
            $exists->execute(['id' => $targetId]);
            if (!$exists->fetchColumn()) {
                throw new InvalidArgumentException('Utilizador não encontrado.');
            }
            Auth::audit($pdo, (int) $currentUser['id'], 'user_updated', ['target_user_id' => $targetId]);
            $message = 'Dados do utilizador atualizados.';
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
        } else {
            throw new InvalidArgumentException('Ação inválida.');
        }
    } catch (Throwable $exception) {
        $error = $exception instanceof PDOException && (string) $exception->getCode() === '23000'
            ? 'Esse nome de utilizador ou email já está associado a outra conta.'
            : $exception->getMessage();
    }
}

$users = $pdo->query(
    'SELECT id, username, display_name, email, mobile, role, is_active, last_login_at, created_at
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
    <title>Utilizadores — Active Lines Unip. Lda.</title>
    <link rel="stylesheet" href="../assets/auth.css">
    <link rel="stylesheet" href="assets/users.css">
    <link rel="stylesheet" href="../assets/session.css?v=<?= (int) filemtime(dirname(__DIR__) . '/assets/session.css') ?>">
</head>
<body>
    <main class="users-shell">
        <?php SessionBar::render($currentUser, '..', true, $canManagePermissions); ?>
        <header><p class="eyebrow">Administração</p><h1 class="page-title">Utilizadores</h1><p>Crie e edite contas, contactos e perfis de acesso.</p></header>
        <?php if ($message): ?><div class="success"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

        <section class="management-card">
            <h2>Novo utilizador</h2>
            <form method="post" class="create-grid" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Csrf::token(), ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="action" value="create">
                <label><span>Utilizador</span><input name="username" required maxlength="64"></label>
                <label><span>Nome</span><input name="display_name" required maxlength="120"></label>
                <label><span>Email</span><input type="email" name="email" required maxlength="190" autocomplete="off"></label>
                <label><span>Telemóvel</span><input type="tel" name="mobile" required maxlength="32" autocomplete="off"></label>
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
                        <div class="user-card-heading"><div><strong><?= htmlspecialchars($user['display_name'], ENT_QUOTES, 'UTF-8') ?></strong><span>@<?= htmlspecialchars($user['username'], ENT_QUOTES, 'UTF-8') ?></span></div><span class="account-state <?= (int) $user['is_active'] === 1 ? 'active' : 'inactive' ?>"><?= (int) $user['is_active'] === 1 ? 'Ativa' : 'Desativada' ?></span></div>
                        <form method="post" class="edit-grid">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Csrf::token(), ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="action" value="save_user"><input type="hidden" name="user_id" value="<?= (int) $user['id'] ?>">
                            <label><span>Utilizador</span><input name="username" required maxlength="64" value="<?= htmlspecialchars($user['username'], ENT_QUOTES, 'UTF-8') ?>"></label>
                            <label><span>Nome</span><input name="display_name" required maxlength="120" value="<?= htmlspecialchars($user['display_name'], ENT_QUOTES, 'UTF-8') ?>"></label>
                            <label><span>Email</span><input type="email" name="email" required maxlength="190" value="<?= htmlspecialchars((string) ($user['email'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></label>
                            <label><span>Telemóvel</span><input type="tel" name="mobile" required maxlength="32" value="<?= htmlspecialchars((string) ($user['mobile'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></label>
                            <label><span>Perfil</span><select name="role" <?= (int) $user['id'] === (int) $currentUser['id'] ? 'disabled' : '' ?>><?php foreach (Auth::ROLES as $value => $label): ?><option value="<?= $value ?>" <?= $user['role'] === $value ? 'selected' : '' ?>><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></label>
                            <?php if ((int) $user['id'] === (int) $currentUser['id']): ?><input type="hidden" name="role" value="<?= htmlspecialchars($user['role'], ENT_QUOTES, 'UTF-8') ?>"><?php endif; ?>
                            <button type="submit">Guardar dados</button>
                        </form>
                        <div class="user-card-actions"><form method="post" class="inline-form">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Csrf::token(), ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="action" value="reset_password"><input type="hidden" name="user_id" value="<?= (int) $user['id'] ?>">
                            <input type="password" name="password" required minlength="12" placeholder="Nova password" autocomplete="new-password"><button type="submit">Alterar</button>
                        </form>
                        <?php if ((int) $user['id'] !== (int) $currentUser['id']): ?>
                            <form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Csrf::token(), ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="action" value="toggle"><input type="hidden" name="user_id" value="<?= (int) $user['id'] ?>"><button class="secondary" type="submit"><?= (int) $user['is_active'] === 1 ? 'Desativar' : 'Ativar' ?></button></form>
                        <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
    </main>
</body>
</html>
