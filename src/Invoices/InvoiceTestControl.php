<?php
declare(strict_types=1);
require_once __DIR__.'/InvoiceWorkspace.php';
require_once dirname(__DIR__).'/Auth/Auth.php';

/** Explicit delegation to the already paired PC, not a browser/admin login. Caller holds the worker lock. */
final class InvoiceTestControl
{
    public const GRANT = 'windows-test-control.enc';
    private const AUTHORIZATION = 'booking-tests-2026-09-29';
    private const PROPERTIES = ['1140306','539828'];

    public function __construct(private readonly PDO $pdo, private readonly InvoiceVault $vault,
        private readonly InvoiceRemoteAgent $agent, private readonly array $whatsapp = []) {}

    /** Called only by the reviewed private CLI release; never re-enables a revoked/rotated grant. */
    public function provisionOnce(): string
    {
        if ($this->vault->has(self::GRANT)) return 'already_recorded';
        if (!$this->vault->has('windows-agent.enc')) return 'not_paired';
        $paired=$this->vault->read('windows-agent.enc');
        $hash=$paired['token_hash'] ?? '';
        if (!is_string($hash) || !preg_match('/\A[a-f0-9]{64}\z/',$hash)) return 'not_paired';
        $s=$this->pdo->prepare("SELECT id,role,is_active FROM users WHERE username=?");
        $s->execute(['gerente']); $owner=$s->fetch(PDO::FETCH_ASSOC);
        if (!$owner || $owner['role']!=='gerente' || (int)$owner['is_active']!==1) return 'owner_unavailable';
        $accounts=new InvoiceAccounts($this->pdo);
        if ($accounts->get(1)['portal']!=='booking' || !$accounts->active(1)) return 'account_unavailable';
        $properties=array_map('strval',array_keys($accounts->collectionProperties(1))); sort($properties,SORT_STRING);
        if ($properties!==self::PROPERTIES) return 'account_unavailable';
        $this->vault->save(self::GRANT,['authorization'=>self::AUTHORIZATION,'enabled'=>true,
            'owner_id'=>(int)$owner['id'],'token_hash'=>$hash,'created_at'=>InvoiceService::utcNow()]);
        Auth::audit($this->pdo,(int)$owner['id'],'invoice_test_access_granted',['authorization'=>self::AUTHORIZATION]);
        return 'granted';
    }

    private function owner(string $token): array
    {
        $this->agent->authenticate($token);
        $grant=$this->vault->has(self::GRANT) ? $this->vault->read(self::GRANT) : [];
        if (($grant['enabled']??false)!==true || ($grant['authorization']??'')!==self::AUTHORIZATION
            || !is_string($grant['token_hash']??null) || !hash_equals($grant['token_hash'],hash('sha256',$token))) {
            throw new RuntimeException('forbidden',403);
        }
        $s=$this->pdo->prepare('SELECT id,role,is_active FROM users WHERE id=?');
        $s->execute([(int)($grant['owner_id']??0)]); $user=$s->fetch(PDO::FETCH_ASSOC);
        if (!$user || (int)$user['is_active']!==1 || $user['role']!=='gerente'
            || !Auth::hasPermission($this->pdo,$user,Auth::PERMISSION_INVOICES_RUN)) throw new RuntimeException('forbidden',403);
        return $user;
    }

