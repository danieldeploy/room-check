<?php
declare(strict_types=1);
$root=dirname(__DIR__); require $root.'/lib.php';
require_once $root.'/src/Invoices/InvoiceAzure.php';
require_once $root.'/src/Invoices/InvoiceReceiptPdf.php';
require_once $root.'/src/Invoices/InvoiceService.php';
require_once $root.'/src/UI/SessionBar.php';
require_once $root.'/src/I18n/InvoiceText.php';
header('Cache-Control: no-store'); header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff'); header("Content-Security-Policy: default-src 'self'; style-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
$config=require $root.'/config.php';
try { $pdo=database(); $user=Auth::requireLogin($pdo,$config); InvoiceService::assertGerente($user); }
catch (Throwable $e) { if ($e->getCode()===401) { header('Location: ../login.php'); exit; } http_response_code(403); exit(InvoiceText::get('forbidden')); }
$t=static fn($k)=>htmlspecialchars(InvoiceText::get($k),ENT_QUOTES,'UTF-8');
$esc=static fn($s)=>htmlspecialchars((string)$s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
$error=''; $tests=[]; $configured=false;
try {
    $vault=new InvoiceVault($config['invoices']['private_dir']); $azure=new InvoiceAzure($vault);
    if (($_SERVER['REQUEST_METHOD']??'GET')==='POST') {
        Csrf::validate($_POST['csrf_token']??null);
        $lock=fopen($vault->path('azure-diagnostics.lock'),'c'); if (!$lock) throw new RuntimeException('worker_busy');
        chmod($vault->path('azure-diagnostics.lock'),0600);
        try {
            if (!flock($lock,LOCK_EX|LOCK_NB)) throw new RuntimeException('worker_busy');
            $action=$_POST['action']??'';
            if ($action==='configure') { $azure->configure((string)($_POST['azure_key']??'')); unset($_POST['azure_key']); }
            elseif ($action==='poll') $azure->poll((string)($_POST['id']??''));
            elseif ($action==='test') {
                $f=$_FILES['document']??[];
                if (($f['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name']??'')
                    || ($f['size']??0)>4*1024*1024) throw new RuntimeException('azure_limits');
                $bytes=file_get_contents($f['tmp_name']); $mime=''; $pages=1;
                if (is_string($bytes) && str_starts_with($bytes,'%PDF-')) {
                    $mime='application/pdf'; $manifest=(new InvoiceReceiptPdf($vault,$config['invoices']))->split($bytes,false); $pages=$manifest['count'];
                } elseif (is_string($bytes) && (@getimagesizefromstring($bytes)[2]??null)===IMAGETYPE_JPEG) $mime='image/jpeg';
                if ($mime==='' || $pages>2) throw new RuntimeException('azure_limits');
                $azure->start($bytes,$mime,$pages,(string)($_POST['label']??''),(int)$user['id']); unset($bytes);
            } else throw new RuntimeException('invalid_request');
            Auth::audit($pdo,(int)$user['id'],'azure_diagnostic_'.$action); // No keys, names, fields or document bytes.
        } finally { flock($lock,LOCK_UN); fclose($lock); }
        header('Location: invoice-azure.php',true,303); exit;
    }
    $configured=$azure->configured(); $tests=array_reverse($azure->tests(),true);
} catch (Throwable $e) {
    $error=isset(InvoiceText::TEXT[$e->getMessage()])?$e->getMessage():InvoiceAzure::safeError($e);
    if (isset($azure)) { $configured=$azure->configured(); $tests=array_reverse($azure->tests(),true); }
}
?>
<!doctype html><html lang="<?= $esc(Translator::locale()) ?>"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title><?= $t('azure_title') ?> — Management Hub</title><link rel="stylesheet" href="assets/settings.css"><link rel="stylesheet" href="../assets/session.css"></head><body><main class="settings-shell">
<?php SessionBar::render($user,'..',Auth::hasPermission($pdo,$user,Auth::PERMISSION_USERS_MANAGE),Auth::hasPermission($pdo,$user,Auth::PERMISSION_PERMISSIONS_MANAGE)); ?>
<h1><?= $t('azure_title') ?></h1><p><a href="receipts.php"><?= $t('receipt_title') ?></a></p>
<p><?= $t('azure_help') ?></p>
<?php if ($error): ?><p class="alert" role="alert"><?= $t($error) ?></p><?php endif; ?>
<section class="card"><h2><?= $t('azure_connection') ?></h2><p><?= $t($configured?'azure_configured':'azure_not_configured') ?></p><p data-i18n-skip><?= $esc(InvoiceAzure::ENDPOINT) ?></p>
<form method="post" autocomplete="off"><input type="hidden" name="csrf_token" value="<?= $esc(Csrf::token()) ?>"><input type="hidden" name="action" value="configure"><label><?= $t('azure_key') ?><input type="password" name="azure_key" autocomplete="new-password" required maxlength="128"></label><button type="submit"><?= $t('azure_save') ?></button></form></section>
<?php if ($configured): ?><section class="card"><h2><?= $t('azure_test') ?></h2><p><?= $t('azure_limits_help') ?></p><form method="post" enctype="multipart/form-data"><input type="hidden" name="csrf_token" value="<?= $esc(Csrf::token()) ?>"><input type="hidden" name="action" value="test"><label><?= $t('azure_label') ?><input type="text" name="label" required maxlength="160"></label><label><?= $t('azure_file') ?><input type="file" name="document" accept="application/pdf,image/jpeg" required></label><button type="submit"><?= $t('azure_submit') ?></button></form></section><?php endif; ?>
<?php foreach ($tests as $id=>$test): ?><article class="card"><h2 data-i18n-skip><?= $esc($test['label']) ?></h2><p><?= $t('azure_state_'.$test['state']) ?></p><p data-i18n-skip><?= $esc($test['created_at']) ?></p>
<?php if (!empty($test['error'])): ?><p class="alert"><?= $t($test['error']) ?></p><?php endif; ?>
<?php if ($test['state']==='running'): ?><form method="post"><input type="hidden" name="csrf_token" value="<?= $esc(Csrf::token()) ?>"><input type="hidden" name="action" value="poll"><input type="hidden" name="id" value="<?= $esc($id) ?>"><button type="submit"><?= $t('azure_poll') ?></button></form><?php endif; ?>
<?php foreach ($test['result']['documents']??[] as $doc): ?><p><?= $t('azure_pages') ?>: <span data-i18n-skip><?= $esc(implode(', ',$doc['pages'])) ?></span></p><table><thead><tr><th><?= $t('azure_field') ?></th><th><?= $t('azure_value') ?></th><th><?= $t('azure_confidence') ?></th><th><?= $t('azure_pages') ?></th></tr></thead><tbody>
<?php foreach ($doc['fields'] as $key=>$f): ?><tr><td><?= $t('azure_field_'.$key) ?></td><td data-i18n-skip><?= $esc($f['value']) ?></td><td data-i18n-skip><?= $esc(round($f['confidence']*100,1)) ?>%</td><td data-i18n-skip><?= $esc(implode(', ',$f['pages'])) ?></td></tr><?php endforeach; ?></tbody></table><?php endforeach; ?>
<?php foreach ($test['result']['pages']??[] as $p): ?><details><summary><?= $t('azure_page_text') ?> <span data-i18n-skip><?= (int)$p['number'] ?></span></summary><p><?= $t($p['continuation']?'azure_continuation':'azure_no_continuation') ?></p><pre data-i18n-skip><?= $esc(implode("\n",$p['lines'])) ?></pre></details><?php endforeach; ?>
</article><?php endforeach; ?></main></body></html>
