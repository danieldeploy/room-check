<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') exit(1);
ini_set('display_errors','0');ini_set('log_errors','0');umask(0077);
try {
    $app=$argv[1]??'';
    if ($app!=='/home/welcome/public_html/check/' || realpath($app)!==rtrim($app,'/')) throw new RuntimeException('configuration');
    require_once $app.'lib.php';require_once $app.'src/Invoices/InvoiceReceipts.php';
    require_once $app.'src/Invoices/InvoiceTocReplyRecovery.php';
    $config=require $app.'config.php';$pdo=database();$vault=new InvoiceVault($config['invoices']['private_dir']);
    $toc=new InvoiceToconline($pdo,$vault,new InvoiceDriveClient($vault));
    if (($toc->settings()['sender']??'')!=='daniel.ciorcas@welcomehostel.pt') throw new RuntimeException('configuration');
    if ((int)$pdo->query("SELECT GET_LOCK('room_check_invoices',30)")->fetchColumn()!==1) throw new RuntimeException('busy');
    try {
        if (!$vault->has('receipt-source-cleanup-hold.enc')) {
            InvoiceReceipts::pauseSourceCleanup($vault);
            $vault->save('receipt-source-cleanup-hold.enc',['applied_at'=>gmdate('c')]);
        }
        if (!$vault->has('receipt-source-processed-policy.enc')) {
            InvoiceReceipts::enableProcessedSources($vault);
            $vault->save('receipt-source-processed-policy.enc',['applied_at'=>gmdate('c')]);
        }
        $result=InvoiceTocReplyRecovery::run($vault,'/home/welcome/mail/welcomehostel.pt/daniel.ciorcas');
        $pipe=new InvoiceTocPipe($vault);
        for ($i=0;$i<4 && $pipe->status()['pending']>0;$i++) $pipe->process($toc);
        if (!$result['complete'] || $pipe->status()['pending']>0) throw new RuntimeException('recovery_pending');
        echo "TOConline retained reply recovery completed through existing verifier; completed sources move to tempo_scan_processed.\n";
    } finally {$pdo->query("SELECT RELEASE_LOCK('room_check_invoices')");}
} catch (Throwable $e) {
    $allowed=['configuration','busy','recovery_pending','toc_recovery_root','toc_recovery_limit','toc_pipe_queue'];
    $code=in_array($e->getMessage(),$allowed,true)?$e->getMessage():'recovery_failed';
    fwrite(STDERR,'TOConline reply recovery incomplete: '.$code."\n");exit(1);
}
