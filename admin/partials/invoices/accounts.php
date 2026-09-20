<?php if (!defined('INVOICE_VIEW')) { http_response_code(404); exit; }
$accountScope=$editing && in_array($editing['portal'],['airbnb','email'],true);
?>
<?php if ($isGerente && ($newAccount || $editing)): ?>
<a class="invoice-link-action" href="<?= ie(invoiceUrl('accounts')) ?>"><?= it('back_accounts') ?></a>
<section class="card" data-account-editor><h2><?= it($newAccount?'create_account':'manage_account') ?></h2>
<?php if ($editing): ?><p><?= ie(InvoiceAccounts::PORTALS[$editing['portal']].' — '.$editing['label']) ?></p><div class="invoice-status-line"><?php invoiceStatus($editing['archived_at']?'archived':((int)$editing['is_active']?'active':'inactive')); ?> <?php invoiceStatus($readiness[$editId]); ?></div><?php endif; ?>
<?php if ($editing && $editing['archived_at']): ?><p><?= it('archived_note') ?></p><form method="post"><?php invoiceHidden('restore_account',$editId); ?><button class="primary-button"><?= it('restore_account') ?></button></form>
<?php else: ?>
<form method="post" data-account-details data-account-scope="<?= $accountScope?'1':'0' ?>">
<?php invoiceHidden($newAccount?'create_account':'account_details',$editId); ?>
<div class="form-grid"><?php if ($newAccount): ?><label class="field"><span><?= it('platform') ?></span><select name="portal" data-account-portal><?php foreach (InvoiceAccounts::PORTALS as $key=>$label): ?><option value="<?= ie($key) ?>"><?= ie($label) ?></option><?php endforeach; ?></select></label><?php endif; ?>
<label class="field"><span><?= it('account_name') ?></span><input type="text" name="label" maxlength="120" value="<?= ie($editing['label'] ?? '') ?>" required></label>
<label class="check-row"><input type="checkbox" name="is_active" <?= !$editing || (int)$editing['is_active']?'checked':'' ?>><span><?= it('active_account') ?></span></label></div><p><?= it('active_account_note') ?></p>
<h3><?= it('associated_properties') ?></h3><p data-account-scope-note <?= !$accountScope?'hidden':'' ?>><?= it('account_export_note') ?></p>
<div data-property-list><?php
if ($editing) {
    $linked=$repository->activeProperties($editId); if ($accountScope) unset($linked['account']);
    if (!$linked) invoicePropertyRow('',$editing['label'],$accountScope);
    else foreach ($linked as $id=>$label) invoicePropertyRow((string)$id,$label,$accountScope,true);
} else invoicePropertyRow();
?></div>
<template data-property-template><?php invoicePropertyRow(); ?></template>
<button type="button" class="invoice-secondary" data-add-property><?= it('add_property') ?></button>
<?php if ($editing): ?><p><?= it('properties_change_note') ?></p><?php endif; ?>
<div class="form-actions"><button class="primary-button"><?= it($newAccount?'create_and_continue':'save_account') ?></button></div>
</form>
<?php endif; ?></section>
<?php if ($editing && !$editing['archived_at']): $a=$editing; ?>
<section class="card"><h2><?= it('access') ?></h2><p><?= it('access_steps') ?></p><div class="invoice-status-line"><?php invoiceStatus($readiness[$editId]); ?></div>
<?php if ($a['login_verified_at']): ?><p><?= it('last_access_check') ?>: <?= ie(invoiceTime($a['login_verified_at'])) ?></p><?php endif; ?>
<?php if ($readiness[$editId]==='integration_unavailable'): ?><p><?= it('integration_unavailable_note') ?></p><?php elseif ($readiness[$editId]==='integration_setup'): ?><p><?= it('integration_setup_note') ?></p><?php endif; ?>
<details <?= $readiness[$editId]==='integration_setup'?'open':'' ?>><summary><?= it('credentials_heading') ?></summary><p><?= it('secret_note') ?></p><p><?= it('credentials_change_note') ?></p>
<form method="post" autocomplete="off" data-auth-form><?php invoiceHidden('credentials',$editId); ?><fieldset <?= !$vault?'disabled':'' ?>>
<div class="form-grid">
<?php if ($a['portal']!=='email'): ?>
<?php foreach (['identifier','password'] as $field): ?><label class="field"><span><?= it($field) ?></span><input type="<?= $field==='password'?'password':'text' ?>" name="<?= ie($field) ?>" autocomplete="new-password" maxlength="2048"></label><?php endforeach; ?>
<?php if ($a['portal']==='hostelworld'): ?><label class="field"><span><?= it('hostel_number') ?></span><input type="text" name="hostel_number" maxlength="2048"></label><?php endif; ?>
<label class="field"><span><?= it('second_factor') ?></span><select name="auth_method" data-auth-method><?php foreach (InvoiceAccounts::METHODS as $method): ?><option value="<?= ie($method) ?>" <?= $a['auth_method']===$method?'selected':'' ?>><?= it('method_'.$method) ?></option><?php endforeach; ?></select></label>
<?php else: ?><input type="hidden" name="auth_method" value="password"><?php endif; ?></div>
<?php if ($a['portal']!=='email'): ?><fieldset data-auth-for="sms" <?= $a['auth_method']!=='sms'?'hidden disabled':'' ?>><h3><?= it('method_sms') ?></h3><p><?= it('sms_note') ?></p><div class="form-grid"><?php foreach (['sms_sender','sms_keyword','sms_sim'] as $field): ?><label class="field"><span><?= it($field) ?></span><input type="text" name="<?= ie($field) ?>" maxlength="190"></label><?php endforeach; ?></div></fieldset><?php endif; ?>
<fieldset <?= $a['portal']!=='email'?'data-auth-for="email"':'' ?> <?= $a['portal']!=='email' && $a['auth_method']!=='email'?'hidden disabled':'' ?>><h3><?= it($a['portal']==='email'?'mailbox_access':'method_email') ?></h3><details open><summary><?= it('advanced_email') ?></summary><div class="form-grid"><?php foreach (['imap_host','imap_user','imap_password','imap_mailbox','email_sender','email_recipient','email_subject'] as $field): ?><label class="field"><span><?= it($field) ?></span><input type="<?= $field==='imap_password'?'password':'text' ?>" name="<?= ie($field) ?>" autocomplete="new-password" maxlength="2048"></label><?php endforeach; ?></div></details></fieldset>
<?php if ($a['portal']!=='email'): ?><fieldset data-auth-for="totp" <?= $a['auth_method']!=='totp'?'hidden disabled':'' ?>><h3><?= it('method_totp') ?></h3><p><?= it('totp_note') ?></p><label class="field"><span><?= it('totp_secret') ?></span><input type="password" name="totp_secret" autocomplete="new-password" maxlength="128"></label></fieldset><?php endif; ?>
<div class="form-actions"><button class="primary-button"><?= it('save_credentials') ?></button></div></fieldset></form></details>
<form method="post"><?php invoiceHidden('login',$editId); ?><button class="primary-button" <?= !$repository->active($editId)?'disabled':'' ?>><?= it('login') ?></button></form>
<?php if ($a['auth_method']==='sms'): ?><details class="invoice-options"><summary><?= it('sms_device_settings') ?></summary><p><?= it('sms_key_note') ?></p><form method="post"><?php invoiceHidden('sms_token',$editId); ?><button class="invoice-secondary"><?= it('sms_token') ?></button></form></details><?php endif; ?></section>
<section class="card"><h2><?= it('schedule') ?></h2><p><?= it('schedule_note') ?></p><p><?= it('schedule_validation_note') ?></p>
<form method="post"><?php invoiceHidden('schedule',$editId); ?><div class="form-grid"><label class="check-row"><input type="checkbox" name="enabled" <?= (int)$a['enabled']?'checked':'' ?> <?= $readiness[$editId]!=='ready' || !(int)$a['is_active']?'disabled':'' ?>><span><?= it('enable_schedule') ?></span></label><label class="field"><span><?= it('day') ?></span><input type="number" name="day" min="1" max="28" value="<?= (int)$a['schedule_day'] ?>" required></label><label class="field"><span><?= it('time') ?></span><input type="time" name="time" value="<?= ie($a['schedule_time']) ?>" required></label></div><button class="primary-button"><?= it('save_schedule') ?></button></form></section>
<section class="card"><details><summary><?= it('archive_account') ?></summary><p><?= it('archive_account_note') ?></p><form method="post"><?php invoiceHidden('archive_account',$editId); ?><button class="invoice-secondary"><?= it('archive_account') ?></button></form></details></section>
<?php endif; ?>
<?php else: ?>
<section class="card"><div class="invoice-record-heading"><h2><?= it('tab_accounts') ?></h2><?php if ($isGerente): ?><a class="primary-button" href="<?= ie(invoiceUrl('accounts',['new'=>1])) ?>"><?= it('create_account') ?></a><?php endif; ?></div>
<form method="get" class="invoice-filters" data-save-context="filter" data-save-context-key="invoice-account-filters" data-save-context-target="#invoice-results"><input type="hidden" name="tab" value="accounts"><input type="hidden" name="period" value="<?= ie($period) ?>"><label class="field"><span><?= it('platform') ?></span><select name="portal"><option value=""><?= it('all_platforms') ?></option><?php foreach (InvoiceAccounts::PORTALS as $key=>$label): ?><option value="<?= ie($key) ?>" <?= $filters['portal']===$key?'selected':'' ?>><?= ie($label) ?></option><?php endforeach; ?></select></label><label class="check-row"><input type="checkbox" name="archived" <?= $showArchived?'checked':'' ?>><span><?= it('show_archived') ?></span></label><button class="primary-button"><?= it('filter') ?></button></form></section>
<div id="invoice-results">
<?php $shown=0; foreach (InvoiceAccounts::PORTALS as $portal=>$portalName): if ($filters['portal'] && $filters['portal']!==$portal) continue;
$portalAccounts=array_filter($accounts,fn($a)=>$a['portal']===$portal && ($showArchived || !$a['archived_at'])); if (!$portalAccounts) continue; $shown++; ?>
<section class="card"><h2><?= ie($portalName) ?></h2><div class="invoice-record-grid"><?php foreach ($portalAccounts as $a): ?><article class="invoice-record"><div class="invoice-record-heading"><h3><?= ie($a['label']) ?></h3><?php invoiceStatus($a['archived_at']?'archived':((int)$a['is_active']?'active':'inactive')); ?></div>
<?php $linked=$repository->activeProperties((int)$a['id']); if (count($linked)>1) unset($linked['account']); ?><p><?= ie(implode(', ',$linked)) ?></p>
<dl class="invoice-facts"><div><dt><?= it('access') ?></dt><dd><?php invoiceStatus($readiness[$a['id']]); ?></dd></div><div><dt><?= it('second_factor') ?></dt><dd><?= it('method_'.$a['auth_method']) ?></dd></div><div><dt><?= it('schedule') ?></dt><dd><?php invoiceStatus((int)$a['enabled']?'enabled':'disabled'); ?></dd></div><div><dt><?= it('last_success') ?></dt><dd><?= ie(invoiceTime($a['last_success'])) ?></dd></div></dl>
<div class="form-actions"><?php if ($isGerente): ?><a href="<?= ie(invoiceUrl('accounts',['edit'=>$a['id']])) ?>"><?= it('manage_account') ?></a><?php endif; ?><a href="<?= ie(invoiceUrl('documents',['account'=>$a['id'],'portal'=>$portal,'property'=>''])) ?>"><?= it('view_invoices') ?></a><a href="<?= ie(invoiceUrl('activity',['account'=>$a['id'],'portal'=>$portal,'property'=>''])) ?>"><?= it('view_activity') ?></a></div></article><?php endforeach; ?></div></section>
<?php endforeach; ?><?php if (!$shown): ?><section class="card"><p><?= it('no_accounts_filter') ?></p></section><?php endif; ?>
</div>
<?php endif; ?>
