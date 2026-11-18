<?php
if (!isset($receiptHistoryRendering)) { http_response_code(404); exit; }
$sent=(new DateTimeImmutable($batch['sent_at']))->setTimezone(new DateTimeZone('Europe/Lisbon'));
$title=strtr(InvoiceTocReplyText::label('toc_batch_title'),['{date}'=>$sent->format('d-m-Y H:i:s')]);
$rows=$batch['documents'];
$identified=count(array_filter($rows,static fn($r)=>in_array($r['state'],['toc_accepted','toc_existing','toc_duplicate_local'],true)));
$canAssign=$canResolveBatch && !empty($batch['unassigned']);
$canResend=$canResolveBatch && in_array(true,array_column($rows,'resend_eligible'),true);
$canConfirmAll=$canResolveBatch && $batch['all_present_eligible'];
$hasActions=$canAssign || $canResend || $canConfirmAll;
?>
<article class="card receipt-invoice receipt-batch" data-receipt-batch>
<h3><?= $escape($title) ?></h3>
<p class="alert receipt-block"><?= $t('toc_batch_blocked') ?>
<?php if ($batch['unassigned']!==null): ?> <?= $t($batch['unassigned']==='toc_existing'?'toc_batch_duplicate_unassigned':'toc_batch_refusal_unassigned') ?><?php endif; ?></p>
<?php if ($batch['accepted']!==null && $batch['rejected']!==null): ?><p><?= strtr($t('toc_batch_reply_counts'),['{total}'=>count($rows),'{accepted}'=>(int)$batch['accepted'],'{rejected}'=>(int)$batch['rejected'],'{missing}'=>(int)$batch['missing']]) ?></p><?php endif; ?>
<p><?= strtr($t('toc_batch_status_counts'),['{found}'=>$identified,'{total}'=>count($rows)]) ?></p>
<p><?= $t('toc_batch_check') ?><?php if ($canAssign || $canResend): ?> <?= $t('toc_batch_choose') ?><?php endif; ?><?php if ($canConfirmAll): ?> <?= $t('toc_batch_choose_all') ?><?php endif; ?></p>
<?php if ($hasActions): ?><form method="post" data-batch-review>
<input type="hidden" name="csrf_token" value="<?= $escape(Csrf::token()) ?>">
<input type="hidden" name="action" value="review_batch"><input type="hidden" name="process" value="<?= $escape($processId) ?>">
<input type="hidden" name="batch" value="<?= $escape($batch['id']) ?>"><input type="hidden" name="version" value="<?= $escape($batch['version']) ?>">
<?php endif; ?>
<table class="receipt-batch-table" aria-label="<?= $escape($title) ?>">
<thead><tr><?php if ($canResolveBatch): ?><th scope="col"><?= $t('toc_batch_selection') ?></th><?php endif; ?>
<?php foreach (['receipt_number','receipt_company','receipt_date','toc_batch_amount','receipt_status'] as $header): ?><th scope="col"><?= $t($header) ?></th><?php endforeach; ?>
</tr></thead>
<?php foreach ($rows as $row):
$id=$row['id'];$d=$fullState['documents'][$id]??[];
$name=(string)($d['name']??'');$number=(string)($d['invoice_number']??'');
$selectable=$row['resend_eligible'] || $row['assign_eligible'];
$reason=(string)($d['delivery_reason']??'');$tocState=$row['state'];
$previewTitle=strtr(InvoiceTocReplyText::label('toc_batch_view'),['{file}'=>$name?:$number]);
?>
<tbody>
<tr><?php if ($canResolveBatch): ?><td data-label="<?= $t('toc_batch_selection') ?>"><input type="checkbox" name="selected[]" value="<?= $escape($id) ?>" <?= !$selectable?'disabled':'' ?>
    aria-label="<?= $escape(strtr(InvoiceTocReplyText::label('toc_batch_select'),['{invoice}'=>trim($number.' '.$name)])) ?>" data-batch-row data-assign="<?= (int)$row['assign_eligible'] ?>" data-resend="<?= (int)$row['resend_eligible'] ?>"></td><?php endif; ?>
