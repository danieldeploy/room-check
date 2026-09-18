<?php
declare(strict_types=1);
$root = dirname(__DIR__);
require $root . '/lib.php';
require_once $root . '/src/UI/SessionBar.php';
require_once $root . '/src/Invoices/InvoiceService.php';
require_once $root . '/src/I18n/InvoiceText.php';
$config = require $root . '/config.php';
try {
    $pdo = database();
    $currentUser = Auth::requirePermission($pdo, $config, Auth::PERMISSION_INVOICES_VIEW);
} catch (Throwable $e) {
    if ($e->getCode() === 401) {
        header('Location: ../login.php');
        exit;
    }
    http_response_code(403);
    exit(InvoiceText::get('forbidden'));
}
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
$isGerente = $currentUser['role'] === 'gerente';
$canRun = Auth::hasPermission($pdo, $currentUser, Auth::PERMISSION_INVOICES_RUN);
$service = new InvoiceService($pdo);
$error = null;
$settings = null;
$vault = null;
$credentials = false;
$browserReady = false;
$period = (string) ($_GET['period'] ?? (new DateTimeImmutable('first day of last month', new DateTimeZone('Europe/Lisbon')))->format('Y-m'));
$property = (string) ($_GET['property'] ?? '');
if (!InvoiceService::validPeriod($period) || ($property !== '' && !isset(InvoiceService::PROPERTIES[$property]))) {
    http_response_code(400);
    exit(InvoiceText::get('invalid_request'));
}
try {
    $settings = $service->settings();
    $browserReady = $service->browserReady();
    if ($isGerente) {
        try {
            $vault = new InvoiceVault($config['invoices']['private_dir']);
            $credentials = $vault->has('booking-credentials.enc');
        } catch (Throwable) {
            $error = 'private_storage_unavailable';
        }
    }
} catch (Throwable) {
    $error = 'migration_required';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        Csrf::validate($_POST['csrf_token'] ?? null);
        if (!$settings) throw new RuntimeException('migration_required');
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'collect') {
            if (!$canRun) throw new RuntimeException('forbidden');
            $requestedPeriod = (string) ($_POST['period'] ?? '');
            $requestedProperty = (string) ($_POST['property'] ?? '');
            if (!InvoiceService::validPeriod($requestedPeriod) || ($requestedProperty !== '' && !isset(InvoiceService::PROPERTIES[$requestedProperty]))) {
                throw new RuntimeException('invalid_request');
            }
            foreach ($requestedProperty === '' ? array_keys(InvoiceService::PROPERTIES) : [$requestedProperty] as $id) {
                $service->enqueue('collect', (string) $id, $requestedPeriod, (int) $currentUser['id']);
            }
        } else {
            InvoiceService::assertGerente($currentUser);
            if ($action === 'preflight' || $action === 'login') {
                $service->enqueue($action, '1140306', $period, (int) $currentUser['id']);
            } elseif ($action === 'credentials') {
                if (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] === 'off') throw new RuntimeException('https_required');
                if (!$browserReady) throw new RuntimeException('preflight_required');
                if (!$vault) throw new RuntimeException('private_storage_unavailable');
                $identifier = trim((string) ($_POST['identifier'] ?? ''));
                $password = (string) ($_POST['password'] ?? '');
                if ($identifier === '' || strlen($identifier) > 190 || $password === '' || strlen($password) > 2048) throw new RuntimeException('invalid_request');
                if ((int) $pdo->query("SELECT GET_LOCK('room_check_invoices', 0)")->fetchColumn() !== 1) throw new RuntimeException('worker_busy');
                try {
                    $vault->save('booking-credentials.enc', ['identifier' => $identifier, 'password' => $password]);
                    $vault->save('booking-session.enc', ['cookies' => []]);
                    $pdo->exec('UPDATE invoice_settings SET enabled = 0, login_verified_at = NULL WHERE id = 1');
                } finally {
                    $pdo->query("SELECT RELEASE_LOCK('room_check_invoices')");
                }
                unset($password);
            } elseif ($action === 'schedule') {
                if (isset($_POST['enabled']) && (!$credentials || !$browserReady)) throw new RuntimeException('preflight_required');
                if (isset($_POST['enabled']) && empty($settings['login_verified_at'])) throw new RuntimeException('login_required');
                $service->saveSchedule(isset($_POST['enabled']), (int) ($_POST['day'] ?? 0), (string) ($_POST['time'] ?? ''));
            } else {
                throw new RuntimeException('invalid_request');
            }
        }
        Auth::audit($pdo, (int) $currentUser['id'], 'invoices_' . $action);
        $_SESSION['invoice_flash'] = in_array($action, ['collect', 'preflight', 'login'], true) ? 'requested' : 'saved';
        header('Location: invoices.php?' . http_build_query(['period' => $period, 'property' => $property]), true, 303);
        exit;
    } catch (Throwable $e) {
        $error = isset(InvoiceText::TEXT[$e->getMessage()]) ? $e->getMessage() : 'worker_failed';
    }
}
$flash = $_SESSION['invoice_flash'] ?? null;
unset($_SESSION['invoice_flash']);
$invoices = [];
$jobs = [];
if ($settings) {
    $before = max(1, (int) ($_GET['before'] ?? PHP_INT_MAX));
    $sql = 'SELECT * FROM portal_invoices WHERE period = ? AND id < ?';
    $params = [$period, $before];
    if ($property !== '') { $sql .= ' AND property_id = ?'; $params[] = $property; }
    $stmt = $pdo->prepare($sql . ' ORDER BY id DESC LIMIT 51');
    $stmt->execute($params);
    $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $jobs = $pdo->query('SELECT * FROM invoice_jobs ORDER BY id DESC LIMIT 20')->fetchAll(PDO::FETCH_ASSOC);
}
$hasMore = count($invoices) > 50;
$invoices = array_slice($invoices, 0, 50);
function ie(string $value): string { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
function it(string $key): string { return ie(InvoiceText::get($key)); }
function invoiceTime(string $value): string { return (new DateTimeImmutable($value, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Europe/Lisbon'))->format('d/m/Y H:i'); }
?>
<!doctype html>
<html lang="<?= ie(Translator::locale()) ?>">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex,nofollow">
<title><?= it('title') ?> — <?= ie(PortalBrand::name()) ?></title>
<link rel="stylesheet" href="assets/settings.css"><link rel="stylesheet" href="../assets/session.css"><link rel="stylesheet" href="assets/invoices.css"></head>
<body><main class="settings-shell">
<?php SessionBar::render($currentUser, '..', Auth::hasPermission($pdo, $currentUser, Auth::PERMISSION_USERS_MANAGE), Auth::hasPermission($pdo, $currentUser, Auth::PERMISSION_PERMISSIONS_MANAGE)); ?>
<header class="page-header compact-page-header"><div class="compact-page-heading"><p class="eyebrow"><?= it('eyebrow') ?></p><h1 class="page-title"><?= it('title') ?></h1></div><p><?= it('scope') ?></p></header>
<?php if ($error): ?><div class="alert" role="alert"><?= it($error) ?></div><?php endif; ?>
<?php if ($flash): ?><div class="success" role="status"><?= it((string) $flash) ?></div><?php endif; ?>
<?php if ($settings): ?>
<?php if (strtotime(($settings['worker_seen_at'] ?? '') . ' UTC') < time() - 300): ?><div class="notice"><?= it('worker_stale') ?></div><?php endif; ?>
<section class="card"><h2><?= it('collect') ?></h2><p><?= it('background') ?></p>
<form method="post"><input type="hidden" name="csrf_token" value="<?= ie(Csrf::token()) ?>"><input type="hidden" name="action" value="collect">
<div class="form-grid"><label class="field"><span><?= it('property') ?></span><select name="property"><option value=""><?= it('all') ?></option><?php foreach (InvoiceService::PROPERTIES as $id => $name): ?><option value="<?= ie((string) $id) ?>" <?= (string) $id === $property ? 'selected' : '' ?>><?= ie($name) ?></option><?php endforeach; ?></select></label>
<label class="field"><span><?= it('period') ?></span><input type="month" name="period" value="<?= ie($period) ?>" min="2000-01" max="2099-12" required></label></div>
<div class="form-actions"><?php if ($canRun): ?><button class="primary-button" type="submit"><?= it('collect') ?></button><?php endif; ?><a href="invoices.php?<?= ie(http_build_query(['period' => $period, 'property' => $property])) ?>"><?= it('refresh') ?></a></div></form></section>
<section class="card"><h2><?= it('invoices') ?></h2><form method="get" class="invoice-filters"><label><?= it('period') ?><input type="month" name="period" value="<?= ie($period) ?>" required></label><label><?= it('property') ?><select name="property"><option value=""><?= it('all') ?></option><?php foreach (InvoiceService::PROPERTIES as $id => $name): ?><option value="<?= ie((string) $id) ?>" <?= (string) $id === $property ? 'selected' : '' ?>><?= ie($name) ?></option><?php endforeach; ?></select></label><button class="primary-button"><?= it('filter') ?></button></form>
<?php if (!$invoices): ?><p><?= it('empty') ?></p><?php else: ?><div class="invoice-table-wrap"><table><thead><tr><th><?= it('property') ?></th><th><?= it('number') ?></th><th><?= it('date') ?></th><th>PDF</th></tr></thead><tbody><?php foreach ($invoices as $invoice): ?><tr><td><?= ie(InvoiceService::PROPERTIES[$invoice['property_id']] ?? '') ?></td><td><?= ie($invoice['invoice_number']) ?></td><td><?= ie($invoice['issued_on']) ?></td><td><a href="invoice-download.php?id=<?= (int) $invoice['id'] ?>"><?= it('download') ?></a></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
<?php if ($hasMore): ?><p><a href="?<?= ie(http_build_query(['period' => $period, 'property' => $property, 'before' => end($invoices)['id']])) ?>"><?= it('older') ?></a></p><?php endif; ?></section>
<section class="card"><h2><?= it('history') ?></h2><div class="invoice-table-wrap"><table><thead><tr><th><?= it('created') ?></th><th><?= it('property') ?></th><th><?= it('period') ?></th><th><?= it('state') ?></th><th><?= it('counts') ?></th></tr></thead><tbody><?php foreach ($jobs as $job): ?><tr><td><?= ie(invoiceTime($job['created_at'])) ?><br><small><?= it($job['kind']) ?></small></td><td><?= ie(InvoiceService::PROPERTIES[$job['property_id']] ?? '') ?></td><td><?= ie($job['period']) ?></td><td><strong><?= it($job['state']) ?></strong><?php if ($job['result_code']): ?><br><small><?= it($job['result_code']) ?></small><?php endif; ?></td><td><?= (int) $job['imported_count'] ?> / <?= (int) $job['duplicate_count'] ?></td></tr><?php endforeach; ?></tbody></table></div></section>
<?php if ($isGerente): ?><section class="card"><h2><?= it('settings') ?></h2><p><?= $browserReady ? it('ready') : it('preflight_required') ?></p>
<form method="post" class="form-actions"><input type="hidden" name="csrf_token" value="<?= ie(Csrf::token()) ?>"><button class="primary-button" name="action" value="preflight"><?= it('preflight') ?></button><button class="primary-button" name="action" value="login" <?= (!$credentials || !$browserReady) ? 'disabled' : '' ?>><?= it('login') ?></button></form>
<p class="invoice-note"><?= $credentials ? it('configured') : it('not_configured') ?></p>
<form method="post" autocomplete="off"><input type="hidden" name="csrf_token" value="<?= ie(Csrf::token()) ?>"><input type="hidden" name="action" value="credentials"><fieldset <?= (!$vault || !$browserReady) ? 'disabled' : '' ?>><div class="form-grid"><label class="field"><span><?= it('identifier') ?></span><input type="text" name="identifier" maxlength="190" autocomplete="off" required></label><label class="field"><span><?= it('password') ?></span><input type="password" name="password" maxlength="2048" autocomplete="new-password" required></label></div><div class="form-actions"><button class="primary-button"><?= it('save_credentials') ?></button></div></fieldset></form>
<form method="post"><input type="hidden" name="csrf_token" value="<?= ie(Csrf::token()) ?>"><input type="hidden" name="action" value="schedule"><div class="form-grid"><label class="check-row"><input type="checkbox" name="enabled" <?= (int) $settings['enabled'] ? 'checked' : '' ?>><span><?= it('schedule') ?></span></label><p><?= it('schedule_note') ?></p><label class="field"><span><?= it('day') ?></span><input type="number" name="day" min="1" max="28" value="<?= (int) $settings['schedule_day'] ?>" required></label><label class="field"><span><?= it('time') ?></span><input type="time" name="time" value="<?= ie($settings['schedule_time']) ?>" required></label></div><div class="form-actions"><button class="primary-button"><?= it('save') ?></button></div></form>
</section><?php endif; ?>
<?php endif; ?>
</main></body></html>
