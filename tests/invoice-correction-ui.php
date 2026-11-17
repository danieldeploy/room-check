<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/src/Invoices/InvoiceReceiptReport.php';
require_once dirname(__DIR__).'/src/Invoices/InvoiceReceipts.php';
require_once dirname(__DIR__).'/src/Security/Csrf.php';
function cu(bool $ok,string $message): void {if(!$ok)throw new RuntimeException($message);}
$render=static function(array $docs,bool $review=true,bool $busy=false): string {
 $receiptHistoryRendering=true;$canHistory=true;$canReview=$review;$canRun=false;$all=$documents=$docs;$selectedRunning=false;$filter='all';
 $fullState=['documents'=>$docs,'requested'=>$busy];$state=$fullState;$tocSettings=['nif'=>'500000000'];$sendEnabled=false;$requestError='';$page=1;$selectionQuery=[];
 $t=static fn($key)=>htmlspecialchars(InvoiceText::get($key),ENT_QUOTES,'UTF-8');$escape=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');
 ob_start();require dirname(__DIR__).'/admin/receipt-history.php';return ob_get_clean();
};
$category=['id'=>'source','format'=>'pdf','stage'=>'review','category_check_required'=>true,'source_category'=>'a4','category_check'=>['category'=>'taloes'],'error'=>'receipt_category_mismatch','name'=>'scan <test>.pdf','sha256'=>str_repeat('a',64),'toconline_state'=>'pending'];
$missing=['id'=>'child','source_id'=>'source','source_month'=>'month-folder-test','expected_period'=>'2026-04','format'=>'pdf','stage'=>'review','complete'=>false,'name'=>'invoice.pdf','sha256'=>str_repeat('b',64),'toconline_state'=>'pending','review_reason'=>'receipt_missing_pages'];
foreach(['pt','en'] as $locale){
 Translator::setLocale($locale,false);$html=$render(['source'=>$category]);
 cu(str_contains($html,'name="selected_files[]"') && str_contains($html,'value="replace_source"') && str_contains($html,'name="replacement_added"'),'Category correction requires explicit selected source and confirmation');
 cu(!str_contains($html,'name="issuer_name"') && !str_contains($html,'name="buyer_confirmed"') && !str_contains($html,'value="discard"'),'Source category never requests fiscal review');
 cu(str_contains($html,InvoiceText::get('receipt_a4')) && str_contains($html,InvoiceText::get('receipt_small')) && str_contains($html,InvoiceText::get('receipt_process_again')),'Expected/observed format and action are localized');
 cu(str_contains($html,'scan &lt;test&gt;.pdf') && str_contains($html,'data-receipt-preview'),'Filename escaped and preview retained');
 cu(!str_contains($render(['source'=>$category],false),'name="selected_files[]"'),'History-only role receives no correction');
 cu(!str_contains($render(['source'=>$category],true,true),'name="selected_files[]"'),'Busy global process hides correction');
 $html=$render(['child'=>$missing]);cu(str_contains($html,'value="repair"') && str_contains($html,'name="missing_added"') && str_contains($html,'multiple size="5"'),'Missing-page repair requires multiple explicit selected files');
 cu(str_contains($html,'temp_scan/Abril') && str_contains($html,'https://drive.google.com/drive/folders/month-folder-test'),'Correction shows the exact searched month and an actionable Drive link');
 cu(str_contains($render(['child'=>$missing],true,true),InvoiceText::get('receipt_correction_wait')),'Busy repair gives translated wait instruction');
}
echo "Receipt correction render, permissions, busy gates and PT/EN passed\n";
