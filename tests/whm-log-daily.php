<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/cron/whm-log-daily.php';
function check(bool $ok, string $label): void {
    if (!$ok) throw new RuntimeException('FAIL: ' . $label);
    echo "PASS: $label\n";
}
$root = sys_get_temp_dir() . '/whm-daily-' . bin2hex(random_bytes(6));
mkdir($root, 0700); mkdir($root . '/logs', 0700); mkdir($root . '/private', 0700);
$day = new DateTimeImmutable('2026-11-05 04:00:00', new DateTimeZone('Europe/Lisbon'));
$secret = 'PRIVATE_TOKEN guest@example.org';
$log = $root . '/logs/error.log';
$run = static fn($date, $budget = 33554432) => WhmDailyLogAudit::run($root . '/logs', $root . '/private', $date, $budget);
try {
    file_put_contents($log, "old PHP Fatal error: $secret\n");
    file_put_contents($root . '/logs/old.gz', gzencode("old PHP Fatal error: $secret\n"));
    $r = $run($day);
    check($r['status'] === 'baseline' && $r['chunks_read'] === 0, 'Baseline does not relabel historical errors as new');
    file_put_contents($log, "[06-Nov-2026 04:01:00 Europe/Lisbon] PHP Warning: Undefined array key in /home/welcome/public_html/check/lib.php $secret\n", FILE_APPEND);
    $r = $run($day);
    check($r['status'] === 'baseline', 'Same day is idempotent');
    $r = $run($day->modify('+1 day'));
    check($r['status'] === 'complete' && $r['chunks_read'] === 1 && $r['severity_counts']['warning'] === 1, 'Reads only new bytes');
    check($r['first_event_date'] === '2026-11-06' && $r['origin_counts']['management_hub'] === 1, 'Parses PHP date and known origin');
    rename($log, $log . '.1');
    file_put_contents($log, "[07/Nov/2026:04:00:00 +0000] PHP Fatal error: $secret\n");
    $r = $run($day->modify('+2 days'));
    check($r['chunks_read'] === 1 && $r['severity_counts']['fatal'] === 1, 'Rename rotation does not reread the previous file');
    file_put_contents($log, "PHP Warning: x\n");
    $r = $run($day->modify('+3 days'));
    check($r['resets'] === 1 && $r['chunks_read'] === 1, 'Truncation starts a new cursor');
    file_put_contents($root . '/logs/new.gz', gzencode("[Wed Nov 04 10:00:00 2026] PHP Fatal error: $secret\n"));
    file_put_contents($log, str_repeat("PHP Warning: x\n", 10), FILE_APPEND);
    $r = $run($day->modify('+4 days'), 45);
    check($r['status'] === 'partial', 'Budget exhaustion is explicit');
    $r = $run($day->modify('+4 days'));
    check($r['status'] === 'complete' && $r['severity_counts']['warning'] === 10, 'Partial progress resumes without losing counts');
    check($r['archive_severity_counts']['fatal'] === 1 && !isset($r['severity_counts']['fatal']), 'Archives are separated from new active errors');
    $json = json_encode($r, JSON_THROW_ON_ERROR);
    check(!str_contains($json, $secret) && !str_contains($json, $root) && !str_contains($json, 'error.log'), 'Report contains no raw content or paths');
    check(($r['first_event_date'] ?? '') === '2026-11-04', 'Parses Apache dates');
    $r = $run($day->modify('+5 days'));
    check($r['chunks_read'] === 0 && $r['files_unchanged'] === 4, 'Unchanged plain and gzip files are skipped');
    file_put_contents($log, "PHP Fat", FILE_APPEND);
    $r = $run($day->modify('+6 days'));
    check($r['pending_tail_bytes'] === 7 && $r['chunks_read'] === 0, 'Retains an unfinished record');
    file_put_contents($log, "al error: test\n500 is an ordinary count\n", FILE_APPEND);
    $r = $run($day->modify('+7 days'));
    check($r['severity_counts']['fatal'] === 1 && !isset($r['severity_counts']['http_5xx']), 'Completes split record without false HTTP status');
    check(WhmDailyLogAudit::eventDate('[05-XYZ-2026]') === null, 'Rejects invalid month');
    file_put_contents($root . '/private/whm-log-cursors.json', 'broken');
    try { $run($day->modify('+8 days')); check(false, 'Invalid state must fail'); }
    catch (JsonException|RuntimeException $e) { check(true, 'Corrupt state fails closed instead of resetting history'); }
    check(file_exists($log) && file_exists($root . '/logs/new.gz'), 'No logs deleted');
} finally {
    foreach (glob($root . '/logs/*') ?: [] as $p) unlink($p);
    foreach (glob($root . '/private/*') ?: [] as $p) unlink($p);
    rmdir($root . '/logs'); rmdir($root . '/private'); rmdir($root);
}
