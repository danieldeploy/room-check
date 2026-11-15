<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/src/Invoices/InvoiceVault.php';

/** Exact approved files only; retain verified recovery copies outside the web root. */
function retireInvoiceDiagnostics(string $app,InvoiceVault $vault,string $backups): array
{
    $check=static function(bool $ok): void {if(!$ok)throw new RuntimeException('invoice_diagnostics_cleanup_failed');};
    foreach([$app,$backups] as $root)$check(is_dir($root) && !is_link($root) && realpath($root)===$root);
    $check(!str_starts_with($backups,$app.'/') && $backups!==$app);
    $destination=$backups.'/retired-invoice-diagnostics-v1';$check(!is_link($destination));
    if(!is_dir($destination))$check(mkdir($destination,0700));
    $check(chmod($destination,0700));
    $targets=[
        'azure-page.php'=>$app.'/admin/invoice-azure.php',
        'azure-client.php'=>$app.'/src/Invoices/InvoiceAzure.php',
    ];
    $pending=false;
    if($vault->has('azure-receipt-tests.enc'))foreach($vault->read('azure-receipt-tests.enc') as $test) {
        if(!is_array($test) || !in_array($test['state']??'',['succeeded','failed'],true))$pending=true;
    }
    if(!$pending)foreach(['azure-document-intelligence.enc','azure-receipt-tests.enc','azure-diagnostics.lock'] as $name)$targets[$name]=$vault->path($name);
    // Preflight the full list before removing anything, including ancestor links.
    foreach($targets as $path) {
        $parent=dirname($path);
        while($parent!==$app && $parent!==$vault->root && $parent!==dirname($parent)) {
            $check(!is_link($parent) && realpath($parent)===$parent);$parent=dirname($parent);
        }
        $check($parent===$app || $parent===$vault->root);
        $check(!is_link($path));if(file_exists($path))$check(is_file($path));
    }
    $lockPath=$vault->path('azure-diagnostics.lock');$newLock=!is_file($lockPath);
    $lock=fopen($lockPath,'c+');$check(is_resource($lock) && chmod($lockPath,0600) && flock($lock,LOCK_EX|LOCK_NB));
    $removed=0;
    try {
        // Recheck after acquiring the diagnostic lock; never race an in-flight test.
        if($vault->has('azure-receipt-tests.enc'))foreach($vault->read('azure-receipt-tests.enc') as $test) {
            if(!is_array($test) || !in_array($test['state']??'',['succeeded','failed'],true))$pending=true;
        }
        foreach($targets as $name=>$path) {
            if($pending && str_starts_with($path,$vault->root.'/'))continue;
            if(!is_file($path))continue;
            $saved=$destination.'/'.$name;$check(!is_link($saved));
            $hash=hash_file('sha256',$path);$check(is_string($hash));
            if(!file_exists($saved))$check(copy($path,$saved) && chmod($saved,0600));
            $check(is_file($saved) && (fileperms($saved)&0077)===0 && hash_equals($hash,hash_file('sha256',$saved)));
            $check(hash_equals($hash,hash_file('sha256',$path)) && unlink($path));
            if($path!==$lockPath || !$newLock)$removed++;
        }
    }finally{if(is_resource($lock)){flock($lock,LOCK_UN);fclose($lock);}}
    $result=['files_removed'=>$removed,'azure_public_removed'=>!file_exists($app.'/admin/invoice-azure.php') && !file_exists($app.'/src/Invoices/InvoiceAzure.php'),
        'azure_private'=>$pending?'retained_pending_operation':'absent','recovery_copy'=>'verified'];
    InvoiceVault::atomicWrite($destination.'/result.json',json_encode($result,JSON_THROW_ON_ERROR));
    return $result;
}
if(PHP_SAPI==='cli' && realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__) {
    ini_set('display_errors','0');umask(0077);
    try {
        $app=rtrim($argv[1]??'','/');$backups='/home/welcome/room-check-backups';
        if($app!=='/home/welcome/public_html/check' || realpath($app)!==$app)throw new RuntimeException('invalid_app');
        $config=require $app.'/config.php';
        $result=retireInvoiceDiagnostics($app,new InvoiceVault($config['invoices']['private_dir']),$backups);
        echo json_encode(['invoice_diagnostics_retirement'=>$result]),PHP_EOL;
    }catch(Throwable){fwrite(STDERR,"Invoice diagnostic cleanup incomplete; inspect before retry\n");exit(1);}
}
