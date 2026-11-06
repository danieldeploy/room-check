<?php
declare(strict_types=1);
require_once __DIR__.'/InvoiceVault.php';

/** Private, bounded local page extraction; no browser, network, rasterisation or shell. */
final class InvoiceReceiptPdf
{
    public function __construct(private readonly InvoiceVault $vault,private readonly array $config) {}
    /** Keep configured runtimes authoritative; only the absent default may use the inspected cPanel Node. */
    public static function nodeCandidates(array $config): array
    {
        $node=(string)($config['node_binary']??''); $paths=[$node];
        if ($node==='/usr/bin/node' && preg_match('~\A(/home/[a-z][a-z0-9_-]{0,31})/room-check-private/invoices\z~',rtrim((string)($config['private_dir']??''),'/'),$m)) {
            $paths[]=$m[1].'/nodevenv/booking-vault-agent/22/bin/node';
        }
        return $paths;
    }
    public function split(string $bytes): array
    {
        $node='';
        foreach (self::nodeCandidates($this->config) as $candidate) {
            if ($candidate!=='' && $candidate[0]==='/' && is_executable($candidate)) { $node=$candidate; break; }
        }
        $script=dirname((string)($this->config['runner_script']??'')).'/receipt-pdf-split.mjs';
        if (strlen($bytes)>10*1024*1024 || !str_starts_with($bytes,'%PDF-')) throw new RuntimeException('receipt_split_failed');
        if (!function_exists('proc_open') || $node==='' || $node[0]!=='/' || !is_executable($node)
            || !is_file($script) || str_contains((string)realpath($script),'/public_html/')) throw new RuntimeException('receipt_split_unavailable');
        $dir=$this->vault->path('receipt-split-'.bin2hex(random_bytes(16)));
        if (!mkdir($dir,0700)) throw new RuntimeException('receipt_split_failed');
        $process=null; $pipes=[];
        try {
            InvoiceVault::atomicWrite($dir.'/source.pdf',$bytes);
            $process=proc_open([$node,'--max-old-space-size=256',$script],
                [0=>['pipe','r'],1=>['pipe','w'],2=>['file','/dev/null','a']],$pipes,dirname($script));
            if (!is_resource($process)) throw new RuntimeException('receipt_split_unavailable');
            fwrite($pipes[0],json_encode(['directory'=>$dir],JSON_THROW_ON_ERROR)); fclose($pipes[0]); unset($pipes[0]);
            stream_set_blocking($pipes[1],false); $out=''; $end=microtime(true)+30;
            do {
                $out.=stream_get_contents($pipes[1]); $status=proc_get_status($process);
                if (!$status['running']) break;
                usleep(10000);
            } while (microtime(true)<$end && strlen($out)<65536);
            if ($status['running']) { proc_terminate($process,9); throw new RuntimeException('receipt_split_failed'); }
            $out.=stream_get_contents($pipes[1]);
            $result=json_decode($out,true,32,JSON_THROW_ON_ERROR);
            if (($result['error']??'')==='receipt_split_limit') throw new RuntimeException('receipt_split_limit');
            $count=$result['count']??0;
            if ($status['exitcode']!==0 || !is_int($count) || $count<1 || $count>50 || !is_array($result['pages']??null)
                || count($result['pages'])!==($count===1?0:$count)) throw new RuntimeException('receipt_split_failed');
            $total=0;
            // Verify the complete manifest before publishing any reusable page files.
            foreach ($result['pages'] as $index=>$page) {
                $file=$dir.'/page-'.($index+1).'.pdf'; $size=is_file($file)?filesize($file):0; $total+=$size;
                if (($page['number']??0)!==$index+1 || $size<5 || $size>10*1024*1024 || $total>50*1024*1024
                    || $size!==($page['size']??0) || is_link($file)
                    || !hash_equals(hash_file('sha256',$file),(string)($page['sha256']??''))
                    || !hash_equals(hash_file('md5',$file),(string)($page['md5Checksum']??''))) throw new RuntimeException('receipt_split_failed');
            }
            foreach ($result['pages'] as $page) InvoiceVault::atomicWrite($this->vault->path('receipt-page-'.$page['sha256'].'.pdf'),(string)file_get_contents($dir.'/page-'.$page['number'].'.pdf'));
            return $result;
        } catch (Throwable $e) {
            if (in_array($e->getMessage(),['receipt_split_failed','receipt_split_limit','receipt_split_unavailable'],true)) throw $e;
            throw new RuntimeException('receipt_split_failed');
        } finally {
            foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
            if (is_resource($process)) proc_close($process);
            foreach (glob($dir.'/*')?:[] as $file) if (is_file($file) && !is_link($file)) unlink($file);
            rmdir($dir);
        }
    }
}
