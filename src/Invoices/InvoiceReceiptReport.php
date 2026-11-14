<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/I18n/InvoiceText.php';

/** Explicit projection: the operator report never contains file identities or original links. */
final class InvoiceReceiptReport
{
    /** Read-compatible migration; callers persist it with their next normal write. */
    public static function withProcesses(array $s): array
    {
        $s+=['documents'=>[],'requested'=>false,'error'=>'','processes'=>[]];
        if (!$s['processes'] && ($s['documents'] || !empty($s['requested_at']))) {
            $s['active_process']='legacy';
            $s['processes']['legacy']=['id'=>'legacy','requested_at'=>$s['requested_at']??'',
                'year'=>$s['year']??0,'months'=>$s['months']??[],'error'=>$s['documents']?'':$s['error'],
                'missing_periods'=>$s['missing_periods']??[]];
        }
        foreach ($s['documents'] as &$d) $d['process_id']??=$s['active_process']??'legacy';
        unset($d);
        return $s;
    }
    public static function resolved(array $d): bool
    {
        if (!empty($d['error']) || ($d['stage']??'')==='failed') return false;
        if (($d['toconline_state']??'')==='toc_accepted') return ($d['stage']??'')==='archived';
        return in_array($d['toconline_state']??'',['toc_existing','toc_duplicate_local'],true)
            && ($d['stage']??'')==='duplicate_trashed';
    }
    public static function documents(array $s,string $process,string $filter='all'): array
    {
        return array_filter($s['documents']??[],static function($d)use($process,$filter){
            if (($d['stage']??'')==='superseded' || ($d['process_id']??'legacy')!==$process) return false;
            if ($filter==='all') return true;
            if (!empty($d['children'])) return $filter==='problems' && (!empty($d['error']) || ($d['stage']??'')==='failed');
            return $filter==='success'?self::resolved($d):!self::resolved($d);
        });
    }
    public static function processPending(array $s,string $id): bool
    {
        $p=$s['processes'][$id]??[];
        if (!empty($p['error']) || !empty($p['missing_periods']) || (!empty($s['requested']) && ($s['active_process']??'')===$id)) return true;
        foreach (self::documents($s,$id) as $d) {
            if (!empty($d['children'])) {
                if (!in_array($d['stage']??'',['source_processed','split_completed'],true)) return true;
            } elseif (!self::resolved($d)) return true;
        }
        return false;
    }
    public static function selected(array $s,string $id): array
    {
        $p=$s['processes'][$id]??[];
        return array_merge($s,$p,['documents'=>self::documents($s,$id),
            'requested'=>!empty($s['requested']) && ($s['active_process']??'')===$id]);
    }

    /** Waiting for human/provider resolution is pending, not an active execution. */
    public static function canStart(array $s,bool $sendEnabled=true): bool
    {
        $progress=self::progress($s,$sendEnabled);
        return !$progress['busy'] && !$progress['next_at'];
    }

    public static function summary(array $documents): array
    {
        $report=['files'=>0,'pdf_files'=>0,'jpg_files'=>0,'a4'=>0,'small'=>0,'documents'=>0,'processed'=>0,'pending'=>0,'duplicates'=>[],'errors'=>[]];
        foreach ($documents as $document) {
            if (($document['stage']??'')==='superseded') continue;
            if (empty($document['source_id'])) {
                $report['files']++;
                if (($document['format']??'')==='pdf') $report['pdf_files']++;
                elseif (($document['format']??'')==='jpg') $report['jpg_files']++;
            }
            $master=!empty($document['children']);
            if (!$master) {
                $report['documents']++;
                if (($document['format']??'')==='jpg') $report['small']++; else $report['a4']++;
                if (self::resolved($document)) $report['processed']++; else $report['pending']++;
                if (in_array($document['toconline_state']??'',['toc_existing','toc_duplicate_local'],true)) {
                    $report['duplicates'][]=[
                        'issuer_name'=>(string)($document['issuer_name']??''),
                        'invoice_number'=>(string)($document['invoice_number']??''),
                        'issued_on'=>(string)($document['issued_on']??''),
                    ];
                }
            }
            $error=(string)($document['error']??'');
            if ($error==='' && in_array($document['toconline_state']??'', ['toc_rejected','toc_review'],true)) $error=$document['toconline_state'];
            if ($error==='' && ($document['stage']??'')==='failed') $error='receipt_failed';
            if ($error!=='') {
                // Same translated diagnostic as the detailed report; never raw provider messages/OCR.
                $report['errors'][]=isset(InvoiceText::TEXT[$error])?$error:'receipt_failed';
            }
        }
        return $report;
    }

