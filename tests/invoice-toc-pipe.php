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
// Default Exim pipe envelope, absent from ordinary exported .eml messages.
$envelope="From envelope@example.invalid Sun Oct  4 15:35:13 2026\n";
$wrapped=$envelope.$plain;
pipeCheck(InvoiceTocPipe::parse($wrapped)===$parsed,'Exim envelope removed before headers/hash');
pipeCheck(InvoiceTocPipe::parse(str_replace("\n","\r\n",$envelope).$plain)===$parsed,'CRLF envelope accepted');
pipeCheck(InvoiceTocPipe::parse(str_replace('15:35:13','16:35:13',$envelope).$plain)===$parsed,'delivery-time changes do not defeat replay deduplication');
pipeCheck(InvoiceTocPipe::parse("From no_reply@toconline.pt Sun Oct  4 15:35:13 2026\n".str_replace('no_reply@toconline.pt','attacker@example.invalid',$plain))===null,'envelope sender is not trusted');
pipeCheck(InvoiceTocPipe::parse($envelope."Subject: missing actual sender\n\n".$body)===null,'envelope cannot replace From header');
pipeCheck(InvoiceTocPipe::parse($envelope."From: no_reply@toconline.pt\r\n".$plain)===null,'duplicate From remains rejected under envelope');
try {InvoiceTocPipe::parse($envelope.$wrapped);throw new LogicException('multiple envelope lines');} catch (RuntimeException $e) {pipeCheck($e->getMessage()==='toc_pipe_header_line','only one leading envelope removed');}
try {InvoiceTocPipe::parse("Bad header line\n".$plain);throw new LogicException('invalid headers');} catch (RuntimeException $e) {pipeCheck($e->getMessage()==='toc_pipe_header_line','arbitrary malformed header remains rejected');}

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
pipeCheck(!str_contains(InvoiceTocPipe::parse("From: no_reply@toconline.pt\r\n\r\nbooking_".substr($key,0,63).'.pdf')['text'],'booking-'),'short normalized token rejected');
foreach (['hostelworld-','hostelworld_'] as $prefix) pipeCheck(InvoiceTocPipe::parse(str_replace('booking-',$prefix,$plain))['text']===$parsed['text'],'Hostelworld normalized correlation');
$legacy="From: no_reply@toconline.pt\r\n\r\ninvoice.pdf";
pipeCheck(InvoiceTocPipe::parse($legacy)['text']==='','legacy generic filename not guessed');
$ref="From: no_reply@toconline.pt\r\nReferences: <toc-".$key."@check.welcomehostel.pt>\r\n\r\narchive response";
pipeCheck(str_contains(InvoiceTocPipe::parse($ref)['text'],$key),'reference correlation');
$invalid="From: no_reply@toconline.pt\r\nContent-Transfer-Encoding: base64\r\n\r\n!!!!";
pipeCheck(InvoiceTocPipe::parse($invalid)['text']==='','malformed body retained only as unmatched hash');
try {InvoiceTocPipe::parse(str_repeat('x',InvoiceTocPipe::MAX_BYTES+1));throw new LogicException('size bound');} catch (RuntimeException) {}
$parserMail = static fn(string $body, string $subject='Ficheiros arquivados com sucesso - Active Lines, Lda', string $type='plain') =>
    "From: no_reply@toconline.pt\r\nSubject: $subject\r\nContent-Type: text/$type; charset=utf-8\r\n\r\n".$body;
$archiveHeading='Ficheiros aceites para arquivo:';
$purchaseHeading='Ficheiros arquivados e associados automaticamente a faturas de compra:';
$wrongHeading='Foram recusados os seguintes ficheiros por não pertencerem à sua empresa:';
$duplicateHeading='Foram recusados os seguintes ficheiros por já constarem no seu arquivo:';
$one=str_repeat('a',64);
$two=str_repeat('b',64);
$purchaseRow=static fn(string $number,string $reference) =>
    '<tr><td>Fornecedor NIF:</td><td>500000000</td></tr><tr><td>Fornecedor:</td><td>Synthetic</td></tr>'
    .'<tr><td>e-Fatura:</td><td>'.$number.'</td></tr><tr><td>Fatura de compra:</td><td>'.$reference.' (*)</td></tr>';
$archiveRow='<tr><td>Ficheiro:</td><td>receipt_'.$one.'.pdf</td></tr><tr><td>Fornecedor NIF:</td><td>500000001</td></tr>'
    .'<tr><td>Fornecedor:</td><td>Synthetic 2</td></tr><tr><td>e-Fatura:</td><td>—</td></tr>';
