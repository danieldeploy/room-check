<?php
declare(strict_types=1);

// Private CLI tool. Only the marked block and exact audited legacy lines are owned.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
const HUB_CRON_BEGIN = '# BEGIN MANAGEMENT-HUB MANAGED';
const HUB_CRON_END = '# END MANAGEMENT-HUB MANAGED';
final class HubCronError extends RuntimeException {}

function hubCronRequire(bool $condition, string $code): void {
    if (!$condition) { throw new HubCronError($code); }
}

function hubCronCommand(array $args, string $stdin = ''): array {
    $process = proc_open($args, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
    hubCronRequire(is_resource($process), 'cron_command_start');
    $written = fwrite($pipes[0], $stdin); fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]); fclose($pipes[2]);
    $code = proc_close($process);
    hubCronRequire($written === strlen($stdin), 'cron_command_input');
    return ['code' => $code, 'stdout' => (string)$stdout, 'stderr' => (string)$stderr];
}

function hubCronManifest(): array {
    $manifest = json_decode((string)file_get_contents(__DIR__ . '/cron-jobs.json'), true, 512, JSON_THROW_ON_ERROR);
    hubCronRequire(is_array($manifest) && is_array($manifest['jobs'] ?? null)
        && is_array($manifest['adopt_lines'] ?? null), 'cron_manifest');
    return $manifest;
}

function hubCronManagedLines(array $jobs): array {
    $lines = [];
    $ids = [];
    foreach ($jobs as $job) {
        hubCronRequire(is_array($job) && preg_match('/^[a-z0-9-]+$/D', $job['id'] ?? '') === 1
            && !isset($ids[$job['id']]), 'cron_job_id');
        $ids[$job['id']] = true;
        // Restrict commands to private PHP workers, optional fixed-path Node override,
        // and suppressed output. No shell operators or inline secrets are accepted.
        hubCronRequire(preg_match('#^(?:INVOICES_NODE_BINARY=/[a-zA-Z0-9_./-]+ )?/usr/local/bin/php /home/welcome/room-check-private/cron/[a-z0-9-]+\.php >/dev/null 2>&1$#D', $job['command'] ?? '') === 1, 'cron_job_command');
        $fields = explode(' ', (string)($job['schedule'] ?? ''));
        hubCronRequire(count($fields) === 5, 'cron_job_schedule');
        foreach ($fields as $i => $field) {
            $bounds = [[0,59],[0,23],[1,31],[1,12],[0,7]][$i];
            foreach (explode(',', $field) as $term) {
                hubCronRequire(preg_match('/^(\*|\d{1,2}(?:-\d{1,2})?)(?:\/(\d{1,2}))?$/D', $term, $parts) === 1, 'cron_job_schedule');
                if ($parts[1] !== '*') {
                    $range = array_map('intval', explode('-', $parts[1]));
                    hubCronRequire(min($range) >= $bounds[0] && max($range) <= $bounds[1]
                        && $range[0] <= end($range), 'cron_job_schedule');
                }
                if (isset($parts[2])) { hubCronRequire((int)$parts[2] >= 1 && (int)$parts[2] <= $bounds[1] + 1, 'cron_job_schedule'); }
            }
        }
        $lines[] = $job['schedule'] . ' ' . $job['command'];
    }
    hubCronRequire(count($lines) === count(array_unique($lines)), 'cron_duplicate_job');
    return $lines;
}

