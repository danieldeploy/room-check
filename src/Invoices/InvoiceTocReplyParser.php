<?php
declare(strict_types=1);

/** Parse provider body sections. Returned evidence may only be acted on after DKIM verification. */
final class InvoiceTocReplyParser
{
    public const TOKEN='(?:booking|hostelworld|cloudbeds|receipt)[-_]([a-f0-9]{64})\.(?:pdf|jpe?g)';
    public const SUCCESS_SUBJECT='Ficheiros arquivados com sucesso - Active Lines, Lda';
    private const HEADINGS=[
        'Ficheiros arquivados e associados automaticamente a faturas de compra:'=>'purchase',
        'Ficheiros aceites para arquivo:'=>'archive',
        'Ficheiros arquivados e associados automaticamente a fornecedores:'=>'supplier',
        'Ficheiros arquivados com sucesso:'=>'archive',
        'Foram recusados os seguintes ficheiros por já constarem no seu arquivo:'=>'existing',
        'Foram recusados os seguintes ficheiros por não pertencerem à sua empresa:'=>'wrong_recipient',
        'Foram recusados os seguintes ficheiros, por se encontrarem em formato não suportado:'=>'format',
        'Foram recusados os seguintes ficheiros:'=>'rejected',
    ];
    private const VERDICTS=[
        'archive'=>['toc_accepted',''], 'supplier'=>['toc_accepted',''], 'existing'=>['toc_existing',''],
        'wrong_recipient'=>['toc_rejected','toc_provider_wrong_recipient'], 'format'=>['toc_rejected','toc_provider_format'],
        'rejected'=>['toc_rejected',''], 'unknown'=>['toc_review',''],
    ];
    private const FILENAME='[\pL\pN._-]+\.(?:pdf|jpe?g)';
    private const UNKNOWN_HEADING='~\A(?:Ficheiros|Documentos|Estado|Resultado|Foram)\b[^:\r\n]{0,180}:~iu';
    private const NIF='[125689][0-9]{8}';
    private const INVOICE='[A-Z0-9]{1,8}[ ]+[A-Z0-9][A-Z0-9 ._/-]{0,99}';

    /** Bare filename lists are restricted to the established success subject. */
    public static function read(string $text,string $subject): array
    {
        $text=trim(preg_replace('/[\h\x{00a0}]+/u',' ',$text)??'');
        $report=['named'=>[], 'purchases'=>[], 'refusals'=>[], 'unassigned'=>0, 'complete'=>true];
        $section='unknown';
        if ($subject===self::SUCCESS_SUBJECT && preg_match('~\A(?:'.self::TOKEN.'(?:\s+|$))+\z~i',$text)) $section='archive';
        $lines=[];
        foreach (preg_split('/\R/u',$text)?:[] as $line) {
            $line=trim($line);
            if ($line==='') continue;
            foreach (self::HEADINGS as $heading=>$next) {
                if (!str_starts_with($line,$heading)) continue;
                self::section($report,$section,$lines);
                $section=$next;
                $lines=[];
                $line=trim(substr($line,strlen($heading)));
                break;
            }
            if (preg_match(self::UNKNOWN_HEADING,$line)) {
                self::section($report,$section,$lines);
                $section='unknown';
                $lines=[];
                self::unresolved($report);
            }
            if ($line!=='') $lines[]=$line;
        }
        self::section($report,$section,$lines);
        // An unfamiliar heading/prose must never convert a mention into an acknowledgement.
        preg_match_all('~'.self::TOKEN.'~i',$text,$mentions);
        foreach (array_unique(array_map('strtolower',$mentions[1])) as $key) {
            if (!isset($report['named'][$key])) self::named($report,$key,'unknown','');
        }
        if (!$report['named'] && !$report['purchases'] && !$report['unassigned']) return [];
        // The signed company-specific subject is part of the established fiscal template.
        // Anonymous supplier rows alone could otherwise belong to another buyer using the same mailbox.
        if ($report['purchases'] && $subject!==self::SUCCESS_SUBJECT) {
            $report['unassigned']+=count($report['purchases']);
            $report['purchases']=[];
            $report['complete']=false;
        }
        if (preg_match('/\AFicheiros (?:arquivados com sucesso|recusados) - /u',$subject) && !str_ends_with($subject,' - Active Lines, Lda')) {
            foreach (array_keys($report['named']) as $key) self::named($report,$key,'unknown','');
            $report['refusals']=[];
            $report['complete']=false;
        }
        // Legacy exact single-file refusal/duplicate with contradictory success subject.
        if ($subject===self::SUCCESS_SUBJECT && count($report['named'])===1 && !$report['purchases'] && !$report['unassigned']) {
            $key=array_key_first($report['named']);
            if (in_array($report['named'][$key]['state'],['toc_existing','toc_rejected'],true) && $report['named'][$key]['reason']!=='toc_provider_wrong_recipient')
                self::named($report,$key,'unknown','');
        }
        if (count($report['named'])+count($report['purchases'])+count($report['refusals'])>20) {
            $report=['named'=>[],'purchases'=>[],'refusals'=>[],'unassigned'=>1,'complete'=>false];
        }
        $states=array_count_values(array_column($report['named'],'state'));
        $report['counts']=['archived'=>$states['toc_accepted']??0,'existing'=>$states['toc_existing']??0,
            'rejected'=>$states['toc_rejected']??0,'review'=>$states['toc_review']??0,
            'purchases'=>count($report['purchases']),'pending'=>count(array_filter($report['purchases'],static fn($p)=>$p['status']==='pending'))];
        foreach ($report['refusals'] as $row) $report['counts'][$row['state']==='toc_existing'?'existing':'rejected']++;
        return $report;
    }

