<?php
declare(strict_types=1);

/**
 * Sends WhatsApp only when a NEW WHM disk incident appears in the private TXT.
 *
 * Uses the existing configured gerente recipient and WhatsAppCloudClient.
 * A dedicated Meta-approved template is required. Disabled by default until
 * approval; never reuse invoice/assignment templates for a storage warning.
 */
final class WhatsAppDiskHealthNotifier
{
    public const TEMPLATE = 'whm_storage_alert_v1';
    public const LANGUAGE = 'pt_PT';

    private const REPORT_PATTERN = '/^whm-disk-20[0-9]{2}-[0-9]{2}-[0-9]{2}\.txt$/D';
    private const STATE_NAME = 'whm-disk-whatsapp-state.json';

    private const QUOTA_LEVELS = [
        'quota90' => 90,
        'quota95' => 95,
        'quota98' => 98,
        'quota100' => 100,
    ];

    private const DETAILS = [
        'quota90' => 'Ocupacao do disco atingiu 90%',
        'quota95' => 'Ocupacao do disco atingiu 95%',
        'quota98' => 'Ocupacao do disco atingiu 98%',
        'quota100' => 'Ocupacao do disco atingiu 100%',
        'growth' => 'Crescimento anormal do disco',
        'logs_growth' => 'Crescimento anormal dos logs',
        'linux_browser_returned' => 'Foi instalado novamente um browser Linux',
        'maintenance_issue' => 'Falha na manutencao ou auditoria do servidor',
    ];
    private const ACTIONS = [
        'quota90' => 'Reveja a quota e elimine apenas ficheiros comprovadamente dispensaveis.',
        'quota95' => 'Prioridade alta: investigue a ocupacao e execute limpeza segura.',
        'quota98' => 'Urgente: verifique a quota e liberte espaco com seguranca.',
        'quota100' => 'Critico: quota cheia; atue apenas apos verificar as dependencias.',
        'growth' => 'Compare o relatorio TXT atual com o anterior.',
        'logs_growth' => 'Investigue o log ativo sem o apagar.',
        'linux_browser_returned' => 'Verifique dependencias antes de eliminar o browser.',
        'maintenance_issue' => 'Consulte a execucao no GitHub Actions.',
    ];

    public function __construct(
        private readonly ?PDO $pdo,
        private readonly array $config,
        private readonly string $privateDir
    ) {}

