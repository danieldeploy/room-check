<?php
declare(strict_types=1);
header('Content-Type: application/json'); header('Cache-Control: no-store'); header('X-Content-Type-Options: nosniff');
ini_set('display_errors','0'); ini_set('log_errors','0'); $pdo=null; $locked=false;
try {
    if (($_SERVER['REQUEST_METHOD']??'')!=='POST' || empty($_SERVER['HTTPS']) || $_SERVER['HTTPS']==='off') throw new RuntimeException('request',400);
    $raw=file_get_contents('php://input',false,null,0,6291457);
    if (!is_string($raw) || strlen($raw)>6291456) throw new RuntimeException('request',400);
    $m=json_decode($raw,true,8,JSON_THROW_ON_ERROR); $source=$m['source']??'';
    if (!in_array($source,['welcome','city'],true)) throw new RuntimeException('request',400);
    require_once __DIR__.'/src/Invoices/CloudbedsInbox.php';
    $path=dirname(__DIR__,2).'/room-check-private/cloudbeds-filter-provision.json';
    if (is_link($path) || !is_file($path) || (fileperms($path)&0077)!==0) throw new RuntimeException('configuration',503);
    $p=json_decode(file_get_contents($path),true,8,JSON_THROW_ON_ERROR); $key=$p['keys'][$source]??'';
    if (!CloudbedsBridge::verify($raw,(string)($_SERVER['HTTP_X_HUB_TIMESTAMP']??''),(string)($_SERVER['HTTP_X_HUB_SIGNATURE']??''),$key)) throw new RuntimeException('auth',403);
    require_once __DIR__.'/lib.php'; $config=require __DIR__.'/config.php'; $pdo=database();
    if ((int)$pdo->query("SELECT GET_LOCK('room_check_invoices',5)")->fetchColumn()!==1) throw new RuntimeException('busy',503);
    $locked=true; $inbox=new CloudbedsInbox($pdo,new InvoiceVault($config['invoices']['private_dir']));
    if (count($m)===3 && ($m['version']??null)===1 && is_array($m['scan']??null)) $inbox->heartbeat($source,$m['scan']);
    else $inbox->receive($m,$source);
    http_response_code(202); echo '{"accepted":true}';
} catch (Throwable $e) {
    http_response_code(in_array($e->getCode(),[400,403],true)?$e->getCode():503); echo '{"accepted":false}';
} finally { if ($pdo && $locked) $pdo->query("SELECT RELEASE_LOCK('room_check_invoices')"); }
