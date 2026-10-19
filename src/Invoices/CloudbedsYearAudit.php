<?php
declare(strict_types=1);
require_once __DIR__.'/InvoiceService.php';
require_once __DIR__.'/InvoiceToconline.php';

/** Owner-requested 2026 recovery. Caller holds the existing invoice worker lock. */
final class CloudbedsYearAudit
{
    private const FILE='cloudbeds-year-audit-2026.enc';
    public function __construct(private readonly PDO $pdo,private readonly InvoiceVault $vault) {}

    public function run(array $settings): void
    {
        if (array_keys($settings['account_ids']??[])!==['welcome','city']) return;
        if (!$this->vault->has(self::FILE)) {
            $through=min('2026-12',(new DateTimeImmutable('now',new DateTimeZone('Europe/Lisbon')))->format('Y-m'));
            if ($through<'2026-01' || $through<=gmdate('Y-m',time()-730*86400)) return;
            $before=$this->summary($settings,$through);
            if (!$before['scans_ready'] || $before['unindexed']>0) return;
            // Preserve the original position before any collection or delivery mutation.
            $this->vault->save(self::FILE,['through'=>$through,'before'=>$before,'batches'=>[]]);
        }
        $state=$this->vault->read(self::FILE);
        foreach ($state['before']['months'] as $period=>$sources) {
            if (isset($state['batches'][$period])) continue;
            $targets=[];
            foreach ($sources as $source=>$row) if ($row['found']>0) {
                $id=(int)$settings['account_ids'][$source];$accounts=new InvoiceAccounts($this->pdo);
                if ($accounts->get($id)['portal']!=='cloudbeds' || !$accounts->active($id)
                    || !isset($accounts->activeProperties($id)[$source])) return;
                $targets[]=['account_id'=>$id,'property_id'=>$source];
            }
            if (!$targets) continue;
            // collectBatch is transactional and reuses this key after a crash before vault save.
            $state['batches'][$period]=(new InvoiceService($this->pdo))->collectBatch($targets,$period,null,
                hash('sha256','cloudbeds-year-audit-2026-v1:'.$period));
            $this->vault->save(self::FILE,$state);
        }
    }

    public function report(array $settings): array
    {
        if (!$this->vault->has(self::FILE)) return ['started'=>false,'complete'=>false];
        $state=$this->vault->read(self::FILE);
        if (isset($state['result'])) return $state['result'];
        $current=$this->summary($settings,$state['through']);
        $complete=$current['scans_ready'] && $current['review']===0 && $current['invalid']===0 && $current['unindexed']===0;
        $tasks=['completed'=>0,'pending'=>0,'failed'=>0];
        foreach ($state['before']['months'] as $period=>$sources) {
            $expected=count(array_filter($sources,static fn($r)=>$r['found']>0));
            if (!$expected) continue;
            $q=$this->pdo->prepare('SELECT t.state FROM invoice_tasks t JOIN invoice_batch_tasks bt ON bt.task_id=t.id WHERE bt.batch_id=?');
            $q->execute([$state['batches'][$period]??0]);$rows=$q->fetchAll(PDO::FETCH_COLUMN);
            if (count($rows)!==$expected) $complete=false;
            foreach ($rows as $status) {
                $tasks[$status==='completed'?'completed':(in_array($status,['failed','cancelled'],true)?'failed':'pending')]++;
                if ($status!=='completed') $complete=false;
            }
        }
        foreach ($current['months'] as $sources) foreach ($sources as $row) {
            if ($row['missing']>0 || $row['registered']!==$row['drive_verified'] || $row['registered']!==$row['company_validated']
                || $row['registered']!==$row['accepted']+$row['existing']+$row['duplicate_confirmed']) $complete=false;
        }
        $result=['started'=>true,'complete'=>$complete,'through'=>$state['through'],'before'=>$state['before'],'current'=>$current,'tasks'=>$tasks];
        if ($complete) {
            $state['result']=$result;$state['completed_at']=InvoiceService::utcNow();
            $this->vault->save(self::FILE,$state);
        }
        return $result;
    }

