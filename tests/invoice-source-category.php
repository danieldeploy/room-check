<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/src/Invoices/InvoiceReceipts.php';
function sc(bool $v,string $m):void{if(!$v)throw new RuntimeException($m);}
sc(method_exists(InvoiceReceipts::class,'archiveCategory'),'Source folder routing is required');
sc(InvoiceReceipts::archiveCategory(['format'=>'pdf','source_category'=>'taloes'])==='taloes','Receipt PDF archives with receipts');
sc(InvoiceReceipts::archiveCategory(['format'=>'pdf','source_category'=>'a4'])==='pdf','A4 PDF archive');
sc(InvoiceReceipts::archiveCategory(['format'=>'jpg','source_category'=>'a4'])==='taloes','JPEG behavior remains unchanged');
sc(InvoiceReceipts::archiveCategory(['format'=>'pdf'])==='pdf','Historical PDFs retain routing');
sc(InvoiceDriveClient::scanCategory('TALÕES')==='taloes','Accent and case accepted');
sc(InvoiceDriveClient::scanCategory('taloes')==='taloes','ASCII alias accepted');
sc(InvoiceDriveClient::scanCategory('A4')==='a4','A4 source category');
sc(InvoiceDriveClient::scanCategory('subfolder','taloes')==='taloes','Nested folders inherit category');
sc(InvoiceDriveClient::scanCategory('A4','taloes')==='invalid','Conflicting nested categories cannot silently reroute');
sc(InvoiceDriveClient::scanCategory('Talões','invalid')==='invalid','Conflict remains invalid at deeper level');
$counts=InvoiceReceiptReport::summary([['format'=>'pdf','source_category'=>'taloes','stage'=>'review','toconline_state'=>'pending']]);
sc($counts['small']===1 && $counts['a4']===0 && $counts['pdf_files']===1,'Receipt PDF retains file format but counts as a receipt');
echo "Source category routing passed\n";
