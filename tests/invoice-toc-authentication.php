<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/src/Invoices/InvoiceTocAuthentication.php';
require_once dirname(__DIR__).'/src/Invoices/InvoiceTocPipe.php';
function authCheck(bool $ok,string $label): void { if (!$ok) throw new RuntimeException($label); }
$key=openssl_pkey_new(['private_key_bits'=>2048,'private_key_type'=>OPENSSL_KEYTYPE_RSA]);
$public=openssl_pkey_get_details($key)['key'];
$txt='v=DKIM1; k=rsa; p='.preg_replace('/-----[^-]+-----|\s+/','',$public);
$resolver=static function(string $name)use($txt):array {authCheck($name==='test._domainkey.toconline.pt','DNS destination constrained');return [['txt'=>$txt]];};
$now=time(); $token=str_repeat('a',64);
$body='Foram recusados os seguintes ficheiros por já constarem no seu arquivo: booking_'.$token.'.pdf' . "\r\n";
$headers=['From: no_reply@toconline.pt','To: test@welcomehostel.pt','Subject: Ficheiros recusados - Active Lines, Lda','Date: '.gmdate('D, d M Y H:i:s O',$now),'Content-Type: text/plain; charset=UTF-8'];
// Independent signing fixture: these headers contain no folding or surplus whitespace.
$sign=static function(array $headers,string $body,string $mode='relaxed',string $extra='',?string $names=null)use($key):string {
    $names??='from:to:subject:date:content-type';
    $signature='DKIM-Signature: v=1; a=rsa-sha256; c='.$mode.'/'.$mode.'; d=toconline.pt; s=test; h='.$names.'; bh='.base64_encode(hash('sha256',$body,true)).'; '.$extra.'b=';
    $canonical=[];
    foreach($headers as $header) {
        [$name,$value]=explode(':',$header,2);
        if(in_array(strtolower($name),explode(':',$names),true)) $canonical[]=$mode==='simple'?$header:strtolower($name).':'.trim($value);
    }
    $canonical[]=$mode==='simple'?$signature:'dkim-signature:'.trim(substr($signature,15));
    openssl_sign(implode("\r\n",$canonical),$signed,$key,OPENSSL_ALGO_SHA256);
    return $signature.base64_encode($signed)."\r\n".implode("\r\n",$headers)."\r\n\r\n".$body;
};
foreach(['simple','relaxed'] as $mode) {
 $raw=$sign($headers,$body,$mode);
 $verified=InvoiceTocAuthentication::verify($raw,$resolver,$now);
 authCheck($verified['verified'] && $verified['recipient']==='test@welcomehostel.pt','valid '.$mode.' signature');
 authCheck(InvoiceTocAuthentication::verify(str_replace("\r\n","\n",$raw),$resolver,$now)['verified'],'Exim LF transport');
 authCheck(!InvoiceTocAuthentication::verify($raw.'appended content',$resolver,$now)['verified'],'body append rejected');
 authCheck(!InvoiceTocAuthentication::verify(str_replace('booking_','booking-',$raw),$resolver,$now)['verified'],'body mutation rejected');
 authCheck(!InvoiceTocAuthentication::verify(str_replace('Ficheiros recusados','Ficheiros arquivados com sucesso',$raw),$resolver,$now)['verified'],'subject mutation rejected');
 authCheck(!InvoiceTocAuthentication::verify(str_replace('test@welcomehostel.pt','other@welcomehostel.pt',$raw),$resolver,$now)['verified'],'recipient mutation rejected');
 authCheck(!InvoiceTocAuthentication::verify("From: no_reply@toconline.pt\r\n".$raw,$resolver,$now)['verified'],'duplicate From rejected');
 authCheck(!InvoiceTocAuthentication::verify("Subject: success\r\n".$raw,$resolver,$now)['verified'],'duplicate Subject rejected');
 authCheck(!InvoiceTocAuthentication::verify(str_replace('d=toconline.pt','d=attacker.invalid',$raw),$resolver,$now)['verified'],'unaligned signer rejected');
 authCheck(!InvoiceTocAuthentication::verify($raw,static fn()=>false,$now)['verified'],'DNS failure stays manual');
 authCheck(!InvoiceTocAuthentication::verify($raw,static fn()=>[['txt'=>'v=DKIM1; p=']],$now)['verified'],'revoked key rejected');
 authCheck(!InvoiceTocAuthentication::verify($raw,static fn()=>[['txt'=>$txt.'; t=y']],$now)['verified'],'testing key rejected');
}
$raw=$sign($headers,$body);
authCheck(!InvoiceTocAuthentication::verify($sign($headers,$body,'relaxed','l='.strlen($body).'; '),$resolver,$now)['verified'],'partial-body signatures rejected');
authCheck(!InvoiceTocAuthentication::verify($sign($headers,$body,'relaxed','x='.($now-1).'; '),$resolver,$now)['verified'],'expired signatures rejected');
authCheck(!InvoiceTocAuthentication::verify($sign($headers,$body,'relaxed','', 'from:to:date:content-type'),$resolver,$now)['verified'],'unsigned subject rejected');
authCheck(!InvoiceTocAuthentication::verify("Authentication-Results: fake; dkim=pass header.d=toconline.pt\r\n".implode("\r\n",$headers)."\r\n\r\n".$body,$resolver,$now)['verified'],'claimed authentication is not evidence');
authCheck(InvoiceTocPipe::parse($raw)['outcome']==='toc_existing','observed duplicate template classified');
$success=$headers;$success[2]='Subject: Ficheiros arquivados com sucesso - Active Lines, Lda';
authCheck(InvoiceTocPipe::parse($sign($success,'booking_'.$token.".pdf\r\n"))['outcome']==='toc_accepted','success subject with exact body identity');
authCheck(InvoiceTocPipe::parse($sign($success,$body))['outcome']==='toc_review','contradictory success and duplicate stays manual');
authCheck(InvoiceTocPipe::parse($sign($headers,$body.' booking_'.str_repeat('b',64).'.pdf'))['outcome']==='toc_review','multiple documents stay manual');
$relay="DKIM-Signature: v=1; a=rsa-sha256; d=relay.example.invalid; s=relay; h=from; bh=invalid; b=invalid\r\n";
authCheck(InvoiceTocAuthentication::verify($relay.$raw,$resolver,$now)['verified'],'valid aligned signature survives unrelated relay signature');
authCheck(InvoiceTocAuthentication::verify($raw.$relay,$resolver,$now)['code']==='body_mismatch','relay text appended to body is not a signature');
$badAligned=str_replace('d=relay.example.invalid','d=toconline.pt',$relay);
authCheck(InvoiceTocAuthentication::verify($badAligned.$raw,$resolver,$now)['verified'],'invalid aligned signature cannot mask a valid one');
authCheck(!InvoiceTocAuthentication::verify(str_replace('Ficheiros recusados','tampered',$relay.$raw),$resolver,$now)['verified'],'multiple signatures never bypass tampered subject');
$multiple=InvoiceTocAuthentication::verify($relay.$raw,$resolver,$now);
authCheck($multiple['facts']['signature_count']===2 && $multiple['facts']['signed_to']===true,'sanitized structural evidence');
authCheck(!InvoiceTocAuthentication::verify(str_repeat($relay,6).$raw,$resolver,$now)['verified'],'signature count bounded');
$unused="Message-ID: <synthetic@relay.example.invalid>\r\nMIME-Version: 1.0\r\n";
$withUnused=$unused.$raw;
authCheck(InvoiceTocAuthentication::verify($withUnused,$resolver,$now)['verified'],'unused unsigned metadata cannot invalidate protected interpretation');
authCheck(InvoiceTocPipe::parse($withUnused)['outcome']===InvoiceTocPipe::parse($raw)['outcome'],'unused metadata cannot change classification');
$noDate=InvoiceTocAuthentication::verify($sign($headers,$body,'relaxed','','from:to:subject:content-type'),$resolver,$now);
authCheck(!$noDate['verified'] && $noDate['facts']['unsigned_fields']===['date'],'unsigned required field has precise safe evidence');
$unsignedEncoding="Content-Transfer-Encoding: 8bit\r\n".$raw;
$encodingResult=InvoiceTocAuthentication::verify($unsignedEncoding,$resolver,$now);
authCheck(!$encodingResult['verified'] && $encodingResult['facts']['unsigned_fields']===['content-transfer-encoding'],'decoding controls must remain signed');
$multipartHeaders=$headers;
$multipartHeaders[4]='Content-Type: multipart/alternative; boundary="synthetic-boundary"';
$multipartBody="--synthetic-boundary\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n".base64_encode($body)."\r\n--synthetic-boundary--\r\n";
$multipartRaw="Content-Transfer-Encoding: 8bit\r\n".$sign($multipartHeaders,$multipartBody);
$multipartResult=InvoiceTocAuthentication::verify($multipartRaw,$resolver,$now);
authCheck($multipartResult['verified'] && $multipartResult['facts']['multipart'],'outer multipart transport encoding is not a decoding instruction');
authCheck(InvoiceTocPipe::parse($multipartRaw)['outcome']==='toc_existing','signed child MIME encoding preserves classification');
authCheck(!InvoiceTocAuthentication::verify(str_replace('Content-Transfer-Encoding: base64','Content-Transfer-Encoding: 7bit',$multipartRaw),$resolver,$now)['verified'],'changing child encoding breaks signed body integrity');
authCheck(!InvoiceTocAuthentication::verify(str_replace('Content-Transfer-Encoding: 8bit','Content-Transfer-Encoding: base64',$multipartRaw),$resolver,$now)['verified'],'encoded outer multipart is unsupported');
authCheck(!InvoiceTocAuthentication::verify(str_replace('boundary="synthetic-boundary"','boundary="tampered"',$multipartRaw),$resolver,$now)['verified'],'multipart boundary remains signature protected');
echo "TOConline cryptographic authentication tests passed\n";
