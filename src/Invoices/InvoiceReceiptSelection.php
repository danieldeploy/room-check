<?php
declare(strict_types=1);
/** Explicit selections are revalidated against a fresh bounded month listing. */
final class InvoiceReceiptSelection
{
    public static function token(array $f): string
    { return base64_encode(json_encode([$f['id'],$f['md5Checksum'],$f['parents'][0]??'',$f['source_category']??'a4'],JSON_THROW_ON_ERROR)); }
    public static function eligible(array $f): bool
    { return InvoiceDriveClient::receiptFormat($f)!==null && !empty($f['md5Checksum']) && (int)($f['size']??0)>=5 && (int)$f['size']<=10*1024*1024; }
    public static function choose(array $files,array $tokens,array $documents,?string $allowId=null): array
    {
        foreach ($tokens as $token) if (!is_string($token)) throw new RuntimeException('receipt_select_files');
        if (!$tokens || count($tokens)>19 || count(array_unique($tokens))!==count($tokens)) throw new RuntimeException('receipt_select_files');
        $available=[];
        foreach ($files as $f) if (self::eligible($f) && (!isset($documents[$f['id']]) || $f['id']===$allowId)) $available[self::token($f)]=$f;
        $chosen=[];
        foreach ($tokens as $token) {
            if (!is_string($token) || strlen($token)>1024 || !isset($available[$token])) throw new RuntimeException('receipt_selection_changed');
            $chosen[]=$available[$token];
        }
        return $chosen;
    }
}
