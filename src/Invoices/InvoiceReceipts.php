<?php
declare(strict_types=1);
require_once __DIR__.'/InvoiceDrive.php';
require_once __DIR__.'/InvoiceToconline.php';
require_once __DIR__.'/InvoiceReceiptAnalysis.php';
require_once __DIR__.'/InvoiceReceiptReport.php';
require_once __DIR__.'/InvoiceReceiptRepair.php';
require_once __DIR__.'/InvoiceReceiptRetry.php';
require_once __DIR__.'/InvoiceReceiptSelection.php';

/** Manual intake, continued by the existing invoice worker. Every writer holds room_check_invoices. */
final class InvoiceReceipts
{
    public const FILE='receipts-workflow.enc';
    public static function archiveCategory(array $d): string
    { return ($d['format']??'')==='jpg' || ($d['source_category']??'')==='taloes' ? 'taloes' : 'pdf'; }
    public static function categoryReview(array $d): bool
    { return !empty($d['category_check_required']) && empty($d['source_id']) && empty($d['children'])
        && (($d['category_check']['category']??'uncertain')!==($d['source_category']??'a4')
            || !in_array($d['category_check']['category']??'',['a4','taloes'],true)
            || (($d['error']??'')==='receipt_selection_changed' && ($d['replacement_allow_same_id']??true))); }
    public function __construct(private readonly PDO $pdo, private readonly InvoiceVault $vault,
        private readonly object $drive, private readonly InvoiceToconline $toc, private readonly mixed $splitter=null, private readonly ?object $analysis=null) {}