$html='<p>'.$purchaseHeading.'</p><table>'.$purchaseRow('FT DEMO/1','FC DEMO/5').$purchaseRow('—','DSP DEMO/7')
    .$purchaseRow('FT DEMO/3','Em preparação..').'</table><p>'.$archiveHeading.'</p><table>'.$archiveRow.'</table><p>'.$wrongHeading.'</p>→ receipt_'.$two.'.pdf';
$result=InvoiceTocPipe::parse($parserMail($html,type:'html'));
$report=$result['batch_reply']??[];
pipeCheck(($report['complete']??false) && $report['unassigned']===0,'mixed provider sections fully parsed');
pipeCheck($report['named'][$one]['state']==='toc_accepted' && $report['named'][$one]['issuer_nif']==='500000001','named file is distinct from adjacent purchase records');
pipeCheck($report['named'][$two]['reason']==='toc_provider_wrong_recipient','one wrong-company file has its own explicit reason');
pipeCheck(array_column($report['purchases'],'reference')===['FC DEMO/5','DSP DEMO/7',''] && $report['purchases'][1]['invoice_number']==='' && $report['purchases'][2]['status']==='pending','FC, DSP, absent e-Fatura and pending parsed');
pipeCheck(!str_contains(json_encode($report),'Synthetic'),'no supplier names retained');
$unknown=InvoiceTocPipe::parse($parserMail('Indicação desconhecida: receipt_'.$two.'.pdf'));
pipeCheck($unknown['outcome']==='toc_review','success subject and unknown body cannot prove archival');
$refusals=InvoiceTocPipe::parse($parserMail($archiveHeading."\nreceipt_$one.pdf\n".$duplicateHeading."\ninvoice.pdf\n".$wrongHeading."\nother.pdf"))['batch_reply'];
pipeCheck($refusals['complete'] && $refusals['unassigned']===2 && array_column($refusals['refusals'],'state')===['toc_existing','toc_rejected'],'unattributed refusals counted by kind');
foreach (['Arquivo digital','Ficheiros arquivados com sucesso - Outra empresa'] as $subject) {
    $foreign=InvoiceTocPipe::parse($parserMail('<p>'.$purchaseHeading.'</p>'.$purchaseRow('FT DEMO/1','FC DEMO/5'),$subject,'html'));
    pipeCheck(!$foreign['batch_reply']['complete'] && !$foreign['batch_reply']['purchases'] && !$foreign['purchase_identity'],'fiscal grouping requires expected signed company subject');
}
$wrongCompany=InvoiceTocPipe::parse($parserMail($archiveHeading."\nreceipt_$one.pdf",'Ficheiros arquivados com sucesso - Outra empresa'));
pipeCheck($wrongCompany['batch_reply']['named'][$one]['state']==='toc_review' && $wrongCompany['outcome']==='toc_review','named outcome for another explicit buyer requires review');
$repeated=InvoiceTocPipe::parse($parserMail($archiveHeading."\nreceipt_$one.pdf\n".$wrongHeading."\nreceipt_$one.pdf"))['batch_reply'];
pipeCheck(!$repeated['complete'] && $repeated['named'][$one]['state']==='toc_review','conflicting named verdicts cannot confirm a document');
$unknownSection=InvoiceTocPipe::parse($parserMail($archiveHeading."\nreceipt_$one.pdf\nResultado adicional:\nreceipt_$two.pdf"))['batch_reply'];
pipeCheck(!$unknownSection['complete'] && $unknownSection['named'][$one]['state']==='toc_accepted' && $unknownSection['named'][$two]['state']==='toc_review','unknown section cannot inherit the preceding verdict');
$quoted=InvoiceTocPipe::parse($parserMail($archiveHeading."\n> receipt_$one.pdf"))['batch_reply'];
pipeCheck(!$quoted['complete'] && $quoted['named'][$one]['state']==='toc_review','quoted filename is not a provider list item');
$alternatives=static fn($plain,$html) => "From: no_reply@toconline.pt\r\nContent-Type: multipart/alternative; boundary=sample\r\n\r\n"
    ."--sample\r\nContent-Type: text/plain\r\n\r\n".$plain."\r\n--sample\r\nContent-Type: text/html\r\n\r\n".$html."\r\n--sample--\r\n";
