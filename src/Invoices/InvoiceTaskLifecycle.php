<?php
declare(strict_types=1);
require_once __DIR__ . '/InvoiceService.php';
require_once __DIR__ . '/InvoiceAlerts.php';

/** The local runner and the HTTPS agent share document validation and task outcomes. */
final class InvoiceTaskLifecycle
{
    public function __construct(private readonly PDO $pdo) {}

    public function complete(InvoiceVault $vault, array $job, array $result, ?iterable $documents = null): void
    {
        $code = (string) ($result['code'] ?? 'worker_failed');
        if (!in_array($code, ['ok', 'no_invoices'], true)) throw new RuntimeException($code);
        if ($job['kind'] === 'preflight') {
            if ($code !== 'ok') throw new RuntimeException('browser_unavailable');
            $this->pdo->prepare('UPDATE invoice_settings SET browser_ready=1,browser_checked_at=? WHERE id=1')->execute([InvoiceService::utcNow()]);
        } else {
            if ($job['kind'] === 'collect') {
                $metadata = $result['documents'] ?? null;
                if (!is_array($metadata) || count($metadata) > 100 || ($code === 'ok' && !$metadata)
                    || ($code === 'no_invoices' && $metadata)) throw new RuntimeException('invalid_document');
                $service = new InvoiceService($this->pdo);
                $count = 0;
                foreach ($documents ?? $metadata as $document) {
                    if (++$count > count($metadata)) throw new RuntimeException('invalid_document');
                    $column = $service->import($vault, $job, $document) ? 'imported_count' : 'duplicate_count';
                    $this->pdo->prepare("UPDATE invoice_tasks SET $column=$column+1 WHERE id=?")->execute([$job['id']]);
                }
                if ($count !== count($metadata)) throw new RuntimeException('invalid_document');
            }
            if ($job['kind']!=='discover' && isset($result['session']) && is_array($result['session'])) {
                $vault->save(InvoiceAccounts::secretName((int)$job['account_id'], 'session'), $result['session']);
            }
            if ($job['kind']!=='discover') {
                $this->pdo->prepare("UPDATE invoice_accounts SET status='ready',login_verified_at=? WHERE id=?")
                    ->execute([InvoiceService::utcNow(), $job['account_id']]);
            }
        }
        $this->pdo->prepare("UPDATE invoice_tasks SET state='completed',result_code=?,active_key=NULL,finished_at=? WHERE id=?")
            ->execute([$code, InvoiceService::utcNow(), $job['id']]);
    }

    public function fail(array $job, string $code, InvoiceAlerts $alerts): void
    {
        $allowed = ['private_storage_unavailable','private_storage_permissions','vault_key_unavailable','vault_read_failed',
            'vault_write_failed','worker_timeout','worker_unavailable','invalid_document','invoice_conflict','connector_unconfigured',
            'browser_unavailable','network_error','needs_auth','auth_unconfigured','auth_timeout','auth_invalid','portal_changed',
            'document_limit','account_mismatch','interrupted','worker_failed'];
        if (!in_array($code, $allowed, true)) $code = 'worker_failed';
        $retry = (int)$job['attempts'] < 2 && in_array($code,
            ['auth_timeout','auth_invalid','network_error','worker_timeout','worker_unavailable','browser_unavailable','interrupted'], true);
        $state = $retry ? 'retry' : (in_array($code, ['needs_auth','auth_timeout','auth_invalid'], true) ? 'needs_auth' : 'failed');
        $this->pdo->prepare('UPDATE invoice_tasks SET state=?,result_code=?,active_key=?,next_attempt_at=?,finished_at=? WHERE id=?')
            ->execute([$state,$code,$retry ? $job['active_key'] : null,$retry ? gmdate('Y-m-d H:i:s',time()+120) : null,
                $retry ? null : InvoiceService::utcNow(),$job['id']]);
        if ($job['kind'] === 'preflight') {
            $this->pdo->prepare('UPDATE invoice_settings SET browser_ready=0,browser_checked_at=? WHERE id=1')->execute([InvoiceService::utcNow()]);
        } elseif ($job['kind']!=='discover') {
            $this->pdo->prepare('UPDATE invoice_accounts SET status=? WHERE id=?')->execute([$retry ? 'retry' : $state,$job['account_id']]);
        }
        if (!$retry) $alerts->queue($job, $code);
    }
}
