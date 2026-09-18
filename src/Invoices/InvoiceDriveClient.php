<?php
declare(strict_types=1);
require_once __DIR__ . '/InvoiceVault.php';

/** OAuth credentials stay in the private encrypted vault. No arbitrary request hosts. */
final class InvoiceDriveClient
{
    private string $token = '';
    public function __construct(private readonly InvoiceVault $vault) {}
    public const CALLBACK = 'https://check.welcomehostel.pt/admin/invoice-drive.php';
    public function authorizationUrl(string $state): string
    {
        if (!$this->vault->has('drive-oauth.enc')) throw new RuntimeException('drive_not_configured');
        $c = $this->vault->read('drive-oauth.enc');
        if (empty($c['client_id']) || empty($c['client_secret'])) throw new RuntimeException('drive_not_configured');
        return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
            'client_id'=>$c['client_id'], 'redirect_uri'=>self::CALLBACK, 'response_type'=>'code',
            'scope'=>'https://www.googleapis.com/auth/drive.file', 'access_type'=>'offline', 'prompt'=>'consent',
            'state'=>$state, 'login_hint'=>'daniel.ciorcas@welcomehostel.pt'
        ]);
    }
    public function finishAuthorization(string $code): void
    {
        $c = $this->vault->read('drive-oauth.enc');
        $r = $this->request('POST', 'https://oauth2.googleapis.com/token', http_build_query([
            'client_id'=>$c['client_id'], 'client_secret'=>$c['client_secret'], 'code'=>$code,
            'redirect_uri'=>self::CALLBACK, 'grant_type'=>'authorization_code'
        ]), ['Content-Type: application/x-www-form-urlencoded']);
        if (empty($r['refresh_token']) || empty($r['access_token'])) throw new RuntimeException('drive_auth');
        $this->token = $r['access_token'];
        $about = $this->api('GET', 'about?fields=user(emailAddress)');
        if (strtolower($about['user']['emailAddress'] ?? '') !== 'daniel.ciorcas@welcomehostel.pt') throw new RuntimeException('drive_account_mismatch');
        $c['refresh_token'] = $r['refresh_token']; $this->vault->save('drive-oauth.enc', $c);
    }
    public function connect(): string
    {
        if (!$this->vault->has('drive-oauth.enc')) throw new RuntimeException('drive_not_configured');
        $c = $this->vault->read('drive-oauth.enc');
        foreach (['client_id', 'client_secret', 'refresh_token'] as $k) if (empty($c[$k])) throw new RuntimeException('drive_not_configured');
        $r = $this->request('POST', 'https://oauth2.googleapis.com/token',
            http_build_query(['client_id' => $c['client_id'], 'client_secret' => $c['client_secret'], 'refresh_token' => $c['refresh_token'], 'grant_type' => 'refresh_token']),
            ['Content-Type: application/x-www-form-urlencoded']);
        $this->token = (string) ($r['access_token'] ?? '');
        if ($this->token === '') throw new RuntimeException('drive_auth');
        $about = $this->api('GET', 'about?fields=user(emailAddress)');
        $email = strtolower((string) ($about['user']['emailAddress'] ?? ''));
        if ($email !== 'daniel.ciorcas@welcomehostel.pt') throw new RuntimeException('drive_account_mismatch');
        return $email;
    }
    public function api(string $method, string $path, ?array $data = null): array
    {
        return $this->request($method, 'https://www.googleapis.com/drive/v3/' . $path,
            $data === null ? null : json_encode($data, JSON_THROW_ON_ERROR), ['Content-Type: application/json']);
    }
    public function newId(): string
    {
        $r = $this->api('GET', 'files/generateIds?count=1&space=drive&type=files');
        $id = (string) ($r['ids'][0] ?? '');
        self::assertId($id); return $id;
    }
    public static function assertId(string $id): void
    {
        if (!preg_match('/\A[a-zA-Z0-9_-]{10,128}\z/', $id)) throw new RuntimeException('drive_invalid_id');
    }
    public function metadata(string $id): ?array
    {
        self::assertId($id);
        try { return $this->api('GET', 'files/' . $id . '?fields=id,size,md5Checksum,trashed,parents,mimeType,owners(emailAddress),capabilities(canAddChildren)'); }
        catch (RuntimeException $e) { if ($e->getMessage() === 'drive_not_found') return null; throw $e; }
    }
    public function folder(string $id, string $parent, string $name): void
    {
        self::assertId($id); self::assertId($parent);
        $existing = $this->metadata($id);
        if (!$existing) {
            try { $this->api('POST', 'files?fields=id', ['id' => $id, 'name' => $name, 'parents' => [$parent], 'mimeType' => 'application/vnd.google-apps.folder']); }
            catch (RuntimeException $e) { if ($e->getMessage() !== 'drive_conflict') throw $e; }
            $existing = $this->metadata($id);
        }
        if (!$existing || ($existing['trashed'] ?? true) || ($existing['mimeType'] ?? '') !== 'application/vnd.google-apps.folder'
            || !in_array($parent, $existing['parents'] ?? [], true)) throw new RuntimeException('drive_verify');
    }
    public function upload(string $id, string $parent, string $name, string $path, string $format): array
    {
        self::assertId($id); self::assertId($parent);
        $meta = $this->metadata($id);
        if (!$meta) {
            $mime = $format === 'pdf' ? 'application/pdf' : 'text/csv';
            $headers = [];
            $this->request('POST', 'https://www.googleapis.com/upload/drive/v3/files?uploadType=resumable',
                json_encode(['id' => $id, 'name' => $name, 'parents' => [$parent], 'mimeType' => $mime], JSON_THROW_ON_ERROR),
                ['Content-Type: application/json', 'X-Upload-Content-Type: ' . $mime, 'X-Upload-Content-Length: ' . filesize($path)], $headers);
            $url = $headers['location'] ?? '';
            if (!str_starts_with($url, 'https://www.googleapis.com/upload/drive/v3/files?')) throw new RuntimeException('drive_upload');
            $this->request('PUT', $url, (string) file_get_contents($path), ['Content-Type: ' . $mime]);
            $meta = $this->metadata($id);
        }
        if (!$meta) throw new RuntimeException('drive_verify');
        return $meta;
    }
    private function request(string $method, string $url, ?string $body, array $headers, array &$responseHeaders = []): array
    {
        if (!function_exists('curl_init')) throw new RuntimeException('drive_transport');
        $host = parse_url($url, PHP_URL_HOST);
        if (!in_array($host, ['oauth2.googleapis.com', 'www.googleapis.com'], true) || parse_url($url, PHP_URL_SCHEME) !== 'https') throw new RuntimeException('drive_transport');
        if ($this->token !== '' && $host === 'www.googleapis.com') $headers[] = 'Authorization: Bearer ' . $this->token;
        $h = curl_init($url);
        $responseHeaders = [];
        curl_setopt_array($h, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_TIMEOUT => 90, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_HTTPHEADER => $headers,
            CURLOPT_HEADERFUNCTION => static function ($c, string $line) use (&$responseHeaders): int {
                if (str_contains($line, ':')) { [$k, $v] = explode(':', $line, 2); $responseHeaders[strtolower(trim($k))] = trim($v); }
                return strlen($line);
            }]);
        if ($body !== null) curl_setopt($h, CURLOPT_POSTFIELDS, $body);
        $raw = curl_exec($h); $status = (int) curl_getinfo($h, CURLINFO_HTTP_CODE); curl_close($h);
        if ($raw === false) throw new RuntimeException('drive_network');
        $result = json_decode($raw, true);
        if ($status < 200 || $status >= 300) {
            $reason = is_array($result['error'] ?? null) ? ($result['error']['errors'][0]['reason'] ?? '') : '';
            $code = match (true) {
                $host === 'oauth2.googleapis.com' || $status === 401 => 'drive_auth',
                $reason === 'storageQuotaExceeded' => 'drive_quota',
                $status === 404 => 'drive_not_found', $status === 409 => 'drive_conflict',
                $status === 403 => 'drive_permission', $status === 429 || $status >= 500 => 'drive_network',
                default => 'drive_upload'
            };
            throw new RuntimeException($code);
        }
        return is_array($result) ? $result : [];
    }
}
