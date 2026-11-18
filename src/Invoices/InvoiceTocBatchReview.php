<?php
declare(strict_types=1);

/** Manual reconciliation of actual paper-invoice emails. All writes use the invoice worker lock. */
trait InvoiceTocBatchReview
{
    private static function receiptKey(array $d,string $nif): string
    {
        return hash('sha256',$nif."\0receipt\0".$d['id']."\0".$d['sha256']);
    }

    /** Before batching, the actual paper mail Message-ID was the canonical attachment key. */
    private static function receiptMailId(string $key,array $entry): string
    {
        return (string)($entry['email_id']??(str_starts_with((string)($entry['document_id']??''),'receipt:')?$key:''));
    }

    private function verifiedReceiptPart(array $doc,string $key): array
    {
        $pdf=$this->drive->verifiedReceipt($doc);
        if (strlen($pdf)!==(int)$doc['size'] || strlen($pdf)>10*1024*1024
            || !InvoiceDriveClient::receiptBytesValid($pdf,$doc['format'])
            || !hash_equals($doc['sha256'],hash('sha256',$pdf))) throw new RuntimeException('drive_verify');
        return ['id'=>'receipt:'.$doc['id'],'identity'=>$key,'content'=>$key,'pdf'=>$pdf,'portal'=>'receipt','format'=>$doc['format']];
    }

    /** The receipts page authorizes existing history viewers before projecting any batch. */
    public function receiptReviewBatches(array $actor,string $processId): array
    {
        if ((int)($actor['id']??0)<1) throw new RuntimeException('forbidden',403);
        return $this->reviewCards($processId,$this->ledger());
    }

    private function reviewCards(string $processId,array $ledger): array
    {
        require_once __DIR__.'/InvoiceReceiptReport.php';
        if ($processId==='' || !$this->vault->has('receipts-workflow.enc')) return [];
        $s=InvoiceReceiptReport::withProcesses($this->vault->read('receipts-workflow.enc'));
        if (!isset($s['processes'][$processId])) return [];
        $settings=$this->settings();
        $batches=[];$invalid=[];
        foreach ($ledger as $key=>$current) {
            foreach ([...($current['previous_attempts']??[]),$current] as $entry) {
                $mail=self::receiptMailId((string)$key,$entry);
                if (!preg_match('/\A[a-f0-9]{64}\z/',$mail)) continue;
                $id=(string)($entry['document_id']??'');
                $doc=$s['documents'][substr($id,8)]??[];
                if (!str_starts_with($id,'receipt:') || !$doc || ($doc['id']??null)!==substr($id,8)
                    || ($entry['nif']??'')!==$settings['nif'] || ($entry['sender']??'')!==$settings['sender']
                    || empty($doc['sha256']) || self::receiptKey($doc,$settings['nif'])!==$key
                    || !strtotime((string)($entry['attempted_at']??''))) {
                    $invalid[$mail]=true;
                    continue;
                }
                $batches[$mail][$doc['id']]=['entry'=>$entry,'current'=>$current,'doc'=>$doc,'live'=>self::receiptMailId((string)$key,$current)===$mail];
            }
        }
        $cards=[];
        foreach (array_diff_key($batches,$invalid) as $mail=>$members) {
            if (!in_array($processId,array_column(array_column($members,'doc'),'process_id'),true)) continue;
            $card=$this->reviewCard((string)$mail,$members,$settings);
            if ($card!==null) $cards[$mail]=$card;
        }
        uasort($cards,static fn($a,$b)=>strcmp($b['sent_at'],$a['sent_at']));
        return $cards;
    }

