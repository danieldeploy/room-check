<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/src/Invoices/InvoiceReceipts.php';
function rt(bool $ok,string $why):void {if(!$ok)throw new RuntimeException($why);}
rt(class_exists('InvoiceReceiptRetry'),'Receipt retry policy must exist');
$start=1800000000;$r=[];
for($failure=0;$failure<6;$failure++) {
    $r=InvoiceReceiptRetry::failure($r,new RuntimeException('drive_network'),$start+$failure*360);
    rt($r['blocked']===($failure===5),'initial failure plus exactly five retries');
    rt($r['next_attempt']===($failure===5?0:$start+($failure+1)*360),'six-minute schedule persists');
}
foreach(['drive_auth','drive_permission','drive_quota','drive_not_found','gemini_auth','gemini_not_configured'] as $code) {
    $r=InvoiceReceiptRetry::failure([],new RuntimeException($code),$start);
    rt($r['blocked'] && !$r['next_attempt'],'human/configuration failure is not automatically retried: '.$code);
}
$r=InvoiceReceiptRetry::failure([],new InvoiceGeminiRetry('gemini_retry',$start+10),$start);
rt($r['next_attempt']===$start+360,'short provider delay cannot trigger early retry');
$r=InvoiceReceiptRetry::failure([],new InvoiceGeminiRetry('gemini_retry',$start+500),$start);
rt($r['next_attempt']===$start+500,'respect a longer provider minimum');
$r=InvoiceReceiptRetry::failure([],new InvoiceGeminiRetry('gemini_daily_quota',$start+86400),$start);
rt(!$r['blocked'] && $r['next_attempt']===$start+86400 && $r['retry_kind']==='quota','daily quota is separate from short retries');
$r=InvoiceReceiptRetry::failure([],new InvoiceGeminiRetry('gemini_wait',$start+10),$start);
rt(($r['attempts']??0)===0 && $r['error']==='' && $r['next_attempt']===$start+10,'normal pacing does not consume a failed attempt');
$r=InvoiceReceiptRetry::failure([],new RuntimeException('drive_network'),$start);
$r=InvoiceReceiptRetry::failure($r,new RuntimeException('drive_network'),$start+1000);
rt($r['next_attempt']===$start+1080,'late worker skips missed slots without stretching the 30-minute window');
$r=InvoiceReceiptRetry::failure($r,new RuntimeException('drive_network'),$start+1800);
rt($r['blocked'],'late attempts cannot extend the retry deadline');

