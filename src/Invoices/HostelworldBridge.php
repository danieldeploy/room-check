<?php
declare(strict_types=1);
require_once __DIR__.'/HostelworldMailMessage.php';
final class HostelworldBridge
{
    public static function packet(string $raw): ?array
    {
        $m=HostelworldMailMessage::parseMessage($raw);
        $subject=preg_replace('/[_-]+/',' ',$m['subject']);
        if (!preg_match('/\A[^<>\s@]+@(?:[A-Za-z0-9-]+\.)*hostelworld\.com\z/i',$m['from'])
            || !preg_match('/\b(login|sign\s*in|security|verification|code|authentication)\b/i',$subject)
            || !$m['received_at'] || abs(time()-$m['received_at'])>150) return null;
        preg_match_all('/(?<!\d)\d{6}(?!\d)/',$m['text'],$codes);
        $codes=array_values(array_unique($codes[0]));
        if (count($codes)!==1 || strlen($m['subject'])>512 || strlen($m['from'])>254) return null;
        return ['source'=>'city','from'=>$m['from'],'subject'=>$m['subject'],'received_at'=>$m['received_at'],'code'=>$codes[0]];
    }
    public static function signature(string $raw,string $timestamp,string $key): string
    {
        return hash_hmac('sha256',$timestamp."\n".hash('sha256',$raw),$key);
    }
    public static function verify(string $raw,string $timestamp,string $signature,string $key): bool
    {
        return strlen($raw)<=4096 && preg_match('/\A[0-9]{10}\z/',$timestamp)
            && abs(time()-(int)$timestamp)<=90 && preg_match('/\A[a-f0-9]{64}\z/',$signature)
            && hash_equals(self::signature($raw,$timestamp,$key),$signature);
    }
}
