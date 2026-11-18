<?php if (!isset($receiptHistoryRendering)) { http_response_code(404); exit; } ?>
<details class="document-disclosure" data-receipt-preview data-url="receipt-document.php?id=<?= $escape(rawurlencode($id)) ?><?= $previewKind==='source'?'&amp;original=1':'' ?>" data-show="<?= $escape($previewTitle) ?>" data-close="<?= $t('receipt_close_document') ?>" data-loading="<?= $t('receipt_loading_document') ?>" data-error="<?= $t('receipt_preview_unavailable') ?>" data-pdf-previous="<?= $t('receipt_pdf_previous') ?>" data-pdf-next="<?= $t('receipt_pdf_next') ?>" data-pdf-page="<?= $t('receipt_pdf_page') ?>" data-pdf-zoom-in="<?= $t('receipt_pdf_zoom_in') ?>" data-pdf-zoom-out="<?= $t('receipt_pdf_zoom_out') ?>">
<summary><?= $escape($previewTitle) ?></summary>
<div id="preview-<?= $escape($id.'-'.$previewKind) ?>" data-preview-panel>
<?php if ($previewKind==='source'): ?><p><?= $t('receipt_source_warning') ?></p><?php endif; ?>
<p role="status"></p><div data-document-content></div></div>
</details>
