<?php
declare(strict_types=1);

require __DIR__ . '/lib.php';
$config = require __DIR__ . '/config.php';
require_once __DIR__ . '/src/Auth/Auth.php';
require_once __DIR__ . '/src/Security/Csrf.php';
require_once __DIR__ . '/src/I18n/SiteTranslations.php';

function loginDestination(array $user): string
{
    return ($user['role'] ?? '') === 'empregada_andares' ? 'tasks.php' : 'index.php';
}

Auth::startSession($config);
SiteTranslations::boot();
$loggedUser = Auth::currentUser(database(), $config);
if ($loggedUser !== null) {
    header('Location: ' . loginDestination($loggedUser));
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
            $config,
            (string) ($_POST['language'] ?? Translator::locale())
        );
        if (!$success) {
            throw new RuntimeException('Utilizador ou password inválidos.');
        }
        $loggedUser = Auth::currentUser(database(), $config);
        header('Location: ' . loginDestination($loggedUser ?? []));
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
    <link rel="stylesheet" href="assets/auth.css?v=<?= (int) filemtime(__DIR__ . '/assets/auth.css') ?>">
</head>
<body>
    <main class="auth-shell">
        <section class="auth-card">
            <p class="eyebrow">Active Lines Unip. Lda.</p>
            <h1 class="auth-title">Portal de Gestão</h1>
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
                <label>
                    <span>Idioma</span>
                    <select name="language" aria-label="Idioma">
                        <option value="pt" <?= Translator::locale() === 'pt' ? 'selected' : '' ?>>Português</option>
                        <option value="en" <?= Translator::locale() === 'en' ? 'selected' : '' ?>>English</option>
                    </select>
                </label>
                <button type="submit">Entrar</button>
            </form>
        </section>
    </main>
</body>
</html>
