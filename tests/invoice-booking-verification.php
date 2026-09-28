<?php
declare(strict_types=1);
require_once __DIR__.'/../src/Invoices/InvoiceBookingVerification.php';

function bookingVerificationFixture(string $property, string $date='03/08/2026'): string
{
    $lines=['Booking.com B.V.','Invoice','Active Lines Unipessoal Lda','Property ID: '.$property,'Invoice number: FIXTURE-900',
        'Invoice date: '.$date,'Period: 01/07/2026 - 31/07/2026','Due date: 15/09/2026'];
    $stream="BT /F1 12 Tf 40 780 Td 16 TL\n";
    foreach ($lines as $line) $stream.='('.str_replace(['\\','(',')'],['\\\\','\\(','\\)'],$line).") Tj T*\n";
    $stream.="ET\n";
    $objects=['<< /Type /Catalog /Pages 2 0 R >>','<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
        '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>','<< /Length '.strlen($stream).">>\nstream\n".$stream.'endstream'];
    $pdf="%PDF-1.4\n"; $offsets=[0];
    foreach ($objects as $i=>$object) { $offsets[]=strlen($pdf); $pdf.=($i+1)." 0 obj\n".$object."\nendobj\n"; }
    $xref=strlen($pdf); $pdf.="xref\n0 6\n0000000000 65535 f \n";
    foreach (array_slice($offsets,1) as $offset) $pdf.=sprintf('%010d 00000 n ',$offset)."\n";
    return $pdf."trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF\n";
}

function runBookingVerificationChecks(callable $check): void
{
    $meta=['number'=>'FIXTURE-900','issued_on'=>'2026-08-03','period'=>'2026-08','period_basis'=>'issue_month','format'=>'pdf'];
    $text="Booking.com\nInvoice\nActive Lines\nProperty: 1140306\nInvoice number: FIXTURE-900\nDate: 03/08/2026\nPeriod: 01/07/2026 - 31/07/2026\nDue date: 15/09/2026\n";
    $check(!in_array(false,InvoiceBookingVerification::checkText($text,'1140306',$meta,'2026-08'),true),'PDF issue date stays distinct from service and due dates');
    $check(!InvoiceBookingVerification::checkText($text,'539828',$meta,'2026-08')['property_verified'],'Another property cannot validate');
    $check(!InvoiceBookingVerification::checkText(str_replace('Active Lines','Different Company',$text),'1140306',$meta,'2026-08')['account_verified'],'The recipient company must match');
    $check(!InvoiceBookingVerification::checkText($text.'Issue date: 04/08/2026','1140306',$meta,'2026-08')['issue_date_verified'],'Conflicting issue dates stop approval');
    $check(!InvoiceBookingVerification::checkText(str_replace('03/08/2026','31/02/2026',$text),'1140306',$meta,'2026-08')['issue_date_verified'],'Invalid calendar dates stop approval');
    $file=tempnam(sys_get_temp_dir(),'booking-pdf-test-');
    try {
        file_put_contents($file,bookingVerificationFixture('1140306'));
        $check(!in_array(false,InvoiceBookingVerification::checkPdf($file,'1140306',$meta,'2026-08'),true),'A complete generated PDF is parsed and checked');
        $fallback=InvoicePdfText::read($file,false);
        $check(!in_array(false,InvoiceBookingVerification::checkText($fallback,'1140306',$meta,'2026-08'),true),'The PHP parser verifies the same complete PDF without a system executable');
        file_put_contents($file,substr(bookingVerificationFixture('1140306'),0,-10));
        $check(!InvoiceBookingVerification::checkPdf($file,'1140306',$meta,'2026-08')['complete_pdf'],'A truncated signature-only PDF cannot pass');
    } finally { unlink($file); }
}

if (realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__) {
    $checks=0;
    runBookingVerificationChecks(static function(bool $ok,string $message) use (&$checks): void { ++$checks; if (!$ok) throw new RuntimeException($message); });
    echo "Booking PDF verification checks: $checks\n";
}
