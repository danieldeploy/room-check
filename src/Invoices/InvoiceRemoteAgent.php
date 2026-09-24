<?php
declare(strict_types=1);
require_once __DIR__ . '/InvoiceTaskLifecycle.php';
require_once __DIR__ . '/InvoiceAuth.php';

/** One trusted Windows executor. Every mutation requires the existing MySQL worker lock. */
final class InvoiceRemoteAgent
{
    public const LEASE_SECONDS = 1200;
    public const BOOKING_FRESH_LOGIN_SMOKE_KEY = 'booking-login-smoke:2:2026-09-24';
    public const CHUNK_BYTES = 196608;
    public const TOTAL_BYTES = 20971520;
    private const CONFIG = 'windows-agent.enc';
    private const LEASE = 'windows-lease.enc';
    private readonly InvoiceVault $vault;

    public function __construct(private readonly PDO $pdo, private readonly array $config)
    {
        $this->vault = new InvoiceVault((string)($config['private_dir'] ?? ''));
    }

    private function settings(): array
    {
        return $this->vault->has(self::CONFIG) ? $this->vault->read(self::CONFIG) : ['mode'=>'local'];
    }

    public function mode(): string
    {
        $mode = $this->settings()['mode'] ?? '';
        if (!in_array($mode, ['local','paused','windows'], true)) throw new RuntimeException('worker_unavailable');
        return $mode;
    }

    public function status(): array
    {
        $s = $this->settings();
        return ['mode'=>$this->mode(), 'paired'=>!empty($s['token_hash']), 'last_seen'=>$s['last_seen'] ?? null,
            'probe_code'=>$s['probe_code'] ?? null, 'probe_at'=>$s['probe_at'] ?? null];
    }

    public function assertIdle(): void
    {
        // Do not shorten a lease: the Windows process may still be running without connectivity.
        $lease = $this->lease();
        if ($lease && empty($lease['finished']) && $lease['expires'] > time()) throw new RuntimeException('worker_busy');
        if ((int)$this->pdo->query("SELECT COUNT(*) FROM invoice_tasks WHERE state IN ('running','waiting_auth')")->fetchColumn()) {
            throw new RuntimeException('worker_busy');
        }
    }

    public function pair(): string
    {
        $this->assertIdle();
        $token = bin2hex(random_bytes(32));
        $this->vault->save(self::CONFIG, ['mode'=>'paused','token_hash'=>hash('sha256',$token)]);
        $this->pdo->exec('UPDATE invoice_settings SET browser_ready=0,browser_checked_at=NULL WHERE id=1');
        return $token;
    }

    /** Seal the new key to the computer; browser/operator never receives a plaintext secret. */
    public function pairEncrypted(string $publicKey): array
    {
        if (strlen($publicKey)>8192 || !str_starts_with($publicKey,'-----BEGIN PUBLIC KEY-----')) {
            throw new RuntimeException('agent_pair_invalid');
        }
        $key=openssl_pkey_get_public($publicKey);
        $details=$key ? openssl_pkey_get_details($key) : false;
        if (!$details || $details['type']!==OPENSSL_KEYTYPE_RSA || $details['bits']!==3072) {
            throw new RuntimeException('agent_pair_invalid');
        }
        $this->assertIdle();
        $token=bin2hex(random_bytes(32));
        if (!openssl_public_encrypt($token,$cipher,$key,OPENSSL_PKCS1_OAEP_PADDING)) {
            throw new RuntimeException('agent_pair_invalid');
        }
        $der=base64_decode(preg_replace('~-----[^-]+-----|\s~','',$details['key']),true);
        if ($der===false) throw new RuntimeException('agent_pair_invalid');
        $this->vault->save(self::CONFIG,['mode'=>'paused','token_hash'=>hash('sha256',$token)]);
        $this->pdo->exec('UPDATE invoice_settings SET browser_ready=0,browser_checked_at=NULL WHERE id=1');
        return ['version'=>1,'algorithm'=>'RSA-OAEP-SHA1','fingerprint'=>hash('sha256',$der),'ciphertext'=>base64_encode($cipher)];
    }

