<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/src/Invoices/InvoiceReceiptReport.php';
function pg(bool $v,string $why):void{if(!$v)throw new RuntimeException($why);}
pg(method_exists(InvoiceReceiptReport::class,'progress'),'read-only processing status required');
$base=['requested'=>false,'documents'=>[]];
$d=['stage'=>'archived','toconline_state'=>'toc_submitted','archived_at'=>'now'];
$s=$base;$s['documents']=['a'=>$d];pg(InvoiceReceiptReport::progress($s)['state']==='receipt_finished_pending','submitted email is not completion');
$s['documents']['a']['toconline_state']='toc_accepted';pg(InvoiceReceiptReport::progress($s)['state']==='receipt_finished','confirmed workflow finalized');
$s['documents']['b']=['stage'=>'queued','toconline_state'=>'pending','next_attempt'=>time()+45];$p=InvoiceReceiptReport::progress($s);pg($p['state']==='receipt_retry_wait' && $p['next_at']>time(),'durable retry countdown');
$s['documents']['b']['next_attempt']=0;pg(InvoiceReceiptReport::progress($s)['state']==='receipt_processing','runnable work processing');
$s['documents']['b']['stage']='failed';pg(InvoiceReceiptReport::progress($s)['state']==='receipt_interrupted','exhausted retries not successful');
$s['documents']['b']['stage']='review';pg(InvoiceReceiptReport::progress($s)['state']==='receipt_finished_pending','manual review distinct from running');
$r=InvoiceReceiptReport::summary(['source'=>['children'=>['a','b'],'stage'=>'split_completed'],'a'=>$d+['source_id'=>'source'],'b'=>['stage'=>'superseded','source_id'=>'source']]);pg($r['documents']===1,'superseded pages excluded from invoice totals');
echo "Receipt progress, real completion and retry countdown passed\n";