    /** @return array<string,string>|null */
    public static function parse(string $raw): ?array
    {
        if ($raw === '' || strlen($raw) > 8000) return null;
        $values = [];
        foreach (explode("\n", $raw) as $line) {
            if ($line === '') continue;
            $pos = strpos($line, '=');
            if ($pos === false) return null;
            $key = substr($line, 0, $pos);
            if (!preg_match('/\A[a-z][a-z0-9_]{0,60}\z/D', $key) || isset($values[$key])) {
                return null;
            }
            $values[$key] = substr($line, $pos + 1);
        }
        if (($values['schema'] ?? '') !== 'whm-disk-v1') return null;
        $date = $values['date_lisbon'] ?? '';
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$parsed || $parsed->format('Y-m-d') !== $date) return null;
        $status = $values['status'] ?? '';
        $code = $values['alert_code'] ?? '';
        if (!in_array($status, ['ok', 'warning', 'critical'], true)) return null;
        if ($status === 'ok' && $code !== 'none') return null;
        if ($status !== 'ok' && !isset(self::DETAILS[$code])) return null;
        if (!is_numeric($values['quota_percent'] ?? null)
            || (float) $values['quota_percent'] < 0
            || (float) $values['quota_percent'] > 100) return null;
        return ['date' => $date, 'status' => $status,
                'code' => $code, 'percent' => (string) $values['quota_percent']];
    }

    /** @return array<string,string>|null */
    private function latest(): ?array
    {
        if (!is_dir($this->privateDir)) return null;
        $matches = glob($this->privateDir . '/whm-disk-*.txt');
        if (!is_array($matches)) return null;
        $matches = array_filter($matches, static fn(string $path): bool =>
            preg_match(self::REPORT_PATTERN, basename($path)) === 1
            && is_file($path) && !is_link($path));
        rsort($matches, SORT_STRING);
        if (!$matches) return null;
        $contents = file_get_contents($matches[0], false, null, 0, 8001);
        if (!is_string($contents) || strlen($contents) > 8000) return null;
        $report = self::parse($contents);
        if ($report === null) return null;
        if (basename($matches[0]) !== 'whm-disk-' . $report['date'] . '.txt') return null;
        $today = new DateTimeImmutable('today', new DateTimeZone('Europe/Lisbon'));
        $age = (int) $today->diff(new DateTimeImmutable($report['date']))->format('%r%a');
        // Ignore future-dated or stale reports; do not send out-of-context alerts.
        return ($age > 0 || $age < -3) ? null : $report;
    }

    private function state(): array
    {
        $path = $this->privateDir . '/' . self::STATE_NAME;
        if (!file_exists($path)) return ['code' => 'none', 'state' => 'none', 'quota_highest' => 0];
        if (!is_file($path) || is_link($path) || filesize($path) > 4096) {
            throw new RuntimeException('disk_alert_state_invalid');
        }
        $raw = file_get_contents($path);
        $state = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($state) || !is_string($state['code'] ?? null)
            || !in_array($state['state'] ?? null,
                ['none', 'sending', 'sent', 'unconfirmed'], true)) {
            throw new RuntimeException('disk_alert_state_invalid');
        }
        // Existing files written before this upgrade contain no tier marker.
        // A previously notified quota90 already counts as 90, preventing a
        // duplicate when the new scheme becomes active.
        $highest = $state['quota_highest']
            ?? (self::QUOTA_LEVELS[$state['code']] ?? 0);
        if (!is_int($highest) || !in_array($highest, [0, 90, 95, 98, 100], true)) {
            throw new RuntimeException('disk_alert_state_invalid');
        }
        $state['quota_highest'] = $highest;
        return $state;
    }

    private function saveState(array $state): void
    {
        $path = $this->privateDir . '/' . self::STATE_NAME;
        $tmp = $path . '.tmp';
        $data = json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if (file_put_contents($tmp, $data, LOCK_EX) !== strlen($data)
            || !chmod($tmp, 0600) || !rename($tmp, $path)) {
            throw new RuntimeException('disk_alert_state_write_failed');
        }
    }

    private function configuredRecipient(): string
    {
        if (!$this->pdo) return '';
        // Reuse the already configured manager of invoice notifications.
        // This does not enable invoice notifications or select employees.
        $query = $this->pdo->query(
            "SELECT u.mobile FROM invoice_notification_settings s
             JOIN users u ON u.id=s.recipient_user_id
             WHERE s.id=1 AND u.role='gerente' AND u.is_active=1 LIMIT 1"
        );
        $mobile = $query ? (string) $query->fetchColumn() : '';
        return preg_match('/\A[+0-9 ]{8,20}\z/D', $mobile) ? $mobile : '';
    }

    /** Notify each increasing quota tier once during an occupancy episode.
     *  The episode resets only once the measured quota falls below 90%.
     *  Technical incidents retain their existing once-per-code behavior.
     */
    public function dispatch(?callable $sender = null, ?callable $recipientProvider = null): string
    {
        $setting = $this->config['disk_alert'] ?? [];
        if (!is_array($setting) || ($setting['enabled'] ?? false) !== true) {
            return 'disabled_until_meta_approval';
        }
        if (($setting['template_name'] ?? self::TEMPLATE) !== self::TEMPLATE) {
            return 'unapproved_template_name';
        }
        $report = $this->latest();
        if ($report === null) return 'no_fresh_report';
        $state = $this->state();

        $percent = (float) $report['percent'];
        if ($percent < 90 && $state['quota_highest'] > 0) {
            // Falling from e.g. 98% to 95% must not send a fresh 95% alert.
            // Only a later recovery below 90% starts a new quota episode.
            $state['quota_highest'] = 0;
            if (isset(self::QUOTA_LEVELS[$state['code']])) {
                $state['code'] = 'none';
                $state['state'] = 'none';
            }
            $this->saveState($state);
        }

        if ($report['code'] === 'none') {
            if ($state['code'] !== 'none' || $state['quota_highest'] !== 0) {
                $this->saveState(['code' => 'none', 'state' => 'none', 'quota_highest' => 0]);
            }
            return 'healthy_no_message';
        }

        $code = $report['code'];
        $tier = self::QUOTA_LEVELS[$code] ?? 0;
        if ($tier > 0) {
            if ($percent < $tier) return 'invalid_quota_tier';
            if ($tier <= $state['quota_highest']) return 'already_notified';
        } elseif ($state['code'] === $code) {
            return 'already_notified';
        }
        $mobile = $recipientProvider ? (string) $recipientProvider() : $this->configuredRecipient();
        if ($mobile === '') return 'recipient_not_configured';
        $values = [
            'WHM ' . $report['date'] . ' - ' . $report['percent'] . '%',
            self::DETAILS[$code],
            self::ACTIONS[$code],
        ];
        $next = [
            'code' => $code,
            'state' => 'sending',
            'date' => $report['date'],
            'quota_highest' => max($state['quota_highest'], $tier),
        ];
        // Persist BEFORE sending; ambiguous responses must not be retried
        // blindly, and lower thresholds may never be resent on a decrease.
        $this->saveState($next);
        try {
            if ($sender) {
                $id = $sender($mobile, $values, self::LANGUAGE, self::TEMPLATE);
            } else {
                $client = new WhatsAppCloudClient($this->config);
                $id = $client->sendTemplate($mobile, $values, self::LANGUAGE, self::TEMPLATE);
            }
            if (!is_string($id) || $id === '') throw new RuntimeException('delivery_unconfirmed');
            $next['state'] = 'sent';
            $this->saveState($next);
            return 'meta_accepted';
        } catch (Throwable) {
            $next['state'] = 'unconfirmed';
            $this->saveState($next);
            return 'delivery_unconfirmed';
        }
    }

}