    public function setMode(string $mode): void
    {
        $this->assertIdle();
        if (!in_array($mode, ['local','paused','windows'], true)) throw new RuntimeException('invalid_request');
        $s = $this->settings();
        if ($mode === 'windows' && (empty($s['token_hash']) || ($s['probe_code'] ?? '') !== 'ok'
            || strtotime(($s['probe_at'] ?? '').' UTC') < time()-900
            || strtotime(($s['last_seen'] ?? '').' UTC') < time()-120)) throw new RuntimeException('agent_test_required');
        $s['mode'] = $mode;
        $this->vault->save(self::CONFIG, $s);
        $this->pdo->prepare('UPDATE invoice_settings SET browser_ready=?,browser_checked_at=? WHERE id=1')
            ->execute([(int)($mode==='windows'),$mode==='windows' ? $s['probe_at'] : null]);
    }

    public function revoke(): void
    {
        // Stop authorization immediately, but retain the lease fence until its expiry.
        $this->vault->save(self::CONFIG, ['mode'=>'paused']);
        $this->pdo->exec('UPDATE invoice_settings SET browser_ready=0,browser_checked_at=NULL WHERE id=1');
    }

    public function authenticate(string $token): void
    {
        $hash = $this->settings()['token_hash'] ?? '';
        if (!preg_match('/\A[a-f0-9]{64}\z/', $token) || !is_string($hash) || strlen($hash)!==64
            || !hash_equals($hash,hash('sha256',$token))) throw new RuntimeException('forbidden',403);
    }

    /** Short requests only. Never run Chrome, Drive uploads or notification delivery here. */
    public function handle(string $token, array $data): array
    {
        $this->authenticate($token);
        $this->maintenance();
        $s = $this->settings();
        $s['last_seen'] = InvoiceService::utcNow();
        $this->vault->save(self::CONFIG, $s);
        $action = $data['action'] ?? '';
        if ($action==='ping') return ['protocol'=>1,'mode'=>$s['mode'],'server_time'=>time()];
        if ($action==='probe') {
            if (!in_array($data['code'] ?? '', ['ok','browser_unavailable'], true)) throw new RuntimeException('invalid_request',400);
            $s['probe_code']=$data['code']; $s['probe_at']=InvoiceService::utcNow();
            $this->vault->save(self::CONFIG,$s);
            if ($s['mode']==='windows') $this->pdo->prepare('UPDATE invoice_settings SET browser_ready=?,browser_checked_at=? WHERE id=1')
                ->execute([(int)($data['code']==='ok'),$s['probe_at']]);
            return ['accepted'=>true];
        }
        if ($s['mode']!=='windows') return ['mode'=>$s['mode'],'job'=>null];
        $this->pdo->prepare('UPDATE invoice_settings SET worker_seen_at=? WHERE id=1')->execute([$s['last_seen']]);
        if ($action==='claim') return $this->claim((string)($data['claim_id'] ?? ''));
        $lease = $this->ownedLease($data);
        if ($action==='complete') return $this->complete($lease,$data);
        if (!empty($lease['finished'])) throw new RuntimeException('lease_expired',409);
        if ($action==='pulse') return ['accepted'=>true,'expires'=>$lease['expires'],'server_time'=>time()];
        if ($action==='upload') return $this->upload($lease,$data);
        if ($action==='challenge') return $this->challenge($lease,$data);
        throw new RuntimeException('invalid_request',400);
    }

    private function lease(): ?array
    {
        return $this->vault->has(self::LEASE) ? $this->vault->read(self::LEASE) : null;
    }

