<?php
declare(strict_types=1);

/** Persisted receipt-only timing; periodic status reads never consume attempts. */
final class InvoiceReceiptRetry
{
    public static function failure(array $previous,Throwable $failure,int $now): array
    {
        $code=$failure->getMessage();
        if (!preg_match('/\A(?:drive_|receipt_|gemini_|toc_)[a-z_]+\z/',$code)) $code='receipt_failed';
        $r=['error'=>$code,'attempts'=>(int)($previous['attempts']??0),'next_attempt'=>0,'blocked'=>false,
            'retry_started_at'=>($previous['retry_kind']??'')==='transient'?(int)($previous['retry_started_at']??$now):$now,'retry_kind'=>'transient','retry_deadline'=>0];
        if ($failure instanceof InvoiceGeminiRetry && in_array($code,['gemini_wait','gemini_daily_quota'],true)) {
            $r['retry_kind']=$code==='gemini_daily_quota'?'quota':'pacing';
            $r['next_attempt']=$failure->retryAt;
            if ($code==='gemini_wait') {
                $r['error']='';
                if (($previous['retry_kind']??'')==='transient') {
                    $r['retry_kind']='transient';$r['error']=$previous['error']??'';
                    $r['retry_deadline']=(int)($previous['retry_deadline']??0);
                    if ($r['retry_deadline'] && $r['next_attempt']>$r['retry_deadline']) {
                        $r['blocked']=true;$r['next_attempt']=0;
                    }
                }
            }
            return $r;
        }
        $r['attempts']++;
        $r['retry_deadline']=$r['retry_started_at']+1800;
        if (!in_array($code,['drive_network','drive_upload','gemini_retry','gemini_quota'],true) || $r['attempts']>=6 || $now>=$r['retry_deadline']) {
            $r['blocked']=true;return $r;
        }
        // Skip missed six-minute slots; never extend the original thirty-minute window.
        $r['next_attempt']=$r['retry_started_at']+(intdiv(max(0,$now-$r['retry_started_at']),360)+1)*360;
        if ($failure instanceof InvoiceGeminiRetry) $r['next_attempt']=max($r['next_attempt'],$failure->retryAt);
        if ($r['next_attempt']>$r['retry_deadline']) { $r['next_attempt']=0;$r['blocked']=true; }
        return $r;
    }

    public static function expired(array $record,int $now): bool
    {
        return ($record['retry_kind']??'')==='transient' && !empty($record['retry_deadline']) && $now>$record['retry_deadline'];
    }

    public static function reset(array $record): array
    {
        unset($record['failure_facts'],$record['wait_reason'],$record['retry_started_at'],$record['retry_kind'],$record['retry_deadline'],$record['blocked']);
        $record['attempts']=0;$record['next_attempt']=0;return $record;
    }
}