    public function handle(string $token,array $data): array
    {
        $owner=$this->owner($token); $action=$data['action']??null;
        $fields=match($action) {
            'control_status'=>['action','period'],
            'control_pause','control_resume','control_revoke','control_enable_captcha'=>['action'],
            'control_enable_alerts'=>['action','recipient'],
            'control_collect','control_login'=>['action','period','request_id'],
            default=>throw new RuntimeException('invalid_request',400),
        };
        if (array_diff(array_keys($data),$fields) || array_diff($fields,array_keys($data))) throw new RuntimeException('invalid_request',400);
        if (array_key_exists('period',$data) && (!is_string($data['period']) || !InvoiceService::validPeriod($data['period']))) throw new RuntimeException('invalid_request',400);
        if ($action==='control_status') return $this->status($data['period'],(int)$owner['id']);
        if ($action==='control_enable_captcha') {
            $this->agent->assertIdle();
            $accounts=new InvoiceAccounts($this->pdo);
            $options=$accounts->automationOptions($this->vault,1);
            $accounts->saveAutomationOptions($this->vault,1,
                ['captcha_mode'=>'collection']+($options['deny_loopback']?['deny_loopback'=>'on']:[]));
            Auth::audit($this->pdo,(int)$owner['id'],'invoice_collection_captcha_enabled');
            return ['control'=>'captcha','mode'=>'collection'];
        }
        if ($action==='control_enable_alerts') {
            if (!is_int($data['recipient']) || $data['recipient']!==(int)$owner['id']) throw new RuntimeException('forbidden',403);
            $state=$this->notificationStatus((int)$owner['id']);
            if (!$state['credentials_configured'] || !$state['owner_mobile_configured']
                || !preg_match('/\A[a-z0-9_]{1,100}\z/',$state['template_name'])
                || !in_array($state['recipient_id'],[null,(int)$owner['id']],true)) throw new RuntimeException('invalid_request',400);
            if (!$state['enabled']) {
                // Activation is for new failures; do not flood the owner with historical tests.
                $this->pdo->exec("UPDATE invoice_failure_alerts SET state='resolved' WHERE state IN ('pending','retry')");
                $this->pdo->exec("UPDATE invoice_drive_alerts SET state='resolved' WHERE state IN ('pending','retry')");
            }
            $this->pdo->prepare('UPDATE invoice_notification_settings SET enabled=1,recipient_user_id=? WHERE id=1')->execute([(int)$owner['id']]);
            Auth::audit($this->pdo,(int)$owner['id'],'invoice_failure_alerts_enabled',['recipient_id'=>(int)$owner['id']]);
            return ['control'=>'alerts','enabled'=>true,'recipient_id'=>(int)$owner['id']];
        }
        if ($action==='control_revoke') {
            $grant=$this->vault->read(self::GRANT); $grant['enabled']=false;
            $this->vault->save(self::GRANT,$grant);
            Auth::audit($this->pdo,(int)$owner['id'],'invoice_test_access_revoked');
            return ['control'=>'revoke','revoked'=>true];
        }
        if (in_array($action,['control_pause','control_resume'],true)) {
            $this->agent->maintenance();
            $mode=$action==='control_pause'?'paused':'windows';
            $this->agent->setMode($mode);
            Auth::audit($this->pdo,(int)$owner['id'],'invoice_test_mode',['mode'=>$mode]);
            return ['control'=>'mode','mode'=>$mode];
        }
        if (!is_string($data['request_id']) || !preg_match('/\A[a-f0-9]{32}\z/',$data['request_id'])) throw new RuntimeException('invalid_request',400);
        $key=hash('sha256',self::AUTHORIZATION.':'.$action.':'.$data['request_id']);
        $name='test-control-'.$data['request_id'].'.enc';
        $digest=hash('sha256',$action.':'.$data['period']);
        if ($this->vault->has($name)) {
            $receipt=$this->vault->read($name);
            if (($receipt['digest']??'')!==$digest) throw new RuntimeException('invalid_request',400);
            return $receipt['result'];
        }
        $accounts=new InvoiceAccounts($this->pdo); $account=$accounts->get(1);
        if ($account['portal']!=='booking' || !$accounts->active(1)) throw new RuntimeException('forbidden',403);
        $props=array_map('strval',array_keys($accounts->collectionProperties(1))); sort($props,SORT_STRING);
        if ($props!==self::PROPERTIES || $this->agent->mode()!=='windows') throw new RuntimeException('invalid_request',400);
        $service=new InvoiceService($this->pdo);
        if ($action==='control_collect') {
            if (InvoiceWorkspace::integration($account,$this->vault)!=='ready') throw new RuntimeException('auth_invalid',409);
            $targets=array_map(static fn($p)=>['account_id'=>1,'property_id'=>$p],$props);
            $id=$service->collectBatch($targets,$data['period'],(int)$owner['id'],$key);
            $result=['control'=>'collect','batch_id'=>$id];
        } else {
            // The fixed schedule key survives a response/file-write failure without another login.
            $s=$this->pdo->prepare('SELECT id,period,requested_by FROM invoice_tasks WHERE schedule_key=?');
            $s->execute([$key]); $existing=$s->fetch(PDO::FETCH_ASSOC);
            if ($existing && ($existing['period']!==$data['period'] || (int)$existing['requested_by']!==(int)$owner['id'])) throw new RuntimeException('invalid_request',400);
            if (!$existing && (int)$this->pdo->query("SELECT COUNT(*) FROM invoice_tasks WHERE account_id=1 AND kind='login' AND state IN ('queued','retry','running','waiting_auth')")->fetchColumn()) throw new RuntimeException('worker_busy',409);
            $id=$service->enqueue('login',$props[0],$data['period'],(int)$owner['id'],$key,1);
            $result=['control'=>'login','task_id'=>$id];
        }
        $this->vault->save($name,['digest'=>$digest,'result'=>$result]);
        Auth::audit($this->pdo,(int)$owner['id'],'invoice_test_requested',[
            'operation'=>$action,'request_id'=>$data['request_id'],'period'=>$data['period'],'result'=>$result]);
        return $result;
    }

