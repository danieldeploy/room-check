<?php
declare(strict_types=1);
require_once __DIR__.'/InvoiceVault.php';

/** Private bounded PDF extraction and local QR decoding; no browser, network or shell. */
final class InvoiceReceiptPdf
{
    public function __construct(private readonly InvoiceVault $vault,private readonly array $config,private readonly mixed $diagnostic=null) {}
    /** Keep configured runtimes authoritative; only the absent default may use the inspected cPanel Node. */
    public static function nodeCandidates(array $config): array
    {
        $node=(string)($config['node_binary']??''); $paths=[$node];
        if ($node==='/usr/bin/node' && preg_match('~\A(/home/[a-z][a-z0-9_-]{0,31})/room-check-private/invoices\z~',rtrim((string)($config['private_dir']??''),'/'),$m)) {
            $paths[]=$m[1].'/nodevenv/booking-vault-agent/22/bin/node';
        }
        return $paths;
    }
    public function split(string $bytes, bool $publish = true): array
    { return $this->execute($bytes,$publish,'split'); }
    public function inspect(string $bytes,string $format): array { return $this->execute($bytes,false,'inspect',[],$format); }
    public function extract(string $bytes,array $pages): array { return $this->execute($bytes,true,'extract',$pages); }
    public static function diagnosticCode(string $stderr): string
    {
        if ($stderr==='') return 'none';
        if (stripos($stderr,'WebAssembly')!==false && preg_match('/memory|allocat/i',$stderr)) return 'wasm_memory';
        if (preg_match('/failed to map segment|cannot allocate memory|std::bad_alloc|Fatal process out of memory/i',$stderr)) return 'memory_mapping';
        if (preg_match('/GLIBC|GLIBCXX|CXXABI|cannot open shared object|invalid ELF/i',$stderr)) return 'native_abi';
        if (preg_match('/Cannot find (module|package)|ERR_MODULE_NOT_FOUND|Failed to load native binding/i',$stderr)) return 'missing_dependency';
        if (preg_match('/getBuiltinModule|Unexpected token|Unsupported engine/i',$stderr)) return 'node_version';
        if (preg_match('/EACCES|Permission denied/i',$stderr)) return 'permission';
        return 'unclassified';
    }
    private function execute(string $bytes,bool $publish,string $operation,array $pages=[],string $format='pdf'): array
    {
        $node='';
        foreach (self::nodeCandidates($this->config) as $candidate) {
            if ($candidate!=='' && $candidate[0]==='/' && is_executable($candidate)) { $node=$candidate; break; }
        }
        $script=dirname((string)($this->config['runner_script']??'')).'/receipt-pdf-split.mjs';
        if (strlen($bytes)>10*1024*1024 || ($format==='pdf' && !str_starts_with($bytes,'%PDF-'))) throw new RuntimeException('receipt_split_failed');
        if (!function_exists('proc_open') || $node==='' || $node[0]!=='/' || !is_executable($node)
            || !is_file($script) || str_contains((string)realpath($script),'/public_html/')) throw new RuntimeException('receipt_split_unavailable');
        $dir=$this->vault->path('receipt-split-'.bin2hex(random_bytes(16)));
        if (!mkdir($dir,0700)) throw new RuntimeException('receipt_split_failed');
        $process=null; $pipes=[];
        try {
            InvoiceVault::atomicWrite($dir.'/source.pdf',$bytes);
            $process=proc_open([$node,'--max-old-space-size=512',$script],
                [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,dirname($script));
            if (!is_resource($process)) throw new RuntimeException('receipt_split_unavailable');
            fwrite($pipes[0],json_encode(['directory'=>$dir,'operation'=>$operation,'pages'=>$pages,'format'=>$format],JSON_THROW_ON_ERROR)); fclose($pipes[0]); unset($pipes[0]);
            stream_set_blocking($pipes[1],false);stream_set_blocking($pipes[2],false); $out='';$err=''; $end=microtime(true)+($operation==='inspect'?120:30);
            do {
                $out.=stream_get_contents($pipes[1]);$err=substr($err.stream_get_contents($pipes[2]),0,16384); $status=proc_get_status($process);
                if (!$status['running']) break;
                usleep(10000);
            } while (microtime(true)<$end && strlen($out)<2*1024*1024);
            if ($status['running']) { proc_terminate($process,9); throw new RuntimeException('receipt_split_failed'); }
            $out.=stream_get_contents($pipes[1]);$err=substr($err.stream_get_contents($pipes[2]),0,16384);
            if (is_callable($this->diagnostic)) {
                $probe=json_decode($out,true);$class=self::diagnosticCode($err);
                if (in_array($probe['diagnostic']??'', ['wasm_memory','memory_mapping','native_abi','missing_dependency','node_version','permission','unclassified'],true)) $class=$probe['diagnostic'];
                $version=preg_match('/\A[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\z/',$probe['node_version']??'')?$probe['node_version']:null;
                ($this->diagnostic)(['operation'=>$operation,'exit_code'=>$status['exitcode'],'stderr_class'=>$class,'node_version'=>$version]);
            }
            $result=json_decode($out,true,32,JSON_THROW_ON_ERROR);
            if (($result['error']??'')==='receipt_split_limit') throw new RuntimeException('receipt_split_limit');
            if ($status['exitcode']!==0) throw new RuntimeException('receipt_split_failed');
            if ($operation==='inspect') {
                $count=$result['count']??0;
                if (!is_int($count) || $count<1 || $count>50 || count($result['pages']??[])!==$count) throw new RuntimeException('receipt_split_failed');
                foreach ($result['pages'] as $i=>$p) {
                    if (($p['number']??0)!==$i+1 || !is_array($p['qr']??null) || count($p['qr'])>8) throw new RuntimeException('receipt_split_failed');
                    foreach ($p['qr'] as $qr) if (!is_string($qr) || strlen($qr)>4096) throw new RuntimeException('receipt_split_failed');
                }
                return $result;
            }
            if ($operation==='extract') {
                $file=$dir.'/document.pdf'; $size=is_file($file)?filesize($file):0;
                if (($result['pages']??[])!==$pages || $size<5 || $size>10*1024*1024 || $size!==($result['size']??0) || is_link($file)
                    || !hash_equals(hash_file('sha256',$file),(string)($result['sha256']??''))
                    || !hash_equals(hash_file('md5',$file),(string)($result['md5Checksum']??''))) throw new RuntimeException('receipt_split_failed');
                InvoiceVault::atomicWrite($this->vault->path('receipt-page-'.$result['sha256'].'.pdf'),(string)file_get_contents($file));
                return $result;
            }
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
            if ($publish) foreach ($result['pages'] as $page) InvoiceVault::atomicWrite($this->vault->path('receipt-page-'.$page['sha256'].'.pdf'),(string)file_get_contents($dir.'/page-'.$page['number'].'.pdf'));
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
