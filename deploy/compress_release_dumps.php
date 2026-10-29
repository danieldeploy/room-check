<?php
declare(strict_types=1);
/**
 * Conserve every recovery point while compressing redundant plaintext SQL dumps.
 * Private CLI only, under the existing release lock. Never accesses mail.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

function dumpCompactRequire(bool $ok, string $code): void {
    if (!$ok) { throw new RuntimeException($code); }
}

function dumpCompactOne(string $directory, string $root): int {
    dumpCompactRequire(
        (bool)preg_match('/^release-[0-9]{8}-[0-9]{6}-[a-f0-9]{12}-[a-f0-9]{12}$/D', basename($directory))
        && is_dir($directory) && !is_link($directory)
        && realpath(dirname($directory)) === realpath($root), 'unexpected_backup_path');
    $manifestFile = $directory . '/complete.json';
    $sql = $directory . '/database.sql';
    $gz = $directory . '/database.sql.gz';
    if (!is_file($sql) || is_link($sql) || !is_file($manifestFile) || is_link($manifestFile)) {
        return 0;
    }
    dumpCompactRequire(!file_exists($gz) && !is_link($gz), 'gzip_target_exists');
    $raw = file_get_contents($manifestFile);
    dumpCompactRequire(is_string($raw) && strlen($raw) <= 32768, 'manifest_unreadable');
    $manifest = json_decode($raw, true);
    dumpCompactRequire(is_array($manifest) && ($manifest['format'] ?? null) === 1
        && is_array($manifest['sha256'] ?? null), 'manifest_invalid');
    $entries = $manifest['sha256'];
    dumpCompactRequire(isset($entries['database.sql']), 'manifest_missing_database');
    foreach ($entries as $name => $digest) {
        dumpCompactRequire(is_string($name)
            && (bool)preg_match('/^(?:database\.sql|application\.tar\.gz|private-(?:cron|invoice-runner|migrations)\.tar\.gz)$/D', $name)
            && is_string($digest) && (bool)preg_match('/^[a-f0-9]{64}$/D', $digest),
            'manifest_unexpected_artifact');
        $file = $directory . '/' . $name;
        dumpCompactRequire(is_file($file) && !is_link($file)
            && hash_file('sha256', $file) === $digest, 'backup_checksum_mismatch');
    }
    $size = filesize($sql);
    dumpCompactRequire(is_int($size) && $size > 100, 'database_empty');
    $temp = $directory . '/.database.sql.gz.' . bin2hex(random_bytes(6));
    $src = null;
    $out = null;
    try {
        $src = fopen($sql, 'rb');
        $out = gzopen($temp, 'wb6');
        dumpCompactRequire(is_resource($src) && is_resource($out), 'compression_open_failed');
        while (!feof($src)) {
            $chunk = fread($src, 65536);
            dumpCompactRequire(is_string($chunk) && ($chunk !== '' || feof($src)), 'compression_read_failed');
            if ($chunk === '') { break; }
            dumpCompactRequire(gzwrite($out, $chunk) === strlen($chunk), 'compression_write_failed');
        }
        fclose($src); $src = null;
        dumpCompactRequire(gzclose($out), 'compression_close_failed'); $out = null;
        dumpCompactRequire(is_file($temp) && filesize($temp) > 16, 'compression_empty');
        // Verify all uncompressed bytes against the original manifest before any replacement.
        $in = gzopen($temp, 'rb');
        dumpCompactRequire(is_resource($in), 'compression_verify_open');
        $hash = hash_init('sha256');
        $decodedBytes = 0;
        try {
            while (!gzeof($in)) {
                $chunk = gzread($in, 65536);
                dumpCompactRequire(is_string($chunk) && ($chunk !== '' || gzeof($in)), 'compression_verify_read');
                $decodedBytes += strlen($chunk);
                hash_update($hash, $chunk);
            }
        } finally { gzclose($in); }
        dumpCompactRequire($decodedBytes === $size
            && hash_final($hash) === $entries['database.sql'], 'compression_verify_mismatch');
        $compressed = filesize($temp);
        if (!is_int($compressed) || $compressed >= $size) { unlink($temp); return 0; }
        dumpCompactRequire(chmod($temp, 0600), 'compression_permissions');
        dumpCompactRequire(rename($temp, $gz), 'compression_commit');
        $new = $manifest;
        $new['format'] = 2;
        unset($new['sha256']['database.sql']);
        $new['sha256']['database.sql.gz'] = hash_file('sha256', $gz);
        $mtemp = $directory . '/.complete.json.' . bin2hex(random_bytes(6));
        $encoded = json_encode($new, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
        try {
            dumpCompactRequire(file_put_contents($mtemp, $encoded, LOCK_EX) === strlen($encoded), 'manifest_write');
            dumpCompactRequire(chmod($mtemp, 0600), 'manifest_permissions');
            dumpCompactRequire(rename($mtemp, $manifestFile), 'manifest_commit');
        } finally {
            if (file_exists($mtemp)) { unlink($mtemp); }
        }
        // In every failure before this line, the original SQL and manifest remain.
        dumpCompactRequire(unlink($sql), 'sql_remove_failed');
        return $size - $compressed;
    } finally {
        if (is_resource($src)) { fclose($src); }
        if (is_resource($out)) { gzclose($out); }
        if (file_exists($temp)) { unlink($temp); }
    }
}

function dumpCompactMain(): int {
    umask(0077);
    ini_set('display_errors', '0');
    $lock = null;
    try {
        dumpCompactRequire(extension_loaded('zlib'), 'zlib_missing');
        $home = realpath((string)getenv('HOME'));
        dumpCompactRequire(is_string($home) && $home !== '/' && is_dir($home), 'home_invalid');
        $root = $home . '/room-check-backups';
        dumpCompactRequire(is_dir($root) && !is_link($root), 'backup_root_invalid');
        $lockPath = $root . '/release-backup.lock';
        dumpCompactRequire(!is_link($lockPath), 'lock_invalid');
        $lock = fopen($lockPath, 'c');
        dumpCompactRequire(is_resource($lock) && flock($lock, LOCK_EX | LOCK_NB), 'backup_busy');
        $dirs = glob($root . '/release-*', GLOB_ONLYDIR);
        dumpCompactRequire(is_array($dirs), 'backup_listing_failed');
        sort($dirs, SORT_STRING);
        $processed = 0; $saved = 0; $skipped = 0;
        $threshold = time() - 48 * 3600;
        foreach ($dirs as $dir) {
            if ($processed >= 50) { break; }
            if (is_link($dir) || !preg_match('/^release-([0-9]{8})-([0-9]{6})-/', basename($dir), $m)) {
                ++$skipped; continue;
            }
            $time = DateTimeImmutable::createFromFormat('!Ymd-His', $m[1] . '-' . $m[2],
                new DateTimeZone('UTC'));
            if (!$time || $time->getTimestamp() >= $threshold || !is_file($dir . '/database.sql')) {
                continue;
            }
            try {
                $bytes = dumpCompactOne($dir, $root);
                if ($bytes > 0) { $processed++; $saved += $bytes; }
            } catch (Throwable $ignored) { ++$skipped; }
        }
        echo json_encode(['ok' => true, 'snapshots_preserved' => true,
            'sql_dumps_compressed' => $processed, 'bytes_reclaimed' => $saved,
            'skipped' => $skipped], JSON_THROW_ON_ERROR) . "\n";
        return 0;
    } catch (Throwable $ignored) {
        echo "{\"ok\":false,\"error\":\"backup_compaction_skipped\"}\n";
        return 1;
    } finally {
        if (is_resource($lock)) { flock($lock, LOCK_UN); fclose($lock); }
    }
}
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) { exit(dumpCompactMain()); }
