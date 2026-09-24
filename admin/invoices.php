<?php
declare(strict_types=1);
$root=dirname(__DIR__);
require $root.'/lib.php';
require_once $root.'/src/UI/SessionBar.php';
require_once $root.'/src/Invoices/InvoiceWorkspace.php';
require_once $root.'/src/Invoices/InvoiceAuth.php';
require_once $root.'/src/Invoices/InvoiceDrive.php';
require_once $root.'/src/Invoices/InvoiceRemoteAgent.php';
require_once $root.'/src/I18n/InvoiceText.php';
$config=require $root.'/config.php';
try {
    $pdo=database(); $currentUser=Auth::requirePermission($pdo,$config,Auth::PERMISSION_INVOICES_VIEW);
} catch (Throwable $e) {
    if ($e->getCode()===401) { header('Location: ../login.php'); exit; }
    http_response_code(403); exit(InvoiceText::get('forbidden'));
}
header('Cache-Control: no-store'); header('Referrer-Policy: no-referrer');
$isGerente=$currentUser['role']==='gerente';
$canRun=Auth::hasPermission($pdo,$currentUser,Auth::PERMISSION_INVOICES_RUN);
$tabs=['overview','documents','accounts','activity']; if ($isGerente) $tabs[]='settings';
$tab=(string)($_GET['tab'] ?? 'overview'); if (!in_array($tab,$tabs,true)) $tab='overview';
try { $filters=InvoiceWorkspace::filters($_GET); }
catch (Throwable) { http_response_code(400); exit(InvoiceText::get('invalid_request')); }
$period=$filters['period']; $editId=$isGerente ? max(0,(int)($_GET['edit'] ?? 0)) : 0;
$newAccount=$isGerente && isset($_GET['new']); $showArchived=isset($_GET['archived']);
$error=null; $settings=null; $vault=null; $accounts=[]; $properties=[]; $readiness=[]; $managers=[];
$driveSettings=[]; $notifications=[]; $editing=null;
$repository=new InvoiceAccounts($pdo); $service=new InvoiceService($pdo); $workspace=new InvoiceWorkspace($pdo);
try {
    $settings=$service->settings(); $accounts=$workspace->accounts($period,$filters['property']);
    try { $vault=new InvoiceVault($config['invoices']['private_dir']); } catch (Throwable) {}
    foreach ($accounts as $a) {
        $id=(int)$a['id']; $properties[$id]=$repository->properties($id);
        $readiness[$id]=InvoiceWorkspace::integration($a,$vault);
        if ($id===$editId) $editing=$a;
    }
    if ($editId && !$editing) throw new RuntimeException('invalid_request');
    if ($filters['account']) $repository->get($filters['account']);
    $driveSettings=$pdo->query('SELECT * FROM invoice_drive_settings WHERE id=1')->fetch(PDO::FETCH_ASSOC) ?: [];
    $notifications=$pdo->query('SELECT * FROM invoice_notification_settings WHERE id=1')->fetch(PDO::FETCH_ASSOC) ?: [];
    if ($isGerente) $managers=$pdo->query("SELECT id,display_name FROM users WHERE role='gerente' AND is_active=1 AND mobile IS NOT NULL AND mobile<>'' ORDER BY display_name")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $settings=null; $error=$e->getMessage()==='invalid_request' ? 'invalid_request' : 'migration_required'; }

