#!/opt/cpanel/ea-php81/root/usr/bin/php -q
<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
ini_set('display_errors','0'); ini_set('log_errors','0'); error_reporting(0); umask(0077);
$city=__DIR__==='/home/city';
$source=$city?'city':'welcome';
$stateRoot=$city?'/home/city':'/home/welcome/room-check-private';
$lib=$city?'/home/city':'/home/welcome/public_html/check/src/Invoices';
function cloudbedsStatus(array $facts): void {
    global $stateRoot;
    try {
        $path=$stateRoot.'/cloudbeds-mail-status.json'; if (is_link($path)) return;
        $old=is_file($path) && filesize($path)<8192?json_decode(file_get_contents($path),true):[];
        foreach (['auth_code','http_status'] as $key) if (isset($old[$key]) && !isset($facts[$key])) $facts[$key]=$old[$key];
        $tmp=$path.'.'.bin2hex(random_bytes(8));
        if (file_put_contents($tmp,json_encode(['at'=>time()]+$facts),LOCK_EX)!==false) { chmod($tmp,0600); rename($tmp,$path); }
    } catch (Throwable) {}
}
function cloudbedsSend(array $packet): bool|string {
    global $stateRoot,$source;
    $path=$stateRoot.'/cloudbeds-'.$source.'.key';
    if (is_link($path) || !is_file($path) || (fileperms($path)&0077)!==0) return false;
    $key=trim(file_get_contents($path)); $body=json_encode($packet,JSON_THROW_ON_ERROR); $stamp=(string)time();
    $curl=curl_init('https://check.welcomehostel.pt/invoice-cloudbeds-hook.php');
    curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body,CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>20,
        CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
        CURLOPT_HTTPHEADER=>['Content-Type: application/json','X-Hub-Timestamp: '.$stamp,
            'X-Hub-Signature: '.CloudbedsBridge::signature($body,$stamp,$key)]]);
    $reply=curl_exec($curl); $status=curl_getinfo($curl,CURLINFO_RESPONSE_CODE); curl_close($curl);
    if ($status===422 && is_string($reply) && (json_decode($reply,true)['review']??null)===true) return 'review';
    $accepted=$status===202 && is_string($reply) && (json_decode($reply,true)['accepted']??null)===true;
    if (!$accepted) cloudbedsStatus(['stage'=>'delivery_retry','http_status'=>(int)$status]);
    return $accepted;
}
function cloudbedsConsume(string $raw): bool|string {
    global $source;
    try {
        $packet=CloudbedsBridge::packet($raw,$source);
        return $packet===null?true:cloudbedsSend($packet);
    } catch (Throwable $e) {
        if ($e->getMessage()==='mail_outside_window') return 'historical';
        if ($e->getMessage()==='mail_review') return 'review';
        $facts=['stage'=>in_array($e->getMessage(),['auth_invalid','invalid_document','document_limit'],true)?$e->getMessage():'parse_retry'];
        if ($e->getMessage()==='auth_invalid') {
            $auth=CloudbedsMailAuthentication::verify(CloudbedsMailMessage::wire($raw));
            $code=!empty($auth['verified'])?'recipient_mismatch':($auth['code']??'unverified');
            if (in_array($code,['recipient_mismatch','unsigned','unsupported','unaligned','expired','unsigned_fields','dns_unavailable','body_mismatch','signature_mismatch','unverified'],true)) $facts['auth_code']=$code;
        }
        cloudbedsStatus($facts);
        return false;
    }
}
try {
    if (!$city && __DIR__!=='/home/welcome/room-check-private/cron') exit(64);
    require_once $lib.'/CloudbedsBridge.php'; require_once $lib.'/CloudbedsFolderQueue.php';
    if ($argc===2 && $argv[1]==='--poll') {
        $mailRoot='/home/'.$source.'/mail/'.($city?'citycenterhostel.pt':'welcomehostel.pt').'/info';
        $scan=CloudbedsFolderQueue::run($mailRoot,$stateRoot,'cloudbedsConsume');
        if (!isset($scan['busy'])) {
            $sent=cloudbedsSend(['version'=>1,'source'=>$source,'scan'=>$scan]);
            cloudbedsStatus(['stage'=>$sent?'scan_complete':'delivery_retry']+$scan);
            exit($sent?0:75);
        }
        exit(0);
    }
    if ($argc!==1) exit(64);
    exit(cloudbedsConsume(stream_get_contents(STDIN,4194305))?0:75);
} catch (Throwable) { cloudbedsStatus(['stage'=>'configuration_retry']); exit(75); }
