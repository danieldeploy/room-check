<?php
declare(strict_types=1);
$root=dirname(__DIR__);
require $root.'/lib.php';
require_once $root.'/src/UI/SessionBar.php';
require_once $root.'/src/Invoices/InvoiceReceipts.php';
require_once $root.'/src/Invoices/InvoiceReceiptReport.php';
require_once $root.'/src/I18n/InvoiceText.php';
$config=require $root.'/config.php';
try { $pdo=database(); $user=Auth::requireLogin($pdo,$config);
    if (!Auth::hasPermission($pdo,$user,Auth::PERMISSION_RECEIPTS_VIEW) && !Auth::hasPermission($pdo,$user,Auth::PERMISSION_RECEIPTS_RUN)) throw new RuntimeException('forbidden',403); }
catch (Throwable $e) { if ($e->getCode()===401) { header('Location: ../login.php'); exit; } http_response_code(403); exit(InvoiceText::get('forbidden')); }
header('Cache-Control: no-store'); header('Referrer-Policy: no-referrer');
$manager=$user['role']==='gerente'; $canRun=Auth::hasPermission($pdo,$user,Auth::PERMISSION_RECEIPTS_RUN);
$canHistory=Auth::hasPermission($pdo,$user,Auth::PERMISSION_RECEIPTS_VIEW); $canReview=Auth::hasPermission($pdo,$user,Auth::PERMISSION_RECEIPTS_REVIEW);
$state=['documents'=>[],'requested'=>false]; $error=''; $connected=false; $tocSettings=[];
try {
    $vault=new InvoiceVault($config['invoices']['private_dir']); $drive=new InvoiceDriveClient($vault);
    $toc=new InvoiceToconline($pdo,$vault,$drive); $receipts=new InvoiceReceipts($pdo,$vault,$drive,$toc);
    if (($_SERVER['REQUEST_METHOD']??'GET')==='POST') {
        Csrf::validate($_POST['csrf_token']??null);
        $action=(string)($_POST['action']??'');
        if (!(($action==='start' && $canRun) || ($action==='retry' && $canRun && $canHistory) || (in_array($action,['review','regroup'],true) && $canReview))) throw new RuntimeException('forbidden',403);
        if ((int)$pdo->query("SELECT GET_LOCK('room_check_invoices',0)")->fetchColumn()!==1) throw new RuntimeException('worker_busy');
        try {
            $action=(string)($_POST['action']??''); $id=(string)($_POST['id']??'');
            if ($action==='start' && $canRun) $receipts->request((int)$user['id'],(int)($_POST['year']??0),is_array($_POST['months']??null)?$_POST['months']:[]);
            elseif ($action==='retry' && $canRun && $canHistory) $receipts->retry($id);
            elseif ($action==='regroup' && $canReview) $receipts->regroup($id,(string)($_POST['page_groups']??''),($_POST['confirmed']??'')==='1',(int)$user['id']);
            elseif ($action==='review' && $canReview) $receipts->review($id,(string)($_POST['issued_on']??''),(string)($_POST['invoice_number']??''),($_POST['confirmed']??'')==='1',(int)$user['id'],(string)($_POST['issuer_name']??''));
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
$report=InvoiceReceiptReport::summary($state['documents']);
$page=max(1,(int)($_GET['page']??1)); $all=$canHistory?array_reverse(array_filter($state['documents'],static fn($d)=>($d['stage']??'')!=='superseded'),true):[]; $documents=array_slice($all,($page-1)*50,50,true);
$progress=InvoiceReceiptReport::progress($state,!empty($tocSettings['enabled']) && !empty($tocSettings['pilot_verified']));$historyHtml='';
$globalError=$error?:($state['error']??'');
$globalError=$globalError!==''?InvoiceText::get(isset(InvoiceText::TEXT[$globalError])?$globalError:'receipt_failed'):'';
if ($canHistory) { $receiptHistoryRendering=true; ob_start(); require __DIR__.'/receipt-history.php'; $historyHtml=(string)ob_get_clean(); }
if (($_GET['status']??'')==='1') {
    jsonResponse(['progress'=>$progress,'label'=>InvoiceText::get($progress['state']),'report'=>InvoiceReceiptReport::render($report),
        'history'=>$canHistory?$historyHtml:null,'error'=>$globalError,'revision'=>hash('sha256',json_encode($state['documents']))]);
}
?>
<!doctype html><html lang="<?= $escape(Translator::locale()) ?>"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex,nofollow">
<title><?= $t('receipt_title') ?> — Management Hub</title>
<link rel="stylesheet" href="assets/settings.css?v=<?= (int)filemtime(__DIR__.'/assets/settings.css') ?>">
<link rel="stylesheet" href="../assets/session.css?v=<?= (int)filemtime($root.'/assets/session.css') ?>">
<script src="assets/receipt-status.js?v=<?= (int)filemtime(__DIR__.'/assets/receipt-status.js') ?>" defer></script>
<script src="assets/receipt-preview.js?v=<?= (int)filemtime(__DIR__.'/assets/receipt-preview.js') ?>" defer></script>
</head><body><main class="settings-shell">
<?php SessionBar::render($user,'..',Auth::hasPermission($pdo,$user,Auth::PERMISSION_USERS_MANAGE),Auth::hasPermission($pdo,$user,Auth::PERMISSION_PERMISSIONS_MANAGE)); ?>
<?php if ($manager): ?><p><a href="invoice-gemini.php"><?= $t('gemini_title') ?></a> · <a href="invoice-azure.php"><?= $t('azure_title') ?></a></p><?php endif; ?>
<header class="page-header compact-page-header"><div><h1 class="page-title"><?= $t('receipt_title') ?></h1><p><?= $t('receipt_description') ?></p></div></header>
<div id="receipt-global-error" class="alert" role="alert" <?= $globalError===''?'hidden':'' ?>><?= $escape($globalError) ?></div>
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
<section class="card" id="receipt-status" role="status" data-url="receipts.php?status=1&amp;page=<?= $page ?>" data-state="<?= $escape($progress['state']) ?>" data-next="<?= $progress['next_at'] ?>" data-server="<?= $progress['server_time'] ?>" data-retry="<?= $t('receipt_retry_in') ?>" data-due="<?= $t('receipt_retry_due') ?>" data-unavailable="<?= $t('receipt_status_unavailable') ?>"><strong data-status-label><?= $t($progress['state']) ?></strong><span class="receipt-dots" aria-hidden="true">…</span><p data-status-warning hidden></p></section>
<div id="receipt-report"><?= InvoiceReceiptReport::render($report) ?></div>
<div id="receipt-history"><?= $historyHtml ?></div></main></body></html>
