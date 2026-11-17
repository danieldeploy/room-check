<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/src/Invoices/InvoiceReceipts.php';

/** Owner-requested operational pause. Caller holds room_check_invoices. No external operations. */
function pauseReceiptWaitOnce(InvoiceVault $vault,int $now,string $runHash='738300cc0912566a7fe4c8c44fda96446d571ff7f8ad31ecb8b3410e03f6c2f2'): string
{
    if(!$vault->has(InvoiceReceipts::FILE))return 'no_history';
    $s=$vault->read(InvoiceReceipts::FILE);$marker='owner_pause_20261117';
    if(isset($s[$marker]))return $s[$marker]['outcome'];
    $record=static function(string $outcome)use(&$s,$marker,$vault,$now):string {
        $s[$marker]=['outcome'=>$outcome,'at'=>gmdate('c',$now)];$vault->save(InvoiceReceipts::FILE,$s);return $outcome;
    };
    $pid=(string)($s['active_process']??'');
    if(!hash_equals($runHash,hash('sha256',$pid)))return $record('target_no_longer_active');
    if(!empty($s['requested']))throw new RuntimeException('receipt_pause_state_changed');
    $pending=array_filter($s['documents']??[],static fn($d)=>($d['process_id']??'')===$pid
        && empty($d['children']) && ($d['stage']??'')!=='superseded' && !InvoiceReceiptReport::resolved($d));
    if(!$pending)return $record('already_settled');
    if(count($pending)!==1)throw new RuntimeException('receipt_pause_state_changed');
    $id=(string)array_key_first($pending);$d=$pending[$id];
    // Only the observed unsent delivery wait. Never stop live/sent/uncertain operations.
    if(($d['stage']??'')!=='archived' || ($d['toconline_state']??'')!=='pending'
        || empty($d['archived_at']) || !empty($d['error']) || ($d['retry_kind']??'')!=='pacing'
        || ($d['next_attempt']??0)<=$now)return $record('wait_no_longer_eligible');
    if(!$vault->has('toconline-ledger.enc'))throw new RuntimeException('receipt_pause_history_missing');
    foreach($vault->read('toconline-ledger.enc') as $entry) {
        if(!is_array($entry) || !isset($entry['document_id']))throw new RuntimeException('receipt_pause_history_invalid');
        if($entry['document_id']==='receipt:'.$id)return $record('delivery_already_attempted');
    }
    $s['documents'][$id]=array_merge(InvoiceReceiptRetry::reset($d),[
        'stage'=>'failed','resume_stage'=>'archived','error'=>'receipt_paused','blocked'=>true]);
    if(!InvoiceReceiptReport::canStart($s))throw new RuntimeException('receipt_pause_other_work');
    // One atomic encrypted write contains both the pause and its durable once-only audit.
    $s[$marker]=['outcome'=>'paused','at'=>gmdate('c',$now),'document_id'=>$id,'before'=>$d];
    $vault->save(InvoiceReceipts::FILE,$s);
    return 'paused';
}

if(realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__) {
    if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
    ini_set('display_errors','0');umask(0077);
    try {
        $app=rtrim($argv[1]??'','/');
        if($app!=='/home/welcome/public_html/check' || realpath($app)!==$app)throw new RuntimeException('invalid_app');
        require $app.'/lib.php';$config=require $app.'/config.php';$pdo=database();
        if((int)$pdo->query("SELECT GET_LOCK('room_check_invoices',60)")->fetchColumn()!==1)throw new RuntimeException('worker_busy');
        try{$result=pauseReceiptWaitOnce(new InvoiceVault($config['invoices']['private_dir']),time());}
        finally{$pdo->query("SELECT RELEASE_LOCK('room_check_invoices')");}
        echo json_encode(['receipt_wait_pause'=>$result]),PHP_EOL;
    }catch(Throwable){fwrite(STDERR,"Receipt wait pause unverified; inspect before retry\n");exit(1);}
}
