<?php
declare(strict_types=1);

// Project-wide, append-only migration runner. Kept outside public_html.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

const HUB_MIGRATION_BASELINE_MAX = 29;
const HUB_MIGRATION_LOCK = 'management_hub_schema_migrations';

final class HubMigrationError extends RuntimeException {}

function hubMigrationRequire(bool $condition, string $code): void {
    if (!$condition) { throw new HubMigrationError($code); }
}

/** @return array<int, array{version:int,name:string,path:string,checksum:string}> */
function hubMigrationFiles(string $directory): array {
    hubMigrationRequire(is_dir($directory) && !is_link($directory), 'migration_directory');
    $migrations = [];
    foreach (glob($directory . '/*.sql') ?: [] as $path) {
        $name = basename($path);
        hubMigrationRequire(!is_link($path) && is_file($path), 'migration_file');
        hubMigrationRequire((bool)preg_match('/^(\d{3})_[a-z0-9_]+\.sql$/D', $name, $match), 'migration_filename');
        $version = (int)$match[1];
        hubMigrationRequire($version > 0 && !isset($migrations[$version]), 'migration_duplicate_version');
        $checksum = hash_file('sha256', $path);
        hubMigrationRequire(is_string($checksum) && strlen($checksum) === 64, 'migration_checksum');
        $migrations[$version] = compact('version', 'name', 'path', 'checksum');
    }
    ksort($migrations, SORT_NUMERIC);
    return $migrations;
}

function hubMigrationValidateBaseline(array $files, array $baseline): void {
    $legacy = [];
    foreach ($files as $version => $file) {
        if ($version <= HUB_MIGRATION_BASELINE_MAX) { $legacy[$file['name']] = $file['checksum']; }
    }
    ksort($legacy); ksort($baseline);
    hubMigrationRequire($legacy === $baseline && $legacy !== [], 'migration_baseline_changed');
}

