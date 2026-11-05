<?php
declare(strict_types=1);
require_once __DIR__ . '/whm-log-audit-once.php';

/** Incremental, private, read-only log scan; consumed by the existing WHM daily report. */
final class WhmDailyLogAudit
{
    public static function runIfDue(): void
    {
        if (PHP_SAPI !== 'cli') return;
        try {
            self::run('/home/welcome/logs', '/home/welcome/room-check-private',
                new DateTimeImmutable('now', new DateTimeZone('Europe/Lisbon')));
        } catch (Throwable) {
            // Preserve the last good cursor. The reader rejects a stale/missing report.
            fwrite(STDERR, "Daily log verification failed; inspect protected maintenance report.\n");
        }
    }

    private static function readState(string $path): ?array
    {
        if (!file_exists($path)) return null;
        if (is_link($path) || !is_file($path) || filesize($path) > 200000) throw new RuntimeException('invalid_state');
        $state = json_decode((string)file_get_contents($path), true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($state) || ($state['schema'] ?? '') !== 'whm-log-cursors-v1'
            || !is_array($state['files'] ?? null) || count($state['files']) > 150
            || !is_array($state['report'] ?? null)) throw new RuntimeException('invalid_state');
        foreach ($state['files'] as $key => $file) {
            if (!is_string($key) || !preg_match('/^[a-f0-9]{64}$/D', $key) || !is_array($file)) throw new RuntimeException('invalid_cursor');
            foreach (['dev', 'ino', 'size', 'mtime', 'offset'] as $n) {
                if (!is_int($file[$n] ?? null) || $file[$n] < 0) throw new RuntimeException('invalid_cursor');
            }
            if (!is_bool($file['gzip'] ?? null) || !is_bool($file['done'] ?? null)
                || !is_string($file['boundary'] ?? null)
                || !preg_match('/^[a-f0-9]{64}$/D', $file['boundary'])) throw new RuntimeException('invalid_cursor');
        }
        return $state;
    }

    private static function save(string $path, array $value): void
    {
        if (is_link($path) || is_link($path . '.tmp')) throw new RuntimeException('unsafe_output');
        $json = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if (strlen($json) > 200000) throw new RuntimeException('oversized_state');
        $oldMask = umask(0077);
        try {
            if (file_put_contents($path . '.tmp', $json, LOCK_EX) !== strlen($json)
                || !chmod($path . '.tmp', 0600) || !rename($path . '.tmp', $path)) throw new RuntimeException('write_failed');
        } finally { umask($oldMask); }
    }

    private static function boundary(string $path, int $offset): string
    {
        $h = @fopen($path, 'rb');
        if ($h === false) throw new RuntimeException('unreadable_log');
        try {
            if (fseek($h, max(0, $offset - 256)) !== 0) throw new RuntimeException('seek_failed');
            $s = $offset === 0 ? '' : fread($h, min(256, $offset));
            if ($s === false) throw new RuntimeException('read_failed');
            return hash('sha256', $s);
        } finally { fclose($h); }
    }

    public static function eventDate(string $line): ?string
    {
        $date = null;
        if (preg_match('/\b(20\d{2})-(\d{2})-(\d{2})\b/', $line, $m)) {
            $date = [$m[1], $m[2], $m[3]];
        } elseif (preg_match('/\b(\d{1,2})[-\/]([A-Za-z]{3})[-\/](20\d{2})\b/', $line, $m)) {
            $date = [$m[3], $m[2], $m[1]];
        } elseif (preg_match('/\b([A-Za-z]{3}) +(\d{1,2}) +\d{2}:\d{2}:\d{2}(?:\.\d+)? +(20\d{2})\b/', $line, $m)) {
            $date = [$m[3], $m[1], $m[2]];
        }
        if ($date === null) return null;
        [$y, $m, $d] = $date;
        if (!ctype_digit($m)) {
            $index = array_search(strtolower($m), ['jan','feb','mar','apr','may','jun','jul','aug','sep','oct','nov','dec'], true);
            if ($index === false) return null;
            $m = (string)($index + 1);
        }
        return checkdate((int)$m, (int)$d, (int)$y) ? sprintf('%04d-%02d-%02d', $y, $m, $d) : null;
    }

