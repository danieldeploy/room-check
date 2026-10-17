<?php
declare(strict_types=1);
require_once __DIR__.'/../src/Invoices/InvoiceHostelworldVerification.php';
function hostelworldFixture(string $property='305209',string $date='3 August 2026',string $nif='500000000'): string
{
    $lines=['Hostelworld.com','Hostel Number: H'.$property,'VAT Number: PT'.$nif,'Date: '.$date,'Period: July 2026'];
    $stream="BT /F1 12 Tf 40 780 Td 16 TL\n";
    foreach ($lines as $line) $stream.='('.str_replace(['\\','(',')'],['\\\\','\\(','\\)'],$line).") Tj T*\n";
    $stream.="ET\n";
    $objects=['<< /Type /Catalog /Pages 2 0 R >>','<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
        '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>','<< /Length '.strlen($stream).">>\nstream\n".$stream.'endstream'];
    $pdf="%PDF-1.4\n";$offsets=[];
    foreach($objects as $i=>$object){$offsets[]=strlen($pdf);$pdf.=($i+1)." 0 obj\n".$object."\nendobj\n";}
    $xref=strlen($pdf);$pdf.="xref\n0 6\n0000000000 65535 f \n";
    foreach($offsets as $offset)$pdf.=sprintf('%010d 00000 n ',$offset)."\n";
    return $pdf."trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF\n";
}
if (realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__) {
    $check=static function(bool $ok,string $message): void {if(!$ok)throw new RuntimeException($message);};
    $reject=static function(callable $fn): void {try{$fn();}catch(RuntimeException){return;}throw new RuntimeException('Expected rejection');};
    $dir=sys_get_temp_dir().'/hw-verify-'.bin2hex(random_bytes(8));mkdir($dir,0700);
    try {
        file_put_contents($dir.'/master.key',random_bytes(32));chmod($dir.'/master.key',0600);$vault=new InvoiceVault($dir);
        $vault->save('toconline-settings.enc',['nif'=>'500000000']);$vault->save(InvoiceAccounts::secretName(2,'credentials'),['synthetic'=>true]);
        $m=['number'=>'901','portal_document_id'=>'901','issued_on'=>'2026-08-03','period'=>'2026-08','period_basis'=>'issue_month','format'=>'pdf'];
        $file=$dir.'/fixture.pdf';file_put_contents($file,hostelworldFixture());
        $check(!in_array(false,InvoiceHostelworldVerification::checkPdf($file,'305209',$m,'2026-08','500000000'),true),'PDF identity and date');
        $text=InvoicePdfText::read($file,false);
        $check(!in_array(false,InvoiceHostelworldVerification::checkText($text,'305209',$m,'2026-08','500000000'),true),'PHP fallback parser');
        $check(!InvoiceHostelworldVerification::checkText($text,'77759',$m,'2026-08','500000000')['property_verified'],'Wrong property');
        $padded=str_replace('H305209','H077759',$text);
        $check(InvoiceHostelworldVerification::checkText($padded,'77759',$m,'2026-08','500000000')['property_verified'],'Leading zeroes preserve numeric property');
        foreach ([str_replace('077759','177759',$padded),str_replace('077759','0777590',$padded),$padded."\nHostel Number: H305209"] as $wrong)
            $check(!InvoiceHostelworldVerification::propertyMatches($wrong,'77759'),'Conflicting or different numeric property');
        $check(!InvoiceHostelworldVerification::checkText($text,'305209',$m,'2026-08','500000001')['company_verified'],'Wrong NIF');
        $check(!InvoiceHostelworldVerification::checkText($text,'305209',$m,'2026-07','500000000')['issue_date_verified'],'Service month is not issue month');
        $check(InvoiceHostelworldVerification::issueDates($text."\nDue date: 9 September 2026")==['2026-08-03'],'Ignore due date');
        $check(count(InvoiceHostelworldVerification::issueDates($text."\nDate: 4 August 2026"))===2,'Conflicting date');
        $check(InvoiceHostelworldVerification::issueDates('Date: 31 February 2026')===[],'Invalid date');
        foreach(['3rd September 2021','03-Sept-2021','September 3, 2021','3September2021','2021-09-03'] as $date)
            $check(InvoiceHostelworldVerification::issueDates('Date: '.$date)===['2021-09-03'],'Unambiguous legacy date');
        foreach(['23/09/2021','09/23/2021'] as $date)
            $check(InvoiceHostelworldVerification::issueDates('Date: '.$date)===['2021-09-23'],'Unambiguous numeric date');
        $check(InvoiceHostelworldVerification::issueDates('Date: 03/09/2021')===[],'Do not guess numeric date order');
        $check(InvoiceHostelworldVerification::issueDates("Date: 3 September 2021\nOriginal invoice date: 3 August 2021")===['2021-09-03'],'Original invoice date is not current issue date');
        foreach(['3 September 20 21',"September 3, 20\n21",'3 September 20 2 1'] as $date)
            $check(InvoiceHostelworldVerification::issueDates('Date: '.$date)===['2021-09-03'],'PDF year fragments');
        foreach(['3 September 20 211','31 February 20 21','3 Unknown 20 21'] as $date)
            $check(InvoiceHostelworldVerification::issueDates('Date: '.$date)===[],'Reject malformed fragmented date');
        $check(count(InvoiceHostelworldVerification::issueDates("Date: 3 September 20 21\nInvoice date: 4 September 2021"))===2,'Fragmented dates retain conflict detection');
        foreach(['January','February','March','April','May','June','July','August','September','October','November','December'] as $i=>$name) {
            $expected=sprintf('2021-%02d-13',$i+1);
            foreach([$name,substr($name,0,3)] as $word)for($split=1;$split<strlen($word);$split++) {
                $fragments=substr($word,0,$split).' '.substr($word,$split);
                foreach(['1 3 '.$fragments.' 2 0 2 1',$fragments.' 1 3, 2 0 2 1'] as $date)
                    $check(InvoiceHostelworldVerification::issueDates('Date: '.$date)===[$expected],'Fragmentation across date components');
            }
            $check(InvoiceHostelworldVerification::issueDates('Date: 13 '.implode(' ',str_split($name)).' 2021')===[$expected],'Individually rendered month letters');
        }
        foreach(['13 Aug other ust 2021','13 Unknown 2021','1 33 Aug ust 2021','31 Feb ruary 2021','13 Aug ust 2 0 2 11'] as $date)
            $check(InvoiceHostelworldVerification::issueDates('Date: '.$date)===[],'Do not accept arbitrary date fragments');
        $pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $pdo->exec("CREATE TABLE invoice_accounts(id INTEGER PRIMARY KEY,portal TEXT,auth_method TEXT,period_basis TEXT,login_verified_at TEXT,status TEXT);
            INSERT INTO invoice_accounts VALUES(2,'hostelworld','email','issue_month',NULL,'configured');
            CREATE TABLE invoice_account_properties(account_id INTEGER,property_id TEXT,label TEXT);INSERT INTO invoice_account_properties VALUES(2,'305209','Fixture');
            CREATE TABLE invoice_account_settings(account_id INTEGER,is_active INTEGER,archived_at TEXT);
            CREATE TABLE invoice_property_settings(account_id INTEGER,property_id TEXT,is_active INTEGER);
            CREATE TABLE invoice_tasks(id INTEGER,account_id INTEGER,property_id TEXT,period TEXT,kind TEXT,state TEXT);
            INSERT INTO invoice_tasks VALUES(1,2,'305209','2026-08','verify','running');");
        $job=['id'=>1,'account_id'=>2,'property_id'=>'305209','period'=>'2026-08','kind'=>'verify'];
        $e=['version'=>1,'validated'=>false,'strategy'=>'hostelworld-vat-v1','session_verified'=>true,'property_verified'=>true,'pagination_verified'=>true,'pagination_mode'=>'next_page','pages'=>2,'scanned_documents'=>2,
            'structure'=>['headers_sha256'=>str_repeat('a',64),'date_source'=>'pdf-date-v1','number_source'=>'invoice-row-id']];
        $files=[['metadata'=>$m,'path'=>$file]];
        $reject(fn()=>InvoiceHostelworldVerification::approve($pdo,$vault,2,'2026-08'));
        $check(InvoiceHostelworldVerification::finish($pdo,$vault,$job,$e,$files),'Verification passed');
        $reject(fn()=>InvoiceHostelworldVerification::approve($pdo,$vault,2,'2026-08'));
        $pdo->exec("UPDATE invoice_tasks SET state='completed'");
        InvoiceHostelworldVerification::approve($pdo,$vault,2,'2026-08');
        $map=json_decode(file_get_contents($vault->path('account-2-map.json')),true);
        $check($map['version']===4 && $map['validated']===true,'Only completed verification approves map');
        $reject(fn()=>InvoiceHostelworldVerification::approve($pdo,$vault,2,'2026-07'));
        $r=$vault->read(InvoiceHostelworldVerification::reportName(2,'305209'));$r['checked_at']='2020-01-01 00:00:00';$vault->save(InvoiceHostelworldVerification::reportName(2,'305209'),$r);
        $reject(fn()=>InvoiceHostelworldVerification::approve($pdo,$vault,2,'2026-08'));
        InvoiceHostelworldVerification::finish($pdo,$vault,$job,$e,$files);
        $vault->save(InvoiceAccounts::secretName(2,'credentials'),['synthetic'=>'changed']);
        $reject(fn()=>InvoiceHostelworldVerification::approve($pdo,$vault,2,'2026-08'));
        $check(!is_file($vault->path(hash_file('sha256',$file).'.pdf')),'Verification never imports document');
        echo "Hostelworld PDF verification checks passed\n";
    } finally {foreach(glob($dir.'/*') as $f)unlink($f);rmdir($dir);}
}
