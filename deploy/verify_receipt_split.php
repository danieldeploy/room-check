<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/src/Invoices/InvoiceReceiptPdf.php';

/** Synthetic two-page document, no business data; tests the configured CLI runtime before release copies. */
function receiptSplitProbePdf(): string
{
    $objects=['<< /Type /Catalog /Pages 2 0 R >>',
        '<< /Type /Pages /Kids [3 0 R 4 0 R] /Count 2 >>',
        '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 200 300] /Resources << >> >>',
        '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 400 200] /Rotate 90 /Resources << >> >>'];
    $pdf="%PDF-1.4\n"; $offsets=[0];
    foreach ($objects as $n=>$object) { $offsets[]=strlen($pdf); $pdf.=($n+1)." 0 obj\n".$object."\nendobj\n"; }
    $xref=strlen($pdf); $pdf.="xref\n0 5\n0000000000 65535 f \n";
    foreach (array_slice($offsets,1) as $offset) $pdf.=sprintf('%010d 00000 n ',$offset)."\n";
    return $pdf."trailer\n<< /Size 5 /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF\n";
}
function verifyReceiptSplit(array $config): void
{
    $base=new InvoiceVault($config['private_dir']);
    $directory=$base->path('receipt-probe-'.bin2hex(random_bytes(12)));
    if (!mkdir($directory,0700)) throw new RuntimeException('receipt_split_failed');
    try {
        $vault=new InvoiceVault($directory); $splitter=new InvoiceReceiptPdf($vault,$config);
        $result=$splitter->split(receiptSplitProbePdf());
        if ($result['count']!==2 || count($result['pages'])!==2) throw new RuntimeException('receipt_split_failed');
        foreach ($result['pages'] as $page) {
            $single=$splitter->split((string)file_get_contents($vault->path('receipt-page-'.$page['sha256'].'.pdf')));
            if ($single!==['count'=>1,'pages'=>[]]) throw new RuntimeException('receipt_split_failed');
        }
    } finally {
        foreach (glob($directory.'/*')?:[] as $file) if (is_file($file) && !is_link($file)) unlink($file);
        rmdir($directory);
    }
}
if (PHP_SAPI==='cli' && realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__) {
    ini_set('display_errors','0');
    try {
        $app=$argv[1]??''; $config=(require $app.'/config.php')['invoices'];
        // Check the incoming splitter before replacing any application files.
        $config['runner_script']=dirname(__DIR__).'/invoice-runner/runner.mjs';
        verifyReceiptSplit($config);
        echo json_encode(['ok'=>true,'receipt_pdf_split'=>'passed','pages'=>2])."\n";
    } catch (Throwable $e) {
        echo json_encode(['ok'=>false,'receipt_pdf_split'=>'failed','error'=>'receipt_split_unavailable'])."\n"; exit(1);
    }
}
