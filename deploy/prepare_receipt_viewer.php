<?php
declare(strict_types=1);

/** Publish only the pinned browser distribution, never the private Node tree. */
function prepareReceiptViewer(string $package,string $assets): int
{
    $check=static function(bool $ok): void {if(!$ok)throw new RuntimeException('receipt_viewer_prepare_failed');};
    foreach([$package,$assets] as $root)$check(is_dir($root) && !is_link($root) && realpath($root)===$root);
    $metadata=json_decode((string)file_get_contents($package.'/package.json'),true,16,JSON_THROW_ON_ERROR);
    $check(($metadata['version']??'')==='6.4.299');
    $target=$assets.'/pdfjs';$check(!is_link($target));
    if(!is_dir($target))$check(mkdir($target,0755));
    $target.='/6.4.299';$check(!is_link($target));
    if(!is_dir($target))$check(mkdir($target,0755));
    $files=['LICENSE','build/pdf.mjs','build/pdf.worker.mjs'];
    foreach(['cmaps','standard_fonts','wasm'] as $directory) {
        $source=$package.'/'.$directory;$check(is_dir($source) && !is_link($source));
        foreach(new DirectoryIterator($source) as $entry) {
            if($entry->isDot())continue;
            $check(!$entry->isLink() && $entry->isFile());$files[]=$directory.'/'.$entry->getFilename();
        }
    }
    foreach($files as $relative) {
        $source=$package.'/'.$relative;$destination=$target.'/'.$relative;$parent=dirname($destination);
        $check(is_file($source) && !is_link($source) && str_starts_with((string)realpath($source),$package.'/'));
        $check(!is_link($parent) && !is_link($destination));
        if(!is_dir($parent))$check(mkdir($parent,0755));
        $check(realpath($parent)===$parent);
        $check(copy($source,$destination) && chmod($destination,0644));
        $check(hash_equals(hash_file('sha256',$source),hash_file('sha256',$destination)));
    }
    return count($files);
}
if(PHP_SAPI==='cli' && realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__) {
    ini_set('display_errors','0');
    try {
        $app=rtrim($argv[1]??'','/');
        if($app!=='/home/welcome/public_html/check' || realpath($app)!==$app)throw new RuntimeException('invalid_app');
        // These directories already belong to the installed application.
        $count=prepareReceiptViewer(dirname(__DIR__).'/invoice-runner/receipt-tools/node_modules/pdfjs-dist',$app.'/admin/assets');
        echo json_encode(['receipt_mobile_assets'=>'ready','files'=>$count]),PHP_EOL;
    }catch(Throwable){fwrite(STDERR,"Receipt mobile assets preparation failed\n");exit(1);}
}
