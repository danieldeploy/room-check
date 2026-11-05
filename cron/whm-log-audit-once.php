<?php
declare(strict_types=1);

/**
 * ONE-OFF full streaming log analysis; installed under the private cPanel cron
 * directory, never under public_html. No raw log content, filenames or domains
 * appear in result/STDOUT/STDERR. No log removal, rotation or truncation.
 * Historical scan completed 2026-11-05. Classifiers are reused by the daily scanner.
 */
final class WhmPrivateLogAuditOnce
{
    private const ROOT = '/home/welcome/logs';
    private const REPORT = '/home/welcome/room-check-private/whm-log-full-20261104.json';
    private const MARKER = '/home/welcome/room-check-private/whm-log-full-20261104.started';
    private const MAX_FILES = 150;
    private const MAX_FILE_BYTES = 200 * 1024 * 1024;
    private const MAX_TOTAL_BYTES = 250 * 1024 * 1024;
    private const MAX_UNCOMPRESSED_BYTES = 450 * 1024 * 1024;
    private const MAX_LINE_BYTES = 8192;
    private const MAX_FINGERPRINTS = 4000;

    public static function runIfNeeded(): string
    {
        if (PHP_SAPI !== 'cli') return 'not_cli';
        if (is_file(self::REPORT)) return 'completed_previously';
        if (file_exists(self::MARKER)) return 'attempted_previously';
        $lock = @fopen(self::MARKER, 'x');
        if ($lock === false) return 'locked_or_unavailable';
        @chmod(self::MARKER, 0600);
        fclose($lock);
        try {
            $report = self::scan(self::ROOT);
            $report['ok'] = true;
            $report['report_schema'] = 'whm-streamed-logs-v1';
            $report['scope'] = 'welcome_logs_only';
            $report['no_deletions'] = true;
            $report['no_retention_change'] = true;
            self::save($report);
            return 'saved';
        } catch (Throwable) {
            self::save([
                'ok' => false, 'report_schema' => 'whm-streamed-logs-v1',
                'error' => 'safe_log_scan_failed',
                'no_deletions' => true, 'no_retention_change' => true,
            ]);
            return 'audit_incomplete';
        }
    }

    private static function save(array $data): void
    {
        $value = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if (strlen($value) > 60000) throw new RuntimeException('report_too_large');
        $tmp = self::REPORT . '.tmp';
        if (file_put_contents($tmp, $value, LOCK_EX) !== strlen($value)
            || !chmod($tmp, 0600)
            || !rename($tmp, self::REPORT)) {
            throw new RuntimeException('report_write_failed');
        }
    }

    public static function reason(string $text): string
    {
        $t = strtolower($text);
        foreach ([
            'disk_full' => ['no space left', 'disk quota exceeded', 'enospc'],
            'permission' => ['permission denied', 'access denied', 'operation not permitted'],
            'database' => ['sqlstate', 'pdoexception', 'mysqli_', 'database connection'],
            'missing_file' => ['no such file', 'failed to open stream', 'file not found'],
            'undefined_key' => ['undefined array key', 'undefined index'],
            'undefined_variable' => ['undefined variable', 'undefined property'],
            'memory' => ['allowed memory size', 'out of memory', 'memory exhausted'],
            'timeout' => ['timed out', 'timeout', 'maximum execution time'],
            'network' => ['curl error', 'connection refused', 'connection reset'],
            'ssl' => ['ssl certificate', 'certificate verify', 'tls handshake'],
            'authentication' => ['unauthorized', 'authentication failed', 'invalid credentials'],
            'deprecated' => ['deprecated'],
        ] as $reason => $fragments) {
            foreach ($fragments as $fragment) {
                if (str_contains($t, $fragment)) return $reason;
            }
        }
        return 'unclassified';
    }

    public static function severity(string $text): string
    {
        $t = strtolower($text);
        if (str_contains($t, 'fatal error') || str_contains($t, 'uncaught')
            || str_contains($t, 'panic')) return 'fatal';
        if (str_contains($t, 'parse error') || str_contains($t, 'syntax error'))
            return 'parse';
        if (str_contains($t, 'deprecated')) return 'deprecated';
        if (str_contains($t, 'php warning') || str_contains($t, 'warning:'))
            return 'warning';
        if (str_contains($t, 'php notice') || str_contains($t, 'notice:'))
            return 'notice';
        if (preg_match('/(?:HTTP\/[0-9.]+\s+|[" ]\s*)(5[0-9]{2})(?:\s|$)/', $text))
            return 'http_5xx';
        if (preg_match('/(?:HTTP\/[0-9.]+\s+|[" ]\s*)(4[0-9]{2})(?:\s|$)/', $text))
            return 'http_4xx';
        if (str_contains($t, 'error') || str_contains($t, 'failed'))
            return 'other_error';
        return 'other';
    }

    public static function category(string $basename): string
    {
        $name = strtolower($basename);
        if (preg_match('/\.(?:gz|bz2|xz|zip|zst)$/', $name)) return 'compressed';
        if (str_contains($name, 'error')) return 'error';
        if (str_contains($name, 'access') || str_contains($name, 'ssl_log')) return 'access';
        return 'other_plaintext';
    }