    private function summary(array $settings,string $through): array
    {
        $facts=['scans_ready'=>true,'review'=>0,'invalid'=>0,'unindexed'=>0,'months'=>[]];
        $ledger=$this->vault->has('toconline-ledger.enc')?$this->vault->read('toconline-ledger.enc'):[];
        $toc=$this->vault->has('toconline-settings.enc')?$this->vault->read('toconline-settings.enc'):[];
        foreach ($settings['account_ids'] as $source=>$id) {
            $scanFile='cloudbeds-source-'.$source.'.enc';$scan=$this->vault->has($scanFile)?$this->vault->read($scanFile):[];
            if (($scan['at']??0)<time()-600 || ($scan['retry']??1)>0 || ($scan['processed']??40)>=40) $facts['scans_ready']=false;
            $facts['review']+=(int)($scan['review']??0);
            $q=$this->pdo->prepare("SELECT d.*,x.drive_state,x.company_state,x.toconline_state FROM invoice_documents d
                LEFT JOIN invoice_document_delivery x ON x.document_id=d.id
                JOIN invoice_accounts a ON a.id=d.account_id WHERE a.portal='cloudbeds' AND d.account_id=? AND d.property_id=?
                AND d.issued_on>=? AND d.issued_on<?");
            $end=(new DateTimeImmutable($through.'-01'))->modify('+1 month')->format('Y-m-d');
            $q->execute([$id,$source,'2026-01-01',$end]);$documents=$q->fetchAll(PDO::FETCH_ASSOC);
            $byNumber=[];$byHash=[];
            for ($month=1;$month<=(int)substr($through,5,2);$month++) {
                $facts['months'][sprintf('2026-%02d',$month)][$source]=array_fill_keys([
                    'found','missing','registered','drive_verified','company_validated','accepted','existing','duplicate_confirmed',
                    'submitted','uncertain','rejected','pending'],0);
            }
            foreach ($documents as $d) {
                $period=substr($d['issued_on'],0,7);$row=&$facts['months'][$period][$source];
                $row['registered']++;$row['drive_verified']+=(int)($d['drive_state']==='verified');
                $row['company_validated']+=(int)($d['company_state']==='validated');
                $status=$d['toconline_state'];
                if ($status==='toc_duplicate') {
                    [$identity,$content]=InvoiceToconline::keys($d+['portal'=>'cloudbeds'],(string)($toc['nif']??''));
                    $a=$ledger[$identity]??null;$b=$ledger[$content]??null;
                    // A duplicate of an uncertain submission is not an archive confirmation.
                    $bucket=$a && $a===$b && in_array($a['state'],['toc_accepted','toc_existing'],true)?'duplicate_confirmed':'uncertain';
                } else $bucket=match($status) {
                    'toc_accepted'=>'accepted','toc_existing'=>'existing','toc_submitted'=>'submitted',
                    'toc_uncertain','toc_sending','toc_review','toc_no_confirmation'=>'uncertain',
                    'toc_rejected'=>'rejected',default=>'pending',
                };
                $row[$bucket]++;unset($row);
                $byNumber[$d['invoice_number']]=true;$byHash[$d['sha256']]=true;
            }
            foreach (glob($this->vault->root.'/cloudbeds-inbox-'.$source.'-*.enc')?:[] as $path) {
                $item=$this->vault->read(basename($path));$m=$item['metadata']??[];
                if (isset($item['error'])) { $facts['invalid']++;continue; }
                if (!$m) { $facts['unindexed']++;continue; }
                $period=substr((string)($m['issued_on']??''),0,7);
                if (!isset($facts['months'][$period][$source])) continue;
                $facts['months'][$period][$source]['found']++;
                if (!isset($byNumber[$m['number']]) && !isset($byHash[$item['sha256']])) $facts['months'][$period][$source]['missing']++;
            }
        }
        ksort($facts['months']);return $facts;
    }
}
