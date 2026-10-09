<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') exit(1);
umask(0077);
try {
    $app=$argv[1]??'';
    if ($app!=='/home/welcome/public_html/check/') throw new RuntimeException('unexpected_app_root');
    require_once $app.'lib.php';
    require_once $app.'src/Invoices/InvoiceAccounts.php';
    $accounts=new InvoiceAccounts(database()); $ids=['welcome'=>[],'city'=>[]];
    foreach ($accounts->all() as $a) {
        if ($a['portal']!=='hostelworld' || $a['auth_method']!=='email' || !$accounts->active((int)$a['id'])) continue;
        $labels=strtolower($a['label'].' '.implode(' ',$accounts->properties((int)$a['id'])));
        $welcome=str_contains($labels,'welcome'); $city=preg_match('/city[ _-]*(center|centre)?/',$labels)===1;
        if ($welcome && !$city) $ids['welcome'][]=(int)$a['id'];
        if ($city && !$welcome) $ids['city'][]=(int)$a['id'];
    }
    if (count($ids['welcome'])!==1 || count($ids['city'])!==1) throw new RuntimeException('hostelworld_account_binding_missing_or_ambiguous');
    $map=['welcome'=>$ids['welcome'][0],'city'=>$ids['city'][0]];
    $root='/home/welcome/room-check-private';
    if (is_link($root) || !is_dir($root) || (fileperms($root)&0077)!==0) throw new RuntimeException('private_root_permissions');
    $path=$root.'/hostelworld-filter-provision.json';
    if (is_link($path)) throw new RuntimeException('private_config_symlink');
    $saved=is_file($path)?json_decode(file_get_contents($path),true,8,JSON_THROW_ON_ERROR):[];
    if ($saved && ($saved['account_ids']??[])!==$map) throw new RuntimeException('hostelworld_account_binding_changed');
    $key=$saved['key']??bin2hex(random_bytes(32));
    if (!preg_match('/\A[a-f0-9]{64}\z/',$key)) throw new RuntimeException('invalid_bridge_key');
    InvoiceVault::atomicWrite($path,json_encode(['version'=>1,'account_ids'=>$map,'key'=>$key],JSON_THROW_ON_ERROR));
    echo "Hostelworld filter account bindings provisioned.\n";
} catch (Throwable $e) {
    fwrite(STDERR,"Hostelworld filter provisioning failed: ".($e instanceof RuntimeException?$e->getMessage():'configuration_error')."\n");
    exit(1);
}
