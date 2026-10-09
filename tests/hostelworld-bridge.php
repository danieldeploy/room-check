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

$linkMail="From: security@hostelworld.com\r\nSubject: Secure login\r\nDate: $date\r\nContent-Type: multipart/mixed; boundary=\"links\"\r\n\r\n--links\r\nContent-Type: text/html\r\n\r\n<a href=\"$url\">Sign in</a>\r\n--links\r\nContent-Type: text/html\r\nContent-Disposition: attachment\r\n\r\n<a href=\"https://inbox.hostelworld.com/inbox/verify?token=attachment-secret\">Private</a>\r\n--links--\r\n";
$linkPacket=HostelworldBridge::packet($linkMail,[$target]);
check($linkPacket!==null && $linkPacket['code']===$code);
check(array_keys($linkPacket)===['source','from','subject','received_at','code']);
$linkJson=json_encode($linkPacket,JSON_THROW_ON_ERROR);
check(!str_contains($linkJson,'https:')&&!str_contains($linkJson,'attachment-secret')&&!str_contains($linkJson,'Sign in'));
check(HostelworldBridge::packet(str_replace($url,$url.'&email=private%40example.com',$linkMail),[$target])===null);
echo "Hostelworld HTML token packet excludes bodies, URLs and attachments.\n";

// Synthetic token; the observed production format is /login/<32 hex digits>.
$pathTarget=['host'=>'inbox.hostelworld.com','path'=>'/login/'];
$pathUrl='https://inbox.hostelworld.com/login/'.str_repeat('a',32).'?Language=English';
$pathCode=HostelworldLinkToken::encode($pathUrl,$pathTarget);
check(HostelworldLinkToken::decode($pathCode,$pathTarget)===$pathUrl);
check(HostelworldLinkToken::extract($pathUrl,[$pathTarget])===$pathCode);
check(HostelworldLinkToken::decode(HostelworldLinkToken::encode(explode('?',$pathUrl)[0],$pathTarget),$pathTarget)===explode('?',$pathUrl)[0]);
foreach ([$pathUrl.'&token=other',$pathUrl.'&Language=French',$pathUrl.'&redirect=evil',
 str_replace('/login/','/other/',$pathUrl),str_replace(str_repeat('a',32),str_repeat('a',31),$pathUrl),
 str_replace(str_repeat('a',32),str_repeat('g',32),$pathUrl),$pathUrl.'#x',
 'https://inbox.hostelworld.com/login/?token='.str_repeat('a',32)] as $invalid) {
 $rejected=false;
 try { HostelworldLinkToken::encode($invalid,$pathTarget); } catch (RuntimeException) { $rejected=true; }
 check($rejected);
}
check(HostelworldLinkToken::extract(str_replace(str_repeat('a',32),str_repeat('b',32),$pathUrl)."\n".$pathUrl,[$pathTarget])===null);
echo "Hostelworld path token format and rejection tests passed.\n";