    private function reviewCard(string $mail,array $members,array $settings): ?array
    {
        $groups=[];$rows=[];$needsReview=false;$accepted=0;$rejected=0;$unaccounted=0;
        $unassigned=null;$assignGroup=null;$withException=0;$hasUnassignedRefusal=false;$hasEvidence=false;
        $definiteRejection=false;$assignConfirmsPeers=false;
        $sendEnabled=!empty($settings['enabled']) && !empty($settings['pilot_verified']);
        foreach ($members as $id=>$member) {
            ['entry'=>$entry,'doc'=>$doc,'current'=>$current]=$member;
            $live=$member['live'];
            $state=$entry['state'];
            $archived=$live && $doc['stage']==='archived' && empty($doc['error']) && empty($doc['children'])
                && empty($doc['duplicate_keeper']) && InvoiceReceiptAnalysis::buyerAccepted($doc,$settings['nif']);
            $eligible=$archived && in_array($state,['toc_review','toc_no_confirmation','toc_submitted','toc_uncertain','toc_sending'],true);
            $mature=in_array($state,['toc_no_confirmation','toc_review','toc_rejected'],true) || strtotime($entry['attempted_at'])<=time()-1800;
            $reason=self::deliveryReason($entry);
            if ($reason==='toc_provider_preparing' && strtotime($entry['attempted_at'])>time()-1800) $mature=false;
            $resend=$eligible && $mature && $sendEnabled && ($doc['format']??'')==='pdf' && !empty($doc['issued_on'])
                && !str_starts_with($reason,'toc_provider_') && $reason!=='toc_reply_unassigned_refusal';
            if ($reason==='toc_reply_unassigned_refusal') $hasUnassignedRefusal=true;
            if ($state==='toc_rejected') $definiteRejection=true;
            if ($live && $mature && in_array($state,['toc_review','toc_no_confirmation','toc_submitted','toc_uncertain','toc_sending','toc_rejected'],true)) $needsReview=true;
            $rows[$id]=['id'=>(string)$id,'state'=>$live?$state:$current['state'],'eligible'=>$eligible,
                'resend_eligible'=>$resend,'assign_eligible'=>false,'assign_group_member'=>false,'resent'=>!$live];
            $group=$entry['toc_supplier_group']??'';
            if (($entry['toc_supplier_refused']??0)>0) {
                $hasUnassignedRefusal=true;
                $rows[$id]['resend_eligible']=false;
            }
            if ($group!=='' && (!empty($entry['toc_supplier_counts_known']) || !empty($entry['toc_supplier_unambiguous']))) $groups[$group][$id]=$entry;
            elseif (($entry['confirmation_source']??'')==='dkim_toconline' && $state==='toc_accepted') { $accepted++;$hasEvidence=true; }
            elseif (($entry['confirmation_source']??'')==='dkim_toconline' && in_array($state,['toc_existing','toc_rejected'],true)) { $rejected++;$hasEvidence=true; }
            else $unaccounted++;
        }
        if (!$needsReview) return null;
        // Count a provider's supplier result once, even if copied onto every row in the ledger.
        foreach ($groups as $group=>$entries) {
            $sample=reset($entries);
            $n=$sample['toc_supplier_total']??null;$yes=$sample['toc_supplier_confirmed']??null;
            $no=$sample['toc_supplier_refused']??0;$outcome=$sample['toc_supplier_refusal_outcome']??'';
            foreach ($entries as $entry) {
                if (($entry['toc_supplier_total']??null)!==$n || ($entry['toc_supplier_confirmed']??null)!==$yes
                    || ($entry['toc_supplier_refused']??0)!==$no || ($entry['toc_supplier_refusal_outcome']??'')!==$outcome) {
                    $n=null;break;
                }
            }
            $namedRefused=0;
            foreach ($entries as $entry) if (($entry['confirmation_source']??'')==='dkim_toconline'
                && in_array($entry['state'],['toc_existing','toc_rejected'],true)) $namedRefused++;
            if (!is_int($n) || $n!==count($entries) || !is_int($yes) || !is_int($no)
                || $yes<0 || $no<0 || $yes+$no+$namedRefused>$n) { $unaccounted+=count($entries);continue; }
            $accepted+=$yes;$rejected+=$no+$namedRefused;$unaccounted+=$n-$yes-$no-$namedRefused;$hasEvidence=true;
            if (!empty($sample['toc_supplier_unambiguous']) && $no===1 && in_array($outcome,['toc_existing','toc_rejected'],true)
                && !in_array('assign_rejection',array_column($entries,'manual_batch_action'),true)) {
                $assignGroup=$group;$unassigned=$outcome;$withException++;
                $assignConfirmsPeers=$yes+$no+$namedRefused===$n;
            }
        }
        if ($withException===1) {
            foreach ($groups[$assignGroup] as $id=>$entry) {
                $rows[$id]['assign_group_member']=true;
                $rows[$id]['assign_eligible']=$rows[$id]['eligible'] && ($members[$id]['doc']['format']??'')==='pdf';
            }
        } else $unassigned=null;
        if (!in_array(true,array_column($rows,'assign_eligible'),true)) $unassigned=null;
        $card=['id'=>$mail,'sent_at'=>reset($members)['entry']['attempted_at'],'documents'=>array_values($rows),
            'accepted'=>$hasEvidence?$accepted:null,'rejected'=>$hasEvidence?$rejected:null,'missing'=>$hasEvidence?$unaccounted:null,'unassigned'=>$unassigned,
            'assign_confirms_peers'=>$unassigned!==null && $assignConfirmsPeers,
            'all_present_eligible'=>!$hasUnassignedRefusal && !$definiteRejection && in_array(true,array_column($rows,'eligible'),true)];
        $evidence=[];
        foreach ($members as $member) $evidence[]=array_intersect_key($member['entry'],array_flip([
            'receipt_hash','toc_supplier_group','toc_supplier_total','toc_supplier_confirmed','toc_supplier_refused','toc_supplier_refusal_outcome','toc_supplier_unambiguous','toc_supplier_counts_known',
        ]));
        $card['version']=hash('sha256',json_encode([$card,$evidence],JSON_THROW_ON_ERROR));
        return $card;
    }

