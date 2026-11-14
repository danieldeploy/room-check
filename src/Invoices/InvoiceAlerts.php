<?php
declare(strict_types=1);
require_once __DIR__ . '/../Notifications/WhatsAppCloudClient.php';
require_once __DIR__.'/InvoiceVault.php';
require_once __DIR__.'/../I18n/InvoiceText.php';

final class InvoiceAlerts
{
    public const RECEIPT_TEMPLATE='receipt_process_summary_v1';
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

    /** One durable summary per stopped/blocked receipt cycle, never one alert per document. */
    public function receipts(InvoiceVault $vault,?callable $sender=null): void
    {
        if (!$vault->has('receipts-workflow.enc')) return;
        require_once __DIR__.'/InvoiceReceiptReport.php';
        $state=InvoiceReceiptReport::withProcesses($vault->read('receipts-workflow.enc'));
        $file='receipt-summaries.enc';$ledger=$vault->has($file)?$vault->read($file):['processes'=>[]];
        $toc=$vault->has('toconline-settings.enc')?$vault->read('toconline-settings.enc'):[];
        $sendEnabled=!empty($toc['enabled']) && !empty($toc['pilot_verified']);
        foreach ($state['processes'] as $pid=>$process) {
            $selected=InvoiceReceiptReport::selected($state,(string)$pid);
            $progress=InvoiceReceiptReport::progress($selected,$sendEnabled);
            if (!$progress['settled']) { $ledger['processes'][$pid]['seen_work']=true;continue; }
            $report=InvoiceReceiptReport::summary($selected['documents']);
            $codes=$report['errors'];$recovered=0;
            foreach ($selected['documents'] as $d) {
                if (($d['stage']??'')==='superseded') continue;
                if (($d['quota_outcome']??'')==='recovered') $codes[]='gemini_quota_recovered';
                if (($d['quota_outcome']??'')==='failed' && !empty($d['quota_reason'])) $codes[]=$d['quota_reason'];
                $recovered+=(int)($d['recovered_failures']??0);
                if (in_array($d['toconline_state']??'',['toc_submitted','toc_uncertain','toc_sending'],true)) $codes[]='toc_no_confirmation';
            }
            if (!empty($selected['error'])) $codes[]=$selected['error'];
            if (!empty($selected['missing_periods'])) $codes[]='receipt_missing_month';
            if ($progress['state']==='receipt_worker_delayed') $codes[]='receipt_worker_delayed';
            $codes=array_values(array_unique(array_map(static fn($code)=>isset(InvoiceText::TEXT[$code])?$code:'receipt_failed',$codes)));sort($codes);
            $summary=['state'=>$progress['state'],'processed'=>$report['processed'],'pending'=>$report['pending'],'total'=>$report['documents'],
                'reasons'=>$codes,'next_at'=>$progress['state']==='receipt_quota_wait'?$progress['next_at']:0,
                'cycle'=>(int)($process['notification_cycle']??0),'recovered'=>$recovered];
            $signature=hash('sha256',json_encode($summary,JSON_THROW_ON_ERROR));
            $old=$ledger['processes'][$pid]??[];
            if (($old['last']??'')===$signature) continue;
            // Installing the policy is not an event in every historical completed run.
            $baseline=!$old && !$summary['cycle'];
            foreach ($old['events']??[] as $key=>$event) if (($event['state_delivery']??'')==='pending') $old['events'][$key]['state_delivery']='superseded';
            $old['last']=$signature;
            if (!isset($old['events'][$signature])) $old['events'][$signature]=$summary+['state_delivery'=>$baseline?'baseline':'pending','created_at'=>gmdate('c')];
            $ledger['processes'][$pid]=$old;
        }
        $vault->save($file,$ledger);
        $settings=$this->pdo->query('SELECT * FROM invoice_notification_settings WHERE id=1')->fetch(PDO::FETCH_ASSOC);
        if (!$settings || empty($settings['enabled'])) return;
        $q=$this->pdo->prepare("SELECT mobile FROM users WHERE id=? AND role='gerente' AND is_active=1");$q->execute([$settings['recipient_user_id']]);$mobile=$q->fetchColumn();if (!$mobile) return;
        foreach ($ledger['processes'] as $pid=>$record) {
            $key=$record['last']??'';$entry=$record['events'][$key]??[];
            if (($entry['state_delivery']??'')==='sending') {
                $ledger['processes'][$pid]['events'][$key]['state_delivery']='uncertain';$vault->save($file,$ledger);continue;
            }
            if (($entry['state_delivery']??'')!=='pending') continue;
            // A resumed process must not dispatch a queued, already stale stopped-state summary.
            if (!InvoiceReceiptReport::progress(InvoiceReceiptReport::selected($state,(string)$pid),$sendEnabled)['settled']) continue;
            $values=self::receiptSummaryValues($state['processes'][$pid],(string)$pid,$entry);
            $ledger['processes'][$pid]['events'][$key]['state_delivery']='sending';$vault->save($file,$ledger);
            try {
                $message=$sender?$sender($mobile,$values,self::RECEIPT_TEMPLATE):(new WhatsAppCloudClient($this->config))->sendTemplate($mobile,$values,'pt_PT',self::RECEIPT_TEMPLATE);
                $ledger['processes'][$pid]['events'][$key]['state_delivery']='sent';
                $ledger['processes'][$pid]['events'][$key]['sent_at']=gmdate('c');
                $ledger['processes'][$pid]['events'][$key]['message_id']=$message;
            } catch (Throwable) { $ledger['processes'][$pid]['events'][$key]['state_delivery']='uncertain'; }
            $vault->save($file,$ledger);break;
        }
    }

