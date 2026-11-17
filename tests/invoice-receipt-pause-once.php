<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/deploy/pause_receipt_wait_once.php';
function pw(bool $ok,string $why):void {if(!$ok)throw new RuntimeException($why);}
$dir=sys_get_temp_dir().'/receipt-pause-'.bin2hex(random_bytes(6));mkdir($dir,0700);
file_put_contents($dir.'/master.key',random_bytes(32));chmod($dir.'/master.key',0600);
try {
    $v=new InvoiceVault($dir);$now=time();$target=hash('sha256','synthetic-run');
    $s=['active_process'=>'synthetic-run','requested'=>false,'error'=>'','processes'=>['synthetic-run'=>['id'=>'synthetic-run','year'=>2026,'months'=>[8]]],
        'documents'=>['source'=>['id'=>'source','process_id'=>'synthetic-run','stage'=>'split','children'=>['accepted','waiting']],
        'accepted'=>['id'=>'accepted','process_id'=>'synthetic-run','source_id'=>'source','stage'=>'archived','toconline_state'=>'toc_accepted'],
        'waiting'=>['id'=>'waiting','process_id'=>'synthetic-run','source_id'=>'source','stage'=>'archived','toconline_state'=>'pending','archived_at'=>'now','error'=>'','retry_kind'=>'pacing','next_attempt'=>$now+1200]]];
    $v->save(InvoiceReceipts::FILE,$s);$v->save('toconline-ledger.enc',[['document_id'=>'receipt:accepted']]);
    pw(!InvoiceReceiptReport::canStart($s),'waiting send currently blocks intake');
    pw(pauseReceiptWaitOnce($v,$now,$target)==='paused','exact pending send suspended');
    $paused=$v->read(InvoiceReceipts::FILE);$d=$paused['documents']['waiting'];
    pw($d['stage']==='failed' && $d['resume_stage']==='archived' && $d['error']==='receipt_paused' && $d['next_attempt']===0,'existing retryable stopped state');
    pw(InvoiceReceiptReport::canStart($paused) && InvoiceReceiptReport::processState($paused,'synthetic-run')==='receipt_pending','new months enabled while old run remains pending');
    pw($paused['documents']['accepted']===$s['documents']['accepted'] && $paused['documents']['source']===$s['documents']['source'],'accepted sibling and master untouched');
    $p=new PDO('sqlite::memory:');$drive=new class{function connect():void{throw new RuntimeException('unexpected network');}function receiptsAuthorized():bool{return true;}};
    $flow=new InvoiceReceipts($p,$v,$drive,new InvoiceToconline($p,$v,$drive));
    $flow->run($now+3600);pw($flow->status()['documents']['waiting']['stage']==='failed','cron cannot resume paused item');
    $flow->request(1,2026,[9]);pw($flow->status()['requested'] && $flow->status()['documents']['waiting']===$d,'new month request retains paused invoice');
    $flow->retry('waiting');$resumed=$flow->status();pw($resumed['documents']['waiting']['stage']==='archived' && $resumed['documents']['waiting']['error']==='','existing manual retry resumes at delivery');
    pw(pauseReceiptWaitOnce($v,$now,$target)==='paused' && $v->read(InvoiceReceipts::FILE)===$resumed,'future deployment never pauses a resumed item again');
    foreach(['sent','uncertain','other-run','multiple','busy','due','missing-ledger'] as $case) {
        $candidate=$s;$v->save('toconline-ledger.enc',[]);
        if($case==='sent')$v->save('toconline-ledger.enc',[['document_id'=>'receipt:waiting']]);
        if($case==='uncertain')$candidate['documents']['waiting']['toconline_state']='toc_uncertain';
        if($case==='other-run')$candidate['active_process']='other';
        if($case==='multiple')$candidate['documents']['second']=$candidate['documents']['waiting'];
        if($case==='busy')$candidate['requested']=true;
        if($case==='due')$candidate['documents']['waiting']['next_attempt']=$now;
        if($case==='missing-ledger')unlink($v->path('toconline-ledger.enc'));
        $v->save(InvoiceReceipts::FILE,$candidate);
        try{pauseReceiptWaitOnce($v,$now,$target);}catch(RuntimeException){}
        $after=$v->read(InvoiceReceipts::FILE);pw($after['documents']===$candidate['documents'],'guard preserves '.$case);
    }
    echo "Receipt one-time pause, cron exclusion, new intake, resume and stale/sent guards passed\n";
}finally{foreach(glob($dir.'/*')?:[] as $file)unlink($file);rmdir($dir);}
