<?php
declare(strict_types=1);
require_once __DIR__.'/hub-connector-cases.php';
$dsn=getenv('INVOICES_TEST_DSN');
if (!$dsn) throw new RuntimeException('Disposable MySQL test database required');
$pdo=new PDO($dsn,getenv('INVOICES_TEST_USER')?:'root',getenv('INVOICES_TEST_PASSWORD')?:'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
if (($argv[1]??'')==='race') {
    $input=json_decode(stream_get_contents(STDIN),true,8,JSON_THROW_ON_ERROR);
    try { (new HubOAuth($pdo,1800000000))->token($input); echo 'issued'; }
    catch (RuntimeException $e) { echo $e->getMessage()==='invalid_grant'?'rejected':'failed'; }
    exit;
}
hubFixture($pdo); runHubCases($pdo);
$oauth=new HubOAuth($pdo,1800000000); $verifier=HubOAuth::randomToken(); $code=$oauth->approve(hubRequest($verifier),880001,['hub:read']);
$tokens=$oauth->token(hubExchange($code,$verifier)); $children=[];
for ($i=0;$i<2;$i++) {
    $pipes=[]; $process=proc_open([PHP_BINARY,__FILE__,'race'],[['pipe','r'],['pipe','w'],['pipe','w']],$pipes);
    if (!is_resource($process)) throw new RuntimeException('Concurrency test process unavailable');
    $children[]=[$process,$pipes];
}
foreach ($children as [$process,$pipes]) { fwrite($pipes[0],json_encode(hubRefresh($tokens['refresh_token']),JSON_THROW_ON_ERROR)); fclose($pipes[0]); }
$results=[];
foreach ($children as [$process,$pipes]) { $results[]=stream_get_contents($pipes[1]); $errors=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); hubCheck(proc_close($process)===0 && $errors==='','Race process failed'); }
sort($results); hubCheck($results===['issued','rejected'],'Refresh race must have exactly one winner');
hubReject(fn()=>$oauth->authenticate($tokens['access_token']),'invalid_token');
echo "MySQL additive migration and concurrent refresh replay revocation passed.\n";
