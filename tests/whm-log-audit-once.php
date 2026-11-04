<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/cron/whm-log-audit-once.php';

function check(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException('FAIL: ' . $message);
    echo 'PASS: ' . $message . PHP_EOL;
}
$dir = sys_get_temp_dir() . '/rc-whm-logs-' . bin2hex(random_bytes(6));
if (!mkdir($dir, 0700)) throw new RuntimeException('test directory unavailable');
$secret = 'guest@example.org PRIVATE_TOKEN_dont_log';
try {
    $lines = [
        "2026-11-01 PHP Warning: Undefined array key " . $secret,
        "2026-11-01 PHP Warning: Undefined array key " . $secret,
        "2026-11-02 PHP Fatal error: Uncaught Error in " . $secret,
        "2026-11-02 SQLSTATE[HY000] database connection failed " . $secret,
        "2026-11-02 no space left on device " . $secret,
    ];
    file_put_contents($dir . '/example-error.log', implode("\n", $lines) . "\n");
    $gz = gzopen($dir . '/archive.gz', 'wb1');
    if ($gz === false) throw new RuntimeException('gzip test unavailable');
    gzwrite($gz, "2023-01-02 PHP Deprecated: old property " . $secret . "\n");
    gzclose($gz);
    $report = WhmPrivateLogAuditOnce::scan($dir);
    check($report['files_total'] === 2 && $report['files_fully_streamed'] === 2,
          'Streams plain and gzip logs in complete bounded passes');
    check($report['total_lines_read'] === 6,
          'All lines analyzed, not only the tail');
    check(($report['severity_counts']['warning'] ?? 0) === 2,
          'Classifies repeated warnings');
    check(($report['severity_counts']['fatal'] ?? 0) === 1,
          'Classifies fatal errors');
    check(($report['reason_counts']['database'] ?? 0) === 1,
          'Classifies database errors');
    check(($report['reason_counts']['disk_full'] ?? 0) === 1,
          'Classifies disk-full errors');
    check(($report['severity_counts']['deprecated'] ?? 0) === 1,
          'Classifies compressed historical warnings');
    $json = json_encode($report, JSON_THROW_ON_ERROR);
    check(!str_contains($json, $secret) && !str_contains($json, 'example-error.log')
          && !str_contains($json, $dir) && !str_contains($json, 'archive.gz'),
          'Never exports raw content, names or local paths');
    check(isset($report['files'][0]['top_repeated_line_counts']),
          'Reports repeated patterns without exposing message signatures');
    check(file_exists($dir . '/example-error.log')
          && file_exists($dir . '/archive.gz'),
          'Keeps original logs unchanged');
    check(WhmPrivateLogAuditOnce::category('site-error.log') === 'error'
          && WhmPrivateLogAuditOnce::category('site.gz') === 'compressed',
          'Classifies filename categories without emitting names');
} finally {
    foreach (glob($dir . '/*') ?: [] as $path) unlink($path);
    rmdir($dir);
}
echo "WHM one-time full log streaming tests passed.\n";
