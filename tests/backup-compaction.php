<?php
declare(strict_types=1);
require dirname(__DIR__) . '/deploy/compress_release_dumps.php';

function assertCompact(bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
}
function cleanupCompact(string $path): void {
    if (is_dir($path) && !is_link($path)) {
        foreach (new FilesystemIterator($path) as $item) { cleanupCompact($item->getPathname()); }
        rmdir($path);
    } elseif (file_exists($path) || is_link($path)) { unlink($path); }
}
$root = sys_get_temp_dir() . '/hub-compress-' . bin2hex(random_bytes(5));
mkdir($root, 0700);
try {
    $valid = $root . '/release-20260922-100000-aaaaaaaaaaaa-bbbbbbbbbbbb';
    mkdir($valid, 0700);
    $sql = str_repeat("-- harmless test SQL row data\nINSERT INTO t VALUES (1);\n", 250);
    file_put_contents($valid . '/database.sql', $sql);
    file_put_contents($valid . '/application.tar.gz', 'test archive bytes');
    $manifest = ['format' => 1, 'sha256' => [
        'application.tar.gz' => hash_file('sha256', $valid . '/application.tar.gz'),
        'database.sql' => hash_file('sha256', $valid . '/database.sql')]];
    file_put_contents($valid . '/complete.json', json_encode($manifest));
    $saved = dumpCompactOne($valid, $root);
    assertCompact($saved > 0, 'compression did not reduce size');
    assertCompact(!is_file($valid . '/database.sql'), 'plaintext still present');
    assertCompact(file_get_contents('compress.zlib://' . $valid . '/database.sql.gz') === $sql,
                  'gzip roundtrip mismatch');
    $new = json_decode(file_get_contents($valid . '/complete.json'), true);
    assertCompact($new['format'] === 2 && !isset($new['sha256']['database.sql'])
        && $new['sha256']['database.sql.gz'] === hash_file('sha256', $valid . '/database.sql.gz'),
        'manifest mismatch');
    assertCompact(dumpCompactOne($valid, $root) === 0, 'not idempotent');

    $broken = $root . '/release-20260923-100000-aaaaaaaaaaaa-cccccccccccc';
    mkdir($broken, 0700);
    file_put_contents($broken . '/database.sql', $sql);
    file_put_contents($broken . '/complete.json', json_encode([
        'format' => 1, 'sha256' => ['database.sql' => str_repeat('0', 64)]]));
    try {
        dumpCompactOne($broken, $root);
        throw new LogicException('bad checksum accepted');
    } catch (RuntimeException $e) {
        assertCompact($e->getMessage() === 'backup_checksum_mismatch', 'wrong checksum failure');
    }
    assertCompact(is_file($broken . '/database.sql'), 'corrupt backup was modified');
    symlink($valid, $root . '/release-20260924-100000-aaaaaaaaaaaa-dddddddddddd');
    try {
        dumpCompactOne($root . '/release-20260924-100000-aaaaaaaaaaaa-dddddddddddd', $root);
        throw new LogicException('symlink was accepted');
    } catch (RuntimeException $e) {
        assertCompact($e->getMessage() === 'unexpected_backup_path', 'wrong symlink failure');
    }
    echo "Private backup compression tests passed.\n";
} finally { cleanupCompact($root); }
