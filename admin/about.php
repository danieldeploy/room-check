<?php
declare(strict_types=1);

// Public information only: never bootstrap an authenticated session or database.
$text = require dirname(__DIR__) . '/src/I18n/HubPublicText.php';
$lang = ($_GET['lang'] ?? '') === 'en' ? 'en' : 'pt';
$page = in_array($_GET['page'] ?? '', ['privacy', 'terms'], true) ? $_GET['page'] : 'home';
$t = static fn(string $key): string => htmlspecialchars($text[$key][$lang === 'en' ? 1 : 0], ENT_QUOTES, 'UTF-8');
$titleKey = ['home'=>'homeTitle', 'privacy'=>'privacyTitle', 'terms'=>'termsTitle'][$page];
$sections = [
    'home' => [['homeTitle', 'intro', 'features', 'drive']],
    'privacy' => [
        ['scopeTitle', 'scope', 'write'],
        ['storageTitle', 'storage', 'retention'],
        ['sharingTitle', 'sharing', 'alerts', 'limited'],
        ['revokeTitle', 'revoke'],
    ],
    'terms' => [['termsTitle', 'use', 'accuracy', 'providers']],
];
$nonce = base64_encode(random_bytes(18));
header('Content-Type: text/html; charset=UTF-8');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; style-src 'nonce-$nonce'; base-uri 'none'; frame-ancestors 'none'; form-action 'none'");
?>
<!doctype html>
<html lang="<?= $lang ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $t($titleKey) ?> — Active Lines</title>
<style nonce="<?= $nonce ?>">
:root{color-scheme:light;font-family:system-ui,sans-serif;color:#193438;background:#f3f8f7}
*{box-sizing:border-box}body{margin:0}header,main,footer{max-width:900px;margin:auto;padding:24px}
header{border-bottom:1px solid #cfddda}nav{display:flex;flex-wrap:wrap;gap:16px;margin-top:18px}
a{color:#086c65;text-underline-offset:3px}a:focus-visible{outline:3px solid #e5b53a;outline-offset:4px}
h1{font-size:clamp(1.8rem,5vw,2.6rem);line-height:1.15}h2{font-size:1.25rem;margin-top:0}
p{line-height:1.7}section{background:white;border:1px solid #dce7e4;border-radius:14px;padding:24px;margin:20px 0}
.brand{font-weight:800;font-size:1.2rem}.company,footer{color:#496261}.login{display:inline-block;font-weight:700}
</style>
</head>
<body>
<header>
<div class="brand">Management Hub</div>
<div class="company">Active Lines Unip. Lda.</div>
<nav aria-label="<?= $t('home') ?>">
<?php foreach (['home','privacy','terms'] as $item): ?>
<a href="about.php?page=<?= $item ?>&amp;lang=<?= $lang ?>"<?= $page === $item ? ' aria-current="page"' : '' ?>><?= $t($item) ?></a>
<?php endforeach; ?>
<a href="about.php?page=<?= $page ?>&amp;lang=<?= $lang === 'pt' ? 'en' : 'pt' ?>" lang="<?= $lang === 'pt' ? 'en' : 'pt' ?>"><?= $lang === 'pt' ? 'English' : 'Português' ?></a>
</nav>
</header>
<main>
<h1><?= $t($titleKey) ?></h1>
<?php foreach ($sections[$page] as $section): ?>
<section>
<?php if ($page !== 'home' && $page !== 'terms'): ?><h2><?= $t($section[0]) ?></h2><?php endif; ?>
<?php foreach (array_slice($section,1) as $key): ?><p><?= $t($key) ?></p><?php endforeach; ?>
<?php if ($section[0] === 'revokeTitle'): ?>
<p><a href="https://myaccount.google.com/connections"><?= $t('connections') ?></a></p>
<?php endif; ?>
<?php if ($section[0] === 'sharingTitle'): ?>
<p><a href="https://developers.google.com/terms/api-services-user-data-policy">Google API Services User Data Policy</a></p>
<?php endif; ?>
</section>
<?php endforeach; ?>
<section><h2><?= $t('contactTitle') ?></h2><p><?= $t('contact') ?>
<a href="mailto:daniel.ciorcas@welcomehostel.pt">daniel.ciorcas@welcomehostel.pt</a></p></section>
<p><a class="login" href="../login.php"><?= $t('login') ?></a></p>
</main>
<footer><p><?= $t('updated') ?></p></footer>
</body>
</html>
