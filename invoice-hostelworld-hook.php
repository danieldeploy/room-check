<?php
declare(strict_types=1);
header('Content-Type: application/json');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
ini_set('display_errors','0');
try {
    if (($_SERVER['REQUEST_METHOD']??'')!=='POST' || empty($_SERVER['HTTPS']) || $_SERVER['HTTPS']==='off') throw new RuntimeException('request',400);
    $raw=file_get_contents('php://input',false,null,0,4097);
    if (!is_string($raw) || strlen($raw)>4096) throw new RuntimeException('request',400);
    require_once __DIR__.'/src/Invoices/HostelworldBridge.php';
    $path=dirname(__DIR__,2).'/room-check-private/hostelworld-filter-provision.json';
    if (is_link($path) || !is_file($path) || (fileperms($path)&0077)!==0) throw new RuntimeException('configuration',503);
    $provision=json_decode(file_get_contents($path),true,8,JSON_THROW_ON_ERROR);
    if (!preg_match('/\A[a-f0-9]{64}\z/',$provision['key']??'')) throw new RuntimeException('configuration',503);
    if (!HostelworldBridge::verify($raw,(string)($_SERVER['HTTP_X_HUB_TIMESTAMP']??''),(string)($_SERVER['HTTP_X_HUB_SIGNATURE']??''),$provision['key'])) throw new RuntimeException('auth',403);
    $m=json_decode($raw,true,8,JSON_THROW_ON_ERROR);
    $keys=['source','from','subject','received_at','code'];
    if (!is_array($m) || count($m)!==5 || array_diff($keys,array_keys($m))
        || $m['source']!=='city' || !is_string($m['code']) || !(preg_match('/\A\d{6}\z/',$m['code']) || (strlen($m['code'])<=2048 && preg_match('/\Ahwlink1\.[A-Za-z0-9_-]+\z/',$m['code'])))
        || !is_string($m['from']) || strlen($m['from'])>254
        || !is_string($m['subject']) || strlen($m['subject'])>512 || !is_int($m['received_at'])) throw new RuntimeException('request',400);
    $id=(int)($provision['account_ids']['city']??0);
    if ($id<1) throw new RuntimeException('configuration',503);
    require_once __DIR__.'/lib.php';
    require_once __DIR__.'/src/Invoices/InvoiceAuth.php';
    $config=require __DIR__.'/config.php';
    (new InvoiceAuth(database(),new InvoiceVault($config['invoices']['private_dir'])))->receiveHostelworldEmail($id,
        ['from'=>$m['from'],'subject'=>$m['subject'],'received_at'=>$m['received_at'],'text'=>$m['code'],'code'=>$m['code']]);
    http_response_code(202); echo '{"received":true}';
} catch (Throwable $e) {
    http_response_code(in_array($e->getCode(),[400,403],true)?$e->getCode():503);
    echo '{"received":false}';
}
