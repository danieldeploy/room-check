<?php
declare(strict_types=1);
require_once __DIR__.'/InvoiceVault.php';

/** Optional manager diagnostics. No Drive, TOConline or receipt-workflow writes. */
final class InvoiceAzure
{
    public const ENDPOINT='https://management-hub-invoices.cognitiveservices.azure.com';
    public const SETTINGS='azure-document-intelligence.enc';
    public const TESTS='azure-receipt-tests.enc';
    private const API='2024-11-30';
    public function __construct(private readonly InvoiceVault $vault, private readonly mixed $transport=null) {}
    public function configured(): bool { return $this->vault->has(self::SETTINGS); }
    public function configure(string $key): void
    {
        $key=trim($key);
        if (!preg_match('/\A[A-Za-z0-9+\/=_-]{32,256}\z/',$key)) throw new RuntimeException('azure_key_invalid');
        $this->vault->save(self::SETTINGS,['key'=>$key,'endpoint'=>self::ENDPOINT,'configured_at'=>gmdate('c')]);
    }
    public function tests(): array { return $this->vault->has(self::TESTS)?$this->vault->read(self::TESTS):[]; }
    public static function operation(string $url): string
    {
        $prefix=self::ENDPOINT.'/documentintelligence/documentModels/prebuilt-invoice/analyzeResults/';
        if (!str_starts_with($url,$prefix)) throw new RuntimeException('azure_response');
        $tail=substr($url,strlen($prefix));
        if (!preg_match('/\A([a-f0-9-]{36})\?api-version=2024-11-30\z/i',$tail,$m)) throw new RuntimeException('azure_response');
        return strtolower($m[1]);
    }
    private function request(string $method,string $path,?string $bytes=null,string $mime='application/pdf'): array
    {
        $settings=$this->vault->read(self::SETTINGS);
        $url=self::ENDPOINT.$path;
        if (is_callable($this->transport)) return ($this->transport)($method,$url,$bytes,$mime);
        $headers=['Ocp-Apim-Subscription-Key: '.$settings['key']];
        if ($bytes!==null) $headers[]='Content-Type: '.$mime;
        $responseHeaders=[]; $data=''; $h=curl_init($url);
        curl_setopt_array($h,[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$headers,
            CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>45,
            CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
            CURLOPT_HEADERFUNCTION=>static function($h,string $line) use (&$responseHeaders): int {
                if (str_contains($line,':')) { [$k,$v]=explode(':',$line,2); $responseHeaders[strtolower(trim($k))]=trim($v); } return strlen($line);
            },CURLOPT_WRITEFUNCTION=>static function($h,string $chunk) use (&$data): int {
                if (strlen($data)+strlen($chunk)>4*1024*1024) return 0; $data.=$chunk; return strlen($chunk);
            }]);
        if ($bytes!==null) curl_setopt($h,CURLOPT_POSTFIELDS,$bytes);
        $ok=curl_exec($h); $status=(int)curl_getinfo($h,CURLINFO_HTTP_CODE); curl_close($h);
        if ($ok===false) throw new RuntimeException('azure_uncertain');
        if ($status===401 || $status===403) throw new RuntimeException('azure_auth');
        if ($status===429) throw new RuntimeException('azure_quota');
        if ($status<200 || $status>=300) throw new RuntimeException('azure_response');
        return ['status'=>$status,'headers'=>$responseHeaders,'body'=>$data===''?[]:json_decode($data,true,128,JSON_THROW_ON_ERROR)];
    }
    public function start(string $bytes,string $mime,int $pages,string $label,int $actor): string
    {
        if (!$this->configured()) throw new RuntimeException('azure_not_configured');
        if (strlen($bytes)>4*1024*1024 || $pages<1 || $pages>2
            || !in_array($mime,['application/pdf','image/jpeg'],true)
            || ($mime==='application/pdf' && !str_starts_with($bytes,'%PDF-'))
            || ($mime==='image/jpeg' && (@getimagesizefromstring($bytes)[2]??null)!==IMAGETYPE_JPEG)) throw new RuntimeException('azure_limits');
        $tests=$this->tests(); $hash=hash('sha256',$bytes);
        foreach ($tests as $id=>$test) if (($test['sha256']??'')===$hash) return $id;
        if (count($tests)>=20) throw new RuntimeException('azure_limits');
        $id=bin2hex(random_bytes(16));
        $tests[$id]=['sha256'=>$hash,'label'=>mb_substr(trim($label),0,160),'pages_expected'=>$pages,
            'state'=>'submitting','actor'=>$actor,'created_at'=>gmdate('c')];
        $this->vault->save(self::TESTS,$tests); // Intent persists before paid/external work; uncertain POST is never replayed.
        try {
            $r=$this->request('POST','/documentintelligence/documentModels/prebuilt-invoice:analyze?api-version='.self::API,$bytes,$mime);
            if (($r['status']??0)!==202) throw new RuntimeException('azure_response');
            $tests[$id]['operation']=self::operation($r['headers']['operation-location']??'');
            $tests[$id]['state']='running';
        } catch (Throwable $e) {
            $tests[$id]['state']='uncertain'; $tests[$id]['error']=self::safeError($e);
        }
        $this->vault->save(self::TESTS,$tests); return $id;
    }
    public function poll(string $id): void
    {
        $tests=$this->tests(); $test=$tests[$id]??null;
        if (!$test || !in_array($test['state'],['running'],true)) return;
        if (($test['checked_at']??0)>time()-5) return;
        $tests[$id]['checked_at']=time(); $this->vault->save(self::TESTS,$tests);
        try {
            $r=$this->request('GET','/documentintelligence/documentModels/prebuilt-invoice/analyzeResults/'.$test['operation'].'?api-version='.self::API);
            $status=$r['body']['status']??'';
            if ($status==='succeeded') {
                $result=$r['body']['analyzeResult']??[];
                if (count($result['pages']??[])!==$test['pages_expected']) throw new RuntimeException('azure_incomplete');
                $tests[$id]['result']=self::project($result); $tests[$id]['state']='succeeded'; unset($tests[$id]['error']);
            } elseif (in_array($status,['failed','canceled'],true)) { $tests[$id]['state']='failed'; $tests[$id]['error']='azure_response'; }
            elseif (!in_array($status,['running','notStarted'],true)) throw new RuntimeException('azure_response');
        } catch (Throwable $e) { $tests[$id]['error']=self::safeError($e); }
        $this->vault->save(self::TESTS,$tests);
    }
    public static function safeError(Throwable $e): string
    {
        return in_array($e->getMessage(),['azure_key_invalid','azure_auth','azure_quota','azure_limits','azure_incomplete','azure_uncertain','azure_not_configured'],true)?$e->getMessage():'azure_response';
    }
    public static function project(array $r): array
    {
        $out=['documents'=>[],'pages'=>[]];
        foreach (array_slice($r['documents']??[],0,10) as $doc) {
            $d=['pages'=>array_values(array_unique(array_map(static fn($p)=>(int)($p['pageNumber']??0),$doc['boundingRegions']??[]))),'fields'=>[]];
            foreach (['InvoiceId','InvoiceDate','VendorName','VendorTaxId','CustomerName','CustomerTaxId','InvoiceTotal','SubTotal','TotalTax'] as $key) {
                $f=$doc['fields'][$key]??null; if (!is_array($f)) continue;
                $value=$f['valueDate']??$f['valueString']??$f['content']??'';
                if (isset($f['valueCurrency']['amount'])) $value=(string)$f['valueCurrency']['amount'].' '.($f['valueCurrency']['currencyCode']??'');
                $d['fields'][$key]=['value'=>mb_substr((string)$value,0,500),'confidence'=>max(0,min(1,(float)($f['confidence']??0))),
                    'pages'=>array_values(array_unique(array_map(static fn($p)=>(int)($p['pageNumber']??0),$f['boundingRegions']??[])))];
            }
            $out['documents'][]=$d;
        }
        foreach (array_slice($r['pages']??[],0,2) as $page) {
            $lines=array_slice(array_map(static fn($l)=>mb_substr((string)($l['content']??''),0,500),$page['lines']??[]),0,500);
            $text=implode("\n",$lines);
            $out['pages'][]=['number'=>(int)$page['pageNumber'],'lines'=>$lines,
                'continuation'=>(bool)preg_match('/a\s+transportar|transporte|transportado|brought\s+forward|carried\s+forward/iu',$text)];
        }
        return $out;
    }
}
