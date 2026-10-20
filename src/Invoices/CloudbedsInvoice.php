<?php
declare(strict_types=1);
require_once __DIR__.'/InvoicePdfText.php';

/** Labels and date format confirmed against real attachments in both mailboxes. */
final class CloudbedsInvoice
{
    public static function parseText(string $text,string $nif): array
    {
        if (!preg_match('/\A\d{9}\z/',$nif) || !preg_match('/\bActive\s+Lines\b/iu',$text)
            || !preg_match('/\bCloudbeds\b/iu',$text)) throw new RuntimeException('account_mismatch');
        if (!preg_match('/\b(?:VAT|Tax)\s*(?:Number|ID|No\.?)\s*:?\s*(?:PT\s*)?'.preg_quote($nif,'/').'(?!\d)/iu',$text)) throw new RuntimeException('account_mismatch');
        // A receipt/payment confirmation is never accepted because it mentions an invoice.
        if (preg_match('/(?:\A|\n)\s*(?:payment\s+(?:receipt|confirmation)|receipt|credit\s+(?:note|memo))\s*(?:\n|\z)/iu',$text)) throw new RuntimeException('invalid_document');
        preg_match_all('/\bInvoice\s*(?:Number|No\.?|#)\s*:?\s*(A\d{6,12})(?!\w)/iu',$text,$numbers);
        $numbers=array_values(array_unique(array_map('strtoupper',$numbers[1])));
        if (count($numbers)!==1) throw new RuntimeException('invalid_document');
        preg_match_all('/\bInvoice\s+Date\s*:?\s*([A-Za-z]{3,9})\s+(\d{1,2}),?\s+(20\d{2})(?!\d)/iu',$text,$dates,PREG_SET_ORDER);
        $found=[]; $months=['january','february','march','april','may','june','july','august','september','october','november','december'];
        foreach ($dates as $date) {
            $month=0; $word=strtolower($date[1]);
            foreach ($months as $i=>$name) if ($word===$name || $word===substr($name,0,3) || ($i===8 && $word==='sept')) $month=$i+1;
            if (!checkdate($month,(int)$date[2],(int)$date[3])) throw new RuntimeException('invalid_document');
            $found[]=sprintf('%04d-%02d-%02d',(int)$date[3],$month,(int)$date[2]);
        }
        $found=array_values(array_unique($found));
        if (count($found)!==1 || preg_match_all('/\bInvoice\s+Date\b/iu',$text)!==count($dates)) throw new RuntimeException('invalid_document');
        return ['number'=>$numbers[0],'issued_on'=>$found[0],'period'=>substr($found[0],0,7),'period_basis'=>'issue_month','format'=>'pdf'];
    }
    public static function read(string $path,string $nif): array
    {
        if (!is_file($path) || is_link($path) || filesize($path)>2097152 || filesize($path)<8) throw new RuntimeException('invalid_document');
        $bytes=file_get_contents($path);
        if (!str_starts_with($bytes,'%PDF-') || !str_contains(substr($bytes,-2048),'%%EOF')) throw new RuntimeException('invalid_document');
        return self::parseText(InvoicePdfText::read($path),$nif);
    }
    public static function matches(string $path,array $document,string $nif): bool
    {
        try {
            $m=self::read($path,$nif);
            return $m['number']===$document['invoice_number'] && $m['issued_on']===$document['issued_on'] && $m['period']===$document['period'];
        } catch (Throwable) { return false; }
    }
}
