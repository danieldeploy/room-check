<?php
declare(strict_types=1);
require_once __DIR__.'/InvoiceDrive.php';
require_once __DIR__.'/InvoiceToconline.php';
require_once __DIR__.'/InvoiceReceiptAnalysis.php';

/** Manual intake, continued by the existing invoice worker. Every writer holds room_check_invoices. */
final class InvoiceReceipts
{
    public const FILE='receipts-workflow.enc';
    public function __construct(private readonly PDO $pdo, private readonly InvoiceVault $vault,
        private readonly object $drive, private readonly InvoiceToconline $toc, private readonly mixed $splitter=null, private readonly ?object $analysis=null) {}

    public function status(): array
    {
        if ($this->vault->has(self::FILE)) return $this->vault->read(self::FILE);
        if ($this->vault->has('toconline-ledger.enc')) foreach ($this->vault->read('toconline-ledger.enc') as $entry) {
            if (str_starts_with((string)$entry['document_id'],'receipt:')) throw new RuntimeException('receipt_history_missing');
        }
        return ['documents'=>[],'requested'=>false,'error'=>''];
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
    /** Read only a recorded document; never accept a caller-supplied Drive path. */
    public function preview(string $id, bool $original = false): array
    {
        $documents=$this->status()['documents'];
        $d=$documents[$id]??null;
        if (!is_array($d) || !in_array($d['format']??'', ['pdf','jpg'],true)
            || in_array($d['stage']??'', ['duplicate_trashed','split_completed'],true)) throw new RuntimeException('receipt_preview_unavailable');
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
    public static function deliveryState(InvoiceVault $vault,string $id,string $state): void
    {
        if (!$vault->has(self::FILE)) throw new RuntimeException('receipt_history_missing');
        $s=$vault->read(self::FILE);
        if (!isset($s['documents'][$id])) throw new RuntimeException('receipt_history_missing');
        $s['documents'][$id]['toconline_state']=$state; $vault->save(self::FILE,$s);
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
            if (($s['year']??null)!==$year || ($s['months']??[])!==$months) throw new RuntimeException('worker_busy');
            return;
        }
        $s['requested']=true; $s['requested_by']=$actor; $s['requested_at']=gmdate('c');
        $s['year']=$year; $s['months']=$months; $s['missing_periods']=[]; $s['error']=''; $this->save($s);
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
    public function review(string $id,string $date,string $number,bool $confirmed,int $actor,string $issuerName=''): void
    {
        $s=$this->status(); $d=$s['documents'][$id]??[];
        if (($d['stage']??'')!=='review' || empty($d['sha256']) || !in_array($d['format']??'',['pdf','jpg'],true)
            || !self::validDate($date) || !$confirmed || trim($number)==='' || mb_strlen($number)>128
            || preg_match('/[\x00-\x1f]/',$number)) throw new RuntimeException('receipt_review_required');
        if (mb_strlen($issuerName)>160 || preg_match('/[\x00-\x1f]/',$issuerName)) throw new RuntimeException('invalid_request');
        if (($d['review_reason']??'')==='receipt_grouping_required') throw new RuntimeException('receipt_grouping_required');
        if (!empty($d['grouping_pending'])) throw new RuntimeException('receipt_grouping_required');
        if (($d['data_source']??'')==='qr' && (($d['issued_on']??'')!==$date || ($d['invoice_number']??'')!==trim($number))) throw new RuntimeException('receipt_qr_locked');
        $settings=$this->toc->settings(); InvoiceToconline::destination($settings['nif']);
        if (($d['data_source']??'')==='qr' && ($d['company_nif']??'')!==$settings['nif']) throw new RuntimeException('receipt_company_changed');
        if (!empty($d['ocr_id']) && empty($d['ocr_cleaned'])) {
            $this->drive->connect(); $this->drive->trashReceiptOcr($d['ocr_id'],$id); $d['ocr_cleaned']=true;
        }
        if (isset($d['fiscal_status']) && $d['fiscal_status']!=='N') throw new RuntimeException('receipt_review_required');
        $d['issuer_name']=trim($issuerName);
        $d['issued_on']=$date; $d['invoice_number']=trim($number); $d['company_nif']=$settings['nif'];
        $d['reviewed_by']=$actor; $d['reviewed_at']=gmdate('c'); $d['stage']='ready'; $d['error']=''; $d['attempts']=0; $d['next_attempt']=0; $d['review_reason']=''; $d['complete']=true;
        $this->put($id,$d);
    }
    public function retry(string $id): void
    {
        $s=$this->status(); $d=$s['documents'][$id]??[];
        if (($d['stage']??'')!=='failed') throw new RuntimeException('invalid_request');
        $d['stage']=$d['resume_stage']; $d['attempts']=0; $d['next_attempt']=0; $d['error']=''; $this->put($id,$d);
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
            $old=$s['documents'][$id]??null;
            if ($old) {
                // Explicit reselection can recover a wrongly filed, still-unsent scan.
                // Replacing bytes on the same ID requires a new scan, never stale OCR reuse.
                if (($old['md5Checksum']??'')!==($f['md5Checksum']??'')) continue;
                if ($old['stage']!=='review' || $old['toconline_state']!=='pending' || !empty($old['drive_parent'])
                    || (($old['expected_period']??'')===$f['expected_period'] && ($old['source_parent']??'')===($f['parents'][0]??'')
                        && ($old['md5Checksum']??'')===($f['md5Checksum']??''))) continue;
            }
            if (!$old && count($s['documents'])>=2000) throw new RuntimeException('receipt_limit');
            $format=InvoiceDriveClient::receiptFormat($f);
            $valid=$format!==null && (int)($f['size']??0)>=5 && (int)$f['size']<=10*1024*1024 && !empty($f['md5Checksum']);
            $s['documents'][$id]=$f+['format'=>$format,'source_parent'=>$f['parents'][0]??'', 'root'=>$root,'source'=>$source,
                'stage'=>$valid?'queued':'review','error'=>$valid?'':'receipt_format_required','toconline_state'=>'pending',
                'requested_by'=>$s['requested_by'],'created_at'=>gmdate('c'),'attempts'=>0];
            if ($old && !empty($old['ocr_creating']) && empty($old['ocr_cleaned'])) {
                // Never lose an unresolved temporary copy when the owner corrects the folder.
                $s['documents'][$id]['ocr_creating']=true;
                $s['documents'][$id]['ocr_id']=$old['ocr_id']??null;
            }
        }
        $s['requested']=false; $s['source']=$source; $s['root']=$root; $s['scanned_at']=gmdate('c'); $s['error']=''; $this->save($s);
    }
    /** Manager supplies complete page groups; fiscal QR fields remain authoritative. */
    public function regroup(string $id,string $text,bool $confirmed,int $actor): void
    {
        $s=$this->status();$d=$s['documents'][$id]??null;
        if (!$confirmed || !is_array($d) || !empty($d['source_id']) || ($d['format']??'')!=='pdf'
            || in_array($d['stage'],['split_completed','source_trashing'],true) || strlen($text)>400) throw new RuntimeException('receipt_grouping_required');
        $groups=[];$seen=[];
        foreach (preg_split('/[;\r\n]+/',trim($text)) as $line) {
            if (!preg_match('/\A\s*\d+(?:\s*,\s*\d+)*\s*\z/',$line)) throw new RuntimeException('receipt_grouping_required');
            $pages=array_map('intval',explode(',',$line));$last=0;
            foreach ($pages as $p) { if ($p<1 || $p>50 || $p<=$last || isset($seen[$p])) throw new RuntimeException('receipt_grouping_required'); $seen[$p]=true;$last=$p; }
            $groups[]=$pages;
        }
        // Actual page count is checked again by the local parser on the original bytes.
        foreach ($d['children']??[$id] as $child) if (!$this->toc->receiptUnsent($child)) throw new RuntimeException('receipt_legacy_submitted');
        $d['legacy_children']=array_values(array_unique(array_merge($d['legacy_children']??[],$d['children']??[])));
        foreach ($d['children']??[] as $child) { $s['documents'][$child]['stage']='superseded';$s['documents'][$child]['error']=''; }
        unset($d['children'],$d['analysis_version'],$d['manifest'],$d['grouping_pending']);
        $d['manual_groups']=$groups;$d['grouped_by']=$actor;$d['stage']='queued';$d['error']='';$d['attempts']=0;$d['next_attempt']=0;
        $s['documents'][$id]=$d;$this->save($s);
    }
    /** Reconcile only unsent old page guesses. Existing sent history is immutable. */
    private function recoverLegacy(): void
    {
        $s=$this->status(); if (($s['analysis_version']??0)>=InvoiceReceiptAnalysis::VERSION) return;
        foreach ($s['documents'] as $id=>$d) {
            if (!empty($d['source_id']) || !empty($d['analysis_version']) || in_array($d['stage'],['split_completed','duplicate_trashed'],true)) continue;
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
                'id'=>$child,'source_id'=>$id,'analysis_version'=>InvoiceReceiptAnalysis::VERSION,
                'page_number'=>$group['pages'][0],'page_count'=>$manifest['page_count'],'pdf_pages_checked'=>true,
                'name'=>pathinfo($d['name'],PATHINFO_FILENAME).'_f'.sprintf('%03d',$i+1).'.'.$d['format'],
                'mimeType'=>$d['mimeType'],'format'=>$d['format'],'root'=>$d['root'],'source'=>$d['source'],
                'source_parent'=>$d['source_parent'],'source_month'=>$d['source_month'],'expected_period'=>$d['expected_period'],
                'stage'=>$review===''?'ready':'preserving','review_reason'=>$review,'error'=>'','toconline_state'=>'pending',
                'requested_by'=>$d['requested_by'],'created_at'=>gmdate('c'),'attempts'=>0]);
        }
        if (!empty($d['ocr_id']) && empty($d['ocr_cleaned'])) $this->drive->trashReceiptOcr($d['ocr_id'],$id);
        $d['children']=$children;$d['analysis_version']=InvoiceReceiptAnalysis::VERSION;$d['manifest']=$manifest;
        $d['page_count']=$manifest['page_count'];$d['stage']='split';$d['toconline_state']='receipt_per_page';$d['error']='';
        $s['documents'][$id]=$d;$this->save($s); // Manifest and stable child identities commit together before any upload/send.
    }
    public function recordFailure(Throwable $e): void
    { $s=$this->status();$s['error']=self::error($e);$this->save($s); }
    /** Bounded work on the existing cron, including short persisted retry windows. */
    public function runBatch(): void
    {
        $deadline=microtime(true)+45;$turns=0;
        do {
            $before=$this->status();$this->run();$after=$this->status();$turns++;
            if (($before['documents']??[])===($after['documents']??[]) && ($before['requested']??false)===($after['requested']??false)) {
                $next=0;foreach ($after['documents']??[] as $d) if (in_array($d['stage'],['queued','reading','ready','moving','preserving'],true) && ($d['next_attempt']??0)>time()) $next=$next?min($next,$d['next_attempt']):$d['next_attempt'];
                if (!$next || $next>$deadline-1) break;
                while (time()<$next && microtime(true)<$deadline) usleep(250000);
            }
        } while ($turns<8 && microtime(true)<$deadline);
    }
    public function run(): void
    {
        if (!$this->vault->has(self::FILE)) return; // Deployment never initiates intake.
        if ($this->analysis) {
            if (!$this->analysis->configured()) { $s=$this->status(); $s['error']='gemini_not_configured'; $this->save($s); return; }
            $this->recoverLegacy();
        }
        $s=$this->status(); $s['worker_seen_at']=time(); $this->save($s);
        $before=$s['documents'];
        // Upgrade only unsent originals; archived/sent historical PDFs are never split again.
        foreach ($s['documents'] as &$d) {
            // Recheck older cleanup denials once with Drive's explicit canTrash capability.
            if (($d['error']??'')==='drive_permission' && empty($d['trash_capability_recheck']) && in_array($d['stage'],['source_trashing','trashing'],true)) {
                $d['next_attempt']=0;$d['trash_capability_recheck']=true;
            }
            if (!$this->analysis && $d['stage']==='review' && $d['toconline_state']==='pending' && empty($d['drive_parent'])) {
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
        $work=$s['requested'] || array_filter($s['documents'],fn($d)=>
            (($d['next_attempt']??0)<=time()) && (in_array($d['stage'],['queued','reading','ready','moving','preserving','trashing','source_trashing'],true)
            || (empty($s['source_cleanup_paused']) && $d['stage']==='split' && $this->childrenArchived($d,$s['documents']))
            || ($d['stage']==='archived' && ($d['toconline_state']==='toc_existing'
                || ($sendEnabled && in_array($d['toconline_state'],['pending','toc_retry'],true))))));
        if (!$work) return;
        try {
            if (!$this->drive->receiptsAuthorized()) throw new RuntimeException('receipt_connect');
            $this->drive->connect(); $this->discover();
        } catch (Throwable $e) { $s=$this->status(); $s['requested']=false; $s['error']=self::error($e); $this->save($s); return; }
        // One invoice or source cleanup per invocation bounds work and preserves the portal queue.
        $documents=$this->status()['documents'];
        uasort($documents,static fn($a,$b)=>(int)($a['stage']==='archived')<=>(int)($b['stage']==='archived'));
        foreach ($documents as $id=>$d) {
            if (!empty($s['source_cleanup_paused']) && in_array($d['stage'],['split','source_trashing'],true)) continue;
            if (!in_array($d['stage'],['queued','reading','ready','moving','preserving','archived','trashing','split','source_trashing'],true) || ($d['next_attempt']??0)>time()) continue;
            if ($d['stage']==='split' && !$this->childrenArchived($d,$documents)) continue;
            if ($d['stage']==='archived' && in_array($d['toconline_state'],['pending','toc_retry'],true)
                && (empty($this->toc->settings()['enabled']) || empty($this->toc->settings()['pilot_verified']))) continue;
            if ($d['stage']==='archived' && !in_array($d['toconline_state'],['pending','toc_retry','toc_existing'],true)) continue;
            try { $this->process($id,$d); }
            catch (Throwable $e) {
                $d=$this->status()['documents'][$id];
                if ($e->getMessage()==='drive_trash_permission') {
                    $d['resume_stage']=$d['stage'];$d['stage']='failed';$d['error']='drive_trash_permission';$d['next_attempt']=0;
                    $this->put($id,$d);break;
                }
                if ($e instanceof InvoiceTocWait) {
                    $d['error']='';$d['next_attempt']=$e->retryAt;$this->put($id,$d);break;
                }
                if ($e instanceof InvoiceGeminiRetry) {
                    $d['error']=$e->getMessage(); $d['next_attempt']=$e->retryAt;
                    if ($e->getMessage()==='gemini_retry') {
                        $d['attempts']=($d['attempts']??0)+1;
                        if ($d['attempts']>=5) { $d['resume_stage']=$d['stage'];$d['stage']='failed'; }
                    }
                    $this->put($id,$d); break;
                }
                if (in_array($e->getMessage(),['receipt_grouping_required','receipt_company_changed','receipt_missing_pages','gemini_response'],true)) {
                    $d['stage']='review'; $d['error']=$e->getMessage(); $d['grouping_pending']=empty($d['source_id']); $this->put($id,$d); break;
                } $d['error']=self::error($e); $d['attempts']=($d['attempts']??0)+1;
                if ($d['attempts']>=5) { $d['resume_stage']=$d['stage']; $d['stage']='failed'; }
                $d['next_attempt']=time()+min(3600,60*(2**$d['attempts'])); $this->put($id,$d);
            }
            break;
        }
    }
    private function pagePath(array $d): string
    {
        if (!preg_match('/\A[a-f0-9]{64}\z/',$d['sha256']??'')) throw new RuntimeException('drive_verify');
        return $this->vault->path('receipt-page-'.$d['sha256'].'.'.(($d['format']??'pdf')==='jpg'?'jpg':'pdf'));
    }
    private function sourceBytes(array $d): string
    {
        if (empty($d['source_id'])) return $this->drive->scanDocument($d);
        $path=$this->pagePath($d);
        if (!is_file($path) && (!empty($d['preserved_at']) || !empty($d['archived_at']))) {
            return $this->drive->verifiedReceipt($d);
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
            if (($documents[$id]['review_reason']??'')==='receipt_grouping_required') return false;
            if (empty($documents[$id]['archived_at']) && empty($documents[$id]['preserved_at'])) return false;
        }
        return true;
    }
    private function clearPageCache(array $d): void
    {
        if (empty($d['source_id'])) return;
        foreach ($this->status()['documents'] as $other) {
            if (($other['stage']??'')!=='superseded' && !empty($other['source_id']) && empty($other['archived_at']) && ($other['sha256']??'')===$d['sha256']) return;
        }
        $path=$this->pagePath($d); if (is_file($path)) unlink($path);
    }
    private function process(string $id,array $d): void
    {
        if (in_array($d['stage'],['split','source_trashing'],true)) {
            $documents=$this->status()['documents'];
            if (!$this->childrenArchived($d,$documents)) throw new RuntimeException('drive_verify');
            foreach ($d['children'] as $childId) {
                $child=$documents[$childId];
                if ($child['stage']==='duplicate_trashed' && $this->toc->receiptDuplicateConfirmed($childId)) continue;
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
            $d['stage']='source_trashing'; $this->put($id,$d);
            $this->drive->trashReceiptFile(array_merge($d,['drive_parent'=>$d['original_parent']??$d['source_parent']]));
            $d['stage']='split_completed'; $d['trashed_at']=gmdate('c'); $d['error']=''; $this->put($id,$d);
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
                        'id'=>$childId,'source_id'=>$id,'page_number'=>$page['number'],'page_count'=>$result['count'],
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
            if (!self::validDate($d['issued_on']??'')) throw new RuntimeException('receipt_review_required');
            if ($d['company_nif']!==$this->toc->settings()['nif']) throw new RuntimeException('receipt_company_changed');
            if (!empty($d['pending_parent']) && $d['drive_parent']===$d['pending_parent']) {
                $d['source_parent']=$d['pending_parent']; unset($d['drive_parent']);
            }
            if (empty($d['drive_parent'])) {
                $parent=$d['root']; $layout=InvoiceDriveLayout::folders(substr($d['issued_on'],0,7)); $layout[2]=[$d['format']==='jpg'?'taloes':'pdf'];
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
            if (in_array($d['toconline_state'],['pending','toc_retry'],true)) $this->toc->submitReceipt($d);
            $d=$this->status()['documents'][$id];
            if ($d['toconline_state']!=='toc_existing' || !$this->toc->receiptDuplicateConfirmed($id)) return;
            $d['stage']='trashing'; $this->put($id,$d);
        }
        if ($d['stage']==='trashing') {
            if (!$this->toc->receiptDuplicateConfirmed($id)) throw new RuntimeException('receipt_review_required');
            $this->drive->trashReceiptFile($d); $d['stage']='duplicate_trashed'; $d['trashed_at']=gmdate('c'); $d['error']=''; $this->put($id,$d);
        }
    }
    private static function error(Throwable $e): string
    {
        $code=$e->getMessage();
        return preg_match('/\A(?:drive_[a-z_]+|receipt_[a-z_]+|toc_[a-z_]+|gemini_[a-z_]+)\z/',$code)?$code:'receipt_failed';
    }
}
