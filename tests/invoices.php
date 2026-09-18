<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/src/Invoices/InvoiceRunner.php';
require_once dirname(__DIR__) . '/src/Auth/Auth.php';

$checks = 0;
function checkInvoice(bool $ok, string $message): void {
    global $checks;
    $checks++;
    if (!$ok) throw new RuntimeException($message);
}
function rejectsInvoice(callable $fn, string $message): void {
    try { $fn(); } catch (Throwable) { checkInvoice(true, $message); return; }
    checkInvoice(false, $message);
}
$tmp = sys_get_temp_dir() . '/invoice-test-' . bin2hex(random_bytes(8));
mkdir($tmp, 0700);
try {
    file_put_contents($tmp . '/master.key', random_bytes(32));
    chmod($tmp . '/master.key', 0600);
    $vault = new InvoiceVault($tmp);
    $vault->save('credentials.enc', ['password' => 'test-only-sensitive-value']);
    checkInvoice(!str_contains((string) file_get_contents($tmp . '/credentials.enc'), 'test-only-sensitive-value'), 'Plaintext leaked');
    checkInvoice($vault->read('credentials.enc')['password'] === 'test-only-sensitive-value', 'Vault round trip');
    copy($tmp . '/credentials.enc', $tmp . '/different.enc'); chmod($tmp . '/different.enc', 0600);
    rejectsInvoice(fn() => $vault->read('different.enc'), 'AAD prevents envelope swapping');
    $envelope = json_decode((string) file_get_contents($tmp . '/credentials.enc'), true);
    $envelope['tag'] = base64_encode(random_bytes(16));
    file_put_contents($tmp . '/credentials.enc', json_encode($envelope));
    rejectsInvoice(fn() => $vault->read('credentials.enc'), 'Tampered ciphertext rejected');
    rejectsInvoice(fn() => $vault->path('../master.key'), 'Traversal rejected');
    mkdir($tmp . '/public_html', 0700);
    symlink($tmp . '/public_html', $tmp . '/public-link');
    rejectsInvoice(fn() => new InvoiceVault($tmp . '/public-link'), 'Symlink into web root rejected');
    rejectsInvoice(fn() => new InvoiceVault($tmp . '/public_html'), 'Web root rejected');

    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec("CREATE TABLE invoice_settings (id INTEGER PRIMARY KEY, enabled INTEGER, schedule_day INTEGER, schedule_time TEXT, browser_ready INTEGER, browser_checked_at TEXT, worker_seen_at TEXT, login_verified_at TEXT);
        INSERT INTO invoice_settings VALUES (1, 0, 5, '04:00', 0, NULL, NULL, NULL);
        CREATE TABLE invoice_tasks (id INTEGER PRIMARY KEY AUTOINCREMENT, account_id INTEGER DEFAULT 1, attempts INTEGER DEFAULT 0, next_attempt_at TEXT, kind TEXT, property_id TEXT, period TEXT, state TEXT DEFAULT 'queued', active_key TEXT UNIQUE, schedule_key TEXT UNIQUE, requested_by INTEGER, created_at TEXT, started_at TEXT, finished_at TEXT, result_code TEXT, imported_count INTEGER DEFAULT 0, duplicate_count INTEGER DEFAULT 0);
        CREATE TABLE invoice_documents (id INTEGER PRIMARY KEY AUTOINCREMENT, account_id INTEGER DEFAULT 1, period_basis TEXT, format TEXT, pipeline_state TEXT, property_id TEXT, invoice_number TEXT, issued_on TEXT, period TEXT, sha256 TEXT, size_bytes INTEGER, job_id INTEGER, created_at TEXT, UNIQUE(account_id,property_id,invoice_number), UNIQUE(account_id,property_id,sha256));");
    $pdo->exec("CREATE TABLE invoice_accounts (id INTEGER PRIMARY KEY,portal TEXT,label TEXT,enabled INTEGER DEFAULT 0,schedule_day INTEGER DEFAULT 5,schedule_time TEXT DEFAULT '04:00',period_basis TEXT DEFAULT 'issue_month',auth_method TEXT DEFAULT 'password',status TEXT,login_verified_at TEXT,sms_token_hash TEXT);
    INSERT INTO invoice_accounts(id,portal,label) VALUES (1,'booking','Test');
    CREATE TABLE invoice_account_properties (account_id INTEGER,property_id TEXT,label TEXT);
    INSERT INTO invoice_account_properties VALUES (1,'1140306','Welcome'),(1,'539828','City');
    CREATE TABLE invoice_auth_challenges (id TEXT,task_id INTEGER,state TEXT);
    CREATE TABLE invoice_failure_alerts (id INTEGER PRIMARY KEY AUTOINCREMENT,task_id INTEGER,dedupe_key TEXT UNIQUE,state TEXT DEFAULT 'pending',result_code TEXT,created_at TEXT);
    CREATE TABLE invoice_drive_alerts (id INTEGER PRIMARY KEY,state TEXT);
    CREATE TABLE invoice_notification_settings (id INTEGER PRIMARY KEY,enabled INTEGER);
    INSERT INTO invoice_notification_settings VALUES (1,0);");
    $service = new InvoiceService($pdo);
    $first = $service->enqueue('collect', '1140306', '2026-08', 1);
    checkInvoice($first === $service->enqueue('collect', '1140306', '2026-08', 1), 'Double click creates one active job');
    rejectsInvoice(fn() => $service->enqueue('collect', '999', '2026-08', 1), 'Unknown property rejected');
    rejectsInvoice(fn() => $service->enqueue('collect', '1140306', '2026-13', 1), 'Invalid month rejected');
    $service->scheduleDue(new DateTimeImmutable('2026-09-06T12:00:00Z'));
    checkInvoice((int) $pdo->query('SELECT count(*) FROM invoice_tasks')->fetchColumn() === 1, 'Disabled means no schedule');
    $service->saveSchedule(true, 5, '04:00');
    $pdo->exec('DELETE FROM invoice_tasks');
    $service->scheduleDue(new DateTimeImmutable('2026-09-05T02:59:00Z'));
    checkInvoice((int) $pdo->query('SELECT count(*) FROM invoice_tasks')->fetchColumn() === 0, 'Lisbon schedule not early');
    $service->scheduleDue(new DateTimeImmutable('2026-09-05T03:00:00Z'));
    checkInvoice((int) $pdo->query('SELECT count(*) FROM invoice_tasks')->fetchColumn() === 2, 'Lisbon summer time schedule');
    $pdo->exec("UPDATE invoice_tasks SET active_key = NULL, state = 'completed'");
    $service->scheduleDue(new DateTimeImmutable('2026-09-15T10:00:00Z'));
    checkInvoice((int) $pdo->query('SELECT count(*) FROM invoice_tasks')->fetchColumn() === 2, 'One scheduled job per property and month');
    $service->scheduleDue(new DateTimeImmutable('2027-01-05T03:59:00Z'));
    checkInvoice((int) $pdo->query('SELECT count(*) FROM invoice_tasks')->fetchColumn() === 2, 'Lisbon winter schedule not early');
    $service->scheduleDue(new DateTimeImmutable('2027-01-05T04:00:00Z'));
    checkInvoice((int) $pdo->query("SELECT count(*) FROM invoice_tasks WHERE period = '2026-12'")->fetchColumn() === 2, 'Year rollover');

    $job = ['id' => 1, 'property_id' => '1140306', 'period' => '2026-08'];
    $doc = ['number' => 'INV-123', 'issued_on' => '2026-08-03', 'pdf' => base64_encode('%PDF-1.7 test fixture')];
    checkInvoice($service->import($vault, $job, $doc), 'First invoice stored');
    checkInvoice(!$service->import($vault, $job, $doc), 'Duplicate number/hash skipped');
    rejectsInvoice(fn() => $service->import($vault, $job, array_replace($doc, ['pdf' => base64_encode('%PDF-1.7 changed')])), 'Changed same invoice rejected');
    rejectsInvoice(fn() => $service->import($vault, $job, array_replace($doc, ['issued_on' => '2026-07-03'])), 'Wrong period rejected');
    rejectsInvoice(fn() => $service->import($vault, $job, array_replace($doc, ['pdf' => base64_encode('<html>Login</html>')])), 'Login HTML rejected');
    checkInvoice($service->import($vault, array_replace($job, ['property_id' => '539828']), $doc), 'Properties remain distinct');
    foreach (array_keys(Auth::ROLES) as $role) {
        if ($role !== 'gerente') rejectsInvoice(fn() => InvoiceService::assertGerente(['role' => $role]), 'Only gerente configures');
    }
    checkInvoice(in_array(Auth::PERMISSION_INVOICES_VIEW, Auth::LOCKED_ROLE_PERMISSIONS['gerente'], true), 'Gerente access cannot be removed');
    checkInvoice(in_array(Auth::PERMISSION_INVOICES_VIEW, Auth::normalizePermissions([Auth::PERMISSION_INVOICES_RUN]), true), 'Run includes view');
    checkInvoice(!Auth::defaultRoleHasPermission('governanta', Auth::PERMISSION_INVOICES_VIEW), 'No new access granted to other profiles');
    $node = getenv('TEST_NODE_BINARY') ?: '/usr/bin/node';
    if (!is_executable($node)) throw new RuntimeException('TEST_NODE_BINARY must identify Node');
    $fakeRunner = $tmp . '/fixture-runner.mjs';
    file_put_contents($fakeRunner, <<<'JS'
import fs from 'node:fs/promises';
let raw = ''; for await (const chunk of process.stdin) raw += chunk;
const input = JSON.parse(raw);
const code = input.action === 'preflight' ? 'ok' : (await fs.readFile(input.privateDir + '/fixture-code', 'utf8')).trim();
if (code === 'throw') { process.stderr.write('test-sensitive-diagnostic'); process.exit(1); }
process.stdout.write(JSON.stringify({code, documents: []}));
JS);
    chmod($fakeRunner, 0600);
    $vault->save('booking-credentials.enc', ['identifier' => 'fixture', 'password' => 'fixture']);
    $runner = new InvoiceRunner($pdo, ['private_dir' => $tmp, 'node_binary' => $node, 'runner_script' => $fakeRunner]);
    $pdo->exec('DELETE FROM invoice_tasks; UPDATE invoice_accounts SET enabled = 0');
    $preflight = $service->enqueue('preflight', '1140306', '2026-08', 1);
    $runner->run();
    checkInvoice($service->browserReady(), 'Runner preflight updates readiness');
    file_put_contents($tmp . '/fixture-code', 'needs_auth');
    $first = $service->enqueue('collect', '1140306', '2026-08', 1);
    $second = $service->enqueue('collect', '539828', '2026-08', 1);
    $runner->run();
    checkInvoice((int) $pdo->query("SELECT COUNT(*) FROM invoice_tasks WHERE state = 'needs_auth'")->fetchColumn() === 2, 'Auth failure does not block following property');
    checkInvoice((int) $service->settings()['enabled'] === 0, 'Auth failure disables scheduling');
    file_put_contents($tmp . '/fixture-code', 'no_invoices');
    $retry = $service->enqueue('collect', '1140306', '2026-08', 1);
    $runner->run();
    checkInvoice($pdo->query("SELECT state FROM invoice_tasks WHERE id = $retry")->fetchColumn() === 'completed', 'Empty confirmed period completes');
    checkInvoice(!empty($pdo->query('SELECT login_verified_at FROM invoice_accounts WHERE id=1')->fetchColumn()), 'Successful portal read validates access');
    $pdo->exec("UPDATE invoice_tasks SET state = 'running' WHERE id = $retry");
    $runner->run();
    checkInvoice($pdo->query("SELECT result_code FROM invoice_tasks WHERE id = $retry")->fetchColumn() === 'interrupted', 'Interrupted jobs do not remain running');
    file_put_contents($tmp . '/fixture-code', 'throw');
    $retry = $service->enqueue('collect', '1140306', '2026-08', 1);
    $runner->run();
    checkInvoice($pdo->query("SELECT result_code FROM invoice_tasks WHERE id = $retry")->fetchColumn() === 'worker_failed', 'Child errors are sanitized');
    echo "Invoice checks passed: $checks\n";
} finally {
    foreach (glob($tmp . '/*') ?: [] as $path) {
        if (is_link($path) || is_file($path)) unlink($path);
    }
    if (is_dir($tmp . '/public_html')) rmdir($tmp . '/public_html');
    rmdir($tmp);
}
