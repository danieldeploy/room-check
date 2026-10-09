<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/src/Invoices/InvoiceHostelworldPipe.php';
function hwCheck(bool $ok,string $message):void { if(!$ok) throw new RuntimeException($message); }
$date=gmdate('r');
$body="Your Hostelworld login verification code is 047291.\r\n";
$mail="From: Hostelworld <no-reply@hostelworld.com>\r\nTo: manager@example.test\r\nDate: {$date}\r\nSubject: Your Hostelworld security code\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n{$body}";
$parsed=InvoiceHostelworldPipe::parse($mail);
hwCheck(is_array($parsed) && $parsed['from']==='no-reply@hostelworld.com','valid sender parsed');
hwCheck(str_contains($parsed['text'],'047291'),'plain body parsed');
hwCheck(InvoiceHostelworldPipe::parse(str_replace('no-reply@hostelworld.com','attacker.example@evil.test',$mail))===null,'wrong sender rejected');
hwCheck(InvoiceHostelworldPipe::parse(str_replace('Your Hostelworld security code','Monthly Hostelworld statement',$mail))===null,'unrelated subject rejected');
hwCheck(InvoiceHostelworldPipe::parse(str_replace('047291','047291 and 912003',$mail))===null,'ambiguous codes rejected');
$encoded="From: no-reply@hostelworld.com\r\nDate: {$date}\r\nSubject: =?UTF-8?Q?Hostelworld_login_verification_code?=\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\nYour code is 047291.";
hwCheck(InvoiceHostelworldPipe::parse($encoded)!==null,'encoded subject and quoted printable body parsed');
hwCheck(InvoiceHostelworldPipe::parse("From envelope@example.invalid ".date('D M j H:i:s Y')."\n".$mail)!==null,'Exim envelope is removed');
try { InvoiceHostelworldPipe::parse(str_repeat('x',InvoiceTocPipe::MAX_BYTES+1)); throw new LogicException('size accepted'); }
catch (RuntimeException $e) { hwCheck($e->getMessage()==='toc_pipe_invalid','message size bounded'); }
echo "Hostelworld filtered email pipe tests passed\n";