    public function status(): array
    {
        if ($this->vault->has(self::FILE)) return InvoiceReceiptReport::withProcesses($this->vault->read(self::FILE));
        if ($this->vault->has('toconline-ledger.enc')) foreach ($this->vault->read('toconline-ledger.enc') as $entry) {
            if (str_starts_with((string)$entry['document_id'],'receipt:')) throw new RuntimeException('receipt_history_missing');
        }
        return InvoiceReceiptReport::withProcesses(['documents'=>[],'requested'=>false,'error'=>'']);
    }
    /** Owner-deferred cleanup. Preserve the intake and all invoice delivery states. */
    public static function pauseSourceCleanup(InvoiceVault $vault): void
    {
        if (!$vault->has(self::FILE)) return;
        $s=$vault->read(self::FILE);$s['source_cleanup_paused']=true;
        foreach ($s['documents'] as &$d) {
            if (empty($d['source_id']) && !empty($d['children'])
                && (($d['stage']??'')==='source_trashing' || (($d['stage']??'')==='failed'
                    && ($d['resume_stage']??'')==='source_trashing'
                    && in_array($d['error']??'',['drive_permission','drive_trash_permission'],true)))) {
                $d['stage']='split';$d['error']='';$d['next_attempt']=0;unset($d['resume_stage']);
            }
        }
        unset($d);$vault->save(self::FILE,$s);
    }
    public static function enableProcessedSources(InvoiceVault $vault): void
    {
        self::pauseSourceCleanup($vault);
        if (!$vault->has(self::FILE)) return;
        $s=InvoiceReceiptReport::withProcesses($vault->read(self::FILE));
        $s['source_cleanup_paused']=false;$vault->save(self::FILE,$s);
    }
    /** Read only a recorded document; never accept a caller-supplied Drive path. */
    public function preview(string $id, bool $original = false): array
    {
        $documents=$this->status()['documents'];
        $previewId=InvoiceReceiptReport::previewId($documents,$id);
        if ($previewId===null || ($original && $previewId!==$id)) throw new RuntimeException('receipt_preview_unavailable');
        $d=$documents[$previewId];
        if (!is_array($d) || !in_array($d['format']??'', ['pdf','jpg'],true)
            || in_array($d['stage']??'', ['duplicate_trashed','split_completed','discarding','discarded'],true)) throw new RuntimeException('receipt_preview_unavailable');
        if ($original) {
            $parent=$documents[$d['source_id']??'']??null;
            if (!is_array($parent) || ($parent['format']??'')!=='pdf' || !in_array($id,$parent['children']??[],true)
                || ($parent['stage']??'')==='split_completed') throw new RuntimeException('receipt_preview_unavailable');
            $d=$parent;
        }
        $this->drive->connect();
        $bytes=empty($d['archived_at'])?$this->sourceBytes($d):$this->drive->verifiedReceipt($d);
        if (strlen($bytes)>10*1024*1024 || strlen($bytes)!==(int)($d['size']??0)
            || !InvoiceDriveClient::receiptBytesValid($bytes,$d['format'])
            || (!empty($d['sha256']) && !hash_equals($d['sha256'],hash('sha256',$bytes)))) throw new RuntimeException('drive_verify');
        return ['bytes'=>$bytes,'type'=>$d['format']==='pdf'?'application/pdf':'image/jpeg'];
    }
    private function save(array $state): void { $this->vault->save(self::FILE,$state); }
    private function put(string $id,array $doc): void
    {
        $s=$this->status(); $s['documents'][$id]=$doc; $this->save($s);
    }
    public static function deliveryState(InvoiceVault $vault,string $id,string $state,array $timing=[]): void
    {
        if (!$vault->has(self::FILE)) throw new RuntimeException('receipt_history_missing');
        $s=$vault->read(self::FILE);
        if (!isset($s['documents'][$id])) throw new RuntimeException('receipt_history_missing');
        $s['documents'][$id]['toconline_state']=$state;
        foreach (['toc_next_check','toc_deadline','toc_checks'] as $key) if (isset($timing[$key])) $s['documents'][$id][$key]=(int)$timing[$key];
        if (!in_array($state,['toc_sending','toc_submitted','toc_uncertain'],true)) {
            $s['documents'][$id]['toc_next_check']=0;$s['documents'][$id]['toc_deadline']=0;
        }
        $vault->save(self::FILE,$s);
    }
    public function request(int $actor, int $year, array $months): void
    {
        $currentYear=(int)(new DateTimeImmutable('now',new DateTimeZone('Europe/Lisbon')))->format('Y');
        if ($year<2000 || $year>$currentYear || !$months || count($months)>12) throw new RuntimeException('receipt_select_periods');
        foreach ($months as $month) if (!is_scalar($month) || !preg_match('/\A(?:[1-9]|1[0-2])\z/',(string)$month)) throw new RuntimeException('receipt_select_periods');
        $months=array_values(array_unique(array_map('intval',$months))); sort($months);
        if (!$this->drive->receiptsAuthorized()) throw new RuntimeException('receipt_connect');
        $s=$this->status();
        if ($s['requested']) {
            if (($s['year']??null)!==$year || ($s['months']??[])!==$months) throw new RuntimeException('receipt_process_running');
            return;
        }
        $tocSettings=$this->toc->settings();
        if (!InvoiceReceiptReport::canStart($s,!empty($tocSettings['enabled']) && !empty($tocSettings['pilot_verified']))) throw new RuntimeException('receipt_process_running');
        $pid=bin2hex(random_bytes(12));$s['active_process']=$pid;
        $s['processes'][$pid]=['id'=>$pid,'year'=>$year,'months'=>$months,'requested_at'=>gmdate('c'),'error'=>'','missing_periods'=>[],'notification_cycle'=>1];
        $s['worker_seen_at']=time();$s['requested']=true; $s['requested_by']=$actor; $s['requested_at']=gmdate('c');
        $s['year']=$year; $s['months']=$months; $s['missing_periods']=[]; $s['error']=''; unset($s['discovery_retry']); $this->save($s);
    }
    /** Retry discovery within the same run; never reassign or resend recorded documents. */
    public function retryDiscovery(string $id): void
    {
        $s=$this->status();$p=$s['processes'][$id]??null;
        $settings=$this->toc->settings();
        if (!$p || !InvoiceReceiptReport::canStart($s,!empty($settings['enabled']) && !empty($settings['pilot_verified']))) throw new RuntimeException('worker_busy');
        $s['active_process']=$id;$s['year']=$p['year'];$s['months']=$p['months'];
        self::beginCycle($s,$id);$s['requested']=true;$s['error']='';unset($s['discovery_retry']);$s['processes'][$id]['error']='';$this->save($s);
    }
    public static function validDate(string $value): bool
    {
        $date=DateTimeImmutable::createFromFormat('!Y-m-d',$value,new DateTimeZone('Europe/Lisbon'));
        return $date && $date->format('Y-m-d')===$value && $value>='2000-01-01'
            && $value<=(new DateTimeImmutable('now',new DateTimeZone('Europe/Lisbon')))->format('Y-m-d');
    }
    /** Conservative OCR: explicit issue-date labels, one invoice identity and the configured recipient NIF. */
    public static function classify(string $text,string $nif): ?array
    {
        if (!preg_match('/(?:cliente|adquirente|customer|client)[^\n]{0,100}(?:\n[^\n]{0,100}){0,4}?(?<!\d)'.preg_quote($nif,'/').'(?!\d)/iu',$text)) return null;
        preg_match_all('/(?:data\s*(?:de\s*)?(?:emiss[aã]o|documento|fatura)|invoice\s*date|issue\s*date)\s*[:\-]?\s*(\d{4}[-\/]\d{2}[-\/]\d{2}|\d{2}[\/.-]\d{2}[\/.-]\d{4})(?!\d)/iu',$text,$m);
        $dates=[];
        foreach ($m[1] as $raw) {
            $parts=preg_split('/[\/.-]/',$raw);
            $date=strlen($parts[0])===4?implode('-',$parts):$parts[2].'-'.$parts[1].'-'.$parts[0];
            if (!self::validDate($date)) return null; $dates[$date]=true;
        }
        preg_match_all('/(?:^|\n)\s*(?:fatura(?:[- ]recibo|\s+simplificada)?|factura|recibo|invoice|FT|FS|FR)\s*(?:n[.º°o]*\s*)?[:#]?\s+([A-Z0-9][A-Z0-9._\/ -]{0,80})\s*$/imu',$text,$n);
        $numbers=array_unique(array_map('trim',$n[1]));
        $numbers=array_values(array_filter($numbers,static fn($v)=>preg_match('/\d/',$v)));
        if (count($dates)!==1 || count($numbers)!==1) return null;
        return ['issued_on'=>array_key_first($dates),'invoice_number'=>$numbers[0]];
    }
    /** Accept explicit supplier labels only; never substitute the customer or filename. */
    public static function issuerName(string $text): string
    {
        $parts=preg_split('/(?:^|\\n)\\s*(?:cliente|adquirente|customer|client|bill(?:ed)?\\s+to|destinat[aá]rio)\\b/iu', $text,2);
        $head=$parts[0];
        preg_match_all('/(?:^|\\n)\\s*(?:fornecedor|emitente|supplier|issuer|vendedor|seller)\\s*[:\\-]\\s*([^\\r\\n]{1,160})/iu',$head,$matches);
        $names=array_values(array_unique(array_map('trim',$matches[1])));
        if (!$names && count($parts)===2) {
            preg_match_all('/(?:^|\\n)\\s*([^\\r\\n]{2,145}\\b(?:Lda\\.?|S\\.?A\\.?|Ltd\\.?|Limited))\\s*(?:\\n|$)/iu',$head,$legal);
            $names=array_values(array_unique(array_map('trim',$legal[1])));
        }
        return count($names)===1 && !preg_match('/[\\x00-\\x1f]/',$names[0]) ? $names[0] : '';
    }
    /** Accept the explicit display format without guessing month/day or changing stored ISO dates. */
    public static function reviewDate(string $date): string
    {
        if (preg_match('/\A(\d{2})\/(\d{2})\/(\d{4})\z/',$date,$m)) return $m[3].'-'.$m[2].'-'.$m[1];
        return $date;
    }
    public function review(string $id,string $date,string $number,bool $confirmed,int $actor,string $issuerName='',?string $issuerNif=null,bool $buyerConfirmed=false): void
    {
        $s=$this->status(); $d=$s['documents'][$id]??[];
        $date=self::reviewDate($date);$issuerNif=trim($issuerNif??(string)($d['issuer_nif']??''));
        if (self::categoryReview($d) || ($d['stage']??'')!=='review' || empty($d['sha256']) || !empty($d['children']) || !in_array($d['format']??'',['pdf','jpg'],true)
            || !self::validDate($date) || !$confirmed || trim($number)==='' || mb_strlen($number)>128
            || preg_match('/[\x00-\x1f]/',$number)) throw new RuntimeException('receipt_review_required');
        if (mb_strlen($issuerName)>160 || preg_match('/[\x00-\x1f]/',$issuerName)) throw new RuntimeException('invalid_request');
        $missing=InvoiceReceiptAnalysis::missingPages($d);
        if (($d['review_reason']??'')==='receipt_grouping_required') throw new RuntimeException('receipt_grouping_required');
        if (!empty($d['grouping_pending'])) throw new RuntimeException('receipt_grouping_required');
        if (($d['data_source']??'')==='qr' && (($d['issued_on']??'')!==$date || ($d['invoice_number']??'')!==trim($number) || ($d['issuer_nif']??'')!==$issuerNif)) throw new RuntimeException('receipt_qr_locked');
        $settings=$this->toc->settings(); InvoiceToconline::destination($settings['nif']);
        if (!$this->toc->receiptUnsent($id) || $actor<1) throw new RuntimeException('receipt_review_required');
        $d['issuer_name']=trim($issuerName);$d['issuer_nif']=$issuerNif;
        $d['issued_on']=$date; $d['invoice_number']=trim($number);
        // Missing-page recovery may save known details, but never queues a known incomplete invoice.
        if (!$missing && InvoiceReceiptAnalysis::reviewFields($d)) throw new RuntimeException('receipt_review_required');
        if ($buyerConfirmed && !InvoiceReceiptAnalysis::buyerAccepted($d,$settings['nif'])) {
            $d['company_confirmation']=['nif'=>$settings['nif'],'document_id'=>$id,'sha256'=>$d['sha256'],
                'extracted_nif'=>$d['company_nif']??'','actor'=>$actor,'at'=>gmdate('c')];
        }
        if (!$missing && !InvoiceReceiptAnalysis::buyerAccepted($d,$settings['nif'])) throw new RuntimeException('receipt_review_required');
        if (isset($d['fiscal_status']) && $d['fiscal_status']!=='N') throw new RuntimeException('receipt_fiscal_review');
        if (!empty($d['ocr_id']) && empty($d['ocr_cleaned'])) {
            $this->drive->connect(); $this->drive->trashReceiptOcr($d['ocr_id'],$id); $d['ocr_cleaned']=true;
        }
        $d['reviewed_by']=$actor; $d['reviewed_at']=gmdate('c'); $d['complete']=!$missing; $d['uncertain']=false;
        $d['error']=InvoiceReceiptAnalysis::reviewReason($d,$settings['nif']);$d['review_reason']=$d['error'];
        $d['stage']=$d['error']===''?'ready':'review';
        if ($d['stage']==='ready') self::beginCycle($s,$d['process_id']??'legacy');
        $s['documents'][$id]=InvoiceReceiptRetry::reset($d);$this->save($s);
    }
    public function confirmBuyer(string $id,bool $confirmed,int $actor): void
    {
        $s=$this->status();$d=$s['documents'][$id]??[];$nif=$this->toc->settings()['nif'];
        InvoiceToconline::destination($nif);
        if (self::categoryReview($d) || !$confirmed || $actor<1 || ($d['stage']??'')!=='review' || empty($d['sha256'])
            || !in_array($d['format']??'',['pdf','jpg'],true) || !empty($d['children'])
            || !$this->toc->receiptUnsent($id) || InvoiceReceiptAnalysis::buyerAccepted($d,$nif)) throw new RuntimeException('receipt_review_required');
        $d['company_confirmation']=['nif'=>$nif,'document_id'=>$id,'sha256'=>$d['sha256'],
            'extracted_nif'=>$d['company_nif']??'','actor'=>$actor,'at'=>gmdate('c')];
        $d['error']=InvoiceReceiptAnalysis::reviewReason($d,$nif);$d['review_reason']=$d['error'];
        if ($d['error']!=='') throw new RuntimeException($d['error']);
        $d['stage']=$d['error']===''?'ready':'review';
        if ($d['stage']==='ready') self::beginCycle($s,$d['process_id']??'legacy');
        $s['documents'][$id]=InvoiceReceiptRetry::reset($d);$this->save($s);
    }
    /** Persist the invoice-only discard intent. The existing worker handles Drive retries. */
    public function requestDiscard(string $id,bool $confirmed,int $actor): void
    {
        $s=$this->status();$d=$s['documents'][$id]??[];
        if (!$confirmed || $actor<1 || !InvoiceReceiptAnalysis::discardable($d) || !$this->toc->receiptUnsent($id)
            || InvoiceReceiptAnalysis::buyerAccepted($d,$this->toc->settings()['nif'])) throw new RuntimeException('receipt_review_required');
        $d['discard_requested_by']=$actor;$d['discard_requested_at']=gmdate('c');
        $d['stage']='discarding';$d['error']='';
        self::beginCycle($s,$d['process_id']??'legacy');$s['documents'][$id]=InvoiceReceiptRetry::reset($d);$this->save($s);
    }
    /** A replacement is new input; no old fiscal field or approval is a prerequisite. */
    public static function replacementReview(array $d): bool
    {
        return ($d['stage']??'')==='review' && empty($d['children'])
            && (self::categoryReview($d) || (!empty($d['sha256']) && !empty($d['source_month'])
                && in_array($d['format']??'',['pdf','jpg'],true)));
    }
    /** Keep every contributing source attached to the replacement's eventual invoice leaves. */
    private static function redirectChildren(array &$s,string $old,array $replacement): void
    {
        foreach ($s['documents'] as &$source) {
            if (!in_array($old,$source['children']??[],true)) continue;
            $children=[];
            foreach ($source['children'] as $child) array_push($children,...($child===$old?$replacement:[$child]));
            $source['children']=array_values(array_unique($children));
        }
        unset($source);
    }
    private function correctionSnapshot(string $id): array
    {
        $s=$this->status();$d=$s['documents'][$id]??[];
        if (($d['stage']??'')!=='review' || !(self::replacementReview($d) || InvoiceReceiptAnalysis::missingPages($d))) throw new RuntimeException('receipt_review_required');
        $this->requireIdle($s);$this->drive->connect();$folders=[];
        $files=$this->drive->scanFiles($d['source_month'],$folders);
        return [$s,$d,$files,$folders];
    }
    public function correctionCandidates(string $id, ?array &$folders=null): array
    {
        [$s,$d,$files,$folders]=$this->correctionSnapshot($id);$out=[];
        $root='temp_scan/'.InvoiceDriveLayout::MONTHS[(int)substr($d['expected_period'],5,2)];
        array_unshift($folders,['id'=>$d['source_month'],'parent'=>'','name'=>$root,'path'=>'/']);
        foreach ($files as $f) {
            $reason='';
            if (isset($s['documents'][$f['id']]) && !(self::categoryReview($d) && $f['id']===$id)) {
                $reason=$f['id']===$id?'receipt_correction_current'
                    :($f['id']===($d['source_id']??'')?'receipt_correction_original':'receipt_correction_recorded');
            } elseif (!InvoiceReceiptSelection::eligible($f)) $reason='receipt_format_required';
            elseif (self::categoryReview($d) && InvoiceDriveClient::receiptFormat($f)!=='pdf') $reason='receipt_correction_pdf';
            $out[]=['token'=>$reason===''?InvoiceReceiptSelection::token($f):'','name'=>$f['name'],'reason'=>$reason,
                'folder'=>$f['parents'][0]??$d['source_month'],
                'preview'=>InvoiceReceiptSelection::eligible($f)?InvoiceReceiptSelection::token($f):'',
                'path'=>rtrim($root.($f['source_path']??'/'),'/')];
        }
        return $out;
    }
    /** Preview only a fresh, bounded correction candidate; no caller-supplied Drive path. */
    public function correctionPreview(string $id,string $selection): array
    {
        if ($selection==='' || strlen($selection)>1024) throw new RuntimeException('receipt_selection_changed');
        [,,$files]=$this->correctionSnapshot($id);
        foreach ($files as $f) {
            if (!InvoiceReceiptSelection::eligible($f) || !hash_equals(InvoiceReceiptSelection::token($f),$selection)) continue;
            $f['source_parent']=$f['parents'][0];$f['format']=InvoiceDriveClient::receiptFormat($f);
            $bytes=$this->drive->scanDocument($f);
            return ['bytes'=>$bytes,'type'=>$f['format']==='pdf'?'application/pdf':'image/jpeg'];
        }
        throw new RuntimeException('receipt_selection_changed');
    }
    private function requireIdle(array $s): void
    {
        $settings=$this->toc->settings();
        if (!InvoiceReceiptReport::canStart($s,!empty($settings['enabled']) && !empty($settings['pilot_verified']))) throw new RuntimeException('worker_busy');
    }
    public function requestReplacement(string $id,array $selected,bool $confirmed,int $actor): void
    {
        $s=$this->status();$d=$s['documents'][$id]??[];
        if (!$confirmed || $actor<1 || ($d['stage']??'')!=='review' || !self::replacementReview($d) || !$this->toc->receiptUnsent($id)) throw new RuntimeException('receipt_review_required');
        $this->requireIdle($s);$this->drive->connect();
        if (count($selected)!==1) throw new RuntimeException('receipt_select_files');
        $allowSame=self::categoryReview($d);
        $files=InvoiceReceiptSelection::choose($this->drive->scanFiles($d['source_month']),$selected,$s['documents'],$allowSame?$id:null);
        if (count($s['documents'])>=2000 && $files[0]['id']!==$id) throw new RuntimeException('receipt_limit');
        if ($allowSame && InvoiceDriveClient::receiptFormat($files[0])!=='pdf') throw new RuntimeException('receipt_format_required');
        $d['replacement_allow_same_id']=$allowSame;
        $d['replacement_selection']=InvoiceReceiptSelection::token($files[0]);$d['correction_requested_by']=$actor;
        $d['stage']='correcting';$d['error']='';$d=InvoiceReceiptRetry::reset($d);
        self::beginCycle($s,$d['process_id']??'legacy');$s['documents'][$id]=$d;$this->save($s);
    }
    private function correctSource(string $id,array $d): void
    {
        $s=$this->status();
        if (!$this->toc->receiptUnsent($id)) throw new RuntimeException('receipt_legacy_submitted');
        $allowSame=$d['replacement_allow_same_id']??self::categoryReview($d);
        $f=InvoiceReceiptSelection::choose($this->drive->scanFiles($d['source_month']),[$d['replacement_selection']],$s['documents'],$allowSame?$id:null)[0];
        if ($allowSame && InvoiceDriveClient::receiptFormat($f)!=='pdf') throw new RuntimeException('receipt_format_required');
        if (count($s['documents'])>=2000 && $f['id']!==$id) throw new RuntimeException('receipt_limit');
        $f['source_parent']=$f['parents'][0];$f['format']=InvoiceDriveClient::receiptFormat($f);
        $bytes=$this->drive->scanDocument($f);$sha=hash('sha256',$bytes);
        if (!in_array($f['source_category']??'a4',['a4','taloes'],true)) throw new RuntimeException('receipt_selection_changed');
        // Commit ownership before analysis; retry never allocates a second replacement.
        $new=array_merge($f,array_intersect_key($d,array_flip(['root','source','source_month','expected_period','process_id','requested_by','legacy_children'])),
            ['sha256'=>$sha,'category_check_required'=>$f['format']==='pdf','stage'=>'queued','error'=>'','toconline_state'=>'pending','attempts'=>0,'created_at'=>gmdate('c')]);
        $history=$d['correction_history']??[];
        $history[]=['id'=>$id,'sha256'=>$d['sha256'],'source_parent'=>$d['source_parent'],'source_category'=>$d['source_category']??'a4','at'=>gmdate('c'),'actor'=>$d['correction_requested_by']];
        $new['correction_history']=$history;
        if (($d['category_check']['sha256']??'')===$sha && ($d['category_check']['category']??'uncertain')!=='uncertain') $new['category_check']=$d['category_check'];
        $newId=$f['id'];
        if ($newId!==$id) {
            $d['stage']='superseded';$d['replacement_id']=$newId;$d['error']='';$s['documents'][$id]=$d;$new['replaces']=$id;
            if (!empty($d['source_id'])) $new['legacy_children']=array_values(array_unique(array_merge($new['legacy_children']??[],[$id])));
            self::redirectChildren($s,$id,[$newId]);
        }
        $s['documents'][$newId]=$new;$this->save($s);
    }
    public function requestRepair(string $id,bool $confirmed,int $actor,array $selected=[]): void
    {
        $s=$this->status();$d=$s['documents'][$id]??[];
        if (!$confirmed || ($d['stage']??'')!=='review' || ($d['format']??'')!=='pdf' || empty($d['source_id'])
            || empty($d['sha256']) || !InvoiceReceiptAnalysis::missingPages($d) || !isset($s['documents'][$d['source_id']]) || !$this->toc->receiptUnsent($id)) throw new RuntimeException('receipt_review_required');
        $settings=$this->toc->settings();
        if (!InvoiceReceiptReport::canStart($s,!empty($settings['enabled']) && !empty($settings['pilot_verified']))) throw new RuntimeException('worker_busy');
        $this->drive->connect();
        $files=InvoiceReceiptSelection::choose($this->drive->scanFiles($d['source_month']),$selected,$s['documents']);
        $d['repair_files']=array_map([InvoiceReceiptSelection::class,'token'],$files);
        $d['repair_original_error']=$d['error']??'';$d['error']='';$d['stage']='repairing';
        $d['repair_requested_by']=$actor;$d['repair_requested_at']=gmdate('c');$d['attempts']=0;$d['next_attempt']=0;
        self::beginCycle($s,$d['process_id']??'legacy');$s['documents'][$id]=InvoiceReceiptRetry::reset($d);$this->save($s);
    }
    public function retry(string $id): void
    {
        $s=$this->status(); $d=$s['documents'][$id]??[];
        if (($d['stage']??'')!=='failed') throw new RuntimeException('invalid_request');
        if (($d['error']??'')==='gemini_quota_probe_failed') {
            InvoiceGemini::resetDailyQuota($this->vault);
            unset($d['quota_outcome'],$d['quota_reason'],$d['quota_retry_at']);
        }
        $d['stage']=$d['resume_stage']; $d['error']='';
        self::beginCycle($s,$d['process_id']??'legacy');$s['documents'][$id]=InvoiceReceiptRetry::reset($d);$this->save($s);
    }
    private function discover(): void
    {
        $s=$this->status(); if (!$s['requested']) return;
        $settings=$this->pdo->query('SELECT * FROM invoice_drive_settings WHERE id=1')->fetch(PDO::FETCH_ASSOC);
        if (($settings['state']??'')!=='ready') throw new RuntimeException('drive_not_configured');
        $root=(string)$settings['folder_id']; $meta=$this->drive->metadata($root);
        if (!$meta || ($meta['trashed']??true) || ($meta['mimeType']??'')!=='application/vnd.google-apps.folder'
            || !in_array('daniel.ciorcas@welcomehostel.pt',array_column($meta['owners']??[],'emailAddress'),true)) throw new RuntimeException('drive_account_mismatch');
        $matches=$this->drive->namedItems($root,['temp_scan'],true);
        if (count($matches)!==1) throw new RuntimeException('receipt_source');
        $source=$matches[0]['id']; $files=[]; $s['missing_periods']=[];
        if (empty($s['year']) || empty($s['months'])) throw new RuntimeException('receipt_select_periods');
        foreach ($s['months'] as $month) {
            $period=sprintf('%04d-%02d',$s['year'],$month);
            $label=InvoiceDriveLayout::MONTHS[$month];
            $names=array_values(array_unique([$label,mb_strtolower($label),mb_strtoupper($label),str_replace('ç','c',mb_strtolower($label))]));
            $folders=$this->drive->namedItems($source,$names,true);
            if (count($folders)>1) throw new RuntimeException('drive_conflict');
            if (!$folders) { $s['missing_periods'][]=$period; continue; }
            foreach ($this->drive->scanFiles($folders[0]['id']) as $f) {
                $files[]=$f+['expected_period'=>$period,'source_month'=>$folders[0]['id']];
                if (count($files)>500) throw new RuntimeException('receipt_limit');
            }
        }
        foreach ($files as $f) {
            $id=$f['id']; InvoiceDriveClient::assertId($id);
            // A new intake never retries, reassigns or reinterprets a recorded scan.
            // Pending originals remain in their prior run for explicit manual action.
            if (isset($s['documents'][$id])) continue;
            if (count($s['documents'])>=2000) throw new RuntimeException('receipt_limit');
            $format=InvoiceDriveClient::receiptFormat($f);
            $valid=$format!==null && (int)($f['size']??0)>=5 && (int)$f['size']<=10*1024*1024 && !empty($f['md5Checksum']);
            $s['documents'][$id]=$f+['format'=>$format,'source_parent'=>$f['parents'][0]??'', 'root'=>$root,'source'=>$source,
                'category_check_required'=>$format==='pdf' && $this->analysis!==null,'source_category'=>$f['source_category']??'a4',
                'stage'=>$valid?'queued':'review','error'=>$valid?'':'receipt_format_required','toconline_state'=>'pending',
                'process_id'=>$s['active_process'],'requested_by'=>$s['requested_by'],'created_at'=>gmdate('c'),'attempts'=>0];
        }
        $s['processes'][$s['active_process']]['missing_periods']=$s['missing_periods'];
        $s['processes'][$s['active_process']]['error']='';
        $s['requested']=false;unset($s['discovery_retry']); $s['source']=$source; $s['root']=$root; $s['scanned_at']=gmdate('c'); $s['error']=''; $this->save($s);
    }
    /** Shared replacement pages must be regrouped from their new source, never old bytes. */
    public static function hasReplacementChildren(string $id,array $d,array $documents): bool
    {
        foreach ($d['children']??[] as $child) {
            $entry=$documents[$child]??[];$source=(string)($entry['source_id']??'');
            if (($source==='' && !empty($entry['replaces']))
                || ($source!==$id && !empty($documents[$source]['replaces']))) return true;
        }
        return false;
    }
    /** Manager supplies complete page groups; fiscal QR fields remain authoritative. */
    public function regroup(string $id,string $text,bool $confirmed,int $actor): void
    {
        $s=$this->status();$d=$s['documents'][$id]??null;
        if (!$confirmed || !is_array($d) || !empty($d['source_id']) || ($d['format']??'')!=='pdf'
            || in_array($d['stage'],['split_completed','source_trashing','source_moving','source_processed'],true) || strlen($text)>400) throw new RuntimeException('receipt_grouping_required');
        if (self::hasReplacementChildren($id,$d,$s['documents'])) throw new RuntimeException('receipt_grouping_required');
        $groups=[];$seen=[];
        foreach (preg_split('/[;\r\n]+/',trim($text)) as $line) {
            if (!preg_match('/\A\s*\d+(?:\s*,\s*\d+)*\s*\z/',$line)) throw new RuntimeException('receipt_grouping_required');
            $pages=array_map('intval',explode(',',$line));$last=0;
            foreach ($pages as $p) { if ($p<1 || $p>50 || $p<=$last || isset($seen[$p])) throw new RuntimeException('receipt_grouping_required'); $seen[$p]=true;$last=$p; }
            $groups[]=$pages;
        }
        // Actual page count is checked again by the local parser on the original bytes.
        foreach ($d['children']??[$id] as $child) if (in_array($s['documents'][$child]['stage']??'',['discarding','discarded'],true) || !$this->toc->receiptUnsent($child)) throw new RuntimeException('receipt_legacy_submitted');
        $d['legacy_children']=array_values(array_unique(array_merge($d['legacy_children']??[],$d['children']??[])));
        foreach ($d['children']??[] as $child) {
            $s['documents'][$child]['stage']='superseded';$s['documents'][$child]['error']='';
            self::redirectChildren($s,$child,[$id]);
        }
        unset($d['children'],$d['analysis_version'],$d['manifest'],$d['grouping_pending']);
        $d['manual_groups']=$groups;$d['grouped_by']=$actor;$d['stage']='queued';$d['error']='';$d['attempts']=0;$d['next_attempt']=0;
        $s['documents'][$id]=InvoiceReceiptRetry::reset($d);self::beginCycle($s,$d['process_id']??'legacy');$this->save($s);
    }
    private static function beginCycle(array &$s,string $pid): void
    {
        $s['processes'][$pid]['notification_cycle']=(int)($s['processes'][$pid]['notification_cycle']??0)+1;
        $s['worker_seen_at']=time();
    }
    private static function failedAttempt(array $d,Throwable $e,int $now): array
    {
        $d=array_replace($d,InvoiceReceiptRetry::failure($d,$e,$now));
        if ($e->getMessage()==='gemini_daily_quota') { $d['quota_outcome']='waiting';$d['quota_retry_at']=$d['next_attempt']; }
        if ($e->getMessage()==='gemini_quota_probe_failed') $d['quota_outcome']='failed';
        if ($d['blocked']) { $d['resume_stage']=$d['stage'];$d['stage']='failed'; }
        return $d;
    }
    private static function runnable(array $d,array $documents,bool $sendEnabled,bool $cleanupPaused): bool
    {
        if (in_array($d['stage']??'',['correcting','repairing','queued','reading','ready','moving','preserving','trashing','discarding'],true)) return true;
        if (in_array($d['stage']??'',['source_trashing','source_moving'],true)) return !$cleanupPaused;
        if (($d['stage']??'')==='archived') return in_array($d['toconline_state']??'',['toc_existing','toc_duplicate_local'],true)
            || ($sendEnabled && empty($d['duplicate_keeper']) && in_array($d['toconline_state']??'',['pending','toc_retry'],true));
        if (($d['stage']??'')!=='split' || $cleanupPaused || empty($d['children'])) return false;
        foreach ($d['children'] as $child) if (!InvoiceReceiptReport::resolved($documents[$child]??[])) return false;
        return true;
    }
    /** Reconcile only unsent old page guesses. Existing sent history is immutable. */
    private function recoverLegacy(): void
    {
        $s=$this->status(); if (($s['analysis_version']??0)>=InvoiceReceiptAnalysis::VERSION) return;
        foreach ($s['documents'] as $id=>$d) {
            if (($d['process_id']??'legacy')!==($s['active_process']??'legacy')) continue;
            if (!empty($d['source_id']) || !empty($d['analysis_version']) || in_array($d['stage'],['split_completed','duplicate_trashed','discarding','discarded'],true)) continue;
            $ids=$d['children']??[$id]; $safe=true;
            foreach ($ids as $child) if (!$this->toc->receiptUnsent($child) || !in_array($s['documents'][$child]['toconline_state']??'pending',['pending','toc_retry','receipt_per_page'],true)) $safe=false;
            if (!$safe) {
                foreach ($ids as $child) if ($this->toc->receiptUnsent($child) && ($s['documents'][$child]['toconline_state']??'')==='pending') {
                    $s['documents'][$child]['stage']='review';$s['documents'][$child]['error']='receipt_legacy_submitted';$s['documents'][$child]['grouping_pending']=true;
                }
                continue;
            }
            if (!empty($d['children'])) {
                $d['legacy_children']=$d['children'];
                foreach ($d['children'] as $child) { $s['documents'][$child]['stage']='superseded'; $s['documents'][$child]['error']=''; }
                unset($d['children']);
            }
            if (!empty($d['drive_parent'])) $d['original_parent']=$d['drive_parent'];
            $d['stage']='queued';$d['error']='';$d['attempts']=0;$d['next_attempt']=0;
            $d['legacy_recovery']=true;$s['documents'][$id]=$d;
        }
        $s['analysis_version']=InvoiceReceiptAnalysis::VERSION;$s['error']='';$this->save($s);
    }
    private function analyseSource(string $id,array $d): void
    {
        $bytes=!empty($d['original_parent'])?$this->drive->verifiedReceipt($d):$this->drive->scanDocument($d);
        $d['sha256']=hash('sha256',$bytes);$d['stage']='reading';$this->put($id,$d);
        if (!empty($d['category_check_required'])) {
            if (($d['category_check']['sha256']??'')!==$d['sha256']) {
                $result=$this->analysis->classifySource($bytes);
                $d['category_check']=['sha256'=>$d['sha256'],'category'=>$result['category']??'uncertain'];
                $this->put($id,$d);
            }
            $category=$d['category_check']['category'];
            if (!in_array($category,['a4','taloes'],true) || $category!==($d['source_category']??'a4')) {
                InvoiceVault::atomicWrite($this->vault->path('receipt-source-'.$d['sha256'].'.pdf'),$bytes);
                $d['stage']='review';$d['error']=in_array($category,['a4','taloes'],true)?'receipt_category_mismatch':'receipt_category_uncertain';
                $this->put($id,$d);return;
            }
        }
        $nif=$this->toc->settings()['nif'];InvoiceToconline::destination($nif);
        $manifest=$this->analysis->analyse($bytes,$d['format'],$nif,$d['manual_groups']??null);
        $s=$this->status();
        if (count($s['documents'])+count($manifest['groups'])>2000) throw new RuntimeException('receipt_limit');
        $children=[];
        foreach ($manifest['groups'] as $i=>$group) {
            if ($d['format']==='pdf') $file=$this->analysis->extract($bytes,$group['pages']);
            else {
                $file=['size'=>strlen($bytes),'md5Checksum'=>md5($bytes),'sha256'=>hash('sha256',$bytes)];
                InvoiceVault::atomicWrite($this->vault->path('receipt-page-'.$file['sha256'].'.jpg'),$bytes);
            }
            $child=$this->drive->newId();InvoiceDriveClient::assertId($child);$children[]=$child;
            $review=$group['review_reason']??'receipt_review_required';
            $s['documents'][$child]=array_merge($group,$file,[
                'id'=>$child,'source_id'=>$id,'process_id'=>$d['process_id']??'legacy','analysis_version'=>InvoiceReceiptAnalysis::VERSION,
                'page_number'=>$group['pages'][0],'page_count'=>$manifest['page_count'],'pdf_pages_checked'=>true,
                'name'=>pathinfo($d['name'],PATHINFO_FILENAME).'_f'.sprintf('%03d',$i+1).'.'.$d['format'],
                'mimeType'=>$d['mimeType'],'format'=>$d['format'],'root'=>$d['root'],'source'=>$d['source'],
                'source_parent'=>$d['source_parent'],'source_month'=>$d['source_month'],'expected_period'=>$d['expected_period'],
                'source_category'=>$d['source_category']??'a4','source_path'=>$d['source_path']??'/',
                'stage'=>$review===''?'ready':'preserving','review_reason'=>$review,'error'=>'','toconline_state'=>'pending',
                'requested_by'=>$d['requested_by'],'created_at'=>gmdate('c'),'attempts'=>0]);
        }
        if (!empty($d['ocr_id']) && empty($d['ocr_cleaned'])) $this->drive->trashReceiptOcr($d['ocr_id'],$id);
        $d['children']=$children;$d['analysis_version']=InvoiceReceiptAnalysis::VERSION;$d['manifest']=$manifest;
        $d['page_count']=$manifest['page_count'];$d['stage']='split';$d['toconline_state']='receipt_per_page';$d['error']='';
        self::redirectChildren($s,$id,$children);
        $s['documents'][$id]=$d;$this->save($s); // Manifest and stable child identities commit together before any upload/send.
    }
    public function recordFailure(Throwable $e): void
    {
        $s=$this->status();$now=time();$s['error']=self::error($e);
        if ($s['requested']) {
            $s['discovery_retry']=InvoiceReceiptRetry::failure($s['discovery_retry']??[],$e,$now);
            $s['requested']=!$s['discovery_retry']['blocked'];
            $s['processes'][$s['active_process']]['error']=$s['error'];
        }
        foreach ($s['documents'] as &$doc) if (self::runnable($doc,$s['documents'],true,!empty($s['source_cleanup_paused']))) $doc=self::failedAttempt($doc,$e,$now);
        unset($doc);$this->save($s);
    }
    /** Bounded work on the existing cron, including short persisted retry windows. */
    public function runBatch(): void
    {
        $deadline=microtime(true)+45;$turns=0;
        do {
            $before=$this->status();$this->run();$after=$this->status();$turns++;
            if (($before['documents']??[])===($after['documents']??[]) && ($before['requested']??false)===($after['requested']??false)) {
                $next=0;foreach ($after['documents']??[] as $d) if (in_array($d['stage'],['correcting','repairing','queued','reading','ready','moving','preserving'],true) && ($d['next_attempt']??0)>time()) $next=$next?min($next,$d['next_attempt']):$d['next_attempt'];
                if (!$next || $next>$deadline-1) break;
                while (time()<$next && microtime(true)<$deadline) usleep(250000);
            }
        } while ($turns<8 && microtime(true)<$deadline);
    }
    public function run(?int $now=null): void
    {
        $now??=time();
        if (!$this->vault->has(self::FILE)) return; // Deployment never initiates intake.
        if ($this->analysis) {
            if ($this->analysis->configured()) $this->recoverLegacy();
        }
        $this->consolidateDuplicates();
        $s=$this->status(); $s['worker_seen_at']=$now; $this->save($s);
        $before=$s['documents'];
        // Upgrade only unsent originals; archived/sent historical PDFs are never split again.
        foreach ($s['documents'] as &$d) {
            // Recheck older cleanup denials once with Drive's explicit canTrash capability.
            if (($d['error']??'')==='drive_permission' && empty($d['trash_capability_recheck']) && in_array($d['stage'],['source_trashing','trashing'],true)) {
                $d['next_attempt']=0;$d['trash_capability_recheck']=true;
            }
            if (!$this->analysis && ($d['process_id']??'legacy')===($s['active_process']??'legacy') && $d['stage']==='review' && $d['toconline_state']==='pending' && empty($d['drive_parent'])) {
                if (($d['format']??'')==='pdf' && empty($d['pdf_pages_checked'])) {
                    $d['stage']='queued';
                    if (!empty($d['ocr_cleaned'])) unset($d['ocr_id'],$d['ocr_creating'],$d['ocr_cleaned']);
                } elseif (($d['error']??'')==='receipt_period_mismatch' && self::validDate($d['issued_on']??'')) {
                    $d['stage']='ready'; $d['error']='';
                }
            }
        }
        unset($d); if ($before!==$s['documents']) $this->save($s);
        $sendEnabled=!empty($this->toc->settings()['enabled']) && !empty($this->toc->settings()['pilot_verified']);
        // A delayed cron must not execute retries beyond their persisted deadline.
        $expired=false;
        if ($s['requested'] && InvoiceReceiptRetry::expired($s['discovery_retry']??[],$now)) {
            $s['requested']=false;$s['discovery_retry']['blocked']=true;$s['discovery_retry']['next_attempt']=0;$expired=true;
        }
        foreach ($s['documents'] as &$doc) if (self::runnable($doc,$s['documents'],$sendEnabled,!empty($s['source_cleanup_paused'])) && InvoiceReceiptRetry::expired($doc,$now)) {
            $doc['resume_stage']=$doc['stage'];$doc['stage']='failed';$doc['blocked']=true;$doc['next_attempt']=0;$expired=true;
        }
        unset($doc);if ($expired) $this->save($s);
        $work=($s['requested'] && ($s['discovery_retry']['next_attempt']??0)<=$now) || array_filter($s['documents'],
            static fn($d)=>($d['next_attempt']??0)<=$now && self::runnable($d,$s['documents'],$sendEnabled,!empty($s['source_cleanup_paused'])));
        if (!$work) return;
        try {
            if ($this->analysis && !$this->analysis->configured()) throw new RuntimeException('gemini_not_configured');
            if (!$this->drive->receiptsAuthorized()) throw new RuntimeException('receipt_connect');
            $this->drive->connect();
            if (($s['discovery_retry']['next_attempt']??0)<=$now) $this->discover();
            $connectedState=$this->status();$connectedState['error']='';$this->save($connectedState);
        } catch (Throwable $e) {
            $s=$this->status();$s['error']=self::error($e);
            if ($s['requested'] && ($s['discovery_retry']['next_attempt']??0)<=$now) {
                $s['discovery_retry']=InvoiceReceiptRetry::failure($s['discovery_retry']??[],$e,$now);
                $s['requested']=!$s['discovery_retry']['blocked'];
                if (isset($s['active_process'])) $s['processes'][$s['active_process']]['error']=$s['error'];
            }
            foreach ($s['documents'] as &$doc) {
                if (($doc['next_attempt']??0)>$now || !self::runnable($doc,$s['documents'],$sendEnabled,!empty($s['source_cleanup_paused']))) continue;
                $doc=self::failedAttempt($doc,$e,$now);
            }
            unset($doc);$this->save($s);return;
        }
        // One invoice or source cleanup per invocation bounds work and preserves the portal queue.
        $documents=$this->status()['documents'];
        uasort($documents,static fn($a,$b)=>(int)($a['stage']==='archived')<=>(int)($b['stage']==='archived'));
        foreach ($documents as $id=>$d) {
            if (!empty($s['source_cleanup_paused']) && in_array($d['stage'],['split','source_trashing','source_moving'],true)) continue;
            if (!in_array($d['stage'],['correcting','repairing','queued','reading','ready','moving','preserving','archived','trashing','discarding','split','source_trashing','source_moving'],true) || ($d['next_attempt']??0)>$now) continue;
            if ($d['stage']==='split' && !$this->childrenArchived($d,$documents)) continue;
            if ($d['stage']==='archived' && in_array($d['toconline_state'],['pending','toc_retry'],true)
                && (empty($this->toc->settings()['enabled']) || empty($this->toc->settings()['pilot_verified']))) continue;
            if ($d['stage']==='archived' && !empty($d['duplicate_keeper']) && in_array($d['toconline_state'],['pending','toc_retry'],true)) continue;
            if ($d['stage']==='archived' && !in_array($d['toconline_state'],['pending','toc_retry','toc_existing','toc_duplicate_local'],true)) continue;
            try {
                $this->process($id,$d);
                $after=$this->status()['documents'][$id];
                if ($after['stage']!==$d['stage'] || empty($after['error'])) {
                    $after['recovered_failures']=(int)($after['recovered_failures']??0)+(int)($d['attempts']??0);
                    if (($d['quota_outcome']??'')==='waiting' && $this->vault->has(InvoiceGemini::BUDGET)) {
                        $q=$this->vault->read(InvoiceGemini::BUDGET)['daily_quota']??[];
                        if (($q['state']??'')==='recovered') $after['quota_outcome']='recovered';
                    }
                    $this->put($id,InvoiceReceiptRetry::reset($after));
                }
            }
            catch (Throwable $e) {
                $d=$this->status()['documents'][$id];
                if ($e instanceof InvoiceTocWait) {
                    $d['error']='';$d['next_attempt']=$e->retryAt;$d['retry_kind']='pacing';$this->put($id,$d);break;
                }
                if (in_array($e->getMessage(),['receipt_grouping_required','receipt_company_changed','receipt_buyer_missing','receipt_buyer_consumer','receipt_missing_pages','receipt_repair_not_found','receipt_repair_ambiguous','receipt_repair_limit','receipt_selection_changed','receipt_select_files','gemini_response'],true)) {
                    $d['stage']='review'; $d['error']=$e->getMessage(); $d['grouping_pending']=!empty($d['grouping_pending']) || (!self::categoryReview($d) && empty($d['source_id']) && !in_array($e->getMessage(),['receipt_company_changed','receipt_buyer_missing','receipt_buyer_consumer'],true)); $this->put($id,$d); break;
                }
                $d=self::failedAttempt($d,$e,$now);
                if (($d['quota_outcome']??'')==='failed' && $this->vault->has(InvoiceGemini::BUDGET)) $d['quota_reason']=$this->vault->read(InvoiceGemini::BUDGET)['daily_quota']['reason']??'gemini_quota_probe_failed';
                $this->put($id,$d);
            }
            break;
        }
    }
    /** Runs under the existing worker lock, before submission or removal. */
    private function consolidateDuplicates(): void
    {
        $s=$this->status();$sets=[];
        foreach ($s['documents'] as $id=>$d) {
            if (in_array($d['stage']??'',['review','failed','discarding','discarded'],true)) continue;
            $key=InvoiceReceiptAnalysis::duplicateKey($d);
            if ($key && !empty($d['source_id']) && ($d['stage']??'')!=='duplicate_trashed') $sets[$key][]=$id;
        }
        foreach ($sets as $ids) {
            if (count($ids)<2) continue;
            // Prefer an already confirmed retained copy, then readability, then source order.
            usort($ids,static function($a,$b)use($s){
                $x=$s['documents'][$a];$y=$s['documents'][$b];
                return (int)InvoiceReceiptReport::resolved($y)<=>(int)InvoiceReceiptReport::resolved($x)
                    ?: (($y['legibility']??0)<=>($x['legibility']??0))
                    ?: strcmp($x['created_at']??'',$y['created_at']??'')
                    ?: (($x['page_number']??0)<=>($y['page_number']??0));
            });
            $keeper=$ids[0];
            if (!empty($s['documents'][$keeper]['duplicate_keeper'])) {
                $kept=$s['documents'][$keeper];unset($kept['duplicate_keeper']);$this->put($keeper,$kept);
            }
            foreach (array_slice($ids,1) as $id) {
                $d=$s['documents'][$id];$d['duplicate_keeper']=$keeper;$this->put($id,$d);
            }
            // Preserve pending copies until a signed acceptance exists, even if the model says duplicate.
            $this->toc->confirmEquivalentReceipts($ids,$keeper);
        }
    }
    private function localDuplicateSafe(array $d,array $documents): bool
    {
        $keeper=$documents[$d['duplicate_keeper']??'']??[];
        $key=InvoiceReceiptAnalysis::duplicateKey($d);
        return ($d['toconline_state']??'')==='toc_duplicate_local' && $key!==null
            && InvoiceReceiptAnalysis::duplicateKey($keeper)===$key && $this->toc->receiptAccepted((string)($keeper['id']??''))
            && InvoiceReceiptReport::resolved($keeper);
    }
    private function pagePath(array $d): string
    {
        if (!preg_match('/\A[a-f0-9]{64}\z/',$d['sha256']??'')) throw new RuntimeException('drive_verify');
        return $this->vault->path('receipt-page-'.$d['sha256'].'.'.(($d['format']??'pdf')==='jpg'?'jpg':'pdf'));
    }
    private function sourceBytes(array $d): string
    {
        if (empty($d['source_id']) && self::categoryReview($d) && preg_match('/\A[a-f0-9]{64}\z/',$d['sha256']??'')) {
            $path=$this->vault->path('receipt-source-'.$d['sha256'].'.pdf');
            if (is_file($path) && hash_equals($d['sha256'],hash_file('sha256',$path))) return (string)file_get_contents($path);
        }
        if (empty($d['source_id'])) return $this->drive->scanDocument($d);
        $path=$this->pagePath($d);
        if (!is_file($path) && (!empty($d['preserved_at']) || !empty($d['archived_at']))) {
            return $this->drive->verifiedReceipt($d);
        }
        if (!is_file($path) && !empty($d['repair_parts'])) {
            if (!$this->analysis) throw new RuntimeException('receipt_split_unavailable');
            $documents=$this->status()['documents'];$parts=[];
            foreach ($d['repair_parts'] as $part) {
                $source=$documents[$part['id']]??null;
                if (!$source || $part['id']===$d['id']) throw new RuntimeException('drive_verify');
                $parts[]=['bytes'=>$this->sourceBytes($source),'format'=>$source['format']];
            }
            $this->analysis->assemble($parts,$d['repair_order']);
        }
        if (!is_file($path)) {
            $parent=$this->status()['documents'][$d['source_id']]??[];
            $bytes=$this->drive->scanDocument($parent);
            if (!hash_equals($parent['sha256'],hash('sha256',$bytes))) throw new RuntimeException('drive_verify');
            if (!empty($d['analysis_version'])) {
                if ($d['format']==='jpg') InvoiceVault::atomicWrite($path,$bytes);
                elseif ($this->analysis) $this->analysis->extract($bytes,$d['pages']);
                else (new InvoiceReceiptPdf($this->vault,(require dirname(__DIR__,2).'/config.php')['invoices']))->extract($bytes,$d['pages']);
            } else $this->splitPdf($bytes); // Rebuild pinned bytes; never allocate new IDs.
        }
        $bytes=is_file($path)?file_get_contents($path):false;
        if (!is_string($bytes) || !hash_equals($d['sha256'],hash('sha256',$bytes))) throw new RuntimeException('drive_verify');
        return $bytes;
    }
    private function splitPdf(string $bytes): array
    {
        if (!is_callable($this->splitter)) throw new RuntimeException('receipt_split_unavailable');
        return ($this->splitter)($bytes);
    }
    private function childrenArchived(array $d,array $documents): bool
    {
        if (empty($d['children'])) return false;
        foreach ($d['children'] as $id) {
            if (!InvoiceReceiptReport::resolved($documents[$id]??[])) return false;
        }
        return true;
    }
    private function clearPageCache(array $d): void
    {
        if (empty($d['source_id'])) return;
        foreach ($this->status()['documents'] as $other) {
            if (!in_array($other['stage']??'',['superseded','discarded'],true) && !empty($other['source_id']) && empty($other['archived_at']) && ($other['sha256']??'')===$d['sha256']) return;
        }
        $path=$this->pagePath($d); if (is_file($path)) unlink($path);
    }
    private function process(string $id,array $d): void
    {
        if ($d['stage']==='discarding') {
            if (!$this->toc->receiptUnsent($id) || empty($d['discard_requested_by']) || !empty($d['children'])) throw new RuntimeException('receipt_review_required');
            if (!empty($d['ocr_creating']) && empty($d['ocr_id']) && empty($d['ocr_cleaned'])) {
                $d['ocr_id']=$this->drive->findReceiptOcr($id,$d['source']);$this->put($id,$d);
            }
            if (!empty($d['ocr_id']) && empty($d['ocr_cleaned'])) {
                $this->drive->trashReceiptOcr($d['ocr_id'],$id);$d['ocr_cleaned']=true;$this->put($id,$d);
            }
            if (!empty($d['drive_parent']) || empty($d['source_id'])) {
                $trash=$d;$trash['drive_parent']??=$d['source_parent'];
                $this->drive->trashReceiptFile($trash);
            }
            $d['stage']='discarded';$d['discarded_at']=gmdate('c');$d['toconline_state']='receipt_not_submitted';
            $d['error']='';$d['review_reason']='';$this->put($id,$d);$this->clearPageCache($d);return;
        }
        if ($d['stage']==='correcting') { $this->correctSource($id,$d);return; }
        if ($d['stage']==='repairing') {
            if (!$this->analysis || !$this->toc->receiptUnsent($id)) throw new RuntimeException('receipt_review_required');
            $state=(new InvoiceReceiptRepair($this->vault,$this->drive,$this->analysis))->prepare(
                $this->status(),$id,$this->toc->settings()['nif'],fn($doc)=>$this->sourceBytes($doc));
            $this->save($state);return;
        }
        if (in_array($d['stage'],['split','source_trashing','source_moving'],true)) {
            $documents=$this->status()['documents'];
            if (!$this->childrenArchived($d,$documents)) throw new RuntimeException('drive_verify');
            foreach ($d['children'] as $childId) {
                $child=$documents[$childId];
                if (($child['stage']??'')==='discarded' && InvoiceReceiptReport::resolved($child)) continue;
                if ($child['stage']==='duplicate_trashed' && ($this->toc->receiptDuplicateConfirmed($childId) || $this->localDuplicateSafe($child,$documents))) continue;
                $this->drive->verifiedReceipt($child); // Fresh content verification before retiring the master.
            }
            foreach ($d['legacy_children']??[] as $oldId) {
                $old=$documents[$oldId];
                if (!$this->toc->receiptUnsent($oldId)) throw new RuntimeException('receipt_legacy_submitted');
                if ((!empty($old['archived_at']) || !empty($old['preserved_at'])) && empty($old['retired_at'])) {
                    if (empty($old['retiring'])) { $this->drive->verifiedReceipt($old);$old['retiring']=true;$this->put($oldId,$old); }
                    $this->drive->trashReceiptFile($old);
                    $old['retired_at']=gmdate('c');$this->put($oldId,$old);
                }
            }
            if (empty($d['processed_parent'])) {
                $d['processed_parent']=(new InvoiceDrive($this->pdo,$this->vault,$this->drive))->folder($d['root'],['temp_scan_processed']);
            }
            $d['stage']='source_moving'; $this->put($id,$d);
            $move=array_merge($d,['source_parent'=>$d['original_parent']??$d['source_parent'],'drive_parent'=>$d['processed_parent']]);
            $this->drive->moveReceipt($move);$this->drive->verifiedReceipt($move);
            $d['source_parent']=$d['processed_parent'];unset($d['original_parent']);
            $d['stage']='source_processed';$d['moved_at']=gmdate('c');$d['error']='';$this->put($id,$d);
            foreach (array_merge($d['children'],$d['legacy_children']??[]) as $childId) $this->clearPageCache($documents[$childId]);
            return;
        }
        if ($this->analysis && empty($d['source_id']) && empty($d['analysis_version']) && in_array($d['stage'],['queued','reading'],true)) {
            $this->analyseSource($id,$d); return;
        }
        if ($d['stage']==='preserving') {
            if (empty($d['drive_parent'])) {
                $d['drive_parent']=(new InvoiceDrive($this->pdo,$this->vault,$this->drive))->folder($d['source'],['pendentes']);
                $this->put($id,$d);
            }
            $this->sourceBytes($d); $path=$this->pagePath($d);
            $m=$this->drive->upload($id,$d['drive_parent'],$d['name'],$path,$d['format']);
            if (!InvoiceDrive::verify($m,$d+['drive_id'=>$id,'size_bytes'=>$d['size']],$path)) throw new RuntimeException('drive_verify');
            $this->drive->verifiedReceipt($d);$d['preserved_at']=gmdate('c');$d['pending_parent']=$d['drive_parent'];
            $d['stage']='review';$d['error']=$d['review_reason'];$this->put($id,$d);return;
        }
        if (($d['format']??'')==='pdf' && empty($d['pdf_pages_checked']) && empty($d['archived_at'])) {
            $bytes=$this->drive->scanDocument($d); $d['sha256']=hash('sha256',$bytes);
            $result=$this->splitPdf($bytes); $d['pdf_pages_checked']=true; $d['page_count']=$result['count'];
            if ($result['count']>1) {
                $s=$this->status(); if (count($s['documents'])+$result['count']>2000) throw new RuntimeException('receipt_limit');
                if (!empty($d['ocr_id']) && empty($d['ocr_cleaned'])) $this->drive->trashReceiptOcr($d['ocr_id'],$id);
                $d['children']=[];
                foreach ($result['pages'] as $page) {
                    $childId=$this->drive->newId(); InvoiceDriveClient::assertId($childId); $d['children'][]=$childId;
                    $s['documents'][$childId]=[
                        'id'=>$childId,'source_id'=>$id,'process_id'=>$d['process_id']??'legacy','page_number'=>$page['number'],'page_count'=>$result['count'],
                        'pdf_pages_checked'=>true,'name'=>pathinfo($d['name'],PATHINFO_FILENAME).'_p'.sprintf('%03d',$page['number']).'.pdf',
                        'mimeType'=>'application/pdf','format'=>'pdf','size'=>$page['size'],'md5Checksum'=>$page['md5Checksum'],'sha256'=>$page['sha256'],
                        'root'=>$d['root'],'source'=>$d['source'],'source_parent'=>$d['source_parent'],'source_month'=>$d['source_month'],
                        'expected_period'=>$d['expected_period'],'stage'=>'queued','toconline_state'=>'pending','attempts'=>0,
                        'requested_by'=>$d['requested_by'],'created_at'=>gmdate('c'),'error'=>''];
                }
                $d['stage']='split'; $d['toconline_state']='receipt_per_page'; $d['error']='';
                $s['documents'][$id]=$d; $this->save($s); return; // Parent and all stable child IDs commit together.
            }
            $this->put($id,$d);
        }
        if (in_array($d['stage'],['queued','reading'],true)) {
            $pdf=$this->sourceBytes($d); $d['sha256']=hash('sha256',$pdf); $d['stage']='reading'; $this->put($id,$d);
            if (!empty($d['ocr_creating']) && empty($d['ocr_id'])) {
                $d['ocr_id']=$this->drive->findReceiptOcr($id,$d['source']);
                if (!$d['ocr_id']) { $d['stage']='review'; $d['error']='receipt_ocr_uncertain'; $this->put($id,$d); return; }
                $this->put($id,$d);
            }
            if (empty($d['ocr_id'])) {
                $d['ocr_creating']=true; $this->put($id,$d); // Unknown creation outcome is never blindly repeated.
                $d['ocr_id']=$this->drive->createReceiptOcr($pdf,$id,$d['source'],$d['mimeType']); $this->put($id,$d);
            }
            $text=$this->drive->receiptOcrText($d['ocr_id']);
            $settings=$this->toc->settings(); InvoiceToconline::destination($settings['nif']);
            $d['issuer_name']=self::issuerName($text);
            $result=self::classify($text,$settings['nif']); unset($text,$pdf);
            $this->drive->trashReceiptOcr($d['ocr_id'],$id); $d['ocr_cleaned']=true;
            if (!$result) { $d['stage']='review'; $d['error']='receipt_review_required'; $this->put($id,$d); return; }
            $d=array_merge($d,$result,['company_nif'=>$settings['nif'],'stage'=>'ready']);
            $this->put($id,$d);
        }
        if (in_array($d['stage'],['ready','moving'],true)) {
            if (empty($d['drive_parent']) && $this->localDuplicateSafe($d,$this->status()['documents'])) {
                $d['stage']='duplicate_trashed';$d['duplicate_skipped_at']=gmdate('c');$d['error']='';$this->put($id,$d);return;
            }
            if (!self::validDate($d['issued_on']??'')) throw new RuntimeException('receipt_review_required');
            if (!InvoiceReceiptAnalysis::buyerAccepted($d,$this->toc->settings()['nif'])) throw new RuntimeException(InvoiceReceiptAnalysis::buyerIssue($d,$this->toc->settings()['nif']) ?: 'receipt_company_changed');
            if (!empty($d['pending_parent']) && $d['drive_parent']===$d['pending_parent']) {
                $d['source_parent']=$d['pending_parent']; unset($d['drive_parent']);
            }
            if (empty($d['drive_parent'])) {
                $parent=$d['root']; $layout=InvoiceDriveLayout::folders(substr($d['issued_on'],0,7)); $layout[2]=[self::archiveCategory($d)];
                $folders=new InvoiceDrive($this->pdo,$this->vault,$this->drive);
                foreach ($layout as $names) $parent=$folders->folder($parent,$names);
                $d['drive_parent']=$parent; $d['stage']='moving'; $this->put($id,$d);
            }
            if (!empty($d['pending_parent'])) {
                $this->drive->moveReceipt($d); $this->drive->verifiedReceipt($d);
            } elseif (!empty($d['source_id'])) {
                $this->sourceBytes($d); $path=$this->pagePath($d);
                $m=$this->drive->upload($id,$d['drive_parent'],$d['name'],$path,$d['format']);
                if (!InvoiceDrive::verify($m,$d+['drive_id'=>$id,'size_bytes'=>$d['size']],$path)) throw new RuntimeException('drive_verify');
            } else {
                $m=$this->drive->metadata($id);
                if (!in_array($d['drive_parent'],$m['parents']??[],true)) {
                    $pdf=$this->drive->scanDocument($d);
                    if (!hash_equals($d['sha256'],hash('sha256',$pdf))) throw new RuntimeException('drive_verify');
                }
                $this->drive->moveReceipt($d);
            }
            $d['stage']='archived'; $d['archived_at']=gmdate('c'); $d['error']=''; $this->put($id,$d); $this->clearPageCache($d);
        }
        if ($d['stage']==='archived') {
            if (in_array($d['toconline_state'],['pending','toc_retry'],true)) {
                if (!empty($d['duplicate_keeper'])) return;
                $this->toc->submitReceipt($d);
            }
            $d=$this->status()['documents'][$id];
            if (!$this->toc->receiptDuplicateConfirmed($id) && !$this->localDuplicateSafe($d,$this->status()['documents'])) return;
            $d['stage']='trashing'; $this->put($id,$d);
        }
        if ($d['stage']==='trashing') {
            if (!$this->toc->receiptDuplicateConfirmed($id) && !$this->localDuplicateSafe($d,$this->status()['documents'])) throw new RuntimeException('receipt_review_required');
            if (!empty($d['duplicate_keeper'])) {
                $keeper=$this->status()['documents'][$d['duplicate_keeper']];
                if ($keeper['stage']==='archived') $this->drive->verifiedReceipt($keeper);
            }
            $this->drive->trashReceiptFile($d); $d['stage']='duplicate_trashed'; $d['trashed_at']=gmdate('c'); $d['error']=''; $this->put($id,$d);
        }
    }
    private static function error(Throwable $e): string
    {
        $code=$e->getMessage();
        return preg_match('/\A(?:drive_[a-z_]+|receipt_[a-z_]+|toc_[a-z_]+|gemini_[a-z_]+)\z/',$code)?$code:'receipt_failed';
    }
}
