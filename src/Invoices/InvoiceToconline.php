<?php
declare(strict_types=1);
require_once __DIR__.'/InvoiceVault.php';

/** Every caller holds room_check_invoices. The encrypted ledger precedes network side effects. */
final class InvoiceToconline
{
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
        return [];
    }

    public static function keys(array $d, string $nif): array
    {
        // Booking invoice numbers identify the supplier invoice across properties and accounts.
        $number=strtoupper(trim((string)$d['invoice_number']));
        if (($d['portal']??'')!=='booking' || $number==='') throw new RuntimeException('toc_ineligible');
        return [hash('sha256',$nif."\0booking\0".$number), hash('sha256',$nif."\0".$d['sha256'])];
    }

    public static function message(string $pdf, string $key, string $sender): array
    {
        if (!preg_match('/\A[a-f0-9]{64}\z/',$key) || !filter_var($sender,FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/',$sender)) throw new RuntimeException('toc_invalid_settings');
        $boundary='hub_'.bin2hex(random_bytes(16));
        $headers=['From: '.$sender,'Reply-To: '.$sender,'MIME-Version: 1.0',
            'Message-ID: <toc-'.$key.'@check.welcomehostel.pt>', 'Content-Type: multipart/mixed; boundary="'.$boundary.'"'];
        $body='--'.$boundary."\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n".
            chunk_split(base64_encode('Management Hub — Booking invoice for digital archive.'),76,"\r\n").
            '--'.$boundary."\r\nContent-Type: application/pdf\r\nContent-Disposition: attachment; filename=\"booking-".$key.".pdf\"\r\nContent-Transfer-Encoding: base64\r\n\r\n".
            chunk_split(base64_encode($pdf),76,"\r\n").'--'.$boundary."--\r\n";
        return ['subject'=>'Management Hub - Booking - '.substr($key,0,16),'headers'=>$headers,'body'=>$body];
    }

    private function state(int $id, string $state): void
    {
        $this->pdo->prepare('UPDATE invoice_document_delivery SET toconline_state=? WHERE document_id=?')->execute([$state,$id]);
    }

    public function confirm(int $id, string $outcome='toc_accepted', int $actor=0): void
    {
        if (!in_array($outcome,['toc_accepted','toc_existing','toc_rejected'],true)) throw new RuntimeException('toc_ineligible');
        $settings=$this->settings(); $ledger=$this->ledger(); $found=false;
        foreach ($ledger as &$entry) {
            if ((int)$entry['document_id']===$id && $entry['nif']===$settings['nif'] && $entry['sender']===$settings['sender']
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
    public function reconcile(?int $now=null): void
    {
        $now??=time(); $ledger=$this->ledger();
        foreach ($ledger as &$entry) {
            if ($entry['state']==='toc_sending') $entry['state']='toc_uncertain';
            if ($entry['state']==='toc_submitted' && !empty($entry['attempted_at'])
                && strtotime($entry['attempted_at']) <= $now-72*3600) $entry['state']='toc_no_confirmation';
        } unset($entry);
        $this->vault->save('toconline-ledger.enc',$ledger);
        $nif=$this->settings()['nif']??'';
        foreach ($ledger as $entry) if ($entry['nif']===$nif) $this->state((int)$entry['document_id'],$entry['state']);
    }

    /** Mail headers/body are untrusted evidence. Exact correlation only; no automatic acceptance. */
    public function receive(string $from, string $references, string $text, string $receiptHash): array
    {
        if (!preg_match('/\A[a-f0-9]{64}\z/',$receiptHash)) throw new RuntimeException('toc_ineligible');
        if (strtolower(trim($from))!=='no_reply@toconline.pt') return ['result'=>'ignored'];
        $ledger=$this->ledger(); $settings=$this->settings(); $matches=[];
        preg_match_all('/(?:booking[-_]|toc-)([a-f0-9]{64})(?:\.pdf|@check\.welcomehostel\.pt)/i',$references."\n".$text,$tokens);
        foreach (array_unique($tokens[1]) as $key) {
            $entry=$ledger[strtolower($key)]??null;
            if ($entry && $entry['nif']===$settings['nif'] && $entry['sender']===$settings['sender']) $matches[(int)$entry['document_id']]=true;
        }
        if (count($matches)!==1) return ['result'=>'unmatched'];
        $id=(int)array_key_first($matches); $suggestion='toc_review';
        // Only the observed duplicate phrase is recognised. Unknown/new templates remain review.
        if (str_contains(mb_strtolower($text),'por já constarem no seu arquivo')) $suggestion='toc_existing';
        foreach ($ledger as &$entry) if ((int)$entry['document_id']===$id
            && $entry['nif']===$settings['nif'] && $entry['sender']===$settings['sender']
            && in_array($entry['state'],['toc_submitted','toc_uncertain','toc_sending','toc_no_confirmation','toc_review'],true)) {
            $entry['state']='toc_review'; $entry['receipt_hash']=$receiptHash;
            $entry['receipt_suggestion']=$suggestion; $entry['receipt_received_at']=gmdate('c');
        }
        unset($entry); $this->vault->save('toconline-ledger.enc',$ledger);
        foreach ($ledger as $entry) if ((int)$entry['document_id']===$id && $entry['nif']===$settings['nif']) { $this->state($id,$entry['state']); break; }
        return ['result'=>'matched','document_id'=>$id,'suggestion'=>$suggestion];
    }

    public function run(?int $pilotId=null): void
    {
        $this->reconcile();
        $settings=$this->settings();
        if ($pilotId && empty($settings['nif'])) throw new RuntimeException('toc_invalid_settings');
        if (empty($settings['nif']) || (!$pilotId && (empty($settings['enabled']) || empty($settings['pilot_verified'])))) return;
        $to=self::destination($settings['nif']); $ledger=$this->ledger();
        // A crash after writing intent must never cause another submission.
        foreach ($ledger as &$entry) if ($entry['state']==='toc_sending') $entry['state']='toc_uncertain';
        unset($entry); $this->vault->save('toconline-ledger.enc',$ledger);
        $query="SELECT d.*,x.drive_id,x.drive_parent,a.portal FROM invoice_documents d JOIN invoice_document_delivery x ON x.document_id=d.id JOIN invoice_accounts a ON a.id=d.account_id WHERE x.drive_state='verified' AND x.company_state='validated' AND d.format='pdf' AND a.portal='booking'";
        $s=$this->pdo->prepare($query.($pilotId?' AND d.id=?':' AND x.toconline_state IN (\'pending\',\'toc_retry\',\'toc_sending\')').' ORDER BY d.id LIMIT 20');
        $s->execute($pilotId?[$pilotId]:[]); $rows=$s->fetchAll(PDO::FETCH_ASSOC);
        if ($pilotId && !$rows) throw new RuntimeException('toc_ineligible');
        $connected=false;
        foreach ($rows as $d) {
            [$identity,$content]=self::keys($d,$settings['nif']);
            $existing=$ledger[$identity]??$ledger[$content]??null;
            if ($existing) {
                $this->state((int)$d['id'],(int)$existing['document_id']===(int)$d['id']?$existing['state']:'toc_duplicate'); continue;
            }
            try {
                if (!$connected) { $this->drive->connect(); $connected=true; }
                $pdf=$this->drive->verifiedPdf($d);
                self::assertPdf($pdf,$d);
                $message=self::message($pdf,$identity,$settings['sender']);
            } catch (Throwable) { $this->state((int)$d['id'],'toc_retry'); continue; }
            $entry=['document_id'=>(int)$d['id'],'nif'=>$settings['nif'],'sender'=>$settings['sender'],'state'=>'toc_sending','attempted_at'=>gmdate('c')];
            $ledger[$identity]=$entry; $ledger[$content]=$entry;
            $this->vault->save('toconline-ledger.enc',$ledger);
            $this->state((int)$d['id'],'toc_sending');
            try {
                $ok=$this->transport ? ($this->transport)($to,$message) : @mail($to,$message['subject'],$message['body'],implode("\r\n",$message['headers']));
                $entry['state']=$ok?'toc_submitted':'toc_uncertain';
            } catch (Throwable) { $entry['state']='toc_uncertain'; }
            unset($pdf,$message);
            $ledger[$identity]=$entry; $ledger[$content]=$entry;
            $this->vault->save('toconline-ledger.enc',$ledger); $this->state((int)$d['id'],$entry['state']);
        }
    }

    public static function assertPdf(string $pdf, array $d): void
    {
        if (strlen($pdf)>10*1024*1024 || strlen($pdf)!==(int)$d['size_bytes'] || !str_starts_with($pdf,'%PDF-')
            || !hash_equals((string)$d['sha256'],hash('sha256',$pdf))) throw new RuntimeException('drive_verify');
    }
}

