<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/lib.php';
require_once dirname(__DIR__).'/src/Auth/HubConnectorHttp.php';
require_once dirname(__DIR__).'/src/UI/SessionBar.php';
$config=require dirname(__DIR__).'/config.php';
try {
    HubConnectorHttp::secure(); $pdo=database(); $user=Auth::requireLogin($pdo,$config);
    if ($user['role']!=='gerente') throw new RuntimeException('access_denied',403);
} catch (Throwable $e) {
    if ($e->getCode()===401) { header('Location: ../login.php'); exit; }
    http_response_code(403); exit('Access denied.');
}
SiteTranslations::boot();
function connectionText(string $pt,string $en): string { return htmlspecialchars(SiteTranslations::text($pt,$en),ENT_QUOTES,'UTF-8'); }
function connectionDate(?int $time): string { return $time===null?'—':(new DateTimeImmutable('@'.$time))->setTimezone(new DateTimeZone('Europe/Lisbon'))->format('d/m/Y H:i'); }
$oauth=new HubOAuth($pdo); $error=null; $message=null;
if (($_SERVER['REQUEST_METHOD']??'GET')==='POST') {
    try {
        Csrf::validate($_POST['csrf_token']??null);
        $id=$_POST['connection_id']??'';
        if (!is_string($id)||!preg_match('/\A[a-f0-9]{32}\z/D',$id)) throw new RuntimeException('invalid_request');
        $oauth->revoke($id,(int)$user['id']);
        $message=SiteTranslations::text('Ligação revogada.','Connection revoked.');
    } catch (Throwable) { $error=SiteTranslations::text('Não foi possível revogar a ligação. Tente novamente.','Could not revoke the connection. Try again.'); }
}
try { $connections=$oauth->connections((int)$user['id']); }
catch (Throwable) { $connections=[]; $error=SiteTranslations::text('Ligações temporariamente indisponíveis.','Connections temporarily unavailable.'); }
?>
<!doctype html><html lang="<?= htmlspecialchars(Translator::locale(),ENT_QUOTES,'UTF-8') ?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title><?= connectionText('Ligações autorizadas','Authorized connections') ?> — Management Hub</title><link rel="stylesheet" href="../assets/auth.css"><link rel="stylesheet" href="assets/users.css"><link rel="stylesheet" href="../assets/session.css"><link rel="stylesheet" href="../assets/hub-connector.css"></head>
<body><main class="users-shell">
<?php SessionBar::render($user,'..',true,true); ?>
<header class="compact-page-header"><h1><?= connectionText('Ligações autorizadas','Authorized connections') ?></h1></header>
<?php if ($message): ?><div class="success" role="status" data-save-feedback="success"><?= htmlspecialchars($message,ENT_QUOTES,'UTF-8') ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert" role="alert"><?= htmlspecialchars($error,ENT_QUOTES,'UTF-8') ?></div><?php endif; ?>
<section class="management-card"><h2>ChatGPT</h2>
<p><?= connectionText('Adicione um servidor MCP personalizado nos Plugins do ChatGPT, escolha OAuth e utilize este endereço:','Add a custom MCP server in ChatGPT Plugins, choose OAuth and use this address:') ?></p>
<p><code><?= HubOAuth::RESOURCE ?></code></p>
<p><?= connectionText('Escolha registo dinâmico (DCR). Se forem pedidos dados estáticos, o Client ID é management-hub-chatgpt, sem Client Secret. A conta gerente autoriza as permissões no Hub.','Choose dynamic registration (DCR). If static details are requested, the Client ID is management-hub-chatgpt, with no Client Secret. The manager account authorizes permissions in the Hub.') ?></p>
<p><?= connectionText('Não é necessário instalar aplicações no PC Windows. A palavra-passe do gerente permanece no Hub; o ChatGPT mantém a autorização da ligação.','No apps need to be installed on the Windows PC. The manager password stays in the Hub; ChatGPT maintains the connection authorization.') ?></p>
</section>
<section class="management-card"><h2><?= connectionText('As minhas ligações','My connections') ?></h2>
<?php if (!$connections): ?><p><?= connectionText('Ainda não existem ligações autorizadas.','No authorized connections yet.') ?></p><?php endif; ?>
<?php foreach ($connections as $c): $active=$c['active']; ?>
<article class="user-row"><strong>ChatGPT · <?= htmlspecialchars(substr($c['id'],0,8),ENT_QUOTES,'UTF-8') ?></strong>
<p><?= $c['revoked_at']!==null?connectionText('Revogada','Revoked'):($active?connectionText('Ativa','Active'):connectionText('Expirada','Expired')) ?></p>
<p><?= connectionText('Permissões:','Permissions:') ?>
<?= connectionText('Confirmar identidade','Confirm identity') ?>
<?php if (in_array('invoices:read',explode(' ',$c['scope']),true)): ?> · <?= connectionText('Consultar faturas','Read invoices') ?><?php endif; ?>
<?php if (in_array('cloudbeds:import',explode(' ',$c['scope']),true)): ?> · <?= connectionText('Adicionar PDFs Cloudbeds','Add Cloudbeds PDFs') ?><?php endif; ?></p>
<p><?= connectionText('Criada:','Created:') ?> <?= connectionDate((int)$c['created_at']) ?> · <?= connectionText('Última renovação:','Last renewal:') ?> <?= connectionDate($c['last_used_at']===null?null:(int)$c['last_used_at']) ?></p>
<?php if ($active): ?><form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Csrf::token(),ENT_QUOTES,'UTF-8') ?>"><input type="hidden" name="connection_id" value="<?= htmlspecialchars($c['id'],ENT_QUOTES,'UTF-8') ?>"><button type="submit"><?= connectionText('Revogar acesso','Revoke access') ?></button></form><?php endif; ?>
</article><?php endforeach; ?></section></main></body></html>
