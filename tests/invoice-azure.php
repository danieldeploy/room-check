<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/src/Invoices/InvoiceAzure.php';
function az(bool $ok,string $why): void { if (!$ok) throw new RuntimeException($why); }
$dir=sys_get_temp_dir().'/azure-test-'.bin2hex(random_bytes(8)); mkdir($dir,0700);
file_put_contents($dir.'/master.key',random_bytes(32)); chmod($dir.'/master.key',0600);
$vault=new InvoiceVault($dir); $posts=0; $gets=0;
$fixture=['status'=>'succeeded','analyzeResult'=>['pages'=>[
 ['pageNumber'=>1,'lines'=>[['content'=>'A transportar 200.00'],['content'=>'Pág. 1/2']]],
 ['pageNumber'=>2,'lines'=>[['content'=>'Total EUR 250.00'],['content'=>'Pág. 2/2']]]],
 'documents'=>[['boundingRegions'=>[['pageNumber'=>1],['pageNumber'=>2]],'fields'=>[
 'InvoiceId'=>['valueString'=>'FT TEST/1','confidence'=>0.98,'boundingRegions'=>[['pageNumber'=>1]]],
 'InvoiceDate'=>['valueDate'=>'2026-04-02','confidence'=>0.99],
 'InvoiceTotal'=>['valueCurrency'=>['amount'=>250,'currencyCode'=>'EUR'],'confidence'=>0.97],
 'VendorIBAN'=>['content'=>'PRIVATE-IBAN']]]]]];
$transport=static function($method,$url,$bytes,$mime) use (&$posts,&$gets,&$fixture): array {
 az(str_starts_with($url,InvoiceAzure::ENDPOINT.'/documentintelligence/'),'fixed API endpoint');
 if ($method==='POST') { $posts++; return ['status'=>202,'headers'=>['operation-location'=>InvoiceAzure::ENDPOINT.'/documentintelligence/documentModels/prebuilt-invoice/analyzeResults/12345678-1234-1234-1234-123456789abc?api-version=2024-11-30']]; }
 $gets++; return ['status'=>200,'body'=>$fixture];
};
try {
 $azure=new InvoiceAzure($vault,$transport); $key=str_repeat('a',32); $azure->configure($key);
 az(!str_contains(file_get_contents($dir.'/'.InvoiceAzure::SETTINGS),$key),'key encrypted');
 foreach (['https://evil.example/','https://management-hub-invoices.cognitiveservices.azure.com.evil.example/documentintelligence/x',InvoiceAzure::ENDPOINT.'/documentintelligence/documentModels/prebuilt-invoice/analyzeResults/../keys?api-version=2024-11-30'] as $url) {
  try { InvoiceAzure::operation($url); throw new RuntimeException('unsafe operation accepted'); } catch (RuntimeException $e) { az($e->getMessage()==='azure_response','operation URL restriction'); }
 }
 try { $azure->start('%PDF-test','application/pdf',3,'bad',1); throw new RuntimeException('F0 truncated silently'); } catch (RuntimeException $e) { az($e->getMessage()==='azure_limits','F0 two-page guard'); }
 $id=$azure->start('%PDF-test','application/pdf',2,'two pages',1);
 az($azure->start('%PDF-test','application/pdf',2,'repeat',1)===$id && $posts===1,'same content POST exactly once');
 $azure->poll($id); $test=$azure->tests()[$id];
 az($test['state']==='succeeded' && count($test['result']['pages'])===2,'two page result complete');
 az($test['result']['pages'][0]['continuation'] && !$test['result']['pages'][1]['continuation'],'carry-forward evidence per page');
 az($test['result']['documents'][0]['fields']['InvoiceId']['value']==='FT TEST/1','extracted ID');
 az(!str_contains(json_encode($test),'PRIVATE-IBAN'),'unneeded fields not persisted');
 $bad=new InvoiceAzure($vault,static function(){ throw new RuntimeException('raw secret error'); });
 $uncertain=$bad->start('%PDF-other','application/pdf',1,'uncertain',1);
 az($bad->tests()[$uncertain]['state']==='uncertain' && $bad->tests()[$uncertain]['error']==='azure_response','safe durable uncertain outcome');
 az($bad->start('%PDF-other','application/pdf',1,'repeat',1)===$uncertain,'uncertain submission is not repeated');
 $fixture['analyzeResult']['pages']=array_slice($fixture['analyzeResult']['pages'],0,1);
 $incomplete=$azure->start('%PDF-incomplete','application/pdf',2,'missing page',1); $azure->poll($incomplete);
 az($azure->tests()[$incomplete]['state']!=='succeeded' && $azure->tests()[$incomplete]['error']==='azure_incomplete','partial results fail closed');
 echo "Azure diagnostics: encrypted key, URL restriction, F0 guard, at-most-once submissions, per-page extraction, incomplete results and redaction passed.\n";
} finally { foreach (glob($dir.'/*') as $f) unlink($f); rmdir($dir); }

