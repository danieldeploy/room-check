<?php
declare(strict_types=1);
function ie(string $value): string { return htmlspecialchars($value,ENT_QUOTES,'UTF-8'); }
function it(string $key): string { return ie(InvoiceText::get($key)); }
function invoiceTime(?string $value): string { return $value ? (new DateTimeImmutable($value,new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Europe/Lisbon'))->format('d/m/Y H:i') : '—'; }
function invoiceUrl(string $tab,array $extra=[]): string {
    global $filters;
    return 'invoices.php?'.http_build_query(array_merge($filters,['tab'=>$tab,'state'=>'','q'=>''],$extra));
}
function invoiceStatus(string $state,?string $label=null): void {
    $tone=match($state) {
        'ready','completed','ok','enabled','active','validated','drive_verified','alert_sent','alert_resolved'=>'success',
        'failed','drive_failed','alert_failed'=>'error',
        'needs_auth','review','retry','drive_retry','waiting_auth','not_configured','partial','integration_setup','access_to_test','alert_pending','alert_retry','session_active_badge'=>'warning', default=>'neutral',
    };
    echo '<span class="invoice-status invoice-status--'.$tone.'">'.it($label ?? $state).'</span>';
}
function invoiceHidden(string $action,int $account=0): void {
    global $filters;
    ?><input type="hidden" name="csrf_token" value="<?= ie(Csrf::token()) ?>"><input type="hidden" name="action" value="<?= ie($action) ?>"><input type="hidden" name="account_id" value="<?= $account ?>"><?php
    foreach ($filters as $key=>$value) { ?><input type="hidden" name="<?= ie($key) ?>" value="<?= ie((string)$value) ?>"><?php }
    if (in_array($action,['collect','retry_batch','retry_task'],true)) { ?><input type="hidden" name="request_key" value="<?= bin2hex(random_bytes(32)) ?>"><?php }
}
function invoiceTaskCard(array $job): void {
    global $isGerente,$canRun,$readiness;
    ?><article class="invoice-record"><div class="invoice-record-heading"><h3><?= ie(InvoiceAccounts::PORTALS[$job['portal']].' — '.$job['account_label']) ?></h3><?php invoiceStatus($job['result_code']==='session_active'?'session_active_badge':$job['state']); ?></div>
    <p><?= ie($job['property_label'] ?? $job['property_id']) ?></p><dl class="invoice-facts"><div><dt><?= it('created') ?></dt><dd><?= ie(invoiceTime($job['created_at'])) ?></dd></div><div><dt><?= it('period') ?></dt><dd><?= ie($job['period']) ?></dd></div><div><dt><?= it('operation') ?></dt><dd><?= it($job['kind']) ?></dd></div><div><dt><?= it('counts') ?></dt><dd><?= (int)$job['imported_count'] ?> / <?= (int)$job['duplicate_count'] ?></dd></div></dl>
    <?php if ($job['result_code']): ?><p><?= it($job['result_code']) ?></p><?php endif; ?>
    <?php if ($job['next_attempt_at']): ?><p><?= it('next_attempt') ?>: <?= ie(invoiceTime($job['next_attempt_at'])) ?></p><?php endif; ?>
    <?php if ($job['kind']==='collect' && in_array($job['state'],['failed','needs_auth'],true)): ?><div class="form-actions"><?php if ($canRun && ($readiness[$job['account_id']] ?? '')==='ready'): ?><form method="post"><?php invoiceHidden('retry_task',(int)$job['account_id']); ?><input type="hidden" name="task_id" value="<?= (int)$job['id'] ?>"><button class="primary-button"><?= it('retry_collection') ?></button></form><?php endif; ?><?php if ($isGerente): ?><a href="<?= ie(invoiceUrl('accounts',['edit'=>$job['account_id']])) ?>"><?= it('check_account') ?></a><?php endif; ?></div><?php endif; ?></article><?php
}

function invoicePropertyRow(string $id='',string $label='',bool $accountScope=false,bool $existing=false): void { ?>
<div class="invoice-property-row" data-property-row><label class="field" data-property-id-field <?= $accountScope?'hidden':'' ?>><span><?= it('portal_property_id') ?></span><input type="text" name="property_ids[]" value="<?= ie($id) ?>" maxlength="64" pattern="[a-zA-Z0-9_-]+" <?= $existing?'readonly':'' ?> <?= !$accountScope?'required':'' ?>></label><label class="field"><span><?= it('property') ?></span><input type="text" name="property_labels[]" value="<?= ie($label) ?>" maxlength="120" required></label><button class="invoice-secondary" type="button" data-remove-property><?= it('remove_property') ?></button></div>
<?php } 
