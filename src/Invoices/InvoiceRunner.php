<?php
declare(strict_types=1);
require_once __DIR__ . '/InvoiceService.php';
require_once __DIR__ . '/InvoiceAuth.php';
require_once __DIR__ . '/InvoiceAlerts.php';
require_once __DIR__ . '/InvoiceDrive.php';

final class InvoiceRunner
{
    public function __construct(private readonly PDO $pdo, private readonly array $config) {}

    /** Caller holds the advisory lock. Individual account failures never break the queue. */
    public function run(): void
    {
        $service = new InvoiceService($this->pdo);
        $accounts = new InvoiceAccounts($this->pdo);
        $alerts = new InvoiceAlerts($this->pdo, $this->config['whatsapp'] ?? []);
        $now = InvoiceService::utcNow();
        $this->pdo->prepare('UPDATE invoice_settings SET worker_seen_at = ? WHERE id = 1')->execute([$now]);
        $stale = $this->pdo->query("SELECT * FROM invoice_tasks WHERE state IN ('running', 'waiting_auth')")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($stale as $job) $this->finishFailure($job, 'interrupted', $alerts);
        $this->pdo->exec("UPDATE invoice_failure_alerts SET state = 'failed', result_code = 'delivery_unconfirmed' WHERE state = 'sending'");
        $this->pdo->exec("UPDATE invoice_drive_alerts SET state='failed' WHERE state='sending'");
        try { $this->archive(); } catch (Throwable) {}
        $service->scheduleDue(new DateTimeImmutable('now'));
        $s = $this->pdo->prepare("SELECT * FROM invoice_tasks WHERE state IN ('queued', 'retry') AND (next_attempt_at IS NULL OR next_attempt_at <= ?) ORDER BY id LIMIT 20");
        $s->execute([$now]);
        // Round-robin across accounts: one account cannot monopolize a cron invocation.
        $queues = [];
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $job) $queues[$job['account_id']][] = $job;
        $jobs = [];
        while ($queues) foreach ($queues as $id => &$queue) {
            $jobs[] = array_shift($queue);
            if (!$queue) unset($queues[$id]);
        }
        unset($queue);
        foreach ($jobs as $job) {
            $vault = null; $exchange = null;
            $job['attempts'] = (int) $job['attempts'] + 1;
            $this->pdo->prepare("UPDATE invoice_tasks SET state = 'running', started_at = ?, attempts = ? WHERE id = ?")
                ->execute([InvoiceService::utcNow(), $job['attempts'], $job['id']]);
            try {
                $account = $accounts->get((int) $job['account_id']);
                if ($job['kind'] !== 'preflight' && (!$accounts->active((int)$account['id'])
                    || !isset($accounts->collectionProperties((int)$account['id'])[$job['property_id']]))) {
                    $this->pdo->prepare("UPDATE invoice_tasks SET state='cancelled',result_code='account_inactive',active_key=NULL,finished_at=? WHERE id=?")
                        ->execute([InvoiceService::utcNow(),$job['id']]);
                    continue;
                }
                $vault = new InvoiceVault((string) ($this->config['private_dir'] ?? ''));
                $input = ['action' => $job['kind'], 'accountId' => (int) $account['id'], 'portal' => $account['portal'],
                    'property' => $job['property_id'], 'period' => $job['period'], 'periodBasis' => $account['period_basis'], 'privateDir' => $vault->root];
                if ($job['kind'] !== 'preflight') {
                    if ($account['portal'] !== 'email' && !$service->browserReady()) {
                        $check = $this->execute(['action' => 'preflight', 'privateDir' => $vault->root]);
                        $ready = ($check['code'] ?? '') === 'ok';
                        $this->pdo->prepare('UPDATE invoice_settings SET browser_ready = ?, browser_checked_at = ? WHERE id = 1')
                            ->execute([(int) $ready, InvoiceService::utcNow()]);
                        if (!$ready) throw new RuntimeException('browser_unavailable');
                    }
                    $input['credentials'] = $accounts->credentials($vault, (int) $account['id']);
                    $input['authMethod'] = $account['auth_method'];
                    $sessionName = InvoiceAccounts::secretName((int) $account['id'], 'session');
                    $input['session'] = $vault->has($sessionName) ? $vault->read($sessionName) : [];
                    $exchange = $vault->path('exchange-' . bin2hex(random_bytes(16)));
                    if (!mkdir($exchange, 0700)) throw new RuntimeException('private_storage_unavailable');
                    $input['exchangeDir'] = $exchange;
                }
                $result = $this->execute($input, $job, $vault);
                $code = (string) ($result['code'] ?? 'worker_failed');
                if (!in_array($code, ['ok', 'no_invoices'], true)) throw new RuntimeException($code);
                if ($job['kind'] === 'preflight') {
                    $this->pdo->prepare('UPDATE invoice_settings SET browser_ready = 1, browser_checked_at = ? WHERE id = 1')->execute([InvoiceService::utcNow()]);
                } else {
                    if (isset($result['session']) && is_array($result['session'])) $vault->save($sessionName, $result['session']);
                    if ($job['kind'] === 'collect') {
                        $documents = $result['documents'] ?? null;
                        if (!is_array($documents) || count($documents) > 100 || ($code === 'ok' && !$documents) || ($code === 'no_invoices' && $documents)) throw new RuntimeException('invalid_document');
                        foreach ($documents as $document) {
                            $column = $service->import($vault, $job, $document) ? 'imported_count' : 'duplicate_count';
                            $this->pdo->prepare("UPDATE invoice_tasks SET $column = $column + 1 WHERE id = ?")->execute([$job['id']]);
                        }
                    }
                    $this->pdo->prepare("UPDATE invoice_accounts SET status = 'ready', login_verified_at = ? WHERE id = ?")
                        ->execute([InvoiceService::utcNow(), $account['id']]);
                }
                $this->pdo->prepare("UPDATE invoice_tasks SET state = 'completed', result_code = ?, active_key = NULL, finished_at = ? WHERE id = ?")
                    ->execute([$code, InvoiceService::utcNow(), $job['id']]);
            } catch (Throwable $e) {
                $allowed = ['private_storage_unavailable', 'private_storage_permissions', 'vault_key_unavailable', 'vault_read_failed',
                    'vault_write_failed', 'worker_timeout', 'worker_unavailable', 'invalid_document', 'invoice_conflict',
                    'connector_unconfigured', 'browser_unavailable', 'network_error', 'needs_auth', 'auth_unconfigured',
                    'auth_timeout', 'auth_invalid', 'portal_changed', 'document_limit', 'account_mismatch'];
                $code = in_array($e->getMessage(), $allowed, true) ? $e->getMessage() : 'worker_failed';
                if ($job['kind'] === 'preflight') $this->pdo->prepare('UPDATE invoice_settings SET browser_ready = 0, browser_checked_at = ? WHERE id = 1')->execute([InvoiceService::utcNow()]);
                $this->finishFailure($job, $code, $alerts);
            } finally {
                if ($vault) (new InvoiceAuth($this->pdo, $vault))->expire((int) $job['id']);
                if ($exchange && is_dir($exchange)) {
                    foreach (glob($exchange . '/*') ?: [] as $file) if (is_file($file)) unlink($file);
                    rmdir($exchange);
                }
            }
            try { $this->archive(); } catch (Throwable) {}
            // Notification transport is independent of the invoice queue.
            try { $alerts->dispatch(); } catch (Throwable) {}
        }
        try { $alerts->dispatch(); } catch (Throwable) {}
    }

    private function archive(): void
    {
        $vault = new InvoiceVault((string) ($this->config['private_dir'] ?? ''));
        (new InvoiceDrive($this->pdo, $vault, new InvoiceDriveClient($vault)))->run();
    }

    private function finishFailure(array $job, string $code, InvoiceAlerts $alerts): void
    {
        $retry = (int) $job['attempts'] < 2 && in_array($code,
            ['auth_timeout', 'auth_invalid', 'network_error', 'worker_timeout', 'worker_unavailable', 'browser_unavailable', 'interrupted'], true);
        $state = $retry ? 'retry' : (in_array($code, ['needs_auth', 'auth_timeout', 'auth_invalid'], true) ? 'needs_auth' : 'failed');
        $this->pdo->prepare('UPDATE invoice_tasks SET state = ?, result_code = ?, active_key = ?, next_attempt_at = ?, finished_at = ? WHERE id = ?')
            ->execute([$state, $code, $retry ? $job['active_key'] : null, $retry ? gmdate('Y-m-d H:i:s', time() + 120) : null,
                $retry ? null : InvoiceService::utcNow(), $job['id']]);
        if ($job['kind'] !== 'preflight') {
            $this->pdo->prepare('UPDATE invoice_accounts SET status = ? WHERE id = ?')->execute([$retry ? 'retry' : $state, $job['account_id']]);
        }
        if (!$retry) $alerts->queue($job, $code);
    }

    private function execute(array $input, ?array $job = null, ?InvoiceVault $vault = null): array
    {
        $node = (string) ($this->config['node_binary'] ?? '');
        $script = (string) ($this->config['runner_script'] ?? '');
        if (!function_exists('proc_open') || $node === '' || $node[0] !== '/' || !is_executable($node)
            || !is_file($script) || str_contains((string) realpath($script), '/public_html/')) {
            throw new RuntimeException('worker_unavailable');
        }
        // No shell, no secrets in arguments or environment, and no browser debug logs.
        $process = proc_open([$node, $script], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'a']], $pipes, dirname($script));
        if (!is_resource($process)) {
            throw new RuntimeException('worker_unavailable');
        }
        $challengeId = null;
        $exchange = $input['exchangeDir'] ?? null;
        $auth = $job && $vault ? new InvoiceAuth($this->pdo, $vault) : null;
        try {
            $payload = json_encode($input, JSON_THROW_ON_ERROR);
            while ($payload !== '') {
                $written = fwrite($pipes[0], $payload);
                if ($written === false || $written === 0) {
                    throw new RuntimeException('worker_failed');
                }
                $payload = substr($payload, $written);
            }
            fclose($pipes[0]);
            stream_set_blocking($pipes[1], false);
            $output = '';
            $deadline = microtime(true) + 650;
            do {
                if ($auth && $exchange && is_file($exchange . '/challenge.json')) {
                    $request = json_decode((string) file_get_contents($exchange . '/challenge.json'), true);
                    if (is_array($request) && ($request['id'] ?? null) !== $challengeId) {
                        $auth->register($job, $request);
                        $challengeId = $request['id'];
                        InvoiceVault::atomicWrite($exchange . '/ready-' . $challengeId, 'ready');
                    }
                    if ($challengeId && ($code = $auth->consume($challengeId, (int) $job['id'])) !== null) {
                        InvoiceVault::atomicWrite($exchange . '/response-' . $challengeId . '.json', json_encode(['value' => $code], JSON_THROW_ON_ERROR));
                        unset($code);
                    }
                }
                $output .= stream_get_contents($pipes[1]);
                if (strlen($output) > 40 * 1024 * 1024 || microtime(true) > $deadline) {
                    throw new RuntimeException('worker_timeout');
                }
                $status = proc_get_status($process);
                if (!$status['running']) {
                    $output .= stream_get_contents($pipes[1]);
                    break;
                }
                usleep(100000);
            } while (true);
            $result = json_decode($output, true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($result)) {
                throw new RuntimeException('worker_failed');
            }
            return $result;
        } finally {
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            if (proc_get_status($process)['running']) {
                proc_terminate($process);
                $until = microtime(true) + 5;
                while (proc_get_status($process)['running'] && microtime(true) < $until) {
                    usleep(100000);
                }
                if (proc_get_status($process)['running']) {
                    proc_terminate($process, 9);
                }
            }
            proc_close($process);
            if ($auth) $auth->expire((int) $job['id']);
        }
    }
}
