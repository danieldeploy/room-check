<?php
declare(strict_types=1);
require_once __DIR__.'/../src/Auth/HubOAuth.php';
require_once __DIR__.'/../src/Auth/HubMcp.php';
require_once __DIR__.'/../src/Auth/HubConnectorHttp.php';
function hubCheck(bool $ok,string $label): void { if (!$ok) throw new RuntimeException($label); }
function hubReject(callable $action,string $code): void {
    try { $action(); } catch (RuntimeException $e) { hubCheck($e->getMessage()===$code,'Unexpected safe rejection: '.$code); return; }
    throw new RuntimeException('Expected rejection: '.$code);
}
function hubFixture(PDO $pdo): void {
    $sqlite=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite';
    $pdo->exec($sqlite
        ? 'CREATE TABLE users (id INTEGER PRIMARY KEY,username TEXT,display_name TEXT,role TEXT,is_active INTEGER,password_hash TEXT);
           CREATE TABLE auth_audit_log (actor_user_id INTEGER,action TEXT,details_json TEXT,ip_key TEXT);
           CREATE TABLE role_permissions (role TEXT,permission TEXT);'
        : 'CREATE TABLE IF NOT EXISTS users (id BIGINT UNSIGNED PRIMARY KEY,username VARCHAR(64),display_name VARCHAR(120),role VARCHAR(40),is_active INT,password_hash VARCHAR(255),last_login_at DATETIME NULL,preferred_language VARCHAR(2) DEFAULT \'pt\');
           CREATE TABLE IF NOT EXISTS auth_audit_log (actor_user_id BIGINT UNSIGNED,action VARCHAR(64),details_json JSON,ip_key CHAR(64));
           CREATE TABLE IF NOT EXISTS role_permissions (role VARCHAR(40),permission VARCHAR(64));');
    if (!$sqlite) $pdo->exec(file_get_contents(__DIR__.'/../migrations/003_auth.sql'));
    $migration=file_get_contents(__DIR__.'/../migrations/030_hub_connector.sql');
    if ($sqlite) {
        $migration=str_replace([' CHARACTER SET ascii COLLATE ascii_bin',' ENGINE=InnoDB'],'',$migration);
        $migration=preg_replace('/^\s*INDEX[^\n]*\n/m','',$migration);
    }
    $pdo->exec($migration); $pdo->exec($migration);
    $pdo->prepare('INSERT INTO users (id,username,display_name,role,is_active,password_hash) VALUES (?,?,?,?,?,?)')
        ->execute([880001,'connector_fixture','Test manager','gerente',1,password_hash('synthetic-test-password-2026',PASSWORD_DEFAULT)]);
    $pdo->prepare('INSERT INTO users (id,username,display_name,role,is_active,password_hash) VALUES (?,?,?,?,?,?)')
        ->execute([880002,'connector_other','Test staff','governanta',1,password_hash('synthetic-staff-password',PASSWORD_DEFAULT)]);
}
function hubRequest(string $verifier): array {
    return ['response_type'=>'code','client_id'=>'management-hub-chatgpt','redirect_uri'=>HubOAuth::CALLBACK,
        'code_challenge'=>HubOAuth::challenge($verifier),'code_challenge_method'=>'S256',
        'state'=>bin2hex(random_bytes(16)),'resource'=>HubOAuth::RESOURCE,'scope'=>implode(' ',HubOAuth::SCOPES)];
}
function hubExchange(string $code,string $verifier): array {
    return ['grant_type'=>'authorization_code','client_id'=>'management-hub-chatgpt','resource'=>HubOAuth::RESOURCE,
        'redirect_uri'=>HubOAuth::CALLBACK,'code'=>$code,'code_verifier'=>$verifier];
}
function hubRefresh(string $refresh): array {
    return ['grant_type'=>'refresh_token','client_id'=>'management-hub-chatgpt','resource'=>HubOAuth::RESOURCE,'refresh_token'=>$refresh];
}
function runHubCases(PDO $pdo): void {
    $now=1800000000; $oauth=new HubOAuth($pdo,$now); $verifier=HubOAuth::randomToken(); $request=hubRequest($verifier);
    hubCheck(HubOAuth::authorization($request)==$request,'Valid PKCE authorization');
    hubCheck(HubOAuth::register(['redirect_uris'=>[HubOAuth::CALLBACK]])['client_id']===$request['client_id'],'Public DCR client');
    foreach (['https://evil.test/callback',HubOAuth::CALLBACK.'?next=evil','https://chatgpt.com.evil.test/callback', 'http://chatgpt.com/connector_platform_oauth_redirect'] as $redirect) {
        hubReject(fn()=>HubOAuth::authorization(array_replace($request,['redirect_uri'=>$redirect])),'invalid_redirect_uri');
    }
    foreach (['code_challenge_method'=>'plain','resource'=>'https://other.test/mcp','client_id'=>'unknown','state'=>'','response_type'=>'token'] as $key=>$value) {
        hubReject(fn()=>HubOAuth::authorization(array_replace($request,[$key=>$value])),'invalid_request');
    }
    hubReject(fn()=>HubOAuth::authorization(array_replace($request,['scope'=>'hub:read admin:all'])),'invalid_scope');
    hubReject(fn()=>$oauth->approve($request,880002,['hub:read']),'access_denied');
    hubReject(fn()=>$oauth->approve(array_replace($request,['scope'=>'hub:read']),880001,['hub:read','cloudbeds:import']),'invalid_scope');
    $code=$oauth->approve($request,880001,['hub:read','invoices:read']);
    $exchange=hubExchange($code,$verifier);
    foreach (['code_verifier'=>HubOAuth::randomToken(),'client_id'=>'another','redirect_uri'=>HubOAuth::CALLBACK.'/extra'] as $key=>$value) {
        hubReject(fn()=>$oauth->token(array_replace($exchange,[$key=>$value])),'invalid_grant');
    }
    hubReject(fn()=>$oauth->token(array_replace($exchange,['resource'=>'https://other.test/mcp'])),'invalid_target');
    $tokens=$oauth->token($exchange); $identity=$oauth->authenticate($tokens['access_token']);
    hubCheck($identity['user']['username']==='connector_fixture' && !isset($identity['user']['password_hash']),'Resolved owner without password');
    hubReject(fn()=>$oauth->authenticate($tokens['refresh_token']),'invalid_token');
    hubReject(fn()=>$oauth->authenticate($tokens['access_token'],'cloudbeds:import'),'insufficient_scope');
    hubReject(fn()=>(new HubOAuth($pdo,$now+901))->authenticate($tokens['access_token']),'invalid_token');
    $renewed=(new HubOAuth($pdo,$now+1000))->token(hubRefresh($tokens['refresh_token']));
    hubCheck($renewed['refresh_token']!==$tokens['refresh_token'],'Refresh rotation');
    (new HubOAuth($pdo,$now+1001))->authenticate($renewed['access_token']);
    hubReject(fn()=>$oauth->token(hubRefresh($tokens['refresh_token'])),'invalid_grant');
    hubReject(fn()=>$oauth->authenticate($renewed['access_token']),'invalid_token');
    $code=$oauth->approve($request,880001,['hub:read']); $tokens=$oauth->token(hubExchange($code,$verifier));
    hubReject(fn()=>$oauth->token(hubExchange($code,$verifier)),'invalid_grant');
    hubReject(fn()=>$oauth->authenticate($tokens['access_token']),'invalid_token');
    $code=$oauth->approve($request,880001,['hub:read']);
    hubReject(fn()=>(new HubOAuth($pdo,$now+301))->token(hubExchange($code,$verifier)),'invalid_grant');
    $code=$oauth->approve($request,880001,['hub:read']); $tokens=$oauth->token(hubExchange($code,$verifier)); $identity=$oauth->authenticate($tokens['access_token']);
    $oauth->revoke($identity['grant_id'],880002); $oauth->authenticate($tokens['access_token']);
    $oauth->revoke($identity['grant_id'],880001); hubReject(fn()=>$oauth->authenticate($tokens['access_token']),'invalid_token');
    hubReject(fn()=>$oauth->token(hubRefresh($tokens['refresh_token'])),'invalid_grant');
    $code=$oauth->approve($request,880001,['hub:read']); $tokens=$oauth->token(hubExchange($code,$verifier));
    hubReject(fn()=>(new HubOAuth($pdo,$now+HubOAuth::REFRESH_TTL+1))->token(hubRefresh($tokens['refresh_token'])),'invalid_grant');
    $pdo->exec('UPDATE users SET is_active=0 WHERE id=880001'); hubReject(fn()=>$oauth->authenticate($tokens['access_token']),'invalid_token');
    $pdo->exec("UPDATE users SET is_active=1,role='governanta' WHERE id=880001"); hubReject(fn()=>$oauth->token(hubRefresh($tokens['refresh_token'])),'invalid_grant');
    $pdo->exec("UPDATE users SET role='gerente' WHERE id=880001");
    $pdo->prepare('UPDATE users SET password_hash=? WHERE id=880001')->execute([password_hash('another-fixture-password',PASSWORD_DEFAULT)]);
    hubReject(fn()=>$oauth->authenticate($tokens['access_token']),'invalid_token');
    $code=$oauth->approve($request,880001,HubOAuth::SCOPES); $tokens=$oauth->token(hubExchange($code,$verifier)); $identity=$oauth->authenticate($tokens['access_token']);
    $profile=(new HubMcp($pdo,[]))->call('hub_get_profile',[],$identity);
    hubCheck($profile['role']==='gerente' && $profile['connection_id']===$identity['grant_id'],'MCP profile');
    hubReject(fn()=>(new HubMcp($pdo,[]))->call('hub_get_profile',['user_id'=>880002],$identity),'invalid_request');
    hubReject(fn()=>(new HubMcp($pdo,[]))->call('hub_import_cloudbeds_pdf',['source'=>'welcome','pdf_base64'=>'invalid'],$identity),'invalid_document');
    hubReject(fn()=>HubMcp::scope('execute_sql'),'unknown_tool');
    foreach ($pdo->query('SELECT * FROM hub_oauth_tokens')->fetchAll(PDO::FETCH_ASSOC) as $row) {
        hubCheck(preg_match('/\A[a-f0-9]{64}\z/',$row['token_hash'])===1,'Only token hashes stored');
    }
    $audit=json_encode($pdo->query('SELECT * FROM auth_audit_log')->fetchAll(PDO::FETCH_ASSOC));
    foreach ([$tokens['access_token'],$tokens['refresh_token'],$code,$verifier,'synthetic-test-password-2026'] as $secret) hubCheck(!str_contains($audit,$secret),'Audit secret redaction');
    $oauth->revokeToken($tokens['refresh_token'],'wrong-client'); $oauth->authenticate($tokens['access_token']);
    $oauth->revokeToken($tokens['refresh_token'],$request['client_id']); hubReject(fn()=>$oauth->authenticate($tokens['access_token']),'invalid_token');
    hubReject(fn()=>HubConnectorHttp::form('resource=a&resource=b'),'invalid_request');
    hubReject(fn()=>HubConnectorHttp::form('scope%5B%5D=a'),'invalid_request');
    hubCheck(str_contains(HubOAuth::callback($request,['error'=>'access_denied']),'iss='),'Issuer on denial callback');
}
