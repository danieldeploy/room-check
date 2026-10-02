<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/src/Invoices/InvoiceDriveSetup.php';
$setupTmp=sys_get_temp_dir().'/drive-setup-test-'.bin2hex(random_bytes(6)); mkdir($setupTmp,0700);
file_put_contents($setupTmp.'/master.key',random_bytes(32)); chmod($setupTmp.'/master.key',0600);
$setupVault=new InvoiceVault($setupTmp); $setup=new InvoiceDriveSetup($setupVault);
$setupOwner=['id'=>1,'role'=>'gerente','is_active'=>1];
$setupFixture=['web'=>['client_id'=>'123456789012-fixtureclient0001.apps.googleusercontent.com',
    'client_secret'=>'fixture-only-google-secret-not-real','auth_uri'=>'https://accounts.google.com/o/oauth2/auth',
    'token_uri'=>'https://oauth2.googleapis.com/token','redirect_uris'=>[InvoiceDriveClient::CALLBACK],
    'refresh_token'=>'fixture-token-must-not-be-imported','extra'=>'fixture-extra-not-stored']];
$setupJson=json_encode($setupFixture,JSON_THROW_ON_ERROR);
$expectSetupError=static function(callable $operation,string $code): void {
    try { $operation(); } catch (RuntimeException $e) { if($e->getMessage()===$code)return; throw $e; }
    throw new RuntimeException('Drive setup accepted an invalid operation');
};
try {
    if($setup->clientConfigured())throw new RuntimeException('Missing client reported as configured');
    foreach(['gestor','governanta','empregada',''] as $role) {
        $expectSetupError(fn()=>$setup->install($setupJson,['role'=>$role],true),'forbidden');
    }
    $expectSetupError(fn()=>$setup->install($setupJson,$setupOwner,false),'https_required');
    foreach(['','{','null','[]','"text"',str_repeat('x',InvoiceDriveSetup::MAX_BYTES+1)] as $invalid) {
        $expectSetupError(fn()=>$setup->install($invalid,$setupOwner,true),'drive_client_invalid');
    }
    $invalidFixtures=[['installed'=>$setupFixture['web']],['web'=>$setupFixture['web'],'installed'=>[]]];
    foreach(['client_id'=>'not-a-google-client','client_secret'=>'short','auth_uri'=>'https://example.invalid/auth',
        'token_uri'=>'https://example.invalid/token','redirect_uris'=>['https://example.invalid/callback']] as $key=>$value) {
        $invalidFixtures[]=['web'=>array_replace($setupFixture['web'],[$key=>$value])];
    }
    $invalidFixtures[]=['web'=>array_replace($setupFixture['web'],['client_secret'=>[]])];
    foreach($invalidFixtures as $invalid) {
        $expectSetupError(fn()=>$setup->install(json_encode($invalid,JSON_THROW_ON_ERROR),$setupOwner,true),'drive_client_invalid');
    }
    $expectSetupError(fn()=>$setup->installUpload(['error'=>UPLOAD_ERR_OK,'size'=>strlen($setupJson),
        'tmp_name'=>$setupTmp.'/master.key'],$setupOwner,true),'drive_client_invalid');
    if($setupVault->has('drive-oauth.enc'))throw new RuntimeException('Rejected setup wrote credentials');
    if($setup->install($setupJson,$setupOwner,true)!=='configured' || !$setup->clientConfigured())throw new RuntimeException('Valid client not installed');
    $stored=$setupVault->read('drive-oauth.enc');
    if($stored!==array_intersect_key($setupFixture['web'],array_flip(['client_id','client_secret'])))throw new RuntimeException('Unexpected fields entered the vault');
    $setupPath=$setupVault->path('drive-oauth.enc');
    if((fileperms($setupPath)&0077)!==0 || str_contains((string)file_get_contents($setupPath),'fixture'))throw new RuntimeException('Credential storage is not private/encrypted');
    $stored['refresh_token']='fixture-existing-authorization'; $setupVault->save('drive-oauth.enc',$stored);
    $setupHash=hash_file('sha256',$setupPath);
    if($setup->install($setupJson,$setupOwner,true)!=='already_configured' || hash_file('sha256',$setupPath)!==$setupHash
        || $setupVault->read('drive-oauth.enc')!==$stored)throw new RuntimeException('Reimport changed existing authorization');
    foreach(['client_id'=>'987654321012-otherfixture0001.apps.googleusercontent.com','client_secret'=>'fixture-different-secret-not-real'] as $key=>$value) {
        $changed=['web'=>array_replace($setupFixture['web'],[$key=>$value])];
        $expectSetupError(fn()=>$setup->install(json_encode($changed,JSON_THROW_ON_ERROR),$setupOwner,true),'drive_client_conflict');
        if(hash_file('sha256',$setupPath)!==$setupHash)throw new RuntimeException('Conflicting import altered the vault');
    }
    InvoiceVault::atomicWrite($setupPath,'{}');
    if($setup->clientConfigured())throw new RuntimeException('Unreadable client reported as configured');
    $expectSetupError(fn()=>$setup->install($setupJson,$setupOwner,true),'private_storage_unavailable');
    if(file_get_contents($setupPath)!=='{}')throw new RuntimeException('Unreadable vault was overwritten');
    echo "Drive OAuth setup validates manager/HTTPS, JSON, fixed endpoints/callback, encrypted storage and authorization preservation.\n";
} finally {
    foreach(glob($setupTmp.'/*')?:[] as $file)unlink($file); rmdir($setupTmp);
}
