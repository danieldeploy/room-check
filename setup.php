<?php
declare(strict_types=1);

require __DIR__ . '/lib.php';
$config = require __DIR__ . '/config.php';
require_once __DIR__ . '/src/Auth/Auth.php';
require_once __DIR__ . '/src/Security/Csrf.php';
require_once __DIR__ . '/src/I18n/SiteTranslations.php';

Auth::startSession($config);
SiteTranslations::boot();
$pdo = database();
$userCount = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
if ($userCount > 0) {
    http_response_code(410);
    exit('A configuração inicial já foi concluída.');
}

$error = null;
$created = false;
$setupLock = false;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        Csrf::validate($_POST['csrf_token'] ?? null);
        $expectedKey = (string) ($config['auth']['setup_key'] ?? '');
        $providedKey = (string) ($_POST['setup_key'] ?? '');
        if ($expectedKey === '' || !hash_equals($expectedKey, $providedKey)) {
            throw new RuntimeException('Chave de instalação inválida.');
        }

        $username = trim((string) ($_POST['username'] ?? ''));
        $displayName = trim((string) ($_POST['display_name'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        if (!preg_match('/^[a-zA-Z0-9._-]{3,64}$/', $username)) {
            throw new InvalidArgumentException('O utilizador deve ter 3–64 caracteres: letras, números, ponto, hífen ou underscore.');
        }
        if ($displayName === '' || mb_strlen($displayName) > 120) {
            throw new InvalidArgumentException('Indique um nome válido.');
        }
        Auth::validatePassword($password);

        $setupLock = (int) $pdo->query("SELECT GET_LOCK('room_check_initial_setup', 5)")->fetchColumn() === 1;
        if (!$setupLock) {
            throw new RuntimeException('Não foi possível bloquear a configuração inicial. Tente novamente.');
        }
        if ((int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0) {
            throw new RuntimeException('A configuração inicial já foi concluída.');
        }

        $statement = $pdo->prepare(
            'INSERT INTO users (username, display_name, password_hash, role, is_active)
             VALUES (:username, :name, :hash, :role, 1)'
        );
        $statement->execute([
            'username' => $username,
            'name' => $displayName,
            'hash' => password_hash($password, PASSWORD_DEFAULT),
            'role' => 'gerente',
        ]);
        Auth::audit($pdo, (int) $pdo->lastInsertId(), 'initial_manager_created');
        $created = true;
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
    } finally {
        if ($setupLock) {
            $pdo->query("DO RELEASE_LOCK('room_check_initial_setup')");
        }
    }
}

header('Cache-Control: no-store');
?>
<!doctype html>
<html lang="pt">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>Configuração inicial — <?= htmlspecialchars(PortalBrand::name(), ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="stylesheet" href="assets/auth.css">
</head>
<body>
    <main class="auth-shell">
        <section class="auth-card">
            <p class="eyebrow">Configuração única</p>
            <h1>Primeiro Gerente</h1>
            <?php if ($created): ?>
                <div class="success">Conta criada. Remova a chave de instalação do servidor.</div>
                <a class="button-link" href="login.php">Ir para o login</a>
            <?php else: ?>
                <p class="intro">Esta página deixa de funcionar assim que a primeira conta for criada.</p>
                <?php if ($error !== null): ?><div class="alert" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
                <form method="post" autocomplete="off">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Csrf::token(), ENT_QUOTES, 'UTF-8') ?>">
                    <label><span>Chave de instalação</span><input type="password" name="setup_key" required></label>
                    <label><span>Utilizador</span><input name="username" required maxlength="64"></label>
                    <label><span>Nome</span><input name="display_name" required maxlength="120"></label>
                    <label><span>Password (mínimo 12 caracteres)</span><input type="password" name="password" required minlength="12" autocomplete="new-password"></label>
                    <button type="submit">Criar Gerente</button>
                </form>
            <?php endif; ?>
        </section>
    </main>
</body>
</html>