    private static function countChunk(array &$r, string $line, bool $gzip): void
    {
        $r['chunks_read']++;
        $r['bytes_read'] += strlen($line);
        $severity = WhmPrivateLogAuditOnce::severity($line);
        if (in_array($severity, ['http_4xx', 'http_5xx'], true)
            && !preg_match('~\bHTTP/[0-9.]+"?\s+[45][0-9]{2}(?:\s|$)~', $line)) $severity = 'other';
        $field = $gzip ? 'archive_severity_counts' : 'severity_counts';
        $r[$field][$severity] = ($r[$field][$severity] ?? 0) + 1;
        $reason = WhmPrivateLogAuditOnce::reason($line);
        $r['reason_counts'][$reason] = ($r['reason_counts'][$reason] ?? 0) + 1;
        $origin = str_contains($line, '/public_html/check/') ? 'management_hub' :
            (preg_match('~/wp-(?:content|includes|admin)/~', $line) ? 'wordpress' :
            (str_contains(strtolower($line), '/roundcube/') ? 'roundcube' : 'unknown'));
        $r['origin_counts'][$origin] = ($r['origin_counts'][$origin] ?? 0) + 1;
        $date = self::eventDate($line);
        if ($date !== null) {
            if ($r['first_event_date'] === null || $date < $r['first_event_date']) $r['first_event_date'] = $date;
            if ($r['last_event_date'] === null || $date > $r['last_event_date']) $r['last_event_date'] = $date;
        } else { $r['undated_chunks']++; }
    }

