<?php
declare(strict_types=1);
require_once __DIR__.'/InvoiceReceipts.php';
require_once __DIR__.'/InvoiceGemini.php';

/** Bounded manager projection of persisted state; no worker, provider or vault writes. */
final class InvoiceReceiptDiagnostics
{
    private static function code(mixed $value): string
    {
        return $value==='' || $value===null ? '' : (is_string($value) && isset(InvoiceText::TEXT[$value])?$value:'receipt_failed');
    }
    private static function stage(mixed $value): string
    {
        return in_array($value,['queued','reading','ready','review','failed','split','split_completed','archived',
            'moving','preserving','trashing','discarding','discarded','duplicate_trashed','correcting','repairing',
            'source_trashing','source_moving','source_processed','superseded'],true)?$value:'unknown';
    }
    private static function date(mixed $value): ?string
    {
        if (!is_string($value) || $value==='') return null;
        try {return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('Europe/Lisbon'))->format(DATE_ATOM);}
        catch (Exception) {return null;}
    }
    public static function read(InvoiceVault $vault,string $process,int $offset=0): array
    {
        if (!preg_match('/\A(?:legacy|[a-f0-9]{24})\z/',$process) || $offset<0 || $offset>10000) throw new RuntimeException('invalid_request');
        if (!$vault->has(InvoiceReceipts::FILE)) throw new RuntimeException('receipt_history_missing');
        $state=InvoiceReceiptReport::withProcesses($vault->read(InvoiceReceipts::FILE));
        if (!isset($state['processes'][$process])) throw new RuntimeException('receipt_run_not_found');
        $selected=InvoiceReceiptReport::selected($state,$process);$p=$state['processes'][$process];$now=time();
        $settings=$vault->has('toconline-settings.enc')?$vault->read('toconline-settings.enc'):[];
        $sendEnabled=!empty($settings['enabled']) && !empty($settings['pilot_verified']);
        $hasLedger=$vault->has('toconline-ledger.enc');$deliveries=[];
        if ($hasLedger) foreach ($vault->read('toconline-ledger.enc') as $entry) {
            if (!is_array($entry) || (!is_string($entry['document_id']??null) && !is_int($entry['document_id']??null))) throw new RuntimeException('receipt_history_missing');
            $id=(string)$entry['document_id'];
            if (!str_starts_with($id,'receipt:') || !isset($selected['documents'][substr($id,8)])) continue;
            if (!isset($deliveries[$id]) || strcmp((string)($entry['attempted_at']??''),(string)($deliveries[$id]['attempted_at']??''))>0) $deliveries[$id]=$entry;
        }
        $rows=[];
        foreach (array_slice($selected['documents'],$offset,100,true) as $id=>$d) {
            $delivery=$deliveries['receipt:'.$id]??null;
            $row=['document_ref'=>hash('sha256',(string)$id),'name'=>mb_substr((string)($d['name']??''),0,240),
                'stage'=>self::stage($d['stage']??null),'resume_stage'=>isset($d['resume_stage'])?self::stage($d['resume_stage']):null,
                'error_code'=>self::code($d['error']??''),'retry_kind'=>in_array($d['retry_kind']??'',['pacing','quota','transient'],true)?$d['retry_kind']:null,
                'blocked'=>!empty($d['blocked']),'format'=>in_array($d['format']??'',['pdf','jpg'],true)?$d['format']:null,
                'has_children'=>!empty($d['children']),'category_check_required'=>!empty($d['category_check_required']),
                'classification_current'=>!empty($d['sha256']) && ($d['category_check']['sha256']??'')===$d['sha256'],
                'toconline_state'=>self::code($d['toconline_state']??''),'archived_at'=>self::date($d['archived_at']??null),
                'delivery_attempt_recorded'=>$hasLedger?$delivery!==null:null,
                'delivery'=>$delivery===null?null:['state'=>self::code($delivery['state']??''),'reason'=>self::code($delivery['delivery_reason']??''),'attempted_at'=>self::date($delivery['attempted_at']??null)]];
            foreach (['attempts','next_attempt','retry_started_at','retry_deadline','toc_next_check','toc_deadline','toc_checks','page_count','size'] as $key)
                $row[$key]=max(0,(int)($d[$key]??0));
            $row['failure_facts']=InvoiceReceiptReport::failureFacts($d);
            $rows[]=$row;
        }
        $budget=$vault->has(InvoiceGemini::BUDGET)?$vault->read(InvoiceGemini::BUDGET):[];
        $gemini=['configured'=>$vault->has(InvoiceGemini::SETTINGS),'model'=>InvoiceGemini::MODEL,
            'budget_day'=>preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/',(string)($budget['day']??''))?$budget['day']:null,
            'http_diagnostics_available'=>(bool)array_filter(array_column($rows,'failure_facts'),static fn($f)=>str_starts_with($f['phase']??'','receipt_gemini_'))];
        foreach (['requests','failures','next_at'] as $key)$gemini[$key]=max(0,(int)($budget[$key]??0));
        $quota=$budget['daily_quota']??[];
        $gemini['daily_quota']=['state'=>in_array($quota['state']??'',['waiting','probing','failed'],true)?$quota['state']:null,
            'retry_at'=>max(0,(int)($quota['retry_at']??0)),'reason'=>self::code($quota['reason']??'')];
        $summary=InvoiceReceiptReport::summary($selected['documents']);unset($summary['duplicates']);
        $summary['errors']=array_values(array_unique($summary['errors']));
        $global=InvoiceReceiptReport::progress($state,$sendEnabled,$now);
        return ['captured_at'=>(new DateTimeImmutable('@'.$now))->setTimezone(new DateTimeZone('Europe/Lisbon'))->format(DATE_ATOM),
            'snapshot_is_transactional'=>false,'timezone'=>'Europe/Lisbon',
            'process'=>['id'=>$process,'year'=>(int)($p['year']??0),'months'=>array_values(array_map('intval',$p['months']??[])),
                'requested_at'=>self::date($p['requested_at']??null),'error_code'=>self::code($p['error']??'')],
            'shared_failure'=>empty($selected['worker_retry'])?null:[
                'phase'=>self::code($selected['worker_retry']['phase']),'reason'=>self::code($selected['worker_retry']['error']),
                'attempts'=>(int)$selected['worker_retry']['attempts'],'next_attempt'=>(int)$selected['worker_retry']['next_attempt'],
                'blocked'=>(bool)$selected['worker_retry']['blocked'],'failure_facts'=>InvoiceReceiptReport::failureFacts($selected['worker_retry'])],
            'shared_archive_incidents'=>$vault->has(InvoiceReceipts::PHASE_ERRORS)?$vault->read(InvoiceReceipts::PHASE_ERRORS):[],
            'worker_seen_at'=>max(0,(int)($state['worker_seen_at']??0)),
            'progress'=>InvoiceReceiptReport::progress($selected,$sendEnabled,$now),'global_progress'=>$global,
            'can_start_new_run'=>InvoiceReceiptReport::canStartNewRun($state,$sendEnabled),'summary'=>$summary,'gemini'=>$gemini,
            'mail_sending_enabled'=>$sendEnabled,'delivery_ledger_available'=>$hasLedger,
            'documents'=>$rows,'next_offset'=>$offset+count($rows)<count($selected['documents'])?$offset+count($rows):null];
    }
}
