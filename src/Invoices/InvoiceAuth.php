<?php
declare(strict_types=1);
require_once __DIR__ . '/InvoiceAccounts.php';

final class InvoiceAuth
{
    public function __construct(private readonly PDO $pdo, private readonly InvoiceVault $vault) {}

    public function rotateSmsToken(int $accountId): string
    {
        (new InvoiceAccounts($this->pdo))->get($accountId);
        $token = bin2hex(random_bytes(32));
        $this->pdo->prepare('UPDATE invoice_accounts SET sms_token_hash = ? WHERE id = ?')->execute([hash('sha256', $token), $accountId]);
        return $token;
    }

    public function register(array $job, array $request): void
    {
        $id = (string) ($request['id'] ?? '');
        $created = (int) ($request['created'] ?? 0);
        if (!preg_match('/\A[a-f0-9]{32}\z/', $id) || ($request['method'] ?? '') !== 'sms'
            || $created < time() - 30 || $created > time() + 5) throw new RuntimeException('auth_unconfigured');
        $this->pdo->prepare("UPDATE invoice_auth_challenges SET state = 'expired' WHERE task_id = ? AND state IN ('waiting', 'received')")->execute([$job['id']]);
        $this->pdo->prepare('INSERT INTO invoice_auth_challenges (id, account_id, task_id, method, created_at, expires_at) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$id, $job['account_id'], $job['id'], 'sms', gmdate('Y-m-d H:i:s', $created), gmdate('Y-m-d H:i:s', $created + 150)]);
        $this->pdo->prepare("UPDATE invoice_tasks SET state = 'waiting_auth' WHERE id = ?")->execute([$job['id']]);
    }

    /** Only called by the authenticated SMS receiver. Raw messages are never persisted. */
    public function receiveSms(int $accountId, string $token, array $payload): bool
    {
        $accounts = new InvoiceAccounts($this->pdo);
        $account = $accounts->get($accountId);
        if (strlen($token) !== 64 || !hash_equals((string) $account['sms_token_hash'], hash('sha256', $token))) throw new RuntimeException('forbidden', 403);
        $credentials = $accounts->credentials($this->vault, $accountId);
        $sender = trim((string) ($payload['sender'] ?? ''));
        $message = (string) ($payload['message'] ?? '');
        $received = filter_var($payload['received_at'] ?? '', FILTER_VALIDATE_INT);
        if (!$received || abs(time() - $received) > 90 || strlen($message) > 2048
            || empty($credentials['sms_sender']) || empty($credentials['sms_keyword'])
            || strcasecmp($sender, trim($credentials['sms_sender'])) !== 0
            || stripos($message, $credentials['sms_keyword']) === false
            || (!empty($credentials['sms_sim']) && (string) ($payload['sim'] ?? '') !== $credentials['sms_sim'])) return false;
        preg_match_all('/(?<!\d)\d{6}(?!\d)/', $message, $codes);
        if (count($codes[0]) !== 1) return false;
        $s = $this->pdo->prepare("SELECT c.* FROM invoice_auth_challenges c JOIN invoice_tasks t ON t.id = c.task_id
            WHERE c.account_id = ? AND c.method = 'sms' AND c.state = 'waiting' AND c.expires_at > ?
            AND t.state IN ('running', 'waiting_auth') ORDER BY c.created_at DESC LIMIT 2");
        $s->execute([$accountId, gmdate('Y-m-d H:i:s')]);
        $matches = $s->fetchAll(PDO::FETCH_ASSOC);
        if (count($matches) !== 1 || $received < strtotime($matches[0]['created_at'] . ' UTC')) return false;
        $challenge = $matches[0];
        // Bind ciphertext and replay key to this account and challenge. Conditional update wins once.
        $hash = hash('sha256', $accountId . "\0" . $sender . "\0" . $message . "\0" . $received);
        $name = 'otp-' . $challenge['id'] . '.enc';
        $this->pdo->beginTransaction();
        try {
            $s = $this->pdo->prepare("UPDATE invoice_auth_challenges SET state = 'receiving', event_hash = ? WHERE id = ? AND state = 'waiting'");
            $s->execute([$hash, $challenge['id']]);
            if ($s->rowCount() !== 1) { $this->pdo->rollBack(); return false; }
            $this->vault->save($name, ['value' => $codes[0][0], 'account' => $accountId, 'challenge' => $challenge['id']]);
            $this->pdo->prepare("UPDATE invoice_auth_challenges SET state = 'received' WHERE id = ?")->execute([$challenge['id']]);
            $this->pdo->commit();
            return true;
        } catch (PDOException $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            if ($e->getCode() === '23000') return false;
            throw $e;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    public function consume(string $id, int $taskId): ?string
    {
        if (!preg_match('/\A[a-f0-9]{32}\z/', $id)) return null;
        $s = $this->pdo->prepare("UPDATE invoice_auth_challenges SET state = 'consumed', consumed_at = ?
            WHERE id = ? AND task_id = ? AND state = 'received' AND expires_at > ?");
        $s->execute([gmdate('Y-m-d H:i:s'), $id, $taskId, gmdate('Y-m-d H:i:s')]);
        if ($s->rowCount() !== 1) return null;
        $name = 'otp-' . $id . '.enc';
        try {
            $value = $this->vault->read($name);
            if (($value['challenge'] ?? '') !== $id || !preg_match('/\A\d{6}\z/', (string) ($value['value'] ?? ''))) throw new RuntimeException('auth_invalid');
            return $value['value'];
        } finally {
            if ($this->vault->has($name)) unlink($this->vault->path($name));
        }
    }

    public function expire(int $taskId): void
    {
        $s = $this->pdo->prepare('SELECT id FROM invoice_auth_challenges WHERE task_id = ?');
        $s->execute([$taskId]);
        foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $name = 'otp-' . $id . '.enc';
            if ($this->vault->has($name)) unlink($this->vault->path($name));
        }
        $this->pdo->prepare("UPDATE invoice_auth_challenges SET state = 'expired' WHERE task_id = ? AND state <> 'consumed'")->execute([$taskId]);
    }
}