if ($_SERVER['REQUEST_METHOD']==='POST') {
    $locked=false;
    try {
        Csrf::validate($_POST['csrf_token'] ?? null);
        if (!$settings) throw new RuntimeException('migration_required');
        $action=(string)($_POST['action'] ?? ''); $id=(int)($_POST['account_id'] ?? 0);
        $returnTab=$tab; $returnEdit=$editId; $message='saved';
        if (in_array($action,['collect','retry_batch','retry_task'],true)) {
            if (!$canRun) throw new RuntimeException('forbidden');
            $requestFilters=InvoiceWorkspace::filters($_POST);
            $month=$requestFilters['period']; $targets=[]; $retryOf=null;
            if ($action==='collect') {
                foreach ($accounts as $a) {
                    if ($id && (int)$a['id']!==$id) continue;
                    if ($requestFilters['portal']!=='' && $a['portal']!==$requestFilters['portal']) continue;
                    if (!(int)$a['is_active'] || $a['archived_at'] || $readiness[$a['id']]!=='ready') continue;
                    foreach ($repository->collectionProperties((int)$a['id']) as $property=>$_) {
                        if ($requestFilters['property']!=='' && $requestFilters['property']!==$a['id'].':'.$property) continue;
                        $targets[]=['account_id'=>(int)$a['id'],'property_id'=>(string)$property];
                    }
                }
            } else {
                $retryOf=$action==='retry_batch' ? (int)($_POST['batch_id'] ?? 0) : null;
                $tasks=$workspace->tasks(array_replace($requestFilters,['state'=>'']),false,$retryOf,PHP_INT_MAX,$action==='retry_task'?(int)($_POST['task_id'] ?? 0):null);
                foreach ($tasks as $task) {
                    if ($action==='retry_task' && (int)$task['id']!==(int)($_POST['task_id'] ?? 0)) continue;
                    if ($task['kind']!=='collect' || !in_array($task['state'],['failed','needs_auth'],true)) continue;
                    if (!$repository->active((int)$task['account_id']) || ($readiness[$task['account_id']] ?? '')!=='ready') continue;
                    if (!isset($repository->collectionProperties((int)$task['account_id'])[$task['property_id']])) continue;
                    $targets[]=['account_id'=>(int)$task['account_id'],'property_id'=>$task['property_id']];
                }
            }
            if (!$targets) throw new RuntimeException('no_ready_accounts');
            $service->collectBatch($targets,$month,(int)$currentUser['id'],(string)($_POST['request_key'] ?? ''),$action==='collect'?'manual':'retry',$retryOf);
            $filters=$requestFilters; $returnTab='activity'; $returnEdit=0; $message='requested';
        } else {
            InvoiceService::assertGerente($currentUser);
            // Match the worker lock for changes to access, account membership and private files.
            if (in_array($action,['create_account','account_details','archive_account','restore_account','credentials','sms_token','schedule','drive_settings','drive_test','drive_retry','agent_pair','agent_mode','agent_revoke'],true)) {
                if ((int)$pdo->query("SELECT GET_LOCK('room_check_invoices',0)")->fetchColumn()!==1) throw new RuntimeException('worker_busy');
                $locked=true;
                if ($vault && $vault->has('windows-agent.enc')) {
                    $remoteGuard=new InvoiceRemoteAgent($pdo,$config['invoices']);
                    $remoteGuard->maintenance();
                    if ($action!=='agent_revoke') $remoteGuard->assertIdle();
                }
            }
            if (in_array($action,['agent_pair','agent_mode','agent_revoke'],true)) {
                if (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS']==='off') throw new RuntimeException('https_required');
                if (!$vault) throw new RuntimeException('private_storage_unavailable');
                $agent=new InvoiceRemoteAgent($pdo,$config['invoices']);
                if ($action==='agent_pair') $_SESSION['invoice_agent_package']=$agent->pairEncrypted(trim((string)($_POST['agent_public_key'] ?? '')));
                elseif ($action==='agent_revoke') $agent->revoke();
                else $agent->setMode((string)($_POST['agent_mode'] ?? ''));
                $returnTab='settings'; $returnEdit=0;
            } elseif ($action==='create_account' || $action==='account_details') {
                $portal=$action==='create_account' ? (string)($_POST['portal'] ?? '') : $repository->get($id)['portal'];
                $label=trim((string)($_POST['label'] ?? ''));
                $props=InvoiceWorkspace::propertiesFromInput($_POST,$portal);
                if ($action==='create_account') {
                    if (!isset(InvoiceAccounts::PORTALS[$portal])) throw new RuntimeException('invalid_request');
                    $id=$repository->create($portal,$label,$props,'password');
                }
                $repository->updateDetails($id,$label,$props,isset($_POST['is_active']));
                $returnTab='accounts'; $returnEdit=$id;
            } elseif ($action==='archive_account' || $action==='restore_account') {
                $repository->setLifecycle($id,$action==='restore_account',$action==='archive_account');
                $returnTab='accounts'; $returnEdit=0;
            } elseif (in_array($action,['preflight','login','discover'],true)) {
                $props=$repository->collectionProperties($id);
                if (!$props) throw new RuntimeException('properties_required');
                if ($action==='discover' && (!$vault || (new InvoiceRemoteAgent($pdo,$config['invoices']))->mode()!=='windows')) throw new RuntimeException('agent_test_required');
                if ($action==='login' && $repository->get($id)['portal']==='booking'
                    && (!$vault || (new InvoiceRemoteAgent($pdo,$config['invoices']))->mode()!=='windows')) throw new RuntimeException('agent_test_required');
                $service->enqueue($action,(string)array_key_first($props),$period,(int)$currentUser['id'],null,$id);
                $returnTab='activity'; $returnEdit=0; $message='requested';
            } elseif ($action==='credentials' || $action==='sms_token') {
                if (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS']==='off') throw new RuntimeException('https_required');
                if (!$vault) throw new RuntimeException('private_storage_unavailable');
                if ($repository->lifecycle($id)['archived_at']) throw new RuntimeException('account_archived');
                if ($action==='credentials') $repository->saveCredentials($vault,$id,$_POST);
                else $_SESSION['invoice_device_token']=['account'=>$id,'token'=>(new InvoiceAuth($pdo,$vault))->rotateSmsToken($id)];
                $returnTab='accounts'; $returnEdit=$id;
            } elseif ($action==='schedule') {
                $a=$repository->get($id);
                if (isset($_POST['enabled']) && ($readiness[$id] ?? '')!=='ready') throw new RuntimeException('login_required');
                $service->saveSchedule(isset($_POST['enabled']),(int)($_POST['day'] ?? 0),(string)($_POST['time'] ?? ''),$id);
                $returnTab='accounts'; $returnEdit=$id;
            } elseif (in_array($action,['drive_settings','drive_test','drive_retry'],true)) {
                if (!$vault) throw new RuntimeException('private_storage_unavailable');
                $client=new InvoiceDriveClient($vault);
                if ($action==='drive_retry') {
                    (new InvoiceDrive($pdo,$vault,$client))->retry((int)($_POST['document_id'] ?? 0)); $returnTab='documents';
                } else {
                    $folder=trim((string)($_POST['folder_id'] ?? $driveSettings['folder_id'] ?? '')); InvoiceDriveClient::assertId($folder);
                    if ($action==='drive_test') {
                        $client->connect(); $meta=$client->metadata($folder);
                        if (!$meta || ($meta['trashed'] ?? true) || ($meta['mimeType'] ?? '')!=='application/vnd.google-apps.folder'
                            || !in_array('daniel.ciorcas@welcomehostel.pt',array_column($meta['owners'] ?? [],'emailAddress'),true)) throw new RuntimeException('drive_account_mismatch');
                    }
                    $pdo->prepare('UPDATE invoice_drive_settings SET folder_id=?,state=?,checked_at=? WHERE id=1')
                        ->execute([$folder,$action==='drive_test'?'ready':'configured',$action==='drive_test'?gmdate('Y-m-d H:i:s'):null]);
                    $returnTab='settings';
                }
            } elseif ($action==='notifications') {
                $recipient=(int)($_POST['recipient'] ?? 0); $template=trim((string)($_POST['template_name'] ?? ''));
                if ((isset($_POST['enabled']) || $recipient) && !in_array($recipient,array_map('intval',array_column($managers,'id')),true)) throw new RuntimeException('invalid_request');
                if (!preg_match('/\A[a-z0-9_]{1,100}\z/',$template)) throw new RuntimeException('invalid_request');
                $pdo->prepare('UPDATE invoice_notification_settings SET enabled=?,recipient_user_id=?,template_name=? WHERE id=1')
                    ->execute([(int)isset($_POST['enabled']),$recipient ?: null,$template]); $returnTab='settings';
            } else throw new RuntimeException('invalid_request');
        }
        Auth::audit($pdo,(int)$currentUser['id'],'invoices_'.$action,['account_id'=>$id]);
        $_SESSION['invoice_flash']=$message;
        header('Location: invoices.php?'.http_build_query(array_merge($filters,['tab'=>$returnTab,'edit'=>$returnEdit])),true,303);
        if ($locked) $pdo->query("SELECT RELEASE_LOCK('room_check_invoices')");
        exit;
    } catch (Throwable $e) { $error=isset(InvoiceText::TEXT[$e->getMessage()])?$e->getMessage():'worker_failed'; }
    finally { if ($locked) $pdo->query("SELECT RELEASE_LOCK('room_check_invoices')"); }
}
$flash=$_SESSION['invoice_flash'] ?? null; unset($_SESSION['invoice_flash']);
$device=$isGerente ? ($_SESSION['invoice_device_token'] ?? null) : null; unset($_SESSION['invoice_device_token']);
$agentPackage=$isGerente ? ($_SESSION['invoice_agent_package'] ?? null) : null; unset($_SESSION['invoice_agent_package'],$_SESSION['invoice_agent_token']);
$agentStatus=['mode'=>'local','paired'=>false];
if ($isGerente && $vault) {
    try { $agentStatus=(new InvoiceRemoteAgent($pdo,$config['invoices']))->status(); }
    catch (Throwable) { $agentStatus=['mode'=>'paused','paired'=>false]; }
}
$documents=[]; $batches=[]; $legacyTasks=[]; $stats=[]; $alerts=[]; $driveAlerts=[];
if ($settings) {
    if (in_array($tab,['overview','documents'],true)) $stats=$workspace->stats($filters);
    if ($tab==='documents') $documents=$workspace->documents($filters,max(1,(int)($_GET['before'] ?? PHP_INT_MAX)));
    if ($tab==='activity') {
        $batches=$workspace->batches($filters,max(1,(int)($_GET['before'] ?? PHP_INT_MAX)));
        $legacyTasks=$workspace->tasks($filters,true,null,max(1,(int)($_GET['task_before'] ?? PHP_INT_MAX)));
        if ($isGerente) {
            $s=$pdo->prepare('SELECT n.*,a.label AS account_label,t.period,t.result_code AS error_code FROM invoice_failure_alerts n JOIN invoice_tasks t ON t.id=n.task_id JOIN invoice_accounts a ON a.id=t.account_id WHERE t.period=? ORDER BY n.id DESC LIMIT 20');
            $s->execute([$period]); $alerts=$s->fetchAll(PDO::FETCH_ASSOC);
            $s=$pdo->prepare('SELECT n.*,a.label AS account_label FROM invoice_drive_alerts n JOIN invoice_accounts a ON a.id=n.account_id WHERE n.period=? ORDER BY n.id DESC LIMIT 20');
            $s->execute([$period]); $driveAlerts=$s->fetchAll(PDO::FETCH_ASSOC);
        }
    }
}
require __DIR__.'/partials/invoices/helpers.php';
$viewRoot=__DIR__.'/partials/invoices'; define('INVOICE_VIEW',true);
?>
<!doctype html><html lang="<?= ie(Translator::locale()) ?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex,nofollow"><title><?= it('title') ?> — <?= ie(PortalBrand::name()) ?></title><link rel="stylesheet" href="assets/settings.css"><link rel="stylesheet" href="../assets/session.css"><link rel="stylesheet" href="assets/invoices.css?v=<?= (int)filemtime(__DIR__.'/assets/invoices.css') ?>"><script src="assets/invoices.js?v=<?= (int)filemtime(__DIR__.'/assets/invoices.js') ?>" defer></script></head>
<body class="invoice-page"><main class="settings-shell">
<?php SessionBar::render($currentUser,'..',Auth::hasPermission($pdo,$currentUser,Auth::PERMISSION_USERS_MANAGE),Auth::hasPermission($pdo,$currentUser,Auth::PERMISSION_PERMISSIONS_MANAGE)); ?>
<header class="page-header"><h1><?= it('title') ?></h1><p><?= it('scope') ?></p></header>
<nav class="invoice-tabs" aria-label="<?= it('module_navigation') ?>"><?php foreach ($tabs as $navTab): ?><a href="<?= ie(invoiceUrl($navTab)) ?>" <?= $navTab===$tab?'aria-current="page"':'' ?>><?= it('tab_'.$navTab) ?></a><?php endforeach; ?></nav>
<?php if ($error): ?><div class="alert" role="alert" data-save-feedback="error"><?= it($error) ?></div><?php endif; ?>
<?php if ($flash): ?><div class="success" role="status" data-save-feedback="success"><?= it($flash) ?></div><?php endif; ?>
<?php if ($device): ?><div class="notice"><p><?= it('token_once') ?> <?= (int)$device['account'] ?></p><code class="invoice-secret"><?= ie($device['token']) ?></code></div><?php endif; ?>
<?php if ($agentPackage): ?><div class="notice"><label class="field"><span><?= it('agent_package') ?></span><textarea name="agent_encrypted_package" rows="6" readonly translate="no"><?= ie(json_encode($agentPackage,JSON_THROW_ON_ERROR)) ?></textarea></label><p><?= it('agent_package_note') ?></p></div><?php endif; ?>
<?php if ($settings): ?>
<?php if (strtotime(($settings['worker_seen_at'] ?? '').' UTC')<time()-300): ?><div class="notice"><?= it('worker_stale') ?></div><?php endif; ?>
<?php require $viewRoot.'/'.$tab.'.php'; ?>
<?php endif; ?></main></body></html>
