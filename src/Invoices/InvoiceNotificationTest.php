<?php
declare(strict_types=1);
require_once __DIR__.'/InvoiceAlerts.php';
require_once dirname(__DIR__).'/Auth/Auth.php';

/** Explicit owner test, separate from real invoice failures. Caller holds the worker lock. */
final class InvoiceNotificationTest
{
    private const LATEST = 'notification-test-latest.enc';
    public function __construct(private readonly PDO $pdo, private readonly InvoiceVault $vault,
        private readonly array $config) {}

    private function name(string $id): string
    {
        if (!preg_match('/\A[a-f0-9]{32}\z/',$id)) throw new RuntimeException('invalid_request',400);
        return 'notification-test-'.$id.'.enc';
    }

    private function recipient(int $owner): array
    {
        $s=$this->pdo->query('SELECT enabled,recipient_user_id,template_name FROM invoice_notification_settings WHERE id=1');
        $settings=$s->fetch(PDO::FETCH_ASSOC) ?: [];
        $s=$this->pdo->prepare("SELECT id,mobile FROM users WHERE id=? AND role='gerente' AND is_active=1");
        $s->execute([$owner]); $user=$s->fetch(PDO::FETCH_ASSOC);
        if (!$user || empty($user['mobile']) || !(int)($settings['enabled']??0)
            || (int)($settings['recipient_user_id']??0)!==$owner
            || !preg_match('/\A[a-z0-9_]{1,100}\z/',(string)($settings['template_name']??''))) {
            throw new RuntimeException('forbidden',403);
        }
        $user['template_name']=$settings['template_name'];
        return $user;
    }

    public function queue(int $owner,string $id): array
    {
        $name=$this->name($id); $this->recipient($owner);
        if ($this->vault->has($name)) {
            $old=$this->vault->read($name);
            if (($old['owner_id']??null)!==$owner) throw new RuntimeException('forbidden',403);
            if ($old['state']==='queued' && !$this->vault->has(self::LATEST)) {
                $this->vault->save(self::LATEST,['request_id'=>$id]);
            }
            return $this->summary($old);
        }
        $latest=$this->status();
        if ($latest && (in_array($latest['state'],['queued','sending'],true)
            || time()-strtotime($latest['requested_at'].' UTC')<60)) throw new RuntimeException('worker_busy',409);
        $receipt=['request_id'=>$id,'owner_id'=>$owner,'state'=>'queued','requested_at'=>gmdate('Y-m-d H:i:s')];
        $this->vault->save($name,$receipt);
        $this->vault->save(self::LATEST,['request_id'=>$id]);
        Auth::audit($this->pdo,$owner,'invoice_notification_test_requested',['request_id'=>$id]);
        return $this->summary($receipt);
    }

    private function summary(array $receipt): array
    {
        return array_intersect_key($receipt,array_flip(['request_id','state','requested_at','finished_at','result_code','provider_http_status']));
    }

    public function status(): ?array
    {
        if (!$this->vault->has(self::LATEST)) return null;
        $id=$this->vault->read(self::LATEST)['request_id']??'';
        return $this->summary($this->vault->read($this->name($id)));
    }

    public function dispatch(?callable $sender=null): void
    {
        $status=$this->status(); if (!$status) return;
        $name=$this->name($status['request_id']); $receipt=$this->vault->read($name);
        if ($receipt['state']==='sending') {
            // A crash after provider acceptance must never resend the same test.
            $receipt['state']='unconfirmed'; $receipt['result_code']='delivery_unconfirmed';
            $receipt['finished_at']=gmdate('Y-m-d H:i:s'); $this->vault->save($name,$receipt); return;
        }
        if ($receipt['state']!=='queued') return;
        try {
            $owner=(int)$receipt['owner_id']; $recipient=$this->recipient($owner);
            $grant=$this->vault->read('windows-test-control.enc');
            $paired=$this->vault->read('windows-agent.enc');
            if (($grant['enabled']??false)!==true || ($grant['owner_id']??null)!==$owner
                || !is_string($grant['token_hash']??null) || !is_string($paired['token_hash']??null)
                || !hash_equals($grant['token_hash'],$paired['token_hash'])
                || !Auth::hasPermission($this->pdo,['id'=>$owner,'role'=>'gerente'],Auth::PERMISSION_INVOICES_RUN)) {
                throw new RuntimeException('forbidden',403);
            }
        } catch (Throwable) {
            $receipt['state']='cancelled'; $receipt['result_code']='authorization_unavailable';
            $receipt['finished_at']=gmdate('Y-m-d H:i:s'); $this->vault->save($name,$receipt); return;
        }
        $receipt['state']='sending'; $this->vault->save($name,$receipt);
        $values=['Booking.com','Teste do Management Hub','Teste',
            'Teste controlado do alerta WhatsApp. Não existe uma nova falha de recolha.',
            'Confirme a receção desta mensagem de teste.'];
        try {
            $id=$sender ? $sender($recipient['mobile'],$values,$recipient['template_name'])
                : (new WhatsAppCloudClient($this->config))->sendTemplate($recipient['mobile'],$values,'pt_PT',$recipient['template_name']);
            if (!is_string($id) || $id==='') throw new RuntimeException('delivery_unconfirmed');
            $receipt['state']='accepted'; $receipt['result_code']='provider_accepted';
            $receipt['message_id']=$id; // Private only; acceptance is not delivery.
        } catch (Throwable $e) {
            $receipt['state']='unconfirmed'; $receipt['result_code']='delivery_unconfirmed';
            $code=$e->getCode();
            if (is_int($code) && $code>=400 && $code<=599) $receipt['provider_http_status']=$code;
        }
        $receipt['finished_at']=gmdate('Y-m-d H:i:s'); $this->vault->save($name,$receipt);
    }
}