    public static function progress(array $state,bool $sendEnabled=true): array
    {
        $busy=!empty($state['requested']);$failed=!empty($state['error']);$pending=false;$next=0;
        foreach ($state['documents']??[] as $d) {
            $stage=$d['stage']??'';if ($stage==='superseded') continue;
            if ($stage==='failed') $failed=true;
            if (in_array($stage,['repairing','queued','reading','ready','moving','preserving','trashing','source_trashing','source_moving'],true)
                || ($stage==='archived' && (in_array($d['toconline_state']??'',['toc_existing','toc_duplicate_local'],true) || ($sendEnabled && empty($d['duplicate_keeper']) && in_array($d['toconline_state']??'',['pending','toc_retry'],true))))) {
                $at=(int)($d['next_attempt']??0);
                if ($at>time()) $next=$next?min($next,$at):$at; else $busy=true;
            }
            if ($stage==='split' && empty($state['source_cleanup_paused'])) {
                $ready=!empty($d['children']);foreach ($d['children']??[] as $child) {
                    $c=$state['documents'][$child]??[];
                    if (!self::resolved($c)) $ready=false;
                }
                if ($ready) $busy=true;
            }
            if (empty($d['children']) && !self::resolved($d)) $pending=true;
            if (!empty($d['children']) && !in_array($stage,['source_processed','split_completed'],true)) $pending=true;
        }
        if (!empty($state['missing_periods'])) $pending=true;
        $key=$busy?'receipt_processing':($next?'receipt_retry_wait':($failed?'receipt_interrupted':($pending?'receipt_finished_pending':'receipt_finished')));
        if ($busy && !empty($state['worker_seen_at']) && time()-(int)$state['worker_seen_at']>600) $key='receipt_worker_delayed';
        return ['state'=>$key,'next_at'=>$next,'server_time'=>time(),'busy'=>$busy];
    }

    /** Presentation only: never write formatted dates back to the workflow. */
    public static function displayDate(string $value,bool $folder=false): string
    {
        $pattern=$folder?'!Y-m':'!Y-m-d';
        $date=DateTimeImmutable::createFromFormat($pattern,$value,new DateTimeZone('Europe/Lisbon'));
        if (!$date || $date->format($folder?'Y-m':'Y-m-d')!==$value) return $value;
        $pt=[1=>'Janeiro','Fevereiro','Março','Abril','Maio','Junho','Julho','Agosto','Setembro','Outubro','Novembro','Dezembro'];
        return $date->format($folder?'m-Y':'d-m-Y').' ('.Translator::localized($pt[(int)$date->format('n')],$date->format('F')).')';
    }
    public static function render(array $report,bool $errorDetails=true): string
    {
        $e=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        $t=static fn($key)=>htmlspecialchars(InvoiceText::get($key),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        ob_start();
        ?>
<section class="card"><h2><?= $t('receipt_report') ?></h2>
<p class="receipt-totals">
<span><?= $t('receipt_files') ?>: <strong><?= $report['pdf_files'] ?></strong> pdf <?= $t('receipt_and') ?> <strong><?= $report['jpg_files'] ?></strong> jpg</span> <strong aria-hidden="true">|</strong>
<span><?= $t('receipt_a4') ?>: <strong><?= $report['a4'] ?></strong> - <?= $t('receipt_small') ?>: <strong><?= $report['small'] ?></strong> (<?= $t('receipt_docs') ?>: <strong><?= $report['documents'] ?></strong>)</span> <strong aria-hidden="true">|</strong>
<span><?= $t('receipt_processed') ?> <strong><?= $report['processed'] ?></strong></span> <strong aria-hidden="true">|</strong>
<span><?= $t('receipt_pending') ?>: <strong><?= $report['pending'] ?></strong></span> <strong aria-hidden="true">|</strong>
<span class="<?= $report['errors']?'receipt-error-count':'' ?>"><?= $t('receipt_errors') ?>: <strong><?= count($report['errors']) ?></strong></span>
</p><p><?= $t('receipt_processed_note') ?></p>
<?php if ($report['duplicates']): ?>
<div class="receipt-table-wrap"><table class="receipt-duplicates"><thead><tr><th><?= $t('receipt_issuer') ?></th><th><?= $t('receipt_number') ?></th><th><?= $t('receipt_date') ?></th></tr></thead><tbody>
<?php foreach ($report['duplicates'] as $duplicate): ?><tr><td data-i18n-skip><?= $duplicate['issuer_name']!==''?$e($duplicate['issuer_name']):$t('receipt_unknown_issuer') ?></td><td data-i18n-skip><?= $duplicate['invoice_number']!==''?$e($duplicate['invoice_number']):$t('receipt_unknown_data') ?></td><td data-i18n-skip><?= $duplicate['issued_on']!==''?$e(self::displayDate($duplicate['issued_on'])):$t('receipt_unknown_data') ?></td></tr><?php endforeach; ?>
</tbody></table></div><?php endif; ?>
<?php if ($errorDetails && $report['errors']): ?><details class="document-disclosure"><summary><?= $t('receipt_error_details') ?></summary><div class="receipt-instructions"><?php foreach ($report['errors'] as $error): ?><p><?= $t($error) ?></p><?php endforeach; ?></div></details><?php endif; ?>
</section>
        <?php
        return (string)ob_get_clean();
    }
}