    public function maintenance(): void
    {
        $lease = $this->lease();
        if ($lease && empty($lease['finished']) && $lease['expires'] <= time()) {
            $job=$this->job((int)$lease['job']['id']);
            if (in_array($job['state'],['running','waiting_auth'],true)) $this->fail($job,'interrupted');
            $lease['finished']=true; $lease['expired']=true;
            unset($lease['input'],$lease['challenge_value']);
            $this->vault->save(self::LEASE,$lease);
            $this->cleanup($lease);
        }
        // Recover a crash between database and private-state writes without stealing a live lease.
        $jobs=$this->pdo->query("SELECT * FROM invoice_tasks WHERE state IN ('running','waiting_auth')")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($jobs as $job) {
            if (!$lease || !empty($lease['finished']) || (int)$lease['job']['id']!==(int)$job['id']) $this->fail($job,'interrupted');
        }
    }

    private function job(int $id): array
    {
        $s=$this->pdo->prepare('SELECT * FROM invoice_tasks WHERE id=?'); $s->execute([$id]);
        $job=$s->fetch(PDO::FETCH_ASSOC);
        if (!$job) throw new RuntimeException('lease_expired',409);
        return $job;
    }

    private function claim(string $claimId): array
    {
        if (!preg_match('/\A[a-f0-9]{32}\z/',$claimId)) throw new RuntimeException('invalid_request',400);
        $old=$this->lease();
        if ($old && empty($old['finished'])) {
            return hash_equals($old['claim_id'],$claimId) ? $this->offer($old) : ['job'=>null,'busy'=>true];
        }
        if ($old && hash_equals($old['claim_id'],$claimId)) return ['job'=>null];
        if ($old) $this->cleanup($old);
        $service=new InvoiceService($this->pdo);
        $service->scheduleDue(new DateTimeImmutable('now'));
        $s=$this->pdo->prepare("SELECT * FROM invoice_tasks WHERE state IN ('queued','retry') AND (next_attempt_at IS NULL OR next_attempt_at<=?) ORDER BY attempts,id LIMIT 1");
        $s->execute([InvoiceService::utcNow()]); $job=$s->fetch(PDO::FETCH_ASSOC);
        if (!$job) return ['job'=>null];
        $accounts=new InvoiceAccounts($this->pdo); $account=$accounts->get((int)$job['account_id']);
        if ($job['kind']!=='preflight' && (!$accounts->active((int)$account['id'])
            || !isset($accounts->collectionProperties((int)$account['id'])[$job['property_id']]))) {
            $this->pdo->prepare("UPDATE invoice_tasks SET state='cancelled',result_code='account_inactive',active_key=NULL,finished_at=? WHERE id=?")
                ->execute([InvoiceService::utcNow(),$job['id']]);
            return ['job'=>null];
        }
        $job['attempts']=(int)$job['attempts']+1;
        $this->pdo->prepare("UPDATE invoice_tasks SET state='running',attempts=?,started_at=? WHERE id=?")
            ->execute([$job['attempts'],InvoiceService::utcNow(),$job['id']]);
        try {
            $input=['action'=>$job['kind'],'accountId'=>(int)$account['id'],'portal'=>$account['portal'],
                'property'=>$job['property_id'],'period'=>$job['period'],'periodBasis'=>$account['period_basis']];
            if ($job['kind']==='login' && (int)$account['id']===1 && $account['portal']==='booking'
                && ($job['schedule_key'] ?? null)===self::BOOKING_FRESH_LOGIN_SMOKE_KEY) {
                $input['browserProfile']='fresh_login';
            }
            if ($job['kind']==='discover' && $account['portal']==='booking') {
                $properties=$accounts->collectionProperties((int)$account['id']);
                $input['propertyLabel']=(string)($properties[$job['property_id']] ?? '');
            }
            if ($job['kind']!=='preflight') {
                $input['credentials']=$accounts->credentials($this->vault,(int)$account['id']);
                $input['authMethod']=$account['auth_method'];
                if (($input['browserProfile'] ?? null)!=='fresh_login') {
                    $session=InvoiceAccounts::secretName((int)$account['id'],'session');
                    $input['session']=$this->vault->has($session) ? $this->vault->read($session) : [];
                }
                if ($job['kind']!=='discover' && !($job['kind']==='login' && $account['portal']==='booking')
                    && $account['portal']!=='email') {
                    $map='account-'.$account['id'].'-map.json';
                    if (!$this->vault->has($map) && (int)$account['id']===1 && $account['portal']==='booking') $map='booking-map.json';
                    $file=$this->vault->path($map);
                    if (!is_file($file) || filesize($file)>131072) throw new RuntimeException('connector_unconfigured');
                    $input['map']=json_decode((string)file_get_contents($file),true,32,JSON_THROW_ON_ERROR);
                }
            }
            $lease=['lease'=>bin2hex(random_bytes(32)),'claim_id'=>$claimId,'job'=>$job,'input'=>$input,
                'expires'=>time()+self::LEASE_SECONDS,'uploads'=>[],'finished'=>false];
            $this->vault->save(self::LEASE,$lease);
            return $this->offer($lease);
        } catch (Throwable $e) { $this->fail($job,$e->getMessage()); return ['job'=>null]; }
    }