// Render the actual endpoint with real Auth, Csrf, vault and report code against a
// temporary SQLite fixture. The fixture adapter replaces only database/bootstrap.
$root=dirname(__DIR__); $tmp=sys_get_temp_dir().'/receipt-report-'.bin2hex(random_bytes(8));
mkdir($tmp,0700); mkdir($tmp.'/web',0700); mkdir($tmp.'/web/admin',0700); mkdir($tmp.'/vault',0700);
symlink($root.'/src',$tmp.'/web/src'); symlink($root.'/assets',$tmp.'/web/assets'); symlink($root.'/admin/assets',$tmp.'/web/admin/assets');
copy($root.'/admin/invoice-azure.php',$tmp.'/web/admin/invoice-azure.php');
file_put_contents($tmp.'/vault/master.key',random_bytes(32)); chmod($tmp.'/vault/master.key',0600);
$vault=new InvoiceVault($tmp.'/vault'); 
file_put_contents($tmp.'/web/config.php','<?php return '.var_export(['invoices'=>['private_dir'=>$tmp.'/vault']],true).';');
$lib=<<<'PHP'
<?php
require_once __DIR__.'/src/Auth/Auth.php';
require_once __DIR__.'/src/Security/Csrf.php';
function database(): PDO {
    static $pdo;
    if ($pdo) return $pdo;
    $pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $pdo->exec("CREATE TABLE users(id INTEGER,username TEXT,display_name TEXT,role TEXT,is_active INTEGER,last_login_at TEXT,preferred_language TEXT);
        CREATE TABLE role_permissions(role TEXT,permission TEXT);
        INSERT INTO users VALUES(1,'fixture','Fixture User','".$GLOBALS['fixture_role']."',1,NULL,'pt');");
    foreach ($GLOBALS['fixture_permissions'] as $permission) $pdo->prepare('INSERT INTO role_permissions VALUES(?,?)')->execute([$GLOBALS['fixture_role'],$permission]);
    return $pdo;
}
PHP;
file_put_contents($tmp.'/web/lib.php',$lib);
$render=static function(string $role,array $permissions,string $action='') use ($tmp): array {
    $code='$GLOBALS["fixture_role"]='.var_export($role,true).';$GLOBALS["fixture_permissions"]='.var_export($permissions,true).';'
        .'session_save_path('.var_export($tmp,true).');session_start();$_SESSION=["user_id"=>1,"last_activity"=>time(),"csrf_token"=>"fixture-csrf"];'
        .'$_SERVER["REQUEST_METHOD"]='.var_export($action===''?'GET':'POST',true).';$_POST=["action"=>'.var_export($action,true).',"id"=>"private-error-id","csrf_token"=>"wrong-csrf"];'
        .'ob_start();register_shutdown_function(static function(){echo json_encode(["status"=>http_response_code(),"html"=>ob_get_clean()]);});'
        .'require '.var_export($tmp.'/web/admin/invoice-azure.php',true).';';
    $process=proc_open([PHP_BINARY,'-d','display_errors=stderr','-r',$code],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    fclose($pipes[0]); $out=stream_get_contents($pipes[1]); fclose($pipes[1]); $err=stream_get_contents($pipes[2]); fclose($pipes[2]);
    az(proc_close($process)===0 && $err==='', 'Actual receipt endpoint failed: '.$err);
    return json_decode($out,true,512,JSON_THROW_ON_ERROR);
};
try {
    foreach (['governanta','tecnico_manutencao','empregada_andares'] as $role) az($render($role,[])['status']===403,'manager-only Azure diagnostic');
    $manager=$render('gerente',[]); az(str_contains($manager['html'],'name="azure_key"'),'manager can configure');
    az(!str_contains($manager['html'],'value="aaaaaaaa'),'key never rendered');
    az(!str_contains($render('gerente',[],'configure')['html'],'Chave guardada'),'invalid CSRF cannot configure');
    echo "Actual Azure endpoint: manager-only access and CSRF rejection passed.\n";
} finally {
    foreach (glob($tmp.'/sess_*')?:[] as $f) unlink($f);
    foreach ([$tmp.'/web/admin/assets',$tmp.'/web/src',$tmp.'/web/assets'] as $f) unlink($f);
    foreach (glob($tmp.'/vault/*')?:[] as $f) unlink($f);
    unlink($tmp.'/web/admin/invoice-azure.php'); unlink($tmp.'/web/lib.php'); unlink($tmp.'/web/config.php');
    rmdir($tmp.'/web/admin'); rmdir($tmp.'/web'); rmdir($tmp.'/vault'); rmdir($tmp);
}
