<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') exit(1);
umask(0077);
try {
    $app=$argv[1]??'';
    if ($app!=='/home/welcome/public_html/check/') throw new RuntimeException('unexpected_app_root');
    require_once $app.'lib.php';
    require_once $app.'src/Invoices/InvoiceAccounts.php';
    $pdo=database(); $accounts=new InvoiceAccounts($pdo); $ids=['welcome'=>[],'city'=>[]]; $candidates=[];
    $config=require $app.'config.php';
    $vault=new InvoiceVault($config['invoices']['private_dir']);
    foreach ($accounts->all() as $a) {
        if ($a['portal']!=='hostelworld' || !$accounts->active((int)$a['id'])) continue;
        $labels=strtolower($a['label'].' '.implode(' ',$accounts->properties((int)$a['id'])));
        $credentials=$accounts->credentials($vault,(int)$a['id']);
        $knownCity=trim((string)($credentials['hostel_number']??''))==='77759'
            || array_key_exists('77759',$accounts->properties((int)$a['id']));
        $welcome=str_contains($labels,'welcome');
        $city=$knownCity || preg_match('/\\bcity(?:[ _-]*(?:center|centre))?\\b/',$labels)===1;
        $candidates[]=['id'=>(int)$a['id'],'method'=>$a['auth_method'],'welcome_label'=>$welcome,'city_match'=>$city,'city_number'=>$knownCity];
        if ($welcome && !$city) $ids['welcome'][]=(int)$a['id'];
        if ($city && !$welcome) $ids['city'][]=(int)$a['id'];
    }
    // Exactly two active portal accounts and an exact City number identify the sole other account.
    if (!$ids['welcome'] && count($candidates)===2 && count($ids['city'])===1
        && count(array_filter($candidates,static fn($c)=>$c['city_number']))===1) {
        $ids['welcome']=array_values(array_diff(array_column($candidates,'id'),$ids['city']));
    }
    if (count($ids['welcome'])!==1 || count($ids['city'])!==1) {
        fwrite(STDERR,'Hostelworld binding facts: '.json_encode($candidates,JSON_THROW_ON_ERROR)."\n");
        throw new RuntimeException('hostelworld_account_binding_missing_or_ambiguous');
    }
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
    // The portal's password remains in the vault; its second factor is filtered email.
    foreach ($map as $id) {
        if ($accounts->get($id)['auth_method']!=='email') {
            $vault->save(InvoiceAccounts::secretName($id,'session'),['cookies'=>[]]);
            $pdo->prepare("UPDATE invoice_accounts SET auth_method='email',status='configured',enabled=0,login_verified_at=NULL WHERE id=?")->execute([$id]);
        }
    }
    echo "Hostelworld filter account bindings provisioned.\n";
} catch (Throwable $e) {
    fwrite(STDERR,"Hostelworld filter provisioning failed: ".($e instanceof RuntimeException?$e->getMessage():'configuration_error')."\n");
    exit(1);
}
