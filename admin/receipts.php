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
        if (!((in_array($action,['start','retry_process'],true) && $canRun) || ($action==='retry' && $canRun && $canHistory) || (in_array($action,['review','regroup','repair','confirm_buyer','discard'],true) && $canReview))) throw new RuntimeException('forbidden',403);
        if ((int)$pdo->query("SELECT GET_LOCK('room_check_invoices',0)")->fetchColumn()!==1) throw new RuntimeException('worker_busy');
        try {
            $action=(string)($_POST['action']??''); $id=(string)($_POST['id']??'');
            if ($action==='start' && $canRun) $receipts->request((int)$user['id'],(int)($_POST['year']??0),is_array($_POST['months']??null)?$_POST['months']:[]);
            elseif ($action==='retry_process' && $canRun) $receipts->retryDiscovery((string)($_POST['process']??''));
            elseif ($action==='retry' && $canRun && $canHistory) $receipts->retry($id);
            elseif ($action==='discard' && $canReview) $receipts->requestDiscard($id,($_POST['discard_confirmed']??'')==='1',(int)$user['id']);
            elseif ($action==='repair' && $canReview) $receipts->requestRepair($id,($_POST['missing_added']??'')==='1',(int)$user['id']);
            elseif ($action==='regroup' && $canReview) $receipts->regroup($id,(string)($_POST['page_groups']??''),($_POST['confirmed']??'')==='1',(int)$user['id']);
            elseif (in_array($action,['review','confirm_buyer'],true) && $canReview) $receipts->review($id,(string)($_POST['issued_on']??''),(string)($_POST['invoice_number']??''),($_POST['confirmed']??'')==='1',(int)$user['id'],(string)($_POST['issuer_name']??''),(string)($_POST['issuer_nif']??''),($_POST['buyer_confirmed']??'')==='1');
            else throw new RuntimeException('forbidden',403);
            Auth::audit($pdo,(int)$user['id'],'receipt_'.$action); // No filename, OCR text or invoice data in audit logs.
        } finally { $pdo->query("SELECT RELEASE_LOCK('room_check_invoices')"); }
        $returnQuery=$action==='start'?['process'=>$receipts->status()['active_process']]:array_intersect_key($_GET,array_flip(['process','filter','process_year','process_month','page']));
        header('Location: receipts.php'.($returnQuery?'?'.http_build_query($returnQuery):''),true,303); exit;
    }
    $state=$receipts->status(); $connected=$drive->receiptsAuthorized(); $tocSettings=$toc->settings();
} catch (Throwable $e) { if (isset($receipts)) { try { $state=$receipts->status();$connected=$drive->receiptsAuthorized();$tocSettings=$toc->settings(); } catch (Throwable) {} } $error=isset(InvoiceText::TEXT[$e->getMessage()])?$e->getMessage():'receipt_failed'; if ($e->getCode()===403) http_response_code(403); }
$escape=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
$t=static fn($key)=>htmlspecialchars(InvoiceText::get($key),ENT_QUOTES,'UTF-8');
unset($_SESSION['receipt_flash']);
$year=(int)($state['year']??(new DateTimeImmutable('now',new DateTimeZone('Europe/Lisbon')))->format('Y'));
$monthEnglish=[1=>'January','February','March','April','May','June','July','August','September','October','November','December'];
$state=InvoiceReceiptReport::withProcesses($state);$fullState=$state;
$now=new DateTimeImmutable('now',new DateTimeZone('Europe/Lisbon'));
$periodDefault=$now;$linkedProcess=(string)($_GET['process']??'');$undatedLink=false;
if ($linkedProcess!=='' && isset($fullState['processes'][$linkedProcess]) && !isset($_GET['process_year']) && !isset($_GET['process_month'])) {
    try {$at=$fullState['processes'][$linkedProcess]['requested_at']??'';if ($at!=='') $periodDefault=(new DateTimeImmutable($at))->setTimezone(new DateTimeZone('Europe/Lisbon'));else $undatedLink=true;}
    catch (Exception) {$undatedLink=true;}
}
$processYear=filter_var($_GET['process_year']??($undatedLink?0:$periodDefault->format('Y')),FILTER_VALIDATE_INT);
$processMonth=filter_var($_GET['process_month']??($undatedLink?0:$periodDefault->format('n')),FILTER_VALIDATE_INT);
if ($processYear===false || ($processYear!==0 && ($processYear<2000 || $processYear>(int)$now->format('Y')))) $processYear=(int)$now->format('Y');
if ($processMonth===false || $processMonth<0 || $processMonth>12) $processMonth=(int)$now->format('n');
$visibleProcesses=InvoiceReceiptReport::processesForPeriod($fullState,$processYear,$processMonth);
$processId=isset($visibleProcesses[$linkedProcess])?$linkedProcess:(string)(array_key_last($visibleProcesses)??'');
$processYears=[(int)$now->format('Y')=>$now->format('Y')];
foreach ($fullState['processes'] as $p) if (!empty($p['requested_at'])) {try {$y=(int)(new DateTimeImmutable($p['requested_at']))->setTimezone(new DateTimeZone('Europe/Lisbon'))->format('Y');if ($y>=2000)$processYears[$y]=(string)$y;}catch(Exception){}}
if ($processYear) $processYears[$processYear]=(string)$processYear;krsort($processYears);

