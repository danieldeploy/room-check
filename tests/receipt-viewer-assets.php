<?php
declare(strict_types=1);
require dirname(__DIR__).'/deploy/prepare_receipt_viewer.php';
$root=sys_get_temp_dir().'/hub-viewer-'.bin2hex(random_bytes(8));mkdir($root,0700);
$package=dirname(__DIR__).'/invoice-runner/receipt-tools/node_modules/pdfjs-dist';
$previousMask=umask(0077); // Match deploy/release.sh, not the CI shell default.
try {
    $count=prepareReceiptViewer($package,$root);
    $publicDirectories=['pdfjs','pdfjs/6.4.299','pdfjs/6.4.299/build','pdfjs/6.4.299/cmaps','pdfjs/6.4.299/standard_fonts','pdfjs/6.4.299/wasm'];
    foreach($publicDirectories as $directory) {
        clearstatcache(true,$root.'/'.$directory);
        if((fileperms($root.'/'.$directory)&0777)!==0755)throw new RuntimeException('browser cannot traverse viewer directory');
        chmod($root.'/'.$directory,0700); // Repair a previous deployment as well.
    }
    if($count<100 || !is_file($root.'/pdfjs/6.4.299/build/pdf.worker.mjs'))throw new RuntimeException('missing viewer assets');
    if(!hash_equals(hash_file('sha256',$package.'/build/pdf.mjs'),hash_file('sha256',$root.'/pdfjs/6.4.299/build/pdf.mjs')))throw new RuntimeException('asset mismatch');
    if(prepareReceiptViewer($package,$root)!==$count)throw new RuntimeException('repeated preparation changed');
    foreach($publicDirectories as $directory) {
        clearstatcache(true,$root.'/'.$directory);
        if((fileperms($root.'/'.$directory)&0777)!==0755)throw new RuntimeException('existing private directory not repaired');
    }
    unlink($root.'/pdfjs/6.4.299/build/pdf.mjs');symlink($root.'/pdfjs/6.4.299/LICENSE',$root.'/pdfjs/6.4.299/build/pdf.mjs');
    try{prepareReceiptViewer($package,$root);throw new RuntimeException('link accepted');}
    catch(RuntimeException $e){if($e->getMessage()!=='receipt_viewer_prepare_failed')throw $e;}
    echo "Receipt viewer assets: pinned version, matched worker, supporting assets and symlink rejection passed.\n";
}finally{
    umask($previousMask);
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($it as $file){if($file->isDir()&&!$file->isLink())rmdir($file->getPathname());else unlink($file->getPathname());}rmdir($root);
}
