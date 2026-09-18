<?php
declare(strict_types=1);

require_once __DIR__ . '/InvoiceVault.php';

final class InvoiceService
{
    public const PROPERTIES = ['1140306' => 'Welcome Guest House', '539828' => 'City Center Guest House'];

    public function __construct(private readonly PDO $pdo) {}

    public static function utcNow(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    public function settings(): array
    {
        $row = $this->pdo->query('SELECT * FROM invoice_settings WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new RuntimeException('migration_required');
        }
        return $row;
    }

    public static function validPeriod(string $period): bool
    {
        return preg_match('/\A20\d{2}-(?:0[1-9]|1[0-2])\z/', $period) === 1;
    }

    public static function assertGerente(array $user): void
    {
        if (($user['role'] ?? '') !== 'gerente') {
            throw new RuntimeException('forbidden', 403);
        }
    }

    public function browserReady(): bool
    {
        $settings = $this->settings();
        $checked = strtotime(($settings['browser_checked_at'] ?? '') . ' UTC');
        return (int) $settings['browser_ready'] === 1 && $checked !== false && $checked >= time() - 86400;
    }

    public function enqueue(string $kind, string $property, string $period, ?int $actor, ?string $scheduleKey = null): int
    {
        if (!in_array($kind, ['preflight', 'login', 'collect'], true)
            || !isset(self::PROPERTIES[$property]) || !self::validPeriod($period)) {
            throw new InvalidArgumentException('invalid_request');
        }
        $active = $kind === 'preflight' ? 'preflight' : $property . ':' . $period . ':' . $kind;
        try {
            $statement = $this->pdo->prepare('INSERT INTO invoice_jobs
                (kind, property_id, period, active_key, schedule_key, requested_by, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?)');
            $statement->execute([$kind, $property, $period, $active, $scheduleKey, $actor, self::utcNow()]);
            return (int) $this->pdo->lastInsertId();
        } catch (PDOException $e) {
            if ($e->getCode() !== '23000') {
                throw $e;
            }
            // Only a collision on this exact active/scheduled job counts as idempotency.
            $statement = $this->pdo->prepare('SELECT id FROM invoice_jobs WHERE active_key = ? OR schedule_key = ? ORDER BY id DESC LIMIT 1');
            $statement->execute([$active, $scheduleKey]);
            $id = $statement->fetchColumn();
            if ($id === false) {
                throw $e;
            }
            return (int) $id;
        }
    }

    public function saveSchedule(bool $enabled, int $day, string $time): void
    {
        if ($day < 1 || $day > 28 || !preg_match('/\A(?:[01]\d|2[0-3]):[0-5]\d\z/', $time)) {
            throw new InvalidArgumentException('invalid_schedule');
        }
        $this->pdo->prepare('UPDATE invoice_settings SET enabled = ?, schedule_day = ?, schedule_time = ? WHERE id = 1')
            ->execute([(int) $enabled, $day, $time]);
    }

    public function scheduleDue(DateTimeImmutable $now): void
    {
        $settings = $this->settings();
        if (!(int) $settings['enabled']) {
            return;
        }
        $local = $now->setTimezone(new DateTimeZone('Europe/Lisbon'));
        $due = $local->format('Y-m-') . sprintf('%02d', $settings['schedule_day']) . ' ' . $settings['schedule_time'];
        if ($local->format('Y-m-d H:i') < $due) {
            return;
        }
        $period = $local->modify('first day of last month')->format('Y-m');
        foreach (self::PROPERTIES as $property => $_name) {
            $this->enqueue('collect', (string) $property, $period, null, 'monthly:' . $local->format('Y-m') . ':' . $property);
        }
    }

    public function import(InvoiceVault $vault, array $job, array $document): bool
    {
        $number = trim((string) ($document['number'] ?? ''));
        $date = (string) ($document['issued_on'] ?? '');
        $pdf = base64_decode((string) ($document['pdf'] ?? ''), true);
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($number === '' || strlen($number) > 128 || preg_match('/[\x00-\x1f\x7f]/', $number)
            || !$parsed || $parsed->format('Y-m-d') !== $date || substr($date, 0, 7) !== $job['period']
            || !isset(self::PROPERTIES[$job['property_id']])
            || $pdf === false || strlen($pdf) > 20 * 1024 * 1024 || !str_starts_with($pdf, '%PDF-')) {
            throw new RuntimeException('invalid_document');
        }
        $hash = hash('sha256', $pdf);
        $existing = $this->pdo->prepare('SELECT invoice_number, sha256 FROM portal_invoices WHERE portal = ? AND property_id = ? AND (invoice_number = ? OR sha256 = ?)');
        $existing->execute(['booking', $job['property_id'], $number, $hash]);
        $rows = $existing->fetchAll(PDO::FETCH_ASSOC);
        if ($rows !== []) {
            foreach ($rows as $row) {
                if ($row['invoice_number'] === $number && $row['sha256'] !== $hash) {
                    throw new RuntimeException('invoice_conflict');
                }
            }
            return false;
        }
        InvoiceVault::atomicWrite($vault->path($hash . '.pdf'), $pdf);
        $statement = $this->pdo->prepare('INSERT INTO portal_invoices
            (portal, property_id, invoice_number, issued_on, period, sha256, size_bytes, job_id, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $statement->execute(['booking', $job['property_id'], $number, $date, $job['period'], $hash, strlen($pdf), $job['id'], self::utcNow()]);
        return true;
    }
}
