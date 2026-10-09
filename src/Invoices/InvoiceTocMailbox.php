<?php
declare(strict_types=1);
require_once __DIR__.'/InvoiceToconline.php';

/** Optional read-only IMAPS receipt collector. No raw messages or passwords in diagnostics. */
final class InvoiceTocMailbox
{
    private const HOST='server50.romania-webhosting.com';
    public function __construct(private readonly InvoiceVault $vault, private readonly InvoiceToconline $toc) {}

    public function status(): array
    {
        $s=$this->vault->has('toconline-mailbox.enc')?$this->vault->read('toconline-mailbox.enc'):[];
        $r=$this->vault->has('toconline-receipts.enc')?$this->vault->read('toconline-receipts.enc'):[];
        return ['configured'=>!empty($s),'enabled'=>!empty($s['enabled']), 'available'=>function_exists('imap_open'),
            'folders'=>$s['folders']??['INBOX','INBOX.Contar Mais'],'checked_at'=>$r['checked_at']??null,
            'error'=>$r['error']??null,'unmatched'=>(int)($r['unmatched']??0)];
    }

    public function configure(array $input): void
    {
        $old=$this->vault->has('toconline-mailbox.enc')?$this->vault->read('toconline-mailbox.enc'):[];
        $sender=(string)($this->toc->settings()['sender']??'');
        if ($sender==='') throw new RuntimeException('toc_invalid_settings');
        $password=(string)($input['toc_mail_password']??'');
        if ($password==='') $password=($old['username']??'')===$sender?(string)($old['password']??''):'';
        $folders=array_values(array_unique(array_filter(array_map('trim',explode("\n",(string)($input['toc_mail_folders']??'INBOX'))))));
        if ($password==='' || strlen($password)>1024 || count($folders)<1 || count($folders)>5) throw new RuntimeException('toc_mail_settings');
        foreach ($folders as $folder) if (!preg_match('/\AINBOX(?:[.\/][a-zA-Z0-9 _.-]{1,120})?\z/',$folder)) throw new RuntimeException('toc_mail_settings');
        $candidate=['username'=>$sender,'password'=>$password,'folders'=>$folders,'enabled'=>!empty($input['toc_mail_enabled'])];
        // Verify TLS, authentication and every folder before saving or enabling.
        foreach ($folders as $folder) { $connection=$this->connect($candidate,$folder); imap_close($connection); }
        $this->vault->save('toconline-mailbox.enc',$candidate);
    }

    private function connect(array $settings, string $folder): mixed
    {
        if (!function_exists('imap_open')) throw new RuntimeException('toc_mail_unavailable');
        imap_timeout(IMAP_OPENTIMEOUT,10); imap_timeout(IMAP_READTIMEOUT,10); imap_timeout(IMAP_WRITETIMEOUT,10);
        // No /novalidate-cert, arbitrary host, port, shell authenticator, or write flags.
        $connection=@imap_open('{'.self::HOST.':993/imap/ssl/validate-cert}'.$folder,$settings['username'],$settings['password'],OP_READONLY,1,['DISABLE_AUTHENTICATOR'=>'GSSAPI']);
        imap_errors(); imap_alerts();
        if (!$connection) throw new RuntimeException('toc_mail_connect');
        return $connection;
    }

