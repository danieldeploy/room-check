<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/src/Invoices/InvoiceReceipts.php';
function qa(bool $ok,string $why): void { if (!$ok) throw new RuntimeException($why); }
qa(class_exists('InvoiceReceiptAnalysis'),'receipt analysis must be wired into the queue');
$qr='A:123456789*B:500000000*C:PT*D:FT*E:N*F:20260408*G:FT TEST/1*H:TEST-1*I1:PT*I2:100.00*N:23.00*O:123.00*Q:abcd*R:1234';
$q=InvoiceReceiptAnalysis::qr($qr);
qa($q['issued_on']==='2026-04-08' && $q['issuer_nif']==='123456789' && $q['invoice_number']==='FT TEST/1','AT QR fiscal fields');
qa(InvoiceReceiptAnalysis::qr(str_replace('20260408','20260231',$qr))===null,'invalid QR date cannot be trusted');
qa(InvoiceReceiptAnalysis::qr($qr.'*F:20260101')===null,'duplicate QR field is ambiguous');
$local=['count'=>3,'pages'=>[['number'=>1,'qr'=>[]],['number'=>2,'qr'=>[$qr]],['number'=>3,'qr'=>[$qr]]]];
$group=['pages'=>[1,2],'issuer_name'=>'Synthetic Supplier','issuer_nif'=>'999999999','company_nif'=>'500000000','invoice_number'=>'WRONG','issued_on'=>'2026-02-16','total'=>'999.00','complete'=>true,'uncertain'=>false,'reason'=>'none','duplicate_of'=>0];
$copy=$group;$copy['pages']=[3];$copy['duplicate_of']=1;
$r=InvoiceReceiptAnalysis::validate(['groups'=>[$group,$copy]],$local,'500000000');
qa(count($r['groups'])===2 && $r['groups'][0]['pages']===[1,2],'continuations kept together; duplicate copy separate');
qa($r['groups'][0]['issued_on']==='2026-04-08' && $r['groups'][0]['invoice_number']==='FT TEST/1' && $r['groups'][0]['total']==='123.00','readable QR always overrides printed/model fields');
foreach ([['groups'=>[$group]],['groups'=>[$group,$group]],['groups'=>[['pages'=>[0]]]]] as $bad) {
    try { InvoiceReceiptAnalysis::validate($bad,$local,'500000000');throw new LogicException('lost/overlapping page accepted'); } catch (RuntimeException $e) { qa($e->getMessage()==='receipt_grouping_required','safe manifest failure'); }
}
$bad=$local;$bad['pages'][0]['qr']=[str_replace('TEST/1','TEST/2',$qr)];
$r=InvoiceReceiptAnalysis::validate(['groups'=>[$group,$copy]],$bad,'500000000');
qa($r['groups'][0]['review_reason']==='receipt_grouping_required','different QR identities cannot be merged by model');
$group['complete']=false;$r=InvoiceReceiptAnalysis::validate(['groups'=>[$group,$copy]],$local,'500000000');
qa($r['groups'][0]['review_reason']==='receipt_missing_pages','missing page never submitted');
$r=InvoiceReceiptAnalysis::validate(['groups'=>[$group,$copy]],$local,'599999999');qa($r['groups'][1]['review_reason']==='receipt_company_changed','QR recipient must match configured company');
$group['complete']=true;
$r=InvoiceReceiptAnalysis::validate(['page_evidence'=>[['source_page'=>3,'printed_page'=>2,'total_pages'=>2,'carry_forward'=>false]],'groups'=>[$group,$copy]],$local,'500000000');
qa($r['groups'][1]['review_reason']==='receipt_missing_pages','printed pagination overrides model assertion of completeness');
echo "Fiscal QR precedence and complete manifest safeguards passed\n";
