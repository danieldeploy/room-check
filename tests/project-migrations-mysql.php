<?php
declare(strict_types=1);

require dirname(__DIR__) . '/deploy/project_migrations.php';

function migrationAssert(bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
}

$dsn = getenv('INVOICES_TEST_DSN');
if (!$dsn) { fwrite(STDERR, "INVOICES_TEST_DSN is required\n"); exit(2); }
$pdo = new PDO($dsn, (string)getenv('INVOICES_TEST_USER'), (string)getenv('INVOICES_TEST_PASSWORD'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$directory = sys_get_temp_dir() . '/hub-migrations-' . bin2hex(random_bytes(8));
mkdir($directory, 0700);
file_put_contents($directory . '/002_legacy.sql', "CREATE TABLE should_not_run (id INT);\n");
file_put_contents($directory . '/030_test_future.sql', "CREATE TABLE hub_migration_test (id INT PRIMARY KEY);\n");

try {
    $pdo->exec('DROP TABLE IF EXISTS schema_migrations, hub_migration_test, should_not_run');
    $files = hubMigrationFiles($directory);
    $baseline = ['002_legacy.sql' => $files[2]['checksum']];
    $first = hubMigrationRun($pdo, $files, $baseline);
    migrationAssert($first['applied'] === ['030_test_future.sql'], 'future migration was not applied');
    migrationAssert((int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='should_not_run'")->fetchColumn() === 0,
        'legacy migration was replayed');
    migrationAssert((int)$pdo->query("SELECT COUNT(*) FROM schema_migrations WHERE version=2 AND status='baseline'")->fetchColumn() === 1,
        'legacy baseline was not recorded');
    migrationAssert((int)$pdo->query("SELECT COUNT(*) FROM schema_migrations WHERE version=30 AND status='applied'")->fetchColumn() === 1,
        'future migration was not recorded');
    migrationAssert(hubMigrationRun($pdo, $files, $baseline)['applied'] === [], 'migration runner is not idempotent');

    file_put_contents($directory . '/030_test_future.sql', "SELECT 1;\n");
    try {
        hubMigrationRun($pdo, hubMigrationFiles($directory), $baseline);
        throw new RuntimeException('checksum change was accepted');
    } catch (RuntimeException $error) {
        migrationAssert($error->getMessage() === 'migration_checksum_changed', 'unexpected checksum failure');
    }
    file_put_contents($directory . '/030_test_future.sql', "CREATE TABLE hub_migration_test (id INT PRIMARY KEY);\n");
    file_put_contents($directory . '/031_partial_failure.sql', "INSERT INTO hub_migration_test VALUES (1); SELECT * FROM deliberately_missing_private_table;\n");
    try { hubMigrationRun($pdo, hubMigrationFiles($directory), $baseline); throw new LogicException('SQL failure ignored'); }
    catch (PDOException) {}
    migrationAssert($pdo->query('SELECT status FROM schema_migrations WHERE version=31')->fetchColumn() === 'failed', 'failure state lost');
    try { hubMigrationRun($pdo, hubMigrationFiles($directory), $baseline); throw new LogicException('failed migration retried'); }
    catch (HubMigrationError $e) { migrationAssert($e->getMessage() === 'migration_incomplete', 'wrong failure block'); }
    migrationAssert((int)$pdo->query('SELECT COUNT(*) FROM hub_migration_test')->fetchColumn() === 1, 'partial DML was replayed');
    $pdo->exec("UPDATE schema_migrations SET status='running' WHERE version=31");
    try { hubMigrationRun($pdo, hubMigrationFiles($directory), $baseline); throw new LogicException('interrupted migration retried'); }
    catch (HubMigrationError $e) { migrationAssert($e->getMessage() === 'migration_incomplete', 'wrong interruption block'); }

    $pdo->exec('DELETE FROM schema_migrations WHERE version=31');
    unlink($directory . '/031_partial_failure.sql');
    file_put_contents($directory . '/031_unclosed_transaction.sql', 'START TRANSACTION; INSERT INTO hub_migration_test VALUES (2);');
    try { hubMigrationRun($pdo, hubMigrationFiles($directory), $baseline); throw new LogicException('open transaction accepted'); }
    catch (HubMigrationError $e) { migrationAssert($e->getMessage() === 'migration_unclosed_transaction', 'wrong transaction guard'); }
    migrationAssert(!$pdo->inTransaction() && (int)$pdo->query('SELECT COUNT(*) FROM hub_migration_test')->fetchColumn() === 1, 'uncommitted DML leaked');
    migrationAssert($pdo->query('SELECT status FROM schema_migrations WHERE version=31')->fetchColumn() === 'failed', 'failed transaction state lost');
    $pdo->exec('DELETE FROM schema_migrations WHERE version=31');
    unlink($directory . '/031_unclosed_transaction.sql');
    file_put_contents($directory . '/002_legacy.sql', 'SELECT 1;');
    try { hubMigrationRun($pdo, hubMigrationFiles($directory), $baseline); throw new LogicException('legacy edited'); }
    catch (HubMigrationError $e) { migrationAssert($e->getMessage() === 'migration_baseline_changed', 'wrong baseline block'); }
    echo "project migration tests passed\n";
} finally {
    $pdo->exec('DROP TABLE IF EXISTS schema_migrations, hub_migration_test, should_not_run');
    foreach (glob($directory . '/*') ?: [] as $path) { unlink($path); }
    rmdir($directory);
}

// Exercise the real future migrations on the maintained installation schema.
// These historical files complete database.sql ONLY in this disposable CI database.
$repo = dirname(__DIR__);
$pdo->exec((string)file_get_contents($repo . '/database.sql'));
foreach (['016_whatsapp_assignment_reminders.sql', '018_whatsapp_reminders_by_list.sql',
    '026_invoices_portals.sql', '027_invoice_accounts.sql', '028_invoice_drive.sql', '029_invoice_workspace.sql'] as $name) {
    $pdo->exec((string)file_get_contents($repo . '/migrations/' . $name));
}
$baseline = json_decode((string)file_get_contents($repo . '/deploy/migration-baseline.json'), true, 512, JSON_THROW_ON_ERROR);
$files = hubMigrationFiles($repo . '/migrations');
hubMigrationRun($pdo, $files, $baseline);
migrationAssert(hubMigrationRun($pdo, $files, $baseline)['applied'] === [], 'real migration replay');
echo "real project migrations validated on disposable MySQL schema\n";
