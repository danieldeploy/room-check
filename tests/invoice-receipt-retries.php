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
    $flow->run($start);$s=$flow->status();rt($s['requested'] && $s['discovery_retry']['next_attempt']===$start+360,'discovery connection failure schedules a retry');
    $flow->run($start+359);rt($drive->connections===1,'cron must not reconnect before due');
    for($i=1;$i<=5;$i++)$flow->run($start+$i*360);
    rt($drive->connections===6 && !$flow->status()['requested'],'discovery stops after five retries');
    $flow->run($start+4000);rt($drive->connections===6,'stopped discovery stays stopped');
    $s=['requested'=>false,'analysis_version'=>InvoiceReceiptAnalysis::VERSION,'documents'=>['scan'=>['id'=>'scan','stage'=>'queued','format'=>'jpg','process_id'=>'run','toconline_state'=>'pending']]];
    $v->save(InvoiceReceipts::FILE,$s);$drive->connections=0;
    for($i=0;$i<=5;$i++){ $flow->run($start+$i*360);$flow->run($start+$i*360+1); }
    rt($drive->connections===6 && $flow->status()['documents']['scan']['stage']==='failed','existing document connection budget is bounded too');
    $drive->connectionError='';$s['documents']['scan']['next_attempt']=0;$v->save(InvoiceReceipts::FILE,$s);
    for($i=0;$i<=5;$i++){ $flow->run($start+$i*360);$flow->run($start+$i*360+1); }
    rt($drive->reads===6 && $flow->status()['documents']['scan']['stage']==='failed','document read retries use same six-minute schedule');
    $v->save(InvoiceReceipts::FILE,$s);$flow->run($start);$reads=$drive->reads;$connections=$drive->connections;
    $flow->run($start+1801);
    rt($drive->reads===$reads && $drive->connections===$connections && $flow->status()['documents']['scan']['stage']==='failed','late cron stops without a network call or invented attempt');
    $v->save(InvoiceReceipts::FILE,$s);$flow->recordFailure(new RuntimeException('unexpected_private_message'));
    rt($flow->status()['documents']['scan']['stage']==='failed' && $flow->status()['error']==='receipt_failed','unexpected worker failure cannot leave endless active polling or raw errors');
    echo "Receipt transient, permanent and discovery retry boundaries passed\n";
} finally {foreach(glob($dir.'/*')?:[] as $f)unlink($f);rmdir($dir);}