$agree=InvoiceTocPipe::parse($alternatives($archiveHeading."\nreceipt_$one.pdf","<p>$archiveHeading</p>receipt_$one.pdf"))['batch_reply'];
pipeCheck($agree['counts']['archived']===1 && $agree['complete'],'equivalent multipart alternatives counted once');
$conflict=InvoiceTocPipe::parse($alternatives($archiveHeading."\nreceipt_$one.pdf","<p>$wrongHeading</p>receipt_$one.pdf"));
pipeCheck(!$conflict['batch_reply']['complete'] && !$conflict['batch_reply']['named'] && $conflict['outcome']==='toc_review','conflicting MIME alternatives stop automatic verdicts');
$tooMany=[];
for ($i=0;$i<21;$i++) $tooMany[]='receipt_'.hash('sha256','synthetic-'.$i).'.pdf';
$overflow=InvoiceTocPipe::parse($parserMail($archiveHeading."\n".implode("\n",$tooMany)));
pipeCheck(!$overflow['batch_reply']['complete'] && !$overflow['attachments'] && $overflow['text']==='','reports exceeding provider batch limit are never truncated into successes');
$dir=sys_get_temp_dir().'/toc-pipe-'.bin2hex(random_bytes(8));mkdir($dir,0700);file_put_contents($dir.'/master.key',random_bytes(32));chmod($dir.'/master.key',0600);
try {
 $vault=new InvoiceVault($dir);$pipe=new InvoiceTocPipe($vault);
 pipeCheck($pipe->enqueue($plain) && $pipe->enqueue($wrapped),'enqueue idempotent across envelope variants');
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
 // An old queued projection and an old recent event must not hide the richer signed batch.
 $oldMail=$parserMail($html,type:'html');
 $new=InvoiceTocPipe::parse($oldMail);
 $queueName='toc-pipe-'.$new['hash'].'.enc';
 $old=['from'=>$new['from'],'text'=>'','hash'=>$new['hash'],'outcome'=>'toc_review','received_at'=>gmdate('c'),
       'authentication'=>['code'=>'verified','verified'=>true]];
 $vault->save($queueName,$old);
 $status=$vault->read('toconline-pipe-status.enc');$status['recent'][]=$new['hash'];$vault->save('toconline-pipe-status.enc',$status);
 $pipe->enqueue($oldMail);
 $saved=$vault->read($queueName);
 pipeCheck($saved['authentication']===$old['authentication'] && $saved['batch_reply']===$new['batch_reply'],'recovered raw bytes upgrade old queued projection without losing verified proof');
 $collector=new class {public array $proofs=[];public function receive(...$args):array{$this->proofs[]=$args[4];return ['result'=>'matched'];}};
 $pipe->process($collector);
 pipeCheck(count($collector->proofs)===1 && $collector->proofs[0]['batch_reply']===$new['batch_reply'],'versioned replay forwards every section to receiver');
 $vault->save($queueName,$saved);$pipe->process($collector);
 pipeCheck(count($collector->proofs)===1,'same verified version is processed once');
 $pipe->enqueue($oldMail);$pipe->process($collector);
 pipeCheck(count($collector->proofs)===1,'later unavailable verification cannot supersede the same verified bytes');
 // Execute the real CLI entry point in an isolated fake hosting layout; no network or real mail.
 $app=$dir.'/public_html/check';$cron=$dir.'/room-check-private/cron';mkdir($app.'/src/Invoices',0700,true);mkdir($cron,0700,true);
 foreach (['InvoiceTocPipe.php','InvoiceTocReplyParser.php','InvoiceVault.php','InvoiceTocAuthentication.php'] as $name)copy(dirname(__DIR__).'/src/Invoices/'.$name,$app.'/src/Invoices/'.$name);
 file_put_contents($app.'/config.php','<?php return '.var_export(['invoices'=>['private_dir'=>$dir]],true).';');
 copy(dirname(__DIR__).'/cron/toconline-reply.php',$cron.'/toconline-reply.php');
 $run=static function(string $input)use($cron):array{$proc=proc_open([PHP_BINARY,$cron.'/toconline-reply.php'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fwrite($pipes[0],$input);fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);return [proc_close($proc),$out,$err];};
 pipeCheck($run($plain)===[0,'',''],'real CLI accepts silently');
 pipeCheck($run($wrapped)===[0,'',''],'real CLI accepts Exim envelope silently');
 pipeCheck($run(str_repeat('x',InvoiceTocPipe::MAX_BYTES+1))===[75,'',''],'real CLI temporary failure without mail leak');
 echo "TOConline private pipe tests passed\n";
} finally {
 $items=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
 foreach($items as $item) {if($item->isDir())rmdir($item->getPathname());else unlink($item->getPathname());}rmdir($dir);
}


