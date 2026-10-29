<?php
declare(strict_types=1);

/**
 * ZKAccess backup schedule, independent of the room PIN automation schedule.
 * This class does not trigger a backup until the Windows agent is paired.
 */
final class ZKAccessBackupSchedule
{
    public const DEFAULT_TIME = '04:00';
    public const RETENTION_DAYS = 10;
    public const TIMEZONE = 'Europe/Lisbon';

    public static function normalizeTime(string $time): string
    {
        if (!preg_match('/\A(?:[01][0-9]|2[0-3]):[0-5][0-9]\z/D', $time)) {
            throw new InvalidArgumentException('Invalid backup time');
        }
        return $time;
    }

    public static function nextRun(string $time, DateTimeImmutable $now): DateTimeImmutable
    {
        $time = self::normalizeTime($time);
        $zone = new DateTimeZone(self::TIMEZONE);
        $local = $now->setTimezone($zone);
        $candidate = new DateTimeImmutable($local->format('Y-m-d') . ' ' . $time . ':00', $zone);
        if ($candidate <= $local) {
            $candidate = new DateTimeImmutable($local->modify('+1 day')->format('Y-m-d') . ' ' . $time . ':00', $zone);
        }
        return $candidate;
    }
}