$dir=sys_get_temp_dir().'/receipt-retry-'.bin2hex(random_bytes(6));mkdir($dir,0700);file_put_contents($dir.'/master.key',random_bytes(32));chmod($dir.'/master.key',0600);
try {
    $v=new InvoiceVault($dir);$p=new PDO('sqlite::memory:');
    $drive=new class {
        public int $connections=0,$reads=0;public string $connectionError='drive_network';
        function receiptsAuthorized():bool{return true;}
        function connect():void{$this->connections++;if($this->connectionError)throw new RuntimeException($this->connectionError);}
        function scanDocument($d):string{$this->reads++;throw new RuntimeException('drive_network');}
    };
    $v->save(InvoiceReceipts::FILE,['requested'=>true,'active_process'=>'run','year'=>2026,'months'=>[4],
        'processes'=>['run'=>['id'=>'run','year'=>2026,'months'=>[4]]],'documents'=>[]]);
    $flow=new InvoiceReceipts($p,$v,$drive,new InvoiceToconline($p,$v,$drive));
    $flow->run($start);$s=$flow->status();rt($s['requested'] && $s['worker_retry']['next_attempt']===$start+360,'discovery connection failure schedules a retry');
    $flow->run($start+359);rt($drive->connections===1,'cron must not reconnect before due');
    for($i=1;$i<=5;$i++)$flow->run($start+$i*360);
    rt($drive->connections===6 && $flow->status()['worker_retry']['blocked'],'discovery stops after five retries');
    $flow->run($start+4000);rt($drive->connections===6,'stopped discovery stays stopped');
    $s=['requested'=>false,'analysis_version'=>InvoiceReceiptAnalysis::VERSION,'documents'=>['scan'=>['id'=>'scan','stage'=>'queued','format'=>'jpg','process_id'=>'run','toconline_state'=>'pending']]];
    $v->save(InvoiceReceipts::FILE,$s);$drive->connections=0;
    $other=$s['documents']['scan'];$other['id']='other';$other['process_id']='other-run';
    $v->save(InvoiceReceipts::FILE,array_replace($s,['documents'=>$s['documents']+['other'=>$other]]));
    for($i=0;$i<=5;$i++){ $flow->run($start+$i*360);$flow->run($start+$i*360+1); }
    $failed=$flow->status();
    rt($drive->connections===6 && $failed['worker_retry']['attempts']===6 && $failed['worker_retry']['blocked'],'one shared connection budget across two runs');
    rt($failed['documents']['scan']===$s['documents']['scan'] && $failed['documents']['other']===$other && $drive->reads===0,'unattempted scans retain all state');
    rt(!InvoiceReceiptReport::progress($failed,true,$start+1800)['automatic'],'terminal shared failure stops polling');
    $flow->retryWorker();rt(empty($flow->status()['worker_retry']),'operator can resume the worker without resetting documents');
    $drive->connectionError='';$s['documents']['scan']['next_attempt']=0;$v->save(InvoiceReceipts::FILE,$s);
    for($i=0;$i<=5;$i++){ $flow->run($start+$i*360);$flow->run($start+$i*360+1); }
    rt($drive->reads===6 && $flow->status()['documents']['scan']['stage']==='failed','document read retries use same six-minute schedule');
    $v->save(InvoiceReceipts::FILE,$s);$flow->run($start);$reads=$drive->reads;$connections=$drive->connections;
    $flow->run($start+1801);
    rt($drive->reads===$reads && $drive->connections===$connections && $flow->status()['documents']['scan']['stage']==='failed','late cron stops without a network call or invented attempt');
    $v->save(InvoiceReceipts::FILE,$s);$flow->recordFailure(new RuntimeException('unexpected_private_message'));
    rt($flow->status()['documents']['scan']===$s['documents']['scan'] && $flow->status()['worker_retry']['error']==='receipt_failed','unexpected worker failure cannot leave endless active polling or raw errors');
    $s['requested']=true;$s['active_process']='new-month';$s['processes']['new-month']=['id'=>'new-month','year'=>2026,'months'=>[10]];
    $v->save(InvoiceReceipts::FILE,$s);$reads=$drive->reads;$flow->run($start);
    $discovery=$flow->status();
    rt(isset($discovery['processes']['new-month']['error']) && $drive->reads===$reads+1,'discovery failure cannot starve an already discovered scan');
    rt($discovery['documents']['scan']['attempts']===1 && $discovery['documents']['scan']['error']==='drive_network','only the scan read contributes to its attempts');
    $s['requested']=false;
    $fresh=$s['documents']['scan'];
    $expiredSlot=array_replace($fresh,['id'=>'september','process_id'=>'september','retry_kind'=>'transient',
        'next_attempt'=>$start,'error'=>'gemini_retry','retry_started_at'=>$start-360,'retry_deadline'=>$start+1440,'attempts'=>1]);
    $queueState=$s;
    $queueState['documents']=['october'=>array_replace($fresh,['id'=>'october','process_id'=>'october']),
        'september'=>$expiredSlot,'other-retry'=>array_replace($expiredSlot,['id'=>'other-retry','next_attempt'=>$start+1])];
    $v->save(InvoiceReceipts::FILE,$queueState);
    $flow->run($start+2);$turn=$flow->status();
    rt($turn['documents']['september']['attempts']===2 && empty($turn['documents']['october']['attempts']),'oldest due retry gets a turn beside fresh work');
    $resumed=new InvoiceReceipts($p,$v,$drive,new InvoiceToconline($p,$v,$drive));$resumed->run($start+3);
    rt($resumed->status()['documents']['october']['attempts']===1 && $resumed->status()['documents']['other-retry']['attempts']===1,'next worker admits a fresh document before the next due retry');
    require_once dirname(__DIR__).'/src/Invoices/InvoiceRunner.php';
    $s['documents']['scan']['stage']='review';
    $v->save(InvoiceReceipts::FILE,$s);
    $queue='toc-pipe-'.str_repeat('a',64).'.enc';
    $v->save($queue,['hash'=>str_repeat('b',64)]);
    $archive=new ReflectionMethod(InvoiceRunner::class,'archive');
    $runner=new InvoiceRunner($p,['private_dir'=>$dir]);
    $archive->invoke($runner);
    $incidents=$v->read(InvoiceReceipts::PHASE_ERRORS);
    rt(isset($incidents['toc_pipe_queue'],$incidents['receipt_portal_archive']),'separate archive and authenticated-reply incidents');
    rt($flow->status()['worker_seen_at']>0 && $flow->status()['documents']['scan']['stage']==='review','paper worker ran despite two independent failures');
    rt(!$v->has('toconline-ledger.enc'),'failed incoming queue never advances confirmation timeouts');
    $archive->invoke($runner);
    rt($v->read(InvoiceReceipts::PHASE_ERRORS)===$incidents,'repeat observation preserves one incident and original time per phase');
    unlink($v->path($queue));$archive->invoke($runner);
    rt(!isset($v->read(InvoiceReceipts::PHASE_ERRORS)['toc_pipe_queue']) && isset($v->read(InvoiceReceipts::PHASE_ERRORS)['receipt_portal_archive']),'recovery clears only the successful phase');
    echo "Receipt transient, permanent and discovery retry boundaries passed\n";
} finally {foreach(glob($dir.'/*')?:[] as $f)unlink($f);rmdir($dir);}
