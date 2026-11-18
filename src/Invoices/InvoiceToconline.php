<?php
declare(strict_types=1);
require_once __DIR__.'/InvoiceReceiptAnalysis.php';
require_once __DIR__.'/InvoiceTocBatchReply.php';
require_once __DIR__.'/InvoiceTocPipe.php';
require_once __DIR__.'/InvoiceVault.php';
require_once __DIR__.'/InvoiceTocBatchReview.php';
require_once dirname(__DIR__).'/I18n/InvoiceTocReplyText.php';

final class InvoiceTocWait extends RuntimeException
{
    public function __construct(public readonly int $retryAt) { parent::__construct('toc_send_wait'); }
}

/** Every caller holds room_check_invoices. The encrypted ledger precedes network side effects. */
final class InvoiceToconline
{
    use InvoiceTocBatchReview;

    // Keep until end-to-end validation AND explicit owner withdrawal (2026-10-04).
    public const DIAGNOSTIC_DOCUMENT_ID = 9;
    public const DIAGNOSTIC_COOLDOWN = 600;
    private const EMAILS_PER_HOUR = 15;
    public const RECEIPT_EVIDENCE_FIELDS=['toc_confirmation_basis','toc_supplier_group','toc_supplier_total',
        'toc_supplier_confirmed','toc_supplier_refused','toc_supplier_refusal_outcome','toc_supplier_unambiguous','toc_supplier_counts_known'];

    public function __construct(private readonly PDO $pdo, private readonly InvoiceVault $vault, private readonly object $drive, private readonly mixed $transport = null) {}

    public static function destination(string $nif): string
    {
        if (!preg_match('/\A[125689][0-9]{8}\z/', $nif)) throw new RuntimeException('toc_invalid_settings');
        $sum=0; for ($i=0; $i<8; $i++) $sum+=(int)$nif[$i]*(9-$i);
        $check=11-($sum%11); if ($check>=10) $check=0;
        if ($check!==(int)$nif[8]) throw new RuntimeException('toc_invalid_settings');
        return $nif.'@my.toconline.pt';
    }

    public function settings(): array
    {
        return $this->vault->has('toconline-settings.enc') ? $this->vault->read('toconline-settings.enc') : ['nif'=>'','sender'=>'','enabled'=>false,'pilot_verified'=>false];
    }

    public function configure(array $input): void
    {
        $old=$this->settings(); $nif=trim((string)($input['toc_nif']??'')); self::destination($nif);
        $sender=strtolower(trim((string)($input['toc_sender']??'')));
        if (!filter_var($sender,FILTER_VALIDATE_EMAIL) || !preg_match('/\A[a-z0-9._+-]+@(welcomehostel|citycenterhostel)\.pt\z/', $sender)
            || empty($input['toc_authorized'])) throw new RuntimeException('toc_invalid_settings');
        $verified=!empty($old['pilot_verified']) && $old['nif']===$nif && $old['sender']===$sender;
        if (!empty($input['toc_enabled']) && !$verified) throw new RuntimeException('toc_pilot_required');
        $this->vault->save('toconline-settings.enc',['nif'=>$nif,'sender'=>$sender,'enabled'=>!empty($input['toc_enabled']),'pilot_verified'=>$verified]);
    }

    private function ledger(): array
    {
        if ($this->vault->has('toconline-ledger.enc')) return $this->vault->read('toconline-ledger.enc');
        // Missing history after a partial restore must stop delivery, never reset deduplication.
        if ((int)$this->pdo->query("SELECT COUNT(*) FROM invoice_document_delivery WHERE toconline_state IN ('toc_sending','toc_submitted','toc_uncertain','toc_accepted','toc_duplicate','toc_existing','toc_rejected','toc_review','toc_no_confirmation')")->fetchColumn()>0) throw new RuntimeException('toc_ledger_missing');
        if ($this->vault->has('receipts-workflow.enc')) foreach ($this->vault->read('receipts-workflow.enc')['documents']??[] as $d) {
            if (!in_array($d['toconline_state'],['pending','toc_retry','receipt_per_page'],true)) throw new RuntimeException('toc_ledger_missing');
        }
        return [];
    }

    public static function keys(array $d, string $nif): array
    {
        // Booking invoice numbers identify the supplier invoice across properties and accounts.
        $number=strtoupper(trim((string)$d['invoice_number']));
        $portal=$d['portal']??''; $scope=$portal;
        if (!in_array($portal,['booking','hostelworld','cloudbeds'],true) || $number==='') throw new RuntimeException('toc_ineligible');
        if ($portal==='hostelworld') {
            $property=(string)($d['property_id']??'');
            if (!in_array($property,['305209','77759'],true) || !preg_match('/\A\d{1,12}\z/',$number)) throw new RuntimeException('toc_ineligible');
            $scope.=':'.$property;
        }
        return [hash('sha256',$nif."\0".$scope."\0".$number), hash('sha256',$nif."\0".$d['sha256'])];
    }

    public static function message(string $pdf, string $key, string $sender, string $portal='booking', string $format='pdf'): array
    {
        return self::batchMessage([['pdf'=>$pdf,'identity'=>$key,'portal'=>$portal,'format'=>$format]],$sender,$key);
    }

    private const MAX_ATTACHMENTS = 20;
    private const MAX_ATTACHMENT_BYTES = 20000000;
    // Conservative transport envelope, accounting for base64, headers and line folding.
    private const MAX_MESSAGE_BYTES = 25000000;

    private static function fits(array $parts,int $bytes): bool
    {
        $configured=getenv('HUB_TOC_MAX_MESSAGE_BYTES');
        $ceiling=$configured!==false && ctype_digit($configured) && (int)$configured>0
            ? min(self::MAX_MESSAGE_BYTES,(int)$configured) : self::MAX_MESSAGE_BYTES;
        $raw=$bytes;
        foreach ($parts as $part) $raw+=strlen($part['pdf']);
        $count=count($parts)+1;
        // Upper bound including 76-character MIME folding and all per-part headers.
        $encoded=4*intdiv($raw+2,3)+4*$count;
        return $count<=self::MAX_ATTACHMENTS && $raw<=self::MAX_ATTACHMENT_BYTES
            && $encoded+2*intdiv($encoded+75,76)+512*$count+2048<=$ceiling;
    }

