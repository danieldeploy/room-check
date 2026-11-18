<?php
declare(strict_types=1);
require_once __DIR__.'/InvoiceVault.php';
require_once __DIR__.'/InvoiceTocAuthentication.php';
require_once __DIR__.'/InvoiceTocReplyParser.php';

/** CLI mail delivery -> bounded encrypted queue -> existing conservative receipt reconciliation. */
final class InvoiceTocPipe
{
    public const PROVIDER_SENDER='no_reply@toconline.pt';
    public const MAX_BYTES=262144;
    public const CORRELATION_VERSION=3;
    public function __construct(private readonly InvoiceVault $vault) {}

    /** Exim's pipe transport may prepend one Unix mailbox From_ envelope line.
     * It is transport metadata, never evidence of the RFC From sender/authenticity.
     * Strip only the first bounded line, never headers or nested MIME parts.
     */
    private static function withoutEnvelope(string $raw): string
    {
        if (preg_match('/\AFrom [^\r\n\x00]{1,1024}\r?\n/',$raw,$m)) return substr($raw,strlen($m[0]));
        return $raw;
    }

    public static function inputFacts(string $raw): array
    {
        return ['input_bytes'=>strlen($raw),'transport_prefix'=>self::withoutEnvelope($raw)!==$raw];
    }

    /** Parse bounded, untrusted cPanel pipe input and return only decoded text headers. */
    public static function parseMessage(string $raw): array
    {
        if (strlen($raw)>self::MAX_BYTES || str_contains($raw,"\0")) throw new RuntimeException('toc_pipe_invalid');
        $raw=self::withoutEnvelope($raw);
        [$headers]=self::split($raw);
        $parts=0; $text=self::text($raw,0,$parts);
        $one=static function(array $values): string {
            if (count($values)!==1) return '';
            return trim((string)$values[0]);
        };
        $fromHeader=$one($headers['from']??[]);
        if (preg_match('/<([^<>]+)>/',$fromHeader,$m)) $from=trim($m[1]);
        else $from=trim($fromHeader," \t\r\n<>");
        $subject=mb_decode_mimeheader($one($headers['subject']??[]));
        $date=$one($headers['date']??[]);
        $received=$date!=='' ? strtotime($date) : false;
        return ['from'=>$from,'subject'=>$subject,'text'=>$text,
            'received_at'=>$received===false ? 0 : $received,'hash'=>hash('sha256',$raw)];
    }

    private static function split(string $raw): array
    {
        $parts=preg_split('/\r?\n\r?\n/',$raw,2);
        if (count($parts)!==2) throw new RuntimeException('toc_pipe_header_separator');
        if (strlen($parts[0])>32768) throw new RuntimeException('toc_pipe_header_size');
        $header=preg_replace('/\r?\n[ \t]+/',' ',$parts[0]); $headers=[];
        foreach (preg_split('/\r?\n/',$header) as $line) {
            if (!preg_match('/\A([A-Za-z0-9-]+):[ \t]*(.*)\z/',$line,$m)) throw new RuntimeException('toc_pipe_header_line');
            $key=strtolower($m[1]); $headers[$key][]=$m[2];
        }
        return [$headers,$parts[1]];
    }

