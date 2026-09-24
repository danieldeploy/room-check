<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/src/Invoices/InvoiceRemoteAgent.php';

function runInvoiceAgentCases(PDO $pdo): void
{
    $checks=0;
    $check=static function(bool $value,string $message) use (&$checks): void {
        ++$checks; if (!$value) throw new RuntimeException($message);
    };
    $reject=static function(callable $fn,string $code) use ($check): void {
        try { $fn(); } catch (Throwable $e) { $check($e->getMessage()===$code,'Expected '.$code.'; got '.$e->getMessage()); return; }
        throw new RuntimeException('Expected rejection: '.$code);
    };
    $tmp=sys_get_temp_dir().'/agent-test-'.bin2hex(random_bytes(8)); mkdir($tmp,0700);
    InvoiceVault::atomicWrite($tmp.'/master.key',random_bytes(32));
    $vault=new InvoiceVault($tmp);
    $pdo->exec('DELETE FROM invoice_tasks; DELETE FROM invoice_documents; DELETE FROM invoice_auth_challenges; UPDATE invoice_accounts SET enabled=0');
    $agent=new InvoiceRemoteAgent($pdo,['private_dir'=>$tmp]); $service=new InvoiceService($pdo);
    $token='';
    $send=static function(array $data) use ($agent,&$token): array { return $agent->handle($token,$data); };
    try {
        $check($agent->mode()==='local','Existing installations retain local execution');
        $token=$agent->pair();
        $check($agent->mode()==='paused','Pairing cannot automatically activate collection');
        $check($vault->read('windows-agent.enc')['token_hash']===hash('sha256',$token),'Only the token hash is persisted');
        $check(!str_contains(file_get_contents($tmp.'/windows-agent.enc'),$token),'Pairing secret is absent from disk');
        $rsa=openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_RSA,'private_key_bits'=>3072]);
        $public=openssl_pkey_get_details($rsa)['key'];
        $sealed=$agent->pairEncrypted($public);
        $check(openssl_private_decrypt(base64_decode($sealed['ciphertext']),$token,$rsa,OPENSSL_PKCS1_OAEP_PADDING),'Computer decrypts the pairing response');
        $check(strlen($token)===64 && !str_contains(json_encode($sealed),$token),'Encrypted response does not expose the access key');
        $check($vault->read('windows-agent.enc')['token_hash']===hash('sha256',$token),'Encrypted pairing persists only a token hash');
        $reject(fn()=>$agent->pairEncrypted('not-a-key'),'agent_pair_invalid');
        $weak=openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_RSA,'private_key_bits'=>2048]);
        $reject(fn()=>$agent->pairEncrypted(openssl_pkey_get_details($weak)['key']),'agent_pair_invalid');
        $check($send(['action'=>'ping'])['protocol']===1,'Invalid pairing input preserves the existing key');
        $reject(fn()=>$agent->handle(str_repeat('0',64),['action'=>'ping']),'forbidden');
        $reject(fn()=>$agent->setMode('windows'),'agent_test_required');
        $check($send(['action'=>'claim','claim_id'=>str_repeat('1',32)])['job']===null,'Paused agent cannot fetch a task');
        $check($send(['action'=>'ping'])['protocol']===1,'Authenticated handshake');
        $send(['action'=>'probe','code'=>'browser_unavailable']);
        $reject(fn()=>$agent->setMode('windows'),'agent_test_required');
        $send(['action'=>'probe','code'=>'ok']); $agent->setMode('windows');
        $job=$service->enqueue('preflight','1140306','2026-08',1);
        $claim=['action'=>'claim','claim_id'=>str_repeat('2',32)];
        $offer=$send($claim)['job'];
        $check($offer['id']===$job,'Existing queue is used');
        $check($send($claim)['job']['lease']===$offer['lease'],'Lost claim response reuses the same lease');
        $check($send(['action'=>'claim','claim_id'=>str_repeat('3',32)])['job']===null,'A second process cannot steal the lease');
        $reject(fn()=>$agent->pair(),'worker_busy');
        $reject(fn()=>$agent->setMode('local'),'worker_busy');
        $reject(fn()=>$agent->handle($token,['action'=>'pulse','task_id'=>$job,'lease'=>str_repeat('0',64)]),'lease_expired');
        $identity=['task_id'=>$job,'lease'=>$offer['lease']];
        $finish=$identity+['action'=>'complete','result'=>['code'=>'ok','documents'=>[]]];
        $check($send($finish)['state']==='completed','Preflight completes');
        $check($send($finish)['state']==='completed','Repeated completion is idempotent');
        $reject(fn()=>$agent->handle($token,$identity+['action'=>'complete','result'=>['code'=>'browser_unavailable']]),'completion_conflict');
        $check($service->browserReady(),'Remote Chrome updates Hub readiness');

        $vault->save('account-1-credentials.enc',['identifier'=>'test-user','password'=>'test-secret','sms_sender'=>'Booking','sms_keyword'=>'code']);
        $pdo->exec("UPDATE invoice_accounts SET status='configured',login_verified_at=NULL,enabled=0 WHERE id=1");
        $login=$service->enqueue('login','1140306','2026-08',1);
        $loginOffer=$send(['action'=>'claim','claim_id'=>str_repeat('b',32),'browserProfile'=>'primary'])['job'];
        $check($loginOffer['id']===$login && !isset($loginOffer['input']['map'])
            && ($loginOffer['input']['browserProfile'] ?? null)==='fresh_login'
            && !array_key_exists('session',$loginOffer['input']),
            'Manager Booking login uses the fixed fresh profile even if the agent request supplies a different profile');
        $loginIdentity=['task_id'=>$login,'lease'=>$loginOffer['lease']];
        $sessionDiagnostic=['version'=>1,'portal'=>'booking','validated'=>false,'login_attempted'=>false,
            'authenticated_session'=>true,'sms_prompted'=>false,'sms_submitted'=>false,'location'=>'https://admin.booking.com/hotel/hoteladmin/groups/home/',
            'snapshots'=>[]];
        $check($send($loginIdentity+['action'=>'complete','result'=>['code'=>'session_active','documents'=>[],
            'diagnostic'=>$sessionDiagnostic]])['state']==='completed','Existing Chrome session has its own task outcome');
        $account=$pdo->query('SELECT status,login_verified_at,enabled FROM invoice_accounts WHERE id=1')->fetch(PDO::FETCH_ASSOC);
        $check($account['status']==='configured' && $account['login_verified_at']===null && (int)$account['enabled']===0,
            'An existing Chrome session does not verify credentials or enable scheduling');
        $check($vault->read('account-1-login-diagnostic.enc')['login_attempted']===false,
            'A session-only diagnostic remains private and distinct from a verified login');
        $check((int)$pdo->query("SELECT COUNT(*) FROM invoice_failure_alerts WHERE task_id=$login")->fetchColumn()===0,
            'An existing Chrome session does not queue a WhatsApp alert');

        $challengeTask=$service->enqueue('login','1140306','2026-08',1);
        $challengeOffer=$send(['action'=>'claim','claim_id'=>str_repeat('d',32)])['job'];
        $check($challengeOffer['id']===$challengeTask
            && ($challengeOffer['input']['browserProfile'] ?? null)==='fresh_login'
            && !array_key_exists('session',$challengeOffer['input']),
            'An access test remains in the fresh profile after a prior task finished');
        $challengeIdentity=['task_id'=>$challengeTask,'lease'=>$challengeOffer['lease']];
        $check($send($challengeIdentity+['action'=>'complete','result'=>['code'=>'human_verification']])['state']==='needs_auth',
            'A human challenge ends the current task without verifying login');
        $retry=$service->enqueue('login','1140306','2026-08',1);
        $retryOffer=$send(['action'=>'claim','claim_id'=>str_repeat('e',32)])['job'];
        $check($retry!==$challengeTask && $retryOffer['id']===$retry
            && ($retryOffer['input']['browserProfile'] ?? null)==='fresh_login'
            && !array_key_exists('session',$retryOffer['input']),
            'The manager can retry the same account and period after the challenge without a deployment key');
        $retryIdentity=['task_id'=>$retry,'lease'=>$retryOffer['lease']];
        $check($send($retryIdentity+['action'=>'complete','result'=>['code'=>'session_active','documents'=>[],
            'diagnostic'=>$sessionDiagnostic]])['state']==='completed',
            'A session-only retry is completed without claiming credential verification');

        $login=$service->enqueue('login','1140306','2026-08',1,InvoiceRemoteAgent::BOOKING_FRESH_LOGIN_SMOKE_KEY);
        $loginOffer=$send(['action'=>'claim','claim_id'=>str_repeat('c',32)])['job'];
        $check($loginOffer['id']===$login && ($loginOffer['input']['browserProfile'] ?? null)==='fresh_login'
            && !array_key_exists('session',$loginOffer['input']),
            'The existing one-off Booking smoke task also uses the secondary profile and excludes saved cookies');
        $loginIdentity=['task_id'=>$login,'lease'=>$loginOffer['lease']];
        $loginDiagnostic=['version'=>1,'portal'=>'booking','validated'=>false,'login_attempted'=>true,
            'authenticated_session'=>true,'sms_prompted'=>true,'sms_submitted'=>true,'location'=>'https://admin.booking.com/hotel/hoteladmin/groups/home/',
            'snapshots'=>[]];
        $check($send($loginIdentity+['action'=>'complete','result'=>['code'=>'ok','documents'=>[],
            'diagnostic'=>$loginDiagnostic]])['state']==='completed','Verified automatic Booking login completes');
        $check($pdo->query('SELECT login_verified_at FROM invoice_accounts WHERE id=1')->fetchColumn()!==null,
            'Only a fresh login verifies access to the account');
        $savedDiagnostic=$vault->read('account-1-login-diagnostic.enc');
        $check($savedDiagnostic['authenticated_session']===true
            && $savedDiagnostic['sms_prompted']===true && $savedDiagnostic['sms_submitted']===true,
            'Private login diagnostic stores SMS progress as booleans, separately from invoice map');
        InvoiceVault::atomicWrite($tmp.'/account-1-map.json','{"version":2,"validated":false}');
        $job=$service->enqueue('collect','1140306','2026-08',1);
        $offer=$send(['action'=>'claim','claim_id'=>str_repeat('4',32)])['job'];
        $identity=['task_id'=>$job,'lease'=>$offer['lease']];
        $check($offer['input']['credentials']['password']==='test-secret','Credentials are delivered only in the authenticated job');
        $check(!isset($offer['input']['privateDir']),'Server filesystem paths are never execution instructions on Windows');
        $check(!isset($offer['input']['browserProfile']) && isset($offer['input']['session']),
            'Booking collection keeps its primary browser profile and saved session');
        $agent->maintenance();
        $check($pdo->query("SELECT state FROM invoice_tasks WHERE id=$job")->fetchColumn()==='running','Cron maintenance preserves a live remote task');
        $bytes='%PDF-1.7 '.str_repeat('test ',50000);
        $metadata=['number'=>'WIN-001','issued_on'=>'2026-08-12'];
        $upload=$identity+['action'=>'upload','id'=>0,'size'=>strlen($bytes),'sha256'=>hash('sha256',$bytes),'metadata'=>$metadata];
        $part=substr($bytes,0,InvoiceRemoteAgent::CHUNK_BYTES);
        $first=$upload+['offset'=>0,'chunk'=>base64_encode($part)];
        $check($send($first)['offset']===strlen($part),'Bounded upload accepted');
        $check($send($first)['offset']===strlen($part),'Repeated chunk accepted without appending twice');
        $reject(fn()=>$agent->handle($token,$upload+['offset'=>0,'chunk'=>base64_encode('different')]),'upload_conflict');
        $send($upload+['offset'=>strlen($part),'chunk'=>base64_encode(substr($bytes,strlen($part)))]);
        $done=$identity+['action'=>'complete','result'=>['code'=>'ok','documents'=>[0],'session'=>['cookies'=>[]]]];
        $check($send($done)['state']==='completed','Uploaded document is validated and imported');
        $check((int)$pdo->query("SELECT imported_count FROM invoice_tasks WHERE id=$job")->fetchColumn()===1,'Import count is recorded once');
        $send($done);
        $check((int)$pdo->query('SELECT COUNT(*) FROM invoice_documents')->fetchColumn()===1,'Lost completion response does not duplicate documents');
        $check(!(glob($tmp.'/windows-*.part') ?: []),'Temporary upload parts removed after completion');
        $check(file_get_contents($tmp.'/'.hash('sha256',$bytes).'.pdf')===$bytes,'Private document bytes preserved');

        $job=$service->enqueue('collect','539828','2026-08',1);
        $offer=$send(['action'=>'claim','claim_id'=>str_repeat('5',32)])['job'];
        $identity=['task_id'=>$job,'lease'=>$offer['lease']];
        $challenge=['id'=>str_repeat('a',32),'method'=>'sms','created'=>1];
        $check($send($identity+['action'=>'challenge','challenge'=>$challenge])['ready']===true,'Challenge uses server time despite PC clock skew');
        $sms=new InvoiceAuth($pdo,$vault); $smsToken=$sms->rotateSmsToken(1);
        $check($sms->receiveSms(1,$smsToken,['sender'=>'Booking','message'=>'Your code 123456','received_at'=>time()]),'SMS receiver remains compatible');
        $otp=$send($identity+['action'=>'challenge','challenge'=>$challenge]);
        $check($otp['value']==='123456','One-time response is delivered to the active lease');
        $check($send($identity+['action'=>'challenge','challenge'=>$challenge])['value']==='123456','Lost OTP response can be retried before acknowledgement');
        $send($identity+['action'=>'challenge','challenge'=>$challenge,'ack'=>true]);
        $check($send($identity+['action'=>'challenge','challenge'=>$challenge])['value']===null,'Acknowledged OTP cannot be replayed');
        $state=$vault->read('windows-lease.enc'); $state['expires']=time()-1; $vault->save('windows-lease.enc',$state);
        $agent->maintenance();
        $reject(fn()=>$agent->handle($token,$identity+['action'=>'pulse']),'lease_expired');
        $check($pdo->query("SELECT state FROM invoice_tasks WHERE id=$job")->fetchColumn()==='retry','Expired lease recovers through the existing retry budget');
        $agent->setMode('paused'); $agent->revoke();
        $reject(fn()=>$agent->handle($token,['action'=>'ping']),'forbidden');
        $check($agent->mode()==='paused','Revocation does not activate the shared-hosting Chrome runner');
        $token=$agent->pair(); $send(['action'=>'probe','code'=>'ok']); $agent->setMode('windows');
        $preflight=$service->enqueue('preflight','1140306','2026-08',1);
        $live=$send(['action'=>'claim','claim_id'=>str_repeat('6',32)])['job'];
        $check($live['id']===$preflight,'A fresh preflight can run after re-pairing');
        $agent->revoke();
        $reject(fn()=>$agent->handle($token,['action'=>'ping']),'forbidden');
        $reject(fn()=>$agent->setMode('local'),'worker_busy');
        $check($agent->mode()==='paused','Emergency revocation is immediate while the lease fence remains');
        $state=$vault->read('windows-lease.enc'); $state['expires']=time()-1; $vault->save('windows-lease.enc',$state);
        $agent->maintenance(); $agent->setMode('local');
        $check($agent->mode()==='local','Local rollback becomes possible only after the lease ends');
        echo "Windows agent checks passed: $checks\n";
    } finally {
        foreach (glob($tmp.'/*') ?: [] as $file) if (is_file($file)) unlink($file);
        rmdir($tmp);
    }
}
