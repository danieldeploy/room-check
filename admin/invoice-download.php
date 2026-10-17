<?php
declare(strict_types=1);
$root = dirname(__DIR__);
require $root . '/lib.php';
require_once $root . '/src/Auth/Auth.php';
require_once $root . '/src/Invoices/InvoiceVault.php';
$config = require $root . '/config.php';
try {
    $pdo = database();
    $user = Auth::requirePermission($pdo, $config, Auth::PERMISSION_INVOICES_VIEW);
    $isNew = isset($_GET['document']);
    $id = filter_var($_GET['document'] ?? $_GET['id'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (!$id) throw new RuntimeException('not_found');
    $stmt = $pdo->prepare($isNew ? 'SELECT * FROM invoice_documents WHERE id = ?' : 'SELECT * FROM portal_invoices WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || !preg_match('/\A[a-f0-9]{64}\z/', $row['sha256'])) throw new RuntimeException('not_found');
    if ($isNew) {
        $delivery = $pdo->prepare("SELECT drive_id FROM invoice_document_delivery WHERE document_id=? AND drive_state='verified'");
        $delivery->execute([$id]); $driveId = $delivery->fetchColumn();
        if ($driveId && preg_match('/\A[a-zA-Z0-9_-]{10,128}\z/', $driveId)) {
            Auth::audit($pdo, (int) $user['id'], 'invoice_drive_open', ['invoice_id' => $id]);
            header('Location: https://drive.google.com/file/d/' . $driveId . '/view', true, 303); exit;
        }
    }
    $vault = new InvoiceVault($config['invoices']['private_dir']);
    $format = $isNew ? $row['format'] : 'pdf';
    if (!in_array($format, ['pdf', 'csv'], true)) throw new RuntimeException('not_found');
    $path = $vault->path($row['sha256'] . '.' . $format);
    if (!is_file($path) || !hash_equals($row['sha256'], (string) hash_file('sha256', $path))) throw new RuntimeException('not_found');
    Auth::audit($pdo, (int) $user['id'], 'invoice_download', ['invoice_id' => $id]);
    // Auth may have started the HTML translation buffer; it must never touch a PDF.
    while (ob_get_level() > 0) ob_end_clean();
    session_write_close();
    header('Content-Type: ' . ($format === 'pdf' ? 'application/pdf' : 'text/csv; charset=utf-8'));
    header('Content-Disposition: attachment; filename="invoice-' . $id . '.' . $format . '"');
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    header('Content-Length: ' . filesize($path));
    readfile($path);
} catch (Throwable $e) {
    http_response_code(in_array($e->getCode(), [401, 403], true) ? $e->getCode() : 404);
    header('Cache-Control: no-store');
}
