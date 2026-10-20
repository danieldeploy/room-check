<?php
declare(strict_types=1);
require_once __DIR__.'/src/Auth/HubConnectorHttp.php';
require_once __DIR__.'/src/Auth/HubMcp.php';
try {
    HubConnectorHttp::secure();
    // Stateless Streamable HTTP: no SSE daemon or browser process on Windows.
    if (($_SERVER['REQUEST_METHOD']??'')==='GET') { header('Allow: POST'); throw new RuntimeException('method_not_allowed',405); }
    $raw=HubConnectorHttp::body(2900000,'application/json');
    $message=json_decode($raw,true,16,JSON_THROW_ON_ERROR);
    if (!is_array($message)||array_is_list($message)||($message['jsonrpc']??'')!=='2.0'||!is_string($message['method']??null)) throw new RuntimeException('invalid_request',400);
    $id=$message['id']??null;
    if ($id!==null && !is_string($id) && !is_int($id)) throw new RuntimeException('invalid_request',400);
    $method=$message['method']; $params=$message['params']??[];
    if (!is_array($params)) throw new RuntimeException('invalid_request',400);
    $protocol=$_SERVER['HTTP_MCP_PROTOCOL_VERSION']??'2025-03-26';
    if (!in_array($protocol,['2025-03-26','2025-06-18','2025-11-25'],true)) throw new RuntimeException('invalid_request',400);
    require_once __DIR__.'/lib.php'; $config=require __DIR__.'/config.php';
    $oauth=new HubOAuth(database());
    // Authentication is required even for initialize/list; discovery is separate.
    $token=HubConnectorHttp::bearer(); $identity=$oauth->authenticate($token);
    if ($id===null) {
        if ($method!=='notifications/initialized' && $method!=='notifications/cancelled') throw new RuntimeException('invalid_request',400);
        http_response_code(202); exit;
    }
    if ($method==='initialize') {
        $requested=$params['protocolVersion']??'';
        $result=['protocolVersion'=>in_array($requested,['2025-03-26','2025-06-18','2025-11-25'],true)?$requested:'2025-06-18',
            'capabilities'=>['tools'=>(object)[]], 'serverInfo'=>['name'=>'Management Hub','version'=>'1.0.0'],
            'instructions'=>'Use hub_get_profile first. Only act on owner-authorized tasks. Do not request portal passwords. Invoice issue date selects the month. An import is queued, not complete: verify Drive and TOConline states. Airbnb remains excluded from TOConline.'];
    } elseif ($method==='ping') $result=(object)[];
    elseif ($method==='tools/list') $result=['tools'=>HubMcp::tools()];
    elseif ($method==='tools/call') {
        $name=$params['name']??''; $arguments=$params['arguments']??[];
        if (!is_string($name)||!is_array($arguments)) throw new RuntimeException('invalid_request',400);
        try {
            $requiredScope=HubMcp::scope($name);
            $identity=$oauth->authenticate($token,$requiredScope);
            $data=(new HubMcp(database(),$config))->call($name,$arguments,$identity);
            $result=['content'=>[['type'=>'text','text'=>json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]],'structuredContent'=>$data];
        } catch (Throwable $e) {
            $safe=in_array($e->getMessage(),['invalid_token','insufficient_scope','unknown_tool','invalid_request','invalid_document','account_mismatch','document_limit','worker_busy','private_storage_unavailable','account_inactive'],true)?$e->getMessage():'operation_failed';
            $result=['isError'=>true,'content'=>[['type'=>'text','text'=>$safe]]];
            if (in_array($e->getCode(),[401,403],true)) $result['_meta']=['mcp/www_authenticate'=>[HubConnectorHttp::challengeValue($requiredScope??'hub:read')]];
        }
    } else HubConnectorHttp::json(['jsonrpc'=>'2.0','id'=>$id,'error'=>['code'=>-32601,'message'=>'Method not found']]);
    HubConnectorHttp::json(['jsonrpc'=>'2.0','id'=>$id,'result'=>$result]);
} catch (JsonException|TypeError $e) { HubConnectorHttp::fail(new RuntimeException('invalid_request',400)); }
catch (Throwable $e) { HubConnectorHttp::fail($e); }
