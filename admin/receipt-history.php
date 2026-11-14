<?php if (!isset($receiptHistoryRendering)) { http_response_code(404); exit; } ?>
<?php if ($canHistory): ?>
<section class="card"><h2><?= $t('receipt_total') ?>: <?= count($all) ?></h2>
<?php if (!$documents): ?><p><?= $t('receipt_empty') ?></p><?php endif; ?>
<?php foreach ($documents as $id=>$d): ?>
<article class="card"><h3 data-i18n-skip><?= $escape($d['name']) ?></h3>
<?php if (!empty($d['page_number'])): ?><p><?= $t('receipt_page') ?> <?= $escape(implode(', ',$d['pages']??[(int)$d['page_number']])) ?> / <?= (int)$d['page_count'] ?></p><?php endif; ?>
<p><?= $t('receipt_expected') ?>: <?= $escape($d['expected_period']??'') ?></p>
<?php if (!empty($d['issuer_name'])): ?><p data-i18n-skip><?= $escape($d['issuer_name']) ?></p><?php endif; ?>
<p><?= $t('receipt_stage_'.$d['stage']) ?> · <?= $t($d['toconline_state']) ?></p>
<?php if (!empty($d['issued_on'])): ?><p><?= $t('receipt_date') ?>: <?= $escape($d['issued_on']) ?> · <?= $escape($d['invoice_number']) ?></p><?php endif; ?>
<?php if (!empty($d['error'])): ?><p class="alert"><?= $t($d['error']) ?></p><?php endif; ?>
<?php if (!in_array($d['stage'],['duplicate_trashed','split_completed'],true)): ?>
<?php
$previewOptions=['document'=>!empty($d['analysis_version']) || empty($d['source_id'])?'receipt_show_document':'receipt_show_page'];
$sourceDocument=$state['documents'][$d['source_id']??'']??null;
if (is_array($sourceDocument) && ($sourceDocument['stage']??'')!=='split_completed') $previewOptions['source']='receipt_show_source';
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
<?php if ($d['stage']==='review' && !empty($d['sha256']) && empty($d['grouping_pending']) && ($d['review_reason']??'')!=='receipt_grouping_required'): ?>
<?php if ($canReview): ?><form method="post">
<input type="hidden" name="csrf_token" value="<?= $escape(Csrf::token()) ?>"><input type="hidden" name="id" value="<?= $escape($id) ?>"><input type="hidden" name="action" value="review">
<label><?= $t('receipt_date') ?> <input type="date" name="issued_on" value="<?= $escape($d['issued_on']??'') ?>" <?= ($d['data_source']??'')==='qr'?'readonly':'' ?> required></label>
<label><?= $t('receipt_issuer') ?> <input type="text" name="issuer_name" maxlength="160" value="<?= $escape($d['issuer_name']??'') ?>"></label>
<label><?= $t('receipt_number') ?> <input type="text" name="invoice_number" maxlength="128" value="<?= $escape($d['invoice_number']??'') ?>" <?= ($d['data_source']??'')==='qr'?'readonly':'' ?> required></label>
<p data-i18n-skip>NIF: <?= $escape($tocSettings['nif']??'') ?></p>
<label><input type="checkbox" name="confirmed" value="1" required> <?= $t('receipt_confirm') ?></label>
<div class="form-actions"><button class="primary-button" type="submit"><?= $t('receipt_approve') ?></button></div>
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