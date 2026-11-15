<?php
declare(strict_types=1);
require dirname(__DIR__).'/deploy/prepare_receipt_viewer.php';
$root=sys_get_temp_dir().'/hub-viewer-'.bin2hex(random_bytes(8));mkdir($root,0700);
$package=dirname(__DIR__).'/invoice-runner/receipt-tools/node_modules/pdfjs-dist';
try {
    $count=prepareReceiptViewer($package,$root);
    if($count<100 || !is_file($root.'/pdfjs/6.4.299/build/pdf.worker.mjs'))throw new RuntimeException('missing viewer assets');
    if(!hash_equals(hash_file('sha256',$package.'/build/pdf.mjs'),hash_file('sha256',$root.'/pdfjs/6.4.299/build/pdf.mjs')))throw new RuntimeException('asset mismatch');
    if(prepareReceiptViewer($package,$root)!==$count)throw new RuntimeException('repeated preparation changed');
    unlink($root.'/pdfjs/6.4.299/build/pdf.mjs');symlink($root.'/pdfjs/6.4.299/LICENSE',$root.'/pdfjs/6.4.299/build/pdf.mjs');
    try{prepareReceiptViewer($package,$root);throw new RuntimeException('link accepted');}
    catch(RuntimeException $e){if($e->getMessage()!=='receipt_viewer_prepare_failed')throw $e;}
    echo "Receipt viewer assets: pinned version, matched worker, supporting assets and symlink rejection passed.\n";
}finally{
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($it as $file){if($file->isDir()&&!$file->isLink())rmdir($file->getPathname());else unlink($file->getPathname());}rmdir($root);
}
