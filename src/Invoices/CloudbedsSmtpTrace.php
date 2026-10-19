<?php
declare(strict_types=1);
require_once __DIR__.'/CloudbedsMailMessage.php';

/** Only called by the source-bound native Maildir/Exim pipe, never on uploaded mail.
 * Use the first SMTP ingress, optionally preceded by one local Exim delivery.
 * Never search lower, sender-supplied trace headers for an authorized IP.
 */
final class CloudbedsSmtpTrace
{
    public static function extract(string $raw,string $source,?string &$stage=null): ?array
    {
        try {
            $stage='source';
            $expected=['welcome'=>'info@welcomehostel.pt','city'=>'info@citycenterhostel.pt'][$source]??null;
            if ($expected===null) return null;
            $stage='headers';[$headers]=CloudbedsMailMessage::split($raw);
            foreach (['from','to','date','subject'] as $name) if (count($headers[$name]??[])!==1) return null;
            $stage='sender';
            if (!preg_match('/\A(?:[^<>]*<([a-z0-9._+-]+@cloudbeds\.com)>|([a-z0-9._+-]+@cloudbeds\.com))\z/i',trim($headers['from'][0]))) return null;
            $stage='recipient';
            if (!preg_match('/\A(?:[^<>]*<([^<>\s]+)>|([^<>\s]+))\z/',trim($headers['to'][0]),$to)
                || strtolower(($to[1]??'')!==''?$to[1]:($to[2]??''))!==$expected) return null;
            $stage='trace_count';$received=$headers['received']??[];
            if (!$received || count($received)>10) return null;
            $stage='first_hop';$index=0; $first=self::hop($received[0]); if ($first===null) return null;
            if (in_array($first['protocol'],['local','spam-scanned'],true)) {
                // Exim's trusted local spam scanner reinjects before delivery.
                // It is not the SMTP sender whose IP must pass SPF.
                if ($first['peer_info']!=='') return null;
                $stage='smtp_hop';$index=1;
                if (!isset($received[1])) return null;
                $ingress=self::hop($received[1]);
                if ($ingress===null) return null;
                $stage='receiver_match';if ($ingress['receiver']!==$first['receiver']) return null;
            } else $ingress=$first;
            $stage='protocol';
            if (!in_array($ingress['protocol'],['esmtp','esmtps'],true)) return null;
            // A genuine local receipt is always prepended. Later trace claims cannot
            // replace the client observed by our native receiving MTA.
            $stage='envelope';$line=$received[$index];
            if (!preg_match('/\A[a-z0-9.-]{1,253}\z/i',$ingress['peer'])
                || preg_match_all('/\(envelope-from\s+<([^<>\s]{1,254})>\)/i',$line,$envelopes)!==1) return null;
            $envelope=[null,$envelopes[1][0]];
            $mailfrom=$envelope[1];
            $stage='alignment';if (!preg_match('/\A[^<>\s@]+@(?:[a-z0-9-]+\.)*cloudbeds\.com\z/i',$mailfrom)) return null;
            $stage='peer_ip';
            preg_match_all('/\[([a-f0-9:.]+)\]/i',$ingress['peer_info'],$ips);
            $ips=array_values(array_unique(array_filter($ips[1],static fn($ip)=>filter_var($ip,FILTER_VALIDATE_IP)!==false)));
            if (count($ips)!==1) return null;
            $stage='return_path';$returns=$headers['return-path']??[];
            if (!$returns || count($returns)>2) return null;
            foreach ($returns as $return) if (trim($return)!=='<'.$mailfrom.'>') return null;
            $stage='smtp_recipient';if (preg_match('/\bfor\s+<?([^<>\s;]+)>?\s*;/i',$line,$recipient)
                && strtolower($recipient[1])!==$expected) return null;
            $stage='usable';return ['ip'=>$ips[0],'mailfrom'=>$mailfrom,'helo'=>strtolower($ingress['peer'])];
        } catch (Throwable) { return null; }
    }
    private static function hop(string $line): ?array
    {
        $line=trim(preg_replace('/\s+/',' ',$line));
        if (preg_match_all('/\bby\s+/i',$line)!==1 || preg_match_all('/\bwith\s+/i',$line)!==1
            || !preg_match('/\Afrom ([a-z0-9._-]{1,253})(?: \(([^()]{0,1024})\))? by (server[0-9]+\.romania-webhosting\.com) with (local|spam-scanned|esmtp|esmtps)(?=\s|;|\z)/i',$line,$m)) return null;
        return ['peer'=>$m[1],'peer_info'=>$m[2]??'','receiver'=>strtolower($m[3]),'protocol'=>strtolower($m[4])];
    }
}
