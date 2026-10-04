<?php if (!defined('INVOICE_VIEW') || !$isGerente) { http_response_code(404); exit; } ?>
<section class="card"><h2><?= it('agent_title') ?></h2><p><?= it('agent_description') ?></p>
<p><?= it('agent_last_seen') ?>: <?= ie(invoiceTime($agentStatus['last_seen'] ?? null)) ?></p>
<p><?= it('agent_last_probe') ?>: <?= ie(invoiceTime($agentStatus['probe_at'] ?? null)) ?> — <?= ($agentStatus['probe_code'] ?? '')==='ok' ? it('browser_ready') : it('preflight_required') ?></p>
<form method="post"><?php invoiceHidden('agent_mode'); ?><label class="field"><span><?= it('agent_mode_label') ?></span><select name="agent_mode"><?php foreach (['local','paused','windows'] as $mode): ?><option value="<?= ie($mode) ?>" <?= $agentStatus['mode']===$mode?'selected':'' ?>><?= it('agent_mode_'.$mode) ?></option><?php endforeach; ?></select></label><button class="primary-button" <?= !$vault?'disabled':'' ?>><?= it('save') ?></button></form>
<details class="invoice-options"><summary><?= it('agent_setup') ?></summary><p><?= it('agent_pair_note') ?></p>
<form method="post"><?php invoiceHidden('agent_pair'); ?><label class="field"><span><?= it('agent_public_key') ?></span><textarea name="agent_public_key" rows="5" maxlength="8192" required spellcheck="false" translate="no"></textarea></label><p><?= it('agent_public_key_note') ?></p><button class="invoice-secondary" <?= !$vault?'disabled':'' ?>><?= it('agent_pair') ?></button></form>
<?php if ($agentStatus['paired']): ?><form method="post"><?php invoiceHidden('agent_revoke'); ?><button class="invoice-secondary"><?= it('agent_revoke') ?></button></form><?php endif; ?>
</details></section>
<section class="card"><h2><?= it('drive') ?></h2><p class="invoice-destination">daniel.ciorcas@welcomehostel.pt</p><div class="invoice-status-line"><?php invoiceStatus($driveSettings['state'] ?? 'not_configured',match($driveSettings['state'] ?? ''){'ready'=>'drive_connection_ready','configured'=>'drive_connection_pending',default=>'drive_not_configured'}); ?></div>
<?php $driveClientConfigured=$vault && (new InvoiceDriveSetup($vault))->clientConfigured();
$driveFolderConfigured=preg_match('/\A[a-zA-Z0-9_-]{10,128}\z/',(string)($driveSettings['folder_id']??''))===1;
$driveCanConnect=$driveClientConfigured && $driveFolderConfigured;
if (!$driveClientConfigured): ?><p><?= it('drive_setup_pending') ?></p><?php elseif (!$driveFolderConfigured): ?><p><?= it('drive_folder_pending') ?></p><?php endif; ?>
<p><a href="about.php?page=privacy&amp;lang=<?= Translator::locale() === 'en' ? 'en' : 'pt' ?>"><?= ie(Translator::localized('Política de privacidade — Google Drive', 'Privacy policy — Google Drive')) ?></a></p>
<form method="post" action="invoice-drive.php"><?php invoiceHidden('connect'); ?><button class="primary-button" <?= !$driveCanConnect?'disabled':'' ?>><?= it('drive_connect') ?></button></form>
<details class="invoice-options" <?= !$driveCanConnect?'open':'' ?>><summary><?= it('drive_manage') ?></summary>
<?php if (!$driveClientConfigured): ?>
<p><?= it('drive_client_note') ?></p><p><?= it('drive_client_callback') ?>: <code translate="no"><?= ie(InvoiceDriveClient::CALLBACK) ?></code></p>
<form method="post" enctype="multipart/form-data"><?php invoiceHidden('drive_client'); ?><label class="field"><span><?= it('drive_client_file') ?></span><input type="file" name="drive_oauth_json" accept=".json,application/json" required aria-describedby="drive-client-private"></label><p id="drive-client-private"><?= it('drive_client_private') ?></p><button class="primary-button" <?= !$vault?'disabled':'' ?>><?= it('drive_client_import') ?></button></form>
<?php endif; ?>
<form method="post"><?php invoiceHidden('drive_settings'); ?><fieldset><label class="field"><span><?= it('folder_id') ?></span><input type="text" name="folder_id" value="<?= ie($driveSettings['folder_id'] ?? '') ?>" maxlength="128" required></label><div class="form-actions"><button class="primary-button" name="action" value="drive_settings"><?= it('save') ?></button><button class="primary-button" name="action" value="drive_test" <?= !$driveClientConfigured?'disabled':'' ?>><?= it('drive_test') ?></button></div></fieldset></form><p><?= it('drive_setup_note') ?></p></details>
<details><summary><?= it('storage_rules') ?></summary><p><?= it('drive_note') ?></p></details></section>
<section class="card"><h2>TOConline</h2><p><?= it('toc_email_note') ?></p>
<p><?= it(!empty($tocSettings['enabled'])?'toc_auto_enabled':'toc_auto_disabled') ?></p>
<form method="post"><?php invoiceHidden('toc_settings'); ?><div class="form-grid">
<label class="field"><span><?= it('toc_nif') ?></span><input name="toc_nif" inputmode="numeric" pattern="[0-9]{9}" maxlength="9" value="<?= ie($tocSettings['nif']??'') ?>" required></label>
<label class="field"><span><?= it('toc_sender') ?></span><input type="email" name="toc_sender" maxlength="190" value="<?= ie($tocSettings['sender']??'') ?>" required></label></div>
<p><?= it('toc_sender_note') ?></p>
<label class="check-row"><input type="checkbox" name="toc_authorized" required><span><?= it('toc_authorized') ?></span></label>
<label class="check-row"><input type="checkbox" name="toc_enabled" <?= !empty($tocSettings['enabled'])?'checked':'' ?> <?= empty($tocSettings['pilot_verified'])?'disabled':'' ?>><span><?= it('toc_enable') ?></span></label>
<p><?= it('toc_pilot_note') ?></p><button class="primary-button" <?= !$vault?'disabled':'' ?>><?= it('save') ?></button></form>
<details><summary><?= it('processing_details') ?></summary><p><?= it('toc_limits') ?></p><p><?= it('airbnb_pipeline') ?></p></details></section>
<?php
require_once dirname(__DIR__,3).'/src/Invoices/InvoiceTocPipe.php';
$pipeStatus=null;
try { if ($vault) $pipeStatus=(new InvoiceTocPipe($vault))->status(); } catch (Throwable) {}
?>
<section class="card"><h2><?= it('toc_pipe_0') ?></h2>
<p><?= it('toc_pipe_1') ?></p>
<p><code translate="no">room-check-private/cron/toconline-reply.php</code></p>
<?php if ($pipeStatus): ?>
<p><?= it('toc_pipe_2') ?>: <?= ie(invoiceTime($pipeStatus['received_at'])) ?></p>
<dl class="invoice-facts"><div><dt><?= it('toc_pipe_3') ?></dt><dd><?= (int)$pipeStatus['pending'] ?></dd></div><div><dt><?= it('toc_pipe_4') ?></dt><dd><?= (int)$pipeStatus['matched'] ?></dd></div><div><dt><?= it('toc_pipe_5') ?></dt><dd><?= (int)$pipeStatus['unmatched'] ?></dd></div></dl>
<?php else: ?><p><?= it('toc_pipe_6') ?></p><?php endif; ?>
<p><?= it('toc_pipe_7') ?></p>
</section>
<section class="card"><h2><?= it('toc_mail_title') ?></h2><p><?= it('toc_mail_policy') ?></p>
<p><?= it(!empty($tocMailbox['enabled'])?'toc_mail_enabled':'toc_mail_disabled') ?></p>
<?php if (empty($tocMailbox['available'])): ?><p role="status"><?= it('toc_mail_unavailable') ?></p><?php endif; ?>
<?php if (!empty($tocMailbox['error'])): ?><p role="alert"><?= it($tocMailbox['error']) ?></p><?php endif; ?>
<p><?= it('toc_mail_checked') ?>: <?= ie(invoiceTime($tocMailbox['checked_at']??null)) ?></p>
<p><?= it('toc_mail_unmatched') ?>: <?= (int)($tocMailbox['unmatched']??0) ?></p>
<form method="post"><?php invoiceHidden('toc_mailbox'); ?>
<p><?= ie($tocSettings['sender']??'') ?> · server50.romania-webhosting.com · TLS</p>
<label class="field"><span><?= it('toc_mail_password') ?></span><input type="password" name="toc_mail_password" autocomplete="new-password" maxlength="1024" <?= empty($tocMailbox['configured'])?'required':'' ?>></label>
<label class="field"><span><?= it('toc_mail_folders') ?></span><textarea name="toc_mail_folders" rows="3" required><?= ie(implode("\n",$tocMailbox['folders']??['INBOX','INBOX.Contar Mais'])) ?></textarea></label>
<label class="check-row"><input type="checkbox" name="toc_mail_enabled" <?= !empty($tocMailbox['enabled'])?'checked':'' ?>><span><?= it('toc_mail_enable') ?></span></label>
<button class="primary-button" <?= empty($tocMailbox['available'])?'disabled':'' ?>><?= it('toc_mail_test_save') ?></button></form>
<?php if (!empty($tocMailbox['enabled'])): ?><form method="post"><?php invoiceHidden('toc_mail_poll'); ?><button class="invoice-secondary"><?= it('toc_mail_check_now') ?></button></form><?php endif; ?>
</section>
<section class="card"><h2><?= it('failure_alerts') ?></h2><div class="invoice-status-line"><?php invoiceStatus(!empty($notifications['enabled'])?'enabled':'disabled',!empty($notifications['enabled'])?'alerts_enabled':'alerts_disabled'); ?></div><p><?= it('alerts_summary') ?></p>
<form method="post"><?php invoiceHidden('notifications'); ?><div class="form-grid"><label class="field"><span><?= it('manager') ?></span><select name="recipient"><option value=""><?= it('select_manager') ?></option><?php foreach ($managers as $manager): ?><option value="<?= (int)$manager['id'] ?>" <?= (int)($notifications['recipient_user_id'] ?? 0)===(int)$manager['id']?'selected':'' ?>><?= ie($manager['display_name']) ?></option><?php endforeach; ?></select></label><label class="check-row"><input type="checkbox" name="enabled" <?= !empty($notifications['enabled'])?'checked':'' ?>><span><?= it('enable_alerts') ?></span></label></div>
<details><summary><?= it('advanced_template') ?></summary><label class="field"><span><?= it('template_name') ?></span><input type="text" name="template_name" value="<?= ie($notifications['template_name'] ?? 'invoice_collection_failed_v1') ?>" pattern="[a-z0-9_]+" required></label><p><?= it('alerts_note') ?></p><p><?= it('template_approval_note') ?></p></details><button class="primary-button"><?= it('save_alerts') ?></button></form></section>
<section class="card"><details><summary><?= it('technical_diagnostics') ?></summary><p><?= $service->browserReady()?it('browser_ready'):it('preflight_required') ?></p><p><?= it('worker_last_seen') ?>: <?= ie(invoiceTime($settings['worker_seen_at'] ?? null)) ?></p><p><?= it('browser_last_check') ?>: <?= ie(invoiceTime($settings['browser_checked_at'] ?? null)) ?></p>
<?php if ($accounts): ?><form method="post"><?php invoiceHidden('preflight',(int)$accounts[0]['id']); ?><button class="invoice-secondary"><?= it('preflight') ?></button></form><?php endif; ?></details></section>



