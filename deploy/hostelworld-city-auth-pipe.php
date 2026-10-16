#!/opt/cpanel/ea-php81/root/usr/bin/php -q
<?php
declare(strict_types=1);
// Use the installed PHP 8.1 CLI directly: mail delivery must not depend on
// ea-php-cli choosing an interpreter from its working directory or environment.
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
ini_set('display_errors','0'); ini_set('log_errors','0'); error_reporting(0); umask(0077);
function recordStage(string $stage,int $status=0,?bool $accepted=null): void {
    try {
    // Bounded operational facts only; never mail, headers, tokens or raw errors.
    $file=__DIR__.'/hostelworld-city-auth-status.json';
    if (is_link($file)) return;
    $tmp=$file.'.'.bin2hex(random_bytes(8));
    $value=json_encode(['version'=>1,'at'=>time(),'stage'=>$stage,'http_status'=>$status,'accepted'=>$accepted]);
    if (file_put_contents($tmp,$value,LOCK_EX)!==false) { chmod($tmp,0600); rename($tmp,$file); }
    } catch (Throwable) { /* Diagnostics must never change mail processing. */ }
}
try {
    recordStage('started');
    require_once __DIR__.'/HostelworldBridge.php';
    $raw=stream_get_contents(STDIN,HostelworldMailMessage::MAX_BYTES+1);
    $targetPath=__DIR__.'/hostelworld-city-auth-targets.json';
    if (is_link($targetPath) || !is_file($targetPath) || (fileperms($targetPath)&0077)!==0) exit(75);
    $targets=json_decode(file_get_contents($targetPath),true,4,JSON_THROW_ON_ERROR);
    if (!is_array($targets)) exit(75);
    try { $packet=HostelworldBridge::packet($raw,$targets); } catch (Throwable) { recordStage('parse_error'); exit(0); }
    unset($raw);
    if ($packet===null) { recordStage('message_not_matched'); exit(0); }
    recordStage('message_matched');
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
    $reply=is_string($result)?json_decode($result,true):null;
    recordStage($result===false?'transport_error':'hub_response',(int)$status,
        is_bool($reply['accepted']??null)?$reply['accepted']:null);
    exit($result!==false && $status>=200 && $status<300 ? 0 : 75);
} catch (Throwable) { recordStage('configuration_error'); exit(75); }
