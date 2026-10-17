<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') exit(1);
ini_set('display_errors','0'); umask(0077);
try {
    $app=$argv[1]??''; if ($app!=='/home/welcome/public_html/check/') throw new RuntimeException('configuration');
    require_once $app.'lib.php'; require_once $app.'src/Invoices/CloudbedsInbox.php';
    require_once $app.'src/Invoices/InvoiceHostelworldVerification.php';
    $config=require $app.'config.php'; $pdo=database(); $vault=new InvoiceVault($config['invoices']['private_dir']);
    InvoiceHostelworldVerification::companyNif($vault);
    if ((int)$pdo->query("SELECT GET_LOCK('room_check_invoices',30)")->fetchColumn()!==1) throw new RuntimeException('busy');
    try {
        $accounts=new InvoiceAccounts($pdo); $settings=$vault->has('cloudbeds-settings.enc')?$vault->read('cloudbeds-settings.enc'):[];
        $ids=[];
        foreach (['welcome'=>'Cloudbeds Welcome','city'=>'Cloudbeds City Center'] as $source=>$label) {
            $s=$pdo->prepare("SELECT id FROM invoice_accounts WHERE portal='cloudbeds' AND label=?"); $s->execute([$label]); $id=$s->fetchColumn();
            if (!$id) $id=$accounts->create('cloudbeds',$label,[$source=>$source==='welcome'?'Welcome Guest House':'City Center Guest House'],'email');
            $id=(int)$id; $a=$accounts->get($id);
            if (array_keys($accounts->properties($id))!==[$source] || $a['period_basis']!=='issue_month' || $a['auth_method']!=='email'
                || (isset($settings['account_ids'][$source]) && $settings['account_ids'][$source]!==$id)) throw new RuntimeException('binding_conflict');
            $ids[$source]=$id;
        }
        $root='/home/welcome/room-check-private'; $file=$root.'/cloudbeds-filter-provision.json';
        if (is_link($file) || (file_exists($file) && (fileperms($file)&0077)!==0)) throw new RuntimeException('configuration');
        $old=is_file($file)?json_decode(file_get_contents($file),true,8,JSON_THROW_ON_ERROR):[];
        if ($old && ($old['account_ids']??[])!==$ids) throw new RuntimeException('binding_conflict');
        $keys=$old['keys']??['welcome'=>bin2hex(random_bytes(32)),'city'=>bin2hex(random_bytes(32))];
        foreach ($keys as $key) if (!preg_match('/\A[a-f0-9]{64}\z/',$key)) throw new RuntimeException('configuration');
        InvoiceVault::atomicWrite($file,json_encode(['version'=>1,'account_ids'=>$ids,'keys'=>$keys],JSON_THROW_ON_ERROR));
        InvoiceVault::atomicWrite($root.'/cloudbeds-welcome.key',$keys['welcome']);
        if (!$settings) $settings=['version'=>1,'account_ids'=>$ids,'bootstrap_period'=>(new DateTimeImmutable('first day of last month',new DateTimeZone('Europe/Lisbon')))->format('Y-m'),'bootstrap_phase'=>0];
        $vault->save('cloudbeds-settings.enc',$settings);
        echo "Cloudbeds email account bindings provisioned; original mail preserved.\n";
    } finally { $pdo->query("SELECT RELEASE_LOCK('room_check_invoices')"); }
} catch (Throwable) { fwrite(STDERR,"Cloudbeds email provisioning failed.\n"); exit(1); }
