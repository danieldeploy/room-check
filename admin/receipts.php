<?php
declare(strict_types=1);
$root=dirname(__DIR__);
require $root.'/lib.php';
require_once $root.'/src/UI/SessionBar.php';
require_once $root.'/src/Invoices/InvoiceReceipts.php';
require_once $root.'/src/I18n/InvoiceText.php';
$config=require $root.'/config.php';
try { $pdo=database(); $user=Auth::requirePermission($pdo,$config,Auth::PERMISSION_RECEIPTS_VIEW); }
catch (Throwable $e) { if ($e->getCode()===401) { header('Location: ../login.php'); exit; } http_response_code(403); exit(InvoiceText::get('forbidden')); }
header('Cache-Control: no-store'); header('Referrer-Policy: no-referrer');
$manager=$user['role']==='gerente'; $canRun=Auth::hasPermission($pdo,$user,Auth::PERMISSION_RECEIPTS_RUN);
$state=['documents'=>[],'requested'=>false]; $error=''; $connected=false; $tocSettings=[];
try {
    $vault=new InvoiceVault($config['invoices']['private_dir']); $drive=new InvoiceDriveClient($vault);
    $toc=new InvoiceToconline($pdo,$vault,$drive); $receipts=new InvoiceReceipts($pdo,$vault,$drive,$toc);
    if (($_SERVER['REQUEST_METHOD']??'GET')==='POST') {
        Csrf::validate($_POST['csrf_token']??null);
        if (!$canRun) throw new RuntimeException('forbidden',403);
        if ((int)$pdo->query("SELECT GET_LOCK('room_check_invoices',0)")->fetchColumn()!==1) throw new RuntimeException('worker_busy');
        try {
            $action=(string)($_POST['action']??''); $id=(string)($_POST['id']??'');
            if ($action==='start') $receipts->request((int)$user['id'],(int)($_POST['year']??0),is_array($_POST['months']??null)?$_POST['months']:[]);
            elseif ($action==='retry') $receipts->retry($id);
            elseif ($action==='review' && $manager) $receipts->review($id,(string)($_POST['issued_on']??''),(string)($_POST['invoice_number']??''),($_POST['confirmed']??'')==='1',(int)$user['id']);
            else throw new RuntimeException('forbidden',403);
            Auth::audit($pdo,(int)$user['id'],'receipt_'.$action); // No filename, OCR text or invoice data in audit logs.
        } finally { $pdo->query("SELECT RELEASE_LOCK('room_check_invoices')"); }
        $_SESSION['receipt_flash']='receipt_queued'; header('Location: receipts.php',true,303); exit;
    }
    $state=$receipts->status(); $connected=$drive->receiptsAuthorized(); $tocSettings=$toc->settings();
} catch (Throwable $e) { $error=isset(InvoiceText::TEXT[$e->getMessage()])?$e->getMessage():'receipt_failed'; if ($e->getCode()===403) http_response_code(403); }
$escape=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
$t=static fn($key)=>htmlspecialchars(InvoiceText::get($key),ENT_QUOTES,'UTF-8');
$flash=$_SESSION['receipt_flash']??''; unset($_SESSION['receipt_flash']);
$year=(int)($state['year']??(new DateTimeImmutable('now',new DateTimeZone('Europe/Lisbon')))->format('Y'));
$monthEnglish=[1=>'January','February','March','April','May','June','July','August','September','October','November','December'];
$page=max(1,(int)($_GET['page']??1)); $all=array_reverse($state['documents'],true); $documents=array_slice($all,($page-1)*50,50,true);
?>
<!doctype html><html lang="<?= $escape(Translator::locale()) ?>"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex,nofollow">
<title><?= $t('receipt_title') ?> — Management Hub</title>
<link rel="stylesheet" href="assets/settings.css?v=<?= (int)filemtime(__DIR__.'/assets/settings.css') ?>">
<link rel="stylesheet" href="../assets/session.css?v=<?= (int)filemtime($root.'/assets/session.css') ?>">
</head><body><main class="settings-shell">
<?php SessionBar::render($user,'..',Auth::hasPermission($pdo,$user,Auth::PERMISSION_USERS_MANAGE),Auth::hasPermission($pdo,$user,Auth::PERMISSION_PERMISSIONS_MANAGE)); ?>
<header class="page-header compact-page-header"><div><h1 class="page-title"><?= $t('receipt_title') ?></h1><p><?= $t('receipt_description') ?></p></div></header>
<?php if ($error || !empty($state['error'])): ?><div class="alert" role="alert"><?= $t($error?:$state['error']) ?></div><?php endif; ?>
<?php if ($flash): ?><div class="success" role="status" data-save-feedback="success"><?= $t($flash) ?></div><?php endif; ?>
<section class="card"><p><?= $t('receipt_help') ?></p><p><?= $t('receipt_duplicates') ?></p>
<?php if (!$connected): ?><div class="alert"><?= $t('receipt_connect') ?></div>
<?php if ($manager): ?><p><?= $t('receipt_scope') ?></p><form method="post" action="invoice-drive.php"><input type="hidden" name="csrf_token" value="<?= $escape(Csrf::token()) ?>"><input type="hidden" name="receipts" value="1"><button class="primary-button" type="submit"><?= $t('receipt_connect_button') ?></button></form><?php endif; ?>
<?php elseif ($canRun): ?><form method="post"><input type="hidden" name="csrf_token" value="<?= $escape(Csrf::token()) ?>"><input type="hidden" name="action" value="start">
<label><?= $t('receipt_year') ?> <input type="number" name="year" min="2000" max="<?= (int)(new DateTimeImmutable('now',new DateTimeZone('Europe/Lisbon')))->format('Y') ?>" value="<?= $year ?>" required></label>
<fieldset><legend><?= $t('receipt_months') ?></legend><div class="form-grid">
<?php foreach (InvoiceDriveLayout::MONTHS as $month=>$name): ?><label class="check-row"><input type="checkbox" name="months[]" value="<?= $month ?>" <?= in_array($month,$state['months']??[],true)?'checked':'' ?>> <?= $escape(SiteTranslations::text($name,$monthEnglish[$month])) ?></label> <?php endforeach; ?>
</div></fieldset><div class="form-actions"><button class="primary-button" type="submit" <?= $state['requested']?'disabled':'' ?>><?= $t('receipt_start') ?></button></div></form><?php endif; ?>
<?php if (!empty($state['months'])): ?><p><?= $t('receipt_selection') ?>: <?= $year ?> · <?= $escape(implode(', ',array_map(static fn($m)=>SiteTranslations::text(InvoiceDriveLayout::MONTHS[$m],$monthEnglish[$m]),$state['months']))) ?></p><?php endif; ?>
<?php if (!empty($state['missing_periods'])): ?><p class="alert"><?= $t('receipt_missing_month') ?>: <?= $escape(implode(', ',$state['missing_periods'])) ?></p><?php endif; ?>
<?php if ($state['requested']): ?><p role="status"><?= $t('receipt_queued') ?></p><?php endif; ?>
<?php if (empty($tocSettings['enabled']) || empty($tocSettings['pilot_verified'])): ?><p class="alert"><?= $t('receipt_toc_disabled') ?></p><?php endif; ?>
<?php if ($manager): ?><p><?= $t('receipt_grant') ?> <a href="permissions.php"><?= $escape(SiteTranslations::text('Permissões','Permissions')) ?></a></p><?php endif; ?>
<p><a href="receipts.php?page=<?= $page ?>"><?= $t('receipt_refresh') ?></a></p>
</section>
<section class="card"><h2><?= $t('receipt_total') ?>: <?= count($all) ?></h2>
<?php if (!$documents): ?><p><?= $t('receipt_empty') ?></p><?php endif; ?>
<?php foreach ($documents as $id=>$d): ?>
<article class="card"><h3 data-i18n-skip><?= $escape($d['name']) ?></h3>
<p><a href="https://drive.google.com/file/d/<?= $escape(rawurlencode($id)) ?>/view" target="_blank" rel="noopener noreferrer"><?= $t('receipt_file') ?></a></p>
<p><?= $t('receipt_expected') ?>: <?= $escape($d['expected_period']??'') ?></p>
<p><?= $t('receipt_stage_'.$d['stage']) ?> · <?= $t($d['toconline_state']) ?></p>
<?php if (!empty($d['issued_on'])): ?><p><?= $t('receipt_date') ?>: <?= $escape($d['issued_on']) ?> · <?= $escape($d['invoice_number']) ?></p><?php endif; ?>
<?php if (!empty($d['error'])): ?><p class="alert"><?= $t($d['error']) ?></p><?php endif; ?>
<?php if ($canRun && $d['stage']==='failed'): ?><form method="post"><input type="hidden" name="csrf_token" value="<?= $escape(Csrf::token()) ?>"><input type="hidden" name="id" value="<?= $escape($id) ?>"><input type="hidden" name="action" value="retry"><button type="submit"><?= $t('receipt_retry') ?></button></form><?php endif; ?>
<?php if ($d['stage']==='review' && !empty($d['sha256'])): ?>
<?php if ($manager): ?><form method="post">
<input type="hidden" name="csrf_token" value="<?= $escape(Csrf::token()) ?>"><input type="hidden" name="id" value="<?= $escape($id) ?>"><input type="hidden" name="action" value="review">
<label><?= $t('receipt_date') ?> <input type="date" name="issued_on" required></label>
<label><?= $t('receipt_number') ?> <input type="text" name="invoice_number" maxlength="128" required></label>
<p data-i18n-skip>NIF: <?= $escape($tocSettings['nif']??'') ?></p>
<label><input type="checkbox" name="confirmed" value="1" required> <?= $t('receipt_confirm') ?></label>
<div class="form-actions"><button class="primary-button" type="submit"><?= $t('receipt_approve') ?></button></div>
</form><?php else: ?><p><?= $t('receipt_wait') ?></p><?php endif; ?>
<?php endif; ?></article>
<?php endforeach; ?>
<nav><?php if ($page>1): ?><a href="?page=<?= $page-1 ?>"><?= $escape(SiteTranslations::text('Anterior','Previous')) ?></a><?php endif; ?> <?php if ($page*50<count($all)): ?><a href="?page=<?= $page+1 ?>"><?= $escape(SiteTranslations::text('Seguinte','Next')) ?></a><?php endif; ?></nav>
</section></main></body></html>
