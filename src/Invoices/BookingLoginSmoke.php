<?php
declare(strict_types=1);
require_once __DIR__.'/InvoiceRemoteAgent.php';

/** One reviewed Booking login request, seeded by the protected production release. */
final class BookingLoginSmoke
{
    public const ACCOUNT_ID = 1;
    public const PERIOD = '2026-08';
    public const KEY = InvoiceRemoteAgent::BOOKING_FRESH_LOGIN_SMOKE_KEY;

    /** The caller holds the invoice worker lock. The key survives task completion. */
    public static function enqueue(PDO $pdo, InvoiceVault $vault, InvoiceRemoteAgent $agent): array
    {
        $accounts = new InvoiceAccounts($pdo);
        $account = $accounts->get(self::ACCOUNT_ID);
        if ($account['portal'] !== 'booking') throw new RuntimeException('account_mismatch');

        $find = $pdo->prepare('SELECT id,account_id,kind,property_id,period,state FROM invoice_tasks WHERE schedule_key=?');
        $find->execute([self::KEY]);
        if ($previous = $find->fetch(PDO::FETCH_ASSOC)) {
            if ((int)$previous['account_id'] !== self::ACCOUNT_ID || $previous['kind'] !== 'login'
                || $previous['period'] !== self::PERIOD) throw new RuntimeException('invalid_request');
            return ['id'=>(int)$previous['id'],'state'=>$previous['state'],'created'=>false];
        }

        if (!$accounts->active(self::ACCOUNT_ID)) throw new RuntimeException('account_inactive');
        $properties = $accounts->collectionProperties(self::ACCOUNT_ID);
        if (!$properties) throw new RuntimeException('properties_required');
        $status = $agent->status();
        if ($status['mode'] !== 'windows' || !$status['paired'] || ($status['probe_code'] ?? '') !== 'ok') {
            throw new RuntimeException('agent_test_required');
        }
        // Do not read or log the secret. The authenticated Windows claim reads it later.
        if (!$vault->has(InvoiceAccounts::secretName(self::ACCOUNT_ID, 'credentials'))
            && !$vault->has('booking-credentials.enc')) throw new RuntimeException('auth_unconfigured');

        $active = $pdo->prepare("SELECT id,property_id,period,schedule_key,state FROM invoice_tasks
            WHERE account_id=? AND kind='login' AND state IN ('queued','retry','running','waiting_auth') ORDER BY id DESC LIMIT 2");
        $active->execute([self::ACCOUNT_ID]);
        $pending = $active->fetchAll(PDO::FETCH_ASSOC);
        if (count($pending)>1) throw new RuntimeException('login_already_active');
        if ($pending) {
            $task=$pending[0];
            if (!in_array($task['state'], ['queued','retry'], true) || $task['period'] !== self::PERIOD
                || !isset($properties[$task['property_id']])
                || ($task['schedule_key'] !== null && $task['schedule_key'] !== self::KEY)) {
                throw new RuntimeException('login_already_active');
            }
            self::reserve($pdo,(int)$task['id']);
            return ['id'=>(int)$task['id'],'state'=>$task['state'],'created'=>false];
        }

        $id = (new InvoiceService($pdo))->enqueue('login',(string)array_key_first($properties),
            self::PERIOD,null,self::KEY,self::ACCOUNT_ID);
        // A concurrent manager request may have won the active-key race. Reserve that
        // same task so a later deployment cannot create another login attempt.
        self::reserve($pdo,$id);
        $task=$pdo->prepare('SELECT state,account_id,kind,period FROM invoice_tasks WHERE id=? AND schedule_key=?');
        $task->execute([$id,self::KEY]);
        $result=$task->fetch(PDO::FETCH_ASSOC);
        if (!$result || (int)$result['account_id']!==self::ACCOUNT_ID || $result['kind']!=='login'
            || $result['period']!==self::PERIOD) throw new RuntimeException('invalid_request');
        return ['id'=>$id,'state'=>$result['state'],'created'=>true];
    }

    private static function reserve(PDO $pdo, int $id): void
    {
        $s=$pdo->prepare('UPDATE invoice_tasks SET schedule_key=? WHERE id=? AND schedule_key IS NULL');
        $s->execute([self::KEY,$id]);
        $check=$pdo->prepare('SELECT schedule_key FROM invoice_tasks WHERE id=?');
        $check->execute([$id]);
        if ($check->fetchColumn()!==self::KEY) throw new RuntimeException('invalid_request');
    }
}
