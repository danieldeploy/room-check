<?php
declare(strict_types=1);
require_once __DIR__.'/../src/Invoices/HostelworldBridge.php';
function check(bool $value): void { if (!$value) throw new RuntimeException('bridge_test_failed'); }
$date=gmdate('r');
$raw="From: Hostelworld <security@hostelworld.com>\r\nSubject: Login_code\r\nDate: $date\r\nContent-Type: multipart/mixed; boundary=\"b\"\r\n\r\n--b\r\nContent-Type: text/plain\r\n\r\nCode: 123456\r\n--b\r\nContent-Type: text/html\r\n\r\n<p>Code: 123456</p>\r\n--b\r\nContent-Type: text/plain\r\nContent-Disposition: attachment\r\n\r\nPRIVATE ATTACHMENT 654321\r\n--b--\r\n";
$p=HostelworldBridge::packet($raw);
check($p!==null && $p['code']==='123456' && $p['source']==='city');
check(array_keys($p)===['source','from','subject','received_at','code']);
$json=json_encode($p); $stamp=(string)time(); $key=str_repeat('a',64);
$sig=HostelworldBridge::signature($json,$stamp,$key);
check(HostelworldBridge::verify($json,$stamp,$sig,$key));
check(!HostelworldBridge::verify(str_replace('city','welcome',$json),$stamp,$sig,$key));
check(!HostelworldBridge::verify($json,(string)(time()-100),$sig,$key));
check(!str_contains($json,'PRIVATE') && !str_contains($json,'text'));
check(HostelworldBridge::packet(str_replace('security@hostelworld.com','security@evil.example',$raw))===null);
check(HostelworldBridge::packet(str_replace('<p>Code: 123456</p>','<p>Code: 222222</p>',$raw))===null);
echo "Hostelworld bridge privacy, signature and MIME tests passed.\n";

require_once __DIR__.'/../src/Invoices/HostelworldLinkToken.php';
$target=['host'=>'inbox.hostelworld.com','path'=>'/inbox/verify'];
$url='https://inbox.hostelworld.com/inbox/verify?token=fixture-token-abcdef&expires=1234567890';
$code=HostelworldLinkToken::encode($url,$target);
check(!str_contains($code,'https')&&!str_contains($code,'hostelworld'));
$rebuilt=HostelworldLinkToken::decode($code,$target);
check(HostelworldLinkToken::encode($rebuilt,$target)===$code);
check(HostelworldLinkToken::extract("$url\n$url",[$target])===$code);
check(HostelworldLinkToken::extract("$url\nhttps://inbox.hostelworld.com/inbox/verify?token=second-fixture",[$target])===null);
foreach ([
 str_replace('inbox.hostelworld.com','evil.example',$url),
 str_replace('/inbox/verify','/other',$url),
 $url.'&email=private%40example.com',
 $url.'&redirect=https%3A%2F%2Fevil.example',
 $url.'&token=duplicate',
 $url.'#fragment',
 str_replace('https://','http://',$url),
 str_replace('https://','https://user@',$url),
 'https://inbox.hostelworld.com/inbox/verify?expires=123'
] as $invalid) {
 $rejected=false;
 try { HostelworldLinkToken::encode($invalid,$target); }
 catch (RuntimeException) { $rejected=true; }
 check($rejected);
}
foreach ([$code.'=', 'hwlink1.'.rtrim(strtr(base64_encode('{"email":"private@example.com"}'),'+/','-_'),'=')] as $invalid) {
 $rejected=false;
 try { HostelworldLinkToken::decode($invalid,$target); }
 catch (RuntimeException) { $rejected=true; }
 check($rejected);
}
echo "Hostelworld token transport destination and privacy tests passed.\n";