<th scope="row" data-label="<?= $t('receipt_number') ?>"><?= $escape($number) ?: $t('receipt_unknown_data') ?></th>
<td data-label="<?= $t('receipt_company') ?>"><?= $escape($d['issuer_name']??'') ?: $t('receipt_unknown_issuer') ?>
<?php if (!empty($d['issuer_nif'])): ?><small><?= $t('receipt_issuer_nif') ?> <?= $escape($d['issuer_nif']) ?></small><?php endif; ?></td>
<td data-label="<?= $t('receipt_date') ?>"><?= !empty($d['issued_on'])?$escape(InvoiceReceiptReport::displayDate($d['issued_on'])):$t('receipt_unknown_data') ?></td>
<td data-label="<?= $t('toc_batch_amount') ?>"><?= isset($d['total']) && (string)$d['total']!==''?$escape($d['total']):$t('receipt_unknown_data') ?></td>
<td data-label="<?= $t('receipt_status') ?>"><strong><?= $t(in_array($tocState,['toc_review','toc_no_confirmation'],true)?'toc_batch_unconfirmed':$tocState) ?></strong>
<?php $basis=InvoiceReceiptReport::confirmationLabel($d);if ($basis!==''): ?><small><?= $escape($basis) ?></small><?php endif; ?>
<?php if ((!empty($d['toc_supplier_counts_known']) || !empty($d['toc_supplier_unambiguous'])) && ($d['toc_confirmation_basis']??'')!=='batch' && isset($d['toc_supplier_total'],$d['toc_supplier_confirmed'])): ?><small><?= strtr($t('toc_batch_fiscal_counts'),['{confirmed}'=>(int)$d['toc_supplier_confirmed'],'{total}'=>(int)$d['toc_supplier_total']]) ?></small><?php endif; ?>
<?php if ($tocState==='toc_review'): $waiting=InvoiceReceiptReport::preparing($d); ?><small<?= $waiting?'':' class="receipt-block"' ?>><?= $escape(InvoiceTocReplyText::get($reason==='toc_provider_preparing' && !$waiting?'toc_preparation_expired':$reason)) ?></small>
<?php elseif ($tocState==='toc_rejected'): ?><small class="receipt-block"><?= $t(in_array($reason,['toc_provider_format','toc_provider_count','toc_provider_size','toc_provider_wrong_recipient'],true)?$reason:'toc_batch_refusal_unknown') ?></small>
<?php elseif ($tocState==='toc_no_confirmation'): ?><small class="receipt-block"><?= $t('toc_confirmation_expired') ?></small><?php endif; ?>
<?php if (array_key_exists('company_nif',$d) && !empty($tocSettings['nif'])):
    $buyerLabel=match(InvoiceReceiptAnalysis::buyerIssue($d,$tocSettings['nif'])) {
        'receipt_buyer_consumer'=>'toc_batch_buyer_consumer','receipt_buyer_missing'=>'toc_batch_buyer_missing',
        'receipt_company_changed'=>'toc_batch_buyer_recorded',default=>'',
    };
    if ($buyerLabel!==''): ?>
<small><?= $escape(strtr(InvoiceTocReplyText::label($buyerLabel),['{buyer}'=>(string)$d['company_nif'],'{expected}'=>$tocSettings['nif']])) ?></small>
<?php if (InvoiceReceiptAnalysis::buyerAccepted($d,$tocSettings['nif'])): ?><small><?= $t('receipt_buyer_confirmed') ?></small><?php endif; ?>
<?php endif; endif; ?>
</td></tr>
<tr class="receipt-batch-file"><td colspan="<?= $canResolveBatch?6:5 ?>">
<?php if (InvoiceReceiptReport::previewId($fullState['documents'],$id)!==null): $previewKind='batch-'.$batch['id'];require __DIR__.'/preview.php'; ?>
<?php else: ?><?= $escape($name) ?> · <?= $t('receipt_preview_unavailable') ?><?php endif; ?>
</td></tr></tbody>
<?php endforeach; ?>
</table>
<?php if ($hasActions): ?>
<label class="check-row"><input type="checkbox" name="checked_toc" value="1" required> <?= $t('toc_batch_verify') ?></label>
<?php if ($canResend && $canConfirmAll && count(array_filter($rows,static fn($r)=>$r['eligible']))>1): ?>
<label class="check-row" data-batch-others hidden><input type="checkbox" name="others_present" value="1"> <?= $t('toc_batch_others') ?></label>
<?php endif; ?>
<div class="form-actions">
<?php if ($canAssign): ?><button type="submit" class="primary-button" name="choice" value="assign_rejection"><?= $t($batch['unassigned']==='toc_existing'?'toc_batch_assign_duplicate':'toc_batch_assign_refused') ?></button><?php endif; ?>
<?php if ($canConfirmAll): ?><button type="submit" class="primary-button" name="choice" value="all_present"><?= $t('toc_batch_confirm_all') ?></button><?php endif; ?>
<?php if ($canResend): ?><button type="submit" class="primary-button" name="choice" value="resend_missing"><?= $t('toc_batch_resend') ?></button><?php endif; ?>
</div></form>
<?php endif; ?>
</article>