$state=InvoiceReceiptReport::selected($state,$processId);
$year=(int)($state['year']??$year);
$sendEnabled=!empty($tocSettings['enabled']) && !empty($tocSettings['pilot_verified']);
$progress=InvoiceReceiptReport::progress($state,$sendEnabled);
$selectedRunning=$visibleProcesses && InvoiceReceiptReport::processState($fullState,$processId,$sendEnabled)==='receipt_run_running';
$report=InvoiceReceiptReport::summary($state['documents']);
$defaultFilter=($report['pending'] || $report['errors'])?'problems':'success';
$filter=$selectedRunning?'all':(in_array($_GET['filter']??'',['all','success','problems'],true)?$_GET['filter']:$defaultFilter);
$processingMessage=strtr(InvoiceText::get('receipt_processing_months'),['{months}'=>implode(', ',array_map(static fn($m)=>SiteTranslations::text(InvoiceDriveLayout::MONTHS[$m],$monthEnglish[$m]),$state['months']??[])),'{year}'=>(string)$year]);
$selectionQuery=['process'=>$processId,'filter'=>$selectedRunning?'auto':$filter,'process_year'=>$processYear,'process_month'=>$processMonth];$query=http_build_query($selectionQuery);
$page=max(1,(int)($_GET['page']??1)); $all=$canHistory?array_reverse(InvoiceReceiptReport::documents($fullState,$processId,$filter),true):[]; $documents=array_slice($all,($page-1)*50,50,true);
$historyHtml='';
if (!$visibleProcesses) $progress['state']='receipt_no_runs';
$requestError=(($_SERVER['REQUEST_METHOD']??'GET')==='POST')?$error:'';
$globalError=($requestError===''?$error:'')?:($state['error']??'')?:($fullState['error']??'');
$globalError=$globalError!==''?InvoiceText::get(isset(InvoiceText::TEXT[$globalError])?$globalError:'receipt_failed'):'';
$canStart=InvoiceReceiptReport::canStart($fullState,!empty($tocSettings['enabled']) && !empty($tocSettings['pilot_verified']));
$globalProgress=InvoiceReceiptReport::progress($fullState,!empty($tocSettings['enabled']) && !empty($tocSettings['pilot_verified']));
$processing=(bool)$selectedRunning;
if (!$canStart && $globalProgress['poll_at']>0) $progress['poll_at']=$progress['poll_at']?min($progress['poll_at'],$globalProgress['poll_at']):$globalProgress['poll_at'];
$processLabels=[];
foreach (array_reverse($visibleProcesses,true) as $pid=>$process) {
    $at='—';try {if(!empty($process['requested_at'])) $at=(new DateTimeImmutable($process['requested_at']))->setTimezone(new DateTimeZone('Europe/Lisbon'))->format('d-m-Y H:i:s');}catch(Exception){}
    $processLabels[$pid]=$at.' · '.$process['year'].' · '.implode(', ',array_map(static fn($m)=>SiteTranslations::text(InvoiceDriveLayout::MONTHS[$m],$monthEnglish[$m]),$process['months'])).' · '.InvoiceText::get(InvoiceReceiptReport::processState($fullState,(string)$pid,!empty($tocSettings['enabled']) && !empty($tocSettings['pilot_verified'])));
}
$formYear=$year;$formMonths=$state['months']??[];
if ($requestError!=='' && ($_POST['action']??'')==='start') {
    $formYear=(int)($_POST['year']??$year);
    $formMonths=is_array($_POST['months']??null)?array_map('intval',array_filter($_POST['months'],'is_scalar')):[];
}
if ($canHistory) { $receiptHistoryRendering=true; ob_start(); require __DIR__.'/receipt-history.php'; $historyHtml=(string)ob_get_clean(); }
if (($_GET['status']??'')==='1') {
    jsonResponse(['progress'=>$progress,'label'=>InvoiceText::get($progress['state']),'report'=>InvoiceReceiptReport::render($report,!$canHistory,$progress),
        'history'=>$canHistory?$historyHtml:null,'error'=>$globalError,'can_start'=>$canRun && $canStart,'processing'=>$processing,'processing_message'=>$processingMessage,'selected_running'=>(bool)$selectedRunning,'filter'=>$filter,'process_labels'=>$processLabels,'revision'=>hash('sha256',json_encode([$state['documents'],$filter,$selectedRunning]))]);
}
?>
<!doctype html><html lang="<?= $escape(Translator::locale()) ?>"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex,nofollow">
<title><?= $t('receipt_title') ?> — Management Hub</title>
<link rel="stylesheet" href="assets/settings.css?v=<?= (int)filemtime(__DIR__.'/assets/settings.css') ?>">
<link rel="stylesheet" href="../assets/session.css?v=<?= (int)filemtime($root.'/assets/session.css') ?>">
<script src="assets/receipt-status.js?v=<?= (int)filemtime(__DIR__.'/assets/receipt-status.js') ?>" defer></script>
<script src="assets/receipt-preview.js?v=<?= (int)filemtime(__DIR__.'/assets/receipt-preview.js') ?>" defer></script>
<script src="assets/receipt-review.js?v=<?= (int)filemtime(__DIR__.'/assets/receipt-review.js') ?>" defer></script>
</head><body class="receipt-page"><main class="settings-shell">
<?php SessionBar::render($user,'..',Auth::hasPermission($pdo,$user,Auth::PERMISSION_USERS_MANAGE),Auth::hasPermission($pdo,$user,Auth::PERMISSION_PERMISSIONS_MANAGE)); ?>
<div id="receipt-content" data-i18n-skip>
<?php if ($manager): ?><p><a href="invoice-gemini.php"><?= $t('gemini_title') ?></a></p><?php endif; ?>
<header class="page-header compact-page-header"><div><h1 class="page-title"><?= $t('receipt_title') ?></h1></div></header>
<?php if ($requestError!==''): ?><div id="receipt-request-error" class="alert" role="alert" tabindex="-1" data-save-feedback="error"><?= $t($requestError) ?></div><?php endif; ?>
<div id="receipt-global-error" class="alert" role="alert" <?= $globalError===''?'hidden':'' ?>><?= $escape($globalError) ?></div>

