<?php
declare(strict_types=1);
require_once __DIR__.'/CloudbedsMailMessage.php';

/** Read-only Maildir traversal. State contains hashes and timestamps, never messages. */
final class CloudbedsFolderQueue
{
    public static function run(string $root,string $stateRoot,callable $consume): array
    {
        if (realpath($root)!==$root || realpath($stateRoot)!==$stateRoot) throw new RuntimeException('folder_invalid');
        $statePath=$stateRoot.'/cloudbeds-folder-state.json'; $lockPath=$stateRoot.'/cloudbeds-folder.lock';
        if (is_link($statePath) || is_link($lockPath)) throw new RuntimeException('state_invalid');
        $lock=fopen($lockPath,'c'); if (!$lock) throw new RuntimeException('state_invalid');
        chmod($lockPath,0600);
        try {
            if (!flock($lock,LOCK_EX|LOCK_NB)) return ['busy'=>true];
            $state=[];
            if (is_file($statePath)) {
                if ((fileperms($statePath)&0077)!==0 || filesize($statePath)>1048576) throw new RuntimeException('state_invalid');
                $state=json_decode(file_get_contents($statePath),true,8,JSON_THROW_ON_ERROR);
                if (!is_array($state)) throw new RuntimeException('state_invalid');
            }
            $files=[]; $folders=0; $scanned=0;
            foreach (new DirectoryIterator($root) as $folder) {
                if (!$folder->isDir() || $folder->isLink() || !preg_match('/\A\.(?:INBOX\.)?CLOUDBEDS\z/i',$folder->getFilename())) continue;
                if (++$folders>2) throw new RuntimeException('folder_invalid');
                foreach (['new','cur'] as $part) {
                    $dir=$folder->getPathname().'/'.$part;
                    if (!is_dir($dir) || realpath($dir)!==$dir) continue;
                    foreach (new DirectoryIterator($dir) as $file) {
                        if ($file->isDot()) continue;
                        if (++$scanned>10000) throw new RuntimeException('document_limit');
                        if ($file->isLink() || !$file->isFile() || $file->getSize()<1 || $file->getSize()>CloudbedsMailMessage::MAX_BYTES
                            || $file->getMTime()<time()-730*86400) continue;
                        $files[$file->getPathname()]=$file->getMTime();
                    }
                }
            }
            if (!$folders) throw new RuntimeException('folder_unavailable');
            arsort($files); $attempts=0; $done=0; $failed=0;
            foreach ($files as $path=>$mtime) {
                // Maildir filenames may change on read. Contents, not flags/names, identify mail.
                if (realpath($path)!==$path || !is_file($path)) continue;
                $raw=file_get_contents($path,false,null,0,CloudbedsMailMessage::MAX_BYTES+1);
                if (!is_string($raw) || strlen($raw)>CloudbedsMailMessage::MAX_BYTES) continue;
                $hash=hash('sha256',$raw); $old=$state[$hash]??[];
                if (($old['done']??false)===true || (int)($old['retry_at']??0)>time()) continue;
                if (++$attempts>40) break;
                try { $accepted=$consume($raw)===true; } catch (Throwable) { $accepted=false; }
                unset($raw);
                $tries=(int)($old['attempts']??0)+1;
                $state[$hash]=['done'=>$accepted,'attempts'=>$tries,
                    'retry_at'=>$accepted?0:time()+min(10800,60*(2**min(8,$tries-1)))];
                $accepted?$done++:$failed++;
                if (count($state)>5000) throw new RuntimeException('document_limit');
            }
            $tmp=$statePath.'.'.bin2hex(random_bytes(8));
            try {
                if (file_put_contents($tmp,json_encode($state,JSON_THROW_ON_ERROR),LOCK_EX)===false
                    || !chmod($tmp,0600) || !rename($tmp,$statePath)) throw new RuntimeException('state_invalid');
            } finally { if (is_file($tmp)) unlink($tmp); }
            $pending=count(array_filter($state,static fn(array $entry):bool=>($entry['done']??false)!==true));
            return ['folder_found'=>true,'processed'=>$done,'retry'=>$pending];
        } finally { flock($lock,LOCK_UN); fclose($lock); }
    }
}
