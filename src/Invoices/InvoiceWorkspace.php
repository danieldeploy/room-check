<?php
declare(strict_types=1);
require_once __DIR__ . '/InvoiceService.php';

/** Queries shared by the overview, invoices and activity screens. No secret values leave the vault. */
final class InvoiceWorkspace
{
    public const DOCUMENT_STATES = ['needs_attention','review','drive_pending','drive_failed','drive_verified','csv_pending','toconline_pending'];
    public const TASK_STATES = ['completed','failed','running','queued','retry','needs_auth','cancelled','waiting_auth'];
    public function __construct(private readonly PDO $pdo) {}

    public static function filters(array $input): array
    {
        $period = (string)($input['period'] ?? (new DateTimeImmutable('first day of last month', new DateTimeZone('Europe/Lisbon')))->format('Y-m'));
        $portal = (string)($input['portal'] ?? ''); $property = (string)($input['property'] ?? '');
        if (!InvoiceService::validPeriod($period) || ($portal !== '' && !isset(InvoiceAccounts::PORTALS[$portal]))
            || ($property !== '' && !preg_match('/\A[1-9][0-9]*:[a-zA-Z0-9_-]{1,64}\z/', $property))) throw new RuntimeException('invalid_request');
        return ['period'=>$period,'portal'=>$portal,'account'=>max(0,(int)($input['account'] ?? 0)),
            'property'=>$property,'q'=>mb_substr(trim((string)($input['q'] ?? '')),0,120),'state'=>(string)($input['state'] ?? '')];
    }

    private static function scope(array $f, string $alias): array
    {
        $where = ["$alias.period=?"]; $params=[$f['period']];
        if ($f['portal'] !== '') { $where[]='a.portal=?'; $params[]=$f['portal']; }
        if ($f['account']) { $where[]="$alias.account_id=?"; $params[]=$f['account']; }
        if ($f['property'] !== '') {
            [$account,$property]=explode(':',$f['property'],2);
            $where[]="$alias.account_id=? AND $alias.property_id=?"; $params[]=(int)$account; $params[]=$property;
        }
        return [implode(' AND ', $where), $params];
    }

