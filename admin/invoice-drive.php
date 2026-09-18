<?php
declare(strict_types=1);
$root = dirname(__DIR__);
require $root . '/lib.php';
require_once $root . '/src/Invoices/InvoiceService.php';
require_once $root . '/src/Invoices/InvoiceDriveClient.php';
require_once $root . '/src/I18n/InvoiceText.php';
header('Cache-Control: no-store'); header('Referrer-Policy: no-referrer');
try {
    $config = require $root . '/config.php'; $pdo = database();
    $user = Auth::requirePermission($pdo, $config, Auth::PERMISSION_INVOICES_VIEW);
    InvoiceService::assertGerente($user);
    if (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] === 'off') throw new RuntimeException('https_required');
    $client = new InvoiceDriveClient(new InvoiceVault($config['invoices']['private_dir']));
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        Csrf::validate($_POST['csrf_token'] ?? null);
        $state = bin2hex(random_bytes(32));
        $_SESSION['invoice_drive_oauth'] = ['state'=>$state,'created'=>time(),'user'=>(int)$user['id']];
        header('Location: ' . $client->authorizationUrl($state), true, 303); exit;
    }
    $pending = $_SESSION['invoice_drive_oauth'] ?? []; unset($_SESSION['invoice_drive_oauth']);
    if (empty($pending['state']) || !is_string($_GET['state'] ?? null) || !hash_equals($pending['state'], $_GET['state'])
        || time()-$pending['created']>600 || $pending['user']!==(int)$user['id'] || !is_string($_GET['code'] ?? null)) throw new RuntimeException('invalid_request');
    if ((int)$pdo->query("SELECT GET_LOCK('room_check_invoices',0)")->fetchColumn()!==1) throw new RuntimeException('worker_busy');
    try {
        $client->finishAuthorization($_GET['code']);
        // Create the destination with drive.file scope; no access to unrelated Drive files.
        $settings=$pdo->query('SELECT * FROM invoice_drive_settings WHERE id=1')->fetch(PDO::FETCH_ASSOC);
        if (empty($settings['folder_id'])) {
            $client->connect(); $folder=$client->newId();
            $client->api('POST','files?fields=id',['id'=>$folder,'name'=>'Management Hub - Faturas','mimeType'=>'application/vnd.google-apps.folder']);
            $pdo->prepare('UPDATE invoice_drive_settings SET folder_id=? WHERE id=1')->execute([$folder]);
        }
        $pdo->prepare("UPDATE invoice_drive_settings SET state='configured',checked_at=? WHERE id=1")->execute([gmdate('Y-m-d H:i:s')]);
        Auth::audit($pdo,(int)$user['id'],'invoices_drive_connected',[]);
    } finally { $pdo->query("SELECT RELEASE_LOCK('room_check_invoices')"); }
    $_SESSION['invoice_flash']='saved'; header('Location: invoices.php',true,303);
} catch (Throwable $e) {
    http_response_code(400);
    echo htmlspecialchars(InvoiceText::get(isset(InvoiceText::TEXT[$e->getMessage()])?$e->getMessage():'drive_auth'),ENT_QUOTES,'UTF-8');
    echo '<p><a href="invoices.php">Management Hub</a></p>';
}
