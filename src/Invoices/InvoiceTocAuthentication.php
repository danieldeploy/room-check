<?php
declare(strict_types=1);

/** Narrow RFC 6376 verifier for TOConline receipts. Unsupported input stays manual.
 * No trust in Authentication-Results or From alone; no raw messages are retained.
 */
final class InvoiceTocAuthentication
{
    private static function tags(string $value): array
    {
        $tags=[];
        foreach (explode(';',preg_replace('/\r\n[ \t]+/',' ',$value)) as $part) {
            if (trim($part)==='') continue;
            if (!preg_match('/\A\s*([a-z][a-z0-9_]*)\s*=\s*([\s\S]*?)\s*\z/',$part,$m) || isset($tags[$m[1]])) throw new RuntimeException('invalid');
            $tags[$m[1]]=$m[2];
        }
        return $tags;
    }

    private static function header(string $line,string $mode): string
    {
        if ($mode==='simple') return $line;
        [$name,$value]=explode(':',$line,2);
        $value=preg_replace('/\r\n[ \t]+/',' ',$value);
        return strtolower($name).':'.trim(preg_replace('/[ \t]+/',' ',$value)," \t");
    }

    /** Resolver injection is for synthetic offline tests only; production uses DNS TXT. */
    public static function verify(string $raw, ?callable $resolver=null, ?int $now=null): array
    {
        $failure=['verified'=>false,'code'=>'unverified'];
        try {
            if (strlen($raw)>262144 || str_contains($raw,"\0")) return $failure;
            // Exim may transport LF line endings; DKIM's wire representation uses CRLF.
            $raw=preg_replace('/(?<!\r)\n/',"\r\n",$raw);
            if (preg_match('/\r(?!\n)/',$raw)) return $failure;
            $split=explode("\r\n\r\n",$raw,2);
            if (count($split)!==2 || strlen($split[0])>32768) return $failure;
            [$head,$body]=$split; $lines=preg_split('/\r\n(?![ \t])/',$head); $headers=[];
            foreach ($lines as $line) {
                if (!preg_match('/\A([A-Za-z0-9-]+):/',$line,$m)) return $failure;
                $headers[strtolower($m[1])][]=$line;
            }
            if (empty($headers['dkim-signature'])) return ['verified'=>false,'code'=>'unsigned'];
            // One signature only initially. Do not guess between competing identities.
            if (count($headers['dkim-signature'])!==1) return ['verified'=>false,'code'=>'unsupported'];
            foreach (['from','to','subject','date'] as $required) if (count($headers[$required]??[])!==1) return $failure;
            foreach (['message-id','mime-version','content-type','content-transfer-encoding'] as $single) if (count($headers[$single]??[])>1) return $failure;
            $signature=$headers['dkim-signature'][0]; $tags=self::tags(explode(':',$signature,2)[1]);
            if (($tags['d']??'')!=='toconline.pt') return ['verified'=>false,'code'=>'unaligned'];
            if (($tags['v']??'')!=='1' || ($tags['a']??'')!=='rsa-sha256' || isset($tags['l'])
                || ($tags['q']??'dns/txt')!=='dns/txt') return ['verified'=>false,'code'=>'unsupported'];
            if (isset($tags['i']) && !preg_match('/@toconline\.pt\z/',$tags['i'])) return $failure;
            $selector=$tags['s']??'';
            if (strlen($selector)>200 || !preg_match('/\A[a-zA-Z0-9_-]+(?:\.[a-zA-Z0-9_-]+)*\z/',$selector)) return $failure;
            $now??=time();
            foreach (['t','x'] as $timeTag) if (isset($tags[$timeTag]) && !preg_match('/\A[0-9]{1,12}\z/',$tags[$timeTag])) return $failure;
            if (isset($tags['x']) && ((int)$tags['x']<$now || (int)$tags['x']<(int)($tags['t']??0))) return ['verified'=>false,'code'=>'expired'];
            if (isset($tags['t']) && (int)$tags['t']>$now+300) return $failure;
            $date=strtotime(trim(explode(':',$headers['date'][0],2)[1]));
            if (!$date || $date>$now+300 || $date<$now-30*86400) return ['verified'=>false,'code'=>'expired'];
            $modes=explode('/',$tags['c']??'simple/simple'); $hMode=$modes[0]; $bMode=$modes[1]??'simple';
            if (count($modes)>2 || !in_array($hMode,['simple','relaxed'],true) || !in_array($bMode,['simple','relaxed'],true)) return $failure;
            $signed=array_map('strtolower',explode(':',preg_replace('/\s+/','',$tags['h']??'')));
            // All fields used to interpret/classify this receipt must be protected.
            foreach (['from','to','subject','date','message-id','mime-version','content-type','content-transfer-encoding'] as $name) {
                if (isset($headers[$name]) && !in_array($name,$signed,true)) return ['verified'=>false,'code'=>'unsigned_fields'];
            }
            $from=trim(preg_replace('/\r\n[ \t]+/',' ',explode(':',$headers['from'][0],2)[1]));
            if (!preg_match('/\A(?:[^<>]*<no_reply@toconline\.pt>|no_reply@toconline\.pt)\z/i',$from)) return $failure;
            $to=trim(preg_replace('/\r\n[ \t]+/',' ',explode(':',$headers['to'][0],2)[1]));
            if (!preg_match('/\A(?:[^<>]*<([a-z0-9._+-]+@(welcomehostel|citycenterhostel)\.pt)>|([a-z0-9._+-]+@(welcomehostel|citycenterhostel)\.pt))\z/i',$to,$m)) return $failure;
            $recipient=strtolower(($m[1]??'')!==''?$m[1]:$m[3]);
            $canonicalBody=$body;
            if ($bMode==='relaxed') {
                $canonicalBody=preg_replace('/[ \t]+/',' ',$canonicalBody);
                $canonicalBody=preg_replace('/[ \t]+(?=\r\n|\z)/','',$canonicalBody);
            }
            $canonicalBody=rtrim($canonicalBody,"\r\n");
            $canonicalBody=$canonicalBody===''&&$bMode==='relaxed'?'':$canonicalBody."\r\n";
            $bh=base64_decode(preg_replace('/\s+/','',$tags['bh']??''),true);
            if ($bh===false || !hash_equals(hash('sha256',$canonicalBody,true),$bh)) return ['verified'=>false,'code'=>'body_mismatch'];
            $data=''; $used=[];
            foreach ($signed as $name) {
                if (!preg_match('/\A[a-z0-9-]+\z/',$name)) return $failure;
                $index=count($headers[$name]??[])-1-($used[$name]??0); $used[$name]=($used[$name]??0)+1;
                if ($index>=0) $data.=self::header($headers[$name][$index],$hMode)."\r\n";
            }
            $emptySignature=preg_replace('/([;:]\s*b[ \t]*=)[^;]*/','$1',$signature,-1,$removed);
            if ($removed!==1) return $failure;
            $data.=self::header($emptySignature,$hMode);
            $dnsName=$selector.'._domainkey.toconline.pt';
            $records=$resolver ? $resolver($dnsName) : @dns_get_record($dnsName,DNS_TXT);
            if (!is_array($records) || count($records)!==1) return ['verified'=>false,'code'=>'dns_unavailable'];
            $record=$records[0]; $key=self::tags(isset($record['entries'])?implode('',$record['entries']):(string)($record['txt']??''));
            if (($key['v']??'DKIM1')!=='DKIM1' || ($key['k']??'rsa')!=='rsa' || empty($key['p'])
                || !array_intersect(explode(':',$key['s']??'*'),['*','email'])
                || !in_array('sha256',explode(':',$key['h']??'sha256'),true)
                || in_array('y',explode(':',$key['t']??''),true)) return $failure;
            $keyBytes=base64_decode(preg_replace('/\s+/','',$key['p']),true);
            if ($keyBytes===false) return $failure;
            $public=@openssl_pkey_get_public("-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($keyBytes),64,"\n")."-----END PUBLIC KEY-----\n");
            if ($public===false) return $failure;
            $details=openssl_pkey_get_details($public);
            if (($details['type']??-1)!==OPENSSL_KEYTYPE_RSA || ($details['bits']??0)<1024) return $failure;
            $signatureBytes=base64_decode(preg_replace('/\s+/','',$tags['b']??''),true);
            if ($signatureBytes===false || @openssl_verify($data,$signatureBytes,$public,OPENSSL_ALGO_SHA256)!==1) return ['verified'=>false,'code'=>'signature_mismatch'];
            return ['verified'=>true,'code'=>'verified','recipient'=>$recipient,'signed_at'=>gmdate('c',$date)];
        } catch (Throwable) { return $failure; }
    }
}