    private static function text(string $raw,int $depth,int &$parts,?array &$bodies=null): string
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
                    if ($active) $text.=self::text($part,$depth+1,$parts,$bodies)."\n";
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
        $isHtml=preg_match('/\A\s*text\/html/i',$type)===1;
        if ($isHtml) {
            if ($bodies!==null) $body=preg_replace('~<(head|style|script)\b[^>]*>.*?</\1\s*>~is','',$body)??$body;
            $body=preg_replace('~<(?:br\s*/?|/(?:p|li|td|tr|div|h[1-6]))\s*>~i',"\n",$body)??$body;
        }
        $text=html_entity_decode(strip_tags($body),ENT_QUOTES|ENT_HTML5,'UTF-8');
        if ($bodies!==null) $bodies[]=$isHtml?$text:$body;
        return $text;
    }

    /** Store only correlation tokens and a classification hint, never the original mail or attachments. */
    public static function parse(string $raw): ?array
    {
        if (strlen($raw)>self::MAX_BYTES) throw new RuntimeException('toc_pipe_message_size');
        if (str_contains($raw,"\0")) throw new RuntimeException('toc_pipe_message_nul');
        // Bound the original input first. Hash the message without the delivery-time prefix
        // so an Exim retry with a different envelope date cannot duplicate queue processing.
        $raw=self::withoutEnvelope($raw);
        [$headers]=self::split($raw); $from=$headers['from']??[];
        if (count($from)!==1 || !preg_match('/\A\s*(?:[^<>]*<no_reply@toconline\.pt>|no_reply@toconline\.pt)\s*\z/i',$from[0])) return null;
        $hash=hash('sha256',$raw);
        try { $parts=0; $bodies=[]; $text=self::text($raw,0,$parts,$bodies); }
        catch (RuntimeException) { return ['from'=>self::PROVIDER_SENDER,'text'=>'','hash'=>$hash,'correlation_version'=>self::CORRELATION_VERSION]; }
        $references=implode(' ',array_merge($headers['references']??[],$headers['in-reply-to']??[]));
        preg_match_all('/(?:(?:booking|hostelworld|cloudbeds|receipt)[-_]|toc-)([a-f0-9]{64})(?:\.(?:pdf|jpe?g)|@check\.welcomehostel\.pt)/i',$references."\n".$text,$m);
        $tokens=array_unique(array_map('strtolower',$m[1]));
        // Too many correlations are ambiguous, never truncate to a seemingly exact match.
        $safe=count($tokens)>20?'':implode("\n",array_map(static fn($key)=>'booking-'.$key.'.pdf',$tokens));
        if (str_contains(mb_strtolower($text),'por já constarem no seu arquivo')) $safe.="\npor já constarem no seu arquivo";
        $subject=count($headers['subject']??[])===1 ? mb_decode_mimeheader($headers['subject'][0]) : '';
        $batch=[];
        // MIME alternatives describe the same event. Never count them twice or combine
        // contradictory renderings into a seemingly complete provider response.
        foreach ($bodies as $body) {
            $parsed=InvoiceTocReplyParser::read($body,trim($subject));
            if (!$parsed) continue;
            if ($batch && $batch!=$parsed) {
                $batch=['named'=>[],'purchases'=>[],'refusals'=>[],'unassigned'=>1,'complete'=>false,'counts'=>[]];
                break;
            }
            $batch=$parsed;
        }
        $reason='';
        $normalized=preg_replace('/[\s\x{00A0}]+/u',' ',trim($text))??'';
        if (str_contains($normalized,'Foram recusados os seguintes ficheiros, por se encontrarem em formato não suportado:')) $reason='toc_provider_format';
        elseif (str_contains($normalized,'Arquivo digital não foi efetuado porque o total dos ficheiros enviados é superior a 20 MB.')) $reason='toc_provider_size';
        elseif (str_contains($normalized,'Arquivo digital não foi efetuado porque enviou mais de 20 ficheiros.')) $reason='toc_provider_count';
        if ($batch && in_array($reason,['toc_provider_size','toc_provider_count'],true)) {
            // An explicit whole-message failure contradicts individual success.
            $batch=['named'=>[],'purchases'=>[],'refusals'=>[],'unassigned'=>1,'complete'=>false,'counts'=>[]];
        }
        $files=$batch['named']??[];
        $rows=$batch['purchases']??[];
        $outcome='toc_review'; $purchase=[]; $purchases=[]; $attachments=[]; $unsupported=[];
        foreach ($files as $key=>$file) {
            $attachments[$key]=$file['state'];
            if ($file['reason']==='toc_provider_format') $unsupported[]=$key;
        }
        // Keep historical receiver/diagnostic projections only for exact, complete cases.
        if (!empty($batch['complete']) && empty($batch['unassigned'])) {
            if (count($files)===1 && !$rows && count($tokens)===1) $outcome=reset($files)['state'];
            if (!$files && !$tokens && $rows) {
                foreach ($rows as $row) {
                    if ($row['status']!=='toc_accepted' || $row['invoice_number']==='') { $purchases=[]; break; }
                    $purchases[]=['issuer_nif'=>$row['issuer_nif'],'invoice_number'=>$row['invoice_number']];
                }
                if (count($purchases)===1) { $purchase=array_pop($purchases); $outcome='toc_accepted'; }
            }
        }
        return ['from'=>self::PROVIDER_SENDER,'text'=>$safe,'hash'=>$hash,'outcome'=>$outcome,'purchase_identity'=>$purchase,
            'purchase_identities'=>$purchases,'provider_reason'=>$reason,'attachments'=>$attachments,'unsupported_files'=>$unsupported,
            'batch_reply'=>$batch,'correlation_version'=>self::CORRELATION_VERSION];
    }

    public function enqueue(string $raw,bool $retryUnavailableDns=false): bool
    {
        $receipt=self::parse($raw); if ($receipt===null) return false;
        $receipt['authentication']=InvoiceTocAuthentication::verify(self::withoutEnvelope($raw));
        $lock=fopen($this->vault->path('toc-pipe.lock'),'c');
        if (!$lock) throw new RuntimeException('toc_pipe_queue');
        try {
            if (!flock($lock,LOCK_EX|LOCK_NB)) throw new RuntimeException('toc_pipe_queue');
            $name='toc-pipe-'.$receipt['hash'].'.enc';
            $queued=$this->vault->has($name)?$this->vault->read($name):[];
            if ($retryUnavailableDns && ($receipt['authentication']['code']??'')==='dns_unavailable' && empty($queued['authentication']['verified']))
                throw new RuntimeException('toc_auth_dns_unavailable');
            if ($queued) {
                $upgraded=($receipt['correlation_version']??0)>($queued['correlation_version']??0);
                $verified=!empty($receipt['authentication']['verified']);
                if (!$upgraded && (!$verified || !empty($queued['authentication']['verified']))) return true;
                // Same raw SHA-256: preserve an earlier valid signature when DNS now fails.
                if (!empty($queued['authentication']['verified'])) $receipt['authentication']=$queued['authentication'];
                $receipt['received_at']=$queued['received_at'];
                $this->vault->save($name,$receipt);
                return true;
            }
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
            'checked_at'=>$status['checked_at']??null,'received_at'=>$status['received_at']??null,
            'provider_reason'=>$status['provider_reason']??'', 'authentication'=>$status['authentication']??'not_checked','outcome'=>$status['outcome']??'toc_review','authentication_facts'=>$status['authentication_facts']??[]];
    }

    /** Called under room_check_invoices; atomic queue files survive worker/database outages. */
    public function process(object $toc): void
    {
        $status=$this->vault->has('toconline-pipe-status.enc')?$this->vault->read('toconline-pipe-status.enc'):['matched'=>0,'unmatched'=>0,'recent'=>[]];
        $status+=['matched'=>0,'unmatched'=>0,'recent'=>[]];
        foreach (array_slice(glob($this->vault->root.'/toc-pipe-*.enc')?:[],0,50) as $file) {
            $name=basename($file); if (!preg_match('/\Atoc-pipe-([a-f0-9]{64})\.enc\z/',$name,$m)) continue;
            $receipt=$this->vault->read($name);
            if (!hash_equals($m[1],$receipt['hash']??'')) throw new RuntimeException('toc_pipe_queue');
            $event=$receipt['hash'];
            $verifiedEvent=null;
            if (($receipt['correlation_version']??0)>=3) {
                $version=$event.':reply-v'.$receipt['correlation_version'];
                $verifiedEvent=$version.':verified';
                $event=!empty($receipt['authentication']['verified'])?$verifiedEvent:$version.':unverified';
            } elseif (!empty($receipt['purchase_identity'])) $event.=':purchase-v2';
            if (!in_array($event,$status['recent'],true) && !in_array($verifiedEvent,$status['recent'],true)) {
                $result=$toc->receive($receipt['from'],'',$receipt['text'],$receipt['hash'],['authentication'=>$receipt['authentication']??[], 'outcome'=>$receipt['outcome']??'toc_review','purchase_identity'=>$receipt['purchase_identity']??[],'attachments'=>$receipt['attachments']??[],'purchase_identities'=>$receipt['purchase_identities']??[],'provider_reason'=>$receipt['provider_reason']??'','unsupported_files'=>$receipt['unsupported_files']??[],'batch_reply'=>$receipt['batch_reply']??[]]);
                $status[$result['result']==='matched'?'matched':'unmatched']++;
                $status['recent']=array_slice([...$status['recent'],$event],-250);
                $status['authentication']=$receipt['authentication']['code']??'not_checked';
                $status['provider_reason']=!empty($receipt['authentication']['verified'])?($receipt['provider_reason']??''):'';
                $status['authentication_facts']=$receipt['authentication']['facts']??[];
                $status['outcome']=$result['outcome']??'toc_review';
                $status['received_at']=$receipt['received_at']; $status['checked_at']=gmdate('c');
                $this->vault->save('toconline-pipe-status.enc',$status);
            }
            // enqueue() can upgrade this exact item while receive() is running. Only
            // acknowledge the snapshot that actually reached the receiver.
            $lock=fopen($this->vault->path('toc-pipe.lock'),'c');
            if (!$lock) throw new RuntimeException('toc_pipe_queue');
            try {
                if (!flock($lock,LOCK_EX)) throw new RuntimeException('toc_pipe_queue');
                if ($this->vault->read($name)===$receipt && !unlink($this->vault->path($name))) throw new RuntimeException('toc_pipe_queue');
            } finally { flock($lock,LOCK_UN); fclose($lock); }
        }
    }
}

