<?php if (!isset($receiptHistoryRendering)) { http_response_code(404); exit; } ?>
<?php if ($canHistory): ?>
<section class="card"><h2><?= $t('receipt_total') ?>: <?= count($all) ?></h2>
<?php if (!$documents): ?><p><?= $t('receipt_empty') ?></p><?php endif; ?>
<?php foreach ($documents as $id=>$d): ?>
<article class="card receipt-invoice"><h3 data-i18n-skip><?= $escape($d['name']) ?></h3>
<p class="receipt-meta"><?php if (!empty($d['page_number'])): ?><?= $t('receipt_page') ?> <?= $escape(implode(', ',$d['pages']??[(int)$d['page_number']])) ?> / <?= (int)$d['page_count'] ?> | <?php endif; ?><?= $t('receipt_expected') ?>: <span data-i18n-skip><?= $escape(InvoiceReceiptReport::displayDate($d['expected_period']??'',true)) ?></span></p>
<p class="receipt-meta"><?= $t('receipt_company') ?> : <span data-i18n-skip><?= $escape($d['issuer_name']??'') ?: $t('receipt_unknown_issuer') ?></span> | <?= $t('receipt_number_date') ?>: <span data-i18n-skip><?= $escape($d['invoice_number']??'') ?> <?= !empty($d['issued_on'])?'('.$escape(str_replace([' (',')'],[' ',''],InvoiceReceiptReport::displayDate($d['issued_on']))).')':'' ?></span></p>
<p class="receipt-meta"><?php if (($d['stage']??'')==='archived' && ($d['toconline_state']??'')==='toc_accepted'): ?><?= $t('receipt_archive_confirmed') ?><?php else: ?><?= $t('receipt_stage_'.$d['stage']) ?> — <?= $t($d['toconline_state']) ?><?php endif; ?></p>
<?php if (!empty($d['error'])): ?><p class="alert"><?= $t($d['error']) ?></p><?php endif; ?>
<?php if (!in_array($d['stage'],['duplicate_trashed','split_completed'],true)): ?>
<?php
$previewOptions=['document'=>'receipt_show_document'];
$sourceDocument=$state['documents'][$d['source_id']??'']??null;
if (($d['format']??'')==='pdf' && is_array($sourceDocument) && ($sourceDocument['format']??'')==='pdf' && ($sourceDocument['stage']??'')!=='split_completed') $previewOptions['source']='receipt_show_source';
foreach ($previewOptions as $previewKind=>$previewLabel): ?>
<details class="document-disclosure" data-receipt-preview data-url="receipt-document.php?id=<?= $escape(rawurlencode($id)) ?><?= $previewKind==='source'?'&amp;original=1':'' ?>" data-show="<?= $t($previewLabel) ?>" data-close="<?= $t('receipt_close_document') ?>" data-loading="<?= $t('receipt_loading_document') ?>" data-error="<?= $t('receipt_preview_unavailable') ?>">
<summary><?= $t($previewLabel) ?></summary>
<div id="preview-<?= $escape($id.'-'.$previewKind) ?>" data-preview-panel>
<?php if ($previewKind==='source'): ?><p><?= $t('receipt_source_warning') ?></p><?php endif; ?>
<p role="status"></p><div data-document-content></div></div>
</details>
<?php endforeach; ?>
<?php endif; ?>
<?php if ($canRun && $d['stage']==='failed'): ?><form method="post"><input type="hidden" name="csrf_token" value="<?= $escape(Csrf::token()) ?>"><input type="hidden" name="id" value="<?= $escape($id) ?>"><input type="hidden" name="action" value="retry"><div class="form-actions"><button class="primary-button" type="submit"><?= $t('receipt_retry') ?></button></div></form><?php endif; ?>
<?php if ($canReview && $d['stage']==='review' && ($d['format']??'')==='pdf' && !empty($d['source_id'])): ?>
<form method="post" class="receipt-review-form">
<input type="hidden" name="csrf_token" value="<?= $escape(Csrf::token()) ?>"><input type="hidden" name="id" value="<?= $escape($id) ?>"><input type="hidden" name="action" value="repair">
<label><input type="checkbox" name="missing_added" value="1" required> <?= $t('receipt_missing_added') ?></label>
<div class="form-actions"><button class="primary-button" type="submit"><?= $t('receipt_check_continue') ?></button></div>
</form><?php endif; ?>
<?php if ($d['stage']==='review' && !empty($d['sha256']) && empty($d['grouping_pending']) && ($d['review_reason']??'')!=='receipt_grouping_required'): ?>
<?php if ($canReview): ?><form method="post" class="receipt-review-form">
<input type="hidden" name="csrf_token" value="<?= $escape(Csrf::token()) ?>"><input type="hidden" name="id" value="<?= $escape($id) ?>"><input type="hidden" name="action" value="review">
<label><?= $t('receipt_date') ?> <input type="date" name="issued_on" value="<?= $escape($d['issued_on']??'') ?>" <?= ($d['data_source']??'')==='qr'?'readonly':'' ?> required></label>
<label><?= $t('receipt_issuer') ?> <input type="text" name="issuer_name" maxlength="160" value="<?= $escape($d['issuer_name']??'') ?>"></label>
<label><?= $t('receipt_number') ?> <input type="text" name="invoice_number" maxlength="128" value="<?= $escape($d['invoice_number']??'') ?>" <?= ($d['data_source']??'')==='qr'?'readonly':'' ?> required></label>
<p><?= $t('receipt_issuer_nif') ?>: <span data-i18n-skip><?= $escape($d['issuer_nif']??'') ?: $t('receipt_unknown_data') ?></span></p>
<?php $missing=($d['review_reason']??'')==='receipt_missing_pages'; ?>
<?php if ($missing): ?><input type="hidden" name="confirmed" value="1"><?php else: ?><label><input type="checkbox" name="confirmed" value="1" required> <?= $t('receipt_confirm') ?></label><?php endif; ?>
<div class="form-actions"><button class="primary-button" type="submit"><?= $t($missing?'receipt_save_details':'receipt_approve') ?></button></div>
</form><?php else: ?><p><?= $t('receipt_wait') ?></p><?php endif; ?>
<?php endif; ?><?php if ($canReview && empty($d['source_id']) && ($d['format']??'')==='pdf' && !in_array($d['stage'],['split_completed','source_trashing','source_moving','source_processed'],true) && (!empty($d['children']) || !empty($d['grouping_pending']))): ?>
<details><summary><?= $t('receipt_groups') ?></summary><p><?= $t('receipt_groups_help') ?></p>
<form method="post"><input type="hidden" name="csrf_token" value="<?= $escape(Csrf::token()) ?>"><input type="hidden" name="action" value="regroup"><input type="hidden" name="id" value="<?= $escape($id) ?>">
<label><?= $t('receipt_groups') ?><textarea name="page_groups" required maxlength="400" rows="4"></textarea></label>
<label class="check-row"><input type="checkbox" name="confirmed" value="1" required><?= $t('receipt_groups_confirm') ?></label>
<div class="form-actions"><button class="primary-button" type="submit"><?= $t('receipt_approve') ?></button></div></form></details>
<?php endif; ?>
</article>
<?php endforeach; ?>
<nav><?php if ($page>1): ?><a href="?<?= $escape(http_build_query(['process'=>$processId,'filter'=>$filter,'page'=>$page-1])) ?>"><?= $escape(SiteTranslations::text('Anterior','Previous')) ?></a><?php endif; ?> <?php if ($page*50<count($all)): ?><a href="?<?= $escape(http_build_query(['process'=>$processId,'filter'=>$filter,'page'=>$page+1])) ?>"><?= $escape(SiteTranslations::text('Seguinte','Next')) ?></a><?php endif; ?></nav>
</section><?php endif; ?>