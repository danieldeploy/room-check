<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/I18n/InvoiceText.php';
require_once __DIR__.'/InvoiceReceiptAnalysis.php';

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
        if (($d['stage']??'')==='discarded') return !empty($d['discarded_at']) && !empty($d['discard_requested_by']) && ($d['toconline_state']??'')==='receipt_not_submitted';
        if (($d['toconline_state']??'')==='toc_accepted') return ($d['stage']??'')==='archived';
        return in_array($d['toconline_state']??'',['toc_existing','toc_duplicate_local'],true)
            && ($d['stage']??'')==='duplicate_trashed';
    }
    public static function paperMonthMismatch(array $d): bool
    {
        $period=(string)($d['expected_period']??'');$issued=(string)($d['issued_on']??'');
        $date=DateTimeImmutable::createFromFormat('!Y-m-d',$issued,new DateTimeZone('Europe/Lisbon'));
        return ($d['stage']??'')==='archived' && ($d['toconline_state']??'')==='toc_accepted'
            && self::resolved($d) && empty($d['children']) && $date && $date->format('Y-m-d')===$issued
            && preg_match('/\A[0-9]{4}-(?:0[1-9]|1[0-2])\z/',$period)===1 && substr($issued,0,7)!==$period;
    }
    /** Preview only the recorded final copy; removed duplicates need an explicit equivalent keeper. */
    public static function previewId(array $documents,string $id): ?string
    {
        $d=$documents[$id]??[];
        if (!in_array($d['format']??'', ['pdf','jpg'],true)) return null;
        if (($d['stage']??'')==='duplicate_trashed') {
            $keeperId=(string)($d['duplicate_keeper']??'');$keeper=$documents[$keeperId]??[];
            $key=InvoiceReceiptAnalysis::duplicateKey($d);
            return $keeperId!==$id && $key!==null && InvoiceReceiptAnalysis::duplicateKey($keeper)===$key
                && ($keeper['stage']??'')==='archived' && ($keeper['toconline_state']??'')==='toc_accepted'
                && !empty($keeper['archived_at']) && empty($keeper['error']) ? $keeperId : null;
        }
        return in_array($d['stage']??'', ['split_completed','discarding','discarded','superseded'],true)?null:$id;
    }
    public static function documents(array $s,string $process,string $filter='all'): array
    {
        return array_filter($s['documents']??[],static function($d)use($process,$filter){
            if (($d['stage']??'')==='superseded' || ($d['process_id']??'legacy')!==$process) return false;
            if ($filter==='all') return true;
            if (!empty($d['children'])) return $filter==='problems' && (!empty($d['error']) || ($d['stage']??'')==='failed');
            return $filter==='success'?(self::resolved($d) && ($d['stage']??'')!=='discarded'):!self::resolved($d);
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
        $selected=array_merge($s,$p,['documents'=>self::documents($s,$id),
            'requested'=>!empty($s['requested']) && ($s['active_process']??'')===$id]);
        if (isset($selected['worker_retry'])) {
            $shared=$selected['worker_retry'];
            unset($selected['worker_retry']);
            $work=self::progress($selected);
            if ($work['busy'] || $work['next_at'] || $selected['requested']) $selected['worker_retry']=$shared;
        }
        return $selected;
    }

    /** Automatic retries and live TOConline confirmation waits also reserve the intake. */
    public static function canStart(array $s,bool $sendEnabled=true): bool
    {
        $progress=self::progress($s,$sendEnabled);
        return !$progress['automatic'];
    }
    /** Only predefined categories and numeric transport data can leave private storage. */
    public static function failureFacts(array $d): array
    {
        $f=$d['failure_facts']??[];
        if (!in_array($f['phase']??'',['receipt_gemini_analysis','receipt_gemini_classification','receipt_drive_request'],true)
            || !in_array($f['reason']??'',['gemini_http','gemini_network','gemini_timeout','gemini_response_size','gemini_schema','gemini_json','gemini_finish','drive_transfer_error','drive_http_error'],true)) return [];
        $safe=['phase'=>$f['phase'],'reason'=>$f['reason']];
        foreach (['http_status','curl_errno','duration_ms'] as $key) $safe[$key]=max(0,(int)($f[$key]??0));
        if (in_array($f['finish']??'',['MAX_TOKENS','SAFETY','RECITATION','BLOCKLIST','PROHIBITED_CONTENT','SPII','OTHER','UNKNOWN'],true)) $safe['finish']=$f['finish'];
        return $safe;
    }
    public static function canStartNewRun(array $s,bool $sendEnabled=true): bool
    {
        // Discovery owns a single cursor. After discovery, request() checks month ownership.
        return empty($s['requested']);
    }
    public static function processState(array $s,string $id,bool $sendEnabled=true): string
    {
        $p=self::progress(self::selected($s,$id),$sendEnabled);
        if ($p['automatic'] && $p['state']!=='receipt_worker_delayed') return 'receipt_run_running';
        return self::processPending($s,$id)?'receipt_pending':'receipt_finished';
    }
    /** Filter by execution start in Lisbon, never by scanned folder or invoice date. */
    public static function processesForPeriod(array $s,int $year,int $month): array
    {
        return array_filter($s['processes']??[],static function(array $p)use($year,$month):bool {
            if (!$year && !$month) return true;
            if (empty($p['requested_at'])) return false;
            try {$at=(new DateTimeImmutable($p['requested_at']))->setTimezone(new DateTimeZone('Europe/Lisbon'));}
            catch (Exception) {return false;}
            return (!$year || (int)$at->format('Y')===$year) && (!$month || (int)$at->format('n')===$month);
        });
    }

    public static function summary(array $documents): array
    {
        $report=['files'=>0,'pdf_files'=>0,'jpg_files'=>0,'a4'=>0,'small'=>0,'documents'=>0,'processed'=>0,'pending'=>0,'discarded'=>0,'duplicates'=>[],'errors'=>[]];
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
                if (($document['format']??'')==='jpg' || ($document['source_category']??'')==='taloes') $report['small']++; else $report['a4']++;
                if (($document['stage']??'')==='discarded' && self::resolved($document)) $report['discarded']++;
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

    public static function progress(array $state,bool $sendEnabled=true,?int $now=null): array
    {
        $now??=time();$busy=false;$failed=!empty($state['error']);$pending=false;$next=0;$shortRetry=false;$replyPoll=0;
        $wait=static function(int $at,bool $quota=false)use(&$next,&$shortRetry):void {
            $next=$next?min($next,$at):$at;if (!$quota) $shortRetry=true;
        };
        if (!empty($state['requested'])) {
            $at=(int)($state['discovery_retry']['next_attempt']??0);
            if ($at>$now) $wait($at);else $busy=true;
        }
        foreach ($state['documents']??[] as $d) {
            $stage=$d['stage']??'';if ($stage==='superseded') continue;
            if ($stage==='failed') $failed=true;
            $cleanup=in_array($stage,['source_trashing','source_moving'],true) && empty($state['source_cleanup_paused']);
            if ($cleanup || in_array($stage,['correcting','repairing','queued','reading','ready','moving','preserving','trashing','discarding'],true)
                || ($stage==='archived' && (in_array($d['toconline_state']??'',['toc_existing','toc_duplicate_local'],true) || ($sendEnabled && empty($d['duplicate_keeper']) && in_array($d['toconline_state']??'',['pending','toc_retry'],true))))) {
                $at=(int)($d['next_attempt']??0);
                if ($at>$now) $wait($at,($d['retry_kind']??'')==='quota');else $busy=true;
            }
            if ($stage==='archived' && in_array($d['toconline_state']??'',['toc_sending','toc_submitted','toc_uncertain'],true)
                && ($d['toc_deadline']??0)>$now) {
                $at=min($now+60,(int)$d['toc_deadline']);$replyPoll=$replyPoll?min($replyPoll,$at):$at;
            }
            if ($stage==='split' && empty($state['source_cleanup_paused'])) {
                $ready=!empty($d['children']);foreach ($d['children']??[] as $child) {
                    if (!self::resolved($state['documents'][$child]??[])) $ready=false;
                }
                if ($ready) {
                    $at=(int)($d['next_attempt']??0);
                    if ($at>$now) $wait($at);else $busy=true;
                }
            }
            if (empty($d['children']) && !self::resolved($d)) $pending=true;
            if (!empty($d['children']) && !in_array($stage,['source_processed','split_completed'],true)) $pending=true;
        }
        $shared=$state['worker_retry']??[];
        if ($shared && ($busy || $next || !empty($state['requested']))) {
            if (!empty($shared['blocked'])) {
                $failed=true;$busy=false;$next=0;$shortRetry=false;
            } elseif (($shared['next_attempt']??0)>$now) {
                $next=$busy?(int)$shared['next_attempt']:max($next,(int)$shared['next_attempt']);
                $busy=false;$shortRetry=true;
            }
        }
        if (!empty($state['missing_periods'])) $pending=true;
        $key=$busy?'receipt_processing':($shortRetry?'receipt_retry_wait':($replyPoll?'receipt_toc_wait':($next?'receipt_quota_wait':($failed?'receipt_interrupted':($pending?'receipt_finished_pending':'receipt_finished')))));
        $poll=$busy?$now+5:($next && $replyPoll?min($next,$replyPoll):($next?:$replyPoll));
        $settled=!$busy && !$shortRetry && !$replyPoll;
        if ($busy && !empty($state['worker_seen_at']) && $now-(int)$state['worker_seen_at']>600) {
            $key='receipt_worker_delayed';$poll=0;$settled=true;
        }
        return ['state'=>$key,'next_at'=>$next,'server_time'=>$now,'busy'=>$busy,'poll_at'=>$poll,'settled'=>$settled,'automatic'=>$busy || $next>0 || $replyPoll>0];
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
    public static function render(array $report,bool $errorDetails=true,?array $progress=null): string
    {
        $e=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        $t=static fn($key)=>htmlspecialchars(InvoiceText::get($key),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        ob_start();
        ?>
<section class="card"><h2><?= $t('receipt_report') ?><?php if ($progress!==null): ?> — <?php
$running=!empty($progress['automatic']) && $progress['state']!=='receipt_worker_delayed';
$completed=$progress['state']==='receipt_finished';
?><span class="receipt-report-label"><?= $t('receipt_status') ?></span> <span class="receipt-report-state <?= $running?'is-running':($completed?'is-completed':'is-pending') ?>"><?= $t($running?'receipt_processing':($completed?'receipt_completed_bang':($progress['state']==='receipt_no_runs'?'receipt_no_runs':'receipt_pending'))) ?><?php if ($running): ?><span class="receipt-dots" aria-hidden="true"><?php for($i=0;$i<5;$i++): ?><span>.</span><?php endfor; ?></span><?php endif; ?></span><?php endif; ?></h2>
<?php if ($progress!==null && in_array($progress['state'],['receipt_retry_wait','receipt_quota_wait','receipt_toc_wait','receipt_worker_delayed'],true)): ?><p data-status-label><?= $t($progress['state']) ?></p><?php endif; ?>
<p class="receipt-totals">
<span><?= $t('receipt_files') ?>: <strong><?= $report['pdf_files'] ?></strong> pdf <?= $t('receipt_and') ?> <strong><?= $report['jpg_files'] ?></strong> jpg</span> <strong aria-hidden="true">|</strong>
<span><?= $t('receipt_a4') ?>: <strong><?= $report['a4'] ?></strong> - <?= $t('receipt_small') ?>: <strong><?= $report['small'] ?></strong> (<?= $t('receipt_docs') ?>: <strong><?= $report['documents'] ?></strong>)</span> <strong aria-hidden="true">|</strong>
<span><?= $t('receipt_processed') ?> <strong><?= $report['processed'] ?></strong></span> <strong aria-hidden="true">|</strong>
<?php if (!empty($report['discarded'])): ?><span><?= $t('receipt_deleted') ?>: <strong><?= $report['discarded'] ?></strong></span> <strong aria-hidden="true">|</strong><?php endif; ?>
<span><?= $t('receipt_pending') ?>: <strong><?= $report['pending'] ?></strong></span> <strong aria-hidden="true">|</strong>
<span class="<?= $report['errors']?'receipt-error-count':'' ?>"><?= $t('receipt_errors') ?>: <strong><?= count($report['errors']) ?></strong></span>
</p><p><?= $t('receipt_processed_note') ?></p>
<?php if ($report['duplicates']): ?>
<h3><?= $t('receipt_duplicates_title') ?> <span class="receipt-duplicate-instruction"><?= strtr($t('receipt_duplicates_instruction'), ['{paper_copies}'=>'<strong>'.$t('receipt_paper_copies').'</strong>']) ?></span></h3>
<div class="receipt-table-wrap"><table class="receipt-duplicates"><thead><tr><th><?= $t('receipt_issuer') ?></th><th><?= $t('receipt_number') ?></th><th><?= $t('receipt_date') ?></th></tr></thead><tbody>
<?php foreach ($report['duplicates'] as $duplicate): ?><tr><td data-i18n-skip><?= $duplicate['issuer_name']!==''?$e($duplicate['issuer_name']):$t('receipt_unknown_issuer') ?></td><td data-i18n-skip><?= $duplicate['invoice_number']!==''?$e($duplicate['invoice_number']):$t('receipt_unknown_data') ?></td><td data-i18n-skip><?= $duplicate['issued_on']!==''?$e(self::displayDate($duplicate['issued_on'])):$t('receipt_unknown_data') ?></td></tr><?php endforeach; ?>
</tbody></table></div><?php endif; ?>
<?php if ($errorDetails && $report['errors']): ?><details class="document-disclosure"><summary><?= $t('receipt_error_details') ?></summary><div class="receipt-instructions"><?php foreach ($report['errors'] as $error): ?><p><?= $t($error) ?></p><?php endforeach; ?></div></details><?php endif; ?>
</section>
        <?php
        return (string)ob_get_clean();
    }
}
