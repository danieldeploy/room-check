<?php
declare(strict_types=1);
require_once __DIR__.'/InvoicePdfText.php';
require_once __DIR__.'/InvoiceService.php';
require_once __DIR__.'/InvoiceToconline.php';

final class InvoiceHostelworldVerification
{
    public static function bound(array $account, string $property): bool
    {
        return ($account['portal']??'')==='hostelworld' && ($account['auth_method']??'')==='email'
            && ($account['period_basis']??'')==='issue_month'
            && ([2=>'305209',3=>'77759'][(int)($account['id']??0)]??null)===$property;
    }
    public static function reportName(int $account, string $property): string
    {
        if (([2=>'305209',3=>'77759'][$account]??null)!==$property) throw new RuntimeException('connector_unconfigured');
        return 'account-'.$account.'-property-'.$property.'-verification.enc';
    }
    public static function companyNif(InvoiceVault $vault): string
    {
        $settings=$vault->has('toconline-settings.enc')?$vault->read('toconline-settings.enc'):[];
        $nif=(string)($settings['nif']??''); InvoiceToconline::destination($nif); return $nif;
    }
    public static function configuration(InvoiceVault $vault, int $id): string
    {
        $file=$vault->path(InvoiceAccounts::secretName($id,'credentials'));
        if (!is_file($file)) throw new RuntimeException('auth_unconfigured');
        return hash('sha256',(string)hash_file('sha256',$file)."\0".self::companyNif($vault));
    }
    public static function issueDates(string $text): array
    {
        preg_match_all('/\b(?:(due\s+date|payment\s+date|(?:original|previous|related)\s+invoice\s+date)|invoice\s+date|date\s+of\s+issue|issue\s+date|date)\s*:\s*/iu',$text,$matches,PREG_SET_ORDER|PREG_OFFSET_CAPTURE);
        $months=['january','february','march','april','may','june','july','august','september','october','november','december'];
        $monthNumber=static function(string $value) use($months): int {
            $v=strtolower($value); if($v==='sept')return 9;
            foreach($months as $i=>$month)if($v===$month || $v===substr($month,0,3))return $i+1;
            return 0;
        };
        $dates=[];
        foreach ($matches as $match) {
            if (($match[1][0]??'')!=='') continue;
            $value=substr($text,$match[0][1]+strlen($match[0][0]),80);
            if(preg_match('/\A(\d{1,2})(?:st|nd|rd|th)?[\s.\/-]*([A-Za-z]{3,9})[\s,.\/-]*(20\d{2})\b/i',$value,$m)) {
                $day=(int)$m[1];$month=$monthNumber($m[2]);$year=(int)$m[3];
            } elseif(preg_match('/\A([A-Za-z]{3,9})\s*(\d{1,2})(?:st|nd|rd|th)?[\s,.\/-]+(20\d{2})\b/i',$value,$m)) {
                $month=$monthNumber($m[1]);$day=(int)$m[2];$year=(int)$m[3];
            } elseif(preg_match('/\A(20\d{2})[-\/](\d{1,2})[-\/](\d{1,2})\b/',$value,$m)) {
                $year=(int)$m[1];$month=(int)$m[2];$day=(int)$m[3];
            } elseif(preg_match('/\A(\d{1,2})[-\/](\d{1,2})[-\/](20\d{2})\b/',$value,$m)) {
                $a=(int)$m[1];$b=(int)$m[2];$year=(int)$m[3];
                if(($a>12 && $b<=12) || $a===$b){$day=$a;$month=$b;}
                elseif($b>12 && $a<=12){$day=$b;$month=$a;}
                else return [];
            } else return [];
            if(!checkdate($month,$day,$year))return [];
            $dates[]=sprintf('%04d-%02d-%02d',$year,$month,$day);
        }
        return array_values(array_unique($dates));
    }
    public static function propertyMatches(string $text,string $property): bool
    {
        preg_match_all('/\bHostel\s+Number\s*:\s*H?(\d{1,12})(?!\d)/iu',$text,$matches);
        $numbers=array_values(array_unique(array_map(static fn(string $n): string=>ltrim($n,'0')?:'0',$matches[1])));
        return count($numbers)===1 && $numbers[0]===$property;
    }
    public static function checkText(string $text,string $property,array $metadata,string $period,string $nif): array
    {
        $dates=self::issueDates($text); $number=(string)($metadata['number']??'');
        return [
            'text_extracted'=>$text!=='',
            'issuer_verified'=>preg_match('/hostelworld\.com/iu',$text)===1,
            'company_verified'=>preg_match('/\A\d{9}\z/',$nif)===1
                && preg_match('/VAT\s+Number\s*:\s*PT\s*'.preg_quote($nif,'/').'(?!\d)/iu',$text)===1,
            'property_verified'=>self::propertyMatches($text,$property),
            // The portal's stable row/PDF identifier is not printed in these PDFs.
            'source_id_consistent'=>preg_match('/\A\d{1,12}\z/',$number)===1 && ($metadata['portal_document_id']??null)===$number,
            'issue_date_unambiguous'=>count($dates)===1,
            'issue_date_verified'=>count($dates)===1 && $dates[0]===($metadata['issued_on']??null) && substr($dates[0],0,7)===$period,
        ];
    }
    public static function checkPdf(string $file,string $property,array $metadata,string $period,string $nif): array
    {
        $size=filesize($file); $handle=fopen($file,'rb'); if (!$handle) throw new RuntimeException('invalid_document');
        try { $prefix=fread($handle,5); fseek($handle,max(0,$size-2048)); $tail=stream_get_contents($handle); }
        finally { fclose($handle); }
        $complete=$size>=8 && $size<=2*1024*1024 && $prefix==='%PDF-' && str_contains($tail,'%%EOF');
        return ['complete_pdf'=>$complete]+self::checkText($complete?InvoicePdfText::read($file):'',$property,$metadata,$period,$nif);
    }
    public static function structure(mixed $value): array
    {
        if (!is_array($value) || !preg_match('/\A[a-f0-9]{64}\z/',(string)($value['headers_sha256']??''))
            || ($value['date_source']??null)!=='pdf-date-v1' || ($value['number_source']??null)!=='invoice-row-id') throw new RuntimeException('invalid_document');
        return array_intersect_key($value,array_flip(['headers_sha256','date_source','number_source']));
    }
    public static function finish(PDO $pdo,InvoiceVault $vault,array $job,mixed $evidence,array $files): bool
    {
        $account=(new InvoiceAccounts($pdo))->get((int)$job['account_id']);
        if ($job['kind']!=='verify' || !self::bound($account,$job['property_id']) || !is_array($evidence)
            || ($evidence['version']??null)!==1 || ($evidence['validated']??null)!==false
            || ($evidence['strategy']??null)!=='hostelworld-vat-v1' || ($evidence['session_verified']??null)!==true
            || ($evidence['property_verified']??null)!==true || ($evidence['pagination_verified']??null)!==true
            || !in_array($evidence['pagination_mode']??null,['single_page','next_page'],true)
            || !is_int($evidence['pages']??null) || $evidence['pages']<1 || $evidence['pages']>20
            || !is_int($evidence['scanned_documents']??null) || $evidence['scanned_documents']<1 || $evidence['scanned_documents']>400
            || !$files || count($files)>100) throw new RuntimeException('invalid_document');
        $structure=self::structure($evidence['structure']??null); $checks=[]; $passed=true; $nif=self::companyNif($vault);
        foreach ($files as $file) {
            $m=$file['metadata'];
            if (($m['format']??null)!=='pdf' || ($m['period_basis']??null)!=='issue_month' || ($m['period']??null)!==$job['period']) throw new RuntimeException('invalid_document');
            $check=self::checkPdf($file['path'],$job['property_id'],$m,$job['period'],$nif);
            $checks[]=$check; if (in_array(false,$check,true)) $passed=false;
        }
        $checked=InvoiceService::utcNow();
        if ($passed) $pdo->prepare("UPDATE invoice_accounts SET login_verified_at=?,status='configured' WHERE id=?")->execute([$checked,$account['id']]);
        $vault->save(self::reportName((int)$account['id'],$job['property_id']),[
            'version'=>1,'validated'=>false,'passed'=>$passed,'account_id'=>(int)$account['id'],'property_id'=>$job['property_id'],
            'period'=>$job['period'],'task_id'=>(int)$job['id'],'checked_at'=>$checked,
            'configuration_sha256'=>self::configuration($vault,(int)$account['id']),
            'session_verified'=>true,'pagination_verified'=>true,'pagination_mode'=>$evidence['pagination_mode'],
            'pages'=>$evidence['pages'],'scanned_documents'=>$evidence['scanned_documents'],'structure'=>$structure,'pdf_checks'=>$checks,
        ]);
        return $passed;
    }
    public static function approve(PDO $pdo,InvoiceVault $vault,int $id,string $period): void
    {
        $accounts=new InvoiceAccounts($pdo); $account=$accounts->get($id); $properties=$accounts->collectionProperties($id);
        if (!$accounts->active($id) || count($properties)!==1 || !self::bound($account,(string)array_key_first($properties))) throw new RuntimeException('connector_unconfigured');
        $property=(string)array_key_first($properties); $r=$vault->read(self::reportName($id,$property));
        $s=$pdo->prepare("SELECT * FROM invoice_tasks WHERE account_id=? AND property_id=? AND period=? AND kind='verify' ORDER BY id DESC LIMIT 1"); $s->execute([$id,$property,$period]); $task=$s->fetch(PDO::FETCH_ASSOC);
        if (($r['passed']??null)!==true || ($r['validated']??null)!==false || ($r['period']??null)!==$period
            || ($r['account_id']??null)!==$id || ($r['property_id']??null)!==$property
            || ($r['checked_at']??null)!==$account['login_verified_at'] || strtotime(($r['checked_at']??'').' UTC')<time()-86400
            || ($r['configuration_sha256']??null)!==self::configuration($vault,$id)
            || !$task || (int)$task['id']!==($r['task_id']??null) || $task['state']!=='completed') throw new RuntimeException('verification_required');
        $map=['version'=>4,'validated'=>true,'strategy'=>'hostelworld-vat-v1','portal'=>'hostelworld','accountId'=>$id,
            'configuration_sha256'=>$r['configuration_sha256'],'properties'=>[$property=>self::structure($r['structure']??null)+['verified'=>true,'verification_task'=>(int)$task['id']]]];
        InvoiceVault::atomicWrite($vault->path('account-'.$id.'-map.json'),json_encode($map,JSON_THROW_ON_ERROR));
        $pdo->prepare("UPDATE invoice_accounts SET status='ready' WHERE id=?")->execute([$id]);
    }
}
