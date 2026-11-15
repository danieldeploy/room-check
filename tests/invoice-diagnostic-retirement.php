<?php
declare(strict_types=1);
require dirname(__DIR__).'/deploy/retire_invoice_diagnostics.php';
function retiredCheck(bool $ok,string $message): void {if(!$ok)throw new RuntimeException($message);}
$root=sys_get_temp_dir().'/hub-retire-'.bin2hex(random_bytes(8));mkdir($root,0700);
$app=$root.'/app';$private=$root.'/private';$backups=$root.'/backups';
foreach([$app,$app.'/admin',$app.'/src',$app.'/src/Invoices',$private,$backups] as $dir)mkdir($dir,0700);
file_put_contents($private.'/master.key',random_bytes(32));chmod($private.'/master.key',0600);$vault=new InvoiceVault($private);
try {
    file_put_contents($app.'/admin/invoice-azure.php','retired page');file_put_contents($app.'/src/Invoices/InvoiceAzure.php','retired class');
    file_put_contents($app.'/admin/receipts.php','keep');$vault->save('receipts-workflow.enc',['keep'=>true]);
    $vault->save('azure-document-intelligence.enc',['key'=>'synthetic']);$vault->save('azure-receipt-tests.enc',[]);
    $r=retireInvoiceDiagnostics($app,$vault,$backups);
    retiredCheck($r['azure_public_removed'] && $r['azure_private']==='absent','Azure files removed');
    retiredCheck($vault->read('receipts-workflow.enc')===['keep'=>true] && is_file($private.'/master.key'),'shared data retained');
    retiredCheck(file_get_contents($backups.'/retired-invoice-diagnostics-v1/azure-page.php')==='retired page','recoverable code');
    retiredCheck(retireInvoiceDiagnostics($app,$vault,$backups)['files_removed']===0,'idempotent');
    $vault->save('azure-receipt-tests.enc',[['state'=>'running']]);
    retiredCheck(retireInvoiceDiagnostics($app,$vault,$backups)['azure_private']==='retained_pending_operation' && $vault->has('azure-receipt-tests.enc'),'unfinished operation retained');
    unlink($private.'/azure-receipt-tests.enc');
    symlink($app.'/admin/receipts.php',$app.'/admin/invoice-azure.php');
    try{retireInvoiceDiagnostics($app,$vault,$backups);throw new RuntimeException('link accepted');}
    catch(RuntimeException $e){retiredCheck($e->getMessage()==='invoice_diagnostics_cleanup_failed','reject link');}
    retiredCheck(file_get_contents($app.'/admin/receipts.php')==='keep','link target untouched');unlink($app.'/admin/invoice-azure.php');
    echo "Invoice diagnostic retirement: allowlist, recovery, idempotence, pending work and links passed.\n";
}finally{
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($it as $file){if($file->isDir()&&!$file->isLink())rmdir($file->getPathname());else unlink($file->getPathname());}rmdir($root);
}
