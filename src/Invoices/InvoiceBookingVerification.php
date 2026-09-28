<?php
declare(strict_types=1);
require_once __DIR__.'/InvoiceCompany.php';
require_once __DIR__.'/InvoiceService.php';

/** Explicit full-document verification, separate from structural diagnostics. */
final class InvoiceBookingVerification
{
    public static function reportName(int $account, string $property): string
    {
        if ($account!==1 || !preg_match('/\A\d{1,12}\z/',$property)) throw new RuntimeException('connector_unconfigured');
        return 'account-'.$account.'-property-'.$property.'-verification.enc';
    }

    public static function pdfText(string $file): string
    {
        if (!function_exists('proc_open')) return '';
        $process=@proc_open(['pdftotext','-layout','-enc','UTF-8',$file,'-'],
            [0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['file','/dev/null','a']],$pipes);
        if (!is_resource($process)) return '';
        stream_set_blocking($pipes[1],false); $text=''; $deadline=microtime(true)+10; $status=null;
        do {
            $text.=stream_get_contents($pipes[1]); $status=proc_get_status($process);
            if (!$status['running']) break;
            usleep(10000);
        } while (microtime(true)<$deadline && strlen($text)<5*1024*1024);
        if ($status['running']) { proc_terminate($process,9); $text=''; }
        else $text.=stream_get_contents($pipes[1]);
        fclose($pipes[1]); proc_close($process);
        return !$status['running'] && $status['exitcode']===0 && strlen($text)<5*1024*1024 ? $text : '';
    }

    public static function issueDates(string $text): array
    {
        $date='(?:\d{4}-\d{2}-\d{2}|\d{1,2}[./-]\d{1,2}[./-]\d{4}|\d{1,2}\s+[A-Za-z]{3,9},?\s+\d{4})';
        preg_match_all('~^[\t ]*(?:invoice date|date of issue|issue date|date|data de emissão|data da fatura|data)[\t ]*:?[\t ]*(?:\r?\n[\t ]*)?('.$date.')\b~imu',$text,$matches);
        $dates=[];
        foreach ($matches[1] as $value) {
            $value=preg_replace('/\s+/',' ',str_replace(',','',trim($value)));
            foreach (['Y-m-d','d/m/Y','d.m.Y','d-m-Y','j M Y','j F Y'] as $format) {
                $parsed=DateTimeImmutable::createFromFormat('!'.$format,$value); $errors=DateTimeImmutable::getLastErrors();
                if ($parsed && (!$errors || (!$errors['warning_count'] && !$errors['error_count']))) {
                    $dates[]=$parsed->format('Y-m-d'); break;
                }
            }
        }
        return array_values(array_unique($dates));
    }

    public static function checkText(string $text, string $property, array $metadata, string $period): array
    {
        $number=(string)($metadata['number']??''); $issued=(string)($metadata['issued_on']??'');
        $dates=self::issueDates($text);
        return [
            'text_extracted'=>$text!=='',
            'account_verified'=>InvoiceCompany::matches($text) && preg_match('/booking\.com/iu',$text)===1,
            'property_verified'=>preg_match('/(?:property|hotel|accommodation|alojamento)[\t ]*(?:id|number|no\.?|número|numero)?[\t ]*:?[\t ]*(?:\r?\n[\t ]*)?'.preg_quote($property,'/').'(?!\d)/iu',$text)===1,
            'number_verified'=>$number!=='' && strlen($number)<=128 && preg_match('/(?:invoice|document|fatura)[\t ]*(?:number|no\.?|número|numero)?[\t ]*:?[\t ]*(?:\r?\n[\t ]*)?'.preg_quote($number,'/').'(?![A-Za-z0-9])/iu',$text)===1,
            'issue_date_verified'=>count($dates)===1 && $dates[0]===$issued && substr($issued,0,7)===$period,
            'issue_date_unambiguous'=>count($dates)===1,
        ];
    }

    public static function checkPdf(string $file, string $property, array $metadata, string $period): array
    {
        $size=filesize($file); $handle=fopen($file,'rb');
        if (!$handle) throw new RuntimeException('invalid_document');
        try {
            $prefix=fread($handle,5); fseek($handle,max(0,$size-2048)); $tail=stream_get_contents($handle);
        } finally { fclose($handle); }
        $complete=$size>=8 && $size<=20*1024*1024 && $prefix==='%PDF-' && str_contains($tail,'%%EOF');
        return ['complete_pdf'=>$complete]+self::checkText($complete?self::pdfText($file):'',$property,$metadata,$period);
    }

