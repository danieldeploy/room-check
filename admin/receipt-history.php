<?php if (!isset($receiptHistoryRendering)) { http_response_code(404); exit; } ?>
<?php if ($canHistory): ?>
<?php $historyCounts=InvoiceReceiptReport::summary($all); ?>
<section class="card"><div class="receipt-history-heading"><h2 data-receipt-history-counts><?= $t('receipt_total') ?>: <?= $historyCounts['documents'] ?> | <?= $t('receipt_history_processed') ?>: <?= $historyCounts['processed'] ?> | <?= $t('receipt_pending') ?>: <?= $historyCounts['pending'] ?></h2>
<div class="receipt-document-filter" data-receipt-document-filter <?= $selectedRunning?'hidden':'' ?>>
<select name="filter" form="receipt-process-form" data-receipt-filter aria-label="<?= $t('receipt_filter') ?>" <?= $selectedRunning?'disabled':'' ?>>
<?php if ($filter==='all'): ?><option value="all" selected hidden><?= $t('receipt_filter_all') ?></option><?php endif; ?>
<?php foreach (['success'=>'receipt_filter_success','problems'=>'receipt_filter_problems'] as $value=>$label): ?><option value="<?= $value ?>" <?= $value===$filter?'selected':'' ?>><?= $t($label) ?></option><?php endforeach; ?>
</select></div></div>
<?php if (!$documents): ?><p><?= $t('receipt_empty') ?></p><?php endif; ?>
<?php foreach ($documents as $id=>$d): ?>
<?php
if (!empty($d['children']) && ($d['format']??'')==='jpg' && empty($d['error']) && ($d['stage']??'')!=='failed') continue;
$categoryReview=$d['stage']==='review' && InvoiceReceipts::categoryReview($d);
$correctionIdle=InvoiceReceiptReport::canStart($fullState,$sendEnabled);
$reviewable=!$categoryReview && $d['stage']==='review' && empty($d['children']) && !empty($d['sha256']);
$buyerAccepted=InvoiceReceiptAnalysis::buyerAccepted($d,(string)($tocSettings['nif']??''));
$buyerIssue=InvoiceReceiptAnalysis::buyerIssue($d,(string)($tocSettings['nif']??''));
?>
<?php if (!empty($d['children'])): ?><details class="document-disclosure receipt-source"><summary><?= $t(($d['format']??'')==='pdf'?'receipt_source_pdf':'receipt_source_file') ?>: <span data-i18n-skip><?= $escape($d['name']) ?></span><?php if (!empty($d['error']) || ($d['stage']??'')==='failed'): ?> — <?= $t('receipt_stage_failed') ?><?php endif; ?></summary><?php endif; ?>
<article class="card receipt-invoice"><h3 data-i18n-skip><?= $escape($d['name']) ?></h3>
<p class="receipt-meta"><?php if (!empty($d['page_number'])): ?><?= $t('receipt_page') ?> <?= $escape(implode(', ',$d['pages']??[(int)$d['page_number']])) ?> / <?= (int)$d['page_count'] ?> | <?php endif; ?><?= $t('receipt_expected') ?>: <span data-i18n-skip><?= $escape(InvoiceReceiptReport::displayDate($d['expected_period']??'',true)) ?></span></p>
<?php if (!$categoryReview): ?><p class="receipt-meta"><?= $t('receipt_company') ?> : <span data-i18n-skip><?= $escape($d['issuer_name']??'') ?: $t('receipt_unknown_issuer') ?></span> | <?= $t('receipt_number_date') ?>: <span data-i18n-skip><?= $escape($d['invoice_number']??'') ?> <?= !empty($d['issued_on'])?'('.$escape(str_replace([' (',')'],[' ',''],InvoiceReceiptReport::displayDate($d['issued_on']))).')':'' ?></span></p><?php endif; ?>
<p class="receipt-meta"><?php if (($d['stage']??'')==='archived' && ($d['toconline_state']??'')==='toc_accepted'): ?><?= $t('receipt_archive_confirmed') ?><?php else: ?><?= $t('receipt_stage_'.$d['stage']) ?> — <?= $t($d['toconline_state']) ?><?php endif; ?></p>
<?php if (InvoiceReceiptReport::paperMonthMismatch($d)): ?><p class="receipt-duplicate-instruction"><?= strtr($t('receipt_paper_month_instruction'),['{paper_invoice}'=>'<strong>'.$t('receipt_paper_invoice').'</strong>']) ?></p><?php endif; ?>
<?php if ($reviewable): ?><p class="alert receipt-review-issues"><?= implode(' | ',array_map($t,InvoiceReceiptAnalysis::reviewIssues($d,(string)($tocSettings['nif']??'')))) ?></p>
<?php elseif (!empty($d['error'])): ?><p class="alert"><?= $t($d['error']) ?></p><?php endif; ?>
<?php if (($d['toconline_state']??'')==='toc_rejected' && ($d['delivery_reason']??'')==='toc_provider_format'): ?><p><?= $t('toc_provider_format') ?></p><?php endif; ?>
<?php if (in_array($d['toconline_state']??'',['toc_no_confirmation','toc_uncertain'],true)): ?>
<?php if (in_array($d['delivery_reason']??'',['toc_mail_refused','toc_mail_exception','toc_interrupted_handoff'],true)): ?><p><?= $t($d['delivery_reason']) ?></p><?php endif; ?>
<?php if ($d['toconline_state']==='toc_no_confirmation'): ?><p><?= $t('toc_confirmation_expired') ?></p><?php endif; ?>
<?php endif; ?>
<?php $facts=InvoiceReceiptReport::failureFacts($d); if ($facts): ?>
<p class="alert"><?= $t($facts['phase']) ?> · <?= $t($facts['reason']) ?>
<span data-i18n-skip><?= $facts['http_status']?'HTTP '.$facts['http_status']:'' ?> <?= $facts['curl_errno']?'cURL '.$facts['curl_errno']:'' ?> <?= $facts['duration_ms'] ?> ms <?= $escape($facts['finish']??'') ?></span></p>
<?php elseif (($d['error']??'')==='gemini_retry'): ?><p><?= $t('gemini_detail_unavailable') ?></p><?php endif; ?>
<?php if (($d['stage']??'')==='failed'): ?><p><?= $t('receipt_retry_stopped') ?></p>
<?php elseif (($d['next_attempt']??0)>0): ?><p><?= ($d['wait_reason']??'')==='toc_send_wait'?$t('toc_send_wait').' ':'' ?><?= $t('receipt_next_attempt') ?> · <?= $escape((new DateTimeImmutable('@'.(int)$d['next_attempt']))->setTimezone(new DateTimeZone('Europe/Lisbon'))->format('d-m-Y H:i:s')) ?></p><?php endif; ?>
<?php $previewId=InvoiceReceiptReport::previewId($fullState['documents'],$id); if ($previewId!==null): ?>
<?php
$previewOptions=['document'=>'receipt_show_document'];
$sourceDocument=$state['documents'][$d['source_id']??'']??null;
if ($previewId===$id && ($d['format']??'')==='pdf' && is_array($sourceDocument) && ($sourceDocument['format']??'')==='pdf' && ($sourceDocument['stage']??'')!=='split_completed') $previewOptions['source']='receipt_show_source';
foreach ($previewOptions as $previewKind=>$previewLabel): ?>
<details class="document-disclosure" data-receipt-preview data-url="receipt-document.php?id=<?= $escape(rawurlencode($id)) ?><?= $previewKind==='source'?'&amp;original=1':'' ?>" data-show="<?= $t($previewLabel) ?>" data-close="<?= $t('receipt_close_document') ?>" data-loading="<?= $t('receipt_loading_document') ?>" data-error="<?= $t('receipt_preview_unavailable') ?>" data-pdf-previous="<?= $t('receipt_pdf_previous') ?>" data-pdf-next="<?= $t('receipt_pdf_next') ?>" data-pdf-page="<?= $t('receipt_pdf_page') ?>" data-pdf-zoom-in="<?= $t('receipt_pdf_zoom_in') ?>" data-pdf-zoom-out="<?= $t('receipt_pdf_zoom_out') ?>">
<summary><?= $t($previewLabel) ?></summary>
<div id="preview-<?= $escape($id.'-'.$previewKind) ?>" data-preview-panel>
<?php if ($previewKind==='source'): ?><p><?= $t('receipt_source_warning') ?></p><?php endif; ?>
<p role="status"></p><div data-document-content></div></div>
</details>
<?php endforeach; ?>
<?php endif; ?>
<?php if ($canRun && $d['stage']==='failed'): ?><form method="post"><input type="hidden" name="csrf_token" value="<?= $escape(Csrf::token()) ?>"><input type="hidden" name="id" value="<?= $escape($id) ?>"><input type="hidden" name="action" value="retry"><div class="form-actions"><button class="primary-button" type="submit"><?= $t('receipt_retry') ?></button></div></form><?php endif; ?>
<?php if ($reviewable): ?>
<?php if ($buyerIssue!==''): ?>
<p><?= $t('receipt_buyer_nif') ?>: <span data-i18n-skip><?= $escape($d['company_nif']??'') ?: $t('receipt_unknown_data') ?></span></p>
<?php if ($buyerAccepted): ?><p><?= $t('receipt_buyer_confirmed') ?></p><?php endif; ?>
<?php if ($canReview && !$buyerAccepted && InvoiceReceiptAnalysis::discardable($d)): ?>
<details class="document-disclosure"><summary><?= $t('receipt_delete') ?></summary>
<form method="post" class="receipt-review-form">
<input type="hidden" name="csrf_token" value="<?= $escape(Csrf::token()) ?>"><input type="hidden" name="id" value="<?= $escape($id) ?>"><input type="hidden" name="action" value="discard">
<label><input type="checkbox" name="discard_confirmed" value="1" required> <?= $t('receipt_delete_confirm') ?></label>
<div class="form-actions"><button class="primary-button" type="submit"><?= $t('receipt_delete') ?></button></div>
</form></details><?php endif; ?>
<?php endif; ?><?php endif; ?>
<?php if ($categoryReview): ?>
<p><?= $t('receipt_category_expected') ?>: <?= $t(($d['source_category']??'')==='a4'?'receipt_a4':'receipt_small') ?> | <?= $t('receipt_category_observed') ?>: <?= $t(match($d['category_check']['category']??'uncertain') {'a4'=>'receipt_a4','taloes'=>'receipt_small',default=>'receipt_category_unknown'}) ?></p>
<?php endif; ?>
<?php $missingCorrection=$d['stage']==='review' && ($d['format']??'')==='pdf' && !empty($d['source_id']) && InvoiceReceiptAnalysis::missingPages($d); ?>
<?php $replacementReview=InvoiceReceipts::replacementReview($d); ?>
<?php if ($canReview && ($replacementReview || $missingCorrection)): ?>
<?php if ($correctionIdle): ?>
<?php foreach (array_keys(array_filter(['repair'=>$missingCorrection,'replace_source'=>$replacementReview])) as $correctionAction): $replacing=$correctionAction==='replace_source'; ?>
<?php if ($replacing && !$categoryReview): ?><details class="document-disclosure"><summary><?= $t('receipt_replace_file') ?></summary><?php endif; ?>
<form method="post" class="receipt-review-form" data-receipt-correction data-url="receipts.php?correction_files=<?= $escape(rawurlencode($id)) ?>" data-preview-url="receipt-document.php?id=<?= $escape(rawurlencode($id)) ?>" data-max="<?= $replacing?1:19 ?>" data-loading="<?= $t('receipt_correction_loading') ?>" data-error="<?= $t('receipt_correction_failed') ?>" data-empty="<?= $t('receipt_correction_empty') ?>">
<input type="hidden" name="csrf_token" value="<?= $escape(Csrf::token()) ?>"><input type="hidden" name="id" value="<?= $escape($id) ?>"><input type="hidden" name="action" value="<?= $correctionAction ?>">
<p><?= $t($replacing?($categoryReview?'receipt_replacement_help':'receipt_replace_file_help'):'receipt_missing_selection_help') ?></p>
<div class="form-actions"><button class="primary-button" type="button" data-correction-load><?= $t('receipt_correction_load') ?></button></div>
<p><?= $t($replacing?'receipt_replacement_file':'receipt_missing_files') ?></p>
<select name="selected_files[]" multiple data-correction-files hidden disabled aria-hidden="true" tabindex="-1"></select>
<ul data-correction-selected data-i18n-skip hidden></ul>
<p role="status" data-correction-status></p>
<label><input type="checkbox" data-correction-confirm name="<?= $replacing?'replacement_added':'missing_added' ?>" value="1" required> <?= $t($replacing?'receipt_replacement_added':'receipt_missing_added') ?></label>
<div class="form-actions"><button class="primary-button" type="submit" data-correction-submit disabled><?= $t('receipt_process_again') ?></button></div>
<template data-correction-picker>
<dialog class="receipt-picker" data-i18n-skip aria-label="<?= $t('receipt_correction_load') ?>" data-folder-empty="<?= $t('receipt_picker_folder_empty') ?>" data-count="<?= $t('receipt_picker_count') ?>">
<div class="receipt-picker-heading"><h2><?= $t('receipt_correction_load') ?></h2><button type="button" class="secondary-button" data-picker-cancel autofocus><?= $t('receipt_picker_cancel') ?></button></div>
<nav class="receipt-picker-path" data-picker-path aria-label="<?= $t('receipt_picker_path') ?>" data-i18n-skip></nav>
<p role="status" data-picker-status></p>
<div data-picker-list class="receipt-picker-list"></div>
<div class="receipt-picker-actions"><span data-picker-count aria-live="polite"></span><button type="button" class="primary-button" data-picker-apply disabled><?= $t('receipt_picker_apply') ?></button></div>
</dialog>
</template>
<template data-correction-preview>
<details class="document-disclosure" data-receipt-preview data-show="<?= $t('receipt_show_document') ?>" data-loading="<?= $t('receipt_loading_document') ?>" data-error="<?= $t('receipt_preview_unavailable') ?>" data-pdf-previous="<?= $t('receipt_pdf_previous') ?>" data-pdf-next="<?= $t('receipt_pdf_next') ?>" data-pdf-page="<?= $t('receipt_pdf_page') ?>" data-pdf-zoom-in="<?= $t('receipt_pdf_zoom_in') ?>" data-pdf-zoom-out="<?= $t('receipt_pdf_zoom_out') ?>">
<summary><?= $t('receipt_show_document') ?></summary><div data-preview-panel><p role="status"></p><div data-document-content></div></div>
</details>
</template>
</form>
<?php if ($replacing && !$categoryReview): ?></details><?php endif; ?>
<?php endforeach; ?>
<?php else: ?><p><?= $t('receipt_correction_wait') ?></p><?php endif; ?>
<?php endif; ?>
<?php if ($reviewable && empty($d['grouping_pending']) && ($d['review_reason']??'')!=='receipt_grouping_required'): ?>
<?php
$formData=$d;
if ($requestError!=='' && in_array($_POST['action']??'',['review','confirm_buyer'],true) && ($_POST['id']??'')===$id) {
    foreach (['issued_on','issuer_name','invoice_number','issuer_nif'] as $field) if (is_string($_POST[$field]??null)) $formData[$field]=$_POST[$field];
}
$inputDate=(string)($formData['issued_on']??'');
if (preg_match('/\A(\d{4})-(\d{2})-(\d{2})\z/',$inputDate,$dateParts)) $inputDate=$dateParts[3].'/'.$dateParts[2].'/'.$dateParts[1];
$missing=InvoiceReceiptAnalysis::missingPages($d);$qr=($d['data_source']??'')==='qr';
$fiscalBlocked=isset($d['fiscal_status']) && $d['fiscal_status']!=='N';
?>
<?php if ($canReview): ?><form method="post" class="receipt-review-form" data-receipt-review data-date-max="<?= $escape((new DateTimeImmutable('now',new DateTimeZone('Europe/Lisbon')))->format('Y-m-d')) ?>" data-blocked="<?= $fiscalBlocked?'true':'false' ?>">
<input type="hidden" name="csrf_token" value="<?= $escape(Csrf::token()) ?>"><input type="hidden" name="id" value="<?= $escape($id) ?>"><input type="hidden" name="action" value="review">
<div class="receipt-review-fields">
<label class="field"><span><?= $t('receipt_date') ?></span><input type="text" inputmode="numeric" name="issued_on" maxlength="10" placeholder="DD/MM/AAAA" data-review-date value="<?= $escape($inputDate) ?>" <?= $qr?'readonly':'' ?> required></label>
<label class="field"><span><?= $t('receipt_issuer') ?></span><input type="text" name="issuer_name" maxlength="160" placeholder="<?= $t('receipt_input_company') ?>" value="<?= $escape($formData['issuer_name']??'') ?>" required></label>
<label class="field"><span><?= $t('receipt_number') ?></span><input type="text" name="invoice_number" maxlength="128" placeholder="<?= $t('receipt_input_number') ?>" value="<?= $escape($formData['invoice_number']??'') ?>" <?= $qr?'readonly':'' ?> required></label>
<label class="field"><span><?= $t('receipt_issuer_nif') ?></span><input type="text" inputmode="numeric" name="issuer_nif" maxlength="9" pattern="[0-9]{9}" placeholder="<?= $t('receipt_input_nif') ?>" value="<?= $escape($formData['issuer_nif']??'') ?>" <?= $qr?'readonly':'' ?> required></label>
</div>
<?php if (!$buyerAccepted): ?><label><input type="checkbox" name="buyer_confirmed" value="1" required> <?= $t('receipt_buyer_confirm') ?> (<?= $escape($tocSettings['nif']??'') ?>)</label><?php endif; ?>
<?php if ($missing): ?><input type="hidden" name="confirmed" value="1"><?php else: ?><label><input type="checkbox" name="confirmed" value="1" required> <?= $t('receipt_confirm') ?></label><?php endif; ?>
<div class="form-actions"><button class="primary-button" type="submit" data-review-submit disabled><?= $t($missing?'receipt_save_details':'receipt_approve') ?></button></div>
</form><?php else: ?><p><?= $t('receipt_wait') ?></p><?php endif; ?>
<?php endif; ?><?php if ($canReview && empty($d['source_id']) && ($d['format']??'')==='pdf' && !in_array($d['stage'],['split_completed','source_trashing','source_moving','source_processed'],true) && (!empty($d['children']) || !empty($d['grouping_pending'])) && !InvoiceReceipts::hasReplacementChildren($id,$d,$fullState['documents'])): ?>
<details><summary><?= $t('receipt_groups') ?></summary><p><?= $t('receipt_groups_help') ?></p>
<form method="post"><input type="hidden" name="csrf_token" value="<?= $escape(Csrf::token()) ?>"><input type="hidden" name="action" value="regroup"><input type="hidden" name="id" value="<?= $escape($id) ?>">
<label class="field"><span><?= $t('receipt_groups') ?></span><textarea name="page_groups" required maxlength="400" rows="4"></textarea></label>
<label class="check-row"><input type="checkbox" name="confirmed" value="1" required><?= $t('receipt_groups_confirm') ?></label>
<div class="form-actions"><button class="primary-button" type="submit"><?= $t('receipt_approve') ?></button></div></form></details>
<?php endif; ?>
</article>
<?php if (!empty($d['children'])): ?></details><?php endif; ?>
<?php endforeach; ?>
<nav><?php if ($page>1): ?><a href="?<?= $escape(http_build_query($selectionQuery+['page'=>$page-1])) ?>"><?= $escape(SiteTranslations::text('Anterior','Previous')) ?></a><?php endif; ?> <?php if ($page*50<count($all)): ?><a href="?<?= $escape(http_build_query($selectionQuery+['page'=>$page+1])) ?>"><?= $escape(SiteTranslations::text('Seguinte','Next')) ?></a><?php endif; ?></nav>
</section><?php endif; ?>
