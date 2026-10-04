<?php
declare(strict_types=1);
require_once __DIR__.'/InvoiceVault.php';

/** CLI mail delivery -> bounded encrypted queue -> existing conservative receipt reconciliation. */
final class InvoiceTocPipe
{
    public const MAX_BYTES=262144;
    public function __construct(private readonly InvoiceVault $vault) {}

    private static function split(string $raw): array
    {
        $parts=preg_split('/\r?\n\r?\n/',$raw,2);
        if (count($parts)!==2 || strlen($parts[0])>32768) throw new RuntimeException('toc_pipe_invalid');
        $header=preg_replace('/\r?\n[ \t]+/',' ',$parts[0]); $headers=[];
        foreach (preg_split('/\r?\n/',$header) as $line) {
            if (!preg_match('/\A([A-Za-z0-9-]+):[ \t]*(.*)\z/',$line,$m)) throw new RuntimeException('toc_pipe_invalid');
            $key=strtolower($m[1]); $headers[$key][]=$m[2];
        }
        return [$headers,$parts[1]];
    }

    private static function text(string $raw,int $depth,int &$parts): string
    {
        if ($depth>5 || ++$parts>32) throw new RuntimeException('toc_pipe_invalid');
        [$h,$body]=self::split($raw);
        if (count($h['content-type']??[])>1 || count($h['content-transfer-encoding']??[])>1) throw new RuntimeException('toc_pipe_invalid');
        $type=$h['content-type'][0]??'text/plain';
        if (preg_match('/\A\s*attachment\b/i',$h['content-disposition'][0]??'')) return '';
        if (preg_match('/\A\s*multipart\//i',$type)) {
            if (!preg_match('/(?:^|;)\s*boundary\s*=\s*(?:"([^"\r\n]{1,200})"|([^;\s]{1,200}))/i',$type,$m)) throw new RuntimeException('toc_pipe_invalid');
            $boundary=$m[1]!==''?$m[1]:$m[2];
            $chunks=preg_split('/(?:\A|\r?\n)--'.preg_quote($boundary,'/').'(--)?[ \t]*(?:\r?\n|\z)/',$body,-1,PREG_SPLIT_DELIM_CAPTURE);
            if (count($chunks)<3) throw new RuntimeException('toc_pipe_invalid');
            $text='';
            // Parse MIME delimiter lines explicitly, excluding preamble and epilogue.
            $lines=preg_split('/\r?\n/',$body); $active=false; $closed=false; $part='';
            foreach ($lines as $line) {
                if (rtrim($line)==='--'.$boundary || rtrim($line)==='--'.$boundary.'--') {
                    if ($active) $text.=self::text($part,$depth+1,$parts)."\n";
                    $part=''; $active=true;
                    if (rtrim($line)==='--'.$boundary.'--') { $closed=true; break; }
                } elseif ($active) $part.=$line."\r\n";
            }
            if (!$closed) throw new RuntimeException('toc_pipe_invalid');
            return $text;
        }
        if (!preg_match('/\A\s*text\/(?:plain|html)(?:\s*;|\s*\z)/i',$type)) return '';
        $encoding=strtolower(trim($h['content-transfer-encoding'][0]??'7bit'));
        if ($encoding==='base64') { $body=base64_decode(preg_replace('/\s+/','',$body),true); if ($body===false) throw new RuntimeException('toc_pipe_invalid'); }
        elseif ($encoding==='quoted-printable') $body=quoted_printable_decode($body);
        elseif (!in_array($encoding,['7bit','8bit','binary'],true)) throw new RuntimeException('toc_pipe_invalid');
        if (preg_match('/;\s*charset\s*=\s*"?([a-zA-Z0-9_-]+)/i',$type,$m)) {
            try { $body=mb_convert_encoding($body,'UTF-8',$m[1]); } catch (ValueError) { throw new RuntimeException('toc_pipe_invalid'); }
        }
        return html_entity_decode(strip_tags($body),ENT_QUOTES|ENT_HTML5,'UTF-8');
    }

