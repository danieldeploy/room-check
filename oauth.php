<?php
declare(strict_types=1);
require_once __DIR__.'/src/Auth/HubConnectorHttp.php';
try {
    HubConnectorHttp::secure();
    $action=$_GET['action']??'';
    if (in_array($action,['metadata','resource'],true)) {
        if (($_SERVER['REQUEST_METHOD']??'')!=='GET') throw new RuntimeException('method_not_allowed',405);
        HubConnectorHttp::json($action==='metadata'?HubOAuth::metadata():HubOAuth::resourceMetadata());
    }
    if ($action==='register') {
        $input=json_decode(HubConnectorHttp::body(16384,'application/json'),true,8,JSON_THROW_ON_ERROR);
        if (!is_array($input) || array_is_list($input)) throw new RuntimeException('invalid_request',400);
        HubConnectorHttp::json(HubOAuth::register($input),201);
    }
    $input=HubConnectorHttp::form(HubConnectorHttp::body(16384,'application/x-www-form-urlencoded'));
    require_once __DIR__.'/lib.php';
    $oauth=new HubOAuth(database());
    if ($action==='token') HubConnectorHttp::json($oauth->token($input));
    if ($action==='revoke') {
        $oauth->revokeToken($input['token']??'', $input['client_id']??'');
        HubConnectorHttp::json([]);
    }
    throw new RuntimeException('invalid_request',400);
} catch (JsonException|TypeError $e) { HubConnectorHttp::fail(new RuntimeException('invalid_request',400)); }
catch (Throwable $e) { HubConnectorHttp::fail($e); }
