<?php
declare(strict_types=1);
require_once __DIR__.'/InvoiceDrive.php';
require_once __DIR__.'/InvoiceToconline.php';

/** Manual intake, continued by the existing invoice worker. Every writer holds room_check_invoices. */
final class InvoiceReceipts
{
    public const FILE='receipts-workflow.enc';
    public function __construct(private readonly PDO $pdo, private readonly InvoiceVault $vault,
        private readonly object $drive, private readonly InvoiceToconline $toc, private readonly mixed $splitter=null) {}

    public function status(): array
    {
        if ($this->vault->has(self::FILE)) return $this->vault->read(self::FILE);
        if ($this->vault->has('toconline-ledger.enc')) foreach ($this->vault->read('toconline-ledger.enc') as $entry) {
            if (str_starts_with((string)$entry['document_id'],'receipt:')) throw new RuntimeException('receipt_history_missing');
        }
        return ['documents'=>[],'requested'=>false,'error'=>''];
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
        $head=preg_split('/(?:^|\\n)\\s*(?:cliente|adquirente|customer|client)\\s*[:\\-]/iu', $text,2)[0];
        preg_match_all('/(?:^|\\n)\\s*(?:fornecedor|emitente|supplier|issuer|vendedor|seller)\\s*[:\\-]\\s*([^\\r\\n]{1,160})/iu',$head,$matches);
        $names=array_values(array_unique(array_map('trim',$matches[1])));
        if (!$names) {
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
        $settings=$this->toc->settings(); InvoiceToconline::destination($settings['nif']);
        if (!empty($d['ocr_id']) && empty($d['ocr_cleaned'])) {
            $this->drive->connect(); $this->drive->trashReceiptOcr($d['ocr_id'],$id); $d['ocr_cleaned']=true;
        }
        $d['issuer_name']=trim($issuerName);
        $d['issued_on']=$date; $d['invoice_number']=trim($number); $d['company_nif']=$settings['nif'];
        $d['reviewed_by']=$actor; $d['reviewed_at']=gmdate('c'); $d['stage']='ready'; $d['error']=''; $d['attempts']=0;
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
    public function run(): void
    {
        if (!$this->vault->has(self::FILE)) return; // Deployment never initiates intake.
        $s=$this->status();
        $before=$s['documents'];
        // Upgrade only unsent originals; archived/sent historical PDFs are never split again.
        foreach ($s['documents'] as &$d) {
            if ($d['stage']==='review' && $d['toconline_state']==='pending' && empty($d['drive_parent'])) {
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
            (($d['next_attempt']??0)<=time()) && (in_array($d['stage'],['queued','reading','ready','moving','trashing','source_trashing'],true)
            || ($d['stage']==='split' && $this->childrenArchived($d,$s['documents']))
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
            if (!in_array($d['stage'],['queued','reading','ready','moving','archived','trashing','split','source_trashing'],true) || ($d['next_attempt']??0)>time()) continue;
            if ($d['stage']==='split' && !$this->childrenArchived($d,$documents)) continue;
            if ($d['stage']==='archived' && in_array($d['toconline_state'],['pending','toc_retry'],true)
                && (empty($this->toc->settings()['enabled']) || empty($this->toc->settings()['pilot_verified']))) continue;
            if ($d['stage']==='archived' && !in_array($d['toconline_state'],['pending','toc_retry','toc_existing'],true)) continue;
            try { $this->process($id,$d); }
            catch (Throwable $e) {
                $d=$this->status()['documents'][$id]; $d['error']=self::error($e); $d['attempts']=($d['attempts']??0)+1;
                if ($d['attempts']>=5) { $d['resume_stage']=$d['stage']; $d['stage']='failed'; }
                $d['next_attempt']=time()+min(3600,60*(2**$d['attempts'])); $this->put($id,$d);
            }
            break;
        }
    }
    private function pagePath(array $d): string
    {
        if (!preg_match('/\A[a-f0-9]{64}\z/',$d['sha256']??'')) throw new RuntimeException('drive_verify');
        return $this->vault->path('receipt-page-'.$d['sha256'].'.pdf');
    }
    private function sourceBytes(array $d): string
    {
        if (empty($d['source_id'])) return $this->drive->scanDocument($d);
        $path=$this->pagePath($d);
        if (!is_file($path)) {
            $parent=$this->status()['documents'][$d['source_id']]??[];
            $bytes=$this->drive->scanDocument($parent);
            if (!hash_equals($parent['sha256'],hash('sha256',$bytes))) throw new RuntimeException('drive_verify');
            $this->splitPdf($bytes); // Rebuild the exact pinned page bytes; never allocate new IDs.
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
        foreach ($d['children'] as $id) if (empty($documents[$id]['archived_at'])) return false;
        return true;
    }
    private function clearPageCache(array $d): void
    {
        if (empty($d['source_id'])) return;
        foreach ($this->status()['documents'] as $other) {
            if (!empty($other['source_id']) && empty($other['archived_at']) && ($other['sha256']??'')===$d['sha256']) return;
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
            $d['stage']='source_trashing'; $this->put($id,$d);
            $this->drive->trashReceiptFile(array_merge($d,['drive_parent'=>$d['source_parent']]));
            $d['stage']='split_completed'; $d['trashed_at']=gmdate('c'); $d['error']=''; $this->put($id,$d);
            foreach ($d['children'] as $childId) $this->clearPageCache($documents[$childId]);
            return;
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
            if (empty($d['drive_parent'])) {
                $parent=$d['root']; $layout=InvoiceDriveLayout::folders(substr($d['issued_on'],0,7)); $layout[2]=[$d['format']==='jpg'?'taloes':'pdf'];
                $folders=new InvoiceDrive($this->pdo,$this->vault,$this->drive);
                foreach ($layout as $names) $parent=$folders->folder($parent,$names);
                $d['drive_parent']=$parent; $d['stage']='moving'; $this->put($id,$d);
            }
            if (!empty($d['source_id'])) {
                $this->sourceBytes($d); $path=$this->pagePath($d);
                $m=$this->drive->upload($id,$d['drive_parent'],$d['name'],$path,'pdf');
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
        return preg_match('/\A(?:drive_[a-z_]+|receipt_[a-z_]+|toc_[a-z_]+)\z/',$code)?$code:'receipt_failed';
    }
}