    /** Store only correlation tokens and a classification hint, never the original mail or attachments. */
    public static function parse(string $raw): ?array
    {
        if (strlen($raw)>self::MAX_BYTES || str_contains($raw,"\0")) throw new RuntimeException('toc_pipe_invalid');
        [$headers]=self::split($raw); $from=$headers['from']??[];
        if (count($from)!==1 || !preg_match('/\A\s*(?:[^<>]*<no_reply@toconline\.pt>|no_reply@toconline\.pt)\s*\z/i',$from[0])) return null;
        $hash=hash('sha256',$raw);
        try { $parts=0; $text=self::text($raw,0,$parts); }
        catch (RuntimeException) { return ['from'=>'no_reply@toconline.pt','text'=>'','hash'=>$hash]; }
        $references=implode(' ',array_merge($headers['references']??[],$headers['in-reply-to']??[]));
        preg_match_all('/(?:booking[-_]|toc-)([a-f0-9]{64})(?:\.pdf|@check\.welcomehostel\.pt)/i',$references."\n".$text,$m);
        $tokens=array_unique(array_map('strtolower',$m[1]));
        // Too many correlations are ambiguous, never truncate to a seemingly exact match.
        $safe=count($tokens)>20?'':implode("\n",array_map(static fn($key)=>'booking-'.$key.'.pdf',$tokens));
        if (str_contains(mb_strtolower($text),'por já constarem no seu arquivo')) $safe.="\npor já constarem no seu arquivo";
        return ['from'=>'no_reply@toconline.pt','text'=>$safe,'hash'=>$hash];
    }

    public function enqueue(string $raw): bool
    {
        $receipt=self::parse($raw); if ($receipt===null) return false;
        $lock=fopen($this->vault->path('toc-pipe.lock'),'c');
        if (!$lock) throw new RuntimeException('toc_pipe_queue');
        try {
            if (!flock($lock,LOCK_EX|LOCK_NB)) throw new RuntimeException('toc_pipe_queue');
            $name='toc-pipe-'.$receipt['hash'].'.enc';
            if ($this->vault->has($name)) return true;
            if (count(glob($this->vault->root.'/toc-pipe-*.enc')?:[])>=200) throw new RuntimeException('toc_pipe_queue');
            $receipt['received_at']=gmdate('c'); $this->vault->save($name,$receipt);
            return true;
        } finally { flock($lock,LOCK_UN); fclose($lock); }
    }

    public function status(): array
    {
        $status=$this->vault->has('toconline-pipe-status.enc')?$this->vault->read('toconline-pipe-status.enc'):[];
        return ['pending'=>count(glob($this->vault->root.'/toc-pipe-*.enc')?:[]),
            'matched'=>(int)($status['matched']??0),'unmatched'=>(int)($status['unmatched']??0),
            'checked_at'=>$status['checked_at']??null,'received_at'=>$status['received_at']??null];
    }

    /** Called under room_check_invoices; atomic queue files survive worker/database outages. */
    public function process(object $toc): void
    {
        $status=$this->vault->has('toconline-pipe-status.enc')?$this->vault->read('toconline-pipe-status.enc'):['matched'=>0,'unmatched'=>0,'recent'=>[]];
        foreach (array_slice(glob($this->vault->root.'/toc-pipe-*.enc')?:[],0,50) as $file) {
            $name=basename($file); if (!preg_match('/\Atoc-pipe-([a-f0-9]{64})\.enc\z/',$name,$m)) continue;
            $receipt=$this->vault->read($name);
            if (!hash_equals($m[1],$receipt['hash']??'')) throw new RuntimeException('toc_pipe_queue');
            if (!in_array($receipt['hash'],$status['recent'],true)) {
                $result=$toc->receive($receipt['from'],'',$receipt['text'],$receipt['hash']);
                $status[$result['result']==='matched'?'matched':'unmatched']++;
                $status['recent']=array_slice([...$status['recent'],$receipt['hash']],-250);
                $status['received_at']=$receipt['received_at']; $status['checked_at']=gmdate('c');
                $this->vault->save('toconline-pipe-status.enc',$status);
            }
            if (!unlink($this->vault->path($name))) throw new RuntimeException('toc_pipe_queue');
        }
    }
}
