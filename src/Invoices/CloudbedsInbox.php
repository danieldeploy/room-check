<?php
declare(strict_types=1);
require_once __DIR__.'/CloudbedsBridge.php';
require_once __DIR__.'/CloudbedsInvoice.php';
require_once __DIR__.'/InvoiceVault.php';
require_once __DIR__.'/InvoiceTaskLifecycle.php';

/** Caller holds room_check_invoices. The inbox is encrypted and bounded. */
final class CloudbedsInbox
{
    public function __construct(private readonly PDO $pdo,private readonly InvoiceVault $vault) {}
    public function settings(): array { return $this->vault->has('cloudbeds-settings.enc')?$this->vault->read('cloudbeds-settings.enc'):[]; }
    public function receive(array $packet,string $source): void
    {
        $settings=$this->settings(); $id=(int)($settings['account_ids'][$source]??0);
        $accounts=new InvoiceAccounts($this->pdo);
        if ($id<1 || $accounts->get($id)['portal']!=='cloudbeds' || !$accounts->active($id)) throw new RuntimeException('account_mismatch');
        $files=glob($this->vault->root.'/cloudbeds-inbox-*.enc')?:[];
        foreach (CloudbedsBridge::validate($packet,$source) as $hash=>$pdf) {
            $name='cloudbeds-inbox-'.$source.'-'.$hash.'.enc';
            if ($this->vault->has($name)) continue;
            if (count($files)>=2000) throw new RuntimeException('document_limit');
            $this->vault->save($name,['version'=>1,'source'=>$source,'account_id'=>$id,'sha256'=>$hash,
                'mail_sha256'=>$packet['mail_sha256'],'received_at'=>time(),'pdf'=>base64_encode($pdf)]);
            $files[]=$name;
        }
    }
    public function heartbeat(string $source,array $scan): void
    {
        if (!isset($this->settings()['account_ids'][$source]) || ($scan['folder_found']??null)!==true
            || !is_int($scan['processed']??null) || !is_int($scan['retry']??null)
            || $scan['processed']<0 || $scan['processed']>40 || $scan['retry']<0 || $scan['retry']>5000) throw new RuntimeException('invalid_request');
        $this->vault->save('cloudbeds-source-'.$source.'.enc',['at'=>time(),'folder_found'=>true,'processed'=>$scan['processed'],'retry'=>$scan['retry']]);
    }
    public function index(): void
    {
        $settings=$this->settings(); if (!$settings) return;
        require_once __DIR__.'/InvoiceHostelworldVerification.php';
        $nif=InvoiceHostelworldVerification::companyNif($this->vault); $count=0;
        foreach (glob($this->vault->root.'/cloudbeds-inbox-*.enc')?:[] as $path) {
            $name=basename($path); $item=$this->vault->read($name);
            if (isset($item['metadata']) || isset($item['error'])) continue;
            if (++$count>40) break;
            $temp=$this->vault->path('cloudbeds-check-'.bin2hex(random_bytes(16)).'.pdf');
            try {
                $pdf=base64_decode($item['pdf'],true);
                if (!is_string($pdf) || !hash_equals($item['sha256'],hash('sha256',$pdf))) throw new RuntimeException('invalid_document');
                InvoiceVault::atomicWrite($temp,$pdf);
                $item['metadata']=CloudbedsInvoice::read($temp,$nif);
                // Future issue dates are retained for review, never silently reclassified.
                if ($item['metadata']['issued_on']>gmdate('Y-m-d',time()+86400)) throw new RuntimeException('invalid_document');
            } catch (Throwable $e) {
                unset($item['metadata']);
                $item['error']=$e->getMessage()==='account_mismatch'?'account_mismatch':'invalid_document';
            } finally { if (is_file($temp)) unlink($temp); }
            $this->vault->save($name,$item);
        }
    }
    public function collect(array $job): array
    {
        $settings=$this->settings(); $source=array_search((int)$job['account_id'],$settings['account_ids']??[],true);
        if (!is_string($source) || $job['property_id']!==$source || $job['kind']!=='collect') throw new RuntimeException('connector_unconfigured');
        $scan=$this->vault->has('cloudbeds-source-'.$source.'.enc')?$this->vault->read('cloudbeds-source-'.$source.'.enc'):[];
        if ((int)($scan['at']??0)<time()-600 || ($scan['retry']??1)>0 || ($scan['processed']??40)>=40) throw new RuntimeException('network_error');
        $result=['imported'=>0,'duplicates'=>0]; $invalid=false;
        foreach (glob($this->vault->root.'/cloudbeds-inbox-'.$source.'-*.enc')?:[] as $path) {
            $name=basename($path); $item=$this->vault->read($name);
            if (isset($item['error']) || !isset($item['metadata'])) { $invalid=true; continue; }
            $m=$item['metadata']; if ($m['period']!==$job['period']) continue;
            $s=$this->pdo->prepare('SELECT invoice_number,sha256 FROM invoice_documents WHERE account_id=? AND property_id=? AND (invoice_number=? OR sha256=?)');
            $s->execute([$job['account_id'],$source,$m['number'],$item['sha256']]); $old=$s->fetchAll(PDO::FETCH_ASSOC);
            if ($old) {
                foreach ($old as $d) if ($d['invoice_number']===$m['number'] && $d['sha256']!==$item['sha256']) throw new RuntimeException('invoice_conflict');
                $result['duplicates']++;
                $column='duplicate_count';
            } else {
                if (!isset($item['pdf'])) throw new RuntimeException('invalid_document');
                (new InvoiceService($this->pdo))->import($this->vault,$job,$m+['content'=>$item['pdf']]);
                $result['imported']++;
                $column='imported_count';
            }
            $this->pdo->prepare("UPDATE invoice_tasks SET $column=$column+1 WHERE id=?")->execute([$job['id']]);
            // Retain provenance and parsed identity; normal Drive cleanup owns the PDF.
            unset($item['pdf']); $item['imported']=true; $this->vault->save($name,$item);
        }
        if ($invalid) throw new RuntimeException('verification_failed');
        return $result;
    }
    public function run(array $config): void
    {
        if (!$this->settings()) return;
        $this->index();
        $this->bootstrap();
        $service=new InvoiceService($this->pdo); $service->scheduleDue(new DateTimeImmutable('now'));
        $accounts=new InvoiceAccounts($this->pdo); $alerts=new InvoiceAlerts($this->pdo,$config['whatsapp']??[]);
        $s=$this->pdo->prepare("SELECT t.* FROM invoice_tasks t JOIN invoice_accounts a ON a.id=t.account_id WHERE a.portal='cloudbeds' AND t.state IN ('queued','retry','running') AND (t.next_attempt_at IS NULL OR t.next_attempt_at<=?) ORDER BY t.id LIMIT 10");
        $s->execute([InvoiceService::utcNow()]);
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $job) {
            if (!$accounts->active((int)$job['account_id']) || !isset($accounts->activeProperties((int)$job['account_id'])[$job['property_id']])) {
                $this->pdo->prepare("UPDATE invoice_tasks SET state='cancelled',active_key=NULL,finished_at=? WHERE id=?")->execute([InvoiceService::utcNow(),$job['id']]); continue;
            }
            $job['attempts']=(int)$job['attempts']+1;
            $this->pdo->prepare("UPDATE invoice_tasks SET state='running',attempts=?,started_at=? WHERE id=?")->execute([$job['attempts'],InvoiceService::utcNow(),$job['id']]);
            try {
                $r=$this->collect($job); $code=$r['imported']+$r['duplicates']>0?'ok':'no_invoices';
                $this->pdo->prepare("UPDATE invoice_tasks SET state='completed',result_code=?,active_key=NULL,finished_at=? WHERE id=?")
                    ->execute([$code,InvoiceService::utcNow(),$job['id']]);
                $this->pdo->prepare("UPDATE invoice_accounts SET status='ready',login_verified_at=? WHERE id=?")->execute([InvoiceService::utcNow(),$job['account_id']]);
            } catch (Throwable $e) { (new InvoiceTaskLifecycle($this->pdo))->fail($job,$e->getMessage(),$alerts); }
        }
        $this->report();
    }
    /** One authorized real previous-month collection followed by a deduplication check. */
    private function bootstrap(): void
    {
        $settings=$this->settings(); $period=$settings['bootstrap_period']??'';
        if (!InvoiceService::validPeriod($period)) return;
        $phase=(int)($settings['bootstrap_phase']??0);
        if ($phase>=2) return;
        foreach ($settings['account_ids'] as $source=>$id) {
            $file='cloudbeds-source-'.$source.'.enc';
            $scan=$this->vault->has($file)?$this->vault->read($file):[];
            if (($scan['at']??0)<time()-600 || ($scan['retry']??1)>0 || ($scan['processed']??40)>=40) return;
            $available=false;
            foreach (glob($this->vault->root.'/cloudbeds-inbox-'.$source.'-*.enc')?:[] as $path) {
                $item=$this->vault->read(basename($path));
                if (($item['metadata']['period']??null)===$period) $available=true;
            }
            if (!$available) return;
        }
        if ($phase===1) {
            $s=$this->pdo->prepare("SELECT t.state FROM invoice_tasks t JOIN invoice_batch_tasks bt ON bt.task_id=t.id WHERE bt.batch_id=?");
            $s->execute([$settings['bootstrap_batch']]); $states=$s->fetchAll(PDO::FETCH_COLUMN);
            if (count($states)!==2 || array_filter($states,static fn($v)=>$v!=='completed')) return;
        }
        $targets=[];
        foreach ($settings['account_ids'] as $source=>$id) $targets[]=['account_id'=>$id,'property_id'=>$source];
        $batch=(new InvoiceService($this->pdo))->collectBatch($targets,$period,null,hash('sha256','cloudbeds-email-acceptance-v1:'.$period.':'.$phase));
        $settings['bootstrap_phase']=$phase+1; $settings['bootstrap_batch']=$batch;
        $this->vault->save('cloudbeds-settings.enc',$settings);
    }
    public function report(): void
    {
        $settings=$this->settings(); if (!$settings) return;
        $facts=['version'=>1,'at'=>time(),'period'=>$settings['bootstrap_period']??null,'sources'=>[]];
        foreach ($settings['account_ids'] as $source=>$id) {
            $counts=['indexed'=>0,'invalid'=>0,'selected'=>0];
            $errors=[];
            foreach (glob($this->vault->root.'/cloudbeds-inbox-'.$source.'-*.enc')?:[] as $path) {
                $item=$this->vault->read(basename($path));
                if (isset($item['metadata'])) $counts['indexed']++;
                if (isset($item['error'])) { $counts['invalid']++; $errors[$item['error']]=($errors[$item['error']]??0)+1; }
                if (($item['metadata']['period']??null)===$facts['period']) $counts['selected']++;
            }
            $s=$this->pdo->prepare("SELECT t.state,t.result_code,t.imported_count,t.duplicate_count FROM invoice_tasks t WHERE t.account_id=? AND t.period=? AND t.kind='collect' ORDER BY t.id DESC LIMIT 2");
            $s->execute([$id,$facts['period']]); $tasks=$s->fetchAll(PDO::FETCH_ASSOC);
            $s=$this->pdo->prepare("SELECT x.drive_state,x.company_state,x.toconline_state,COUNT(*) AS total FROM invoice_documents d LEFT JOIN invoice_document_delivery x ON x.document_id=d.id WHERE d.account_id=? AND d.period=? GROUP BY x.drive_state,x.company_state,x.toconline_state");
            $s->execute([$id,$facts['period']]); $delivery=$s->fetchAll(PDO::FETCH_ASSOC);
            $scanFile='cloudbeds-source-'.$source.'.enc';
            $scan=$this->vault->has($scanFile)?$this->vault->read($scanFile):[];
            $facts['sources'][$source]=$counts+['errors'=>$errors,'scan'=>$scan,'tasks'=>$tasks,'delivery'=>$delivery];
        }
        if (empty($settings['validated_at']) && self::accepted($facts['sources'])) {
            foreach ($settings['account_ids'] as $id) $this->pdo->prepare("UPDATE invoice_accounts SET enabled=1,schedule_day=5,schedule_time='04:00' WHERE id=?")->execute([$id]);
            $settings['validated_at']=InvoiceService::utcNow(); $this->vault->save('cloudbeds-settings.enc',$settings);
        }
        $facts['validated']=!empty($settings['validated_at']);
        InvoiceVault::atomicWrite('/home/welcome/room-check-private/cloudbeds-status.json',json_encode($facts,JSON_THROW_ON_ERROR));
    }
    public static function accepted(array $sources): bool
    {
        if (array_keys($sources)!==['welcome','city']) return false;
        foreach ($sources as $r) {
            if ($r['selected']<1 || $r['invalid']>0 || count($r['tasks'])!==2 || !$r['delivery']) return false;
            foreach ($r['tasks'] as $t) if ($t['state']!=='completed') return false;
            if ((int)$r['tasks'][0]['duplicate_count']<1) return false;
            foreach ($r['delivery'] as $d) if ($d['drive_state']!=='verified' || $d['company_state']!=='validated'
                || !in_array($d['toconline_state'],['toc_accepted','toc_existing','toc_duplicate'],true)) return false;
        }
        return true;
    }
}
