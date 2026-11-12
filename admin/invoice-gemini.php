<?php
declare(strict_types=1);
$root=dirname(__DIR__);require $root.'/lib.php';
require_once $root.'/src/Invoices/InvoiceGemini.php';
require_once $root.'/src/Invoices/InvoiceService.php';
require_once $root.'/src/UI/SessionBar.php';
require_once $root.'/src/I18n/InvoiceText.php';
header('Cache-Control: no-store');header('Referrer-Policy: no-referrer');header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'self'; style-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
$config=require $root.'/config.php';
try {$pdo=database();$user=Auth::requireLogin($pdo,$config);InvoiceService::assertGerente($user);}
catch(Throwable $e){if($e->getCode()===401){header('Location: ../login.php');exit;}http_response_code(403);exit(InvoiceText::get('forbidden'));}
$t=static fn($k)=>htmlspecialchars(InvoiceText::get($k),ENT_QUOTES,'UTF-8');$esc=static fn($s)=>htmlspecialchars((string)$s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
$error='';$status=['configured'=>false,'requests'=>0,'tokens'=>0,'model'=>InvoiceGemini::MODEL];
try {
 $gemini=new InvoiceGemini(new InvoiceVault($config['invoices']['private_dir']));
 if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
  Csrf::validate($_POST['csrf_token']??null);
  if((int)$pdo->query("SELECT GET_LOCK('room_check_invoices',0)")->fetchColumn()!==1)throw new RuntimeException('worker_busy');
  try{$gemini->configure((string)($_POST['gemini_key']??''));unset($_POST['gemini_key']);Auth::audit($pdo,(int)$user['id'],'receipt_gemini_configured');}
  finally{$pdo->query("SELECT RELEASE_LOCK('room_check_invoices')");}
  header('Location: invoice-gemini.php',true,303);exit;
 }
 $status=$gemini->status();
}catch(Throwable $e){$error=isset(InvoiceText::TEXT[$e->getMessage()])?$e->getMessage():'receipt_failed';}
?>
<!doctype html><html lang="<?= $esc(Translator::locale()) ?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title><?= $t('gemini_title') ?> — Management Hub</title><link rel="stylesheet" href="assets/settings.css"><link rel="stylesheet" href="../assets/session.css"></head><body><main class="settings-shell">
<?php SessionBar::render($user,'..',Auth::hasPermission($pdo,$user,Auth::PERMISSION_USERS_MANAGE),Auth::hasPermission($pdo,$user,Auth::PERMISSION_PERMISSIONS_MANAGE)); ?>
<h1><?= $t('gemini_title') ?></h1><p><a href="receipts.php"><?= $t('receipt_title') ?></a></p><p><?= $t('gemini_help') ?></p>
<?php if($error):?><p class="alert" role="alert"><?= $t($error) ?></p><?php endif;?>
<section class="card"><p><?= $t($status['configured']?'gemini_configured':'gemini_not_configured') ?></p><p data-i18n-skip><?= $esc($status['model']) ?></p>
<p><?= $t('gemini_requests') ?>: <strong><?= (int)$status['requests'] ?></strong> | <?= $t('gemini_tokens') ?>: <strong><?= (int)$status['tokens'] ?></strong></p>
<form method="post" autocomplete="off"><input type="hidden" name="csrf_token" value="<?= $esc(Csrf::token()) ?>"><label><?= $t('gemini_key') ?><input type="password" name="gemini_key" autocomplete="new-password" required maxlength="204"></label><div class="form-actions"><button class="primary-button" type="submit"><?= $t('gemini_save') ?></button></div></form></section>
</main></body></html>
