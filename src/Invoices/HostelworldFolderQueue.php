<?php
declare(strict_types=1);
/** Reads only recent files in dedicated local Hostelworld folders. Never moves mail. */
final class HostelworldFolderQueue
{
    public static function run(string $mailRoot,string $stateRoot,callable $consume): void
    {
        $mailRoot=rtrim($mailRoot,'/'); $stateRoot=rtrim($stateRoot,'/');
        if (realpath($mailRoot)!==$mailRoot || realpath($stateRoot)!==$stateRoot) throw new RuntimeException('folder_invalid');
        $lockPath=$stateRoot.'/hostelworld-city-folder.lock';
        $statePath=$stateRoot.'/hostelworld-city-folder-state.json';
        if (is_link($lockPath) || is_link($statePath)) throw new RuntimeException('state_invalid');
        $previousMask=umask(0077);
        $lock=fopen($lockPath,'c');
        if (!$lock) { umask($previousMask); throw new RuntimeException('lock_unavailable'); }
        try {
            chmod($lockPath,0600);
            if (!flock($lock,LOCK_EX|LOCK_NB)) return;
            $now=time(); $seen=[];
            if (is_file($statePath)) {
                if ((fileperms($statePath)&0077)!==0 || filesize($statePath)>16384) throw new RuntimeException('state_invalid');
                $saved=json_decode(file_get_contents($statePath),true);
                if (!is_array($saved)) throw new RuntimeException('state_invalid');
                foreach ($saved as $hash=>$at) if (is_string($hash) && preg_match('/\A[a-f0-9]{64}\z/',$hash)
                    && is_int($at) && $at>=$now-180 && $at<=$now) $seen[$hash]=$at;
            }
            $candidates=[]; $folders=glob($mailRoot.'/*/*/.HOSTELWORLD',GLOB_ONLYDIR)?:[];
            if (count($folders)>32) throw new RuntimeException('folder_limit');
            foreach ($folders as $folder) foreach (['new','cur'] as $part) {
                $directory=$folder.'/'.$part;
                if (!is_dir($directory) || realpath($directory)!==$directory) continue;
                $scanned=0;
                foreach (new DirectoryIterator($directory) as $file) {
                    if ($file->isDot()) continue;
                    if (++$scanned>10000) throw new RuntimeException('scan_limit');
                    if ($file->isLink() || !$file->isFile() || $file->getMTime()<$now-150
                        || $file->getMTime()>$now+30 || $file->getSize()<1 || $file->getSize()>262144) continue;
                    $candidates[$file->getPathname()]=$file->getMTime();
                }
            }
            arsort($candidates);
            foreach (array_slice($candidates,0,40,true) as $file=>$at) {
                if (is_link($file) || realpath($file)!==$file) continue;
                $handle=fopen($file,'rb');
                if (!$handle) continue;
                try { $raw=stream_get_contents($handle,262145); } finally { fclose($handle); }
                if (!is_string($raw) || strlen($raw)>262144) continue;
                $hash=hash('sha256',$raw);
                if (isset($seen[$hash])) continue;
                // Callback retains the existing sender, subject, age, link and account checks.
                if ($consume($raw)===true) $seen[$hash]=$now;
                unset($raw);
            }
            arsort($seen); $seen=array_slice($seen,0,100,true);
            $temporary=$statePath.'.'.bin2hex(random_bytes(8));
            try {
                if (file_put_contents($temporary,json_encode($seen,JSON_THROW_ON_ERROR),LOCK_EX)===false
                    || !chmod($temporary,0600) || !rename($temporary,$statePath)) throw new RuntimeException('state_write_failed');
            } finally { if (is_file($temporary)) unlink($temporary); }
        } finally { flock($lock,LOCK_UN); fclose($lock); umask($previousMask); }
    }
}
