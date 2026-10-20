<?php
declare(strict_types=1);
// CLI only. Run from the checked-out repository before deploying its application files.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
umask(0077);
ini_set('display_errors','0');
$repo=dirname(__DIR__);
$app=$argv[1] ?? '/home/welcome/public_html/check';
$backupRoot=$argv[2] ?? '/home/welcome/room-check-backups';
$stage='preconditions';$locked=false;
function runCommand(array $args): string {
    $p = proc_open($args, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($p)) throw new RuntimeException('command_start');
    $out = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $err = stream_get_contents($pipes[2]); fclose($pipes[2]);
    if (proc_close($p) !== 0) throw new RuntimeException('command_failed');
    return trim($out);
}
function qi(string $s): string { return '`' . str_replace('`', '``', $s) . '`'; }
function savePrivate(string $path, string $content): void {
    if (file_put_contents($path, $content, LOCK_EX) !== strlen($content)) throw new RuntimeException('write_failed');
    chmod($path, 0600);
}
try {
    if (!is_dir($app) || !is_file($app.'/lib.php')) throw new RuntimeException('application_missing');
    require $app.'/lib.php';
    $pdo=database();
    $installed=$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('invoice_account_settings','invoice_property_settings','invoice_batches','invoice_batch_tasks')")->fetchColumn();
    if ((int)$installed===4) { echo json_encode(['ok'=>true,'migration'=>'029','already_installed'=>true])."\n"; exit; }
    if (runCommand(['git','-C',$repo,'status','--porcelain'])!=='') throw new RuntimeException('dirty_repository');
    $head=runCommand(['git','-C',$repo,'rev-parse','HEAD']);
    $backup=$backupRoot.'/invoice-workspace-'.substr($head,0,12);
    if ((int)$pdo->query("SELECT GET_LOCK('room_check_invoices',0)")->fetchColumn()!==1) throw new RuntimeException('worker_busy');
    $locked=true;
    $tables=$pdo->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM);
        if (!is_dir($backup) && !mkdir($backup, 0700, true)) throw new RuntimeException('backup_directory');
        if (!is_file($backup . '/complete.json')) {
            $stage = 'application_backup';
            runCommand(['tar', '-czf', $backup . '/application.tar.gz', '-C', dirname($app), basename($app)]);
            chmod($backup . '/application.tar.gz', 0600);
            $stage = 'database_backup';
            // Use native dump tooling, with a short-lived private defaults file.
            $dbConfig = (require $app . '/config.php')['db'];
            $defaults = $backup . '/mysql-client.cnf';
            $escape = static fn($v) => '"' . str_replace(["\\", '"', "\n", "\r"], ["\\\\", '\\"', '\\n', '\\r'], (string)$v) . '"';
            savePrivate($defaults, "[client]\nuser=" . $escape($dbConfig['user']) . "\npassword=" . $escape($dbConfig['pass']) . "\nhost=" . $escape($dbConfig['host']) . "\nport=" . (int)$dbConfig['port'] . "\n");
            try {
                runCommand(['mysqldump', '--defaults-extra-file=' . $defaults, '--single-transaction', '--quick', '--hex-blob', '--routines', '--triggers', '--events', '--no-tablespaces', '--result-file=' . $backup . '/database.sql', $dbConfig['name']]);
            } finally { if (is_file($defaults)) unlink($defaults); unset($dbConfig); }
            chmod($backup . '/database.sql', 0600);
            if (filesize($backup . '/database.sql') < 100 || filesize($backup . '/application.tar.gz') < 100) throw new RuntimeException('backup_empty');
            savePrivate($backup . '/complete.json', json_encode(['time' => gmdate('c'), 'repo_head' => $head, 'tables' => count($tables), 'application_sha256' => hash_file('sha256', $backup . '/application.tar.gz'), 'database_sha256' => hash_file('sha256', $backup . '/database.sql')], JSON_PRETTY_PRINT));
        }

    $stage='migration_029';
    $pdo->exec(file_get_contents($repo.'/migrations/029_invoice_workspace.sql'));
    foreach (['invoice_account_settings','invoice_property_settings','invoice_batches','invoice_batch_tasks'] as $table) $pdo->query('SELECT 1 FROM '.qi($table).' LIMIT 1');
    echo json_encode(['ok'=>true,'migration'=>'029','repo_head'=>$head,'backup'=>$backup])."\n";
} catch (Throwable $e) {
    echo json_encode(['ok'=>false,'stage'=>$stage,'error_type'=>get_class($e)])."\n";
    exit(1);
} finally {
    if ($locked) $pdo->query("SELECT RELEASE_LOCK('room_check_invoices')");
}