    private static function batchMessage(array $parts,string $sender,string $key): array
    {
        if (!preg_match('/\A[a-f0-9]{64}\z/',$key) || !filter_var($sender,FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/',$sender)) throw new RuntimeException('toc_invalid_settings');
        $boundary='hub_'.bin2hex(random_bytes(16));
        $prefix=count($parts)>1?'toc-batch-':'toc-';
        $headers=['From: '.$sender,'Reply-To: '.$sender,'MIME-Version: 1.0',
            'Message-ID: <'.$prefix.$key.'@check.welcomehostel.pt>', 'Content-Type: multipart/mixed; boundary="'.$boundary.'"'];
        $body='--'.$boundary."\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n".
            chunk_split(base64_encode('Management Hub invoices for digital archive.'));
        foreach ($parts as $part) {
            $portal=$part['portal']; $format=$part['format']; $identity=$part['identity'];
            if (!in_array($portal,['booking','hostelworld','cloudbeds','receipt'],true) || !preg_match('/\A[a-f0-9]{64}\z/',$identity)
                || ($format!=='pdf' && !($format==='jpg' && $portal==='receipt'))) throw new RuntimeException('toc_ineligible');
            $mime=$format==='jpg'?'image/jpeg':'application/pdf';
            $body.='--'.$boundary."\r\nContent-Type: ".$mime."\r\nContent-Disposition: attachment; filename=\"".$portal.'-'.$identity.'.'.$format."\"\r\nContent-Transfer-Encoding: base64\r\n\r\n";
            $body.=chunk_split(base64_encode($part['pdf']));
        }
        $body.='--'.$boundary."--\r\n";
        $label=count($parts)>1?'Invoices':['booking'=>'Booking','hostelworld'=>'Hostelworld','cloudbeds'=>'Cloudbeds','receipt'=>'Taloes'][$portal];
        return ['subject'=>'Management Hub - '.$label.' - '.substr($key,0,16),'headers'=>$headers,'body'=>$body];
    }

    private function state(int|string $id, string $state,array $timing=[]): void
    {
        if (str_starts_with((string)$id,'receipt:')) {
            require_once __DIR__.'/InvoiceReceipts.php';
            InvoiceReceipts::deliveryState($this->vault,substr((string)$id,8),$state,$timing); return;
        }
        $this->pdo->prepare('UPDATE invoice_document_delivery SET toconline_state=? WHERE document_id=?')->execute([$state,$id]);
    }

    public function confirm(int $id, string $outcome='toc_accepted', int $actor=0): void
    {
        if (!in_array($outcome,['toc_accepted','toc_existing','toc_rejected'],true)) throw new RuntimeException('toc_ineligible');
        $settings=$this->settings(); $ledger=$this->ledger(); $found=false;
        foreach ($ledger as &$entry) {
            if ((string)$entry['document_id']===(string)$id && $entry['nif']===$settings['nif'] && $entry['sender']===$settings['sender']
                && in_array($entry['state'],['toc_submitted','toc_uncertain','toc_sending','toc_review','toc_no_confirmation'],true)) {
                $entry['state']=$outcome; $entry['confirmed_at']=gmdate('c');
                $entry['confirmed_by']=$actor; $entry['confirmation_source']='manager'; $found=true;
            }
        } unset($entry);
        if (!$found) throw new RuntimeException('toc_ineligible');
        $this->vault->save('toconline-ledger.enc',$ledger); $this->state($id,$outcome);
        // An external duplicate is not proof of a successful new archival pilot.
        if ($outcome==='toc_accepted') {
            $settings['pilot_verified']=true; $this->vault->save('toconline-settings.enc',$settings);
        }
    }

    /** Reconcile even when sending is disabled. Never change a final confirmed outcome. */
    public function reconcile(?int $now=null,bool $ageDue=true): void
    {
        $now??=time(); $ledger=$this->ledger();
        foreach ($ledger as &$entry) {
            if ($entry['state']==='toc_sending') { $entry['state']='toc_uncertain';$entry['delivery_reason']='toc_interrupted_handoff'; }
            if (!$ageDue) continue;
            if (str_starts_with((string)$entry['document_id'],'receipt:')) {
                $sent=strtotime((string)($entry['attempted_at']??''));
                $preparing=$entry['state']==='toc_review' && self::deliveryReason($entry)==='toc_provider_preparing';
                if ($sent && ($preparing || in_array($entry['state'],['toc_submitted','toc_uncertain'],true))) {
                    $entry['receipt_checks']??=0;$entry['receipt_next_check']??=$sent+300;
                    if ($entry['receipt_next_check']>0 && $now>=(int)$entry['receipt_next_check']) {
                        $entry['receipt_checks']=min(6,$entry['receipt_checks']+1);
                        // A delayed worker does not invent missed checks or replay them in a burst.
                        $entry['receipt_next_check']=$sent+(intdiv(max(0,$now-$sent),300)+1)*300;
                        if ($now>=$sent+1800) {
                            $entry['receipt_next_check']=0;
                            if (!$preparing) $entry['state']='toc_no_confirmation';
                        }
                    }
                }
            } elseif ($entry['state']==='toc_submitted' && !empty($entry['attempted_at'])
                && strtotime($entry['attempted_at']) <= $now-72*3600) $entry['state']='toc_no_confirmation';
        } unset($entry);
        $this->vault->save('toconline-ledger.enc',$ledger);
        $nif=$this->settings()['nif']??'';
        foreach ($ledger as $entry) if ($entry['nif']===$nif) $this->state($entry['document_id'],$entry['state'],self::receiptTiming($entry));
    }

    public static function deliveryReason(array $entry): string
    {
        if (($entry['state']??'')!=='toc_review') return (string)($entry['delivery_reason']??'');
        if (!empty($entry['receipt_review_reason'])) return $entry['receipt_review_reason'];
        if (($entry['receipt_ambiguity']??'')==='multiple_scans') return 'toc_reply_multiple_scans';
        // Older multi-file outcomes recorded a receipt hash and time without a single-file suggestion.
        if (!empty($entry['receipt_hash']) && !empty($entry['receipt_received_at']) && !empty($entry['email_id'])
            && !isset($entry['receipt_suggestion'])) return 'toc_reply_attachment_unclear';
        return 'toc_reply_detail_unavailable';
    }

    private static function receiptTiming(array $entry): array
    {
        $sent=strtotime((string)($entry['attempted_at']??''));
        $reason=self::deliveryReason($entry);
        $waiting=$sent && (in_array($entry['state']??'',['toc_sending','toc_submitted','toc_uncertain'],true)
            || (($entry['state']??'')==='toc_review' && $reason==='toc_provider_preparing'));
        $evidence=array_intersect_key($entry,array_flip(self::RECEIPT_EVIDENCE_FIELDS));
        if (!isset($evidence['toc_confirmation_basis']) && empty($entry['equivalent_keeper'])
            && in_array($entry['state']??'',['toc_accepted','toc_existing','toc_rejected'],true)) {
            if (($entry['confirmation_source']??'')==='dkim_toconline') $evidence['toc_confirmation_basis']='individual';
            elseif (($entry['confirmation_source']??'')==='manager') $evidence['toc_confirmation_basis']='manual';
        }
        return ['delivery_reason'=>$reason, 'toc_next_check'=>$waiting?(int)($entry['receipt_next_check']??$sent+300):0,
            'toc_deadline'=>$waiting?$sent+1800:0,'toc_checks'=>(int)($entry['receipt_checks']??0),
            'evidence'=>$evidence];
    }

    private static function protectedReview(array $entry): bool
    {
        return ($entry['state']??'')==='toc_review' && in_array($entry['receipt_review_reason']??'',
            ['toc_provider_preparing','toc_reply_unassigned_refusal'],true);
    }

    private static function recordBatchDecision(array &$entry,array $decision,string $hash,int $signedAt): void
    {
        $outcome=$decision['state'];
        $open=in_array($entry['state'],['toc_sending','toc_submitted','toc_uncertain','toc_no_confirmation','toc_review'],true);
        if ($outcome==='toc_review' && ($signedAt<($entry['receipt_signed_at']??0)
            || (self::protectedReview($entry) && !in_array($decision['reason'],['toc_provider_preparing','toc_reply_unassigned_refusal'],true)))) return;
        if ($open) {
            $entry['state']=$outcome;
            if (($entry['receipt_hash']??'')!==$hash || empty($entry['receipt_received_at'])) $entry['receipt_received_at']=gmdate('c');
            $entry['receipt_hash']=$hash;$entry['receipt_signed_at']=$signedAt;
            unset($entry['receipt_ambiguity'],$entry['receipt_suggestion'],$entry['receipt_review_reason']);
            if ($outcome==='toc_review') $entry['receipt_review_reason']=$decision['reason'];
            else {
                unset($entry['delivery_reason']);
                if (!empty($decision['reason'])) $entry['delivery_reason']=$decision['reason'];
                $entry['confirmed_at']=gmdate('c');$entry['confirmed_by']=0;
                $entry['toc_confirmation_basis']=$decision['basis'];
                $entry['confirmation_source']=$decision['source']??($decision['basis']==='batch'?'dkim_toconline_batch':'dkim_toconline');
                if (!empty($decision['prior_email'])) $entry['receipt_attempt_email_id']=$decision['prior_email'];
            }
        } elseif (($entry['confirmation_source']??'')==='dkim_toconline' && empty($entry['toc_confirmation_basis'])
            && empty($entry['equivalent_keeper']) && $outcome===$entry['state'] && ($decision['basis']??'')==='individual') {
            $entry['toc_confirmation_basis']='individual';
        }
        if (isset($decision['toc_supplier_group']) && (empty($entry['toc_supplier_unambiguous'])
            || !empty($decision['toc_supplier_unambiguous']) || ($entry['toc_supplier_evidence_hash']??'')===$hash)) {
            foreach (self::RECEIPT_EVIDENCE_FIELDS as $field) if ($field!=='toc_confirmation_basis') $entry[$field]=$decision[$field];
            $entry['toc_supplier_evidence_hash']=$hash;
        }
    }

    /** Persist one authenticated report atomically in the ledger, then repair its document projections on replay. */
    private function receiveBatch(array $settings,int $signedAt,array $report,string $hash): array
    {
        $ledger=$this->ledger();
        $documents=$this->vault->has('receipts-workflow.enc')?$this->vault->read('receipts-workflow.enc')['documents']??[]:[];
        $decisions=InvoiceTocBatchReply::decide($ledger,$documents,$settings,$signedAt,$report);
        if (!$decisions) return ['result'=>'unmatched'];
        $changed=false;$updated=[];$pilot=false;
        foreach ($ledger as &$entry) {
            $id=$entry['document_id'];$decision=$decisions[$id]??null;
            if (!$decision || $entry['nif']!==$settings['nif'] || $entry['sender']!==$settings['sender']) continue;
            $before=$entry;
            if (!empty($decision['prior_email'])) {
                // The original attachment was acknowledged after a deliberate resend. Preserve which handoff was proven.
                $present=false;
                if (empty($entry['previous_attempts'])) continue;
                foreach ($entry['previous_attempts'] as &$prior) if (($prior['email_id']??'')===$decision['prior_email']) {
                    self::recordBatchDecision($prior,$decision,$hash,$signedAt);
                    $present=in_array($prior['state'],['toc_accepted','toc_existing'],true);
                }
                unset($prior);
                if (!$present) continue;
                $decision=array_intersect_key($decision,array_flip(['state','basis','reason','prior_email']));
                $decision['source']='dkim_toconline_prior_send';
            }
            self::recordBatchDecision($entry,$decision,$hash,$signedAt);
            $pilot=$pilot || (($entry['receipt_hash']??'')===$hash && $entry['state']==='toc_accepted'
                && ($entry['confirmation_source']??'')==='dkim_toconline');
            $changed=$changed || $entry!==$before;
            $updated[$id]=$entry;
        }
        unset($entry);
        if ($changed) $this->vault->save('toconline-ledger.enc',$ledger);
        foreach ($updated as $id=>$entry) $this->state($id,$entry['state'],self::receiptTiming($entry));
        if ($pilot && empty($settings['pilot_verified'])) {
            $settings['pilot_verified']=true;$this->vault->save('toconline-settings.enc',$settings);
        }
        return ['result'=>'matched','outcome'=>count($updated)===1?reset($updated)['state']:'toc_review'];
    }

    /** Body-derived verdicts may only change a uniquely supported, authenticated submission. */
    public function receive(string $from, string $references, string $text, string $receiptHash, array $proof=[]): array
    {
        if (!preg_match('/\A[a-f0-9]{64}\z/',$receiptHash)) throw new RuntimeException('toc_ineligible');
        if (strtolower(trim($from))!==InvoiceTocPipe::PROVIDER_SENDER) return ['result'=>'ignored'];
        $settings=$this->settings();
        $auth=$proof['authentication']??[];$signedAt=strtotime((string)($auth['signed_at']??''));
        $batch=$proof['batch_reply']??[];
        // Keep the existing single fiscal path, including its equivalent-copy resolution protocol.
        $singlePurchase=!empty($proof['purchase_identity']) && count($batch['purchases']??[])===1 && empty($batch['named'])
            && empty($batch['unassigned']) && !empty($batch['complete']);
        $trusted=($auth['verified']??false)===true && ($auth['code']??'')==='verified'
            && ($auth['recipient']??'')===strtolower($settings['sender']) && $signedAt;
        if ((!empty($batch['named']) || !empty($batch['purchases']) || !empty($batch['refusals'])) && !$singlePurchase && $trusted) {
            return $this->receiveBatch($settings,$signedAt,$batch,$receiptHash);
        }
        $purchases=$proof['purchase_identities']??[];
        if ($purchases && count($purchases)<=self::MAX_ATTACHMENTS && $text==='' && $references==='') {
            $matched=false;
            foreach ($purchases as $identity) {
                $result=$this->receive($from,'','',$receiptHash,['authentication'=>$proof['authentication']??[],
                    'outcome'=>'toc_accepted','purchase_identity'=>$identity]);
                if ($result['result']==='matched') $matched=true;
            }
            return ['result'=>$matched?'matched':'unmatched','outcome'=>'toc_review'];
        }
        $ledger=$this->ledger(); $matches=[];$wire=[];
        foreach ($ledger as $key=>$entry) $wire[$entry['attachment_key']??$key]=$entry;
        preg_match_all('/(?:(?:booking|hostelworld|cloudbeds|receipt)[-_]|toc-)([a-f0-9]{64})(?:\.(?:pdf|jpe?g)|@check\.welcomehostel\.pt)/i',$references."\n".$text,$tokens);
        $uniqueKeys=array_unique(array_map('strtolower',$tokens[1]));
        if ($trusted && count($uniqueKeys)===1 && !isset($wire[reset($uniqueKeys)]) && in_array($proof['outcome']??'',['toc_accepted','toc_existing'],true)) {
            // A pre-upgrade encrypted queue entry may have an old signed verdict without the structured row list.
            return $this->receiveBatch($settings,$signedAt,['named'=>[reset($uniqueKeys)=>['state'=>$proof['outcome']]],
                'purchases'=>[],'unassigned'=>0,'complete'=>true],$receiptHash);
        }
        foreach (array_unique($tokens[1]) as $key) {
            $entry=$wire[strtolower($key)]??null;
            if ($entry && $entry['nif']===$settings['nif'] && $entry['sender']===$settings['sender']) $matches[(string)$entry['document_id']]=true;
        }
        $auth=$proof['authentication']??[];$purchase=$proof['purchase_identity']??[];$fiscalCandidate=null;
        $signedAt=strtotime((string)($auth['signed_at']??''));
        if (!$tokens[1] && ($proof['outcome']??'')==='toc_accepted'
            && ($auth['verified']??false)===true && ($auth['code']??'')==='verified'
            && ($auth['recipient']??'')===strtolower($settings['sender']) && $signedAt
            && preg_match('/\A[125689][0-9]{8}\z/',(string)($purchase['issuer_nif']??''))
            && ($purchase['invoice_number']??'')!=='' && $this->vault->has('receipts-workflow.enc')) {
            $documents=$this->vault->read('receipts-workflow.enc')['documents']??[];
            foreach ($ledger as $entry) {
                $id=(string)$entry['document_id'];$d=$documents[substr($id,8)]??[];
                $sentAt=strtotime((string)($entry['attempted_at']??''));
                if (!str_starts_with($id,'receipt:') || $entry['nif']!==$settings['nif'] || $entry['sender']!==$settings['sender']
                    || !InvoiceReceiptAnalysis::buyerAccepted($d,$settings['nif']) || ($d['issuer_nif']??'')!==$purchase['issuer_nif']
                    || trim(preg_replace('/\s+/u',' ',(string)($d['invoice_number']??'')))!==$purchase['invoice_number']
                    || !$sentAt || $signedAt<$sentAt-300) continue;
                // Include terminal copies too: a replay must never be rebound to a later duplicate scan.
                $matches[$id]=true;$fiscalCandidate=$entry;
            }
        }
        // Only signed body-derived per-file outcomes can resolve a multi-attachment reply.
        $attachments=$proof['attachments']??[];
        if ($attachments && count($attachments)<=20 && ($auth['verified']??false)===true && ($auth['code']??'')==='verified'
            && ($auth['recipient']??'')===strtolower($settings['sender']) && $signedAt) {
            $handled=false;
            foreach ($attachments as $key=>$outcome) {
                if (!in_array($outcome,['toc_accepted','toc_existing','toc_rejected','toc_review'],true)
                    || !in_array($key,$tokens[1],true) || !isset($wire[$key])) continue;
                $candidate=$wire[$key];
                if (!isset($matches[(string)$candidate['document_id']]) || empty($candidate['email_id'])
                    || $candidate['email_id']===$key || $candidate['sender']!==$settings['sender'] || $candidate['nif']!==$settings['nif']) continue;
                $sent=strtotime((string)($candidate['attempted_at']??''));
                if (!$sent || $signedAt<$sent-300) continue;
                $handled=true;
                foreach ($ledger as &$item) {
                    if ($item['document_id']!==$candidate['document_id'] || ($item['email_id']??'')!==$candidate['email_id']
                        || !in_array($item['state'],['toc_sending','toc_submitted','toc_uncertain','toc_review','toc_no_confirmation'],true)) continue;
                    if ($outcome==='toc_review' && self::protectedReview($item)) continue;
                    $item['state']=$outcome;
                    if ($outcome==='toc_review') $item['receipt_review_reason']='toc_reply_attachment_unclear';
                    if ($outcome==='toc_rejected' && in_array($key,$proof['unsupported_files']??[],true)) $item['delivery_reason']='toc_provider_format';
                    $item['receipt_hash']=$receiptHash;$item['receipt_received_at']=gmdate('c');
                    if ($outcome!=='toc_review') { $item['confirmation_source']='dkim_toconline';$item['toc_confirmation_basis']='individual';$item['confirmed_at']=gmdate('c'); }
                }
                unset($item);
            }
            if ($handled) {
                $this->vault->save('toconline-ledger.enc',$ledger);
                foreach ($ledger as $key=>$item) if (isset($attachments[$item['attachment_key']??$key]) && isset($matches[(string)$item['document_id']])) {
                    $this->state($item['document_id'],$item['state'],self::receiptTiming($item));
                }
                return ['result'=>'matched','outcome'=>'toc_review']; // Detailed outcomes live on each invoice.
            }
        }
        if (count($matches)>1 && $fiscalCandidate!==null) {
            // The invoice was acknowledged, but the provider omitted which scan.
            // Expose the ambiguity without declaring any copy accepted or duplicate.
            $review=[];
            foreach ($ledger as &$entry) if (isset($matches[(string)$entry['document_id']])
                && $entry['nif']===$settings['nif'] && $entry['sender']===$settings['sender']
                && in_array($entry['state'],['toc_submitted','toc_uncertain','toc_sending','toc_no_confirmation','toc_review'],true)) {
                if (self::protectedReview($entry)) continue;
                $entry['state']='toc_review';$entry['receipt_hash']=$receiptHash;
                $entry['receipt_suggestion']='toc_accepted';$entry['receipt_received_at']=gmdate('c');
                $entry['receipt_ambiguity']='multiple_scans';$entry['receipt_review_reason']='toc_reply_multiple_scans';
                $review[(string)$entry['document_id']]=self::receiptTiming($entry);
            }
            unset($entry);$this->vault->save('toconline-ledger.enc',$ledger);
            foreach ($review as $id=>$timing) $this->state($id,'toc_review',$timing);
            return ['result'=>'matched','suggestion'=>'toc_review','outcome'=>'toc_review'];
        }
        if (count($matches)!==1) return ['result'=>'unmatched'];
        $id=array_key_first($matches); $suggestion='toc_review';
        $automatic='toc_review'; $applied=false;
        $uniqueTokens=array_unique(array_map('strtolower',$tokens[1]));
        $signedAt=strtotime((string)($auth['signed_at']??''));
        $candidate=$fiscalCandidate??($wire[reset($uniqueTokens)]??[]);
        $sentAt=strtotime((string)($candidate['attempted_at']??''));
        $authReason='toc_auth_'.($auth['code']??'');
        $providerReason=$proof['provider_reason']??'';
        $reviewReason=match (true) {
            ($auth['verified']??false)!==true || ($auth['code']??'')!=='verified'=>
                isset(InvoiceText::TEXT[$authReason]) && !in_array($authReason,['toc_auth_verified','toc_auth_label','toc_auth_outcome','toc_auth_not_checked'],true)?$authReason:'toc_reply_authentication',
            ($auth['recipient']??'')!==strtolower($settings['sender'])=>'toc_reply_recipient',
            count($uniqueTokens)!==1 && $fiscalCandidate===null=>'toc_reply_correlation',
            !$signedAt || !$sentAt=>'toc_reply_timestamp_missing',
            $signedAt<$sentAt-300=>'toc_reply_predates_send',
            !in_array($proof['outcome']??'',['toc_accepted','toc_existing','toc_rejected'],true)=>
                in_array($providerReason,['toc_provider_size','toc_provider_count'],true)?$providerReason:'toc_reply_attachment_unclear',
            default=>'',
        };
        if ($reviewReason==='') $automatic=$proof['outcome'];
        // Only the observed duplicate phrase is recognised. Unknown/new templates remain review.
        if (str_contains(mb_strtolower($text),'por já constarem no seu arquivo')) $suggestion='toc_existing';
        foreach ($ledger as &$entry) if ((string)$entry['document_id']===(string)$id
            && $entry['nif']===$settings['nif'] && $entry['sender']===$settings['sender']
            && in_array($entry['state'],['toc_submitted','toc_uncertain','toc_sending','toc_no_confirmation','toc_review'],true)) {
            if ($automatic==='toc_review' && self::protectedReview($entry)) continue;
            $entry['state']=$automatic;
            if ($automatic==='toc_review') $entry['receipt_review_reason']=$reviewReason;
            if ($automatic==='toc_rejected' && ($proof['provider_reason']??'')==='toc_provider_format') $entry['delivery_reason']='toc_provider_format';
            $entry['receipt_hash']=$receiptHash;
            if ($automatic!=='toc_review') {
                $entry['confirmed_at']=gmdate('c'); $entry['confirmation_source']='dkim_toconline';
                $entry['confirmed_by']=0; $entry['toc_confirmation_basis']='individual';$applied=true;
            }
            $entry['receipt_suggestion']=$suggestion; $entry['receipt_received_at']=gmdate('c');
        }
        unset($entry); $this->vault->save('toconline-ledger.enc',$ledger);
        foreach ($ledger as $entry) if ((string)$entry['document_id']===(string)$id && $entry['nif']===$settings['nif']) { $this->state($id,$entry['state'],self::receiptTiming($entry)); break; }
        if ($applied && $automatic==='toc_accepted') {
            $settings['pilot_verified']=true; $this->vault->save('toconline-settings.enc',$settings);
        }
        return ['result'=>'matched','document_id'=>$id,'suggestion'=>$suggestion,'outcome'=>$applied?$automatic:'toc_review'];
    }

    public function run(?int $pilotId=null,bool $reconcile=true): void
    {
        if ($reconcile) $this->reconcile(null,false); // Only the worker ages records after its late-mail check.
        $settings=$this->settings();
        if ($pilotId && empty($settings['nif'])) throw new RuntimeException('toc_invalid_settings');
        if (empty($settings['nif']) || (!$pilotId && (empty($settings['enabled']) || empty($settings['pilot_verified'])))) return;
        $to=self::destination($settings['nif']); $ledger=$this->ledger();
        // A crash after writing intent must never cause another submission.
        foreach ($ledger as &$entry) if ($entry['state']==='toc_sending') $entry['state']='toc_uncertain';
        unset($entry); $this->vault->save('toconline-ledger.enc',$ledger);
        $query="SELECT d.*,x.drive_id,x.drive_parent,a.portal FROM invoice_documents d JOIN invoice_document_delivery x ON x.document_id=d.id JOIN invoice_accounts a ON a.id=d.account_id WHERE x.drive_state='verified' AND x.company_state='validated' AND d.format='pdf' AND a.portal IN ('booking','hostelworld','cloudbeds')";
        $s=$this->pdo->prepare($query.($pilotId?' AND d.id=?':' AND x.toconline_state IN (\'pending\',\'toc_retry\',\'toc_sending\')').' ORDER BY d.id LIMIT 20');
        $s->execute($pilotId?[$pilotId]:[]); $rows=$s->fetchAll(PDO::FETCH_ASSOC);
        if ($pilotId && !$rows) throw new RuntimeException('toc_ineligible');
        $connected=false;$parts=[];$reserved=[];
        $problems=$this->preflightProblems();
        $originalProblems=$problems;
        foreach ($rows as $d) {
            try {
                [$identity,$content]=self::keys($d,$settings['nif']);
                $existing=$ledger[$identity]??$ledger[$content]??null;
                if ($existing) {
                    $this->state((int)$d['id'],(int)$existing['document_id']===(int)$d['id']?$existing['state']:'toc_duplicate'); continue;
                }
                if (isset($reserved[$identity]) || isset($reserved[$content])) continue; // Next tick reconciles aliases after the batch is committed.
                if (!self::fits($parts,(int)$d['size_bytes'])) {
                    if ($parts) break;
                    throw new RuntimeException('toc_attachment_size');
                }
                if (!$connected) { $this->drive->connect(); $connected=true; }
                $pdf=$this->drive->verifiedPdf($d);
                self::assertPdf($pdf,$d);
                if ($d['portal']==='hostelworld') {
                    require_once __DIR__.'/InvoiceHostelworldVerification.php';
                    $temporary=$this->vault->path('hostelworld-toc-'.bin2hex(random_bytes(16)).'.pdf');
                    try {
                        InvoiceVault::atomicWrite($temporary,$pdf);
                        $checks=InvoiceHostelworldVerification::checkPdf($temporary,(string)$d['property_id'],[
                            'number'=>$d['invoice_number'],'portal_document_id'=>$d['invoice_number'],'issued_on'=>$d['issued_on'],
                        ],$d['period'],$settings['nif']);
                        if (in_array(false,$checks,true)) throw new RuntimeException('toc_portal_mismatch');
                    } finally { if (is_file($temporary)) unlink($temporary); }
                }
                if ($d['portal']==='cloudbeds') {
                    require_once __DIR__.'/CloudbedsInvoice.php';
                    $temporary=$this->vault->path('cloudbeds-toc-'.bin2hex(random_bytes(16)).'.pdf');
                    try {
                        InvoiceVault::atomicWrite($temporary,$pdf);
                        if (!CloudbedsInvoice::matches($temporary,$d,$settings['nif'])) throw new RuntimeException('toc_portal_mismatch');
                    } finally { if (is_file($temporary)) unlink($temporary); }
                }
                $parts[]=['id'=>(int)$d['id'],'identity'=>$identity,'content'=>$content,'portal'=>$d['portal'],'format'=>'pdf','pdf'=>$pdf];
                $reserved[$identity]=$reserved[$content]=true;
                unset($problems[$d['id']]);
            } catch (Throwable $failure) {
                $code=$failure->getMessage();
                $problems[$d['id']]=['reason'=>isset(InvoiceText::TEXT[$code])?$code:'toc_preflight_unknown'];
                if ($failure instanceof InvoiceDriveFailure) {
                    $problems[$d['id']]['http_status']=(int)($failure->facts['http_status']??0);
                    $problems[$d['id']]['curl_errno']=(int)($failure->facts['curl_errno']??0);
                }
                $this->state((int)$d['id'],'toc_retry');
            }
        }
        if ($problems!==$originalProblems) $this->vault->save('toconline-preflight.enc',$problems);
        if ($parts) {
            try { $this->transmitBatch($ledger,$parts,$settings,$to); }
            catch (InvoiceTocWait) { return; }
        }
    }

    private function preflightProblems(): array
    {
        return $this->vault->has('toconline-preflight.enc')?$this->vault->read('toconline-preflight.enc'):[];
    }

    /** Safe manager view. Preflight failures never count as attempted emails. */
    public function deliveryProblems(): array
    {
        $problems=$this->preflightProblems();
        foreach ($this->ledger() as $entry) {
            $id=$entry['document_id'];
            if (!is_numeric($id)) continue;
            $reason=self::deliveryReason($entry);
            if ($reason!=='') $problems[$id]=['reason'=>$reason];
        }
        return $problems;
    }

    private function transmitBatch(array &$ledger,array $parts,array $settings,string $to,int $resendActor=0): void
    {
        $this->assertSendBudget($ledger);
        $mailId=count($parts)===1 && !$resendActor?$parts[0]['identity']:bin2hex(random_bytes(32));
        $wireParts=$parts;
        if ($resendActor) $wireParts[0]['identity']=$mailId;
        $message=self::batchMessage($wireParts,$settings['sender'],$mailId);
        $attempted=gmdate('c');
        foreach ($parts as $part) {
            $entry=['document_id'=>$part['id'],'nif'=>$settings['nif'],'sender'=>$settings['sender'],
                'state'=>'toc_sending','attempted_at'=>$attempted,'email_id'=>$mailId];
            if ($resendActor) {
                $previous=$ledger[$part['identity']];
                $history=$previous['previous_attempts']??[];
                unset($previous['previous_attempts']);
                $entry['previous_attempts']=[...$history,$previous];
                $entry['resent_by']=$resendActor;
                $entry['attachment_key']=$mailId;
            }
            $ledger[$part['identity']]=$ledger[$part['content']]=$entry;
        }
        // A single durable write reserves every attachment before the irreversible handoff.
        $this->vault->save('toconline-ledger.enc',$ledger);
        try {
            $ok=$this->transport ? ($this->transport)($to,$message) : @mail($to,$message['subject'],$message['body'],implode("\r\n",$message['headers']));
            $state=$ok?'toc_submitted':'toc_uncertain';
            $reason=$ok?'':'toc_mail_refused';
        } catch (Throwable) { $state='toc_uncertain';$reason='toc_mail_exception'; }
        foreach ($parts as $part) {
            $ledger[$part['identity']]['state']=$state;
            if ($reason!=='') $ledger[$part['identity']]['delivery_reason']=$reason;
            $ledger[$part['content']]=$ledger[$part['identity']];
        }
        $this->vault->save('toconline-ledger.enc',$ledger);
        foreach ($parts as $part) $this->state($part['id'],$state,self::receiptTiming($ledger[$part['identity']]));
    }

    /** Shared rolling budget leaves capacity for the domain's other mail. No sleep under the lock. */
    private function assertSendBudget(array $ledger): void
    {
        $now=time();$attempts=[];
        foreach ($ledger as $entry) {
            foreach ([...($entry['previous_attempts']??[]),$entry] as $attempt) {
                $at=strtotime((string)($attempt['attempted_at']??''));
                if ($at && $at>$now-3600) $attempts[$attempt['email_id']??((string)$attempt['document_id'].'|'.$at)]=$at;
            }
        }
        foreach ($this->diagnosticAttempts() as $key=>$entry) {
            $at=strtotime((string)($entry['attempted_at']??''));
            if ($at && $at>$now-3600) $attempts['diagnostic:'.$key]=$at;
        }
        if (count($attempts)>=self::EMAILS_PER_HOUR) {
            sort($attempts,SORT_NUMERIC);
            throw new InvoiceTocWait($attempts[count($attempts)-self::EMAILS_PER_HOUR]+3600);
        }
    }

    public function submitReceipt(array $d,array $candidates=[]): void
    {
        $settings=$this->settings();
        if (empty($settings['enabled']) || empty($settings['pilot_verified'])) return;
        if (($d['stage']??'')!=='archived' || empty($d['issued_on']) || !InvoiceReceiptAnalysis::buyerAccepted($d,$settings['nif'])) throw new RuntimeException('toc_ineligible');
        $id='receipt:'.$d['id']; $ledger=$this->ledger();
        $identity=self::receiptKey($d,$settings['nif']);
        // Each new scan gets its own external verdict. Repeated clicks on this ID never resend.
        $content=$identity; $existing=$ledger[$identity]??null;
        if ($existing) { $this->state($id,(string)$existing['document_id']===$id?$existing['state']:'toc_duplicate'); return; }
        $this->assertSendBudget($ledger);
        $this->drive->connect(); // Receipt-only cron runs may never have entered the portal sender.
        $parts=[];
        foreach ([$d['id']=>$d]+$candidates as $doc) {
            if (!isset($doc['id'])) continue;
            $first=$doc['id']===$d['id'];
            if (!$first && (($doc['stage']??'')!=='archived' || !in_array($doc['toconline_state']??'',['pending','toc_retry'],true)
                || !empty($doc['duplicate_keeper']) || ($doc['next_attempt']??0)>time() || empty($doc['issued_on'])
                || !InvoiceReceiptAnalysis::buyerAccepted($doc,$settings['nif']))) continue;
            $key=self::receiptKey($doc,$settings['nif']);
            if (isset($ledger[$key])) continue;
            if (!self::fits($parts,(int)$doc['size'])) {
                if ($first) throw new RuntimeException('toc_attachment_size');
                break;
            }
            try {
                $parts[]=$this->verifiedReceiptPart($doc,$key);
            } catch (Throwable $failure) {
                if ($first) throw $failure;
                break; // Its own worker turn will record the exact verification failure.
            }
            if (count($parts)===self::MAX_ATTACHMENTS) break;
        }
        if (!$parts) throw new RuntimeException('toc_ineligible');
        $this->transmitBatch($ledger,$parts,$settings,self::destination($settings['nif']));
    }

    public function receiptUnsent(string $id): bool
    {
        foreach ($this->ledger() as $e) if ((string)$e['document_id']==='receipt:'.$id) return false;
        return true;
    }
    public function receiptDuplicateConfirmed(string $id): bool
    {
        foreach ($this->ledger() as $e) if ((string)$e['document_id']==='receipt:'.$id
            && $e['nif']===($this->settings()['nif']??'') && $e['sender']===($this->settings()['sender']??'')
            && $e['state']==='toc_existing' && ($e['confirmation_source']??'')==='dkim_toconline') return true;
        return false;
    }

    /** Consolidate only fully equivalent scans; retain the signed acknowledgement as invoice-level evidence. */
    public function confirmEquivalentReceipts(array $ids,string $keeper): bool
    {
        $documents=$this->vault->read(InvoiceReceipts::FILE)['documents']??[];
        $key=InvoiceReceiptAnalysis::duplicateKey($documents[$keeper]??[]);
        if (!$key || count($ids)<2 || !in_array($keeper,$ids,true)) return false;
        foreach ($ids as $id) if (InvoiceReceiptAnalysis::duplicateKey($documents[$id]??[])!==$key) return false;
        $ledger=$this->ledger();$settings=$this->settings();$evidence=null;$ambiguous=[];
        foreach ($ledger as $e) {
            if (!in_array(substr((string)$e['document_id'],8),$ids,true) || !str_starts_with((string)$e['document_id'],'receipt:')
                || $e['nif']!==$settings['nif'] || $e['sender']!==$settings['sender']) continue;
            if (in_array($e['state'],['toc_accepted','toc_existing'],true) && ($e['confirmation_source']??'')==='dkim_toconline') $evidence=$e;
            if ($e['state']==='toc_review' && ($e['receipt_ambiguity']??'')==='multiple_scans'
                && ($e['receipt_suggestion']??'')==='toc_accepted' && !empty($e['receipt_hash'])) $ambiguous[$e['document_id']]=$e;
        }
        if (!$evidence && count($ambiguous)===count($ids)) {
            $hashes=array_unique(array_column($ambiguous,'receipt_hash'));
            if (count($hashes)===1) {
                $outside=false;
                foreach ($ledger as $e) if (($e['receipt_hash']??'')===reset($hashes) && ($e['receipt_ambiguity']??'')==='multiple_scans'
                    && !in_array(substr((string)$e['document_id'],8),$ids,true)) $outside=true;
                if (!$outside) $evidence=reset($ambiguous);
            }
        }
        if (!$evidence) return false;
        $accepted=$evidence['state']!=='toc_existing';
        foreach ($ids as $id) {
            $state=$id===$keeper?($accepted?'toc_accepted':'toc_existing'):'toc_duplicate_local';
            $found=false;
            foreach ($ledger as &$e) if ((string)$e['document_id']==='receipt:'.$id && $e['nif']===$settings['nif'] && $e['sender']===$settings['sender']) {
                $e['state']=$state;$e['confirmed_at']??=gmdate('c');$e['confirmation_source']='dkim_toconline';
                $e['equivalent_keeper']=$keeper;$e['receipt_hash']=$evidence['receipt_hash']??'';$found=true;
            }
            unset($e);
            // An unsent equivalent needs a durable terminal entry so reconciliation never resurrects it.
            if (!$found) {
                $d=$documents[$id];$identity=hash('sha256',$settings['nif']."\0receipt\0".$id."\0".$d['sha256']);
                $ledger[$identity]=['document_id'=>'receipt:'.$id,'nif'=>$settings['nif'],'sender'=>$settings['sender'],
                    'state'=>$state,'confirmation_source'=>'dkim_toconline','confirmed_at'=>gmdate('c'),
                    'equivalent_keeper'=>$keeper,'receipt_hash'=>$evidence['receipt_hash']??''];
            }
        }
        $this->vault->save('toconline-ledger.enc',$ledger);
        foreach ($ids as $id) $this->state('receipt:'.$id,$id===$keeper?($accepted?'toc_accepted':'toc_existing'):'toc_duplicate_local');
        return true;
    }
    public function receiptAccepted(string $id): bool
    {
        foreach ($this->ledger() as $e) if ((string)$e['document_id']==='receipt:'.$id
            && $e['nif']===($this->settings()['nif']??'') && $e['sender']===($this->settings()['sender']??'')
            && in_array($e['state'],['toc_accepted','toc_existing'],true) && ($e['confirmation_source']??'')==='dkim_toconline') return true;
        return false;
    }

    private function diagnosticAttempts(): array
    {
        $attempts=$this->vault->has('toconline-diagnostic-attempts.enc') ? $this->vault->read('toconline-diagnostic-attempts.enc') : [];
        // Keep the legacy one-shot record intact, including uncertain outcomes.
        if ($this->vault->has('toconline-diagnostic-resend.enc')) $attempts['legacy']=$this->vault->read('toconline-diagnostic-resend.enc');
        return $attempts;
    }

    public function diagnosticStatus(int $id, ?int $now=null): string
    {
        if ($id!==self::DIAGNOSTIC_DOCUMENT_ID) return 'unavailable';
        $attempts=$this->diagnosticAttempts(); $latest=0;
        foreach ($attempts as $attempt) {
            if (($attempt['state']??'')!=='submitted') return 'uncertain';
            $at=strtotime((string)($attempt['attempted_at']??''));
            if (!$at) return 'uncertain';
            $latest=max($latest,$at);
        }
        if (count($attempts)>=200) return 'unavailable';
        if ($latest && ($now??time())<$latest+self::DIAGNOSTIC_COOLDOWN) return 'cooldown';
        return 'available';
    }

    /** Explicit manual tests only. Caller holds room_check_invoices, as for run(). */
    public function diagnosticResend(int $id, array $actor, bool $confirmed, string $requestId, ?int $now=null): string
    {
        if (($actor['role']??'')!=='gerente' || (int)($actor['id']??0)<1) throw new RuntimeException('forbidden');
        if (!$confirmed || $this->diagnosticStatus($id,$now)!=='available') throw new RuntimeException('toc_diagnostic_unavailable');
        $attempts=$this->diagnosticAttempts();
        if (!preg_match('/\A[a-f0-9]{32}\z/',$requestId) || isset($attempts[$requestId])) throw new RuntimeException('toc_diagnostic_unavailable');
        $settings=$this->settings(); $to=self::destination((string)($settings['nif']??''));
        $ledger=$this->ledger();
        $s=$this->pdo->prepare("SELECT d.*,x.drive_id,x.drive_parent,a.portal FROM invoice_documents d JOIN invoice_document_delivery x ON x.document_id=d.id JOIN invoice_accounts a ON a.id=d.account_id WHERE d.id=? AND x.drive_state='verified' AND x.company_state='validated' AND d.format='pdf' AND a.portal='booking'");
        $s->execute([$id]); $d=$s->fetch(PDO::FETCH_ASSOC);
        if (!$d) throw new RuntimeException('toc_ineligible');
        [$identity,$content]=self::keys($d,$settings['nif']);
        // Both original deduplication keys must agree with this exact document and destination.
        foreach ([$identity,$content] as $key) {
            $entry=$ledger[$key]??null;
            if (!$entry || (int)$entry['document_id']!==$id || $entry['nif']!==$settings['nif']
                || $entry['sender']!==$settings['sender']
                || !in_array($entry['state'],['toc_submitted','toc_uncertain','toc_review','toc_no_confirmation','toc_accepted','toc_existing'],true)) {
                throw new RuntimeException('toc_ineligible');
            }
        }
        if ($ledger[$identity]!==$ledger[$content]) throw new RuntimeException('toc_ineligible');
        $this->assertSendBudget($ledger);
        $this->drive->connect(); $pdf=$this->drive->verifiedPdf($d); self::assertPdf($pdf,$d);
        $message=self::message($pdf,$identity,$settings['sender']);
        $attemptId=bin2hex(random_bytes(16));
        // Fresh email ID, unchanged attachment filename/PDF for original receipt correlation.
        $message['headers'][3]='Message-ID: <toc-diagnostic-'.$attemptId.'@check.welcomehostel.pt>';
        $attempt=['document_id'=>$id,'actor_id'=>(int)$actor['id'],'attempt_id'=>$attemptId,
            'attempted_at'=>gmdate('c',$now??time()),'state'=>'sending'];
        // Reserve before network I/O. Crash, false, or exception permanently consumes this action.
        $attempts[$requestId]=$attempt;
        $this->vault->save('toconline-diagnostic-attempts.enc',$attempts);
        try {
            $ok=$this->transport ? ($this->transport)($to,$message) : @mail($to,$message['subject'],$message['body'],implode("\r\n",$message['headers']));
            $attempt['state']=$ok?'submitted':'uncertain';
        } catch (Throwable) { $attempt['state']='uncertain'; }
        unset($pdf,$message);
        $attempts[$requestId]=$attempt;
        $this->vault->save('toconline-diagnostic-attempts.enc',$attempts);
        // Never reset the original ledger, final outcome, pilot flag, or automatic-send setting.
        return $attempt['state'];
    }

    public static function assertPdf(string $pdf, array $d): void
    {
        if (strlen($pdf)>10*1024*1024 || strlen($pdf)!==(int)$d['size_bytes'] || !str_starts_with($pdf,'%PDF-')
            || !hash_equals((string)$d['sha256'],hash('sha256',$pdf))) throw new RuntimeException('drive_verify');
    }
}