    public static function scan(string $directory): array
    {
        $root = realpath($directory);
        if ($root === false || !is_dir($root)) throw new RuntimeException('no_root');
        $paths = [];
        $total = 0;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($iterator as $info) {
            if ($info->isLink() || !$info->isFile()) continue;
            $path = $info->getRealPath();
            if ($path === false || !str_starts_with($path, $root . DIRECTORY_SEPARATOR)) {
                throw new RuntimeException('unexpected_log_path');
            }
            $size = $info->getSize();
            if ($size < 0 || $size > self::MAX_FILE_BYTES) throw new RuntimeException('oversized_log');
            $total += $size;
            if ($total > self::MAX_TOTAL_BYTES) throw new RuntimeException('too_many_log_bytes');
            $paths[] = [$path, $size, $info->getMTime()];
            if (count($paths) > self::MAX_FILES) throw new RuntimeException('too_many_logs');
        }
        usort($paths, static fn($a, $b) => ($b[1] <=> $a[1]) ?: strcmp($a[0], $b[0]));
        $report = [
            'files_total' => count($paths),
            'size_mb' => round($total / 1048576, 2),
            'files' => [],
            'total_lines_read' => 0,
            'total_uncompressed_mb' => 0,
            'severity_counts' => [],
            'reason_counts' => [],
            'coverage' => 'streamed_whole_file_unless_explicit_skip',
        ];
        $allSeverity = [];
        $allReasons = [];
        $expandedTotal = 0;
        foreach ($paths as $index => [$path, $size, $mtime]) {
            $type = self::category(basename($path));
            $file = [
                'ordinal' => $index + 1,
                'type' => $type,
                'size_mb' => round($size / 1048576, 2),
                'days_since_modification' => max(0, intdiv(max(0, time() - $mtime), 86400)),
                'status' => 'not_scanned',
            ];
            if ($size === 0) {
                $file['status'] = 'empty';
                $report['files'][] = $file;
                continue;
            }
            $gzip = str_ends_with(strtolower($path), '.gz');
            if ($type === 'compressed' && !$gzip) {
                $file['status'] = 'compression_not_supported';
                $report['files'][] = $file;
                continue;
            }
            if ($gzip && !function_exists('gzopen')) {
                $file['status'] = 'gzip_reader_missing';
                $report['files'][] = $file;
                continue;
            }
            $handle = $gzip ? @gzopen($path, 'rb') : @fopen($path, 'rb');
            if ($handle === false) {
                $file['status'] = 'not_readable';
                $report['files'][] = $file;
                continue;
            }
            $counts = [];
            $reasons = [];
            $fingerprints = [];
            $lines = 0;
            $expanded = 0;
            $years = [];
            try {
                while (($line = $gzip ? gzgets($handle, self::MAX_LINE_BYTES) :
                              fgets($handle, self::MAX_LINE_BYTES)) !== false) {
                    $expanded += strlen($line);
                    $expandedTotal += strlen($line);
                    if ($expanded > self::MAX_UNCOMPRESSED_BYTES
                        || $expandedTotal > self::MAX_UNCOMPRESSED_BYTES) {
                        throw new RuntimeException('decompression_limit');
                    }
                    $lines++;
                    $sev = self::severity($line);
                    $why = self::reason($line);
                    $counts[$sev] = ($counts[$sev] ?? 0) + 1;
                    $reasons[$why] = ($reasons[$why] ?? 0) + 1;
                    if (preg_match('/\b(20[0-3][0-9])[-\/](0?[1-9]|1[0-2])[-\/][0-3]?[0-9]\b/', $line, $m)) {
                        $year = $m[1];
                        $years[$year] = ($years[$year] ?? 0) + 1;
                    }
                    // Hash never leaves this PHP process; only top counts do.
                    // Discard dynamic numbers, URLs and addresses before grouping.
                    $normalized = preg_replace(
                        '~https?://\S+|[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}|(?:\d{1,3}\.){3}\d{1,3}|\b\d+\b~',
                        '#', substr($line, 0, 1024)
                    );
                    $digest = hash('sha256', $sev . ':' . ($normalized ?? ''));
                    if (isset($fingerprints[$digest])) {
                        $fingerprints[$digest]['count']++;
                    } elseif (count($fingerprints) < self::MAX_FINGERPRINTS) {
                        $fingerprints[$digest] = ['count' => 1, 'severity' => $sev];
                    }
                }
            } finally {
                $gzip ? gzclose($handle) : fclose($handle);
            }
            arsort($counts);
            arsort($reasons);
            arsort($years);
            usort($fingerprints, static fn($a, $b) => $b['count'] <=> $a['count']);
            $file = array_merge($file, [
                'status' => 'fully_streamed',
                'lines' => $lines,
                'bytes_read_mb' => round($expanded / 1048576, 2),
                'severity_counts' => $counts,
                'reason_counts' => $reasons,
                'year_counts' => $years,
                'top_repeated_line_counts' => array_slice(
                    array_map(static fn($x) => ['count' => $x['count'],
                                              'severity' => $x['severity']],
                              $fingerprints), 0, 6
                ),
            ]);
            $report['total_lines_read'] += $lines;
            foreach ($counts as $key => $value) $allSeverity[$key] = ($allSeverity[$key] ?? 0) + $value;
            foreach ($reasons as $key => $value) $allReasons[$key] = ($allReasons[$key] ?? 0) + $value;
            $report['files'][] = $file;
        }
        arsort($allSeverity);
        arsort($allReasons);
        $report['severity_counts'] = $allSeverity;
        $report['reason_counts'] = $allReasons;
        $report['total_uncompressed_mb'] = round($expandedTotal / 1048576, 2);
        $report['files_fully_streamed'] = count(array_filter(
            $report['files'], static fn($x) => $x['status'] === 'fully_streamed'
        ));
        return $report;
    }
}
