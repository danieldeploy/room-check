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
    $id = filter_var($_GET['id'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (!$id) throw new RuntimeException('not_found');
    $stmt = $pdo->prepare('SELECT * FROM portal_invoices WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || !preg_match('/\A[a-f0-9]{64}\z/', $row['sha256'])) throw new RuntimeException('not_found');
    $vault = new InvoiceVault($config['invoices']['private_dir']);
    $path = $vault->path($row['sha256'] . '.pdf');
    if (!is_file($path) || !hash_equals($row['sha256'], (string) hash_file('sha256', $path))) throw new RuntimeException('not_found');
    Auth::audit($pdo, (int) $user['id'], 'invoice_download', ['invoice_id' => $id]);
    // Auth may have started the HTML translation buffer; it must never touch a PDF.
    while (ob_get_level() > 0) ob_end_clean();
    session_write_close();
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="booking-' . $row['property_id'] . '-' . $row['period'] . '-' . $id . '.pdf"');
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    header('Content-Length: ' . filesize($path));
    readfile($path);
} catch (Throwable $e) {
    http_response_code(in_array($e->getCode(), [401, 403], true) ? $e->getCode() : 404);
    header('Cache-Control: no-store');
}
