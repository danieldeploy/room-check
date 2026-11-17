<?php
declare(strict_types=1);

function hubReceiptReadCases(PDO $pdo,array $identity): void
{
    $root=sys_get_temp_dir().'/hub-read-'.bin2hex(random_bytes(8));mkdir($root,0700);
    file_put_contents($root.'/master.key',random_bytes(32));chmod($root.'/master.key',0600);
    $vault=new InvoiceVault($root);$mcp=new HubMcp($pdo,['invoices'=>['private_dir'=>$root]]);
    $run='111111111111111111111111';$other='222222222222222222222222';$now=time();
    $snapshot=static function()use($root):array {
        $result=[];foreach(glob($root.'/*') as $file)$result[basename($file)]=hash_file('sha256',$file);
        return $result;
    };
    try {
        // A real encrypted fixture catches state writes, run mixing and accidental secret export.
        $vault->save('receipts-workflow.enc',['active_process'=>$other,'requested'=>false,'worker_seen_at'=>$now,
            'processes'=>[$run=>['year'=>2026,'months'=>[9],'requested_at'=>'2026-11-17T16:14:44+00:00'],
                $other=>['year'=>2026,'months'=>[8],'requested_at'=>'2026-11-17T15:00:00+00:00']],
            'documents'=>[
                'drive-source-private'=>['process_id'=>$run,'name'=>'scan.pdf','format'=>'pdf','stage'=>'failed','resume_stage'=>'reading',
                    'error'=>'gemini_retry','attempts'=>6,'blocked'=>true,'next_attempt'=>0,'retry_kind'=>'transient',
                    'retry_started_at'=>$now-1800,'retry_deadline'=>$now,'category_check_required'=>true,
                    'category_check'=>['category'=>'taloes'],'toconline_state'=>'pending','raw'=>'PRIVATE_OCR_TEXT'],
                'drive-ready-private'=>['process_id'=>$run,'name'=>'ready.pdf','format'=>'pdf','stage'=>'archived',
                    'error'=>'','toconline_state'=>'toc_submitted','archived_at'=>'2026-11-17T17:00:00+00:00'],
                'drive-other-private'=>['process_id'=>$other,'name'=>'other-run-secret.pdf','format'=>'pdf','stage'=>'reading',
                    'error'=>'','next_attempt'=>$now+600,'retry_kind'=>'pacing','toconline_state'=>'pending'],
            ]]);
        $vault->save('gemini-receipts.enc',['key'=>'SECRET_API_KEY']);
        $vault->save('gemini-receipts-budget.enc',['day'=>'2026-11-17','requests'=>19,'failures'=>7,'next_at'=>$now+600,'unexpected'=>'SECRET_BUDGET']);
        $vault->save('toconline-settings.enc',['enabled'=>true,'pilot_verified'=>true,'sender'=>'private@example.invalid','nif'=>'SECRET_NIF']);
        $vault->save('toconline-ledger.enc',['alias'=>['document_id'=>'receipt:drive-ready-private','state'=>'toc_submitted',
            'attempted_at'=>'2026-11-17T17:01:00+00:00','message'=>'PRIVATE_EMAIL_BODY']]);
        $before=$snapshot();
        $result=$mcp->call('hub_get_receipt_run',['process_id'=>$run],$identity);
        hubCheck($result['process']['id']===$run && $result['process']['months']===[9],'Exact selected run');
        hubCheck($result['summary']['documents']===2 && count($result['documents'])===2,'No other run records');
        hubCheck($result['progress']['automatic']===false && $result['can_start_new_run']===false,'Selected interruption differs from global wait');
        $failed=$result['documents'][0];$sent=$result['documents'][1];
        hubCheck($failed['error_code']==='gemini_retry' && $failed['attempts']===6 && $failed['next_attempt']===0,'Persisted retry facts');
        hubCheck($failed['delivery_attempt_recorded']===false && $sent['delivery_attempt_recorded']===true,'Ledger distinguishes no send from actual attempt');
        hubCheck($sent['delivery']['state']==='toc_submitted','Submission is not acceptance');
        hubCheck($result['gemini']['requests']===19 && $result['gemini']['next_at']===$now+600,'Shared Gemini pacing visible');
        $encoded=json_encode($result);
        foreach(['PRIVATE_OCR_TEXT','SECRET_API_KEY','SECRET_BUDGET','SECRET_NIF','PRIVATE_EMAIL_BODY','private@example.invalid','drive-source-private','other-run-secret.pdf'] as $secret)
            hubCheck(!str_contains($encoded,$secret),'Diagnostic output excludes private payloads and unrelated run');
        $mcp->call('hub_get_receipt_run',['process_id'=>$run],$identity);
        hubCheck($snapshot()===$before,'Repeated reads do not mutate encrypted files');
        foreach([['process_id'=>'../master.key'],['process_id'=>$run,'offset'=>-1],['process_id'=>$run,'offset'=>'0'],
                 ['process_id'=>$run,'path'=>'/tmp/elsewhere'],[]] as $args)
            hubReject(fn()=>$mcp->call('hub_get_receipt_run',$args,$identity),'invalid_request');
        hubReject(fn()=>$mcp->call('hub_get_receipt_run',['process_id'=>'333333333333333333333333'],$identity),'receipt_run_not_found');
        $limited=$identity;$limited['scopes']=['hub:read'];
        hubReject(fn()=>$mcp->call('hub_get_receipt_run',['process_id'=>$run],$limited),'insufficient_scope');
        $staff=$identity;$staff['user']['role']='governanta';
        hubReject(fn()=>$mcp->call('hub_get_receipt_run',['process_id'=>$run],$staff),'insufficient_scope');
        $page=$mcp->call('hub_get_receipt_run',['process_id'=>$run,'offset'=>1],$identity);
        hubCheck(count($page['documents'])===1 && $page['documents'][0]['name']==='ready.pdf','Offset selects next record');
        // A bounded page must not silently truncate a large run.
        $state=$vault->read('receipts-workflow.enc');
        for($i=0;$i<100;$i++)$state['documents']['extra-'.$i]=['process_id'=>$run,'stage'=>'review','error'=>'raw secret error','format'=>'pdf'];
        $vault->save('receipts-workflow.enc',$state);
        $page=$mcp->call('hub_get_receipt_run',['process_id'=>$run],$identity);
        hubCheck(count($page['documents'])===100 && $page['next_offset']===100,'Bounded page with continuation');
        hubCheck($page['documents'][2]['error_code']==='receipt_failed','Raw errors are not exposed');
        $page=$mcp->call('hub_get_receipt_run',['process_id'=>$run,'offset'=>100],$identity);
        hubCheck(count($page['documents'])===2 && $page['next_offset']===null,'Final diagnostic page');
        unlink($root.'/toconline-ledger.enc');
        $page=$mcp->call('hub_get_receipt_run',['process_id'=>$run],$identity);
        hubCheck($page['documents'][0]['delivery_attempt_recorded']===null,'Missing ledger is unknown, not proof of no send');
    } finally {foreach(glob($root.'/*') as $file)unlink($file);rmdir($root);}
}
