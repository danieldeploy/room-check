<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/I18n/InvoiceText.php';

/** Explicit projection: the operator report never contains file identities or original links. */
final class InvoiceReceiptReport
{
    public static function summary(array $documents): array
    {
        $report=['files'=>0,'documents'=>0,'processed'=>0,'pending'=>0,'duplicates'=>[],'errors'=>[]];
        foreach ($documents as $document) {
            if (($document['stage']??'')==='superseded') continue;
            if (empty($document['source_id'])) $report['files']++;
            $master=!empty($document['children']);
            if (!$master) {
                $report['documents']++;
                if (!in_array($document['toconline_state']??'pending',['toc_accepted','toc_existing'],true)) $report['pending']++;
                if (!empty($document['archived_at'])) $report['processed']++;
                if (($document['toconline_state']??'')==='toc_existing') {
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
            if (in_array($stage,['queued','reading','ready','moving','preserving','trashing','source_trashing'],true)
                || ($stage==='archived' && (($d['toconline_state']??'')==='toc_existing' || ($sendEnabled && in_array($d['toconline_state']??'',['pending','toc_retry'],true))))) {
                $at=(int)($d['next_attempt']??0);
                if ($at>time()) $next=$next?min($next,$at):$at; else $busy=true;
            }
            if ($stage==='split') {
                $ready=!empty($d['children']);foreach ($d['children']??[] as $child) {
                    $c=$state['documents'][$child]??[];
                    if ((empty($c['archived_at']) && empty($c['preserved_at'])) || ($c['review_reason']??'')==='receipt_grouping_required') $ready=false;
                }
                if ($ready) $busy=true;
            }
            if (empty($d['children']) && !in_array($d['toconline_state']??'', ['toc_accepted','toc_existing'],true)) $pending=true;
        }
        $key=$busy?'receipt_processing':($next?'receipt_retry_wait':($failed?'receipt_interrupted':($pending?'receipt_finished_pending':'receipt_finished')));
        if ($busy && !empty($state['worker_seen_at']) && time()-(int)$state['worker_seen_at']>600) $key='receipt_worker_delayed';
        return ['state'=>$key,'next_at'=>$next,'server_time'=>time(),'busy'=>$busy];
    }

    public static function render(array $report): string
    {
        $e=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        $t=static fn($key)=>htmlspecialchars(InvoiceText::get($key),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        ob_start();
        ?>
<section class="card"><h2><?= $t('receipt_report') ?></h2>
<p class="receipt-totals"><?php foreach (['files'=>'receipt_files','documents'=>'receipt_documents','processed'=>'receipt_processed','duplicates'=>'receipt_report_duplicates','pending'=>'receipt_pending','errors'=>'receipt_errors'] as $key=>$label): ?>
<span><?= $t($label) ?>: <strong><?= is_array($report[$key])?count($report[$key]):(int)$report[$key] ?></strong></span><?= $key!=='errors'?' <strong aria-hidden="true">|</strong> ':'' ?>
<?php endforeach; ?></p>
<p><?= $t('receipt_processed_note') ?></p>
<h3><?= $t('receipt_report_duplicates') ?></h3>
<?php if (!$report['duplicates']): ?><p><?= $t('receipt_no_duplicates') ?></p><?php else: ?>
<table><thead><tr><th><?= $t('receipt_issuer') ?></th><th><?= $t('receipt_number') ?></th><th><?= $t('receipt_date') ?></th></tr></thead><tbody>
<?php foreach ($report['duplicates'] as $duplicate): ?><tr><td data-i18n-skip><?= $duplicate['issuer_name']!==''?$e($duplicate['issuer_name']):$t('receipt_unknown_issuer') ?></td><td data-i18n-skip><?= $duplicate['invoice_number']!==''?$e($duplicate['invoice_number']):$t('receipt_unknown_data') ?></td><td data-i18n-skip><?= $duplicate['issued_on']!==''?$e($duplicate['issued_on']):$t('receipt_unknown_data') ?></td></tr><?php endforeach; ?>
</tbody></table><?php endif; ?>
<h3><?= $t('receipt_errors') ?></h3>
<?php if (!$report['errors']): ?><p><?= $t('receipt_no_errors') ?></p><?php else: ?><ul><?php foreach ($report['errors'] as $error): ?><li><?= $t($error) ?></li><?php endforeach; ?></ul><?php endif; ?>
</section>
        <?php
        return (string)ob_get_clean();
    }
}
