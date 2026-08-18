<?php
declare(strict_types=1);

final class My2NScheduleClock
{
    public static function dueMode(DateTimeImmutable $now): array
    {
        $local = $now->setTimezone(new DateTimeZone('Europe/Lisbon'));
        $minutes = ((int) $local->format('H')) * 60 + (int) $local->format('i');
        if ($minutes >= 15 * 60) return ['modeKey' => 'out_of_hours', 'localDate' => $local->format('Y-m-d')];
        if ($minutes >= 8 * 60) return ['modeKey' => 'reception', 'localDate' => $local->format('Y-m-d')];
        return ['modeKey' => 'out_of_hours', 'localDate' => $local->modify('-1 day')->format('Y-m-d')];
    }
}
