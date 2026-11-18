<?php
declare(strict_types=1);
require_once __DIR__.'/InvoiceTocPipe.php';

/** The invoice lock covers the cursor, encrypted queue processing and delivery reconciliation. */
final class InvoiceTocReplyRecovery
{
    private const FILE='toconline-recovery.enc';
    private const OPEN=['toc_sending','toc_submitted','toc_uncertain','toc_no_confirmation','toc_review'];
    private const WINDOW=30*86400; // Same age ceiling as the DKIM verifier.

    private static function state(InvoiceVault $vault): array
    {
        return $vault->has(self::FILE)?$vault->read(self::FILE):[];
    }

    /** The worker drains the live queue independently after this retained mailbox pass. */
    public static function queueDue(InvoiceVault $vault): bool
    {
        $state=self::state($vault);
        $mailRoot=$state['mail_root']??'';
        if ($mailRoot==='') return false; // Await the guarded release's route before ageing submissions.
        if (!empty($state['complete'])) {
            $due=false;$now=time();
            foreach ($vault->has('toconline-ledger.enc')?$vault->read('toconline-ledger.enc'):[] as $entry) {
                $at=strtotime((string)($entry['attempted_at']??''));
                if (!$at || !in_array($entry['state']??'',self::OPEN,true)) continue;
                $deadline=$at+(str_starts_with((string)$entry['document_id'],'receipt:')?1800:72*3600);
                if ($deadline<=$now && $deadline>(int)($state['covered_until']??0)) { $due=true;break; }
            }
            if (!$due && ($state['correlation_version']??0)===InvoiceTocPipe::CORRELATION_VERSION) return true;
        }
        return self::run($vault,$mailRoot,true)['complete'];
    }

    /** A fresh late-mail check is mandatory for every manual resend, even after expiry.
     * Never enter the irreversible handoff if evidence or a recovery page is still pending. */
    public static function beforeResend(InvoiceVault $vault,object $toc): void
    {
        try {
            $pipe=new InvoiceTocPipe($vault);
            $pipe->process($toc);
            $state=self::state($vault);
            $mailRoot=$state['mail_root']??'';
            if ($mailRoot==='' || $pipe->status()['pending']>0) throw new RuntimeException('toc_reply_check_pending');
            // If a previous pass yielded, finish it first and inspect new arrivals once more.
            $passes=empty($state['complete'])?2:1;
            for ($i=0;$i<$passes;$i++) {
                $pass=self::run($vault,$mailRoot,true);
                $pipe->process($toc);
                if (!$pass['complete'] || $pipe->status()['pending']>0) throw new RuntimeException('toc_reply_check_pending');
            }
        } catch (Throwable $e) {
            throw new RuntimeException('toc_reply_check_pending',0,$e);
        }
    }

