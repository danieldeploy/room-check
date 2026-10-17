<?php
declare(strict_types=1);
require_once __DIR__.'/CloudbedsMailMessage.php';
require_once __DIR__.'/CloudbedsMailAuthentication.php';

/** Account-bound delivery of PDFs only. No City message is forwarded to Welcome. */
final class CloudbedsBridge
{
    public const RECIPIENTS=['welcome'=>'info@welcomehostel.pt','city'=>'info@citycenterhostel.pt'];
    public static function packet(string $raw,string $source,?callable $resolver=null,?int $now=null): ?array
    {
        if (!isset(self::RECIPIENTS[$source])) throw new RuntimeException('account_mismatch');
        $raw=CloudbedsMailMessage::wire($raw);
        $pdfs=CloudbedsMailMessage::attachments($raw);
        if (!$pdfs) return null;
        [$headers]=CloudbedsMailMessage::split($raw);
        $date=count($headers['date']??[])===1?strtotime($headers['date'][0]):false;
        // This is an intake limit, never proof of invoice identity. Preserve older
        // originals for review instead of retrying mail outside the verifier's window.
        if ($date!==false && $date<($now??time())-730*86400) throw new RuntimeException('mail_outside_window');
        $auth=CloudbedsMailAuthentication::verify($raw,$resolver,$now);
        if (empty($auth['verified']) || ($auth['recipient']??'')!==self::RECIPIENTS[$source]) throw new RuntimeException('auth_invalid');
        return ['version'=>1,'source'=>$source,'mail_sha256'=>hash('sha256',$raw),
            'pdfs'=>array_map('base64_encode',array_values($pdfs))];
    }
    public static function signature(string $body,string $stamp,string $key): string
    {
        if (!preg_match('/\A[a-f0-9]{64}\z/',$key)) throw new RuntimeException('auth_unconfigured');
        return hash_hmac('sha256',"cloudbeds-v1\n".$stamp."\n".$body,$key);
    }
    public static function verify(string $body,string $stamp,string $signature,string $key,?int $now=null): bool
    {
        return preg_match('/\A[0-9]{10}\z/',$stamp)===1 && abs(($now??time())-(int)$stamp)<=300
            && preg_match('/\A[a-f0-9]{64}\z/',$signature)===1
            && hash_equals(self::signature($body,$stamp,$key),$signature);
    }
    public static function validate(array $packet,string $source): array
    {
        if (count($packet)!==4 || ($packet['version']??null)!==1 || ($packet['source']??null)!==$source
            || !isset(self::RECIPIENTS[$source]) || !is_string($packet['mail_sha256']??null)
            || !preg_match('/\A[a-f0-9]{64}\z/',$packet['mail_sha256'])
            || !is_array($packet['pdfs']??null) || count($packet['pdfs'])<1 || count($packet['pdfs'])>5) throw new RuntimeException('invalid_document');
        $pdfs=[]; $total=0;
        foreach ($packet['pdfs'] as $encoded) {
            if (!is_string($encoded) || strlen($encoded)>2800000) throw new RuntimeException('invalid_document');
            $pdf=base64_decode($encoded,true);
            if (!is_string($pdf) || strlen($pdf)>CloudbedsMailMessage::MAX_PDF || !str_starts_with($pdf,'%PDF-')
                || !str_contains(substr($pdf,-2048),'%%EOF')) throw new RuntimeException('invalid_document');
            $total+=strlen($pdf); if ($total>CloudbedsMailMessage::MAX_BYTES) throw new RuntimeException('document_limit');
            $pdfs[hash('sha256',$pdf)]=$pdf;
        }
        return $pdfs;
    }
}
