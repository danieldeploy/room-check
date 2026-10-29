<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/src/ZKAccess/BackupSchedule.php';

function assertBackup(bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
}
assertBackup(ZKAccessBackupSchedule::normalizeTime('04:00') === '04:00', 'Default time');
foreach (['24:00', '4:00', '04:60', '12:00\n'] as $invalid) {
    try {
        ZKAccessBackupSchedule::normalizeTime($invalid);
        throw new RuntimeException('Accepted invalid time');
    } catch (InvalidArgumentException $expected) {}
}
$zone = new DateTimeZone('Europe/Lisbon');
$now = new DateTimeImmutable('2026-10-23 04:01:00', $zone);
assertBackup(ZKAccessBackupSchedule::nextRun('04:00', $now)->format('Y-m-d H:i') === '2026-10-24 04:00', 'Tomorrow rollover');
assertBackup(ZKAccessBackupSchedule::RETENTION_DAYS === 10, 'Retention');
echo "OK\n";
