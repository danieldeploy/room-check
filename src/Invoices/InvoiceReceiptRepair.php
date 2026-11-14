<?php
declare(strict_types=1);

/** Targeted, conservative repair. Caller holds the existing invoice worker lock. */
final class InvoiceReceiptRepair
{
    public function __construct(private readonly InvoiceVault $vault,private readonly object $drive,private readonly object $analysis) {}

    private static function sameInvoice(array $a,array $b): bool
    {
        foreach (['issuer_nif','company_nif','invoice_number','issued_on'] as $key) {
            if (empty($a[$key]) || empty($b[$key]) || $a[$key]!==$b[$key]) return false;
        }
        if (!empty($a['total']) && !empty($b['total']) && $a['total']!==$b['total']) return false;
        return true;
    }
    private function cached(array $file): string
    {
        if (!preg_match('/\A[a-f0-9]{64}\z/',$file['sha256']??'')) throw new RuntimeException('drive_verify');
        $bytes=file_get_contents($this->vault->path('receipt-page-'.$file['sha256'].'.pdf'));
        if (!is_string($bytes) || !hash_equals($file['sha256'],hash('sha256',$bytes))) throw new RuntimeException('drive_verify');
        return $bytes;
    }
    /** No source upload, move or send occurs here. Commit claims + replacement atomically afterwards. */
    public function prepare(array $s,string $id,string $nif,callable $read): array
    {
        $d=$s['documents'][$id];
        if (($d['fiscal_status']??'N')!=='N') throw new RuntimeException('receipt_repair_ambiguous');
        if (($d['company_nif']??'')!==$nif) throw new RuntimeException('receipt_company_changed');
        $parts=[['bytes'=>$read($d),'format'=>$d['format']]];$sources=[];$total=strlen($parts[0]['bytes']);
        $files=array_values(array_filter($this->drive->scanFiles($d['source_month']),static fn($f)=>!isset($s['documents'][$f['id']])));
        if (count($files)>19) throw new RuntimeException('receipt_repair_limit');
        foreach ($files as $f) {
            $format=InvoiceDriveClient::receiptFormat($f);
            if (!$format || empty($f['md5Checksum']) || ($f['size']??0)<5 || $f['size']>10*1024*1024) continue;
            $f+=['format'=>$format,'source_parent'=>$f['parents'][0]??''];
            $bytes=$this->drive->scanDocument($f);
            $manifest=$this->analysis->analyse($bytes,$format,$nif);
            // A mixed original must not be claimed: unrelated pages still need normal intake.
            if (count($manifest['groups'])!==1) continue;
            $g=$manifest['groups'][0];
            if (!self::sameInvoice($d,$g) || !empty($g['uncertain'])
                || in_array($g['review_reason']??'',['receipt_grouping_required','receipt_company_changed'],true)) continue;
            $total+=strlen($bytes);if ($total>10*1024*1024) throw new RuntimeException('receipt_repair_limit');
            $f['sha256']=hash('sha256',$bytes);$sources[]=$f;$parts[]=['bytes'=>$bytes,'format'=>$format];
        }
        if (count($s['documents'])+count($sources)+1>2000) throw new RuntimeException('receipt_limit');
        if (!$sources) throw new RuntimeException('receipt_repair_not_found');
        $joined=$file=null;$keep='';
        try {
        $joined=$this->analysis->assemble($parts);$bytes=$this->cached($joined);
        $manifest=$this->analysis->analyse($bytes,'pdf',$nif);
        if (count($manifest['groups'])!==1) throw new RuntimeException('receipt_repair_ambiguous');
        $g=$manifest['groups'][0];
        if (!self::sameInvoice($d,$g) || empty($g['complete']) || !empty($g['uncertain']) || !empty($g['review_reason'])
            || $g['pages']!==range(1,$manifest['page_count'])) throw new RuntimeException('receipt_repair_ambiguous');
        // New pages cannot reinterpret the target's authoritative QR fields.
        if (!empty($d['qr_fields']) && !empty($g['qr_fields'])) {
            $a=$d['qr_fields'];$b=$g['qr_fields'];ksort($a);ksort($b);
            if ($a!==$b) throw new RuntimeException('receipt_repair_ambiguous');
        }
        $order=[];
        foreach ($g['page_evidence']??[] as $e) {
            if (($e['printed_page']??0)<1 || isset($order[$e['printed_page']])) throw new RuntimeException('receipt_repair_ambiguous');
            $order[$e['printed_page']]=$e['source_page'];
        }
        ksort($order);
        if (array_keys($order)!==range(1,$manifest['page_count'])) throw new RuntimeException('receipt_repair_ambiguous');
        $order=array_values($order);$file=$this->analysis->assemble($parts,$order);
        $new=$this->drive->newId();InvoiceDriveClient::assertId($new);
        $replacement=array_merge($d,$g,$file,['id'=>$new,'name'=>pathinfo($d['name'],PATHINFO_FILENAME).'_complete.pdf',
            'format'=>'pdf','mimeType'=>'application/pdf','stage'=>'ready','error'=>'','review_reason'=>'','toconline_state'=>'pending',
            'pages'=>range(1,$manifest['page_count']),'page_number'=>1,'page_count'=>$manifest['page_count'],'pdf_pages_checked'=>true,
            'repair_parts'=>array_merge([['id'=>$id]],array_map(static fn($f)=>['id'=>$f['id']],$sources)),
            'repair_order'=>$order,'replaces'=>$id,'created_at'=>gmdate('c'),'attempts'=>0,'next_attempt'=>0]);
        foreach (['drive_parent','pending_parent','preserved_at','archived_at','original_parent','ocr_id','ocr_cleaned','ocr_creating','duplicate_keeper','resume_stage','repair_original_error'] as $key) unset($replacement[$key]);
        if (($d['data_source']??'')==='qr') foreach (['data_source','qr_fields','fiscal_status','document_type','atcud','issuer_nif','company_nif','invoice_number','issued_on','total'] as $key) if (isset($d[$key])) $replacement[$key]=$d[$key];
        $s['documents'][$new]=$replacement;
        $s['documents'][$id]['stage']='superseded';$s['documents'][$id]['error']='';$s['documents'][$id]['replacement_id']=$new;
        $parent=$d['source_id'];
        foreach ($s['documents'] as &$source) if (!empty($source['children'])) {
            $source['children']=array_map(static fn($child)=>$child===$id?$new:$child,$source['children']);
        }
        unset($source);
        $s['documents'][$parent]['legacy_children']=array_values(array_unique(array_merge($s['documents'][$parent]['legacy_children']??[],[$id])));
        foreach ($sources as $f) {
            $s['documents'][$f['id']]=array_merge($f,['children'=>[$new],'stage'=>'split','toconline_state'=>'receipt_per_page','error'=>'',
                'root'=>$d['root'],'source'=>$d['source'],'source_month'=>$d['source_month'],'expected_period'=>$d['expected_period'],
                'process_id'=>$d['process_id'],'requested_by'=>$d['repair_requested_by'],'created_at'=>gmdate('c'),
                'analysis_version'=>InvoiceReceiptAnalysis::VERSION,'repair_for'=>$new,'attempts'=>0]);
        }
        $keep=$file['sha256'];return $s;
        } finally {
            $referenced=array_column($s['documents'],'sha256');
            foreach ([$joined,$file] as $temporary) {
                $sha=$temporary['sha256']??'';
                if ($sha!=='' && $sha!==$keep && !in_array($sha,$referenced,true)) {
                    $path=$this->vault->path('receipt-page-'.$sha.'.pdf');if (is_file($path)) unlink($path);
                }
            }
        }
    }
}