    /** Paths/time/budget are injected only by local tests; production uses fixed constants above. */
    public static function run(string $root, string $private, DateTimeImmutable $now, int $budget = 33554432): array
    {
        if (is_link($root) || is_link($private) || !is_dir($private) || !is_dir($root)) throw new RuntimeException('invalid_root');
        $lockPath = $private . '/whm-log-daily.lock';
        if (is_link($lockPath)) throw new RuntimeException('unsafe_lock');
        $lock = fopen($lockPath, 'c');
        if (!$lock) throw new RuntimeException('lock_unavailable');
        chmod($lockPath, 0600);
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) return ['status' => 'busy'];
            $statePath = $private . '/whm-log-cursors.json';
            $reportPath = $private . '/whm-log-daily.json';
            $state = self::readState($statePath);
            $day = $now->setTimezone(new DateTimeZone('Europe/Lisbon'))->format('Y-m-d');
            $prior = $state['report'] ?? [];
            if (($prior['date_lisbon'] ?? '') === $day && in_array($prior['status'] ?? '', ['baseline', 'complete'], true)) {
                // Recover a crash between the atomic cursor commit and report projection.
                if (!is_file($reportPath) || file_get_contents($reportPath) !== json_encode($prior, JSON_UNESCAPED_SLASHES)) self::save($reportPath, $prior);
                return $prior;
            }
            $r = ($prior['date_lisbon'] ?? '') === $day ? $prior : [
                'schema' => 'whm-log-daily-v1', 'date_lisbon' => $day, 'status' => 'partial',
                'started_utc' => $now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
                'window_start_utc' => $prior['checked_utc'] ?? $now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
                'files_total' => 0, 'files_unchanged' => 0, 'resets' => 0, 'removed_files' => 0,
                'chunks_read' => 0, 'bytes_read' => 0, 'undated_chunks' => 0, 'pending_tail_bytes' => 0,
                'severity_counts' => [], 'archive_severity_counts' => [], 'reason_counts' => [], 'origin_counts' => [],
                'first_event_date' => null, 'last_event_date' => null,
            ];
            $paths = [];
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file->isLink() || !$file->isFile()) continue;
                $path = $file->getRealPath();
                if ($path === false || !str_starts_with($path, realpath($root) . '/')) throw new RuntimeException('unsafe_log_path');
                $paths[hash('sha256', substr($path, strlen($root)))] = $path;
                if (count($paths) > 150) throw new RuntimeException('too_many_logs');
            }
            ksort($paths);
            $oldFiles = $state['files'] ?? [];
            $files = [];
            $r['files_total'] = count($paths);
            $r['files_unchanged'] = 0;
            $r['pending_tail_bytes'] = 0;
            $r['removed_files'] += count(array_diff_key($oldFiles, $paths));
            $remaining = $budget;
            $deadline = microtime(true) + 8;
            $partial = false;
            foreach ($paths as $key => $path) {
                clearstatcache(true, $path);
                $s = stat($path);
                if ($s === false || $s['size'] > 1073741824) throw new RuntimeException('invalid_log');
                $gzip = str_ends_with(strtolower($path), '.gz');
                if (preg_match('/\.(?:bz2|xz|zip|zst)$/i', $path) || ($gzip && $s['size'] > 16777216)) throw new RuntimeException('unsupported_archive');
                $old = $oldFiles[$key] ?? null;
                // Follow a renamed inode without replaying the already scanned content.
                if ($old === null || $old['ino'] !== $s['ino'] || $old['dev'] !== $s['dev']) {
                    $old = null;
                    foreach ($oldFiles as $candidate) {
                        if ($candidate['ino'] === $s['ino'] && $candidate['dev'] === $s['dev']) { $old = $candidate; break; }
                    }
                }
                $same = $old !== null && $old['gzip'] === $gzip && $old['ino'] === $s['ino'] && $old['dev'] === $s['dev'];
                $boundaryOffset = $gzip ? min(256, $s['size']) : ($old['offset'] ?? 0);
                $same = $same && ($gzip ? ($old['size'] === $s['size'] && $old['mtime'] === $s['mtime']) : $s['size'] >= $old['offset'])
                    && hash_equals($old['boundary'], self::boundary($path, $boundaryOffset));
                $f = ['dev' => $s['dev'], 'ino' => $s['ino'], 'size' => $s['size'], 'mtime' => $s['mtime'],
                    'gzip' => $gzip, 'offset' => $same ? $old['offset'] : 0, 'done' => $same && $old['done'], 'boundary' => ''];
                if ($state === null) {
                    $f['offset'] = $gzip ? 0 : $s['size'];
                    $f['done'] = true;
                } elseif ($same && (($gzip && $f['done']) || (!$gzip && $f['offset'] === $s['size']))) {
                    $r['files_unchanged']++;
                } else {
                    if (!$same && isset($oldFiles[$key])) $r['resets']++;
                    if ($remaining <= 0 || microtime(true) >= $deadline) { $partial = true; }
                    else {
                        $h = $gzip ? @gzopen($path, 'rb') : @fopen($path, 'rb');
                        if (!$h) throw new RuntimeException('unreadable_log');
                        try {
                            if (($gzip ? gzseek($h, $f['offset']) : fseek($h, $f['offset'])) !== 0) throw new RuntimeException('seek_failed');
                            $f['done'] = false;
                            while ($remaining > 0 && microtime(true) < $deadline) {
                                $left = $gzip ? 8191 : min(8191, $s['size'] - $f['offset']);
                                if ($left <= 0) { $f['done'] = true; break; }
                                $line = $gzip ? gzgets($h, $left + 1) : fgets($h, $left + 1);
                                if ($line === false) {
                                    if (!($gzip ? gzeof($h) : feof($h))) throw new RuntimeException('read_failed');
                                    $f['done'] = true; break;
                                }
                                if (!$gzip && !str_ends_with($line, "\n") && strlen($line) < 8191
                                    && $f['offset'] + strlen($line) >= $s['size']) {
                                    // A writer may still be completing this record. Retain its
                                    // starting offset so a split "Fatal error" is not missed.
                                    $r['pending_tail_bytes'] += strlen($line);
                                    $f['done'] = true; break;
                                }
                                // Bound reads by chunks, never split ordinary records at budget boundaries.
                                self::countChunk($r, $line, $gzip);
                                $f['offset'] += strlen($line); $remaining -= strlen($line);
                                if ($gzip && $f['offset'] > 268435456) throw new RuntimeException('archive_expansion_limit');
                            }
                            if (!$gzip && $f['offset'] === $s['size']) $f['done'] = true;
                            if (!$f['done']) $partial = true;
                        } finally { $gzip ? gzclose($h) : fclose($h); }
                    }
                }
                $f['boundary'] = self::boundary($path, $gzip ? min(256, $s['size']) : $f['offset']);
                $files[$key] = $f;
            }
            $r['status'] = $state === null ? 'baseline' : ($partial ? 'partial' : 'complete');
            $r['checked_utc'] = $now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
            self::save($statePath, ['schema' => 'whm-log-cursors-v1', 'files' => $files, 'report' => $r]);
            self::save($reportPath, $r);
            return $r;
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }
}