    public static function structure(mixed $value): array
    {
        if (!is_array($value) || !preg_match('/\A[a-f0-9]{64}\z/',(string)($value['headers_sha256']??''))
            || !is_int($value['number_column']??null) || !is_int($value['date_column']??null)
            || $value['number_column']<0 || $value['number_column']>19 || $value['date_column']<0 || $value['date_column']>19
            || $value['number_column']===$value['date_column'] || ($value['date_format']??null)!=='D MMM YYYY') throw new RuntimeException('invalid_document');
        return array_intersect_key($value,array_flip(['headers_sha256','number_column','date_column','date_format']));
    }

    public static function finish(PDO $pdo, InvoiceVault $vault, array $job, mixed $evidence, array $files): bool
    {
        $account=(new InvoiceAccounts($pdo))->get((int)$job['account_id']);
        if ($job['kind']!=='verify' || $account['portal']!=='booking' || (int)$account['id']!==1
            || empty($account['login_verified_at']) || $account['period_basis']!=='issue_month'
            || !is_array($evidence) || ($evidence['version']??null)!==1 || ($evidence['validated']??null)!==false
            || ($evidence['strategy']??null)!=='booking-finance-v1' || ($evidence['session_verified']??null)!==true
            || ($evidence['property_verified']??null)!==true || ($evidence['pagination_verified']??null)!==true
            || !in_array($evidence['pagination_mode']??null,['single_page','next_page','load_more'],true)
            || !$files || count($files)>100) throw new RuntimeException('invalid_document');
        $structure=self::structure($evidence['structure']??null); $checks=[]; $passed=true;
        foreach ($files as $file) {
            $metadata=$file['metadata'];
            if (($metadata['format']??null)!=='pdf' || ($metadata['period_basis']??null)!=='issue_month'
                || ($metadata['period']??null)!==$job['period']) throw new RuntimeException('invalid_document');
            $check=self::checkPdf($file['path'],$job['property_id'],$metadata,$job['period']);
            $checks[]=$check; if (in_array(false,$check,true)) $passed=false;
        }
        $vault->save(self::reportName((int)$account['id'],$job['property_id']),[
            'version'=>1,'validated'=>false,'passed'=>$passed,'account_id'=>(int)$account['id'],
            'property_id'=>$job['property_id'],'period'=>$job['period'],'task_id'=>(int)$job['id'],
            'checked_at'=>InvoiceService::utcNow(),'login_verified_at'=>$account['login_verified_at'],
            'session_verified'=>true,'pagination_verified'=>true,'pagination_mode'=>$evidence['pagination_mode'],
            'structure'=>$structure,'pdf_checks'=>$checks,
        ]);
        return $passed;
    }

    public static function approve(PDO $pdo, InvoiceVault $vault, int $id, string $period): void
    {
        $accounts=new InvoiceAccounts($pdo); $account=$accounts->get($id);
        if ($id!==1 || $account['portal']!=='booking' || !$accounts->active($id) || empty($account['login_verified_at'])) throw new RuntimeException('login_required');
        $properties=$accounts->collectionProperties($id); if (!$properties) throw new RuntimeException('properties_required');
        $map=['version'=>3,'validated'=>true,'strategy'=>'booking-finance-v1','portal'=>'booking','accountId'=>$id,'properties'=>[]];
        foreach ($properties as $property=>$_) {
            $name=self::reportName($id,(string)$property);
            if (!$vault->has($name)) throw new RuntimeException('verification_required');
            $r=$vault->read($name);
            $s=$pdo->prepare("SELECT id,state,kind,account_id,property_id,period FROM invoice_tasks WHERE account_id=? AND property_id=? AND period=? AND kind='verify' ORDER BY id DESC LIMIT 1"); $s->execute([$id,(string)$property,$period]); $task=$s->fetch(PDO::FETCH_ASSOC);
            if (($r['passed']??null)!==true || ($r['validated']??null)!==false || ($r['period']??null)!==$period
                || ($r['account_id']??null)!==$id || (string)($r['property_id']??'')!==(string)$property
                || ($r['login_verified_at']??null)!==$account['login_verified_at']
                || strtotime(($r['checked_at']??'').' UTC')<time()-86400
                || !$task || (int)$task['id']!==($r['task_id']??null) || $task['state']!=='completed' || $task['kind']!=='verify' || (int)$task['account_id']!==$id
                || (string)$task['property_id']!==(string)$property || $task['period']!==$period) throw new RuntimeException('verification_required');
            $map['properties'][(string)$property]=self::structure($r['structure']??null)+['verified'=>true,'verification_task'=>(int)$r['task_id']];
        }
        InvoiceVault::atomicWrite($vault->path('account-'.$id.'-map.json'),json_encode($map,JSON_THROW_ON_ERROR));
    }
}
