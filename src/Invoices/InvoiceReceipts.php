<?php
declare(strict_types=1);
require_once __DIR__.'/InvoiceDrive.php';
require_once __DIR__.'/InvoiceToconline.php';

/** Manual intake, continued by the existing invoice worker. Every writer holds room_check_invoices. */
final class InvoiceReceipts
{
    public const FILE='receipts-workflow.enc';
    public function __construct(private readonly PDO $pdo, private readonly InvoiceVault $vault,
        private readonly object $drive, private readonly InvoiceToconline $toc) {}

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
    public function review(string $id,string $date,string $number,bool $confirmed,int $actor): void
    {
        $s=$this->status(); $d=$s['documents'][$id]??[];
        if (($d['stage']??'')!=='review' || empty($d['sha256']) || !in_array($d['format']??'',['pdf','jpg'],true)
            || !self::validDate($date) || !$confirmed || trim($number)==='' || mb_strlen($number)>128
            || preg_match('/[\x00-\x1f]/',$number)) throw new RuntimeException('receipt_review_required');
        if (substr($date,0,7)!==($d['expected_period']??'')) throw new RuntimeException('receipt_period_mismatch');
        $settings=$this->toc->settings(); InvoiceToconline::destination($settings['nif']);
        if (!empty($d['ocr_id']) && empty($d['ocr_cleaned'])) {
            $this->drive->connect(); $this->drive->trashReceiptOcr($d['ocr_id'],$id); $d['ocr_cleaned']=true;
        }
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
                // Explicit reselection can recover a wrongly filed/replaced, still-unsent scan.
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
        $sendEnabled=!empty($this->toc->settings()['enabled']) && !empty($this->toc->settings()['pilot_verified']);
        $work=$s['requested'] || array_filter($s['documents'],static fn($d)=>
            (($d['next_attempt']??0)<=time()) && (in_array($d['stage'],['queued','reading','ready','moving','trashing'],true)
            || ($d['stage']==='archived' && ($d['toconline_state']==='toc_existing'
                || ($sendEnabled && in_array($d['toconline_state'],['pending','toc_retry'],true))))));
        if (!$work) return;
        try {
            if (!$this->drive->receiptsAuthorized()) throw new RuntimeException('receipt_connect');
            $this->drive->connect(); $this->discover();
        } catch (Throwable $e) { $s=$this->status(); $s['requested']=false; $s['error']=self::error($e); $this->save($s); return; }
        // One original per invocation bounds OCR/network work and preserves the portal queue.
        $documents=$this->status()['documents'];
        uasort($documents,static fn($a,$b)=>(int)($a['stage']==='archived')<=>(int)($b['stage']==='archived'));
        foreach ($documents as $id=>$d) {
            if (!in_array($d['stage'],['queued','reading','ready','moving','archived','trashing'],true) || ($d['next_attempt']??0)>time()) continue;
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
    private function process(string $id,array $d): void
    {
        if (in_array($d['stage'],['queued','reading'],true)) {
            $pdf=$this->drive->scanDocument($d); $d['sha256']=hash('sha256',$pdf); $d['stage']='reading'; $this->put($id,$d);
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
            $result=self::classify($text,$settings['nif']); unset($text,$pdf);
            $this->drive->trashReceiptOcr($d['ocr_id'],$id); $d['ocr_cleaned']=true;
            if (!$result) { $d['stage']='review'; $d['error']='receipt_review_required'; $this->put($id,$d); return; }
            $d=array_merge($d,$result,['company_nif'=>$settings['nif'],'stage'=>'ready']);
            if (substr($d['issued_on'],0,7)!==($d['expected_period']??'')) { $d['stage']='review'; $d['error']='receipt_period_mismatch'; $this->put($id,$d); return; }
            $this->put($id,$d);
        }
        if (in_array($d['stage'],['ready','moving'],true)) {
            if (substr($d['issued_on'],0,7)!==($d['expected_period']??'')) throw new RuntimeException('receipt_period_mismatch');
            if ($d['company_nif']!==$this->toc->settings()['nif']) throw new RuntimeException('receipt_company_changed');
            if (empty($d['drive_parent'])) {
                $parent=$d['root']; $layout=InvoiceDriveLayout::folders(substr($d['issued_on'],0,7)); $layout[2]=[$d['format']==='jpg'?'taloes':'pdf'];
                $folders=new InvoiceDrive($this->pdo,$this->vault,$this->drive);
                foreach ($layout as $names) $parent=$folders->folder($parent,$names);
                $d['drive_parent']=$parent; $d['stage']='moving'; $this->put($id,$d);
            }
            $m=$this->drive->metadata($id);
            if (!in_array($d['drive_parent'],$m['parents']??[],true)) {
                $pdf=$this->drive->scanDocument($d);
                if (!hash_equals($d['sha256'],hash('sha256',$pdf))) throw new RuntimeException('drive_verify');
            }
            $this->drive->moveReceipt($d); $d['stage']='archived'; $d['archived_at']=gmdate('c'); $d['error']=''; $this->put($id,$d);
        }
        if ($d['stage']==='archived') {
            if (in_array($d['toconline_state'],['pending','toc_retry'],true)) $this->toc->submitReceipt($d);
            $d=$this->status()['documents'][$id];
            if ($d['toconline_state']!=='toc_existing' || !$this->toc->receiptDuplicateConfirmed($id)) return;
            $d['stage']='trashing'; $this->put($id,$d);
        }
        if ($d['stage']==='trashing') {
            if (!$this->toc->receiptDuplicateConfirmed($id)) throw new RuntimeException('receipt_review_required');
            $this->drive->trashReceiptDuplicate($d); $d['stage']='duplicate_trashed'; $d['trashed_at']=gmdate('c'); $d['error']=''; $this->put($id,$d);
        }
    }
    private static function error(Throwable $e): string
    {
        $code=$e->getMessage();
        return preg_match('/\A(?:drive_[a-z_]+|receipt_[a-z_]+|toc_[a-z_]+)\z/',$code)?$code:'receipt_failed';
    }
}
