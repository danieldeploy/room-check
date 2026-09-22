<?php
declare(strict_types=1);
// Private CLI deployment gate. This file is never copied to public_html.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

function hubBackupRequire(bool $condition, string $code): void {
    if (!$condition) { throw new RuntimeException($code); }
}

function hubBackupWrite(string $path, string $contents): void {
    hubBackupRequire(file_put_contents($path, $contents, LOCK_EX) === strlen($contents), 'backup_write');
    hubBackupRequire(chmod($path, 0600), 'backup_permissions');
}

function hubBackupRun(array $args): string {
    // Discard stderr: native tools may include credentials or private paths in it.
    $process = proc_open($args, [0 => ['file', '/dev/null', 'r'],
        1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    hubBackupRequire(is_resource($process), 'backup_command_start');
    $output = stream_get_contents($pipes[1], 1024 * 1024);
    fclose($pipes[1]);
    hubBackupRequire(proc_close($process) === 0, 'backup_command_failed');
    return trim((string)$output);
}

function hubBackupBytes(string $directory): int {
    $total = 0;
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
        $directory, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $item) {
        if (!$item->isLink() && $item->isFile()) { $total += $item->getSize(); }
    }
    return $total;
}

function hubBackupSnapshot(string $app, string $private, string $root, string $sha,
    array $database, int $databaseBytes, callable $run = 'hubBackupRun'): string {
    hubBackupRequire((bool)preg_match('/^[a-f0-9]{40}$/D', $sha), 'backup_commit');
    hubBackupRequire(is_dir($app) && !is_link($app), 'backup_application');
    hubBackupRequire(!is_link($private) && !is_link($root), 'backup_symlink');
    hubBackupRequire(is_array($database) && (bool)preg_match('/^[a-zA-Z0-9_]+$/D',
        (string)($database['name'] ?? '')), 'backup_database');
    if (!is_dir($root)) { hubBackupRequire(mkdir($root, 0700, true), 'backup_directory'); }
    hubBackupRequire(chmod($root, 0700), 'backup_permissions');
    $sources = ['application' => $app];
    foreach (['cron', 'invoice-runner', 'migrations'] as $name) {
        $path = $private . '/' . $name;
        hubBackupRequire(!is_link($path), 'backup_symlink');
        if (is_dir($path)) { $sources['private-' . $name] = $path; }
    }
    $bytes = max(0, $databaseBytes);
    foreach ($sources as $source) { $bytes += hubBackupBytes($source); }
    // Filesystem free space is a preflight, not a guarantee of cPanel account quota.
    // Quota exhaustion during archive/dump still fails this gate before deployment.
    $free = disk_free_space($root);
    hubBackupRequire($free !== false && $free > max(128 * 1024 * 1024, $bytes * 2), 'backup_space');
    $destination = $root . '/release-' . gmdate('Ymd-His') . '-' . substr($sha, 0, 12)
        . '-' . bin2hex(random_bytes(6));
    hubBackupRequire(mkdir($destination, 0700), 'backup_directory');
    $defaults = $destination . '/mysql-client.cnf';
    $artifacts = [];
    try {
        foreach ($sources as $name => $source) {
            $archive = $destination . '/' . $name . '.tar.gz';
            $run(['tar', '-czf', $archive, '-C', dirname($source), '--', basename($source)]);
            hubBackupRequire(is_file($archive) && filesize($archive) > 0, 'backup_empty');
            hubBackupRequire(chmod($archive, 0600), 'backup_permissions');
            $run(['tar', '-tzf', $archive]);
            $artifacts[basename($archive)] = hash_file('sha256', $archive);
        }
        $escape = static function ($value): string {
            hubBackupRequire(strpos((string)$value, "\0") === false, 'backup_config');
            return '"' . str_replace(["\\", '"', "\n", "\r"],
                ["\\\\", '\\"', '\\n', '\\r'], (string)$value) . '"';
        };
        hubBackupWrite($defaults, "[client]\nuser=" . $escape($database['user'])
            . "\npassword=" . $escape($database['pass']) . "\nhost=" . $escape($database['host'])
            . "\nport=" . (int)$database['port'] . "\n");
        $dump = $destination . '/database.sql';
        $run(['mysqldump', '--defaults-extra-file=' . $defaults, '--single-transaction',
            '--quick', '--hex-blob', '--routines', '--triggers', '--events', '--no-tablespaces',
            '--result-file=' . $dump, $database['name']]);
        clearstatcache(true, $dump);
        hubBackupRequire(is_file($dump) && filesize($dump) > 100, 'backup_empty');
        hubBackupRequire(chmod($dump, 0600), 'backup_permissions');
        $artifacts['database.sql'] = hash_file('sha256', $dump);
        foreach ($artifacts as $hash) {
            hubBackupRequire(is_string($hash) && strlen($hash) === 64, 'backup_checksum');
        }
        hubBackupWrite($destination . '/complete.json', json_encode([
            'format' => 1, 'created_at' => gmdate('c'), 'target_commit' => $sha,
            'sha256' => $artifacts, 'private_directories' => array_keys($sources),
            'database_consistency' => 'single_transaction_no_concurrent_ddl',
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
        return $destination;
    } finally {
        if (is_file($defaults)) { unlink($defaults); }
    }
}

function hubBackupMain(): int {
    umask(0077);
    ini_set('display_errors', '0');
    $lock = null;
    $pdo = null;
    $workerLocked = false;
    try {
        hubBackupRequire(function_exists('proc_open'), 'backup_process_unavailable');
        $accountHome = realpath((string)getenv('HOME'));
        hubBackupRequire(is_string($accountHome) && is_dir($accountHome), 'backup_account');
        $app = $accountHome . '/public_html/check';
        $private = $accountHome . '/room-check-private';
        $root = $accountHome . '/room-check-backups';
        hubBackupRequire(is_file($app . '/config.php') && !is_link($root), 'backup_application');
        if (!is_dir($root)) { hubBackupRequire(mkdir($root, 0700, true), 'backup_directory'); }
        $lockPath = $root . '/release-backup.lock';
        hubBackupRequire(!is_link($lockPath), 'backup_symlink');
        $lock = fopen($lockPath, 'c');
        hubBackupRequire(is_resource($lock) && flock($lock, LOCK_EX | LOCK_NB), 'backup_busy');
        chmod($lockPath, 0600);
        $repo = dirname(__DIR__);
        hubBackupRequire(hubBackupRun(['git', '-C', $repo, 'status', '--porcelain']) === '', 'dirty_repository');
        $sha = hubBackupRun(['git', '-C', $repo, 'rev-parse', 'HEAD']);
        $config = require $app . '/config.php';
        $db = $config['db'];
        hubBackupRequire((bool)preg_match('/^[a-zA-Z0-9_]+$/D', (string)$db['name'])
            && (bool)preg_match('/^[a-zA-Z0-9_.:-]+$/D', (string)$db['host']), 'backup_database');
        $pdo = new PDO('mysql:host=' . $db['host'] . ';port=' . (int)$db['port']
            . ';dbname=' . $db['name'] . ';charset=utf8mb4', $db['user'], $db['pass'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        hubBackupRequire((int)$pdo->query("SELECT GET_LOCK('room_check_invoices',30)")->fetchColumn() === 1, 'worker_busy');
        $workerLocked = true;
        $nonTransactional = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_type='BASE TABLE' AND engine <> 'InnoDB'")->fetchColumn();
        hubBackupRequire($nonTransactional === 0, 'backup_nontransactional_tables');
        $size = (int)$pdo->query('SELECT COALESCE(SUM(data_length+index_length),0) FROM information_schema.tables WHERE table_schema=DATABASE()')->fetchColumn();
        hubBackupSnapshot($app, $private, $root, $sha, $db, $size);
        unset($db, $config);
        echo json_encode(['ok' => true, 'backup' => 'completed', 'target_commit' => $sha]) . "\n";
        return 0;
    } catch (Throwable $error) {
        // Do not reproduce native errors, credentials, server paths or SQL.
        echo "{\"ok\":false,\"error\":\"release_backup_failed_no_deploy\"}\n";
        return 1;
    } finally {
        if ($workerLocked && $pdo instanceof PDO) {
            try { $pdo->query("SELECT RELEASE_LOCK('room_check_invoices')"); } catch (Throwable $ignored) {}
        }
        if (is_resource($lock)) { flock($lock, LOCK_UN); fclose($lock); }
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) { exit(hubBackupMain()); }
