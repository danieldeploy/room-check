<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/cron/whm-log-daily.php';

/** One reviewed cleanup, not a retention policy. All historical bytes survive privately. */
final class WhmInactiveLogCompactor
{
    private const REPORT = 'whm-log-compaction-20261106.json';
    private const ARCHIVES = 'whm-log-preserved-20261106';

    private static function ensure(bool $ok, string $code): void {
        if (!$ok) throw new RuntimeException($code);
    }

    private static function save(string $path, array $value): void {
        self::ensure(!is_link($path) && !file_exists($path . '.tmp') && !is_link($path . '.tmp'), 'unsafe_report');
        $raw = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        self::ensure(strlen($raw) < 120000, 'report_too_large');
        $h = @fopen($path . '.tmp', 'x');
        self::ensure(is_resource($h), 'report_open');
        try { self::ensure(fwrite($h, $raw) === strlen($raw) && fflush($h), 'report_write'); }
        finally { fclose($h); }
        self::ensure(chmod($path . '.tmp', 0600) && rename($path . '.tmp', $path), 'report_commit');
    }

    private static function metadata(string $path): array {
        clearstatcache(true, $path);
        $s = @lstat($path);
        self::ensure(is_array($s) && ($s['mode'] & 0170000) === 0100000 && $s['nlink'] === 1, 'not_regular_single_link');
        return array_intersect_key($s, array_flip(['dev','ino','mode','uid','gid','nlink','size','mtime','ctime']));
    }

    private static function profile(string $path, array $s, int $now, float $deadline): array {
        $p = ['size_bytes' => $s['size'], 'age_days' => max(0, intdiv($now - $s['mtime'], 86400)),
            'status' => 'preserved', 'lines' => 0, 'origin_counts' => [], 'severity_counts' => [],
            'first_date' => null, 'last_date' => null];
        if (preg_match('/\.(?:gz|zip|bz2|xz|zst)$/i', $path)) return $p;
        $h = @fopen($path, 'rb');
        self::ensure(is_resource($h), 'profile_open');
        try {
            $bytes = 0;
            while ($bytes < $s['size']) {
                self::ensure(microtime(true) < $deadline, 'profile_budget');
                $line = fgets($h, min(8192, $s['size'] - $bytes + 1));
                self::ensure(is_string($line) && $line !== '', 'profile_read');
                $bytes += strlen($line); $p['lines']++;
                $t = strtolower($line);
                $origin = str_contains($t, '/public_html/check/') ? 'management_hub' :
                    (preg_match('~/wp-(?:content|includes|admin)/~', $t) ? 'wordpress' :
                    ((str_contains($t, '/roundcube/') || str_contains($t, 'rcube_')) ? 'roundcube' : 'unknown'));
                $p['origin_counts'][$origin] = ($p['origin_counts'][$origin] ?? 0) + 1;
                $sev = WhmPrivateLogAuditOnce::severity($line);
                if (in_array($sev, ['http_4xx','http_5xx'], true)
                    && !preg_match('~\bHTTP/[0-9.]+"?\s+[45][0-9]{2}(?:\s|$)~', $line)) $sev = 'other';
                $p['severity_counts'][$sev] = ($p['severity_counts'][$sev] ?? 0) + 1;
                $date = WhmDailyLogAudit::eventDate($line);
                if ($date !== null) {
                    if ($p['first_date'] === null || $date < $p['first_date']) $p['first_date'] = $date;
                    if ($p['last_date'] === null || $date > $p['last_date']) $p['last_date'] = $date;
                }
            }
        } finally { fclose($h); }
        return $p;
    }

