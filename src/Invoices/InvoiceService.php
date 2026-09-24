<?php
declare(strict_types=1);
require_once __DIR__ . '/InvoiceAccounts.php';

final class InvoiceService
{
    public const PROPERTIES = ['1140306' => 'Welcome Guest House', '539828' => 'City Center Guest House'];
    public function __construct(private readonly PDO $pdo) {}
    public static function utcNow(): string { return gmdate('Y-m-d H:i:s'); }
    public function settings(): array
    {
        $row = $this->pdo->query('SELECT * FROM invoice_settings WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new RuntimeException('migration_required');
        return $row;
    }
    public static function validPeriod(string $period): bool { return preg_match('/\A20\d{2}-(?:0[1-9]|1[0-2])\z/', $period) === 1; }
    public static function assertGerente(array $user): void
    {
        if (($user['role'] ?? '') !== 'gerente') throw new RuntimeException('forbidden', 403);
    }
    public function browserReady(): bool
    {
        $s = $this->settings();
        return (int) $s['browser_ready'] === 1 && strtotime(($s['browser_checked_at'] ?? '') . ' UTC') >= time() - 86400;
    }
    public function enqueue(string $kind, string $property, string $period, ?int $actor, ?string $scheduleKey = null, int $accountId = 1): int
    {
        $accounts = new InvoiceAccounts($this->pdo);
        $accounts->get($accountId);
        if ($kind !== 'preflight' && !$accounts->active($accountId)) throw new RuntimeException('account_inactive');
        if (!in_array($kind, ['preflight', 'login', 'collect', 'discover'], true) || !self::validPeriod($period)
            || !array_key_exists($property, $accounts->properties($accountId))) throw new RuntimeException('invalid_request');
        $active = $kind === 'preflight' ? 'preflight' : "$accountId:$property:$period:$kind";
        try {
            $this->pdo->prepare('INSERT INTO invoice_tasks (account_id, kind, property_id, period, active_key, schedule_key, requested_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$accountId, $kind, $property, $period, $active, $scheduleKey, $actor, self::utcNow()]);
            return (int) $this->pdo->lastInsertId();
        } catch (PDOException $e) {
            if ($e->getCode() !== '23000') throw $e;
            $s = $this->pdo->prepare('SELECT id FROM invoice_tasks WHERE active_key = ? OR schedule_key = ? ORDER BY id DESC LIMIT 1');
            $s->execute([$active, $scheduleKey]);
            $id = $s->fetchColumn();
            if ($id === false) throw $e;
            return (int) $id;
        }
    }
    public function saveSchedule(bool $enabled, int $day, string $time, int $accountId = 1): void
    {
        $a = (new InvoiceAccounts($this->pdo))->get($accountId);
        if ($enabled && !(new InvoiceAccounts($this->pdo))->active($accountId)) throw new RuntimeException('account_inactive');
        if ($enabled && in_array($a['portal'], ['expedia','hostelsclub','email'], true)) throw new RuntimeException('connector_unconfigured');
        if ($day < 1 || $day > 28 || !preg_match('/\A(?:[01]\d|2[0-3]):[0-5]\d\z/', $time)) throw new RuntimeException('invalid_schedule');
        $this->pdo->prepare('UPDATE invoice_accounts SET enabled = ?, schedule_day = ?, schedule_time = ? WHERE id = ?')
            ->execute([(int) $enabled, $day, $time, $accountId]);
    }
    public function scheduleDue(DateTimeImmutable $now): void
    {
        $accounts = new InvoiceAccounts($this->pdo);
        $local = $now->setTimezone(new DateTimeZone('Europe/Lisbon'));
        foreach ($accounts->all() as $a) {
            if (!(int) $a['enabled'] || !$accounts->active((int) $a['id'])) continue;
            $due = $local->format('Y-m-') . sprintf('%02d', $a['schedule_day']) . ' ' . $a['schedule_time'];
            if ($local->format('Y-m-d H:i') < $due) continue;
            $period = $local->modify('first day of last month')->format('Y-m');
            $targets = [];
            foreach ($accounts->collectionProperties((int) $a['id']) as $p => $_) $targets[] = ['account_id'=>(int)$a['id'], 'property_id'=>(string)$p];
            if ($targets) $this->collectBatch($targets, $period, null, hash('sha256', 'scheduled:' . $a['id'] . ':' . $local->format('Y-m')), 'scheduled');
        }
    }

    /** Atomic request creation; shared active tasks can appear in more than one request. */
    public function collectBatch(array $targets, string $period, ?int $actor, string $key, string $source = 'manual', ?int $retryOf = null): int
    {
        if (!$targets || !self::validPeriod($period) || !preg_match('/\A[a-f0-9]{64}\z/', $key)
            || !in_array($source, ['manual','scheduled','retry'], true)) throw new RuntimeException('invalid_request');
        $existing = $this->pdo->prepare('SELECT * FROM invoice_batches WHERE request_key=?'); $existing->execute([$key]);
        if ($batch = $existing->fetch(PDO::FETCH_ASSOC)) {
            if ($batch['period'] !== $period || (int)$batch['requested_by'] !== (int)$actor) throw new RuntimeException('invalid_request');
            return (int)$batch['id'];
        }
        $accounts = new InvoiceAccounts($this->pdo);
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('INSERT INTO invoice_batches (request_key,period,source,requested_by,retry_of,created_at) VALUES (?,?,?,?,?,?)')
                ->execute([$key,$period,$source,$actor,$retryOf,self::utcNow()]);
            $id = (int)$this->pdo->lastInsertId(); $seen = [];
            foreach ($targets as $target) {
                $account = (int)$target['account_id']; $property = (string)$target['property_id'];
                if (!isset($accounts->collectionProperties($account)[$property])) throw new RuntimeException('property_inactive');
                $targetKey = $account . ':' . $property; if (isset($seen[$targetKey])) continue; $seen[$targetKey] = true;
                $scheduleKey = $source === 'scheduled' ? $account . ':monthly:' . (new DateTimeImmutable($period . '-01'))->modify('+1 month')->format('Y-m') . ':' . $property : null;
                $task = $this->enqueue('collect',$property,$period,$actor,$scheduleKey,$account);
                $this->pdo->prepare('INSERT INTO invoice_batch_tasks (batch_id,task_id) VALUES (?,?)')->execute([$id,$task]);
            }
            $this->pdo->commit(); return $id;
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            if ($e instanceof PDOException && $e->getCode()==='23000') {
                $existing->execute([$key]); $batch=$existing->fetch(PDO::FETCH_ASSOC);
                if ($batch && $batch['period']===$period && (int)$batch['requested_by']===(int)$actor) return (int)$batch['id'];
            }
            throw $e;
        }
    }
    public function import(InvoiceVault $vault, array $job, array $document): bool
    {
        $accountId = (int) ($job['account_id'] ?? 1);
        $accounts = new InvoiceAccounts($this->pdo);
        $account = $accounts->get($accountId);
        $number = trim((string) ($document['number'] ?? ''));
        $format = (string) ($document['format'] ?? 'pdf');
        $data = base64_decode((string) ($document['content'] ?? $document['pdf'] ?? ''), true);
        $date = $document['issued_on'] ?? null;
        $basis = (string) ($document['period_basis'] ?? 'issue_month');
        $period = (string) ($document['period'] ?? ($date ? substr($date, 0, 7) : ''));
        $parsed = is_string($date) ? DateTimeImmutable::createFromFormat('!Y-m-d', $date) : false;
        if ($number === '' || strlen($number) > 128 || preg_match('/[\x00-\x1f\x7f]/', $number)
            || !isset($accounts->properties($accountId)[$job['property_id']])
            || $period !== $job['period'] || $basis !== $account['period_basis']
            || !in_array($format, ['pdf', 'csv'], true) || $data === false || strlen($data) < 8 || strlen($data) > 20 * 1024 * 1024
            || ($date !== null && (!$parsed || $parsed->format('Y-m-d') !== $date))
            || ($basis === 'issue_month' && (!$date || substr($date, 0, 7) !== $period))) throw new RuntimeException('invalid_document');
        if ($format === 'pdf' && !str_starts_with($data, '%PDF-')) throw new RuntimeException('invalid_document');
        if ($format === 'csv') {
            if ($account['portal'] !== 'airbnb' || $basis !== 'export_month' || $job['property_id'] !== 'account'
                || !preg_match('//u', $data) || str_contains($data, "\0") || preg_match('/\A\s*(?:<!|<html)/i', $data)) throw new RuntimeException('invalid_document');
            $line = strtok(ltrim($data, "\xEF\xBB\xBF"), "\r\n");
            $headers = str_getcsv((string) $line, ',', '"', '');
            if (count($headers) < 2 || !array_filter($headers, static fn($h) => preg_match('/invoice|fatura|factura|rechnung/i', $h))) throw new RuntimeException('invalid_document');
        }
        $hash = hash('sha256', $data);
        $s = $this->pdo->prepare('SELECT invoice_number, sha256 FROM invoice_documents WHERE account_id = ? AND property_id = ? AND (invoice_number = ? OR sha256 = ?)');
        $s->execute([$accountId, $job['property_id'], $number, $hash]);
        $rows = $s->fetchAll(PDO::FETCH_ASSOC);
        if ($rows) {
            foreach ($rows as $row) if ($row['invoice_number'] === $number && $row['sha256'] !== $hash) throw new RuntimeException('invoice_conflict');
            return false;
        }
        InvoiceVault::atomicWrite($vault->path($hash . '.' . $format), $data);
        $this->pdo->prepare('INSERT INTO invoice_documents (account_id, property_id, invoice_number, issued_on, period, period_basis, format, sha256, size_bytes, job_id, pipeline_state, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$accountId, $job['property_id'], $number, $date, $period, $basis, $format, $hash, strlen($data), $job['id'],
                $account['portal'] === 'airbnb' ? 'awaiting_csv_processing' : 'collected', self::utcNow()]);
        return true;
    }
}
