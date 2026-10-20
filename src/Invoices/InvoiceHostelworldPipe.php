<?php
declare(strict_types=1);
require_once __DIR__.'/InvoiceTocPipe.php';
require_once __DIR__.'/InvoiceAuth.php';
require_once __DIR__.'/HostelworldBridge.php';

/** cPanel-filtered Hostelworld authentication messages; no raw mail is persisted. */
final class InvoiceHostelworldPipe
{
    public function __construct(private readonly InvoiceAuth $auth) {}

    public static function parse(string $raw,array $targets=[]): ?array
    {
        if ($targets) {
            $packet=HostelworldBridge::packet($raw,$targets);
            return $packet===null?null:['from'=>$packet['from'],'subject'=>$packet['subject'],'received_at'=>$packet['received_at'],'code'=>$packet['code'],'text'=>''];
        }
        $message=InvoiceTocPipe::parseMessage($raw);
        if (!preg_match('/\A[^<>\s@]+@(?:[A-Za-z0-9-]+\.)*hostelworld\.com\z/i',$message['from'])) return null;
        $subject = preg_replace('/[_-]+/', ' ', $message['subject']);
        if (!preg_match('/\b(login|sign\s*in|security|verification|code|authentication)\b/i',$subject)) return null;
        preg_match_all('/(?<!\d)\d{6}(?!\d)/',$message['text'],$codes);
        $codes[0]=array_values(array_unique($codes[0]));
        if (count($codes[0])!==1 || !$message['received_at']) return null;
        return $message;
    }

    public function receive(int $accountId,string $raw): bool
    {
        $message=self::parse($raw,$this->auth->hostelworldLinkTargets($accountId));
        return $message!==null && $this->auth->receiveHostelworldEmail($accountId,$message);
    }
}
