<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/ZKAccess/BackupStore.php';
require_once __DIR__ . '/../src/ZKAccess/BackupSchedule.php';
require_once __DIR__ . '/../src/ZKAccess/BackupDestination.php';

function assertBackup(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException('Backup test failed: ' . $message);
}
function expectBackupFailure(callable $work, string $message): void
{
    try { $work(); } catch (Throwable) { return; }
    throw new RuntimeException('Expected failure: ' . $message);
}
$root = sys_get_temp_dir() . '/zk-backup-test-' . bin2hex(random_bytes(6));
if (!mkdir($root, 0700) || !chmod($root, 0700)) throw new RuntimeException('test_storage');
file_put_contents($root . '/master.key', random_bytes(32));
chmod($root . '/master.key', 0600);
try {
    assertBackup(ZKAccessBackupSchedule::DEFAULT_TIME === '04:00', 'schedule');
    assertBackup(ZKAccessBackupSchedule::RETENTION_DAYS === 30, 'retention');
    assertBackup(ZKAccessBackupStore::validateTime('04:00') === '04:00', 'valid time');
    expectBackupFailure(static fn() => ZKAccessBackupStore::validateTime('24:00'), 'invalid time');
    expectBackupFailure(static fn() => ZKAccessBackupStore::validateTime('4:00'), 'invalid time format');
    assertBackup(ZKAccessBackupDestination::BACKUP_FOLDER_NAME === 'ZKTeco Backup', 'folder');

    $vault = new InvoiceVault($root);
    $store = new ZKAccessBackupStore($vault);
    $settings = $store->settings();
    assertBackup($settings['time'] === '04:00' && $settings['enabled'] === true, 'default settings');
    $store->saveSettings(false, '02:35');
    assertBackup($store->settings()['enabled'] === false && $store->settings()['time'] === '02:35', 'pause and reschedule');

    $id = bin2hex(random_bytes(16));
    $sql = 'CREATE TABLE test (id int);' . base64_encode(random_bytes(280000));
    $gzip = gzencode($sql, 6);
    assertBackup(is_string($gzip) && strlen($gzip) > 2 * ZKAccessBackupStore::CHUNK_BYTES, 'test fixture');
    $size = strlen($gzip); $sha = hash('sha256', $gzip);
    $begin = $store->begin(['id' => $id, 'size' => $size, 'sha256' => $sha]);
    assertBackup($begin['received'] === 0 && $begin['status'] === 'receiving', 'start');
    assertBackup($store->begin(['id' => $id, 'size' => $size, 'sha256' => $sha])['received'] === 0, 'idempotent start');
    expectBackupFailure(static fn() => $store->begin(['id' => $id, 'size' => $size + 1, 'sha256' => $sha]), 'start conflict');
    expectBackupFailure(static fn() => $store->begin(['id' => '../unsafe', 'size' => $size, 'sha256' => $sha]), 'invalid id');
    expectBackupFailure(static fn() => $store->begin(['id' => bin2hex(random_bytes(16)), 'size' => $size, 'sha256' => $sha]), 'only one active upload');

    $first = substr($gzip, 0, ZKAccessBackupStore::CHUNK_BYTES);
    $request = ['id' => $id, 'offset' => 0, 'data' => base64_encode($first)];
    assertBackup($store->chunk($request)['received'] === strlen($first), 'chunk');
    assertBackup($store->chunk($request)['duplicate'] === true, 'retry identical chunk');
    expectBackupFailure(static fn() => $store->chunk(['id' => $id, 'offset' => 0,
        'data' => base64_encode(str_repeat('x', strlen($first)))]), 'conflicting retry');
    expectBackupFailure(static fn() => $store->finish(['id' => $id]), 'incomplete archive');
    $offset = strlen($first);
    while ($offset < $size) {
        $segment = substr($gzip, $offset, ZKAccessBackupStore::CHUNK_BYTES);
        $result = $store->chunk(['id' => $id, 'offset' => $offset, 'data' => base64_encode($segment)]);
        $offset += strlen($segment);
        assertBackup($result['received'] === $offset, 'ordered chunks');
    }
    $done = $store->finish(['id' => $id]);
    assertBackup($done['verified'] === true, 'end-to-end authenticated archive');
    assertBackup($store->finish(['id' => $id])['verified'] === true, 'repeat finalization');
    assertBackup($store->status()[0]['status'] === 'ready', 'status visible');
    $encrypted = file_get_contents($vault->path(ZKAccessBackupStore::dataName($id)));
    assertBackup(is_string($encrypted) && !str_contains($encrypted, 'CREATE TABLE test'), 'plaintext not stored');
    assertBackup(strlen($encrypted) > 7 && substr($encrypted, 0, 7) === "ZKBAK1\n", 'archive format');
    assertBackup(gzdecode($gzip) === $sql, 'gzip is recoverable');

    $archive = $vault->path(ZKAccessBackupStore::dataName($id));
    $handle = fopen($archive, 'r+b');
    fseek($handle, 20);
    fwrite($handle, "\0");
    fclose($handle);
    expectBackupFailure(static fn() => $store->verify($store->entries()[$id]), 'modified ciphertext refused');

    echo "ZKAccess encrypted backup tests: OK\n";
} finally {
    $files = glob($root . '/*') ?: [];
    foreach ($files as $file) if (is_file($file)) unlink($file);
    rmdir($root);
}