    private function offer(array $lease): array
    {
        return ['job'=>['id'=>(int)$lease['job']['id'],'lease'=>$lease['lease'],'expires'=>$lease['expires'],
            'server_time'=>time(),'input'=>$lease['input']]];
    }

    private function ownedLease(array $data): array
    {
        $lease=$this->lease(); $id=$data['lease'] ?? '';
        if (!$lease || !is_string($id) || !hash_equals($lease['lease'],$id)
            || (int)($data['task_id'] ?? 0)!==(int)$lease['job']['id'] || !empty($lease['expired'])
            || (empty($lease['finished']) && $lease['expires']<=time())) throw new RuntimeException('lease_expired',409);
        return $lease;
    }

    private function uploadPath(array $lease,int $id): string
    {
        return $this->vault->path('windows-'.$lease['lease'].'-'.$id.'.part');
    }

    private function upload(array $lease,array $data): array
    {
        $id=$data['id'] ?? null; $offset=$data['offset'] ?? null; $size=$data['size'] ?? null;
        $hash=$data['sha256'] ?? ''; $meta=$data['metadata'] ?? null;
        $bytes=is_string($data['chunk'] ?? null) ? base64_decode($data['chunk'],true) : false;
        if ($lease['job']['kind']!=='collect' || !is_int($id) || $id<0 || $id>=100 || !is_int($offset) || $offset<0
            || !is_int($size) || $size<8 || $size>self::TOTAL_BYTES || !is_string($hash) || !preg_match('/\A[a-f0-9]{64}\z/',$hash)
            || !is_array($meta) || strlen(json_encode($meta,JSON_THROW_ON_ERROR))>4096 || isset($meta['content']) || isset($meta['pdf'])
            || $bytes===false || strlen($bytes)<1 || strlen($bytes)>self::CHUNK_BYTES || $offset+strlen($bytes)>$size) {
            throw new RuntimeException('invalid_request',400);
        }
        $descriptor=['size'=>$size,'sha256'=>$hash,'metadata'=>$meta];
        if (isset($lease['uploads'][$id]) && $lease['uploads'][$id]!==$descriptor) throw new RuntimeException('upload_conflict',409);
        if (!isset($lease['uploads'][$id])) {
            $total=$size; foreach ($lease['uploads'] as $entry) $total+=$entry['size'];
            if ($total>self::TOTAL_BYTES) throw new RuntimeException('document_limit',413);
            $lease['uploads'][$id]=$descriptor;
            $this->vault->save(self::LEASE,$lease);
        }
        $file=$this->uploadPath($lease,$id);
        if (!is_file($file)) InvoiceVault::atomicWrite($file,'');
        $handle=fopen($file,'r+b');
        if (!$handle) throw new RuntimeException('private_storage_unavailable');
        try {
            $current=fstat($handle)['size'];
            if ($offset>$current) throw new RuntimeException('upload_conflict',409);
            fseek($handle,$offset);
            // A repeated chunk after a lost response is accepted only if its bytes match.
            if ($offset<$current) {
                if ($offset+strlen($bytes)>$current || fread($handle,strlen($bytes))!==$bytes) throw new RuntimeException('upload_conflict',409);
            } else {
                if (fwrite($handle,$bytes)!==strlen($bytes) || !fflush($handle)) {
                    ftruncate($handle,$offset); throw new RuntimeException('private_storage_unavailable');
                }
            }
            return ['accepted'=>true,'offset'=>$offset+strlen($bytes)];
        } finally { fclose($handle); }
    }

