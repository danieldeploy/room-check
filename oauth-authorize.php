<?php
declare(strict_types=1);
require_once __DIR__.'/src/Auth/HubConnectorHttp.php';
require_once __DIR__.'/src/I18n/SiteTranslations.php';
require_once __DIR__.'/src/Security/Csrf.php';
function connectorEscape(string $s): string { return htmlspecialchars($s,ENT_QUOTES,'UTF-8'); }
function connectorText(string $pt,string $en): string { return connectorEscape(SiteTranslations::text($pt,$en)); }
try {
    HubConnectorHttp::secure();
    header("Content-Security-Policy: default-src 'none'; style-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
    $method=$_SERVER['REQUEST_METHOD']??'GET';
    if (!in_array($method,['GET','POST'],true)) throw new RuntimeException('method_not_allowed',405);
    $incoming=null;
    if (isset($_GET['client_id'])) $incoming=HubOAuth::authorization(HubConnectorHttp::form($_SERVER['QUERY_STRING']??''));
    // The existing session uses SameSite=Strict. A same-origin first hop preserves
    // it when arriving from ChatGPT, instead of overwriting it with an anonymous session.
    if ($incoming!==null && !isset($_GET['continue'])) {
        ?>
        <!doctype html><html lang="pt"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Ligar Management Hub / Connect Management Hub</title><link rel="stylesheet" href="assets/auth.css"></head>
        <body><main class="auth-shell"><section class="auth-card"><h1>Management Hub</h1><p>Ligar a conta ao ChatGPT / Connect your account to ChatGPT</p><form action="oauth-authorize.php" method="get">
        <?php foreach ($incoming as $key=>$value): ?><input type="hidden" name="<?= connectorEscape($key) ?>" value="<?= connectorEscape($value) ?>"><?php endforeach; ?>
        <input type="hidden" name="continue" value="1"><button type="submit">Continuar no Hub / Continue to Hub</button></form></section></main></body></html>
        <?php exit;
    }
    require_once __DIR__.'/lib.php'; $config=require __DIR__.'/config.php';
    Auth::startSession($config); SiteTranslations::boot();
    if ($incoming!==null) {
        if ($method!=='GET') throw new RuntimeException('invalid_request',400);
        $_SESSION['hub_oauth_pending']=['request'=>$incoming,'created_at'=>time(),'nonce'=>bin2hex(random_bytes(24))];
    }
    $pending=$_SESSION['hub_oauth_pending']??null;
    if (!is_array($pending) || (int)($pending['created_at']??0)<time()-600) {
        unset($_SESSION['hub_oauth_pending']); throw new RuntimeException('invalid_request',400);
    }
    $request=HubOAuth::authorization($pending['request']);
    $user=Auth::currentUser(database(),$config);
    if ($user===null) { header('Location: login.php'); exit; }
    if ($user['role']!=='gerente') throw new RuntimeException('access_denied',403);
    $error=null;
    if ($method==='POST') {
        try {
            Csrf::validate($_POST['csrf_token']??null);
            if (!is_string($_POST['transaction']??null) || !hash_equals($pending['nonce'],$_POST['transaction'])) throw new RuntimeException('invalid_request',400);
            if (($_POST['decision']??'')==='deny') $result=['error'=>'access_denied'];
            elseif (($_POST['decision']??'')==='allow') {
                $selected=$_POST['scopes']??[];
                if (!is_array($selected)||array_filter($selected,static fn($v)=>!is_string($v))) throw new RuntimeException('invalid_scope',400);
                $result=['code'=>(new HubOAuth(database()))->approve($request,(int)$user['id'],$selected)];
            } else throw new RuntimeException('invalid_request',400);
            unset($_SESSION['hub_oauth_pending']);
            header('Location: '.HubOAuth::callback($request,$result),true,303); exit;
        } catch (Throwable $e) {
            $error=$e->getMessage()==='connection_limit'
                ? SiteTranslations::text('Revogue uma ligação antiga em Ligações autorizadas e tente novamente.','Revoke an old connection in Authorized connections and try again.')
                : SiteTranslations::text('Não foi possível autorizar. Reinicie a ligação no ChatGPT.','Could not authorize. Restart the connection in ChatGPT.');
        }
    }
    $labels=[
        'hub:read'=>SiteTranslations::text('Confirmar a identidade e as permissões desta conta.','Confirm this account’s identity and permissions.'),
        'invoices:read'=>SiteTranslations::text('Consultar contas, faturas e estados do Drive e TOConline.','Read accounts, invoices and Drive and TOConline states.'),
        'cloudbeds:import'=>SiteTranslations::text('Adicionar PDFs Cloudbeds ao circuito de recolha, Drive e envio ao TOConline.','Add Cloudbeds PDFs to collection, Drive and TOConline delivery.'),
    ];
} catch (Throwable $e) {
    unset($_SESSION['hub_oauth_pending']);
    http_response_code(in_array($e->getCode(),[400,403,405],true)?$e->getCode():503);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="pt"><meta charset="utf-8"><title>Management Hub</title><p>Ligação indisponível ou expirada. Reinicie a ligação no ChatGPT com uma conta gerente.<br>Connection unavailable or expired. Restart in ChatGPT with a manager account.</p><a href="index.php">Management Hub</a></html>'; exit;
}
?>
<!doctype html><html lang="<?= connectorEscape(Translator::locale()) ?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title><?= connectorText('Autorizar ChatGPT','Authorize ChatGPT') ?> — Management Hub</title><link rel="stylesheet" href="assets/auth.css"><link rel="stylesheet" href="assets/hub-connector.css"></head>
<body><main class="auth-shell"><section class="auth-card connector-card">
<p class="eyebrow">Management Hub</p><h1><?= connectorText('Ligar ao ChatGPT','Connect to ChatGPT') ?></h1>
<p><?= connectorText('Conta que autoriza:','Authorizing account:') ?> <strong><?= connectorEscape($user['username']) ?></strong></p>
<p><?= connectorText('Autorize apenas se iniciou esta ligação na sua conta ChatGPT. Os dados consultados serão disponibilizados nessa conta.','Authorize only if you started this connection in your own ChatGPT account. Retrieved data will be available in that account.') ?></p>
<?php if ($error!==null): ?><div class="alert" role="alert"><?= connectorEscape($error) ?></div><?php endif; ?>
<form method="post" action="oauth-authorize.php">
<input type="hidden" name="csrf_token" value="<?= connectorEscape(Csrf::token()) ?>"><input type="hidden" name="transaction" value="<?= connectorEscape($pending['nonce']) ?>">
<?php foreach (explode(' ',$request['scope']) as $scope): ?>
<label class="connector-scope"><input type="checkbox" name="scopes[]" value="<?= connectorEscape($scope) ?>" checked <?= $scope==='hub:read'?'disabled':'' ?>><span><?= connectorEscape($labels[$scope]) ?></span></label>
<?php if ($scope==='hub:read'): ?><input type="hidden" name="scopes[]" value="hub:read"><?php endif; ?>
<?php endforeach; ?>
<p><?= connectorText('A ligação renova-se automaticamente durante a utilização. Expira após 90 dias sem renovação ou ao fim de um ano. Pode revogá-la a qualquer momento em Ligações autorizadas.','The connection renews automatically during use. It expires after 90 days without renewal or after one year. Revoke it at any time in Authorized connections.') ?></p>
<div class="connector-actions"><button name="decision" value="allow" type="submit"><?= connectorText('Autorizar ligação','Authorize connection') ?></button><button name="decision" value="deny" type="submit" class="connector-secondary"><?= connectorText('Recusar','Deny') ?></button></div>
</form></section></main></body></html>
