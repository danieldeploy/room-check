<?php
declare(strict_types=1);
$implementation = dirname(__DIR__) . '/deploy/compact_inactive_logs_once.php';
if (is_file($implementation)) require_once $implementation;
function check(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException('FAIL: ' . $message);
    echo "PASS: $message\n";
}
check(class_exists('WhmInactiveLogCompactor'), 'Inactive log compactor is available');
$root = sys_get_temp_dir() . '/whm-compact-' . bin2hex(random_bytes(6));
mkdir($root, 0700); mkdir($root . '/logs', 0700); mkdir($root . '/private', 0700);
$secret = 'guest@example.org PRIVATE_TOKEN';
$old = str_repeat("[01-Jan-2019 10:00:00 UTC] PHP Fatal error: old /roundcube/ $secret\n", 100);
file_put_contents($root . '/logs/old-error.log', $old);
touch($root . '/logs/old-error.log', 1546340400);
file_put_contents($root . '/logs/active-error.log', "[06-Nov-2026] PHP Deprecated: $secret\n");
file_put_contents($root . '/logs/incompressible.log', random_bytes(4096));
touch($root . '/logs/incompressible.log', 1546340400);
symlink($root . '/logs/active-error.log', $root . '/logs/link-error.log');
try {
    $r = WhmInactiveLogCompactor::run($root . '/logs', $root . '/private', time());
    check($r['ok'] && $r['files_compacted'] === 1 && $r['bytes_reclaimed'] > 0, 'Reclaims only inactive plaintext after verification');
    check(!file_exists($root . '/logs/old-error.log'), 'Removes redundant original after private archive commit');
    check(is_file($root . '/logs/active-error.log') && is_link($root . '/logs/link-error.log'), 'Preserves active logs and does not follow symlinks');
    check(is_file($root . '/logs/incompressible.log'), 'Keeps original when compression would not save space');
    $manifest = json_decode(file_get_contents($root . '/private/whm-log-preserved-20261106/manifest.json'), true);
    check(count($manifest) === 1, 'Private recovery manifest contains the retained source mapping');
    $archive = $root . '/private/whm-log-preserved-20261106/' . $manifest[0]['archive'];
    check(gzdecode(file_get_contents($archive)) === $old, 'Historical fatal events recover byte for byte');
    check($r['profiles'][0]['first_date'] === '2019-01-01' && $r['profiles'][0]['origin_counts']['roundcube'] === 100, 'Profiles dated origins without disclosing historical messages');
    check((fileperms($archive) & 0777) === 0600, 'Archive is owner only');
    check(!str_contains(json_encode($r), $secret) && !str_contains(json_encode($r), 'old-error.log'), 'Public result exposes neither log contents nor filenames');
    $again = WhmInactiveLogCompactor::run($root . '/logs', $root . '/private', time());
    check($again === $r, 'Completed one-off is not repeated');
    unlink($root . '/private/whm-log-compaction-20261106.json');
    try { WhmInactiveLogCompactor::run($root . '/logs', $root . '/private', time()); check(false, 'Uncertain run must stop'); }
    catch (RuntimeException $e) { check($e->getMessage() === 'prior_compaction_unconfirmed', 'Marker without report is never retried'); }
} finally {
    foreach (glob($root . '/logs/*') as $p) unlink($p);
    foreach (glob($root . '/private/whm-log-preserved-20261106/*') as $p) unlink($p);
    rmdir($root . '/private/whm-log-preserved-20261106');
    foreach (glob($root . '/private/*') as $p) unlink($p);
    rmdir($root . '/logs'); rmdir($root . '/private'); rmdir($root);
}
