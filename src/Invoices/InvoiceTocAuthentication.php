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

    /** Each aligned signature is verified against the original, unchanged message.
     * Multiple signatures are common with mail relays; unaligned signatures cannot
     * grant trust. Never remove headers to manufacture a different signed message.
     */
    public static function verify(string $raw, ?callable $resolver=null, ?int $now=null): array
    {
        $fallback=['verified'=>false,'code'=>'unverified'];
        try {
            if (strlen($raw)>262144 || str_contains($raw,"\0")) return $fallback;
            $head=preg_split('/\r?\n\r?\n/',$raw,2)[0];
            if (strlen($head)>32768) return $fallback;
            $head=preg_replace('/\r?\n[ \t]+/',' ',$head);
            preg_match_all('/^dkim-signature:[ \t]*(.*)$/mi',$head,$signatures);
            $count=count($signatures[1]);
            if ($count===0) return ['verified'=>false,'code'=>'unsigned','facts'=>['signature_count'=>0]];
            if ($count>5) return ['verified'=>false,'code'=>'unsupported','facts'=>['signature_count'=>$count]];
            $best=['verified'=>false,'code'=>'unaligned','facts'=>['signature_count'=>$count]];
            foreach ($signatures[1] as $index=>$signature) {
                try { $tags=self::tags(trim($signature)); } catch (Throwable) { continue; }
                if (($tags['d']??'')!=='toconline.pt') continue;
                $signed=array_map('strtolower',explode(':',preg_replace('/\s+/','',$tags['h']??'')));
                $facts=['signature_count'=>$count,'aligned_signer'=>true,
                    'rsa_sha256'=>($tags['a']??'')==='rsa-sha256','partial_body'=>isset($tags['l']),
                    'signed_to'=>in_array('to',$signed,true),'signed_subject'=>in_array('subject',$signed,true)];
                $result=self::verifySignature($raw,$resolver,$now,$index);
                $result['facts']=array_merge($facts,$result['facts']??[]);
                if ($result['verified']) return $result;
                $best=$result;
            }
            return $best;
        } catch (Throwable) { return $fallback; }
    }

    /** Resolver injection is for synthetic offline tests only; production uses DNS TXT. */
    private static function verifySignature(string $raw, ?callable $resolver, ?int $now, int $signatureIndex): array
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
            if (!isset($headers['dkim-signature'][$signatureIndex])) return $failure;
            foreach (['from','to','subject','date'] as $required) if (count($headers[$required]??[])!==1) return $failure;
            foreach (['message-id','mime-version','content-type','content-transfer-encoding'] as $single) if (count($headers[$single]??[])>1) return $failure;
            $signature=$headers['dkim-signature'][$signatureIndex]; $tags=self::tags(explode(':',$signature,2)[1]);
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
            // Chained DKIM signatures require additional header-selection validation.
            if (in_array('dkim-signature',$signed,true)) return ['verified'=>false,'code'=>'unsupported'];
            // Protect every top-level field the classifier actually consumes.
            // Message-ID and MIME-Version are not used for identity, dates, MIME
            // decoding or outcome classification. Requiring their signatures would
            // reject valid mail without protecting any decision made by this code.
            $contentType=isset($headers['content-type']) ? trim(explode(':',$headers['content-type'][0],2)[1]) : 'text/plain';
            $multipart=preg_match('/\Amultipart\//i',$contentType)===1;
            $encoding=isset($headers['content-transfer-encoding']) ? strtolower(trim(explode(':',$headers['content-transfer-encoding'][0],2)[1])) : '7bit';
            $facts=['multipart'=>$multipart,'transfer_encoding'=>in_array($encoding,['7bit','8bit','binary','base64','quoted-printable'],true)?$encoding:'other'];
            // The MIME parser ignores transport encoding on the outer multipart
            // container. Its signed Content-Type fixes the boundaries and every
            // child encoding header is covered by the full signed body hash.
            // Only RFC-compatible identity encodings are allowed on that container.
            if ($multipart && !in_array($encoding,['7bit','8bit','binary'],true)) return ['verified'=>false,'code'=>'unsupported','facts'=>$facts];
            $unsigned=[];
            foreach (['from','to','subject','date','content-type','content-transfer-encoding'] as $name) {
                if ($name==='content-transfer-encoding' && $multipart) continue;
                if (isset($headers[$name]) && !in_array($name,$signed,true)) $unsigned[]=$name;
            }
            if ($unsigned) return ['verified'=>false,'code'=>'unsigned_fields','facts'=>array_merge($facts,['unsigned_fields'=>$unsigned])];
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
            return ['verified'=>true,'code'=>'verified','recipient'=>$recipient,'signed_at'=>gmdate('c',$date),'facts'=>$facts];
        } catch (Throwable) { return $failure; }
    }
}
