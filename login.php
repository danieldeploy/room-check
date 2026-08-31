<?php
declare(strict_types=1);

require __DIR__ . '/lib.php';
$config = require __DIR__ . '/config.php';
require_once __DIR__ . '/src/Auth/Auth.php';
require_once __DIR__ . '/src/Security/Csrf.php';

Auth::startSession($config);
if (Auth::currentUser(database(), $config) !== null) {
    header('Location: index.php');
    exit;
}

$error = null;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        Csrf::validate($_POST['csrf_token'] ?? null);
        $success = Auth::attempt(
            database(),
            (string) ($_POST['username'] ?? ''),
            (string) ($_POST['password'] ?? ''),
            $config
        );
        if (!$success) {
            throw new RuntimeException('Utilizador ou password inválidos.');
        }
        header('Location: index.php');
        exit;
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
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
    <title>Entrar — Portal Active Lines Unip. Lda.</title>
    <link rel="stylesheet" href="assets/auth.css">
</head>
<body>
    <main class="auth-shell">
        <section class="auth-card">
            <p class="eyebrow">Active Lines Unip. Lda.</p>
            <h1>Portal de Operações</h1>
            <p class="intro">Entre com a sua conta de trabalho para aceder aos módulos autorizados.</p>
            <?php if ($error !== null): ?>
                <div class="alert" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>
            <form method="post" autocomplete="on">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Csrf::token(), ENT_QUOTES, 'UTF-8') ?>">
                <label>
                    <span>Utilizador</span>
                    <input name="username" required autocomplete="username" maxlength="64" autofocus>
                </label>
                <label>
                    <span>Password</span>
                    <input type="password" name="password" required autocomplete="current-password">
                </label>
                <button type="submit">Entrar</button>
            </form>
        </section>
    </main>
</body>
</html>
