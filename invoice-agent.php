<?php
declare(strict_types=1);
// Machine protocol. Tokens, account access, sessions and OTPs never enter URL queries or logs.
ini_set('display_errors','0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
$locked=false; $pdo=null;
try {
    if (($_SERVER['REQUEST_METHOD'] ?? '')!=='POST') throw new RuntimeException('invalid_request',405);
    if (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS']==='off') throw new RuntimeException('https_required',400);
    if (!empty($_SERVER['QUERY_STRING']) || !preg_match('~\Aapplication/json(?:\s*;|\z)~i',(string)($_SERVER['CONTENT_TYPE'] ?? ''))
        || (int)($_SERVER['CONTENT_LENGTH'] ?? 0)>524288) throw new RuntimeException('invalid_request',400);
    $authorization=(string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if (!preg_match('/\ABearer ([a-f0-9]{64})\z/',$authorization,$match)) throw new RuntimeException('forbidden',403);
    $raw=file_get_contents('php://input',false,null,0,524289);
    if (!$raw || strlen($raw)>524288) throw new RuntimeException('invalid_request',400);
    try { $data=json_decode($raw,true,32,JSON_THROW_ON_ERROR); } catch (Throwable) { throw new RuntimeException('invalid_request',400); }
    if (!is_array($data)) throw new RuntimeException('invalid_request',400);
    require __DIR__.'/lib.php';
    require_once __DIR__.'/src/Invoices/InvoiceRemoteAgent.php';
    $config=require __DIR__.'/config.php'; $pdo=database();
    $agent=new InvoiceRemoteAgent($pdo,array_merge($config['invoices'],['whatsapp'=>$config['whatsapp'] ?? []]));
    $agent->authenticate($match[1]);
    if ((int)$pdo->query("SELECT GET_LOCK('room_check_invoices',0)")->fetchColumn()!==1) throw new RuntimeException('worker_busy',409);
    $locked=true;
    echo json_encode($agent->handle($match[1],$data),JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    $status=in_array($e->getCode(),[400,403,405,409,413],true)?$e->getCode():503;
    http_response_code($status);
    $allowed=['invalid_request','https_required','forbidden','worker_busy','lease_expired','upload_conflict','completion_conflict','document_limit','auth_invalid'];
    echo json_encode(['error'=>in_array($e->getMessage(),$allowed,true)?$e->getMessage():'unavailable']);
} finally {
    if ($locked && $pdo) $pdo->query("SELECT RELEASE_LOCK('room_check_invoices')");
}
