<?php
declare(strict_types=1);
/** Authentication material only. Destinations come exclusively from the account map. */
final class HostelworldLinkToken
{
    private const PREFIX='hwlink1.';
    private const KEYS=['token','code','key','auth','authToken','loginToken','signature','sig','nonce','expires','expiry','timestamp'];

    public static function target(array $target): array
    {
        $host=strtolower((string)($target['host']??$target['linkHost']??''));
        $path=(string)($target['path']??$target['linkPath']??'');
        if (!preg_match('/\A(?:[a-z0-9-]+\\.)*hostelworld\\.com\z/',$host)
            || !preg_match('/\A\\/[a-zA-Z0-9_\\/.-]{0,200}\z/',$path)
            || preg_match('/[a-zA-Z0-9_-]{24,}/',$path)
            || str_contains($path,'..') || str_contains($path,'//')) throw new RuntimeException('auth_invalid');
        return ['host'=>$host,'path'=>$path];
    }

    private static function params(array $params): array
    {
        if (!$params || count($params)>8) throw new RuntimeException('auth_invalid');
        $hasSecret=false;
        foreach ($params as $name=>$value) {
            if (!in_array($name,self::KEYS,true) || !is_string($value)
                || !preg_match('/\A[A-Za-z0-9_~.+\\/=-]{1,1024}\z/',$value)) throw new RuntimeException('auth_invalid');
            if (!in_array($name,['expires','expiry','timestamp'],true)) $hasSecret=true;
        }
        if (!$hasSecret) throw new RuntimeException('auth_invalid');
        ksort($params);
        return $params;
    }

    private static function pathToken(array $target): bool
    {
        return $target['host']==='inbox.hostelworld.com' && $target['path']==='/login/';
    }

    private static function pathParams(array $params): array
    {
        if (array_diff(array_keys($params),['token','Language'])
            || !is_string($params['token']??null)
            || !preg_match('/\A[a-fA-F0-9]{32}\z/',$params['token'])
            || (isset($params['Language']) && (!is_string($params['Language'])
                || !preg_match('/\A[A-Za-z]{2,20}\z/',$params['Language'])))) throw new RuntimeException('auth_invalid');
        ksort($params);
        return $params;
    }

    public static function encode(string $url,array $target): string
    {
        $target=self::target($target); $parts=parse_url($url);
        if (!$parts || ($parts['scheme']??'')!=='https' || strtolower($parts['host']??'')!==$target['host']
            || (!self::pathToken($target) && ($parts['path']??'/')!==$target['path']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['port']) || isset($parts['fragment']) || strlen($url)>4096) throw new RuntimeException('auth_invalid');
        $params=[];
        foreach (($parts['query']??'')==='' ? [] : explode('&',$parts['query']) as $pair) {
            $bits=explode('=',$pair,2);
            if (count($bits)!==2) throw new RuntimeException('auth_invalid');
            $name=rawurldecode($bits[0]); $value=rawurldecode($bits[1]);
            if (array_key_exists($name,$params)) throw new RuntimeException('auth_invalid');
            $params[$name]=$value;
        }
        if (self::pathToken($target)) {
            if (array_diff(array_keys($params),['Language'])
                || !preg_match('~\A/login/([a-fA-F0-9]{32})\z~',$parts['path']??'',$match)) throw new RuntimeException('auth_invalid');
            $params['token']=$match[1];
            $params=self::pathParams($params);
        } else $params=self::params($params);
        $json=json_encode($params,JSON_THROW_ON_ERROR);
        $code=self::PREFIX.rtrim(strtr(base64_encode($json),'+/','-_'),'=');
        if (strlen($code)>2048) throw new RuntimeException('auth_invalid');
        return $code;
    }

    public static function decode(string $code,array $target): string
    {
        $target=self::target($target);
        if (strlen($code)>2048 || !str_starts_with($code,self::PREFIX)) throw new RuntimeException('auth_invalid');
        $body=substr($code,strlen(self::PREFIX));
        if (!preg_match('/\A[A-Za-z0-9_-]+\z/',$body)) throw new RuntimeException('auth_invalid');
        $json=base64_decode(strtr($body,'-_','+/'),true);
        if ($json===false) throw new RuntimeException('auth_invalid');
        try { $params=json_decode($json,true,4,JSON_THROW_ON_ERROR); }
        catch (Throwable) { throw new RuntimeException('auth_invalid'); }
        if (!is_array($params) || array_is_list($params)) throw new RuntimeException('auth_invalid');
        if (self::pathToken($target)) {
            $params=self::pathParams($params);
            $url='https://'.$target['host'].$target['path'].$params['token'];
            if (isset($params['Language'])) $url.='?Language='.rawurlencode($params['Language']);
        } else {
            $params=self::params($params);
            $url='https://'.$target['host'].$target['path'].'?'.http_build_query($params,'','&',PHP_QUERY_RFC3986);
        }
        if (!hash_equals(self::encode($url,$target),$code)) throw new RuntimeException('auth_invalid');
        return $url;
    }

    /** Exactly one distinct approved login link, including duplicate plain/HTML alternatives. */
    public static function extract(string $text,array $targets): ?string
    {
        preg_match_all('~https://[^\\s<>"\']{1,4096}~',$text,$matches);
        $codes=[];
        foreach ($matches[0] as $url) foreach ($targets as $target) {
            try { $codes[]=self::encode(html_entity_decode($url,ENT_QUOTES|ENT_HTML5,'UTF-8'),$target); }
            catch (RuntimeException) {}
        }
        $codes=array_values(array_unique($codes));
        return count($codes)===1?$codes[0]:null;
    }
}
