<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/src/Invoices/InvoiceReceipts.php';
function sel(bool $v,string $m):void{if(!$v)throw new RuntimeException($m);}
sel(class_exists('InvoiceReceiptSelection'),'Explicit scoped selection is required');
$f=['id'=>'scan-one','name'=>'exact.pdf','mimeType'=>'application/pdf','size'=>20,'md5Checksum'=>str_repeat('a',32),'parents'=>['folder-one'],'source_category'=>'a4'];
$g=array_replace($f,['id'=>'scan-two']);
$token=InvoiceReceiptSelection::token($f);
sel(InvoiceReceiptSelection::choose([$f,$g],[$token],[])===[$f],'Select only the requested file');
foreach ([['files'=>[array_replace($f,['md5Checksum'=>str_repeat('b',32)])],'docs'=>[]],['files'=>[array_replace($f,['parents'=>['moved']])],'docs'=>[]],['files'=>[$f],'docs'=>['scan-one'=>['stage'=>'archived']]],['files'=>[],'docs'=>[]]] as $case) {
 try {InvoiceReceiptSelection::choose($case['files'],[$token],$case['docs']);throw new LogicException('Stale/outside/claimed selection accepted');}catch(RuntimeException $e){sel($e->getMessage()==='receipt_selection_changed','Safe rejection');}
}
sel(InvoiceReceiptSelection::choose([$f],[$token],['scan-one'=>[]],'scan-one')===[$f],'Moved original is selectable explicitly');
try {InvoiceReceiptSelection::choose([$f],[],[]);throw new LogicException('Empty selection');}catch(RuntimeException $e){sel($e->getMessage()==='receipt_select_files','Explicit selection mandatory');}
echo "Scoped explicit selection passed\n";