    /** One page. The original is read privately and re-enters the same cryptographic verifier
     * as live mail. Only a completed page may advance past its last inspected candidate. */
    public static function run(InvoiceVault $vault,string $mailRoot,bool $fresh=false): array
    {
        if (realpath($mailRoot)!==$mailRoot || !is_dir($mailRoot) || is_link($mailRoot)) throw new RuntimeException('toc_recovery_root');
        $state=self::state($vault);
        if (($state['correlation_version']??0)!==InvoiceTocPipe::CORRELATION_VERSION || ($state['mail_root']??'')!==$mailRoot) {
            $sender=$vault->has('toconline-settings.enc')?($vault->read('toconline-settings.enc')['sender']??''):'';
            $state=['mail_root'=>$mailRoot,'sender'=>$sender,'correlation_version'=>InvoiceTocPipe::CORRELATION_VERSION,'seen'=>[]];
            $vault->save(self::FILE,$state); // The worker can resume even if the release process exits.
        }
        if (!empty($state['sender']) && (!$vault->has('toconline-settings.enc')
            || ($vault->read('toconline-settings.enc')['sender']??'')!==$state['sender'])) throw new RuntimeException('toc_recovery_root');
        if (!empty($state['complete']) && !$fresh) return ['complete'=>true,'queued'=>0];
        $now=time();$keys=[];$batchKeys=[];$oldest=$now;
        if (($state['retry_after']??0)>$now) throw new RuntimeException('toc_auth_dns_unavailable');
        unset($state['retry_after']);
        $knownSent=false;
        foreach ($vault->has('toconline-ledger.enc')?$vault->read('toconline-ledger.enc'):[] as $key=>$entry) {
            if (!preg_match('/\A[a-f0-9]{64}\z/',(string)$key) || !in_array($entry['state']??'',self::OPEN,true)) continue;
            foreach ([...($entry['previous_attempts']??[]),$entry] as $attempt) {
                $wireKey=$attempt['attachment_key']??$key;
                if (!preg_match('/\A[a-f0-9]{64}\z/',(string)$wireKey)) continue;
                $keys[$wireKey]=true;
                if (!empty($attempt['email_id']) && $attempt['email_id']!==$wireKey) $batchKeys[$wireKey]=true;
                $sent=strtotime((string)($attempt['attempted_at']??''));
                if ($sent) { $oldest=min($oldest,$sent);$knownSent=true; }
            }
        }
        if (!isset($state['started'])) {
            $state['started']=$now;
            $state['since']=max($now-self::WINDOW,($knownSent?$oldest:$now-3*86400)-300,(int)($state['covered_until']??0)-300);
            $state['complete']=false;
            $state['seen']=array_filter($state['seen'],static fn($at)=>$at>=$now-self::WINDOW-300);
            $state['recent_files']=array_filter($state['recent_files']??[],static fn($mtime)=>$mtime>=$state['since']);
        }
        $files=$keys?self::files($mailRoot,$state['since'],$state['started']+300):[];
        $pipe=new InvoiceTocPipe($vault);$budget=12*1024*1024;$queued=0;$read=0;$complete=true;
        foreach ($files as $path=>$mtime) {
            if (isset($state['cursor']) && [$mtime,$path]<=$state['cursor']) continue;
            clearstatcache(true,$path);
            if (realpath($path)!==$path || !is_file($path)) continue;
            $stat=stat($path);
            if (!$stat) throw new RuntimeException('toc_recovery_root');
            $size=$stat['size'];
            $fileId=hash('sha256',implode('|',[$stat['dev'],$stat['ino'],$size,$stat['mtime'],$stat['ctime']]));
            if (isset($state['recent_files'][$fileId]) || $size>InvoiceTocPipe::MAX_BYTES) continue;
            if ($size>$budget || $read>=250 || $queued>=50) { $complete=false;break; }
            $budget-=$size;$read++;
            $raw=file_get_contents($path,false,null,0,InvoiceTocPipe::MAX_BYTES+1);
            if ($raw===false) {
                if (is_file($path)) throw new RuntimeException('toc_recovery_root');
                continue; // A concurrent Maildir flag change moved this copy.
            }
            $hash=hash('sha256',$raw);
            $discarded=false;
            if (!isset($state['seen'][$hash]) && strlen($raw)<=InvoiceTocPipe::MAX_BYTES) {
                try { $parsed=InvoiceTocPipe::parse($raw); } catch (RuntimeException) { $parsed=null; }
                $discarded=$parsed===null;
                if ($parsed!==null && self::relevant($parsed,$keys,$batchKeys)) {
                    try { $pipe->enqueue($raw,true); }
                    catch (RuntimeException $e) {
                        if ($e->getMessage()==='toc_auth_dns_unavailable') $state['retry_after']=$now+300;
                        $vault->save(self::FILE,$state); // Resume at the unverified message.
                        throw $e;
                    }
                    $state['seen'][$hash]=$now;$queued++;
                    if (count($state['seen'])>5000) throw new RuntimeException('toc_recovery_limit');
                }
            }
            // The next pass overlaps the last five minutes. Rechecking a busy directory must
            // not repeatedly spend the whole byte budget on the same unrelated mail.
            if ($mtime>=$state['started']-300 && ($discarded || isset($state['seen'][$hash]))) $state['recent_files'][$fileId]=$mtime;
            $state['cursor']=[$mtime,$path];
        }
        if (count($state['recent_files'])>5000) $state['recent_files']=array_slice($state['recent_files'],-5000,null,true);
        if ($complete) {
            $state['covered_until']=$state['started'];
            unset($state['cursor'],$state['since'],$state['started']);
        }
        $state['complete']=$complete;$state['checked_at']=gmdate('c');
        $vault->save(self::FILE,$state);
        return ['complete'=>$complete,'queued'=>$queued];
    }

    /** Selection is permissive for fiscal rows without filenames. Only receive() may bind
     * signed evidence to a sender, buyer, batch, scan or terminal outcome. */
    private static function relevant(array $parsed,array $keys,array $batchKeys): bool
    {
        $batch=$parsed['batch_reply']??[];
        $named=$batch['named']??[];
        if (array_intersect_key($named,count($named)===1?$keys:$batchKeys) || !empty($batch['purchases'])
            || !empty($parsed['purchase_identity']) || !empty($parsed['purchase_identities'])) return true;
        preg_match_all('/booking-([a-f0-9]{64})\.pdf/',$parsed['text'],$m);
        $tokens=array_values(array_unique($m[1]));
        return (count($tokens)===1 && isset($keys[$tokens[0]])) || (bool)array_intersect_key($parsed['attachments']??[],$batchKeys);
    }

    private static function files(string $root,int $since,int $until): array
    {
        $folders=[$root];$files=[];$scanned=0;$readable=false;
        foreach (new DirectoryIterator($root) as $folder) {
            if (!$folder->isDot() && !$folder->isLink() && $folder->isDir() && str_starts_with($folder->getFilename(),'.')) $folders[]=$folder->getPathname();
            if (count($folders)>100) throw new RuntimeException('toc_recovery_limit');
        }
        foreach ($folders as $folder) foreach (['cur','new'] as $part) {
            $dir=$folder.'/'.$part;
            if (!is_dir($dir) || realpath($dir)!==$dir) continue;
            $readable=true;
            foreach (new DirectoryIterator($dir) as $file) {
                if ($file->isDot()) continue;
                if (++$scanned>100000) throw new RuntimeException('toc_recovery_limit');
                if ($file->isLink() || !$file->isFile() || $file->getSize()<1 || $file->getSize()>InvoiceTocPipe::MAX_BYTES) continue;
                // Filing an original into this mailbox can preserve its older mtime.
                $mtime=max($file->getMTime(),$file->getCTime());
                if ($mtime >= $since && $mtime <= $until) $files[$file->getPathname()]=$mtime;
            }
        }
        if (!$readable) throw new RuntimeException('toc_recovery_root');
        ksort($files,SORT_STRING);asort($files,SORT_NUMERIC); // Stable path tie-breaker after a yield.
        return $files;
    }
}
