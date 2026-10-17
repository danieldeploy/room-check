<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__) . '/src/Invoices/InvoiceVault.php';
$root = $argv[1] ?? '';
if ($root === '' || $root[0] !== '/' || preg_match('~/(?:public_html|\.\.?)(?:/|$)~', $root)) {
    fwrite(STDERR, "Usage: php deploy/setup_invoices.php /home/ACCOUNT/room-check-private/invoices\n");
    exit(1);
}
umask(0077);
if (!is_dir($root) && !mkdir($root, 0700, true)) {
    exit(1);
}
$vault = new InvoiceVault($root);
$keyPath = $vault->path('master.key');
if (!is_file($keyPath)) {
    $handle = fopen($keyPath, 'x');
    if ($handle === false || fwrite($handle, random_bytes(32)) !== 32) {
        throw new RuntimeException('Key creation failed.');
    }
    fclose($handle);
}
$vault->save('setup-check.enc', ['ok' => true]);
if ($vault->read('setup-check.enc') !== ['ok' => true]) {
    throw new RuntimeException('Vault verification failed.');
}
unlink($vault->path('setup-check.enc'));
fwrite(STDOUT, "Private vault ready. Existing key preserved. No portal credentials saved.\n");