    private static function named(array &$report,string $key,string $section,string $nif): void
    {
        [$state,$reason]=self::VERDICTS[$section];
        $key=strtolower($key);
        $verdict=['state'=>$state,'reason'=>$reason,'issuer_nif'=>$nif];
        if (isset($report['named'][$key]) && $report['named'][$key]!==$verdict) $verdict=['state'=>'toc_review','reason'=>'','issuer_nif'=>''];
        if ($verdict['state']==='toc_review') $report['complete']=false;
        $report['named'][$key]=$verdict;
    }

    private static function unresolved(array &$report): void
    {
        $report['unassigned']++;
        $report['complete']=false;
    }

    private static function section(array &$report,string $section,array $lines): void
    {
        // Recognized service footer carries no result. Only ignore the footer line itself.
        $lines=array_values(array_filter($lines,static fn($line)=>!str_starts_with($line,'Qualquer dúvida, poderá entrar em contacto com o nosso suporte telefónico')
            && $line!=='(*) Fatura criada automaticamente via QRCode'));
        $content=implode(' ',$lines);
        if ($section==='unknown') {
            foreach ($lines as $line) {
                if (preg_match('~'.self::TOKEN.'~i',$line,$m)) self::named($report,$m[1],'unknown','');
                elseif (preg_match('~(?:\b(?:recusad[oa]s?|rejeitad[oa]s?)\b|\b'.self::FILENAME.'\b)~iu',$line)) self::unresolved($report);
            }
            return;
        }
        if (!$lines) { self::unresolved($report); return; }
        if ($section==='purchase') {
            $rows=preg_split('/\bFornecedor NIF:\s*/u',$content);
            if (trim(array_shift($rows))!=='' || !$rows) self::unresolved($report);
            foreach ($rows as $row) {
                $row=preg_replace('/(?:\s*\(\*\))?(?:\s*\(\*\) Fatura criada automaticamente via QRCode)?\s*$/u','',$row);
                $pattern='~\A('.self::NIF.') +Fornecedor:\s*[^:]{0,200}?\s+(?:e-Fatura:\s*('.self::INVOICE.'|[—–-])?\s+)?'
                    .'Fatura de compra:\s*((?:FC|DSP) +[A-Z0-9._-]+/[A-Z0-9._-]+|Em preparação\.{0,3})\z~u';
                if (!preg_match($pattern,trim($row),$fields)) { self::unresolved($report); continue; }
                $pending=str_starts_with($fields[3],'Em preparação');
                $report['purchases'][]=['issuer_nif'=>$fields[1],'invoice_number'=>preg_match('~\A'.self::INVOICE.'\z~',$fields[2])?$fields[2]:'',
                    'reference'=>$pending?'':$fields[3],'status'=>$pending?'pending':'toc_accepted'];
            }
            return;
        }
        if ($section==='supplier' || (str_starts_with($content,'Fornecedor NIF:') && str_contains($content,'Ficheiro:'))) {
            $rows=preg_split('/\bFornecedor NIF:\s*/u',$content);
            if (trim(array_shift($rows))!=='' || !$rows) self::unresolved($report);
            foreach ($rows as $row) {
                if (preg_match('~\A('.self::NIF.') +Fornecedor: [^:]{1,200} +Ficheiro:\s*('.self::FILENAME.')\s*\]?\z~iu',$row,$fields))
                    self::filename($report,$fields[2],$section,$fields[1]);
                else self::unresolved($report);
            }
            return;
        }
        // Provider archive tables begin with Ficheiro. Every following supplier field belongs to that file.
        if (str_starts_with($content,'Ficheiro:') && str_contains($content,'Fornecedor NIF:')) {
            $rows=preg_split('/\bFicheiro:\s*/u',$content);
            array_shift($rows);
            foreach ($rows as $row) {
                $pattern='~\A('.self::FILENAME.')\s+Fornecedor NIF:\s*('.self::NIF.'|[—–-])\s+Fornecedor:\s*[^:]{1,200}?'
                    .'(?:\s+e-Fatura:\s*(?:'.self::INVOICE.'|[—–-])?)?\s*\z~iu';
                if (preg_match($pattern,trim($row),$fields))
                    self::filename($report,$fields[1],$section,ctype_digit($fields[2])?$fields[2]:'');
                else self::unresolved($report);
            }
            return;
        }
        foreach ($lines as $line) {
            $line=preg_replace('/\A(?:→|[•*-]|Ficheiro:)\s*/u','',$line);
            // Unsupported inputs may carry a different extension; only count isolated
            // filenames explicitly listed by the provider under a refusal heading.
            $filePattern=in_array(self::VERDICTS[$section][0],['toc_existing','toc_rejected'],true)
                ? '[\pL\pN._-]+\.[A-Za-z0-9]{1,10}' : self::FILENAME;
            if (!preg_match('~\A(?:'.$filePattern.'(?:[\s,;]+|$))+\z~iu',$line)) {
                self::unresolved($report);
                $section='unknown';
                continue;
            }
            preg_match_all('~'.$filePattern.'~iu',$line,$matches);
            foreach ($matches[0] as $name) self::filename($report,$name,$section,'');
        }
    }

    private static function filename(array &$report,string $name,string $section,string $nif): void
    {
        if (preg_match('~\A'.self::TOKEN.'\z~i',$name,$m)) {
            self::named($report,$m[1],$section,$nif);
        } elseif (in_array($section,['existing','wrong_recipient','format','rejected'],true)) {
            [$state,$reason]=self::VERDICTS[$section];
            $report['refusals'][]=['issuer_nif'=>$nif,'state'=>$state,'reason'=>$reason];
            $report['unassigned']++;
        } else self::unresolved($report);
    }
}
