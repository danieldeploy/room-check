<?php
declare(strict_types=1);
require_once __DIR__ . '/../Notifications/WhatsAppCloudClient.php';

final class InvoiceAlerts
{
    public function __construct(private readonly PDO $pdo, private readonly array $config) {}

    public function queue(array $job, string $code): void
    {
        if ($job['kind'] === 'preflight') return;
        if ($job['kind'] === 'login' && $code !== 'human_verification') return;
        // A later login can hit a new human challenge in the same month. Deduplicate
        // retries of one task without suppressing the later task's notification.
        $key = $job['kind'] === 'login' && $code === 'human_verification'
            ? 'login:' . $job['id'] . ':human_verification'
            : $job['account_id'] . ':' . $job['period'] . ':' . $code;
        try {
            $this->pdo->prepare('INSERT INTO invoice_failure_alerts (task_id, dedupe_key, created_at) VALUES (?, ?, ?)')
                ->execute([$job['id'], $key, gmdate('Y-m-d H:i:s')]);
        } catch (PDOException $e) { if ($e->getCode() !== '23000') throw $e; }
    }

    public function dispatch(?callable $sender = null): void
    {
        $settings = $this->pdo->query('SELECT * FROM invoice_notification_settings WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
        if (!$settings || !(int) $settings['enabled']) return;
        $s = $this->pdo->prepare("SELECT id, mobile FROM users WHERE id = ? AND role = 'gerente' AND is_active = 1");
        $s->execute([$settings['recipient_user_id']]);
        $manager = $s->fetch(PDO::FETCH_ASSOC);
        if (!$manager || empty($manager['mobile']) || !preg_match('/\A[a-z0-9_]{1,100}\z/', $settings['template_name'])) return;
        $s = $this->pdo->prepare("SELECT n.id, n.attempts, t.period, t.result_code, a.portal, a.label FROM invoice_failure_alerts n
            JOIN invoice_tasks t ON t.id = n.task_id JOIN invoice_accounts a ON a.id = t.account_id
            WHERE n.state IN ('pending', 'retry') AND (n.next_attempt_at IS NULL OR n.next_attempt_at <= ?) ORDER BY n.id LIMIT 5");
        $s->execute([gmdate('Y-m-d H:i:s')]);
        $client = new WhatsAppCloudClient($this->config);
        $queued = $s->fetchAll(PDO::FETCH_ASSOC);
        $this->dispatchDrive($settings, $manager, $client, $sender);
        foreach ($queued as $alert) {
            try {
                $this->pdo->prepare("UPDATE invoice_failure_alerts SET state = 'sending', attempts = attempts + 1 WHERE id = ?")->execute([$alert['id']]);
                $values = [InvoiceAccounts::PORTALS[$alert['portal']], $alert['label'], $alert['period'], self::reason($alert['result_code'] ?? ''), self::action($alert['result_code'] ?? '')];
                $message = $sender ? $sender($manager['mobile'], $values, $settings['template_name'])
                    : $client->sendTemplate($manager['mobile'], $values, 'pt_PT', $settings['template_name']);
                $this->pdo->prepare("UPDATE invoice_failure_alerts SET state = 'sent', sent_at = ?, meta_message_id = ?, result_code = NULL WHERE id = ?")
                    ->execute([gmdate('Y-m-d H:i:s'), $message, $alert['id']]);
            } catch (Throwable) {
                // Never persist raw provider errors, recipient numbers or credentials.
                $attempt = (int) $alert['attempts'] + 1;
                $this->pdo->prepare('UPDATE invoice_failure_alerts SET state = ?, next_attempt_at = ?, result_code = ? WHERE id = ?')
                    ->execute([$attempt < 3 ? 'retry' : 'failed', gmdate('Y-m-d H:i:s', time() + $attempt * 600), 'delivery_unconfirmed', $alert['id']]);
            }
        }
    }

    public static function reason(string $code): string
    {
        return match ($code) {
            'drive_auth' => 'A ligação ao Google Drive expirou.',
            'drive_quota' => 'O Google Drive está sem espaço.',
            'drive_not_configured', 'drive_invalid_id' => 'O destino Google Drive não está configurado.',
            'drive_account_mismatch' => 'A conta ou pasta Drive não corresponde ao destino autorizado.',
            'drive_permission', 'drive_not_found' => 'A pasta Drive está indisponível ou sem permissão.',
            'drive_verify' => 'Não foi possível confirmar a integridade do ficheiro no Drive.',
            'drive_local_missing' => 'A cópia local não está disponível ou não passou a verificação.',
            'human_verification' => 'O Booking pediu uma verificação humana para iniciar sessão.',
            'needs_auth', 'auth_invalid', 'auth_timeout' => 'A autenticação na plataforma não terminou.',
            'browser_unavailable' => 'O Chrome da recolha está indisponível.',
            'connector_unconfigured', 'portal_changed' => 'O procedimento da plataforma requer configuração.',
            default => 'O processamento não terminou com sucesso.'
        };
    }
    public static function action(string $code): string
    {
        return match ($code) {
            'drive_auth' => 'No módulo Faturas e Portais, volte a ligar a conta Google Drive e clique em Tentar novamente.',
            'drive_quota' => 'Liberte espaço no Google Drive e clique em Tentar novamente no módulo.',
            'drive_not_configured', 'drive_invalid_id', 'drive_account_mismatch', 'drive_permission', 'drive_not_found' => 'Verifique a ligação e a pasta da conta daniel.ciorcas@welcomehostel.pt e clique em Tentar novamente.',
            'drive_local_missing', 'drive_verify' => 'Peça a verificação dos ficheiros ao suporte antes de repetir. Não elimine a cópia do servidor.',
            'human_verification' => 'No Chrome de login controlado, conclua apenas a verificação humana na aba do Booking. Depois clique em Testar acesso à conta no Management Hub; o agente trata das credenciais e do SMS.',
            'needs_auth', 'auth_invalid', 'auth_timeout' => 'Verifique o acesso e o método 2FA da conta no módulo e teste novamente.',
            'browser_unavailable' => 'Verifique o Chrome no computador de recolha e volte a testar o acesso.',
            'connector_unconfigured', 'portal_changed' => 'Peça ao suporte para validar o procedimento de acesso e recolha desta conta.',
            default => 'Abra Faturas e Portais, consulte os documentos pendentes e clique em Tentar novamente após verificar a ligação.'
        };
    }
    private function dispatchDrive(array $settings, array $manager, WhatsAppCloudClient $client, ?callable $sender): void
    {
        $s = $this->pdo->prepare("SELECT n.*, a.portal, a.label FROM invoice_drive_alerts n JOIN invoice_accounts a ON a.id=n.account_id
            WHERE n.state IN ('pending','retry') AND (n.next_attempt_at IS NULL OR n.next_attempt_at<=?) ORDER BY n.id LIMIT 5");
        $s->execute([gmdate('Y-m-d H:i:s')]);
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $alert) {
            $attempt = (int) $alert['attempts'] + 1;
            try {
                $this->pdo->prepare("UPDATE invoice_drive_alerts SET state='sending', attempts=? WHERE id=?")->execute([$attempt,$alert['id']]);
                $q = $this->pdo->prepare("SELECT d.invoice_number FROM invoice_documents d JOIN invoice_document_delivery x ON x.document_id=d.id
                    WHERE d.account_id=? AND d.period=? AND x.drive_state='failed' ORDER BY d.id LIMIT 5");
                $q->execute([$alert['account_id'], $alert['period']]);
                $numbers = $q->fetchAll(PDO::FETCH_COLUMN);
                if (!$numbers) { $this->pdo->prepare("UPDATE invoice_drive_alerts SET state='resolved' WHERE id=?")->execute([$alert['id']]); continue; }
                $values = [InvoiceAccounts::PORTALS[$alert['portal']], $alert['label'], $alert['period'],
                    'Drive: 8 novas tentativas esgotadas. Documentos: ' . implode(', ', $numbers) . '. ' . self::reason($alert['error_code']), self::action($alert['error_code'])];
                if ($sender) $sender($manager['mobile'], $values, $settings['template_name']);
                else $client->sendTemplate($manager['mobile'], $values, 'pt_PT', $settings['template_name']);
                $this->pdo->prepare("UPDATE invoice_drive_alerts SET state='sent', sent_at=? WHERE id=?")->execute([gmdate('Y-m-d H:i:s'),$alert['id']]);
            } catch (Throwable) {
                $this->pdo->prepare('UPDATE invoice_drive_alerts SET state=?,next_attempt_at=? WHERE id=?')
                    ->execute([$attempt<3?'retry':'failed',gmdate('Y-m-d H:i:s',time()+$attempt*600),$alert['id']]);
            }
        }
    }
}
