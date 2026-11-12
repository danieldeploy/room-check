<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/I18n/InvoiceText.php';

/** Explicit projection: the operator report never contains file identities or original links. */
final class InvoiceReceiptReport
{
    public static function summary(array $documents): array
    {
        $report=['files'=>0,'documents'=>0,'processed'=>0,'duplicates'=>[],'errors'=>[]];
        foreach ($documents as $document) {
            if (empty($document['source_id'])) $report['files']++;
            $master=!empty($document['children']);
            if (!$master) {
                $report['documents']++;
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

    public static function render(array $report): string
    {
        $e=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        $t=static fn($key)=>htmlspecialchars(InvoiceText::get($key),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        ob_start();
        ?>
<section class="card"><h2><?= $t('receipt_report') ?></h2>
<p><?= $t('receipt_files') ?>: <?= (int)$report['files'] ?> · <?= $t('receipt_documents') ?>: <?= (int)$report['documents'] ?> · <?= $t('receipt_processed') ?>: <?= (int)$report['processed'] ?> · <?= $t('receipt_report_duplicates') ?>: <?= count($report['duplicates']) ?> · <?= $t('receipt_errors') ?>: <?= count($report['errors']) ?></p>
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
