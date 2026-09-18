<?php
declare(strict_types=1);

require_once __DIR__ . '/InvoiceService.php';

final class InvoiceRunner
{
    public function __construct(private readonly PDO $pdo, private readonly array $config) {}

    /** Caller holds the MySQL advisory lock for this entire method. */
    public function run(): void
    {
        $service = new InvoiceService($this->pdo);
        $now = InvoiceService::utcNow();
        $this->pdo->prepare('UPDATE invoice_settings SET worker_seen_at = ? WHERE id = 1')->execute([$now]);
        // A previous process died: the global lock proves it is no longer processing jobs.
        $this->pdo->prepare("UPDATE invoice_jobs SET state = 'failed', result_code = 'interrupted', active_key = NULL, finished_at = ? WHERE state = 'running'")
            ->execute([$now]);
        $service->scheduleDue(new DateTimeImmutable('now'));
        $jobs = $this->pdo->query("SELECT * FROM invoice_jobs WHERE state = 'queued' ORDER BY id LIMIT 2")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($jobs as $job) {
            $this->pdo->prepare("UPDATE invoice_jobs SET state = 'running', started_at = ? WHERE id = ? AND state = 'queued'")
                ->execute([InvoiceService::utcNow(), $job['id']]);
            $state = 'failed';
            $code = 'worker_failed';
            $imported = 0;
            $duplicates = 0;
            try {
                $vault = new InvoiceVault((string) ($this->config['private_dir'] ?? ''));
                $input = ['action' => $job['kind'], 'property' => $job['property_id'], 'period' => $job['period'], 'privateDir' => $vault->root];
                if ($job['kind'] !== 'preflight') {
                    if (!$service->browserReady()) {
                        $check = $this->execute(['action' => 'preflight', 'privateDir' => $vault->root]);
                        $ready = ($check['code'] ?? '') === 'ok';
                        $this->pdo->prepare('UPDATE invoice_settings SET browser_ready = ?, browser_checked_at = ? WHERE id = 1')
                            ->execute([(int) $ready, InvoiceService::utcNow()]);
                        if (!$ready) throw new RuntimeException('preflight_required');
                    }
                    $input['credentials'] = $vault->read('booking-credentials.enc');
                    $input['session'] = $vault->has('booking-session.enc') ? $vault->read('booking-session.enc') : [];
                }
                $result = $this->execute($input);
                $code = (string) ($result['code'] ?? 'worker_failed');
                if (!in_array($code, ['ok', 'no_invoices', 'needs_auth', 'connector_unconfigured', 'portal_changed', 'browser_unavailable', 'network_error', 'document_limit'], true)) {
                    throw new RuntimeException('worker_failed');
                }
                if (isset($result['session']) && is_array($result['session']) && in_array($code, ['ok', 'no_invoices'], true)) {
                    $vault->save('booking-session.enc', $result['session']);
                }
                if ($job['kind'] === 'preflight') {
                    $this->pdo->prepare('UPDATE invoice_settings SET browser_ready = ?, browser_checked_at = ? WHERE id = 1')
                        ->execute([(int) ($code === 'ok'), InvoiceService::utcNow()]);
                }
                if ($code === 'ok' && $job['kind'] === 'collect') {
                    $documents = $result['documents'] ?? null;
                    if (!is_array($documents) || count($documents) < 1 || count($documents) > 100) {
                        throw new RuntimeException('invalid_document');
                    }
                    foreach ($documents as $document) {
                        if ($service->import($vault, $job, $document)) {
                            $imported++;
                        } else {
                            $duplicates++;
                        }
                        // Preserve partial progress even if a later document is rejected.
                        $this->pdo->prepare('UPDATE invoice_jobs SET imported_count = ?, duplicate_count = ? WHERE id = ?')
                            ->execute([$imported, $duplicates, $job['id']]);
                    }
                }
                if ($job['kind'] !== 'preflight' && in_array($code, ['ok', 'no_invoices'], true)) {
                    $this->pdo->prepare('UPDATE invoice_settings SET login_verified_at = ? WHERE id = 1')
                        ->execute([InvoiceService::utcNow()]);
                }
                $state = in_array($code, ['ok', 'no_invoices'], true) ? 'completed' : ($code === 'needs_auth' ? 'needs_auth' : 'failed');
            } catch (Throwable $e) {
                $allowed = ['private_storage_unavailable', 'private_storage_permissions', 'vault_key_unavailable',
                    'vault_read_failed', 'vault_write_failed', 'preflight_required', 'worker_timeout', 'worker_unavailable',
                    'invalid_document', 'invoice_conflict', 'connector_unconfigured'];
                $code = in_array($e->getMessage(), $allowed, true) ? $e->getMessage() : 'worker_failed';
                if ($job['kind'] === 'preflight') {
                    $this->pdo->prepare('UPDATE invoice_settings SET browser_ready = 0, browser_checked_at = ? WHERE id = 1')
                        ->execute([InvoiceService::utcNow()]);
                }
            }
            if ($code === 'needs_auth') {
                $this->pdo->exec('UPDATE invoice_settings SET enabled = 0, login_verified_at = NULL WHERE id = 1');
                $this->pdo->prepare("UPDATE invoice_jobs SET state = 'needs_auth', result_code = 'needs_auth', active_key = NULL, finished_at = ? WHERE state = 'queued' AND kind <> 'preflight'")
                    ->execute([InvoiceService::utcNow()]);
            }
            $this->pdo->prepare('UPDATE invoice_jobs SET state = ?, result_code = ?, active_key = NULL, finished_at = ?, imported_count = ?, duplicate_count = ? WHERE id = ?')
                ->execute([$state, $code, InvoiceService::utcNow(), $imported, $duplicates, $job['id']]);
            if ($code === 'needs_auth') break;
        }
    }

    private function execute(array $input): array
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
        }
    }
}
