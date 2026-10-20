<?php
declare(strict_types=1);
/** Bounded local MIME parsing; attachments are excluded. */
final class HostelworldMailMessage
{
public const MAX_BYTES=262144;
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
        $links=[];
        if (preg_match('/\A\s*text\/html/i',$type)) {
            preg_match_all('/\bhref\s*=\s*(["\'])(https:\/\/[^"\']{1,4096})\1/i',$body,$matches);
            $links=$matches[2];
        }
        // Retained only inside the local parser; the bridge emits authentication material only.
        return html_entity_decode(strip_tags($body)."\n".implode("\n",$links),ENT_QUOTES|ENT_HTML5,'UTF-8');
    }

}
