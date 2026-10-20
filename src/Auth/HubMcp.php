<?php
declare(strict_types=1);
require_once __DIR__.'/HubOAuth.php';
require_once dirname(__DIR__).'/Invoices/CloudbedsManualUpload.php';

final class HubMcp
{
    public function __construct(private readonly PDO $pdo, private readonly array $config) {}
    public static function tools(): array
    {
        $object=static fn(array $properties=[],array $required=[])=>['type'=>'object','properties'=>(object)$properties,'required'=>$required,'additionalProperties'=>false];
        $read=['readOnlyHint'=>true,'destructiveHint'=>false,'openWorldHint'=>false];
        $tool=static fn(string $name,string $description,string $scope,array $schema,array $annotations)=>[
            'name'=>$name,'description'=>$description,'inputSchema'=>$schema,'annotations'=>$annotations,
            'securitySchemes'=>[['type'=>'oauth2','scopes'=>[$scope]]],
            '_meta'=>['securitySchemes'=>[['type'=>'oauth2','scopes'=>[$scope]]]]];
        return [
            $tool('hub_get_profile','Confirm the authenticated Management Hub account, delegated scopes and connection. Use first to verify access. Does not create a browser session.',
                'hub:read',$object(),$read),
            $tool('hub_list_invoice_accounts','List invoice account IDs, properties and collection status. Does not return portal credentials or authentication codes.',
                'invoices:read',$object(),$read),
            $tool('hub_list_invoice_documents','Read documents and their actual Drive and TOConline states for one account and issue month. Pending or sent does not mean accepted. Paginate using after_id.',
                'invoices:read',$object(['account_id'=>['type'=>'integer','minimum'=>1],
                    'period'=>['type'=>'string','pattern'=>'^20[0-9]{2}-(0[1-9]|1[0-2])$'],
                    'after_id'=>['type'=>'integer','minimum'=>0]],['account_id','period']),$read),
            $tool('hub_import_cloudbeds_pdf','Import an owner-provided Cloudbeds PDF for Welcome or City. This validates company, property and issue date, deduplicates, and queues the existing Drive/TOConline pipeline. Use only when the owner authorizes submission. Pass the original PDF as base64, at most 2 MiB. Then check documents for real completion.',
                'cloudbeds:import',$object(['source'=>['type'=>'string','enum'=>['welcome','city']],
                    'pdf_base64'=>['type'=>'string','maxLength'=>2796204]],['source','pdf_base64']),
                ['readOnlyHint'=>false,'destructiveHint'=>false,'idempotentHint'=>true,'openWorldHint'=>true]),
        ];
    }

    public static function scope(string $name): string
    {
        foreach (self::tools() as $tool) if ($tool['name']===$name) return $tool['securitySchemes'][0]['scopes'][0];
        throw new RuntimeException('unknown_tool');
    }

    public function call(string $name, array $arguments, array $identity): array
    {
        $scope=self::scope($name);
        if (!in_array($scope,$identity['scopes'],true) || ($identity['user']['role']??'')!=='gerente') throw new RuntimeException('insufficient_scope',403);
        $allowed=match($name) {
            'hub_list_invoice_documents'=>['account_id','period','after_id'],
            'hub_import_cloudbeds_pdf'=>['source','pdf_base64'], default=>[]
        };
        if (array_diff(array_keys($arguments),$allowed)) throw new RuntimeException('invalid_request');
        $user=$identity['user'];
        // Only structural action metadata is audited. No PDF, invoice values or tokens.
        Auth::audit($this->pdo,(int)$user['id'],'connector_tool_called',['grant_id'=>$identity['grant_id'],'tool'=>$name]);
        if ($name==='hub_get_profile') return [
            'id'=>(string)$user['id'],'username'=>$user['username'],'display_name'=>$user['display_name'],
            'role'=>$user['role'],'scopes'=>$identity['scopes'],'origin'=>HubOAuth::ORIGIN,'connection_id'=>$identity['grant_id'],
        ];
        if ($name==='hub_list_invoice_accounts') {
            $accounts=new InvoiceAccounts($this->pdo); $result=[];
            foreach ($accounts->all() as $a) $result[]=[
                'id'=>(int)$a['id'],'portal'=>$a['portal'],'label'=>$a['label'],'status'=>$a['status'],
                'period_basis'=>$a['period_basis'],'schedule_enabled'=>(bool)$a['enabled'],
                'active'=>$accounts->active((int)$a['id']),'properties'=>(object)$accounts->properties((int)$a['id'])];
            return ['accounts'=>$result];
        }
        if ($name==='hub_list_invoice_documents') {
            $id=$arguments['account_id']??null; $period=$arguments['period']??null; $after=$arguments['after_id']??0;
            if (!is_int($id)||$id<1||!is_string($period)||!InvoiceService::validPeriod($period)||!is_int($after)||$after<0) throw new RuntimeException('invalid_request');
            (new InvoiceAccounts($this->pdo))->get($id);
            $s=$this->pdo->prepare('SELECT d.id,d.property_id,d.invoice_number,d.issued_on,d.period,d.period_basis,d.pipeline_state,
                v.drive_state,v.verified_at AS drive_verified_at,v.toconline_state,v.last_error
                FROM invoice_documents d LEFT JOIN invoice_document_delivery v ON v.document_id=d.id
                WHERE d.account_id=? AND d.period=? AND d.id>? ORDER BY d.id LIMIT 101');
            $s->execute([$id,$period,$after]); $rows=$s->fetchAll(PDO::FETCH_ASSOC); $more=count($rows)>100; $rows=array_slice($rows,0,100);
            return ['account_id'=>$id,'period'=>$period,'documents'=>$rows,'next_after_id'=>$more?(int)end($rows)['id']:null];
        }
        $source=$arguments['source']??null; $encoded=$arguments['pdf_base64']??null;
        if (!in_array($source,['welcome','city'],true) || !is_string($encoded) || strlen($encoded)>2796204) throw new RuntimeException('invalid_document');
        $pdf=base64_decode($encoded,true);
        if ($pdf===false || strlen($pdf)<8 || strlen($pdf)>2097152 || !str_starts_with($pdf,'%PDF-')) throw new RuntimeException('invalid_document');
        $vault=new InvoiceVault($this->config['invoices']['private_dir']);
        if ((int)$this->pdo->query("SELECT GET_LOCK('room_check_invoices',0)")->fetchColumn()!==1) throw new RuntimeException('worker_busy');
        $path=null;
        try {
            // Fresh exclusive private file; never a caller-provided filesystem path or URL.
            $path=$vault->path('connector-upload-'.bin2hex(random_bytes(16)).'.pdf');
            $previous=umask(0077);
            try { $stream=fopen($path,'xb'); } finally { umask($previous); }
            if ($stream===false) throw new RuntimeException('private_storage_unavailable');
            try { if (fwrite($stream,$pdf)!==strlen($pdf)) throw new RuntimeException('private_storage_unavailable'); }
            finally { fclose($stream); }
            $result=(new CloudbedsManualUpload($this->pdo,$vault))->store($path,$source,$user,true);
            return ['state'=>'queued','completion_verified'=>false]+$result;
        } finally {
            if ($path!==null && is_file($path)) unlink($path);
            $this->pdo->query("SELECT RELEASE_LOCK('room_check_invoices')");
        }
    }
}
