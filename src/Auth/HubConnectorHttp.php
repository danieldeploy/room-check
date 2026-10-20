<?php
declare(strict_types=1);
require_once __DIR__.'/HubOAuth.php';

final class HubConnectorHttp
{
    public static function secure(): void
    {
        ini_set('display_errors','0');
        header('Cache-Control: no-store');
        header('Pragma: no-cache');
        header('Referrer-Policy: no-referrer');
        header('X-Content-Type-Options: nosniff');
        if (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] === 'off') throw new RuntimeException('https_required',400);
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        if ($origin !== '' && !in_array($origin,[HubOAuth::ORIGIN,'https://chatgpt.com'],true)) throw new RuntimeException('invalid_origin',403);
    }

    public static function json(array $body, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($body,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function body(int $limit, string $type): string
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') throw new RuntimeException('method_not_allowed',405);
        if (strtolower(trim(explode(';',$_SERVER['CONTENT_TYPE'] ?? '')[0])) !== $type) throw new RuntimeException('unsupported_media_type',415);
        if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > $limit) throw new RuntimeException('request_too_large',413);
        $body = file_get_contents('php://input',false,null,0,$limit+1);
        if ($body === false || strlen($body)>$limit) throw new RuntimeException('request_too_large',413);
        return $body;
    }

    /** Reject arrays and duplicate parameters instead of PHP's last-value-wins. */
    public static function form(string $body): array
    {
        $out=[];
        foreach (explode('&',$body) as $part) {
            if ($part==='') continue;
            $pair=explode('=',$part,2); $key=urldecode($pair[0]); $value=urldecode($pair[1]??'');
            if (!preg_match('/\A[a-z_]+\z/D',$key) || array_key_exists($key,$out) || str_contains($value,"\0")) throw new RuntimeException('invalid_request',400);
            $out[$key]=$value;
        }
        return $out;
    }

    public static function bearer(): string
    {
        $header=$_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if (!preg_match('/\ABearer ([A-Za-z0-9_-]{43})\z/Di',$header,$match)) throw new RuntimeException('invalid_token',401);
        return $match[1];
    }

    public static function challenge(string $scope = 'hub:read'): void
    {
        header('WWW-Authenticate: '.self::challengeValue($scope));
    }

    public static function challengeValue(string $scope): string
    {
        if (!in_array($scope,HubOAuth::SCOPES,true)) $scope='hub:read';
        return 'Bearer resource_metadata="'.HubOAuth::ORIGIN.'/.well-known/oauth-protected-resource/mcp.php", scope="'.$scope.'"';
    }

    public static function fail(Throwable $e): never
    {
        $known=['invalid_request','invalid_origin','invalid_redirect_uri','invalid_client_metadata','invalid_scope','invalid_grant',
            'invalid_target','unsupported_grant_type','invalid_token','insufficient_scope','https_required','method_not_allowed',
            'unsupported_media_type','request_too_large','access_denied','connection_limit'];
        $safe=$e instanceof RuntimeException && in_array($e->getMessage(),$known,true);
        $code=$safe && in_array($e->getCode(),[400,401,403,405,413,415],true)?$e->getCode():503;
        if ($code===401 || $code===403) self::challenge();
        self::json(['error'=>$safe?$e->getMessage():'temporarily_unavailable'],$code);
    }
}
