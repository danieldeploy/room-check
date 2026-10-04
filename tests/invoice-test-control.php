<?php
declare(strict_types=1);
require __DIR__.'/invoices.php';
require_once dirname(__DIR__).'/src/Invoices/InvoiceRemoteAgent.php';
require_once dirname(__DIR__).'/src/Invoices/InvoiceTestControl.php';
$pdo->exec("CREATE TABLE users(id INTEGER PRIMARY KEY,username TEXT,display_name TEXT,mobile TEXT,role TEXT,is_active INTEGER);
INSERT INTO users VALUES(7,'gerente','Fixture owner','351900000001','gerente',1);
CREATE TABLE auth_audit_log(id INTEGER PRIMARY KEY,actor_user_id INTEGER,action TEXT,details_json TEXT,ip_key TEXT);
CREATE TABLE role_permissions(role TEXT,permission TEXT);
CREATE TABLE invoice_drive_settings(id INTEGER PRIMARY KEY,state TEXT,folder_id TEXT);
INSERT INTO invoice_drive_settings VALUES(1,'not_configured',NULL);
CREATE TABLE invoice_document_delivery(document_id INTEGER PRIMARY KEY,drive_state TEXT,last_error TEXT,drive_id TEXT,drive_parent TEXT);
ALTER TABLE invoice_notification_settings ADD COLUMN recipient_user_id INTEGER;
ALTER TABLE invoice_notification_settings ADD COLUMN template_name TEXT;
UPDATE invoice_notification_settings SET enabled=0,template_name='invoice_collection_failed';
DELETE FROM invoice_tasks; DELETE FROM invoice_batches; DELETE FROM invoice_batch_tasks; DELETE FROM invoice_documents;
DELETE FROM invoice_account_settings; DELETE FROM invoice_property_settings;
DELETE FROM invoice_account_properties WHERE account_id=1;
INSERT INTO invoice_account_properties VALUES(1,'1140306','Welcome'),(1,'539828','City');
UPDATE invoice_accounts SET enabled=0,login_verified_at='2026-09-01 00:00:00' WHERE id=1;");
$tmp=sys_get_temp_dir().'/test-control-'.bin2hex(random_bytes(8)); mkdir($tmp,0700);
InvoiceVault::atomicWrite($tmp.'/master.key',random_bytes(32));
$vault=new InvoiceVault($tmp); $agent=new InvoiceRemoteAgent($pdo,['private_dir'=>$tmp,'whatsapp'=>['secrets_file'=>$tmp.'/whatsapp.json']]);
$control=new InvoiceTestControl($pdo,$vault,$agent);
$token=$agent->pair();
$send=static fn(array $request)=>$agent->handle($token,$request);
$reject=static function(callable $fn,string $code): void {
    try { $fn(); } catch (Throwable $e) { checkInvoice($e->getMessage()===$code,'Expected '.$code.', got '.$e->getMessage()); return; }
    throw new RuntimeException('Missing rejection '.$code);
};
try {
    $reject(fn()=>$send(['action'=>'control_status','period'=>'2026-07']),'forbidden');
    checkInvoice($control->provisionOnce()==='granted','Explicit grant targets the exact two active properties');
    checkInvoice($control->provisionOnce()==='already_recorded','Provisioning is one-time');
    $send(['action'=>'ping']); $send(['action'=>'probe','code'=>'ok']); $send(['action'=>'control_resume']);
    $before=$vault->read('windows-agent.enc');
    $status=$send(['action'=>'control_status','period'=>'2026-07']);
    checkInvoice($before===$vault->read('windows-agent.enc'),'Status is not a worker heartbeat');
    checkInvoice($status['notifications']['owner_id']===7 && $status['notifications']['owner_mobile_suffix']==='0001','Only fixed owner metadata is returned');
    checkInvoice($status['drive']['connection_state']==='not_configured' && $status['drive']['oauth_storage']==='missing'
        && !$status['drive']['oauth_client_configured'] && !$status['drive']['oauth_authorization_stored']
        && !$status['drive']['folder_configured'] && array_sum($status['drive']['delivery'])===0,'Missing Drive configuration is distinguishable from a stored authorization');
    $vault->save('drive-oauth.enc',['client_id'=>'fixture-private-client','client_secret'=>'fixture-private-secret']);
    $pdo->exec("UPDATE invoice_drive_settings SET state='configured',folder_id='fixture-private-root';
        INSERT INTO invoice_documents(id,account_id,period) VALUES(1001,1,'2026-07'),(1002,1,'2026-07'),(1003,1,'2026-07'),(1004,1,'2026-06'),(1005,2,'2026-07'),(1006,1,'2026-07');
        INSERT INTO invoice_document_delivery VALUES(1001,'verified',NULL,'fixture-private-file','fixture-private-parent'),
            (1002,'retry','drive_permission',NULL,NULL),(1003,'fixture-private-state',NULL,NULL,NULL),
            (1004,'verified',NULL,NULL,NULL),(1005,'failed','drive_quota',NULL,NULL);");
    $drive=$send(['action'=>'control_status','period'=>'2026-07'])['drive'];
    checkInvoice($drive['connection_state']==='configured' && $drive['folder_configured'] && $drive['oauth_client_configured']
        && !$drive['oauth_authorization_stored'],'Configured OAuth client does not imply completed Google authorization');
    checkInvoice($drive['delivery']===['pending'=>1,'uploading'=>0,'retry'=>1,'failed'=>0,'verified'=>1,'unknown'=>1]
        && $drive['errors']===['drive_permission'=>1],'Delivery evidence is scoped to the fixed account and requested issue month, including missing delivery rows');
    $vault->save('drive-oauth.enc',['client_id'=>'fixture-private-client','client_secret'=>'fixture-private-secret','refresh_token'=>'fixture-private-refresh']);
    $pdo->exec("UPDATE invoice_drive_settings SET state='ready'; UPDATE invoice_document_delivery SET drive_state='failed',last_error='fixture-private-error' WHERE document_id=1003;");
    $dbBefore=$pdo->query('SELECT * FROM invoice_drive_settings')->fetchAll(PDO::FETCH_ASSOC);
    $deliveryBefore=$pdo->query('SELECT * FROM invoice_document_delivery ORDER BY document_id')->fetchAll(PDO::FETCH_ASSOC);
    $auditBefore=(int)$pdo->query('SELECT COUNT(*) FROM auth_audit_log')->fetchColumn();
    $vaultBefore=hash_file('sha256',$vault->path('drive-oauth.enc'));
    $drive=$send(['action'=>'control_status','period'=>'2026-07'])['drive'];
    checkInvoice($drive['oauth_authorization_stored'] && $drive['connection_state']==='ready'
        && $drive['delivery']['failed']===1 && $drive['errors']===['drive_permission'=>1,'unknown'=>1],'Stored authorization and verified readiness remain separate evidence');
    checkInvoice(!str_contains(json_encode($drive),'fixture-private'),'Status excludes OAuth secrets, identifiers, unknown states and raw errors');
    checkInvoice($dbBefore===$pdo->query('SELECT * FROM invoice_drive_settings')->fetchAll(PDO::FETCH_ASSOC)
        && $deliveryBefore===$pdo->query('SELECT * FROM invoice_document_delivery ORDER BY document_id')->fetchAll(PDO::FETCH_ASSOC)
        && $auditBefore===(int)$pdo->query('SELECT COUNT(*) FROM auth_audit_log')->fetchColumn()
        && $vaultBefore===hash_file('sha256',$vault->path('drive-oauth.enc')),'Drive diagnostics do not change readiness, queues, audit state or OAuth storage');
    $pdo->exec("UPDATE invoice_drive_settings SET state='fixture-private-state',folder_id='invalid';");
    InvoiceVault::atomicWrite($vault->path('drive-oauth.enc'),'fixture-invalid-envelope');
    $drive=$send(['action'=>'control_status','period'=>'2026-07'])['drive'];
    checkInvoice($drive['connection_state']==='unknown' && !$drive['folder_configured'] && $drive['oauth_storage']==='unreadable'
        && !$drive['oauth_client_configured'] && !$drive['oauth_authorization_stored'],'Unreadable stored authorization returns bounded diagnostics without exposing the error');
    unlink($vault->path('drive-oauth.enc')); $pdo->exec('DELETE FROM invoice_document_delivery; DELETE FROM invoice_documents;');
    $reject(fn()=>$send(['action'=>'control_status','period'=>null]),'invalid_request');
    $reject(fn()=>$send(['action'=>'control_status','period'=>'2026-07','account_id'=>2]),'invalid_request');
    $reject(fn()=>$send(['action'=>'control_enable_alerts','recipient'=>8]),'forbidden');
    $reject(fn()=>$send(['action'=>'control_enable_alerts','recipient'=>7]),'invalid_request');
    InvoiceVault::atomicWrite($tmp.'/whatsapp.json',json_encode(['access_token'=>'fake-provider-secret','phone_number_id'=>'123']));
    $send(['action'=>'control_enable_alerts','recipient'=>7]);
    checkInvoice((int)$pdo->query('SELECT enabled FROM invoice_notification_settings')->fetchColumn()===1,'Owner alerts enabled with existing configuration');
    $testRequest=['action'=>'control_test_alert','recipient'=>7,'request_id'=>str_repeat('e',32)];
    $reject(fn()=>$send(array_replace($testRequest,['recipient'=>8])),'forbidden');
    $reject(fn()=>$send($testRequest+['message'=>'arbitrary content']),'invalid_request');
    $reject(fn()=>$send(array_replace($testRequest,['request_id'=>'invalid'])),'invalid_request');
    $queued=$send($testRequest);
    checkInvoice($queued['test']['state']==='queued','Owner-authorized notification test queued separately');
    $reject(fn()=>$send(array_replace($testRequest,['request_id'=>str_repeat('f',32)])),'worker_busy');
    unlink($vault->path('notification-test-latest.enc'));
    checkInvoice($send($testRequest)===$queued,'Replayed queue request recovers a lost latest pointer');
    $notificationTest=new InvoiceNotificationTest($pdo,$vault,[]); $sent=0;
    $sender=static function(string $mobile,array $values,string $template) use (&$sent): string {
        ++$sent;
        checkInvoice($mobile==='351900000001' && $template==='invoice_collection_failed','Only configured owner and template used');
        checkInvoice(count($values)===5 && str_contains($values[3],'Teste controlado'),'Test is explicitly labeled and follows the existing template');
        return 'private-provider-message-id';
    };
    $notificationTest->dispatch($sender); $notificationTest->dispatch($sender);
    checkInvoice($sent===1 && $notificationTest->status()['state']==='accepted','At most one send and acceptance is separate from delivery');
    checkInvoice($send($testRequest)['test']['state']==='accepted','Replayed accepted request returns its current receipt');
    checkInvoice(!str_contains(json_encode($send(['action'=>'control_status','period'=>'2026-07'])),'private-provider-message-id'),'Provider identifier stays private');
    $reject(fn()=>$send(array_replace($testRequest,['request_id'=>str_repeat('f',32)])),'worker_busy');
    $first=$vault->read('notification-test-'.str_repeat('e',32).'.enc');
    $first['requested_at']=gmdate('Y-m-d H:i:s',time()-61); $vault->save('notification-test-'.str_repeat('e',32).'.enc',$first);
    $send(array_replace($testRequest,['request_id'=>str_repeat('f',32)]));
    $notificationTest->dispatch(static function(): string { throw new RuntimeException('sensitive-provider-body',400); });
    $failed=$notificationTest->status();
    checkInvoice($failed['state']==='unconfirmed' && $failed['provider_http_status']===400 && !str_contains(json_encode($failed),'sensitive'),'Uncertain sends expose only safe provider status');
    $notificationTest->dispatch($sender); checkInvoice($sent===1,'Uncertain sends are never retried automatically');
    $failedReceipt=$vault->read('notification-test-'.str_repeat('f',32).'.enc');
    $failedReceipt['requested_at']=gmdate('Y-m-d H:i:s',time()-61); $vault->save('notification-test-'.str_repeat('f',32).'.enc',$failedReceipt);
    $send(array_replace($testRequest,['request_id'=>str_repeat('1',32)]));
    $pdo->exec('UPDATE users SET is_active=0 WHERE id=7'); $notificationTest->dispatch($sender);
    checkInvoice($notificationTest->status()['state']==='cancelled' && $sent===1,'Deactivated owner cancels a queued test');
    $pdo->exec('UPDATE users SET is_active=1 WHERE id=7');
    $crashed=$vault->read('notification-test-'.str_repeat('1',32).'.enc'); $crashed['state']='sending';
    $vault->save('notification-test-'.str_repeat('1',32).'.enc',$crashed); $notificationTest->dispatch($sender);
    checkInvoice($sent===1 && $notificationTest->status()['state']==='unconfirmed','Interrupted send is reported without resending');
    $vault->save('account-1-automation.enc',['captcha_mode'=>'test','captcha_api_key'=>str_repeat('a',32),'deny_loopback'=>true]);
    $send(['action'=>'control_enable_captcha']);
    checkInvoice($vault->read('account-1-automation.enc')['captcha_mode']==='collection','Explicit opt-in covers collections');
    checkInvoice(!str_contains(json_encode($send(['action'=>'control_status','period'=>'2026-07'])),str_repeat('a',32)),'Status excludes CAPTCHA key');
    $request=['action'=>'control_collect','period'=>'2026-07','request_id'=>str_repeat('b',32)];
    $reject(fn()=>$send($request),'auth_invalid');
    $vault->save('account-1-credentials.enc',['identifier'=>'fixture','password'=>'fixture']);
    InvoiceVault::atomicWrite($tmp.'/account-1-map.json',json_encode(['version'=>3,'validated'=>true,'portal'=>'booking','accountId'=>1,'strategy'=>'booking-finance-v1','properties'=>['1140306'=>[],'539828'=>[]]]));
    $batch=$send($request);
    checkInvoice($batch===$send($request),'Response replay cannot create another batch');
    checkInvoice((int)$pdo->query('SELECT COUNT(*) FROM invoice_tasks')->fetchColumn()===2,'Both properties share one request');
    unlink($vault->path('test-control-'.str_repeat('b',32).'.enc'));
    checkInvoice($send($request)===$batch,'Database request key recovers a lost receipt');
    $reject(fn()=>$send(array_replace($request,['period'=>'2026-06'])),'invalid_request');
    $offer=$send(['action'=>'claim','claim_id'=>str_repeat('c',32)])['job'];
    checkInvoice($offer['input']['automation']['captcha_api_key']===str_repeat('a',32),'Authorized collection receives the provider key through the encrypted transport');
    $reject(fn()=>$send(['action'=>'control_pause']),'worker_busy');
    $pdo->exec("UPDATE users SET is_active=0 WHERE id=7");
    $reject(fn()=>$send(['action'=>'control_status','period'=>'2026-07']),'forbidden');
    $pdo->exec("UPDATE users SET is_active=1 WHERE id=7");
    $send(['action'=>'control_revoke']);
    checkInvoice($control->provisionOnce()==='already_recorded','Deploy never re-enables a revoked grant');
    $reject(fn()=>$send(['action'=>'control_status','period'=>'2026-07']),'forbidden');
    $grant=$vault->read(InvoiceTestControl::GRANT); $grant['enabled']=true; $vault->save(InvoiceTestControl::GRANT,$grant);
    $paired=$vault->read('windows-agent.enc');$rotated=str_repeat('d',64);$paired['token_hash']=hash('sha256',$rotated);$vault->save('windows-agent.enc',$paired);
    $reject(fn()=>$agent->handle($rotated,['action'=>'control_status','period'=>'2026-07']),'forbidden');
    echo "Scoped Booking test control, permissions, revocation and idempotence passed.\n";
} finally { foreach(glob($tmp.'/*')?:[] as $file) unlink($file); rmdir($tmp); }
