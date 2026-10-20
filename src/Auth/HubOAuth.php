<?php
declare(strict_types=1);
require_once __DIR__ . '/Auth.php';

/** Narrow authorization-code/PKCE delegation for the owner's ChatGPT connection.
 * Public clients, opaque random tokens, server-side grants, no password grant.
 * This never creates a browser session or provisions an administrative account.
 */
final class HubOAuth
{
    public const ORIGIN = 'https://check.welcomehostel.pt';
    public const RESOURCE = self::ORIGIN . '/mcp.php';
    public const CALLBACK = 'https://chatgpt.com/connector_platform_oauth_redirect';
    public const SCOPES = ['hub:read', 'invoices:read', 'cloudbeds:import'];
    public const ACCESS_TTL = 900;
    public const REFRESH_TTL = 7776000; // 90 days of inactivity
    public const GRANT_TTL = 31536000; // explicit annual renewal

    public function __construct(private readonly PDO $pdo, private readonly ?int $clock = null) {}
    private function now(): int { return $this->clock ?? time(); }
    private function lock(): string { return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : ''; }
    public static function randomToken(): string { return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='); }
    public static function challenge(string $verifier): string { return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='); }

    public static function metadata(): array
    {
        return ['issuer'=>self::ORIGIN, 'authorization_endpoint'=>self::ORIGIN.'/oauth-authorize.php',
            'token_endpoint'=>self::ORIGIN.'/oauth.php?action=token',
            'registration_endpoint'=>self::ORIGIN.'/oauth.php?action=register',
            'revocation_endpoint'=>self::ORIGIN.'/oauth.php?action=revoke',
            'response_types_supported'=>['code'], 'grant_types_supported'=>['authorization_code','refresh_token'],
            'token_endpoint_auth_methods_supported'=>['none'], 'revocation_endpoint_auth_methods_supported'=>['none'],
            'code_challenge_methods_supported'=>['S256'], 'scopes_supported'=>self::SCOPES,
            'authorization_response_iss_parameter_supported'=>true];
    }

    public static function resourceMetadata(): array
    {
        return ['resource'=>self::RESOURCE, 'authorization_servers'=>[self::ORIGIN],
            'scopes_supported'=>self::SCOPES, 'bearer_methods_supported'=>['header']];
    }

    public static function clientId(string $redirect): string
    {
        if ($redirect !== self::CALLBACK && !preg_match('~\Ahttps://chatgpt\.com/connector/oauth/[A-Za-z0-9_-]{16,128}\z~D', $redirect)) {
            throw new RuntimeException('invalid_redirect_uri', 400);
        }
        return $redirect === self::CALLBACK ? 'management-hub-chatgpt' : 'hub_' . hash('sha256', $redirect);
    }

    /** A public client has no secret; registration does not grant access.
     * Deduplication by exact allowlisted callback avoids unbounded public storage.
     */
    public static function register(array $input): array
    {
        $redirects = $input['redirect_uris'] ?? null;
        if (!is_array($redirects) || count($redirects) !== 1 || !is_string($redirects[0] ?? null)
            || ($input['token_endpoint_auth_method'] ?? 'none') !== 'none'
            || array_diff($input['grant_types'] ?? ['authorization_code','refresh_token'], ['authorization_code','refresh_token'])
            || ($input['response_types'] ?? ['code']) !== ['code']) throw new RuntimeException('invalid_client_metadata', 400);
        return ['client_id'=>self::clientId($redirects[0]), 'client_name'=>'Management Hub / ChatGPT',
            'redirect_uris'=>$redirects, 'token_endpoint_auth_method'=>'none',
            'grant_types'=>['authorization_code','refresh_token'], 'response_types'=>['code']];
    }

    public static function scopes(string $scope): array
    {
        $scopes = array_values(array_unique(explode(' ', $scope)));
        if (!$scope || array_diff($scopes, self::SCOPES) || !in_array('hub:read', $scopes, true)) throw new RuntimeException('invalid_scope', 400);
        return array_values(array_intersect(self::SCOPES, $scopes));
    }

    public static function authorization(array $input): array
    {
        $r = [];
        foreach (['client_id','redirect_uri','response_type','code_challenge','code_challenge_method','state','resource'] as $field) {
            if (!isset($input[$field]) || !is_string($input[$field]) || strlen($input[$field]) > 512) throw new RuntimeException('invalid_request', 400);
            $r[$field] = $input[$field];
        }
        if ($r['client_id'] !== self::clientId($r['redirect_uri']) || $r['response_type'] !== 'code'
            || $r['code_challenge_method'] !== 'S256' || !preg_match('/\A[A-Za-z0-9_-]{43}\z/D', $r['code_challenge'])
            || $r['state'] === '' || preg_match('/[\x00-\x20\x7f]/', $r['state'])
            || $r['resource'] !== self::RESOURCE) throw new RuntimeException('invalid_request', 400);
        $r['scope'] = implode(' ', self::scopes(is_string($input['scope'] ?? null) ? $input['scope'] : implode(' ', self::SCOPES)));
        return $r;
    }

    public static function callback(array $request, array $result): string
    {
        // Validate again; neither success nor error can redirect to an arbitrary origin.
        $r = self::authorization($request);
        return $r['redirect_uri'] . '?' . http_build_query($result + ['state'=>$r['state'], 'iss'=>self::ORIGIN], '', '&', PHP_QUERY_RFC3986);
    }

    private function user(int $id): array
    {
        $s = $this->pdo->prepare('SELECT id,username,display_name,role,is_active,password_hash FROM users WHERE id=?');
        $s->execute([$id]); $u = $s->fetch(PDO::FETCH_ASSOC);
        if (!$u || (int)$u['is_active'] !== 1 || $u['role'] !== 'gerente') throw new RuntimeException('access_denied', 403);
        return $u;
    }

    public function approve(array $request, int $userId, array $selected): string
    {
        $r = self::authorization($request); $u = $this->user($userId);
        $scopes = self::scopes(implode(' ', $selected));
        if (array_diff($scopes, explode(' ', $r['scope']))) throw new RuntimeException('invalid_scope', 400);
        $id = bin2hex(random_bytes(16)); $code = self::randomToken(); $now = $this->now();
        $this->pdo->beginTransaction();
        try {
            // Serialize grants per owner, including concurrent consent tabs.
            $s = $this->pdo->prepare('SELECT id FROM users WHERE id=?' . $this->lock()); $s->execute([$userId]);
            $s = $this->pdo->prepare('SELECT COUNT(*) FROM hub_oauth_grants WHERE user_id=? AND password_stamp=? AND revoked_at IS NULL AND expires_at>? AND idle_expires_at>?');
            $s->execute([$userId, hash('sha256',$u['password_hash']), $now, $now]);
            if ((int)$s->fetchColumn() >= 20) throw new RuntimeException('connection_limit', 400);
            $this->pdo->prepare('INSERT INTO hub_oauth_grants (id,user_id,client_id,scope,password_stamp,created_at,expires_at,idle_expires_at) VALUES (?,?,?,?,?,?,?,?)')
                ->execute([$id,$userId,$r['client_id'],implode(' ', $scopes),hash('sha256', $u['password_hash']),$now,$now+self::GRANT_TTL,$now+300]);
            $this->pdo->prepare('INSERT INTO hub_oauth_codes (token_hash,grant_id,redirect_uri,challenge,expires_at) VALUES (?,?,?,?,?)')
                ->execute([hash('sha256',$code),$id,$r['redirect_uri'],$r['code_challenge'],$now+300]);
            Auth::audit($this->pdo,$userId,'connector_authorized',['grant_id'=>$id,'scopes'=>$scopes]);
            $this->pdo->commit(); return $code;
        } catch (Throwable $e) { if ($this->pdo->inTransaction()) $this->pdo->rollBack(); throw $e; }
    }

    private function grant(string $id, bool $lock = false): array
    {
        $s = $this->pdo->prepare('SELECT * FROM hub_oauth_grants WHERE id=?' . ($lock ? $this->lock() : ''));
        $s->execute([$id]); $g = $s->fetch(PDO::FETCH_ASSOC);
        if (!$g || $g['revoked_at'] !== null || (int)$g['expires_at'] <= $this->now() || (int)$g['idle_expires_at'] <= $this->now()) throw new RuntimeException('invalid_grant', 400);
        try { $u = $this->user((int)$g['user_id']); } catch (RuntimeException) { throw new RuntimeException('invalid_grant', 400); }
        if (!hash_equals($g['password_stamp'], hash('sha256',$u['password_hash']))) throw new RuntimeException('invalid_grant', 400);
        unset($u['password_hash']); $g['user'] = $u; return $g;
    }

    private function issue(array $grant): array
    {
        $access = self::randomToken(); $refresh = self::randomToken(); $now = $this->now();
        $idle = min($now + self::REFRESH_TTL, (int)$grant['expires_at']);
        $accessEnd = min($now + self::ACCESS_TTL, $idle);
        $s = $this->pdo->prepare('INSERT INTO hub_oauth_tokens (token_hash,grant_id,kind,expires_at) VALUES (?,?,?,?)');
        $s->execute([hash('sha256',$access),$grant['id'],'access',$accessEnd]);
        $s->execute([hash('sha256',$refresh),$grant['id'],'refresh',$idle]);
        $this->pdo->prepare('UPDATE hub_oauth_grants SET last_used_at=?,idle_expires_at=? WHERE id=?')->execute([$now,$idle,$grant['id']]);
        // Consumed refresh hashes are retained until grant expiry for reuse detection.
        $this->pdo->prepare("DELETE FROM hub_oauth_tokens WHERE grant_id=? AND kind='access' AND expires_at<=?")->execute([$grant['id'],$now]);
        return ['access_token'=>$access,'token_type'=>'Bearer','expires_in'=>$accessEnd-$now,
            'refresh_token'=>$refresh,'scope'=>$grant['scope']];
    }

    public function token(array $input): array
    {
        foreach (['client_id','resource','grant_type'] as $key) if (!is_string($input[$key] ?? null)) throw new RuntimeException('invalid_request', 400);
        if ($input['resource'] !== self::RESOURCE || isset($input['client_secret'])) throw new RuntimeException('invalid_target', 400);
        $refresh = $input['grant_type'] === 'refresh_token';
        if (!$refresh && $input['grant_type'] !== 'authorization_code') throw new RuntimeException('unsupported_grant_type', 400);
        $value = $input[$refresh ? 'refresh_token' : 'code'] ?? null;
        if (!is_string($value) || !preg_match('/\A[A-Za-z0-9_-]{43}\z/D', $value)) throw new RuntimeException('invalid_grant', 400);
        $hash = hash('sha256', $value); $table = $refresh ? 'hub_oauth_tokens' : 'hub_oauth_codes';
        $s = $this->pdo->prepare('SELECT grant_id FROM '.$table.' WHERE token_hash=?'); $s->execute([$hash]); $id = $s->fetchColumn();
        if (!$id) throw new RuntimeException('invalid_grant', 400);
        $this->pdo->beginTransaction();
        try {
            $g = $this->grant((string)$id, true);
            $s = $this->pdo->prepare('SELECT * FROM '.$table.' WHERE token_hash=?'.$this->lock()); $s->execute([$hash]); $t = $s->fetch(PDO::FETCH_ASSOC);
            if (!$t || $g['client_id'] !== $input['client_id'] || (int)$t['expires_at'] <= $this->now()
                || ($refresh && $t['kind'] !== 'refresh')) throw new RuntimeException('invalid_grant', 400);
            if ($refresh) {
                if (isset($input['scope']) && $input['scope'] !== $g['scope']) throw new RuntimeException('invalid_scope', 400);
            } else {
                $v = $input['code_verifier'] ?? '';
                if (!is_string($v) || !preg_match('/\A[A-Za-z0-9._~-]{43,128}\z/D', $v)
                    || !hash_equals($t['challenge'], self::challenge($v))
                    || ($input['redirect_uri'] ?? null) !== $t['redirect_uri']) throw new RuntimeException('invalid_grant', 400);
            }
            if ($t['consumed_at'] !== null) {
                $this->pdo->prepare('UPDATE hub_oauth_grants SET revoked_at=? WHERE id=?')->execute([$this->now(),$id]);
                Auth::audit($this->pdo,(int)$g['user_id'],'connector_reuse_blocked',['grant_id'=>$id]);
                $this->pdo->commit(); throw new RuntimeException('invalid_grant', 400);
            }
            $this->pdo->prepare('UPDATE '.$table.' SET consumed_at=? WHERE token_hash=?')->execute([$this->now(),$hash]);
            $result = $this->issue($g);
            $this->pdo->commit(); return $result;
        } catch (Throwable $e) { if ($this->pdo->inTransaction()) $this->pdo->rollBack(); throw $e; }
    }

    public function authenticate(string $token, string $scope = 'hub:read'): array
    {
        if (!preg_match('/\A[A-Za-z0-9_-]{43}\z/D', $token)) throw new RuntimeException('invalid_token', 401);
        $s = $this->pdo->prepare("SELECT grant_id,expires_at FROM hub_oauth_tokens WHERE token_hash=? AND kind='access'");
        $s->execute([hash('sha256',$token)]); $t = $s->fetch(PDO::FETCH_ASSOC);
        if (!$t || (int)$t['expires_at'] <= $this->now()) throw new RuntimeException('invalid_token', 401);
        try { $g = $this->grant($t['grant_id']); } catch (RuntimeException) { throw new RuntimeException('invalid_token', 401); }
        if (!in_array($scope, explode(' ', $g['scope']), true)) throw new RuntimeException('insufficient_scope', 403);
        $permission = match ($scope) { 'invoices:read'=>Auth::PERMISSION_INVOICES_VIEW, 'cloudbeds:import'=>Auth::PERMISSION_INVOICES_RUN, default=>null };
        if ($permission && !Auth::hasPermission($this->pdo,$g['user'],$permission)) throw new RuntimeException('insufficient_scope', 403);
        return ['grant_id'=>$g['id'],'scopes'=>explode(' ',$g['scope']),'user'=>$g['user']];
    }

    public function connections(int $userId): array
    {
        $u = $this->user($userId);
        $s = $this->pdo->prepare('SELECT id,scope,password_stamp,created_at,expires_at,idle_expires_at,last_used_at,revoked_at FROM hub_oauth_grants WHERE user_id=? ORDER BY (revoked_at IS NULL AND expires_at>? AND idle_expires_at>? AND password_stamp=?) DESC,created_at DESC LIMIT 100');
        $s->execute([$userId,$this->now(),$this->now(),hash('sha256',$u['password_hash'])]); $rows = $s->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['active'] = $row['revoked_at'] === null && (int)$row['expires_at'] > $this->now()
                && (int)$row['idle_expires_at'] > $this->now() && hash_equals($row['password_stamp'],hash('sha256',$u['password_hash']));
            unset($row['password_stamp']);
        }
        unset($row); return $rows;
    }

    public function revoke(string $id, int $userId): void
    {
        $this->pdo->beginTransaction();
        try {
            $s = $this->pdo->prepare('UPDATE hub_oauth_grants SET revoked_at=? WHERE id=? AND user_id=? AND revoked_at IS NULL');
            $s->execute([$this->now(),$id,$userId]);
            if ($s->rowCount()) Auth::audit($this->pdo,$userId,'connector_revoked',['grant_id'=>$id]);
            $this->pdo->commit();
        } catch (Throwable $e) { if ($this->pdo->inTransaction()) $this->pdo->rollBack(); throw $e; }
    }

    public function revokeToken(string $token, string $client): void
    {
        if (!preg_match('/\A[A-Za-z0-9_-]{43}\z/D', $token)) return;
        $s = $this->pdo->prepare('SELECT g.id,g.user_id FROM hub_oauth_tokens t JOIN hub_oauth_grants g ON g.id=t.grant_id WHERE t.token_hash=? AND g.client_id=?');
        $s->execute([hash('sha256',$token),$client]); $g = $s->fetch(PDO::FETCH_ASSOC);
        if ($g) $this->revoke($g['id'],(int)$g['user_id']);
    }
}