    private function challenge(array $lease,array $data): array
    {
        if ($lease['job']['kind']==='preflight') throw new RuntimeException('invalid_request',400);
        $request=$data['challenge'] ?? null;
        if (!is_array($request)) throw new RuntimeException('invalid_request',400);
        $id=(string)($request['id'] ?? '');
        if (!preg_match('/\A[a-f0-9]{32}\z/',$id) || ($request['method'] ?? '')!=='sms') throw new RuntimeException('invalid_request',400);
        $auth=new InvoiceAuth($this->pdo,$this->vault);
        if (!isset($lease['challenge_id'])) {
            // Use server time; clock skew on the Windows PC must not bind an SMS to another job.
            $auth->register($lease['job'],['id'=>$id,'method'=>'sms','created'=>time()]);
            $lease['challenge_id']=$id;
            $this->vault->save(self::LEASE,$lease);
        } elseif (!hash_equals($lease['challenge_id'],$id)) throw new RuntimeException('auth_invalid',409);
        // Retain an encrypted delivery receipt until acknowledgement, so a lost HTTPS reply
        // does not lose the one-time value. It is available only to this live task lease.
        if (isset($data['ack']) && $data['ack']===true) {
            unset($lease['challenge_value']); $lease['challenge_delivered']=true;
            $this->vault->save(self::LEASE,$lease);
            return ['ready'=>true];
        }
        if (empty($lease['challenge_delivered']) && !isset($lease['challenge_value'])) {
            $value=$auth->consume($id,(int)$lease['job']['id']);
            if ($value!==null) { $lease['challenge_value']=$value; $this->vault->save(self::LEASE,$lease); }
        }
        return ['ready'=>true,'value'=>$lease['challenge_value'] ?? null];
    }

