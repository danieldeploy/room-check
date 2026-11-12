<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/src/Invoices/InvoiceReceiptPdf.php';
/** Pinned decoder dependencies only, before release copies. Never installs a browser. */
function prepareReceiptRuntime(array $config,string $directory): void
{
    $real=realpath($directory);
    if (!$real || $real!==$directory || str_contains($real,'/public_html/') || is_link($directory)) throw new RuntimeException('receipt_split_unavailable');
    $node='';foreach (InvoiceReceiptPdf::nodeCandidates($config) as $candidate) if (is_executable($candidate)) {$node=$candidate;break;}
    $npm=dirname($node).'/npm';
    if (!$node || !is_executable($npm) || !function_exists('proc_open')) throw new RuntimeException('receipt_split_unavailable');
    $hash=hash_file('sha256',$directory.'/package-lock.json');$marker=$directory.'/.installed-lock';
    if (is_file($marker) && trim((string)file_get_contents($marker))===$hash && is_dir($directory.'/node_modules/zxing-wasm')) return;
    $env=getenv();$env['PATH']=dirname($node).PATH_SEPARATOR.($env['PATH']??'/usr/bin:/bin');
    $process=proc_open([$npm,'ci','--prefix',$directory,'--ignore-scripts','--omit=dev','--no-audit','--no-fund'],
        [0=>['pipe','r'],1=>['file','/dev/null','a'],2=>['file','/dev/null','a']],$pipes,$directory,$env);
    if (!is_resource($process)) throw new RuntimeException('receipt_split_unavailable');
    fclose($pipes[0]);$deadline=microtime(true)+180;
    do {$status=proc_get_status($process);if(!$status['running'])break;usleep(100000);}while(microtime(true)<$deadline);
    if($status['running'])proc_terminate($process,9);proc_close($process);
    if($status['running'] || $status['exitcode']!==0)throw new RuntimeException('receipt_split_unavailable');
    InvoiceVault::atomicWrite($marker,$hash."\n");
}
if(PHP_SAPI==='cli' && realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__){
    ini_set('display_errors','0');
    try{$config=(require ($argv[1]??'').'/config.php')['invoices'];prepareReceiptRuntime($config,dirname(__DIR__).'/invoice-runner/receipt-tools');echo "Receipt decoder dependencies ready\n";}
    catch(Throwable){fwrite(STDERR,"Receipt decoder preparation failed before application copies\n");exit(1);}
}
