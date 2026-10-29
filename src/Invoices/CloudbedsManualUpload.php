<?php
declare(strict_types=1);
require_once __DIR__.'/CloudbedsInvoice.php';
require_once __DIR__.'/InvoiceHostelworldVerification.php';

/** Authenticated owner uploads, separate from email authentication. Caller holds the worker lock. */
final class CloudbedsManualUpload
{
    public function __construct(private readonly PDO $pdo,private readonly InvoiceVault $vault) {}

    public function installUpload(array $file,string $source,array $user,bool $https): array
    {
        InvoiceService::assertGerente($user);
        if (!$https) throw new RuntimeException('https_required');
        $path=$file['tmp_name']??null;
        if (($file['error']??null)!==UPLOAD_ERR_OK || !is_int($file['size']??null) || $file['size']<8
            || $file['size']>2097152 || !is_string($path) || !is_uploaded_file($path)) throw new RuntimeException('invalid_document');
        try { return $this->store($path,$source,$user,$https); }
        finally { if (is_uploaded_file($path)) unlink($path); }
    }

    public function store(string $path,string $source,array $user,bool $https): array
    {
        InvoiceService::assertGerente($user);
        if (!$https) throw new RuntimeException('https_required');
        if ((int)($user['id']??0)<1 || !in_array($source,['welcome','city'],true)) throw new RuntimeException('invalid_request');
        $settings=$this->vault->has('cloudbeds-settings.enc')?$this->vault->read('cloudbeds-settings.enc'):[];
        $id=(int)($settings['account_ids'][$source]??0);$accounts=new InvoiceAccounts($this->pdo);
        if ($id<1 || $accounts->get($id)['portal']!=='cloudbeds' || !$accounts->active($id)
            || !isset($accounts->activeProperties($id)[$source])) throw new RuntimeException('account_mismatch');
        $metadata=CloudbedsInvoice::read($path,InvoiceHostelworldVerification::companyNif($this->vault));
        if ($metadata['issued_on']>gmdate('Y-m-d') || $metadata['period']<=gmdate('Y-m',time()-730*86400)) throw new RuntimeException('invalid_document');
        $text=InvoicePdfText::read($path);$houses=[];
        if (preg_match('/\bWelcome\s+Guest\s*House\b/iu',$text)) $houses[]='welcome';
        if (preg_match('/\bCity\s+Cent(?:er|re)\s+Guest\s*House\b/iu',$text)) $houses[]='city';
        if ($houses!==[$source]) throw new RuntimeException('account_mismatch');
        $bytes=file_get_contents($path);$hash=hash('sha256',$bytes);$name='cloudbeds-inbox-'.$source.'-'.$hash.'.enc';
        if (!$this->vault->has($name)) {
            if (count(glob($this->vault->root.'/cloudbeds-inbox-*.enc')?:[])>=2000) throw new RuntimeException('document_limit');
            $this->vault->save($name,['version'=>1,'source'=>$source,'account_id'=>$id,'sha256'=>$hash,'received_at'=>time(),
                'authentication'=>'manager_upload','submitted_by'=>(int)$user['id'],'metadata'=>$metadata,'pdf'=>base64_encode($bytes)]);
        }
        $batch=(new InvoiceService($this->pdo))->collectBatch([['account_id'=>$id,'property_id'=>$source]],$metadata['period'],
            (int)$user['id'],hash('sha256','cloudbeds-manual-v1:'.$source.':'.$hash.':'.(int)$user['id']));
        return ['account_id'=>$id,'source'=>$source,'period'=>$metadata['period'],'batch_id'=>$batch];
    }
}
