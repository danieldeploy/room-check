<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/src/Invoices/InvoiceTocPipe.php';
function pipeCheck(bool $ok,string $message): void {if (!$ok) throw new RuntimeException($message);}
$key=hash('sha256','synthetic invoice identity');
$body='Foram recusados os seguintes ficheiros por já constarem no seu arquivo: booking-'.$key.'.pdf';
$plain="From: TOConline <no_reply@toconline.pt>\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n".$body;
$parsed=InvoiceTocPipe::parse($plain);
pipeCheck(str_contains($parsed['text'],$key) && str_contains($parsed['text'],'por já constarem'),'plain receipt');
pipeCheck(!str_contains($parsed['text'],'Foram recusados'),'no raw prose retained');
pipeCheck(InvoiceTocPipe::parse(str_replace('no_reply@toconline.pt','attacker@example.org',$plain))===null,'wrong sender');
pipeCheck(InvoiceTocPipe::parse("From: no_reply@toconline.pt\r\n".$plain)===null,'duplicate from rejected');
$base="From: no_reply@toconline.pt\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n".base64_encode('<p>'.$body.'</p>');
pipeCheck(InvoiceTocPipe::parse($base)['text']===$parsed['text'],'base64 HTML');
$qp="From: no_reply@toconline.pt\nContent-Type: text/plain; charset=UTF-8\nContent-Transfer-Encoding: quoted-printable\n\n".quoted_printable_encode($body);
pipeCheck(InvoiceTocPipe::parse($qp)['text']===$parsed['text'],'quoted printable');
$attachmentKey=hash('sha256','must not read attachment');
$multipart="From: no_reply@toconline.pt\r\nContent-Type: multipart/mixed; boundary=\"test-boundary\"\r\n\r\npreamble\r\n--test-boundary\r\nContent-Type: text/plain\r\n\r\n".$body."\r\n--test-boundary\r\nContent-Type: text/plain\r\nContent-Disposition: attachment; filename=test.txt\r\n\r\nbooking-".$attachmentKey.".pdf\r\n--test-boundary--\r\nepilogue";
pipeCheck(InvoiceTocPipe::parse($multipart)['text']===$parsed['text'],'multipart excludes attachments');
// TOConline normalizes the attachment hyphen to underscore in its HTML reply.
$normalized=str_replace('booking-','booking_',$body);
$realShape="From: no_reply@toconline.pt\r\nContent-Type: multipart/mixed; boundary=\"--==_synthetic_boundary\"; charset=\"UTF-8\"\r\n\r\n----==_synthetic_boundary\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n".quoted_printable_encode('<p>'.$normalized.'</p>')."\r\n----==_synthetic_boundary--\r\n";
pipeCheck(InvoiceTocPipe::parse($realShape)['text']===$parsed['text'],'normalized provider filename in multipart HTML without references');
pipeCheck(!str_contains(InvoiceTocPipe::parse(str_replace($key,substr($key,0,63),$realShape))['text'],'booking-'),'short normalized token rejected');
$legacy="From: no_reply@toconline.pt\r\n\r\ninvoice.pdf";
pipeCheck(InvoiceTocPipe::parse($legacy)['text']==='','legacy generic filename not guessed');
$ref="From: no_reply@toconline.pt\r\nReferences: <toc-".$key."@check.welcomehostel.pt>\r\n\r\narchive response";
pipeCheck(str_contains(InvoiceTocPipe::parse($ref)['text'],$key),'reference correlation');
$invalid="From: no_reply@toconline.pt\r\nContent-Transfer-Encoding: base64\r\n\r\n!!!!";
pipeCheck(InvoiceTocPipe::parse($invalid)['text']==='','malformed body retained only as unmatched hash');
try {InvoiceTocPipe::parse(str_repeat('x',InvoiceTocPipe::MAX_BYTES+1));throw new LogicException('size bound');} catch (RuntimeException) {}
$dir=sys_get_temp_dir().'/toc-pipe-'.bin2hex(random_bytes(8));mkdir($dir,0700);file_put_contents($dir.'/master.key',random_bytes(32));chmod($dir.'/master.key',0600);
try {
 $vault=new InvoiceVault($dir);$pipe=new InvoiceTocPipe($vault);
 pipeCheck($pipe->enqueue($plain) && $pipe->enqueue($plain),'enqueue idempotent');
 pipeCheck($pipe->status()['pending']===1,'single queued receipt');
 $raw=file_get_contents(glob($dir.'/toc-pipe-*.enc')[0]);
 pipeCheck(!str_contains($raw,$key) && !str_contains($raw,'no_reply'),'queue encrypted');
 $receiver=new class {public int $calls=0;public bool $fail=true;public function receive(string $from,string $ref,string $text,string $hash): array {if($this->fail)throw new RuntimeException('database offline');$this->calls++;return ['result'=>'matched'];}};
 try {$pipe->process($receiver);}catch(RuntimeException){}
 pipeCheck($pipe->status()['pending']===1,'failure preserves queue');
 $receiver->fail=false;$pipe->process($receiver);
 pipeCheck($receiver->calls===1 && $pipe->status()['pending']===0 && $pipe->status()['matched']===1,'queue drains after recovery');
 $pipe->enqueue($plain);$pipe->process($receiver);
 pipeCheck($receiver->calls===1 && $pipe->status()['matched']===1,'replayed receipt not double counted');
 $pipe->enqueue($legacy);
 $unmatched=new class {public function receive(...$args): array {return ['result'=>'unmatched'];}};
 $pipe->process($unmatched);pipeCheck($pipe->status()['unmatched']===1,'unmatched receipt visible');
 // Execute the real CLI entry point in an isolated fake hosting layout; no network or real mail.
 $app=$dir.'/public_html/check';$cron=$dir.'/room-check-private/cron';mkdir($app.'/src/Invoices',0700,true);mkdir($cron,0700,true);
 foreach (['InvoiceTocPipe.php','InvoiceVault.php'] as $name)copy(dirname(__DIR__).'/src/Invoices/'.$name,$app.'/src/Invoices/'.$name);
 file_put_contents($app.'/config.php','<?php return '.var_export(['invoices'=>['private_dir'=>$dir]],true).';');
 copy(dirname(__DIR__).'/cron/toconline-reply.php',$cron.'/toconline-reply.php');
 $run=static function(string $input)use($cron):array{$proc=proc_open([PHP_BINARY,$cron.'/toconline-reply.php'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fwrite($pipes[0],$input);fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);return [proc_close($proc),$out,$err];};
 pipeCheck($run($plain)===[0,'',''],'real CLI accepts silently');
 pipeCheck($run(str_repeat('x',InvoiceTocPipe::MAX_BYTES+1))===[75,'',''],'real CLI temporary failure without mail leak');
 echo "TOConline private pipe tests passed\n";
} finally {
 $items=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
 foreach($items as $item) {if($item->isDir())rmdir($item->getPathname());else unlink($item->getPathname());}rmdir($dir);
}
