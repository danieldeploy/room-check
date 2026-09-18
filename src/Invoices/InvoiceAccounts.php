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
        $vault->save(self::secretName($id, 'credentials'), $data);
        $vault->save(self::secretName($id, 'session'), ['cookies' => []]);
        $this->pdo->prepare("UPDATE invoice_accounts SET auth_method = ?, status = 'configured', login_verified_at = NULL, enabled = 0 WHERE id = ?")
            ->execute([$method, $id]);
    }
}
