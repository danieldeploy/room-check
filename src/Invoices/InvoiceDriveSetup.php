<?php
declare(strict_types=1);
require_once __DIR__.'/InvoiceService.php';
require_once __DIR__.'/InvoiceDriveClient.php';

/** First-time client installation. The Google consent and folder test remain separate. */
final class InvoiceDriveSetup
{
    public const MAX_BYTES = 32768;

    public function __construct(private readonly InvoiceVault $vault) {}

    private static function validClient(array $client): bool
    {
        return is_string($client['client_id']??null)
            && preg_match('/\A[0-9]{6,30}-[a-zA-Z0-9_-]{8,100}\.apps\.googleusercontent\.com\z/',$client['client_id'])===1
            && is_string($client['client_secret']??null)
            && preg_match('/\A[\x21-\x7e]{16,256}\z/',$client['client_secret'])===1;
    }

    public function clientConfigured(): bool
    {
        try { return $this->vault->has('drive-oauth.enc') && self::validClient($this->vault->read('drive-oauth.enc')); }
        catch (Throwable) { return false; }
    }

    public function install(string $json,array $user,bool $https): string
    {
        InvoiceService::assertGerente($user);
        if (!$https) throw new RuntimeException('https_required');
        if ($json==='' || strlen($json)>self::MAX_BYTES) throw new RuntimeException('drive_client_invalid');
        try { $payload=json_decode($json,true,16,JSON_THROW_ON_ERROR); }
        catch (Throwable) { throw new RuntimeException('drive_client_invalid'); }
        if (!is_array($payload) || !is_array($payload['web']??null) || array_key_exists('installed',$payload)) throw new RuntimeException('drive_client_invalid');
        $client=$payload['web'];
        if (!self::validClient($client)
            || !is_array($client['redirect_uris']??null) || !in_array(InvoiceDriveClient::CALLBACK,$client['redirect_uris'],true)
            || !in_array($client['auth_uri']??null,['https://accounts.google.com/o/oauth2/auth','https://accounts.google.com/o/oauth2/v2/auth'],true)
            || ($client['token_uri']??null)!=='https://oauth2.googleapis.com/token') throw new RuntimeException('drive_client_invalid');
        try { $current=$this->vault->has('drive-oauth.enc') ? $this->vault->read('drive-oauth.enc') : []; }
        catch (Throwable) { throw new RuntimeException('private_storage_unavailable'); }
        if ($current) {
            // Reimporting the same file must not rotate encryption or lose a refresh token.
            if (!is_string($current['client_id']??null) || !is_string($current['client_secret']??null)
                || !hash_equals($current['client_id'],$client['client_id']) || !hash_equals($current['client_secret'],$client['client_secret'])) {
                throw new RuntimeException('drive_client_conflict');
            }
            return 'already_configured';
        }
        // Do not import tokens, URLs, or other fields supplied by the uploaded file.
        $this->vault->save('drive-oauth.enc',['client_id'=>$client['client_id'],'client_secret'=>$client['client_secret']]);
        return 'configured';
    }

    public function installUpload(array $file,array $user,bool $https): string
    {
        InvoiceService::assertGerente($user);
        if (!$https) throw new RuntimeException('https_required');
        $path=$file['tmp_name']??null;
        if (($file['error']??null)!==UPLOAD_ERR_OK || !is_int($file['size']??null) || $file['size']<1
            || $file['size']>self::MAX_BYTES || !is_string($path) || !is_uploaded_file($path)) throw new RuntimeException('drive_client_invalid');
        try {
            $json=file_get_contents($path,false,null,0,self::MAX_BYTES+1);
            if (!is_string($json)) throw new RuntimeException('drive_client_invalid');
            return $this->install($json,$user,$https);
        } finally {
            // Remove the temporary plaintext upload as soon as validation/storage finishes.
            if (is_uploaded_file($path)) unlink($path);
        }
    }
}