    private static function receiptSummaryValues(array $process,string $pid,array $entry): array
    {
        $date=static fn(string $at)=>(new DateTimeImmutable($at))->setTimezone(new DateTimeZone('Europe/Lisbon'))->format('d-m-Y H:i');
        $period=implode(', ',array_map(static fn($m)=>sprintf('%02d-%04d',(int)$m,(int)($process['year']??0)),$process['months']??[]));
        $complete=$entry['state']==='receipt_finished';
        $label=$complete?'Concluído':($entry['state']==='receipt_quota_wait'?'Pausado — quota diária':'Pendente — intervenção necessária');
        // The dedicated summary template describes both success and blocked outcomes neutrally.
        $text=($complete?'Sem problemas. ':'').'Estado: '.$label.'. Tratados: '.$entry['processed'].'/'.$entry['total'].'. Pendentes: '.$entry['pending'].'.';
        if ($entry['recovered']) $text.=' Falhas temporárias recuperadas: '.$entry['recovered'].'.';
        foreach ($entry['reasons'] as $code) {
            $reason=InvoiceText::TEXT[$code][0];
            if (mb_strlen($text.' '.$reason)>620) { $text.=' Mais detalhes no Hub.';break; }
            $text.=' '.$reason;
        }
        $action=$complete?'Nenhuma ação necessária.':($entry['next_at']?'Nova tentativa em '.$date('@'.$entry['next_at']).'. Consulte as outras pendências no Hub.':'Consulte os motivos e resolva as pendências no Management Hub. Não reenvie documentos sem confirmação.');
        $when=!empty($process['requested_at'])?$date($process['requested_at']):$date($entry['created_at']);
        return ['Faturas A4 e pequenos talões','Processo '.$when.' / '.substr($pid,0,8),$period,$text,$action];
    }

    public static function reason(string $code): string
    {
        return match ($code) {
            'cloudbeds_mail_review' => 'Existem emails Cloudbeds cuja origem não foi confirmada por DKIM ou SPF. Apenas as faturas autenticadas foram tratadas.',
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
            'cloudbeds_mail_review' => 'Consulte os emails preservados na pasta Cloudbeds da conta indicada. Peça uma nova cópia ao fornecedor se faltar alguma fatura do período.',
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
