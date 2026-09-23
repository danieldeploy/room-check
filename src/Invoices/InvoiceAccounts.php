<?php
declare(strict_types=1);
require_once __DIR__ . '/InvoiceVault.php';

final class InvoiceAccounts
{
    public const PORTALS = ['booking' => 'Booking.com', 'hostelworld' => 'Hostelworld', 'airbnb' => 'Airbnb',
        'email' => 'Email', 'expedia' => 'Expedia', 'hostelsclub' => 'HostelsClub'];
    public const METHODS = ['password', 'sms', 'email', 'totp'];

    public function __construct(private readonly PDO $pdo) {}

    public function all(): array
    {
        return $this->pdo->query('SELECT * FROM invoice_accounts ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    }

    public function get(int $id): array
    {
        $s = $this->pdo->prepare('SELECT * FROM invoice_accounts WHERE id = ?');
        $s->execute([$id]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new RuntimeException('invalid_request');
        return $row;
    }

    public function properties(int $id): array
    {
        $s = $this->pdo->prepare('SELECT property_id, label FROM invoice_account_properties WHERE account_id = ? ORDER BY property_id');
        $s->execute([$id]);
        return $s->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    public function lifecycle(int $id): array
    {
        $s = $this->pdo->prepare('SELECT is_active, archived_at FROM invoice_account_settings WHERE account_id = ?');
        $s->execute([$id]);
        return $s->fetch(PDO::FETCH_ASSOC) ?: ['is_active' => 1, 'archived_at' => null];
    }

    public function active(int $id): bool
    {
        $state = $this->lifecycle($id);
        return (int) $state['is_active'] === 1 && !$state['archived_at'];
    }

    public function activeProperties(int $id): array
    {
        $s = $this->pdo->prepare('SELECT p.property_id, p.label FROM invoice_account_properties p LEFT JOIN invoice_property_settings s
            ON s.account_id=p.account_id AND s.property_id=p.property_id WHERE p.account_id=? AND COALESCE(s.is_active,1)=1 ORDER BY p.property_id');
        $s->execute([$id]);
        return $s->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    public function collectionProperties(int $id): array
    {
        $properties = $this->activeProperties($id);
        return in_array($this->get($id)['portal'], ['airbnb', 'email'], true)
            ? array_intersect_key($properties, ['account' => true]) : $properties;
    }

    /** Caller holds the worker lock. Archiving never removes documents or credentials. */
    public function setLifecycle(int $id, bool $active, bool $archived = false): void
    {
        $this->get($id);
        $existing = $this->pdo->prepare('SELECT account_id FROM invoice_account_settings WHERE account_id=?');
        $existing->execute([$id]);
        $values = [(int) ($active && !$archived), $archived ? gmdate('Y-m-d H:i:s') : null, $id];
        $this->pdo->prepare($existing->fetchColumn() !== false
            ? 'UPDATE invoice_account_settings SET is_active=?, archived_at=? WHERE account_id=?'
            : 'INSERT INTO invoice_account_settings (is_active, archived_at, account_id) VALUES (?,?,?)')->execute($values);
        if (!$active || $archived) {
            $this->pdo->prepare('UPDATE invoice_accounts SET enabled=0 WHERE id=?')->execute([$id]);
            $this->pdo->prepare("UPDATE invoice_tasks SET state='cancelled', result_code='account_inactive', active_key=NULL,
                next_attempt_at=NULL, finished_at=? WHERE account_id=? AND state IN ('queued','retry')")
                ->execute([gmdate('Y-m-d H:i:s'), $id]);
        }
    }

    /** Existing property identifiers stay attached to historical documents. */
    public function updateDetails(int $id, string $label, array $properties, bool $active): void
    {
        $account = $this->get($id);
        if ($this->lifecycle($id)['archived_at']) throw new RuntimeException('account_archived');
        if (trim($label) === '' || strlen($label) > 120 || !$properties || count($properties) > 50) throw new RuntimeException('invalid_request');
        foreach ($properties as $key => $name) if (!preg_match('/\A[a-zA-Z0-9_-]{1,64}\z/', (string) $key)
            || trim($name) === '' || strlen($name) > 120) throw new RuntimeException('invalid_request');
        $accountScope = in_array($account['portal'], ['airbnb','email'], true);
        if ($accountScope) $properties['account'] = trim($label);
        $old = $this->activeProperties($id);
        $all = $this->properties($id);
        $changedTargets = !$accountScope && (array_diff_key($old, $properties) + array_diff_key($properties, $old)) !== [];
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('UPDATE invoice_accounts SET label=? WHERE id=?')->execute([trim($label), $id]);
            foreach ($properties as $key => $name) {
                $this->pdo->prepare(isset($all[$key])
                    ? 'UPDATE invoice_account_properties SET label=? WHERE account_id=? AND property_id=?'
                    : 'INSERT INTO invoice_account_properties (label,account_id,property_id) VALUES (?,?,?)')->execute([trim($name), $id, (string) $key]);
            }
            foreach ($all + $properties as $key => $_) {
                $s = $this->pdo->prepare('SELECT is_active FROM invoice_property_settings WHERE account_id=? AND property_id=?');
                $s->execute([$id, (string) $key]);
                $this->pdo->prepare($s->fetchColumn() !== false
                    ? 'UPDATE invoice_property_settings SET is_active=? WHERE account_id=? AND property_id=?'
                    : 'INSERT INTO invoice_property_settings (is_active,account_id,property_id) VALUES (?,?,?)')
                    ->execute([(int) isset($properties[$key]), $id, (string) $key]);
                if (!isset($properties[$key])) $this->pdo->prepare("UPDATE invoice_tasks SET state='cancelled', result_code='property_inactive', active_key=NULL,
                    next_attempt_at=NULL, finished_at=? WHERE account_id=? AND property_id=? AND state IN ('queued','retry')")
                    ->execute([gmdate('Y-m-d H:i:s'), $id, (string) $key]);
            }
            if ($changedTargets) $this->pdo->prepare("UPDATE invoice_accounts SET enabled=0,login_verified_at=NULL,status='configured' WHERE id=?")->execute([$id]);
            $this->setLifecycle($id, $active);
            $this->pdo->commit();
        } catch (Throwable $e) { $this->pdo->rollBack(); throw $e; }
    }

    public function create(string $portal, string $label, array $properties, string $method): int
    {
        if (!isset(self::PORTALS[$portal]) || trim($label) === '' || strlen($label) > 120
            || !in_array($method, self::METHODS, true) || !$properties || count($properties) > 50) throw new RuntimeException('invalid_request');
        if (in_array($portal, ['airbnb', 'email'], true)) $properties = ['account' => $label];
        foreach ($properties as $id => $name) {
            if (!preg_match('/\A[a-zA-Z0-9_-]{1,64}\z/', (string) $id) || trim($name) === '' || strlen($name) > 120) throw new RuntimeException('invalid_request');
        }
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('INSERT INTO invoice_accounts (portal, label, auth_method, period_basis, created_at) VALUES (?, ?, ?, ?, ?)')
                ->execute([$portal, trim($label), $method, $portal === 'airbnb' ? 'export_month' : 'issue_month', gmdate('Y-m-d H:i:s')]);
            $id = (int) $this->pdo->lastInsertId();
            $s = $this->pdo->prepare('INSERT INTO invoice_account_properties (account_id, property_id, label) VALUES (?, ?, ?)');
            foreach ($properties as $property => $name) $s->execute([$id, (string) $property, trim($name)]);
            $this->pdo->commit();
            return $id;
        } catch (Throwable $e) { $this->pdo->rollBack(); throw $e; }
    }

    public static function secretName(int $id, string $type): string
    {
        if ($id < 1 || !in_array($type, ['credentials', 'session'], true)) throw new RuntimeException('invalid_request');
        return 'account-' . $id . '-' . $type . '.enc';
    }

    public function credentials(InvoiceVault $vault, int $id): array
    {
        $this->get($id);
        $name = self::secretName($id, 'credentials');
        if (!$vault->has($name) && $id === 1 && $vault->has('booking-credentials.enc')) {
            $vault->save($name, $vault->read('booking-credentials.enc'));
            if ($vault->has('booking-session.enc')) $vault->save(self::secretName(1, 'session'), $vault->read('booking-session.enc'));
        }
        return $vault->has($name) ? $vault->read($name) : [];
    }

    public function saveCredentials(InvoiceVault $vault, int $id, array $input): void
    {
        $account = $this->get($id);
        $data = $this->credentials($vault, $id);
        $original = $data;
        // Blank secret fields preserve existing values; secrets are never sent back in HTML.
        foreach (['identifier', 'password', 'hostel_number', 'totp_secret', 'sms_sender', 'sms_keyword', 'sms_sim',
            'imap_host', 'imap_user', 'imap_password', 'imap_mailbox', 'email_sender', 'email_recipient', 'email_subject'] as $key) {
            if (isset($input[$key]) && $input[$key] !== '') {
                if (!is_string($input[$key]) || strlen($input[$key]) > 2048 || str_contains($input[$key], "\0")) throw new RuntimeException('invalid_request');
                $data[$key] = $input[$key];
            }
        }
        $method = (string) ($input['auth_method'] ?? $account['auth_method']);
        if (!in_array($method, self::METHODS, true)) throw new RuntimeException('invalid_request');
        if (!empty($data['totp_secret'])) {
            $data['totp_secret'] = strtoupper(str_replace(' ', '', $data['totp_secret']));
            if (!preg_match('/\A[A-Z2-7]{16,128}=*\z/', $data['totp_secret'])) throw new RuntimeException('invalid_request');
        }
        if ($method === 'totp' && empty($data['totp_secret'])) throw new RuntimeException('auth_unconfigured');
        if ($method === 'sms' && (empty($data['sms_sender']) || empty($data['sms_keyword']))) throw new RuntimeException('auth_unconfigured');
        if (($method === 'email' || $account['portal'] === 'email') &&
            (empty($data['imap_host']) || empty($data['imap_user']) || empty($data['imap_password']))) throw new RuntimeException('auth_unconfigured');
        if ($method === 'email' && (empty($data['email_sender']) || empty($data['email_recipient']) || empty($data['email_subject']))) throw new RuntimeException('auth_unconfigured');
        if ($data === $original && $method === $account['auth_method']) return;
        $vault->save(self::secretName($id, 'credentials'), $data);
        $vault->save(self::secretName($id, 'session'), ['cookies' => []]);
        $this->pdo->prepare("UPDATE invoice_accounts SET auth_method = ?, status = 'configured', login_verified_at = NULL, enabled = 0 WHERE id = ?")
            ->execute([$method, $id]);
    }
}