<section class="card"><details class="document-disclosure"><summary><?= $t('receipt_instructions') ?></summary><div class="receipt-instructions">
<?php foreach (['scan','start','review','missing','done'] as $instruction): ?><p><?= $t('receipt_instruction_'.$instruction) ?></p><?php endforeach; ?>
</div></details>
<p class="receipt-start-help"><?= $t('receipt_description') ?></p>
<?php if (!$connected): ?><div class="alert"><?= $t('receipt_connect') ?></div>
<?php if ($manager): ?><p><?= $t('receipt_scope') ?></p><form method="post" action="invoice-drive.php"><input type="hidden" name="csrf_token" value="<?= $escape(Csrf::token()) ?>"><input type="hidden" name="receipts" value="1"><button class="primary-button" type="submit"><?= $t('receipt_connect_button') ?></button></form><?php endif; ?>
<?php elseif ($canRun): ?><form method="post"><input type="hidden" name="csrf_token" value="<?= $escape(Csrf::token()) ?>"><input type="hidden" name="action" value="start"><fieldset class="receipt-intake" data-receipt-intake <?= !$canStart?'disabled':'' ?>>
<label class="field receipt-year"><span><?= $t('receipt_year') ?></span><input type="number" name="year" min="2000" max="<?= (int)(new DateTimeImmutable('now',new DateTimeZone('Europe/Lisbon')))->format('Y') ?>" value="<?= $formYear ?>" required></label>
<fieldset class="receipt-months"><legend class="field-label"><?= $t('receipt_months') ?></legend><div class="receipt-month-row">
<?php foreach (InvoiceDriveLayout::MONTHS as $month=>$name): ?><label class="receipt-month"><input type="checkbox" name="months[]" value="<?= $month ?>" <?= in_array($month,$formMonths,true)?'checked':'' ?>> <span><?= $escape(SiteTranslations::text($name,$monthEnglish[$month])) ?></span></label> <?php endforeach; ?>
</div></fieldset><div class="form-actions"><button class="primary-button" type="submit" data-receipt-start <?= !$canStart?'disabled':'' ?>><?= $t('receipt_start') ?></button></div></fieldset></form><?php endif; ?>
<?php if ($canRun && (!empty($state['missing_periods']) || !empty($state['error']))): ?><form method="post"><input type="hidden" name="csrf_token" value="<?= $escape(Csrf::token()) ?>"><input type="hidden" name="action" value="retry_process"><input type="hidden" name="process" value="<?= $escape($processId) ?>"><button class="primary-button" type="submit"><?= $t('receipt_retry') ?></button></form><?php endif; ?>
<?php if (!empty($state['missing_periods'])): ?><p class="alert"><?= $t('receipt_missing_month') ?>: <?= $escape(implode(', ',$state['missing_periods'])) ?></p><?php endif; ?>
<?php if (empty($tocSettings['enabled']) || empty($tocSettings['pilot_verified'])): ?><p class="alert"><?= $t('receipt_toc_disabled') ?></p><?php endif; ?>

