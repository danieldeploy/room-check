<?php
declare(strict_types=1);

/** Reconcile the rows of ONE authenticated provider report against actual outbound attempts. */
final class InvoiceTocBatchReply
{
    private static function number(string $value): string
    {
        return trim(preg_replace('/[\s\x{00a0}]+/u',' ',$value)??'');
    }

    private static function supplier(string $value): string
    {
        return preg_match('/\A[125689][0-9]{8}\z/',$value)?$value:'';
    }

    /** Include terminal entries, earlier manual sends, all issue months, and portal entries with unknown suppliers. */
    private static function outbound(array $ledger,array $documents,array $settings,int $signedAt): array
    {
        $batches=[];
        foreach ($ledger as $key=>$current) {
            foreach ([...($current['previous_attempts']??[]),$current] as $entry) {
                $sent=strtotime((string)($entry['attempted_at']??''));
                if (!$sent || $signedAt<$sent-300 || $entry['nif']!==$settings['nif'] || $entry['sender']!==$settings['sender']) continue;
                $id=(string)$entry['document_id'];$batch=$entry['email_id']??'legacy:'.$id;
                $paper=str_starts_with($id,'receipt:');
                $d=$paper?($documents[substr($id,8)]??[]):[];
                $buyer=!$paper || InvoiceReceiptAnalysis::buyerAccepted($d,$settings['nif']);
                // Portal records have a validated buyer but no independently extracted supplier NIF.
                $batches[$batch][$id]??=['keys'=>[], 'active'=>($current['email_id']??'legacy:'.$id)===$batch,
                    'buyer'=>$buyer,'supplier'=>self::supplier((string)($d['issuer_nif']??'')),
                    'number'=>self::number((string)($d['invoice_number']??'')),'state'=>$entry['state'],
                    'recorded_refusal'=>!empty($entry['toc_supplier_unambiguous']) && !empty($entry['toc_supplier_refused'])];
                $batches[$batch][$id]['keys'][$entry['attachment_key']??$key]=true;
            }
        }
        return $batches;
    }

    private static function exact(array $member,array $row): bool
    {
        return $member['buyer'] && $member['supplier']!=='' && $member['number']!==''
            && $member['supplier']===($row['issuer_nif']??'') && $member['number']===self::number((string)($row['invoice_number']??''));
    }

    /** A fiscal-only report can identify an email by its entire supplier multiset. Unknown local NIFs compete as wildcards. */
    private static function compatible(array $members,array $counts): bool
    {
        if (count($members)!==array_sum($counts)) return false;
        foreach ($members as $m) {
            $nif=$m['supplier'];
            if ($nif!=='' && empty($counts[$nif])) return false;
            if ($nif!=='') --$counts[$nif];
        }
        return true;
    }