    public function documents(array $f, int $before = PHP_INT_MAX): array
    {
        [$where,$params]=self::scope($f,'d');
        $where.=' AND d.id<?'; $params[]=$before;
        if ($f['q'] !== '') {
            $where.=" AND (d.invoice_number LIKE ? ESCAPE '!' OR a.label LIKE ? ESCAPE '!' OR p.label LIKE ? ESCAPE '!')";
            $search='%'.str_replace(['!','%','_'],['!!','!%','!_'],$f['q']).'%'; array_push($params,$search,$search,$search);
        }
        $where .= match ($f['state']) {
            'needs_attention'=>" AND (COALESCE(x.company_state,'review')='review' OR x.drive_state='failed')",
            'review'=>" AND COALESCE(x.company_state,'review')='review'",
            'drive_pending'=>" AND COALESCE(x.drive_state,'pending') IN ('pending','retry','uploading')",
            'drive_failed'=>" AND x.drive_state='failed'", 'drive_verified'=>" AND x.drive_state='verified'",
            'csv_pending'=>" AND d.pipeline_state='awaiting_csv_processing'",
            'toconline_pending'=>" AND COALESCE(x.toconline_state,'pending')='pending'", default=>'',
        };
        $s=$this->pdo->prepare("SELECT d.*,a.portal,a.label AS account_label,p.label AS property_label,x.company_state,x.company_name,
            x.drive_state,x.drive_id,x.attempts AS drive_attempts,x.next_attempt_at,x.last_error,x.local_deleted_at,x.toconline_state
            FROM invoice_documents d JOIN invoice_accounts a ON a.id=d.account_id
            LEFT JOIN invoice_account_properties p ON p.account_id=d.account_id AND p.property_id=d.property_id
            LEFT JOIN invoice_document_delivery x ON x.document_id=d.id WHERE $where ORDER BY d.id DESC LIMIT 51");
        $s->execute($params); return $s->fetchAll(PDO::FETCH_ASSOC);
    }

    public function stats(array $f): array
    {
        [$where,$params]=self::scope($f,'d');
        $s=$this->pdo->prepare("SELECT COUNT(*) AS total,
            SUM(CASE WHEN x.drive_state='verified' THEN 1 ELSE 0 END) AS archived,
            SUM(CASE WHEN COALESCE(x.company_state,'review')='review' OR x.drive_state='failed' THEN 1 ELSE 0 END) AS review,
            SUM(CASE WHEN d.pipeline_state='awaiting_csv_processing' THEN 1 ELSE 0 END) AS csv
            FROM invoice_documents d JOIN invoice_accounts a ON a.id=d.account_id LEFT JOIN invoice_document_delivery x ON x.document_id=d.id WHERE $where");
        $s->execute($params); return array_map('intval',$s->fetch(PDO::FETCH_ASSOC));
    }

    public function tasks(array $f, bool $legacyOnly=false, ?int $batch=null, int $before=PHP_INT_MAX, ?int $taskId=null): array
    {
        [$where,$params]=self::scope($f,'t');
        $where.=' AND t.id<?'; $params[]=$before;
        if ($taskId !== null) { $where.=' AND t.id=?'; $params[]=$taskId; }
        if (in_array($f['state'],self::TASK_STATES,true)) { $where.=' AND t.state=?'; $params[]=$f['state']; }
        if ($batch !== null) { $where.=' AND EXISTS (SELECT 1 FROM invoice_batch_tasks bt WHERE bt.task_id=t.id AND bt.batch_id=?)'; $params[]=$batch; }
        if ($legacyOnly) $where.=' AND NOT EXISTS (SELECT 1 FROM invoice_batch_tasks bt WHERE bt.task_id=t.id)';
        $s=$this->pdo->prepare("SELECT t.*,a.portal,a.label AS account_label,p.label AS property_label FROM invoice_tasks t
            JOIN invoice_accounts a ON a.id=t.account_id LEFT JOIN invoice_account_properties p ON p.account_id=t.account_id AND p.property_id=t.property_id
            WHERE $where ORDER BY t.id DESC" . ($batch === null ? ' LIMIT 51' : ''));
        $s->execute($params); return $s->fetchAll(PDO::FETCH_ASSOC);
    }

    public function batches(array $f, int $before=PHP_INT_MAX): array
    {
        [$where,$params]=self::scope($f,'t');
        if (in_array($f['state'],self::TASK_STATES,true)) { $where.=' AND t.state=?'; $params[]=$f['state']; }
        $where.=' AND b.id<?'; $params[]=$before;
        $s=$this->pdo->prepare("SELECT DISTINCT b.* FROM invoice_batches b JOIN invoice_batch_tasks bt ON bt.batch_id=b.id
            JOIN invoice_tasks t ON t.id=bt.task_id JOIN invoice_accounts a ON a.id=t.account_id WHERE $where ORDER BY b.id DESC LIMIT 21");
        $s->execute($params); $rows=$s->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['tasks']=$this->tasks(array_replace($f,['state'=>'']),false,(int)$row['id']);
            $row['summary']=self::summarize($row['tasks']);
        }
        unset($row); return $rows;
    }

    public static function summarize(array $tasks): array
    {
        $groups=[]; $new=0; $duplicates=0;
        foreach ($tasks as $task) { $groups[$task['account_id']][]=$task['state']; $new+=(int)$task['imported_count']; $duplicates+=(int)$task['duplicate_count']; }
        $counts=['total'=>count($groups),'completed'=>0,'failed'=>0,'running'=>0,'cancelled'=>0,'new'=>$new,'duplicates'=>$duplicates];
        foreach ($groups as $states) {
            $state=array_intersect($states,['queued','running','waiting_auth','retry']) ? 'running'
                : (array_intersect($states,['failed','needs_auth']) ? 'failed' : (in_array('cancelled',$states,true) ? 'cancelled' : 'completed'));
            $counts[$state]++;
        }
        $counts['state']=$counts['running'] ? 'running' : ($counts['failed'] ? ($counts['completed'] ? 'partial' : 'failed') : ($counts['cancelled'] ? 'cancelled' : 'completed'));
        return $counts;
    }

    public function accounts(string $period, string $propertyFilter=''): array
    {
        $s=$this->pdo->prepare("SELECT a.*,COALESCE(s.is_active,1) AS is_active,s.archived_at,
            (SELECT MAX(t.finished_at) FROM invoice_tasks t WHERE t.account_id=a.id AND t.kind='collect' AND t.state='completed') AS last_success,
            (SELECT COUNT(*) FROM invoice_documents d WHERE d.account_id=a.id AND d.period=?) AS document_count
            FROM invoice_accounts a LEFT JOIN invoice_account_settings s ON s.account_id=a.id ORDER BY a.portal,a.label");
        $s->execute([$period]); $rows=$s->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $q=$this->pdo->prepare("SELECT state,result_code,created_at,finished_at FROM invoice_tasks WHERE account_id=? AND kind='collect' AND period=? ORDER BY id DESC LIMIT 1");
            $q->execute([$row['id'],$period]); $row['last_task']=$q->fetch(PDO::FETCH_ASSOC) ?: null;
            $scheduled=$this->pdo->prepare('SELECT id FROM invoice_batches WHERE request_key=?');
            $scheduled->execute([hash('sha256','scheduled:'.$row['id'].':'.(new DateTimeImmutable('now',new DateTimeZone('Europe/Lisbon')))->format('Y-m'))]);
            $row['scheduled_current']=$scheduled->fetchColumn() !== false;
            $props=''; $args=[$row['id'],$period];
            if ($propertyFilter !== '') { [$owner,$property]=explode(':',$propertyFilter,2); $props=' AND property_id=? AND account_id=?'; array_push($args,$property,(int)$owner); }
            $latest=$this->pdo->prepare("SELECT state,result_code FROM invoice_tasks t WHERE account_id=? AND kind='collect' AND period=? $props
                AND id=(SELECT MAX(t2.id) FROM invoice_tasks t2 WHERE t2.account_id=t.account_id AND t2.property_id=t.property_id AND t2.period=t.period AND t2.kind='collect')");
            $latest->execute($args); $results=$latest->fetchAll(PDO::FETCH_ASSOC);
            $states=array_column($results,'state');
            $row['month_state']=!$states ? 'not_collected' : (array_intersect($states,['queued','running','retry','waiting_auth']) ? 'running'
                : (array_intersect($states,['failed','needs_auth']) ? (in_array('completed',$states,true)?'partial':'failed') : (in_array('cancelled',$states,true)?'cancelled':'completed')));
            $row['month_error']=null; foreach ($results as $result) if (in_array($result['state'],['failed','needs_auth','retry'],true)) { $row['month_error']=$result['result_code']; break; }
            if ($propertyFilter !== '') {
                $count=$this->pdo->prepare('SELECT COUNT(*) FROM invoice_documents WHERE account_id=? AND period=?'.$props); $count->execute($args); $row['document_count']=(int)$count->fetchColumn();
            }
        }
        unset($row); return $rows;
    }

    public static function integration(array $account, ?InvoiceVault $vault): string
    {
        if (in_array($account['portal'],['expedia','hostelsclub','email'],true)) return 'integration_unavailable';
        if (!$vault) return 'integration_setup';
        $id=(int)$account['id'];
        $credentials=$vault->has(InvoiceAccounts::secretName($id,'credentials')) || ($id===1 && $vault->has('booking-credentials.enc'));
        $map=is_file($vault->path('account-'.$id.'-map.json')) || ($id===1 && $account['portal']==='booking' && is_file($vault->path('booking-map.json')));
        if (!$credentials || !$map) return 'integration_setup';
        return empty($account['login_verified_at']) ? 'access_to_test' : 'ready';
    }

    public static function nextRun(array $account, ?DateTimeImmutable $now=null): ?string
    {
        if (!(int)$account['enabled'] || !(int)$account['is_active'] || $account['archived_at']) return null;
        $now=($now ?? new DateTimeImmutable('now'))->setTimezone(new DateTimeZone('Europe/Lisbon'));
        $due=new DateTimeImmutable($now->format('Y-m-').sprintf('%02d',(int)$account['schedule_day']).' '.$account['schedule_time'],new DateTimeZone('Europe/Lisbon'));
        if ($due <= $now && !empty($account['scheduled_current'])) $due=$due->modify('+1 month');
        return $due->format('d/m/Y H:i');
    }

    public static function propertiesFromInput(array $input, string $portal): array
    {
        $ids=$input['property_ids'] ?? []; $names=$input['property_labels'] ?? [];
        if (!is_array($ids) || !is_array($names) || count($names)>50 || count($ids)!==count($names)) throw new RuntimeException('invalid_request');
        $result=[]; $accountScope=in_array($portal,['airbnb','email'],true);
        foreach ($names as $i=>$name) {
            if (!is_string($name) || !is_string($ids[$i] ?? null)) throw new RuntimeException('invalid_request');
            $name=trim($name); $id=trim($ids[$i]); if ($name==='' && $id==='') continue;
            if ($accountScope && $id==='') $id='local_'.bin2hex(random_bytes(5));
            if ($name==='' || strlen($name)>120 || !preg_match('/\A[a-zA-Z0-9_-]{1,64}\z/',$id) || $id==='account' || isset($result[$id])) throw new RuntimeException('invalid_request');
            $result[$id]=$name;
        }
        if (!$result) throw new RuntimeException('properties_required');
        return $result;
    }
}