function hubMigrationEnsureLedger(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
        version INT UNSIGNED NOT NULL PRIMARY KEY,
        name VARCHAR(190) NOT NULL,
        checksum CHAR(64) NOT NULL,
        status ENUM('baseline','running','applied','failed') NOT NULL,
        started_at DATETIME NULL,
        applied_at DATETIME NULL,
        execution_ms INT UNSIGNED NULL,
        INDEX idx_schema_migrations_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

/** @param array<int, array{version:int,name:string,path:string,checksum:string}> $files */
function hubMigrationRun(PDO $pdo, array $files, array $baseline): array {
    hubMigrationValidateBaseline($files, $baseline);
    hubMigrationRequire(!$pdo->inTransaction(), 'migration_open_transaction');
    hubMigrationRequire((int)$pdo->query("SELECT GET_LOCK('" . HUB_MIGRATION_LOCK . "', 30)")->fetchColumn() === 1, 'migration_busy');
    try {
        hubMigrationEnsureLedger($pdo);
        $rows = $pdo->query('SELECT version, name, checksum, status FROM schema_migrations ORDER BY version')
            ->fetchAll(PDO::FETCH_ASSOC);
        $known = [];
        foreach ($rows as $row) { $known[(int)$row['version']] = $row; }

        // Existing installations predate the ledger. Record old migrations without
        // replaying them; deployment 029 was already guarded separately.
        if ($known === []) {
            $baseline = $pdo->prepare("INSERT INTO schema_migrations
                (version,name,checksum,status,started_at,applied_at,execution_ms)
                VALUES (:version,:name,:checksum,'baseline',UTC_TIMESTAMP(),UTC_TIMESTAMP(),0)");
            $pdo->beginTransaction();
            try {
                foreach ($files as $version => $migration) {
                    if ($version > HUB_MIGRATION_BASELINE_MAX) { continue; }
                    $parameters = ['version' => $version, 'name' => $migration['name'],
                        'checksum' => $migration['checksum']];
                    $baseline->execute($parameters);
                    $known[$version] = $parameters + ['status' => 'baseline'];
                }
                $pdo->commit();
            } catch (Throwable $error) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                throw $error;
            }
        }

        foreach ($known as $version => $row) {
            hubMigrationRequire(isset($files[$version]), 'migration_file_missing');
            hubMigrationRequire($row['name'] === $files[$version]['name'], 'migration_name_changed');
            hubMigrationRequire(hash_equals((string)$row['checksum'], $files[$version]['checksum']), 'migration_checksum_changed');
            hubMigrationRequire(in_array($row['status'], ['baseline', 'applied'], true), 'migration_incomplete');
        }

        $highest = $known === [] ? HUB_MIGRATION_BASELINE_MAX : max(array_keys($known));
        foreach ($files as $version => $migration) {
            hubMigrationRequire(isset($known[$version]) || $version > $highest, 'migration_out_of_order');
        }

        $applied = [];
        foreach ($files as $version => $migration) {
            if (isset($known[$version]) || $version <= HUB_MIGRATION_BASELINE_MAX) { continue; }
            $started = hrtime(true);
            $running = $pdo->prepare("INSERT INTO schema_migrations
                (version,name,checksum,status,started_at) VALUES (:version,:name,:checksum,'running',UTC_TIMESTAMP())");
            $running->execute(['version' => $version, 'name' => $migration['name'],
                'checksum' => $migration['checksum']]);
            try {
                $sql = file_get_contents($migration['path']);
                hubMigrationRequire(is_string($sql) && trim($sql) !== '', 'migration_empty');
                hubMigrationRequire(hash_equals($migration['checksum'], hash('sha256', $sql)), 'migration_changed_during_run');
                $pdo->exec($sql);
                hubMigrationRequire(!$pdo->inTransaction(), 'migration_unclosed_transaction');
                $elapsed = max(0, (int)round((hrtime(true) - $started) / 1_000_000));
                $done = $pdo->prepare("UPDATE schema_migrations SET status='applied', applied_at=UTC_TIMESTAMP(), execution_ms=:ms WHERE version=:version AND status='running'");
                $done->execute(['ms' => $elapsed, 'version' => $version]);
                hubMigrationRequire($done->rowCount() === 1, 'migration_state');
                $applied[] = $migration['name'];
            } catch (Throwable $error) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                $failed = $pdo->prepare("UPDATE schema_migrations SET status='failed' WHERE version=:version AND status='running'");
                $failed->execute(['version' => $version]);
                throw $error;
            }
        }
        return ['baseline_max' => HUB_MIGRATION_BASELINE_MAX, 'applied' => $applied];
    } finally {
        try { $pdo->query("SELECT RELEASE_LOCK('" . HUB_MIGRATION_LOCK . "')"); } catch (Throwable) {}
    }
}

function hubMigrationMain(array $argv): int {
    ini_set('display_errors', '0');
    $stage = 'preconditions';
    try {
        $app = rtrim((string)($argv[1] ?? ''), '/');
        hubMigrationRequire($app !== '' && is_file($app . '/lib.php'), 'migration_application');
        require_once $app . '/lib.php';
        $stage = 'migrations';
        $baseline = json_decode((string)file_get_contents(__DIR__ . '/migration-baseline.json'), true, 512, JSON_THROW_ON_ERROR);
        $result = hubMigrationRun(database(), hubMigrationFiles(dirname(__DIR__) . '/migrations'), $baseline);
        echo json_encode(['ok' => true] + $result, JSON_THROW_ON_ERROR) . "\n";
        return 0;
    } catch (Throwable $error) {
        // PDOException extends RuntimeException; only our fixed codes may be logged.
        echo json_encode(['ok' => false, 'stage' => $stage, 'error' => $error instanceof HubMigrationError ? $error->getMessage() : 'migration_failed']) . "\n";
        return 1;
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) { exit(hubMigrationMain($argv)); }
