<?php
declare(strict_types=1);
require_once __DIR__.'/InvoiceReceiptPdf.php';
require_once __DIR__.'/InvoiceGemini.php';

/** Converts untrusted model suggestions and local fiscal QR into a complete, durable page manifest. */
final class InvoiceReceiptAnalysis
{
    public const VERSION=2;
    public function __construct(private readonly InvoiceReceiptPdf $pdf,private readonly InvoiceGemini $gemini) {}
    public function configured(): bool { return $this->gemini->configured(); }
    public static function qr(string $raw): ?array
    {
        if (strlen($raw)>4096 || preg_match('/[\x00-\x1f]/',$raw)) return null;
        $f=[];
        foreach (explode('*',$raw) as $part) {
            if (!preg_match('/\A([A-Z][0-9]?):(.+)\z/u',$part,$m) || isset($f[$m[1]])) return null;
            $f[$m[1]]=$m[2];
        }
        if (!preg_match('/\A\d{9}\z/',$f['A']??'') || !preg_match('/\A\d{9}\z/',$f['B']??'')
            || !preg_match('/\A\d{8}\z/',$f['F']??'') || empty($f['G']) || strlen($f['G'])>128
            || !preg_match('/\A\d{1,10}\.\d{2}\z/',$f['O']??'') || empty($f['D']) || empty($f['E'])) return null;
        $date=substr($f['F'],0,4).'-'.substr($f['F'],4,2).'-'.substr($f['F'],6,2);
        if (!InvoiceReceipts::validDate($date)) return null;
        return ['issued_on'=>$date,'invoice_number'=>$f['G'],'issuer_nif'=>$f['A'],'company_nif'=>$f['B'],
            'total'=>$f['O'],'document_type'=>$f['D'],'fiscal_status'=>$f['E'],'atcud'=>$f['H']??'',
            'qr_fields'=>$f,'data_source'=>'qr'];
    }
    private static function identity(array $q): string
    { return implode('|',[$q['issuer_nif'],$q['invoice_number'],$q['issued_on'],$q['company_nif'],$q['total']]); }
    /** Only complete, unambiguous copies qualify; QR agreement alone does not prove completeness. */
    public static function duplicateKey(array $d): ?string
    {
        if (empty($d['complete']) || !empty($d['uncertain']) || !empty($d['review_reason']) || !empty($d['children'])
            || ($d['stage']??'')==='superseded') return null;
        foreach (['issuer_nif','company_nif','invoice_number','issued_on','total'] as $k) if (empty($d[$k])) return null;
        if (!empty($d['qr_fields'])) { $q=$d['qr_fields'];ksort($q);return hash('sha256',json_encode($q,JSON_THROW_ON_ERROR)); }
        // Without a QR, only byte-identical complete scans are automatically consolidated.
        return !empty($d['sha256'])?hash('sha256',self::identity($d).'|'.$d['sha256']):null;
    }
    private static function clean(mixed $v,int $max=160): string
    { return is_string($v) && mb_strlen($v)<=$max && !preg_match('/[\x00-\x1f]/',$v)?trim($v):''; }
    public function analyse(string $bytes,string $format,string $nif,?array $manual=null): array
    {
        $local=$this->pdf->inspect($bytes,$format); $groups=[]; $seen=[]; $simple=true; $names=$this->gemini->suppliers();
        foreach ($local['pages'] as $p) {
            $q=count($p['qr'])===1?self::qr($p['qr'][0]):null;
            if ($q) $q['issuer_name']=$names[$q['issuer_nif']]??'';
            if (!$q || $q['issuer_name']==='' || !empty($p['structure_unknown']) || !empty($p['continuation']) || isset($seen[self::identity($q)])) { $simple=false; break; }
            $seen[self::identity($q)]=true;
            $groups[]=$q+['pages'=>[$p['number']],'complete'=>true,'uncertain'=>false,'reason'=>'none','duplicate_of'=>0];
        }
        if ($manual!==null) { $simple=true; $groups=array_map(static fn($pages)=>['pages'=>$pages,'complete'=>true,'uncertain'=>false],$manual); }
        $suggestion=$simple?['groups'=>$groups]:$this->gemini->analyse($bytes,$format,$local);
        $local['require_page_evidence']=!$simple;
        $manifest=self::validate($suggestion,$local,$nif);
        $this->gemini->suppliers($manifest['groups']);
        return $manifest;
    }
    public function extract(string $bytes,array $pages): array { return $this->pdf->extract($bytes,$pages); }
    public static function validate(array $result,array $local,string $nif): array
    {
        $count=$local['count']??0; $input=$result['groups']??null;
        if (!is_int($count) || $count<1 || $count>50 || !is_array($input) || !array_is_list($input) || !$input || count($input)>$count) throw new RuntimeException('receipt_grouping_required');
        $evidence=[];
        foreach ($result['page_evidence']??[] as $p) {
            $n=$p['source_page']??0;
            if (!is_int($n) || $n<1 || $n>$count || isset($evidence[$n])
                || !is_int($p['printed_page']??null) || !is_int($p['total_pages']??null)
                || min($p['printed_page'],$p['total_pages'])<0 || max($p['printed_page'],$p['total_pages'])>1000) throw new RuntimeException('receipt_grouping_required');
            $evidence[$n]=$p;
        }
        if (!empty($local['require_page_evidence']) && count($evidence)!==$count) throw new RuntimeException('receipt_grouping_required');
        $used=[];$groups=[];
        foreach ($input as $i=>$item) {
            $pages=$item['pages']??null;
            if (!is_array($pages) || !array_is_list($pages) || !$pages) throw new RuntimeException('receipt_grouping_required');
            $identities=[]; $badQr=false; $previous=0;
            foreach ($pages as $n) {
                if (!is_int($n) || $n<1 || $n>$count || $n<=$previous || isset($used[$n])) throw new RuntimeException('receipt_grouping_required');
                $previous=$n; $used[$n]=true;
                foreach ($local['pages'][$n-1]['qr']??[] as $raw) {
                    $q=self::qr($raw); if (!$q) { $badQr=true; continue; } $identities[self::identity($q)]=$q;
                }
            }
            $g=['pages'=>$pages,'issuer_name'=>self::clean($item['issuer_name']??''),'issuer_nif'=>self::clean($item['issuer_nif']??''),
                'company_nif'=>self::clean($item['company_nif']??''),'invoice_number'=>self::clean($item['invoice_number']??'',128),
                'issued_on'=>self::clean($item['issued_on']??''),'total'=>self::clean($item['total']??''),'data_source'=>'gemini',
                'legibility'=>is_int($item['legibility']??null)?max(0,min(100,$item['legibility'])):0,
                'complete'=>($item['complete']??false)===true,'uncertain'=>($item['uncertain']??true)!==false,'review_reason'=>''];
            if (count($identities)===1) $g=array_merge($g,array_values($identities)[0]);
            if ($badQr || count($identities)>1) $g['review_reason']='receipt_grouping_required';
            elseif ($g['company_nif']!==$nif) $g['review_reason']='receipt_company_changed';
            elseif (!$g['complete']) $g['review_reason']='receipt_missing_pages';
            elseif ($g['uncertain'] || !InvoiceReceipts::validDate($g['issued_on']) || $g['invoice_number']==='' || $g['issuer_name']===''
                || !preg_match('/\A\d{9}\z/',$g['issuer_nif']) || !preg_match('/\A\d{1,10}\.\d{2}\z/',$g['total'])
                || (isset($g['fiscal_status']) && $g['fiscal_status']!=='N')) $g['review_reason']='receipt_review_required';
            $totals=[];$printed=[];$carry=false;
            foreach ($pages as $n) if (isset($evidence[$n])) {
                $p=$evidence[$n];if ($p['total_pages']>0) $totals[$p['total_pages']]=true;
                if ($p['printed_page']>0) $printed[]=$p['printed_page'];
                if (!empty($p['carry_forward']) && $n===end($pages)) $carry=true;
            }
            if ($totals) {
                $total=(int)array_key_first($totals);sort($printed);
                if (count($totals)!==1 || count($pages)!==$total || $printed!==range(1,$total)) { $g['complete']=false;if(!in_array($g['review_reason'],['receipt_grouping_required','receipt_company_changed'],true))$g['review_reason']='receipt_missing_pages'; }
            } elseif ($carry || ($printed && max($printed)>count($pages))) { $g['complete']=false;if(!in_array($g['review_reason'],['receipt_grouping_required','receipt_company_changed'],true))$g['review_reason']='receipt_missing_pages'; }
            $g['page_evidence']=array_values(array_intersect_key($evidence,array_flip($pages)));
            $duplicate=$item['duplicate_of']??0;
            if (is_int($duplicate) && $duplicate>0 && $duplicate<=$i && self::identity($g)===self::identity($groups[$duplicate-1])) $g['duplicate_of']=$duplicate;
            $groups[]=$g;
        }
        if (count($used)!==$count) throw new RuntimeException('receipt_grouping_required');
        return ['version'=>self::VERSION,'page_count'=>$count,'groups'=>$groups];
    }
}
