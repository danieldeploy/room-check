<?php
declare(strict_types=1);
require_once __DIR__.'/InvoiceTocPipe.php';

/** One-off recovery from retained local mail, through the unchanged authenticated pipe. */
final class InvoiceTocReplyRecovery
{
    public static function run(InvoiceVault $vault,string $mailRoot): array
    {
        if (realpath($mailRoot)!==$mailRoot || !is_dir($mailRoot) || is_link($mailRoot)) throw new RuntimeException('toc_recovery_root');
        $state=$vault->has('toconline-recovery.enc')?$vault->read('toconline-recovery.enc'):['seen'=>[]];
        if (!empty($state['complete'])) return ['complete'=>true,'queued'=>0];
        $ledger=$vault->has('toconline-ledger.enc')?$vault->read('toconline-ledger.enc'):[];
        $keys=[];
        foreach ($ledger as $key=>$entry) if (preg_match('/\A[a-f0-9]{64}\z/',(string)$key)
            && str_starts_with((string)($entry['document_id']??''),'receipt:')
            && in_array($entry['state']??'',['toc_submitted','toc_uncertain','toc_sending','toc_no_confirmation','toc_review'],true)) $keys[$key]=true;
        $folders=[$mailRoot];$files=[];$now=time();$scanned=0;
        foreach (new DirectoryIterator($mailRoot) as $file) {
            if (!$file->isDot() && !$file->isLink() && $file->isDir() && str_starts_with($file->getFilename(),'.')) $folders[]=$file->getPathname();
        }
        if (count($folders)>100) throw new RuntimeException('toc_recovery_limit');
        foreach ($folders as $folder) foreach (['cur','new'] as $part) {
            $directory=$folder.'/'.$part;
            if (!is_dir($directory) || realpath($directory)!==$directory) continue;
            foreach (new DirectoryIterator($directory) as $file) {
                if ($file->isDot()) continue;
                if (++$scanned>100000) throw new RuntimeException('toc_recovery_limit');
                if ($file->isLink() || !$file->isFile() || $file->getSize()<1 || $file->getSize()>InvoiceTocPipe::MAX_BYTES
                    || $file->getMTime()<$now-3*86400 || $file->getMTime()>$now+300) continue;
                $files[$file->getPathname()]=$file->getMTime();
            }
        }
        arsort($files);$pipe=new InvoiceTocPipe($vault);$queued=0;$budget=12*1024*1024;$complete=true;
        foreach ($files as $path=>$mtime) {
            if (realpath($path)!==$path || !is_file($path)) continue;
            $raw=file_get_contents($path,false,null,0,InvoiceTocPipe::MAX_BYTES+1);
            if (!is_string($raw) || strlen($raw)>InvoiceTocPipe::MAX_BYTES) continue;
            $hash=hash('sha256',$raw);if (isset($state['seen'][$hash])) continue;
            if (($budget-=strlen($raw))<0 || $queued>=100) {$complete=false;break;}
            try { $parsed=InvoiceTocPipe::parse($raw); }
            catch (RuntimeException) { $parsed=null; }
            if ($parsed!==null) {
                preg_match_all('/booking-([a-f0-9]{64})\.pdf/',$parsed['text'],$m);
                $tokens=array_values(array_unique($m[1]));
                if (count($tokens)===1 && isset($keys[$tokens[0]])) {
                    $pipe->enqueue($raw);$queued++;
                }
            }
            $state['seen'][$hash]=$now;
            if (count($state['seen'])>5000) throw new RuntimeException('toc_recovery_limit');
            $vault->save('toconline-recovery.enc',$state);
            unset($raw);
        }
        $state['complete']=$complete;$state['checked_at']=gmdate('c');$vault->save('toconline-recovery.enc',$state);
        return ['complete'=>$complete,'queued'=>$queued];
    }
}