</section>
<section class="card receipt-process-card">
<form method="get" id="receipt-process-form" class="receipt-process-filters">
<label class="field receipt-run-select"><span><?= $t('receipt_process') ?></span><select name="process" data-receipt-filter <?= !$visibleProcesses?'disabled':'' ?>>
<?php if (!$visibleProcesses): ?><option value=""><?= $t('receipt_no_runs') ?></option><?php endif; ?>
<?php foreach ($processLabels as $pid=>$label): ?>
<option value="<?= $escape($pid) ?>" <?= $pid===$processId?'selected':'' ?>><?= $escape($label) ?></option>
<?php endforeach; ?></select></label>
<div class="receipt-run-period">
<label class="field"><span><?= $t('receipt_run_year') ?></span><select name="process_year" data-receipt-filter data-receipt-period><option value="0" <?= !$processYear?'selected':'' ?>><?= $t('receipt_all_years') ?></option><?php foreach ($processYears as $y): ?><option value="<?= $escape($y) ?>" <?= (int)$y===$processYear?'selected':'' ?>><?= $escape($y) ?></option><?php endforeach; ?></select></label>
<label class="field"><span><?= $t('receipt_run_month') ?></span><select name="process_month" data-receipt-filter data-receipt-period><option value="0" <?= !$processMonth?'selected':'' ?>><?= $t('receipt_all_months') ?></option><?php foreach (InvoiceDriveLayout::MONTHS as $month=>$name): ?><option value="<?= $month ?>" <?= $month===$processMonth?'selected':'' ?>><?= $escape(SiteTranslations::text($name,$monthEnglish[$month])) ?></option><?php endforeach; ?></select></label>
</div>
<noscript><button class="primary-button" type="submit"><?= $t('receipt_refresh') ?></button></noscript></form>
<div id="receipt-processing-message" class="success" role="status" <?= $processing?'':'hidden' ?>><?= $escape($processingMessage) ?></div>
<?php if (!$visibleProcesses): ?><p><?= $t('receipt_no_runs') ?></p><?php endif; ?>
<div class="receipt-status-line" id="receipt-status" role="status" data-url="receipts.php?status=1&amp;<?= $escape($query) ?>&amp;page=<?= $page ?>" data-state="<?= $escape($progress['state']) ?>" data-poll="<?= $progress['poll_at'] ?>" data-next="<?= $progress['next_at'] ?>" data-server="<?= $progress['server_time'] ?>" data-retry="<?= $t('receipt_retry_in') ?>" data-due="<?= $t('receipt_retry_due') ?>" data-unavailable="<?= $t('receipt_status_unavailable') ?>"><button type="button" class="primary-button" data-receipt-refresh hidden><?= $t('receipt_refresh') ?></button><p data-status-warning hidden></p></div></section>
<?php if (!empty($state['source_cleanup_paused'])): ?><p><?= $t('receipt_cleanup_deferred') ?></p><?php endif; ?>
<div id="receipt-report"><?= InvoiceReceiptReport::render($report,!$canHistory,$progress) ?></div>
<div id="receipt-history"><?= $historyHtml ?></div></div></main></body></html>