    private function notificationStatus(int $owner): array
    {
        $settings=$this->pdo->query('SELECT enabled,recipient_user_id,template_name FROM invoice_notification_settings WHERE id=1')->fetch(PDO::FETCH_ASSOC) ?: [];
        $s=$this->pdo->prepare('SELECT username,display_name,mobile FROM users WHERE id=?'); $s->execute([$owner]); $user=$s->fetch(PDO::FETCH_ASSOC);
        $mobile=preg_replace('/\D+/','',(string)($user['mobile']??''));
        $file=(string)($this->whatsapp['secrets_file']??'');
        $secret=$file!=='' && is_file($file) ? json_decode((string)file_get_contents($file),true) : [];
        return ['enabled'=>(bool)($settings['enabled']??false),'recipient_id'=>isset($settings['recipient_user_id'])?(int)$settings['recipient_user_id']:null,
            'template_name'=>(string)($settings['template_name']??''),'owner_id'=>$owner,'owner_username'=>$user['username']??'',
            'owner_name'=>$user['display_name']??'','owner_mobile_configured'=>strlen($mobile)>=8 && strlen($mobile)<=15,
            'owner_mobile_suffix'=>$mobile!==''?substr($mobile,-4):null,
            'credentials_configured'=>!empty($secret['phone_number_id']) && !empty($secret['access_token'])];
    }

    private function status(string $period,int $owner): array
    {
        $s=$this->pdo->prepare('SELECT id,kind,property_id,period,state,result_code,imported_count,duplicate_count FROM invoice_tasks WHERE account_id=1 AND period=? ORDER BY id DESC LIMIT 20');
        $s->execute([$period]); $tasks=$s->fetchAll(PDO::FETCH_ASSOC);
        foreach ($tasks as &$task) {
            foreach (['id','imported_count','duplicate_count'] as $field) $task[$field]=(int)$task[$field];
            if (!is_string($task['result_code']) || !preg_match('/\A[a-z_]{1,64}\z/',$task['result_code'])) $task['result_code']=null;
        }
        unset($task);
        $s=$this->pdo->prepare('SELECT COUNT(*) FROM invoice_documents WHERE account_id=1 AND period=?'); $s->execute([$period]);
        $account=(new InvoiceAccounts($this->pdo))->get(1);
        return ['control'=>'status','agent'=>$this->agent->status(),'active_tasks'=>(int)$this->pdo->query("SELECT COUNT(*) FROM invoice_tasks WHERE state IN ('running','waiting_auth')")->fetchColumn(),
            'pending_tasks'=>(int)$this->pdo->query("SELECT COUNT(*) FROM invoice_tasks WHERE state IN ('queued','retry')")->fetchColumn(),
            'documents'=>(int)$s->fetchColumn(),'period'=>$period,'tasks'=>$tasks,
            'automation'=>(new InvoiceAccounts($this->pdo))->automationOptions($this->vault,1),
            'notifications'=>$this->notificationStatus($owner),
            'schedule'=>['enabled'=>(bool)$account['enabled'],'day'=>(int)$account['schedule_day'],'time'=>$account['schedule_time'],'timezone'=>'Europe/Lisbon']];
    }
}