    public static function decide(array $ledger,array $documents,array $settings,int $signedAt,array $report): array
    {
        $named=$report['named']??[];$purchases=$report['purchases']??[];$refusals=$report['refusals']??[];
        if (!is_array($named) || !is_array($purchases) || !is_array($refusals) || count($named)+count($purchases)+count($refusals)>20) return [];
        $batches=self::outbound($ledger,$documents,$settings,$signedAt);
        $anchors=[];$namedHits=[];$fiscalHits=[];$counts=[];$references=[];$seenFiscal=[];$conflictingRows=[];
        $shapeValid=($report['complete']??false)===true;$pure=$shapeValid && !$named && !$refusals && empty($report['unassigned']) && count($purchases)>1;
        foreach ($named as $key=>$row) {
            foreach ($batches as $batch=>$members) foreach ($members as $id=>$m) if (isset($m['keys'][$key])) {
                $namedHits[$key][$batch][$id]=true;
            }
            $anchors[]=array_keys($namedHits[$key]??[]);
        }
        foreach ($purchases as $i=>$row) {
            $nif=self::supplier((string)($row['issuer_nif']??''));
            $ref=(string)($row['reference']??'');$number=self::number((string)($row['invoice_number']??''));
            if ($nif==='') $shapeValid=false;
            $counts[$nif]=1+($counts[$nif]??0);
            if ($ref!=='' && isset($references[$ref])) $conflictingRows[$i]=$conflictingRows[$references[$ref]]=true;
            if ($number!=='' && isset($seenFiscal[$nif][$number])) $conflictingRows[$i]=$conflictingRows[$seenFiscal[$nif][$number]]=true;
            if ($ref!=='') $references[$ref]=$i;
            if ($number!=='') $seenFiscal[$nif][$number]=$i;
            if (!in_array($row['status']??'',['toc_accepted','pending'],true) || (($row['status']??'')==='toc_accepted' && $ref==='')) $pure=false;
            foreach ($batches as $batch=>$members) foreach ($members as $id=>$m) if (self::exact($m,$row)) $fiscalHits[$i][$batch][$id]=true;
        }
        if (!$references) $pure=false;
        if ($conflictingRows) $shapeValid=false;
        $possible=[];
        if ($anchors) {
            $possible=array_values(array_intersect(array_shift($anchors),...$anchors));
        } elseif ($pure && $shapeValid) {
            foreach ($batches as $batch=>$members) if (self::compatible($members,$counts)) $possible[]=$batch;
            // Exact fiscal identities can distinguish known sends with otherwise identical supplier counts.
            foreach ($fiscalHits as $hits) {
                $possible=array_values(array_filter($possible,static fn($b)=>isset($hits[$b]) || in_array('',array_column($batches[$b],'supplier'),true)));
            }
        } else {
            // A complete fiscal identity may bind an otherwise partial report to a recorded email.
            foreach ($fiscalHits as $hits) $anchors[]=array_keys($hits);
            if ($anchors) $possible=array_values(array_intersect(array_shift($anchors),...$anchors));
        }
        $identified=count($possible)===1?$possible[0]:null;
        // An unknown supplier is evidence for ambiguity, never evidence for selecting that email by composition.
        if ($pure && $identified!==null) foreach ($batches[$identified] as $m) if ($m['supplier']==='' || !$m['buyer']) $identified=null;

        $decisions=[];$used=[];$assigned=[];
        foreach ($named as $key=>$row) {
            $hits=$namedHits[$key]??[];
            if ($identified!==null) $hits=array_intersect_key($hits,[$identified=>true]);
            $state=$row['state']??'toc_review';
            if (count($hits)!==1 || !in_array($state,['toc_accepted','toc_existing','toc_rejected','toc_review'],true)) continue;
            $batch=array_key_first($hits);
            if (count($hits[$batch])!==1) continue;
            $id=array_key_first($hits[$batch]);$m=$batches[$batch][$id];
            $rowNif=self::supplier((string)($row['issuer_nif']??''));
            if ($rowNif!=='' && $m['supplier']!=='' && $rowNif!==$m['supplier']) $shapeValid=false;
            if (in_array($state,['toc_accepted','toc_existing'],true) && !$m['buyer']) { $state='toc_review';$shapeValid=false; }
            if (isset($used[$batch][$id])) { $state='toc_review';$shapeValid=false; }
            $reason=$state==='toc_review'?'toc_reply_batch_conflict':($row['reason']??'');
            $used[$batch][$id]=$state;
            if ($m['active'] || in_array($state,['toc_accepted','toc_existing'],true)) $decisions[$id]=['state'=>$state,'basis'=>'individual','reason'=>$reason,
                'prior_email'=>$m['active']?null:$batch];
        }
        foreach ($purchases as $i=>$row) {
            $hits=$fiscalHits[$i]??[];
            if ($identified!==null) {
                if ($hits && !isset($hits[$identified])) $shapeValid=false;
                $hits=array_intersect_key($hits,[$identified=>true]);
            } elseif ($named) continue; // Conflicting or unknown filename anchors cannot bind other invoices.
            if (count($hits)!==1) continue;
            $batch=array_key_first($hits);
            if (count($hits[$batch])!==1) continue;
            $id=array_key_first($hits[$batch]);
            if (isset($used[$batch][$id])) { $shapeValid=false;continue; }
            $accepted=!isset($conflictingRows[$i]) && ($row['status']??'')==='toc_accepted';
            $used[$batch][$id]=$accepted?'toc_accepted':'toc_review';$assigned[$i]=true;
            if ($batches[$batch][$id]['active'] || $accepted) $decisions[$id]=['state'=>$used[$batch][$id],'basis'=>'individual',
                'prior_email'=>$batches[$batch][$id]['active']?null:$batch,
                'reason'=>$accepted?'':(isset($conflictingRows[$i])?'toc_reply_batch_conflict':'toc_provider_preparing')];
        }
        if ($identified===null) {
            if (count($possible)>1) foreach ($possible as $batch) foreach ($batches[$batch] as $id=>$m) {
                if ($m['active'] && $m['buyer'] && $m['supplier']!=='' && !isset($decisions[$id])) {
                    $decisions[$id]=['state'=>'toc_review','reason'=>'toc_reply_batch_ambiguous'];
                }
            }
            return $decisions;
        }
        $members=$batches[$identified];$used=$used[$identified]??[];
        $groups=[];$remaining=[];$accepted=[];
        foreach ($members as $id=>$m) {
            $nif=$m['supplier'];
            // A named portal attachment can carry its supplier in the signed row. Never synthesize an identity for its siblings.
            if ($nif==='' && isset($used[$id])) foreach ($m['keys'] as $key=>$_) {
                if (isset($named[$key])) $nif=self::supplier((string)($named[$key]['issuer_nif']??''));
                if ($nif!=='') break;
            }
            if ($nif==='' || !$m['buyer']) {
                if (!isset($used[$id])) $shapeValid=false;
                continue;
            }
            $groups[$nif][$id]=$m;
            if (isset($used[$id])) {
                if ($used[$id]==='toc_accepted') $accepted[$nif]=1+($accepted[$nif]??0);
                if (in_array($m['state'],['toc_accepted','toc_existing','toc_rejected'],true) && $used[$id]!==$m['state']) $shapeValid=false;
            } else $remaining[$nif][$id]=$m;
        }
        $openRows=[];$pending=[];$uncounted=[];
        foreach ($purchases as $i=>$row) {
            if (isset($assigned[$i])) continue;
            $nif=(string)($row['issuer_nif']??'');
            $openRows[$nif]=1+($openRows[$nif]??0);
            if (!isset($conflictingRows[$i]) && ($row['status']??'')==='toc_accepted' && !empty($row['reference'])) $accepted[$nif]=1+($accepted[$nif]??0);
            elseif (($row['status']??'')==='pending') $pending[$nif]=true;
            else $uncounted[$nif]=true;
        }
        foreach ($openRows as $nif=>$n) if (count($remaining[$nif]??[])<$n) $shapeValid=false;
        $unassigned=(int)($report['unassigned']??0);
        $deficits=[];
        foreach ($groups as $nif=>$group) {
            $deficit=count($remaining[$nif]??[])-($openRows[$nif]??0);
            if ($deficit>0) $deficits[$nif]=$deficit;
        }
        $refused=[];
        foreach ($refusals as $r) {
            $nif=self::supplier((string)($r['issuer_nif']??''));
            if ($nif==='' && count($deficits)===1 && array_sum($deficits)===count($refusals)) $nif=(string)array_key_first($deficits);
            if ($nif==='' || !isset($deficits[$nif]) || !in_array($r['state']??'',['toc_existing','toc_rejected'],true)) continue;
            $refused[$nif][]=$r['state'];
        }
        $knownRefusals=$unassigned===count($refusals) && array_sum(array_map('count',$refused))===count($refusals);
        foreach ($refused as $nif=>$results) if (count($results)>($deficits[$nif]??0)) $knownRefusals=false;
        foreach ($groups as $nif=>$group) {
            $n=count($group);$confirmed=$accepted[$nif]??0;$unknown=count($remaining[$nif]??[]);
            $ref=$refused[$nif]??[];
            $manual=$shapeValid && $knownRefusals && $ref && count(array_unique($ref))===1
                && empty($pending[$nif]) && empty($uncounted[$nif]) && count($ref)===($deficits[$nif]??0);
            $full=$shapeValid && !$unassigned && !$refusals && empty($pending[$nif]) && empty($uncounted[$nif])
                && ($openRows[$nif]??0)===$unknown;
            // Existing final outcomes also occupy their place in the send and can contradict an anonymous acceptance.
            $supplierConflict=false;
            if ($full) foreach ($remaining[$nif]??[] as $m) {
                if ($m['recorded_refusal'] || in_array($m['state'],['toc_existing','toc_rejected','toc_duplicate_local'],true)) $supplierConflict=true;
            }
            $full=$full && !$supplierConflict;
            $metrics=['toc_supplier_group'=>hash('sha256',implode("\0",[$settings['nif'],$settings['sender'],$identified,$nif])),
                'toc_supplier_total'=>$n,'toc_supplier_confirmed'=>min($n,$confirmed),
                'toc_supplier_counts_known'=>$shapeValid && !$supplierConflict && $knownRefusals && empty($uncounted[$nif]) && $confirmed<=$n,
                'toc_supplier_unambiguous'=>(bool)($manual || $full),
                'toc_supplier_refused'=>$shapeValid && $knownRefusals?count($ref):0,'toc_supplier_refusal_outcome'=>$manual?$ref[0]:''];
            foreach ($group as $id=>$m) {
                if (!$m['active'] && empty($decisions[$id]['prior_email']) && (isset($used[$id]) || !$full)) continue;
                $reason=match (true) {
                    (bool)$refusals=>'toc_reply_unassigned_refusal',
                    !$shapeValid || $supplierConflict=>'toc_reply_batch_conflict',
                    !empty($pending[$nif])=>'toc_provider_preparing',
                    default=>'toc_reply_batch_incomplete',
                };
                if (!isset($decisions[$id])) $decisions[$id]=$full
                    ? ['state'=>'toc_accepted','basis'=>'batch','reason'=>'','prior_email'=>$m['active']?null:$identified]
                    : ['state'=>'toc_review','reason'=>$reason];
                $decisions[$id]+=$metrics;
            }
        }
        return $decisions;
    }
}