    /** Caller has validated CSRF, receipts.review, receipts.history, and holds room_check_invoices. */
    public function reviewReceiptBatch(array $actor,string $processId,string $batch,string $version,string $choice,array $selected,bool $confirmed,bool $othersPresent=false): void
    {
        if (($actor['role']??'')!=='gerente' || ($actor['id']??0)<1) throw new RuntimeException('forbidden',403);
        if (!$confirmed || !in_array($choice,['all_present','assign_rejection','resend_missing'],true)
            || !array_is_list($selected) || count($selected)!==($choice==='all_present'?0:1)
            || ($selected && !is_string($selected[0]))) throw new RuntimeException('toc_batch_check_required');
        $read=function() use ($processId,$batch,$version): array {
            $ledger=$this->ledger();$card=$this->reviewCards($processId,$ledger)[$batch]??null;
            if (!$card || !hash_equals($card['version'],$version)) throw new RuntimeException('toc_batch_changed');
            return [$ledger,$card];
        };
        [$ledger,$card]=$read();$rows=array_column($card['documents'],null,'id');
        $id=$selected[0]??'';$actorId=(int)$actor['id'];
        if ($choice==='resend_missing') {
            if (empty($rows[$id]['resend_eligible'])) throw new RuntimeException('toc_batch_check_required');
            $settings=$this->settings();$doc=$this->vault->read('receipts-workflow.enc')['documents'][$id];
            if (!self::fits([],(int)$doc['size'])) throw new RuntimeException('toc_attachment_size');
            $this->assertSendBudget($ledger);
            $this->drive->connect();$key=self::receiptKey($doc,$settings['nif']);$part=$this->verifiedReceiptPart($doc,$key);
            require_once __DIR__.'/InvoiceTocReplyRecovery.php';
            InvoiceTocReplyRecovery::beforeResend($this->vault,$this);
            [$ledger]=$read(); // A late individual confirmation or changed group invalidates the entire submitted form.
            $peers=[];
            if ($othersPresent) {
                if (!$card['all_present_eligible']) throw new RuntimeException('toc_batch_check_required');
                foreach ($ledger as $otherKey=>&$other) {
                    $otherId=substr((string)$other['document_id'],8);
                    if (self::receiptMailId((string)$otherKey,$other)!==$batch || $otherId===$id || empty($rows[$otherId]['eligible'])) continue;
                    self::recordManualOutcome($other,'toc_accepted',$actorId,'others_present');
                    $peers[]=$other;
                }
                unset($other);
            }
            // The same durable write reserves the send and records the independently checked peers.
            $this->transmitBatch($ledger,[$part],$settings,self::destination($settings['nif']),$actorId);
            foreach ($peers as $other) $this->state($other['document_id'],$other['state'],self::receiptTiming($other));
            return;
        }
        if ($choice==='all_present' && !$card['all_present_eligible']) throw new RuntimeException('toc_batch_check_required');
        if ($choice==='assign_rejection' && (empty($rows[$id]['assign_eligible']) || empty($card['unassigned']))) throw new RuntimeException('toc_batch_check_required');
        $updated=[];
        foreach ($ledger as $key=>&$entry) {
            if (self::receiptMailId((string)$key,$entry)!==$batch) continue;
            $rowId=substr((string)$entry['document_id'],8);
            if (empty($rows[$rowId]['eligible'])) continue;
            if ($choice==='assign_rejection' && $rowId!==$id
                && (!$card['assign_confirms_peers'] || !$rows[$rowId]['assign_group_member'])) continue;
            self::recordManualOutcome($entry,$choice==='assign_rejection' && $rowId===$id?$card['unassigned']:'toc_accepted',$actorId,$choice);
            $updated[$entry['document_id']]=$entry;
        }
        unset($entry);
        if (!$updated) throw new RuntimeException('toc_batch_changed');
        $this->vault->save('toconline-ledger.enc',$ledger);
        foreach ($updated as $entry) $this->state($entry['document_id'],$entry['state'],self::receiptTiming($entry));
    }

    private static function recordManualOutcome(array &$entry,string $state,int $actor,string $action): void
    {
        $entry['state']=$state;$entry['confirmed_by']=$actor;$entry['confirmed_at']=gmdate('c');
        $entry['confirmation_source']='manager';$entry['toc_confirmation_basis']='manual';$entry['manual_batch_action']=$action;
        unset($entry['receipt_review_reason'],$entry['delivery_reason']);
    }
}