    public static function run(string $root, string $private, int $now): array {
        self::ensure(is_dir($root) && !is_link($root) && is_dir($private) && !is_link($private), 'invalid_roots');
        $oldMask = umask(0077);
        try {
            $report = $private . '/' . self::REPORT;
            self::ensure(!is_link($report), 'unsafe_report');
            if (is_file($report)) {
                self::ensure(filesize($report) < 120000, 'report_too_large');
                $prior = json_decode((string)file_get_contents($report), true, 32, JSON_THROW_ON_ERROR);
                self::ensure(is_array($prior) && ($prior['ok'] ?? false) === true, 'prior_compaction_unconfirmed');
                return $prior;
            }
            $marker = $private . '/whm-log-compaction-20261106.started';
            $lock = @fopen($marker, 'x');
            self::ensure(is_resource($lock), 'prior_compaction_unconfirmed');
            fclose($lock); chmod($marker, 0600);
            $archiveRoot = $private . '/' . self::ARCHIVES;
            self::ensure(!file_exists($archiveRoot) && !is_link($archiveRoot), 'prior_archives_unconfirmed');
            self::ensure(mkdir($archiveRoot, 0700), 'archive_directory');
            $files = []; $total = 0; $deadline = microtime(true) + 45;
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                if ($file->isLink() || !$file->isFile()) continue;
                $path = $file->getRealPath();
                self::ensure(is_string($path) && str_starts_with($path, realpath($root) . '/'), 'unsafe_log_path');
                $s = self::metadata($path);
                self::ensure($s['uid'] === stat($root)['uid'], 'log_owner_mismatch');
                self::ensure($s['size'] >= 0 && $s['size'] <= 200 * 1048576, 'oversized_log');
                $total += $s['size'];
                self::ensure($total <= 250 * 1048576 && count($files) < 150, 'inventory_budget');
                $files[$path] = $s;
            }
            uasort($files, static fn($a, $b) => $b['size'] <=> $a['size']);
            $r = ['schema' => 'whm-log-compaction-v1', 'ok' => true, 'historical_bytes_preserved' => true,
                'files_compacted' => 0, 'bytes_reclaimed' => 0, 'profiles' => []];
            // Complete provenance read before any deletion. No historical marker/cursor is reset.
            foreach ($files as $path => $s) $r['profiles'][] = self::profile($path, $s, $now, $deadline);
            $manifest = []; $i = 0;
            foreach ($files as $path => $s) {
                $index = $i++;
                // This is inactivity eligibility for lossless compaction, never deletion by event age.
                if ($now - $s['mtime'] < 365 * 86400 || $s['size'] < 1024
                    || preg_match('/\.(?:gz|zip|bz2|xz|zst)$/i', $path)) continue;
                self::ensure(self::metadata($path) === $s, 'source_changed');
                $digest = hash_file('sha256', $path);
                $name = hash('sha256', substr($path, strlen($root))) . '.log.gz';
                $archive = $archiveRoot . '/' . $name;
                self::ensure(!file_exists($archive) && !is_link($archive), 'archive_exists');
                $in = @fopen($path, 'rb'); $out = @gzopen($archive, 'wb6');
                self::ensure(is_resource($in) && is_resource($out), 'compression_open');
                try {
                    while (!feof($in)) {
                        $chunk = fread($in, 65536);
                        self::ensure(is_string($chunk) && ($chunk !== '' || feof($in)), 'compression_read');
                        self::ensure(gzwrite($out, $chunk) === strlen($chunk), 'compression_write');
                    }
                } finally { fclose($in); gzclose($out); }
                self::ensure(chmod($archive, 0600), 'archive_permissions');
                $verify = @gzopen($archive, 'rb');
                self::ensure(is_resource($verify), 'verify_open');
                $hash = hash_init('sha256'); $decoded = 0;
                try {
                    while (!gzeof($verify)) {
                        $chunk = gzread($verify, 65536);
                        self::ensure(is_string($chunk) && ($chunk !== '' || gzeof($verify)), 'verify_read');
                        $decoded += strlen($chunk); hash_update($hash, $chunk);
                    }
                } finally { gzclose($verify); }
                self::ensure($decoded === $s['size'] && hash_final($hash) === $digest, 'roundtrip_mismatch');
                $compressed = filesize($archive);
                if ($compressed >= $s['size']) { unlink($archive); continue; }
                self::ensure(self::metadata($path) === $s && hash_file('sha256', $path) === $digest, 'source_changed');
                $manifest[] = ['source' => $path, 'archive' => $name, 'sha256' => $digest, 'bytes' => $decoded,
                    'mode' => $s['mode'], 'uid' => $s['uid'], 'gid' => $s['gid'], 'mtime' => $s['mtime']];
                self::save($archiveRoot . '/manifest.json', $manifest);
                self::ensure(self::metadata($path) === $s && unlink($path), 'source_remove_unconfirmed');
                clearstatcache(true, $path);
                self::ensure(!file_exists($path), 'source_remove_unconfirmed');
                $r['files_compacted']++; $r['bytes_reclaimed'] += $s['size'] - $compressed;
                $r['profiles'][$index]['status'] = 'compacted';
            }
            self::save($report, $r);
            return $r;
        } finally { umask($oldMask); }
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    if (PHP_SAPI !== 'cli' || realpath((string)getenv('HOME')) !== '/home/welcome') exit(1);
    ini_set('display_errors', '0'); ini_set('log_errors', '0');
    try { WhmInactiveLogCompactor::run('/home/welcome/logs', '/home/welcome/room-check-private', time()); }
    catch (Throwable) { fwrite(STDERR, "Inactive log compaction incomplete; inspect private receipt before retry.\n"); exit(1); }
}
