<?php
declare(strict_types=1);
// Run only against a disposable database explicitly provided by the test runner.
require_once dirname(__DIR__) . '/src/Invoices/InvoiceService.php';
$dsn = getenv('INVOICES_TEST_DSN');
if (!$dsn) { fwrite(STDERR, "INVOICES_TEST_DSN must point to a disposable MySQL database.\n"); exit(1); }
$pdo = new PDO($dsn, getenv('INVOICES_TEST_USER') ?: 'root', getenv('INVOICES_TEST_PASSWORD') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE IF NOT EXISTS role_permissions (role VARCHAR(40), permission VARCHAR(64), PRIMARY KEY(role,permission))');
$migration = (string) file_get_contents(dirname(__DIR__) . '/migrations/026_invoices_portals.sql');
$pdo->exec($migration);
$pdo->exec($migration);
$service = new InvoiceService($pdo);
if ((int) $service->settings()['enabled'] !== 0) throw new RuntimeException('Default must be disabled');
$id = $service->enqueue('collect', '1140306', '2026-08', 1);
if ($service->enqueue('collect', '1140306', '2026-08', 2) !== $id) throw new RuntimeException('Active-job uniqueness failed');
$pdo->prepare("UPDATE invoice_jobs SET state = 'failed', active_key = NULL WHERE id = ?")->execute([$id]);
if ($service->enqueue('collect', '1140306', '2026-08', 1) === $id) throw new RuntimeException('Manual retry must create a new job');
$other = new PDO($dsn, getenv('INVOICES_TEST_USER') ?: 'root', getenv('INVOICES_TEST_PASSWORD') ?: '');
if ((int) $pdo->query("SELECT GET_LOCK('room_check_invoices',0)")->fetchColumn() !== 1) throw new RuntimeException('Lock failed');
if ((int) $other->query("SELECT GET_LOCK('room_check_invoices',0)")->fetchColumn() !== 0) throw new RuntimeException('Concurrent worker was allowed');
$pdo->query("SELECT RELEASE_LOCK('room_check_invoices')");
$service->saveSchedule(true, 5, '04:00');
$service->scheduleDue(new DateTimeImmutable('2026-10-05T03:00:00Z'));
$service->scheduleDue(new DateTimeImmutable('2026-10-06T03:00:00Z'));
if ((int) $pdo->query("SELECT COUNT(*) FROM invoice_jobs WHERE schedule_key IS NOT NULL")->fetchColumn() !== 2) throw new RuntimeException('Schedule duplicated');
echo "MySQL migration, queue idempotency, retry, advisory lock and schedule passed.\n";