    private function complete(array $lease,array $data): array
    {
        $result=$data['result'] ?? null;
        if (!is_array($result)) throw new RuntimeException('invalid_request',400);
        $digest=hash('sha256',json_encode($result,JSON_THROW_ON_ERROR));
        if (isset($lease['completion_hash']) && !hash_equals($lease['completion_hash'],$digest)) throw new RuntimeException('completion_conflict',409);
        $job=$this->job((int)$lease['job']['id']);
        if (!empty($lease['finished'])) return ['accepted'=>true,'state'=>$job['state']];
        $lease['completion_hash']=$digest;
        $this->vault->save(self::LEASE,$lease);
        if (in_array($job['state'],['running','waiting_auth'],true)) {
            $this->pdo->beginTransaction();
            try {
                $code=$result['code'] ?? 'worker_failed';
                if (in_array($job['kind'],['discover','login'],true) && isset($result['diagnostic'])) {
                    $draft=$result['diagnostic'];
                    $encoded=is_array($draft) ? json_encode($draft,JSON_THROW_ON_ERROR) : false;
                    if (!$encoded || strlen($encoded)>65536 || ($draft['validated'] ?? null)!==false
                        || ($draft['portal'] ?? null)!==$this->pdo->query('SELECT portal FROM invoice_accounts WHERE id='.(int)$job['account_id'])->fetchColumn()) {
                        throw new RuntimeException('invalid_document');
                    }
                    $suffix=$job['kind']==='login' ? 'login-diagnostic.enc' : 'map-diagnostic.enc';
                    $this->vault->save('account-'.$job['account_id'].'-'.$suffix,$draft);
                }
                if ($code==='session_active') {
                    if ($job['kind']!=='login'
                        || $this->pdo->query('SELECT portal FROM invoice_accounts WHERE id='.(int)$job['account_id'])->fetchColumn()!=='booking'
                        || ($result['diagnostic']['authenticated_session'] ?? null)!==true
                        || ($result['diagnostic']['login_attempted'] ?? null)!==false
                        || ($result['documents'] ?? null)!==[] || isset($result['session']) || $lease['uploads']) {
                        throw new RuntimeException('invalid_document');
                    }
                    (new InvoiceTaskLifecycle($this->pdo))->complete($this->vault,$job,$result);
                } elseif (in_array($code,['ok','no_invoices'],true)) {
                    if ($job['kind']==='discover' && !isset($result['diagnostic'])) throw new RuntimeException('invalid_document');
                    if ($job['kind']==='login' && $this->pdo->query('SELECT portal FROM invoice_accounts WHERE id='.(int)$job['account_id'])->fetchColumn()==='booking'
                        && (($result['diagnostic']['authenticated_session'] ?? null)!==true
                            || ($result['diagnostic']['login_attempted'] ?? null)!==true)) throw new RuntimeException('invalid_document');
                    $ids=$result['documents'] ?? [];
                    if (!is_array($ids) || !array_is_list($ids) || count($ids)!==count(array_unique($ids,SORT_REGULAR))
                        || ($job['kind']==='collect' && count($ids)!==count($lease['uploads']))
                        || ($job['kind']!=='collect' && $ids)) throw new RuntimeException('invalid_document');
                    foreach ($ids as $id) if (!is_int($id) || !isset($lease['uploads'][$id])) throw new RuntimeException('invalid_document');
                    $documents=(function() use ($ids,$lease): Generator {
                        foreach ($ids as $id) {
                            $entry=$lease['uploads'][$id]; $file=$this->uploadPath($lease,$id);
                            clearstatcache(true,$file);
                            if (!is_file($file) || filesize($file)!==$entry['size'] || hash_file('sha256',$file)!==$entry['sha256']) throw new RuntimeException('invalid_document');
                            yield array_merge($entry['metadata'],['content'=>base64_encode((string)file_get_contents($file))]);
                        }
                    })();
                    (new InvoiceTaskLifecycle($this->pdo))->complete($this->vault,$job,$result,$documents);
                } else $this->fail($job,is_string($code)?$code:'worker_failed');
                $this->pdo->commit();
            } catch (Throwable $e) {
                if ($this->pdo->inTransaction()) $this->pdo->rollBack();
                $this->fail($job,$e->getMessage());
            }
        }
        $lease['finished']=true;
        unset($lease['input'],$lease['challenge_value']);
        $this->vault->save(self::LEASE,$lease);
        $this->cleanup($lease);
        return ['accepted'=>true,'state'=>$this->job((int)$job['id'])['state']];
    }

    private function fail(array $job,string $code): void
    {
        (new InvoiceTaskLifecycle($this->pdo))->fail($job,$code,new InvoiceAlerts($this->pdo,$this->config['whatsapp'] ?? []));
        (new InvoiceAuth($this->pdo,$this->vault))->expire((int)$job['id']);
    }

    private function cleanup(array $lease): void
    {
        foreach (array_keys($lease['uploads'] ?? []) as $id) {
            $file=$this->uploadPath($lease,(int)$id); if (is_file($file)) unlink($file);
        }
        (new InvoiceAuth($this->pdo,$this->vault))->expire((int)$lease['job']['id']);
    }
}