function hubCronRender(string $current, array $managed, array $adopt): string {
    $output = '';
    $inside = false;
    $seenBegin = false;
    foreach (preg_split('/(?<=\n)/', $current, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $raw) {
        $line = rtrim($raw, "\r\n");
        if ($line === HUB_CRON_BEGIN) {
            hubCronRequire(!$inside && !$seenBegin, 'cron_marker_invalid');
            $inside = true; $seenBegin = true; continue;
        }
        if ($line === HUB_CRON_END) {
            hubCronRequire($inside, 'cron_marker_invalid');
            $inside = false; continue;
        }
        if ($inside) { continue; }
        if (in_array($line, $adopt, true)) { continue; }
        // Unknown variants require review, rather than deletion or duplication.
        if ($line !== '' && !str_starts_with(ltrim($line), '#')) {
            foreach ($managed as $desired) {
                preg_match('# /home/welcome/room-check-private/cron/([a-z0-9-]+\.php) #', $desired, $path);
                hubCronRequire(isset($path[1]), 'cron_managed_line');
                hubCronRequire(!str_contains($line, '/home/welcome/room-check-private/cron/' . $path[1]), 'cron_unrecognized_hub_entry');
            }
        }
        $output .= $raw;
    }
    hubCronRequire(!$inside, 'cron_marker_invalid');
    // Preserve all bytes outside owned entries, including comments, env and spacing.
    if ($output !== '' && !str_ends_with($output, "\n")) { $output .= "\n"; }
    return $output . HUB_CRON_BEGIN . "\n" . ($managed === [] ? '' : implode("\n", $managed) . "\n") . HUB_CRON_END . "\n";
}

function hubCronRead(callable $run): string {
    $read = $run(['/usr/bin/crontab', '-l']);
    hubCronRequire($read['code'] === 0 || ($read['code'] === 1 && $read['stdout'] === ''
        && preg_match('/^no crontab for [a-z][a-z0-9_]*\s*$/D', trim($read['stderr'])) === 1), 'cron_read');
    return $read['stdout'];
}

function hubCronSync(array $manifest, bool $apply, string $backupRoot, callable $run): array {
    $current = hubCronRead($run);
    $managed = hubCronManagedLines($manifest['jobs']);
    $desired = hubCronRender($current, $managed, $manifest['adopt_lines']);
    $changed = $desired !== $current;
    if ($apply && $changed) {
        hubCronRequire(is_dir($backupRoot) && !is_link($backupRoot), 'cron_backup_directory');
        $backup = $backupRoot . '/crontab-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(8)) . '.txt';
        $handle = fopen($backup, 'x');
        hubCronRequire(is_resource($handle), 'cron_backup');
        try {
            hubCronRequire(chmod($backup, 0600) && fwrite($handle, $current) === strlen($current)
                && fflush($handle), 'cron_backup');
        } finally { fclose($handle); }
        hubCronRequire(hash_equals(hash('sha256', $current), (string)hash_file('sha256', $backup)), 'cron_backup_verify');
        hubCronRequire(hubCronRead($run) === $current, 'cron_changed_before_write');
        hubCronRequire($run(['/usr/bin/crontab', '-'], $desired)['code'] === 0, 'cron_write');
        hubCronRequire(hubCronRead($run) === $desired, 'cron_verify');
    }
    return ['ok' => true, 'mode' => $apply ? 'apply' : 'check', 'changed' => $changed, 'managed_jobs' => count($managed)];
}

function hubCronMain(array $argv): int {
    ini_set('display_errors', '0');
    umask(0077);
    $lock = null;
    try {
        hubCronRequire(count($argv) === 2 && in_array($argv[1], ['--check','--apply'], true), 'cron_usage');
        hubCronRequire(rtrim((string)getenv('HOME'), '/') === '/home/welcome', 'cron_account');
        hubCronRequire(is_executable('/usr/bin/crontab'), 'cron_unavailable');
        $manifest = hubCronManifest();
        foreach ($manifest['jobs'] as $job) {
            if (preg_match('/^INVOICES_NODE_BINARY=([^ ]+)/', $job['command'], $match)) {
                hubCronRequire(is_executable($match[1]), 'cron_node_unavailable');
            }
        }
        $apply = $argv[1] === '--apply';
        $root = '/home/welcome/room-check-backups';
        if ($apply) {
            hubCronRequire(is_dir($root) && !is_link($root) && !is_link($root . '/cron.lock'), 'cron_backup_directory');
            $lock = fopen($root . '/cron.lock', 'c');
            hubCronRequire(is_resource($lock) && flock($lock, LOCK_EX | LOCK_NB), 'cron_busy');
        }
        echo json_encode(hubCronSync($manifest, $apply, $root, 'hubCronCommand'), JSON_THROW_ON_ERROR) . "\n";
        return 0;
    } catch (Throwable $error) {
        echo json_encode(['ok' => false, 'error' => $error instanceof HubCronError ? $error->getMessage() : 'cron_failed']) . "\n";
        return 1;
    } finally {
        if (is_resource($lock)) { flock($lock, LOCK_UN); fclose($lock); }
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) { exit(hubCronMain($argv)); }
