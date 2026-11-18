<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/src/Invoices/InvoiceVault.php';require_once dirname(__DIR__).'/src/Invoices/InvoiceAlerts.php';
function ac(bool $v,string $m):void{if(!$v)throw new RuntimeException($m);}
$d=sys_get_temp_dir().'/alert-'.bin2hex(random_bytes(5));mkdir($d,0700);file_put_contents($d.'/master.key',random_bytes(32));chmod($d.'/master.key',0600);
try{
 $v=new InvoiceVault($d);$p=new PDO('sqlite::memory:');$p->exec("CREATE TABLE invoice_notification_settings(id INTEGER,enabled INTEGER,recipient_user_id INTEGER,template_name TEXT);INSERT INTO invoice_notification_settings VALUES(1,1,1,'invoice_failure');CREATE TABLE users(id INTEGER,role TEXT,is_active INTEGER,mobile TEXT);INSERT INTO users VALUES(1,'gerente',1,'123456789');");
 $now=time();$s=['active_process'=>'run','requested'=>false,'processes'=>['run'=>['id'=>'run','requested_at'=>gmdate('c'),'notification_cycle'=>1,'year'=>2026,'months'=>[4]]], 'documents'=>[
  'manual'=>['process_id'=>'run','stage'=>'review','error'=>'receipt_missing_pages','name'=>'PRIVATE-INVOICE.pdf','toconline_state'=>'pending'],
  'running'=>['process_id'=>'run','stage'=>'queued','error'=>'','toconline_state'=>'pending']]];
 $v->save('receipts-workflow.enc',$s);$a=new InvoiceAlerts($p,[]);$calls=[];$send=static function($number,$values,$template)use(&$calls){ac($template==='receipt_process_summary_v1' && count($values)===5,'neutral receipt-only template contract');$calls[]=$values;return 'provider-message';};
 $v->save('receipt-summary-probe-v1.enc',['state'=>'meta_accepted']);
 $a->receipts($v,$send);ac(!$calls,'manual issue must not notify while other invoices run');
 $s['documents']['running']['next_attempt']=$now+360;$s['documents']['running']['error']='drive_network';$v->save('receipts-workflow.enc',$s);
 $a->receipts($v,$send);ac(!$calls,'no summary for a short retry pause');
 $s['documents']['running']['stage']='failed';$s['documents']['running']['next_attempt']=0;$v->save('receipts-workflow.enc',$s);
 unlink($v->path('receipt-summary-probe-v1.enc'));$a->receipts($v,$send);ac(!$calls,'summaries remain queued until dedicated template acceptance');
 $v->save('receipt-summary-probe-v1.enc',['state'=>'meta_accepted']);$a->receipts($v,$send);$a->receipts($v,$send);ac(count($calls)===1,'one consolidated summary for both problems');
 ac(str_contains($calls[0][3],InvoiceText::TEXT['receipt_missing_pages'][0]) && str_contains($calls[0][3],InvoiceText::TEXT['drive_network'][0]),'summary includes all safe reasons');
 ac(!str_contains(json_encode($calls),'PRIVATE-INVOICE'),'no invoice contents in alert');
 $s['documents']['running']=['process_id'=>'run','stage'=>'queued','next_attempt'=>$now+86400,'retry_kind'=>'quota','quota_outcome'=>'waiting','error'=>'gemini_daily_quota','toconline_state'=>'pending'];$s['processes']['run']['notification_cycle']++;$v->save('receipts-workflow.enc',$s);
 $a->receipts($v,$send);$a->receipts($v,$send);ac(count($calls)===2,'daily quota pause has one summary');
 $s['documents']['running']=['process_id'=>'run','stage'=>'archived','quota_outcome'=>'recovered','error'=>'','toconline_state'=>'toc_accepted'];$v->save('receipts-workflow.enc',$s);
 $a->receipts($v,$send);ac(count($calls)===3 && str_contains($calls[2][3],InvoiceText::TEXT['gemini_quota_recovered'][0]),'new recovery outcome sends a new consolidated summary');
 $s['documents']['manual']=['process_id'=>'run','stage'=>'archived','toconline_state'=>'toc_accepted'];$v->save('receipts-workflow.enc',$s);
 $a->receipts($v,$send);$a->receipts($v,$send);ac(count($calls)===4 && str_contains($calls[3][3],'Concluído'),'success is a final summary too');
 // A new unsuccessful manual cycle can legitimately have the same counts/reason.
 $s['processes']['run']['notification_cycle']++;
 $s['documents']['manual']=['process_id'=>'run','stage'=>'review','error'=>'receipt_missing_pages','toconline_state'=>'pending'];$v->save('receipts-workflow.enc',$s);
 $a->receipts($v,static function(){throw new RuntimeException('uncertain provider result');});$a->receipts($v,$send);ac(count($calls)===4,'uncertain summary never blindly resent');
 // Existing stopped history is baselined rather than replayed on deployment.
 $s['processes']['old']=['id'=>'old','year'=>2026,'months'=>[3]];$s['documents']['old']=['process_id'=>'old','stage'=>'archived','toconline_state'=>'toc_accepted'];$v->save('receipts-workflow.enc',$s);
 $a->receipts($v,$send);ac(count($calls)===4,'historical completion is not a new event');
 $s['processes']['run']['notification_cycle']++;$v->save('receipts-workflow.enc',$s);$a->receipts($v,$send);$a->receipts($v,$send);
 ac(count($calls)===5,'new manual cycle reports an unchanged failure once');
 $s['documents']['manual']=['process_id'=>'run','stage'=>'archived','toconline_state'=>'toc_accepted'];$v->save('receipts-workflow.enc',$s);$a->receipts($v,$send);
 $s['documents']['manual']=['process_id'=>'run','stage'=>'review','error'=>'receipt_missing_pages','toconline_state'=>'pending'];$v->save('receipts-workflow.enc',$s);$a->receipts($v,$send);
 ac(count($calls)===6,'previously delivered fingerprint is not resent after an oscillation');
 // Two affected members of one unresolved batch generate a single actionable process summary.
 $calls=[];$s['documents']['manual']=$s['documents']['running']=['process_id'=>'run','stage'=>'archived','toconline_state'=>'toc_review',
     'delivery_reason'=>'toc_reply_batch_incomplete','name'=>'PRIVATE-INVOICE.pdf'];
 $s['processes']['run']['notification_cycle']++;$v->save('receipts-workflow.enc',$s);$a->receipts($v,$send);$a->receipts($v,$send);
 ac(count($calls)===1 && str_contains($calls[0][3],InvoiceTocReplyText::REASONS['toc_reply_batch_incomplete'][0])
     && str_contains($calls[0][4],'compare os PDFs') && !str_contains(json_encode($calls),'PRIVATE-INVOICE'),'actionable, redacted group summary');
 echo "Receipt consolidated summaries, recovery, deduplication and uncertainty passed\n";
}finally{foreach(glob($d.'/*')?:[]as$f)unlink($f);rmdir($d);}
