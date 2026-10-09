#!/usr/local/bin/php -q
<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
ini_set('display_errors','0'); ini_set('log_errors','0'); error_reporting(0); umask(0077);
try {
    require_once __DIR__.'/HostelworldBridge.php';
    $raw=stream_get_contents(STDIN,HostelworldMailMessage::MAX_BYTES+1);
    try { $packet=HostelworldBridge::packet($raw); } catch (Throwable) { exit(0); }
    unset($raw);
    if ($packet===null) exit(0);
    $path=__DIR__.'/hostelworld-city-auth.key';
    if (is_link($path) || !is_file($path) || (fileperms($path)&0077)!==0) exit(75);
    $key=trim(file_get_contents($path));
    if (!preg_match('/\A[a-f0-9]{64}\z/',$key)) exit(75);
    $body=json_encode($packet,JSON_THROW_ON_ERROR);
    $stamp=(string)time();
    $curl=curl_init('https://check.welcomehostel.pt/invoice-hostelworld-hook.php');
    curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body,CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>15,
        CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
        CURLOPT_HTTPHEADER=>['Content-Type: application/json','X-Hub-Timestamp: '.$stamp,
            'X-Hub-Signature: '.HostelworldBridge::signature($body,$stamp,$key)]]);
    $result=curl_exec($curl); $status=curl_getinfo($curl,CURLINFO_RESPONSE_CODE); curl_close($curl);
    exit($result!==false && $status>=200 && $status<300 ? 0 : 75);
} catch (Throwable) { exit(75); }