    /** Decode text only; never retrieve attachments, follow links, or execute message content. */
    private function text(mixed $connection, int $uid, object $part, string $section='', int $depth=0, int &$parts=0): string
    {
        if (++$parts>20 || $depth>4) return '';
        if (isset($part->parts)) {
            $text=''; foreach ($part->parts as $i=>$child) $text.=$this->text($connection,$uid,$child,$section===''?(string)($i+1):$section.'.'.($i+1),$depth+1,$parts)."\n";
            return $text;
        }
        if (($part->type??-1)!==0 || !in_array(strtoupper($part->subtype??''),['PLAIN','HTML'],true)
            || strtoupper($part->disposition??'')==='ATTACHMENT' || ($part->bytes??0)>262144) return '';
        $body=$section===''?@imap_body($connection,$uid,FT_UID|FT_PEEK):@imap_fetchbody($connection,$uid,$section,FT_UID|FT_PEEK);
        if (!is_string($body) || strlen($body)>262144) return '';
        $body=match($part->encoding??0) {3=>base64_decode($body,true)?:'',4=>quoted_printable_decode($body),default=>$body};
        foreach ($part->parameters??[] as $param) if (strtolower($param->attribute)==='charset') {
            try { $body=mb_convert_encoding($body,'UTF-8',$param->value); } catch (ValueError) { return ''; }
        }
        return html_entity_decode(strip_tags($body),ENT_QUOTES|ENT_HTML5,'UTF-8');
    }

    /** Caller owns room_check_invoices. Progress persists after each receipt; repeat processing is safe. */
    public function poll(): void
    {
        if (!$this->vault->has('toconline-mailbox.enc')) return;
        $settings=$this->vault->read('toconline-mailbox.enc'); if (empty($settings['enabled'])) return;
        $r=$this->vault->has('toconline-receipts.enc')?$this->vault->read('toconline-receipts.enc'):['cursors'=>[],'unmatched'=>0];
        $r['checked_at']=gmdate('c'); $r['error']=null;
        try {
            if (($settings['username']??'')!==($this->toc->settings()['sender']??'')) throw new RuntimeException('toc_mail_settings');
            $budget=50;
            foreach ($settings['folders'] as $folder) {
                if ($budget<=0) break;
                $connection=$this->connect($settings,$folder);
                try {
                    $status=@imap_status($connection,'{'.self::HOST.':993/imap/ssl/validate-cert}'.$folder,SA_UIDVALIDITY);
                    if (!$status || empty($status->uidvalidity)) throw new RuntimeException('toc_mail_connect');
                    $scope=hash('sha256',$settings['username']."\0".$folder."\0".$status->uidvalidity);
                    $cursor=(int)($r['cursors'][$scope]??0);
                    $criteria='FROM "no_reply@toconline.pt" SINCE "'.gmdate('d-M-Y',time()-90*86400).'" UID '.($cursor+1).':*';
                    $uids=@imap_search($connection,$criteria,SE_UID)?:[]; sort($uids,SORT_NUMERIC);
                    foreach ($uids as $uid) {
                        if ($uid<=$cursor) continue;
                        if ($budget--<=0) break;
                        $overview=@imap_fetch_overview($connection,(string)$uid,FT_UID);
                        if (!$overview || !isset($overview[0]->size)) throw new RuntimeException('toc_mail_connect');
                        if ($overview[0]->size>262144) { $r['unmatched']++; }
                        else {
                            $header=@imap_fetchheader($connection,$uid,FT_UID);
                            $structure=@imap_fetchstructure($connection,$uid,FT_UID);
                            if (!is_string($header) || !$structure) throw new RuntimeException('toc_mail_connect');
                            $parsed=imap_rfc822_parse_headers($header); $from=$parsed->from??[];
                            $address=count($from)===1?($from[0]->mailbox??'').'@'.($from[0]->host??''):'';
                            $parts=0; $body=$this->text($connection,$uid,$structure,'',0,$parts);
                            $result=$this->toc->receive($address,($parsed->in_reply_to??'')."\n".($parsed->references??''),$body,hash('sha256',$header."\n".$body));
                            if ($result['result']==='unmatched') $r['unmatched']++;
                        }
                        $r['cursors'][$scope]=(int)$uid;
                        $this->vault->save('toconline-receipts.enc',$r);
                    }
                } finally { imap_close($connection); imap_errors(); imap_alerts(); }
            }
        } catch (Throwable $e) {
            $r['error']=in_array($e->getMessage(),['toc_mail_connect','toc_mail_unavailable','toc_mail_settings'],true)?$e->getMessage():'toc_mail_connect';
        }
        $this->vault->save('toconline-receipts.enc',$r);
    }
}
