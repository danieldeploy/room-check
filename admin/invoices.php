<?php
declare(strict_types=1);
$root = dirname(__DIR__);
require $root . '/lib.php';
require_once $root . '/src/UI/SessionBar.php';
require_once $root . '/src/Invoices/InvoiceService.php';
require_once $root . '/src/Invoices/InvoiceAuth.php';
require_once $root . '/src/Invoices/InvoiceDrive.php';
require_once $root . '/src/I18n/InvoiceText.php';
$config = require $root . '/config.php';
try {
    $pdo = database();
    $currentUser = Auth::requirePermission($pdo, $config, Auth::PERMISSION_INVOICES_VIEW);
} catch (Throwable $e) {
    if ($e->getCode() === 401) { header('Location: ../login.php'); exit; }
    http_response_code(403); exit(InvoiceText::get('forbidden'));
}
header('Cache-Control: no-store'); header('Referrer-Policy: no-referrer');
$isGerente = $currentUser['role'] === 'gerente';
$canRun = Auth::hasPermission($pdo, $currentUser, Auth::PERMISSION_INVOICES_RUN);
$service = new InvoiceService($pdo); $repository = new InvoiceAccounts($pdo);
$error = null; $settings = null; $vault = null; $accounts = []; $properties = []; $notifications = []; $managers = []; $driveSettings = [];
$period = (string) ($_GET['period'] ?? (new DateTimeImmutable('first day of last month', new DateTimeZone('Europe/Lisbon')))->format('Y-m'));
$accountId = max(0, (int) ($_GET['account'] ?? 0));
$property = (string) ($_GET['property'] ?? '');
if (!InvoiceService::validPeriod($period)) { http_response_code(400); exit(InvoiceText::get('invalid_request')); }
try {
    $accounts = $repository->all(); $settings = $service->settings();
    foreach ($accounts as $a) $properties[$a['id']] = $repository->properties((int) $a['id']);
    if ($accountId) $repository->get($accountId);
    if ($property !== '' && (!$accountId || !isset($properties[$accountId][$property]))) throw new RuntimeException('invalid_request');
    if ($isGerente) {
        try { $vault = new InvoiceVault($config['invoices']['private_dir']); } catch (Throwable) { $error = 'private_storage_unavailable'; }
        $driveSettings = $pdo->query('SELECT * FROM invoice_drive_settings WHERE id=1')->fetch(PDO::FETCH_ASSOC);
        $notifications = $pdo->query('SELECT * FROM invoice_notification_settings WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
        $managers = $pdo->query("SELECT id, display_name FROM users WHERE role = 'gerente' AND is_active = 1 AND mobile IS NOT NULL AND mobile <> '' ORDER BY display_name")->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) { $settings = null; $error = $e->getMessage() === 'invalid_request' ? 'invalid_request' : 'migration_required'; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        Csrf::validate($_POST['csrf_token'] ?? null);
        if (!$settings) throw new RuntimeException('migration_required');
        $action = (string) ($_POST['action'] ?? '');
        $id = (int) ($_POST['account_id'] ?? 0);
        if ($action === 'collect') {
            if (!$canRun) throw new RuntimeException('forbidden');
            $month = (string) ($_POST['period'] ?? '');
            $target = (string) ($_POST['property'] ?? '');
            if (!InvoiceService::validPeriod($month)) throw new RuntimeException('invalid_request');
            $selected = $id ? [$repository->get($id)] : array_filter($accounts, static fn($a) => (int) $a['enabled'] === 1);
            if (!$selected) throw new RuntimeException('no_accounts');
            foreach ($selected as $a) {
                $props = $repository->properties((int) $a['id']);
                if ($target !== '' && (!isset($props[$target]) || !$id)) throw new RuntimeException('invalid_request');
                foreach ($target === '' ? array_keys($props) : [$target] as $p) $service->enqueue('collect', (string) $p, $month, (int) $currentUser['id'], null, (int) $a['id']);
            }
        } else {
            InvoiceService::assertGerente($currentUser);
            if ($action === 'create_account') {
                $portal = (string) ($_POST['portal'] ?? ''); $label = trim((string) ($_POST['label'] ?? ''));
                $props = [];
                foreach ([1, 2] as $n) if (trim((string) ($_POST['property_id_' . $n] ?? '')) !== '') $props[trim($_POST['property_id_' . $n])] = trim((string) ($_POST['property_label_' . $n] ?? ''));
                if (in_array($portal, ['airbnb', 'email'], true)) $props = ['account' => $label];
                $accountId = $repository->create($portal, $label, $props, (string) ($_POST['auth_method'] ?? 'password'));
                $property = '';
            } elseif ($action === 'preflight' || $action === 'login') {
                $props = $repository->properties($id);
                $service->enqueue($action, (string) array_key_first($props), $period, (int) $currentUser['id'], null, $id);
            } elseif ($action === 'credentials' || $action === 'sms_token') {
                if (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] === 'off') throw new RuntimeException('https_required');
                if (!$vault) throw new RuntimeException('private_storage_unavailable');
                if ((int) $pdo->query("SELECT GET_LOCK('room_check_invoices', 0)")->fetchColumn() !== 1) throw new RuntimeException('worker_busy');
                try {
                    if ($action === 'credentials') $repository->saveCredentials($vault, $id, $_POST);
                    else $_SESSION['invoice_device_token'] = ['account' => $id, 'token' => (new InvoiceAuth($pdo, $vault))->rotateSmsToken($id)];
                } finally { $pdo->query("SELECT RELEASE_LOCK('room_check_invoices')"); }
            } elseif ($action === 'schedule') {
                $a = $repository->get($id);
                if (isset($_POST['enabled']) && empty($a['login_verified_at'])) throw new RuntimeException('login_required');
                $service->saveSchedule(isset($_POST['enabled']), (int) ($_POST['day'] ?? 0), (string) ($_POST['time'] ?? ''), $id);
            } elseif (in_array($action, ['drive_settings','drive_test','drive_retry'], true)) {
                if (!$vault) throw new RuntimeException('private_storage_unavailable');
                if ((int) $pdo->query("SELECT GET_LOCK('room_check_invoices', 0)")->fetchColumn() !== 1) throw new RuntimeException('worker_busy');
                try {
                    $driveClient = new InvoiceDriveClient($vault);
                    if ($action === 'drive_retry') {
                        (new InvoiceDrive($pdo, $vault, $driveClient))->retry((int) ($_POST['document_id'] ?? 0));
                    } else {
                        $folder = trim((string) ($_POST['folder_id'] ?? $driveSettings['folder_id'] ?? ''));
                        InvoiceDriveClient::assertId($folder);
                        if ($action === 'drive_test') {
                            $driveClient->connect(); $meta = $driveClient->metadata($folder);
                            if (!$meta || ($meta['trashed'] ?? true) || ($meta['mimeType'] ?? '') !== 'application/vnd.google-apps.folder'
                                || !in_array('daniel.ciorcas@welcomehostel.pt', array_column($meta['owners'] ?? [], 'emailAddress'), true)) throw new RuntimeException('drive_account_mismatch');
                        }
                        $pdo->prepare('UPDATE invoice_drive_settings SET folder_id=?, state=?, checked_at=? WHERE id=1')
                            ->execute([$folder, $action === 'drive_test' ? 'ready' : 'configured', $action === 'drive_test' ? gmdate('Y-m-d H:i:s') : null]);
                    }
                } finally { $pdo->query("SELECT RELEASE_LOCK('room_check_invoices')"); }
            } elseif ($action === 'notifications') {
                $recipient = (int) ($_POST['recipient'] ?? 0);
                $template = trim((string) ($_POST['template_name'] ?? ''));
                if (!in_array($recipient, array_map('intval', array_column($managers, 'id')), true) || !preg_match('/\A[a-z0-9_]{1,100}\z/', $template)) throw new RuntimeException('invalid_request');
                $pdo->prepare('UPDATE invoice_notification_settings SET enabled = ?, recipient_user_id = ?, template_name = ? WHERE id = 1')
                    ->execute([(int) isset($_POST['enabled']), $recipient, $template]);
            } else throw new RuntimeException('invalid_request');
        }
        Auth::audit($pdo, (int) $currentUser['id'], 'invoices_' . $action, ['account_id' => $id]);
        $_SESSION['invoice_flash'] = in_array($action, ['collect', 'preflight', 'login'], true) ? 'requested' : 'saved';
        header('Location: invoices.php?' . http_build_query(['period' => $period, 'account' => $accountId, 'property' => $property]), true, 303); exit;
    } catch (Throwable $e) { $error = isset(InvoiceText::TEXT[$e->getMessage()]) ? $e->getMessage() : 'worker_failed'; }
}
$flash = $_SESSION['invoice_flash'] ?? null; unset($_SESSION['invoice_flash']);
$device = $isGerente ? ($_SESSION['invoice_device_token'] ?? null) : null; unset($_SESSION['invoice_device_token']);
$documents = []; $jobs = []; $alerts = []; $driveAlerts = [];
if ($settings) {
    $where = 'd.period = ? AND d.id < ?'; $params = [$period, max(1, (int) ($_GET['before'] ?? PHP_INT_MAX))];
    if ($accountId) { $where .= ' AND d.account_id = ?'; $params[] = $accountId; }
    if ($property !== '') { $where .= ' AND d.property_id = ?'; $params[] = $property; }
    $s = $pdo->prepare('SELECT d.*, x.company_state, x.drive_state, x.attempts AS drive_attempts, x.next_attempt_at, x.last_error, x.toconline_state, a.portal, a.label AS account_label, p.label AS property_label FROM invoice_documents d LEFT JOIN invoice_document_delivery x ON x.document_id=d.id JOIN invoice_accounts a ON a.id = d.account_id LEFT JOIN invoice_account_properties p ON p.account_id = d.account_id AND p.property_id = d.property_id WHERE ' . $where . ' ORDER BY d.id DESC LIMIT 51');
    $s->execute($params); $documents = $s->fetchAll(PDO::FETCH_ASSOC);
    $jobs = $pdo->query('SELECT t.*, a.portal, a.label AS account_label FROM invoice_tasks t JOIN invoice_accounts a ON a.id = t.account_id ORDER BY t.id DESC LIMIT 30')->fetchAll(PDO::FETCH_ASSOC);
    if ($isGerente) $driveAlerts = $pdo->query('SELECT n.*, a.label AS account_label FROM invoice_drive_alerts n JOIN invoice_accounts a ON a.id=n.account_id ORDER BY n.id DESC LIMIT 10')->fetchAll(PDO::FETCH_ASSOC);
    if ($isGerente) $alerts = $pdo->query('SELECT n.*, a.label AS account_label FROM invoice_failure_alerts n JOIN invoice_tasks t ON t.id = n.task_id JOIN invoice_accounts a ON a.id = t.account_id ORDER BY n.id DESC LIMIT 10')->fetchAll(PDO::FETCH_ASSOC);
}
$hasMore = count($documents) > 50; $documents = array_slice($documents, 0, 50);
function ie(string $value): string { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
function it(string $key): string { return ie(InvoiceText::get($key)); }
function invoiceTime(string $value): string { return (new DateTimeImmutable($value, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Europe/Lisbon'))->format('d/m/Y H:i'); }
function invoiceHidden(string $action, int $account = 0): void { ?><input type="hidden" name="csrf_token" value="<?= ie(Csrf::token()) ?>"><input type="hidden" name="action" value="<?= ie($action) ?>"><input type="hidden" name="account_id" value="<?= $account ?>"><?php }
?>
<!doctype html><html lang="<?= ie(Translator::locale()) ?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex,nofollow"><title><?= it('title') ?> — <?= ie(PortalBrand::name()) ?></title><link rel="stylesheet" href="assets/settings.css"><link rel="stylesheet" href="../assets/session.css"><link rel="stylesheet" href="assets/invoices.css"></head>
<body><main class="settings-shell">
<?php SessionBar::render($currentUser, '..', Auth::hasPermission($pdo, $currentUser, Auth::PERMISSION_USERS_MANAGE), Auth::hasPermission($pdo, $currentUser, Auth::PERMISSION_PERMISSIONS_MANAGE)); ?>
<header class="page-header compact-page-header"><div class="compact-page-heading"><p class="eyebrow"><?= it('eyebrow') ?></p><h1 class="page-title"><?= it('title') ?></h1></div><p><?= it('scope') ?></p></header>
<?php if ($error): ?><div class="alert" role="alert"><?= it($error) ?></div><?php endif; ?>
<?php if ($flash): ?><div class="success" role="status"><?= it($flash) ?></div><?php endif; ?>
<?php if ($device): ?><div class="notice"><p><?= it('token_once') ?> <?= (int) $device['account'] ?></p><code class="invoice-secret"><?= ie($device['token']) ?></code></div><?php endif; ?>
<?php if ($settings): ?>
<?php if (strtotime(($settings['worker_seen_at'] ?? '') . ' UTC') < time() - 300): ?><div class="notice"><?= it('worker_stale') ?></div><?php endif; ?>
<section class="card"><h2><?= it('accounts') ?></h2><div class="invoice-table-wrap"><table><thead><tr><th><?= it('platform') ?></th><th><?= it('account') ?></th><th><?= it('property') ?></th><th><?= it('state') ?></th><th><?= it('schedule') ?></th></tr></thead><tbody>
<?php foreach ($accounts as $a): ?><tr><td><?= ie(InvoiceAccounts::PORTALS[$a['portal']]) ?></td><td><a href="?<?= ie(http_build_query(['account' => $a['id'], 'period' => $period])) ?>"><?= ie($a['label']) ?></a></td><td><?= ie(implode(', ', $properties[$a['id']])) ?></td><td><?= it($a['status']) ?></td><td><?= it((int) $a['enabled'] ? 'enabled' : 'disabled') ?></td></tr><?php endforeach; ?>
</tbody></table></div></section>
<section class="card"><h2><?= it('collect') ?></h2><p><?= it('background') ?></p>
<form method="get" class="form-grid"><label class="field"><span><?= it('account') ?></span><select name="account"><option value="0"><?= it('all_accounts') ?></option><?php foreach ($accounts as $a): ?><option value="<?= (int) $a['id'] ?>" <?= (int) $a['id'] === $accountId ? 'selected' : '' ?>><?= ie(InvoiceAccounts::PORTALS[$a['portal']] . ' — ' . $a['label']) ?></option><?php endforeach; ?></select></label><label class="field"><span><?= it('period') ?></span><input type="month" name="period" value="<?= ie($period) ?>" required></label><button class="primary-button"><?= it('filter') ?></button></form>
<form method="post"><?php invoiceHidden('collect', $accountId); ?><input type="hidden" name="period" value="<?= ie($period) ?>"><?php if ($accountId): ?><label class="field"><span><?= it('property') ?></span><select name="property"><option value=""><?= it('all') ?></option><?php foreach ($properties[$accountId] as $id => $label): ?><option value="<?= ie((string) $id) ?>"><?= ie($label) ?></option><?php endforeach; ?></select></label><p><?= it($repository->get($accountId)['period_basis']) ?></p><?php endif; ?><div class="form-actions"><?php if ($canRun): ?><button class="primary-button"><?= it('collect') ?></button><?php endif; ?><a href="?<?= ie(http_build_query(['account' => $accountId, 'period' => $period])) ?>"><?= it('refresh') ?></a></div></form></section>
<section class="card"><h2><?= it('invoices') ?></h2><?php if (!$documents): ?><p><?= it('empty') ?></p><?php else: ?><div class="invoice-table-wrap"><table><thead><tr><th><?= it('account') ?></th><th><?= it('property') ?></th><th><?= it('number') ?></th><th><?= it('date') ?></th><th><?= it('state') ?></th><th><?= it('company') ?></th><th><?= it('drive') ?></th><th><?= it('toconline') ?></th><th><?= it('download') ?></th></tr></thead><tbody><?php foreach ($documents as $d): ?><tr><td><?= ie(InvoiceAccounts::PORTALS[$d['portal']] . ' — ' . $d['account_label']) ?></td><td><?= ie($d['property_label'] ?? $d['property_id']) ?></td><td><?= ie($d['invoice_number']) ?></td><td><?= ie($d['issued_on'] ?? '—') ?><br><small><?= it($d['period_basis']) ?></small></td><td><?= it($d['pipeline_state']) ?></td><td><?= it($d['company_state'] ?? 'review') ?></td><td><?= it('drive_' . ($d['drive_state'] ?? 'pending')) ?><br><small><?= it('attempts') ?>: <?= (int) ($d['drive_attempts'] ?? 0) ?>/9</small>
<?php if ($d['next_attempt_at']): ?><br><small><?= it('next_attempt') ?>: <?= ie(invoiceTime($d['next_attempt_at'])) ?></small><?php endif; ?>
<?php if ($d['last_error']): ?><br><small><?= it($d['last_error']) ?></small><?php endif; ?>
<?php if ($isGerente && in_array($d['drive_state'], ['failed','retry'], true)): ?><form method="post"><?php invoiceHidden('drive_retry'); ?><input type="hidden" name="document_id" value="<?= (int) $d['id'] ?>"><button class="primary-button"><?= it('retry_now') ?></button></form><?php endif; ?>
</td><td><?= it($d['toconline_state'] ?? 'pending') ?></td><td><a href="invoice-download.php?document=<?= (int) $d['id'] ?>"><?= ie(strtoupper($d['format'])) ?></a></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?><?php if ($hasMore): ?><a href="?<?= ie(http_build_query(['account' => $accountId, 'period' => $period, 'before' => end($documents)['id']])) ?>"><?= it('older') ?></a><?php endif; ?><p class="invoice-note"><?= it('airbnb_pipeline') ?></p><p><?= it('toconline_note') ?></p></section>
<section class="card"><h2><?= it('history') ?></h2><div class="invoice-table-wrap"><table><thead><tr><th><?= it('created') ?></th><th><?= it('account') ?></th><th><?= it('period') ?></th><th><?= it('state') ?></th><th><?= it('counts') ?></th></tr></thead><tbody><?php foreach ($jobs as $job): ?><tr><td><?= ie(invoiceTime($job['created_at'])) ?><br><small><?= it($job['kind']) ?></small></td><td><?= ie(InvoiceAccounts::PORTALS[$job['portal']] . ' — ' . $job['account_label']) ?><br><small><?= ie($properties[$job['account_id']][$job['property_id']] ?? '') ?></small></td><td><?= ie($job['period']) ?></td><td><?= it($job['state']) ?><?php if ($job['result_code']): ?><br><small><?= it($job['result_code']) ?></small><?php endif; ?></td><td><?= (int) $job['imported_count'] ?> / <?= (int) $job['duplicate_count'] ?></td></tr><?php endforeach; ?></tbody></table></div></section>
<?php if ($isGerente): ?>
<section class="card"><h2><?= it('drive') ?></h2><p><?= it('drive_note') ?></p><p><?= it($driveSettings['state'] ?? 'not_configured') ?></p>
<form method="post" action="invoice-drive.php"><?php invoiceHidden('connect'); ?><button class="primary-button"><?= it('drive_connect') ?></button></form>
<form method="post"><?php invoiceHidden('drive_settings'); ?><label class="field"><span><?= it('folder_id') ?></span><input name="folder_id" value="<?= ie($driveSettings['folder_id'] ?? '') ?>" maxlength="128" required></label><div class="form-actions"><button class="primary-button" name="action" value="drive_settings"><?= it('save') ?></button><button class="primary-button" name="action" value="drive_test"><?= it('drive_test') ?></button></div></form>
<p class="invoice-note"><?= it('drive_setup_note') ?></p></section>
<section class="card"><h2><?= it('settings') ?></h2><p><?= $service->browserReady() ? it('ready') : it('preflight_required') ?></p>
<?php if ($accountId): $a = $repository->get($accountId); ?>
<h3><?= ie(InvoiceAccounts::PORTALS[$a['portal']] . ' — ' . $a['label']) ?></h3>
<form method="post" class="form-actions"><?php invoiceHidden('preflight', $accountId); ?><button class="primary-button" name="action" value="preflight"><?= it('preflight') ?></button><button class="primary-button" name="action" value="login"><?= it('login') ?></button></form>
<details><summary><?= it('credentials_heading') ?></summary><p><?= it('secret_note') ?></p><form method="post" autocomplete="off"><?php invoiceHidden('credentials', $accountId); ?><fieldset <?= !$vault ? 'disabled' : '' ?>><div class="form-grid">
<?php foreach (['identifier', 'password', 'hostel_number'] as $field): ?><label class="field"><span><?= it($field) ?></span><input type="<?= $field === 'password' ? 'password' : 'text' ?>" name="<?= ie($field) ?>" autocomplete="new-password" maxlength="2048"></label><?php endforeach; ?>
<label class="field"><span><?= it('auth_method') ?></span><select name="auth_method"><?php foreach (InvoiceAccounts::METHODS as $method): ?><option value="<?= ie($method) ?>" <?= $method === $a['auth_method'] ? 'selected' : '' ?>><?= it('method_' . $method) ?></option><?php endforeach; ?></select></label></div>
<details><summary><?= it('method_sms') ?></summary><p><?= it('sms_note') ?></p><div class="form-grid"><?php foreach (['sms_sender', 'sms_keyword', 'sms_sim'] as $field): ?><label class="field"><span><?= it($field) ?></span><input name="<?= ie($field) ?>" maxlength="190"></label><?php endforeach; ?></div></details>
<details><summary><?= it('method_email') ?></summary><div class="form-grid"><?php foreach (['imap_host', 'imap_user', 'imap_password', 'imap_mailbox', 'email_sender', 'email_recipient', 'email_subject'] as $field): ?><label class="field"><span><?= it($field) ?></span><input type="<?= $field === 'imap_password' ? 'password' : 'text' ?>" name="<?= ie($field) ?>" autocomplete="new-password" maxlength="2048"></label><?php endforeach; ?></div></details>
<details><summary><?= it('method_totp') ?></summary><p><?= it('totp_note') ?></p><label class="field"><span><?= it('totp_secret') ?></span><input type="password" name="totp_secret" autocomplete="new-password" maxlength="128"></label></details>
<div class="form-actions"><button class="primary-button"><?= it('save_credentials') ?></button></div></fieldset></form></details>
<form method="post" class="form-actions"><?php invoiceHidden('sms_token', $accountId); ?><button class="primary-button"><?= it('sms_token') ?></button></form>
<form method="post"><?php invoiceHidden('schedule', $accountId); ?><div class="form-grid"><label class="check-row"><input type="checkbox" name="enabled" <?= (int) $a['enabled'] ? 'checked' : '' ?>><span><?= it('schedule') ?></span></label><label class="field"><span><?= it('day') ?></span><input type="number" name="day" min="1" max="28" value="<?= (int) $a['schedule_day'] ?>" required></label><label class="field"><span><?= it('time') ?></span><input type="time" name="time" value="<?= ie($a['schedule_time']) ?>" required></label></div><p><?= it('schedule_note') ?></p><button class="primary-button"><?= it('save') ?></button></form>
<?php else: ?><p><?= it('select_account') ?></p><?php endif; ?>
</section>
<section class="card"><details><summary><?= it('create_account') ?></summary><p><?= it('account_note') ?></p><form method="post"><?php invoiceHidden('create_account'); ?><div class="form-grid"><label class="field"><span><?= it('platform') ?></span><select name="portal"><?php foreach (InvoiceAccounts::PORTALS as $key => $label): ?><option value="<?= ie($key) ?>"><?= ie($label) ?></option><?php endforeach; ?></select></label><label class="field"><span><?= it('account_name') ?></span><input name="label" maxlength="120" required></label><label class="field"><span><?= it('auth_method') ?></span><select name="auth_method"><?php foreach (InvoiceAccounts::METHODS as $method): ?><option value="<?= ie($method) ?>"><?= it('method_' . $method) ?></option><?php endforeach; ?></select></label>
<?php foreach ([1, 2] as $n): ?><label class="field"><span><?= it('portal_property_id') ?> <?= $n ?></span><input name="property_id_<?= $n ?>" maxlength="64"></label><label class="field"><span><?= it('property') ?> <?= $n ?></span><input name="property_label_<?= $n ?>" maxlength="120"></label><?php endforeach; ?></div><button class="primary-button"><?= it('create_account') ?></button></form></details></section>
<section class="card"><h2><?= it('failure_alerts') ?></h2><p><?= it('alerts_note') ?></p><form method="post"><?php invoiceHidden('notifications'); ?><div class="form-grid"><label class="field"><span><?= it('manager') ?></span><select name="recipient" required><option value=""><?= it('select_manager') ?></option><?php foreach ($managers as $m): ?><option value="<?= (int) $m['id'] ?>" <?= (int) ($notifications['recipient_user_id'] ?? 0) === (int) $m['id'] ? 'selected' : '' ?>><?= ie($m['display_name']) ?></option><?php endforeach; ?></select></label><label class="field"><span><?= it('template_name') ?></span><input name="template_name" value="<?= ie($notifications['template_name'] ?? 'invoice_collection_failed_v1') ?>" pattern="[a-z0-9_]+" required></label><label class="check-row"><input type="checkbox" name="enabled" <?= !empty($notifications['enabled']) ? 'checked' : '' ?>><span><?= it('enable_alerts') ?></span></label></div><button class="primary-button"><?= it('save') ?></button></form>
<?php if ($driveAlerts): ?><ul><?php foreach ($driveAlerts as $alert): ?><li>Google Drive — <?= ie($alert['account_label']) ?> — <?= ie($alert['period']) ?> — <?= it('alert_' . $alert['state']) ?><br><?= it($alert['error_code']) ?></li><?php endforeach; ?></ul><?php endif; ?>
<?php if ($alerts): ?><ul><?php foreach ($alerts as $alert): ?><li><?= ie($alert['account_label']) ?> — <?= it('alert_' . $alert['state']) ?></li><?php endforeach; ?></ul><?php endif; ?></section>
<?php endif; ?>
<?php endif; ?></main></body></html>
