<?php
declare(strict_types=1);

/** A bounded attachment reader. Does not follow links or interpret payment receipts. */
final class CloudbedsMailMessage
{
    public const MAX_BYTES=4194304;
    public const MAX_PDF=2097152;

    public static function wire(string $raw): string
    {
        if (strlen($raw)>self::MAX_BYTES || str_contains($raw,"\0")) throw new RuntimeException('invalid_document');
        return preg_replace('/\AFrom [^\r\n\x00]{1,1024}\r?\n/','',$raw);
    }

    public static function split(string $raw): array
    {
        $parts=preg_split('/\r?\n\r?\n/',$raw,2);
        if (count($parts)!==2 || strlen($parts[0])>32768) throw new RuntimeException('invalid_document');
        $header=preg_replace('/\r?\n[ \t]+/',' ',$parts[0]); $headers=[];
        foreach (preg_split('/\r?\n/',$header) as $line) {
            if (!preg_match('/\A([A-Za-z0-9-]+):[ \t]*(.*)\z/',$line,$m)) throw new RuntimeException('invalid_document');
            $headers[strtolower($m[1])][]=$m[2];
        }
        foreach (['from','to','subject','date','content-type','content-transfer-encoding','content-disposition'] as $one)
            if (count($headers[$one]??[])>1) throw new RuntimeException('invalid_document');
        return [$headers,$parts[1]];
    }

    /** Caller authenticates the unchanged wire message before trusting this result. */
    public static function attachments(string $raw): array
    {
        $raw=self::wire($raw); $count=0; $pdfs=[];
        self::walk($raw,0,$count,$pdfs);
        return $pdfs;
    }

    private static function walk(string $raw,int $depth,int &$count,array &$pdfs): void
    {
        if ($depth>5 || ++$count>64) throw new RuntimeException('document_limit');
        [$h,$body]=self::split($raw); $type=$h['content-type'][0]??'text/plain';
        $encoding=strtolower(trim($h['content-transfer-encoding'][0]??'7bit'));
        if (preg_match('/\Amultipart\//i',$type)) {
            if (!in_array($encoding,['7bit','8bit','binary'],true)
                || !preg_match('/;\s*boundary\s*=\s*(?:"([^"\r\n]{1,200})"|([^;\s]{1,200}))/i',$type,$m)) throw new RuntimeException('invalid_document');
            $boundary=$m[1]!==''?$m[1]:$m[2]; $active=false; $closed=false; $part='';
            foreach (preg_split('/\r?\n/',$body) as $line) {
                if (rtrim($line," \t")==='--'.$boundary || rtrim($line," \t")==='--'.$boundary.'--') {
                    if ($active) self::walk($part,$depth+1,$count,$pdfs);
                    $part=''; $active=true;
                    if (rtrim($line," \t")==='--'.$boundary.'--') { $closed=true; break; }
                } elseif ($active) $part.=$line."\r\n";
            }
            if (!$closed) throw new RuntimeException('invalid_document');
            return;
        }
        // Nested forwarded mail and all non-PDF attachments are deliberately excluded.
        if (!preg_match('/\Aapplication\/pdf(?:\s*;|\s*\z)/i',$type)) return;
        if ($encoding!=='base64') throw new RuntimeException('invalid_document');
        $pdf=base64_decode(preg_replace('/[\r\n \t]+/','',$body),true);
        if (!is_string($pdf) || strlen($pdf)<8 || strlen($pdf)>self::MAX_PDF
            || !str_starts_with($pdf,'%PDF-') || !str_contains(substr($pdf,-2048),'%%EOF')) throw new RuntimeException('invalid_document');
        $pdfs[hash('sha256',$pdf)]=$pdf;
        if (count($pdfs)>5) throw new RuntimeException('document_limit');
    }
}
