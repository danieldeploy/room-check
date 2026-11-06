<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/deploy/verify_receipt_split.php';
$directory=sys_get_temp_dir().'/receipt-pdf-test-'.bin2hex(random_bytes(10)); mkdir($directory,0700);
function pdfAssert(bool $value,string $message): void { if (!$value) throw new RuntimeException($message); }
try {
    $config=['private_dir'=>$directory,'node_binary'=>getenv('TEST_NODE_BINARY')?:'/usr/bin/node','runner_script'=>dirname(__DIR__).'/invoice-runner/runner.mjs'];
    verifyReceiptSplit($config);
    pdfAssert(!glob($directory.'/*'),'release probe leaves no artifacts');
    $splitter=new InvoiceReceiptPdf(new InvoiceVault($directory),$config);
    $a=$splitter->split(receiptSplitProbePdf()); $b=$splitter->split(receiptSplitProbePdf());
    pdfAssert($a===$b,'retry reproduces identical page bytes');
    foreach ($a['pages'] as $page) pdfAssert((fileperms($directory.'/receipt-page-'.$page['sha256'].'.pdf')&0777)===0600,'page files private');
    try { $splitter->split('%PDF-1.4 invalid'); throw new LogicException('malformed PDF accepted'); } catch (RuntimeException $e) { pdfAssert($e->getMessage()==='receipt_split_failed','safe malformed failure'); }
    pdfAssert(!glob($directory.'/receipt-split-*'),'failed extraction cleans its workspace');
    try { (new InvoiceReceiptPdf(new InvoiceVault($directory),$config+[]))->split('not a PDF'); throw new LogicException('non PDF accepted'); } catch (RuntimeException $e) { pdfAssert($e->getMessage()==='receipt_split_failed','reject non PDF'); }
    $config['node_binary']='/missing-node';
    try { verifyReceiptSplit($config); throw new LogicException('release ignored missing runtime'); } catch (RuntimeException $e) { pdfAssert($e->getMessage()==='receipt_split_unavailable','release fails before copies'); }
    echo "Receipt PDF bridge and release probe passed\n";
} finally { foreach (glob($directory.'/*')?:[] as $file) unlink($file); rmdir($directory); }
