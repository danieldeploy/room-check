<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/src/Invoices/InvoiceReceiptReport.php';
require_once dirname(__DIR__).'/src/Invoices/InvoiceReceipts.php';
function reportCheck(bool $ok,string $message): void { if (!$ok) throw new RuntimeException($message); }
$documents=[
    'private-master-id'=>['name'=>'SECRET-MASTER.pdf','children'=>['private-page-id'],'stage'=>'split_completed','toconline_state'=>'receipt_per_page'],
    'private-page-id'=>['source_id'=>'private-master-id','name'=>'SECRET-PAGE.pdf','stage'=>'duplicate_trashed','archived_at'=>'now','toconline_state'=>'toc_existing','issuer_name'=>'Supplier <script>bad()</script>','invoice_number'=>'FT 42','issued_on'=>'2026-10-02','drive_parent'=>'PRIVATE-PARENT'],
    'private-normal-id'=>['name'=>'SECRET-NORMAL.jpg','stage'=>'archived','archived_at'=>'now','issued_on'=>'2026-10-01','invoice_number'=>'SECRET-NORMAL-NUMBER','issuer_name'=>'SECRET-NORMAL-COMPANY','toconline_state'=>'toc_accepted','format'=>'jpg'],
    'private-error-id'=>['name'=>'SECRET-ERROR.jpg','stage'=>'review','sha256'=>'private-sha','format'=>'jpg','issuer_nif'=>'123456789','company_nif'=>'500000000','error'=>'receipt_review_required','toconline_state'=>'pending'],
    'private-old-duplicate'=>['name'=>'SECRET-OLD.pdf','stage'=>'duplicate_trashed','archived_at'=>'now','invoice_number'=>'FT 43','issued_on'=>'2026-09-01','toconline_state'=>'toc_existing'],
];
$summary=InvoiceReceiptReport::summary($documents);
reportCheck($summary['files']===4 && $summary['documents']===4 && $summary['processed']===3,'Split masters must not double-count invoices');
reportCheck(count($summary['duplicates'])===2 && count($summary['errors'])===1,'Duplicate/error totals');
$pending=InvoiceReceiptReport::summary([
 ['format'=>'pdf','stage'=>'archived','archived_at'=>'now','toconline_state'=>'toc_submitted'],
 ['format'=>'jpg','stage'=>'trashing','archived_at'=>'now','toconline_state'=>'toc_existing'],
 ['format'=>'pdf','stage'=>'archived','toconline_state'=>'toc_accepted'],
]);
reportCheck($pending['processed']===1 && $pending['pending']===2,'Archive alone and duplicate awaiting cleanup are not processed');
reportCheck($pending['pdf_files']===2 && $pending['jpg_files']===1 && $pending['a4']===2 && $pending['small']===1,'Format counts distinguish originals and invoices');
reportCheck(InvoiceReceiptReport::displayDate('2026-09-04')==='04-09-2026 (Setembro)','Format never swaps the real day and month');
reportCheck(InvoiceReceiptReport::displayDate('2026-04',true)==='04-2026 (Abril)','Original folder date format');
$zeroHtml=InvoiceReceiptReport::render(InvoiceReceiptReport::summary([]));
reportCheck(!str_contains($zeroHtml,'class="receipt-error-count"'),'Zero errors must not be red');
reportCheck(str_contains(InvoiceReceiptReport::render($summary,false),'class="receipt-error-count"'),'Nonzero errors highlighted');
reportCheck(!str_contains(InvoiceReceiptReport::render($summary,false),InvoiceText::get('receipt_review_required')),'Manager does not get a duplicate general error list');
$html=InvoiceReceiptReport::render($summary);
foreach (['SECRET-','private-','PRIVATE-PARENT','drive.google.com','type="date"','name="id"','name="action"','<script>bad()'] as $private) reportCheck(!str_contains($html,$private),'Operator report leaked private document data');
foreach (['FT 42','02-10-2026','FT 43','01-09-2026','Supplier &lt;script&gt;','Empresa não identificada'] as $public) reportCheck(str_contains($html,$public),'Required duplicate/date/error detail missing');
reportCheck(InvoiceReceipts::issuerName("Fornecedor: Empresa ABC\nCliente: Active Lines\nFornecedor: Not the supplier") === 'Empresa ABC','Do not mistake customer-section content for supplier');
reportCheck(InvoiceReceipts::issuerName("Loja Exemplo, Lda.\nNIF: 123456789\nCliente: Active Lines") === 'Loja Exemplo, Lda.', 'Recognize unique legal supplier heading');
reportCheck(InvoiceReceipts::issuerName("Cliente\nActive Lines, Lda.") === '', 'Bare customer heading must never become supplier');
reportCheck(InvoiceReceipts::issuerName("Loja Exemplo, Lda.\nCliente\nActive Lines, Lda.") === 'Loja Exemplo, Lda.', 'Bare customer heading bounds the legal supplier header');
reportCheck(InvoiceReceipts::issuerName("Active Lines, Lda.\nNIF: 500000000") === '', 'Unlabelled legal company alone is not supplier evidence');
reportCheck(InvoiceReceipts::issuerName("Cliente: Active Lines\nNIF: 500000000") === '', 'Never infer supplier from customer');
reportCheck(InvoiceReceipts::issuerName("Fornecedor: ABC\nEmitente: DEF") === '', 'Ambiguous suppliers remain unidentified');

// Render the actual endpoint with real Auth, Csrf, vault and report code against a
// temporary SQLite fixture. The fixture adapter replaces only database/bootstrap.
$root=dirname(__DIR__); $tmp=sys_get_temp_dir().'/receipt-report-'.bin2hex(random_bytes(8));
mkdir($tmp,0700); mkdir($tmp.'/web',0700); mkdir($tmp.'/web/admin',0700); mkdir($tmp.'/vault',0700);
symlink($root.'/src',$tmp.'/web/src'); symlink($root.'/assets',$tmp.'/web/assets'); symlink($root.'/admin/assets',$tmp.'/web/admin/assets');
copy($root.'/admin/receipts.php',$tmp.'/web/admin/receipts.php');
copy($root.'/admin/receipt-history.php',$tmp.'/web/admin/receipt-history.php');
copy($root.'/admin/receipt-document.php',$tmp.'/web/admin/receipt-document.php');
file_put_contents($tmp.'/vault/master.key',random_bytes(32)); chmod($tmp.'/vault/master.key',0600);
$vault=new InvoiceVault($tmp.'/vault'); $vault->save('toconline-settings.enc',['nif'=>'600000000','enabled'=>false]); $vault->save('drive-oauth.enc',['scope'=>'https://www.googleapis.com/auth/drive']); $vault->save(InvoiceReceipts::FILE,['documents'=>$documents,'requested'=>false,'requested_at'=>gmdate('c'),'year'=>2026,'months'=>[10]]);
file_put_contents($tmp.'/web/config.php','<?php return '.var_export(['invoices'=>['private_dir'=>$tmp.'/vault']],true).';');
$lib=<<<'PHP'
<?php
require_once __DIR__.'/src/Security/Csrf.php';
function jsonResponse(array $payload,int $status=200):never{http_response_code($status);echo json_encode(SiteTranslations::localizePayload($payload));exit;}
function database(): PDO {
    static $pdo;
    if ($pdo) return $pdo;
    $pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $pdo->sqliteCreateFunction('GET_LOCK',static fn($name,$timeout)=>1,2);
    $pdo->sqliteCreateFunction('RELEASE_LOCK',static fn($name)=>1,1);
    $pdo->exec("CREATE TABLE users(id INTEGER,username TEXT,display_name TEXT,role TEXT,is_active INTEGER,last_login_at TEXT,preferred_language TEXT);
        CREATE TABLE role_permissions(role TEXT,permission TEXT);
        CREATE TABLE invoice_document_delivery(document_id INTEGER,toconline_state TEXT);
        CREATE TABLE auth_audit_log(actor_user_id INTEGER,action TEXT,details_json TEXT,ip_key TEXT);
        INSERT INTO users VALUES(1,'fixture','Fixture User','".$GLOBALS['fixture_role']."',1,NULL,'pt');");
    foreach ($GLOBALS['fixture_permissions'] as $permission) $pdo->prepare('INSERT INTO role_permissions VALUES(?,?)')->execute([$GLOBALS['fixture_role'],$permission]);
    return $pdo;
}
PHP;
file_put_contents($tmp.'/web/lib.php',$lib);
$render=static function(string $role,array $permissions,string $action='',bool $preview=false,bool $status=false,array $query=[],array $post=[]) use ($tmp): array {
    $code='$GLOBALS["fixture_role"]='.var_export($role,true).';$GLOBALS["fixture_permissions"]='.var_export($permissions,true).';'
        .'session_save_path('.var_export($tmp,true).');session_start();$_SESSION=["user_id"=>1,"last_activity"=>time(),"csrf_token"=>"fixture-csrf"];'
        .'$_SERVER["REQUEST_METHOD"]='.var_export($action===''?'GET':'POST',true).';$_POST='.var_export($post+['action'=>$action,'id'=>'private-error-id','csrf_token'=>'fixture-csrf'],true).';'
        .'if('.var_export($action==='start',true).'){$_POST["year"]=1999;$_POST["months"]=["6"];}$_GET='.var_export($query+['filter'=>'all'],true).';$_GET["status"]='.var_export($status?'1':'',true).';'
        .'ob_start();register_shutdown_function(static function(){echo json_encode(["status"=>http_response_code(),"html"=>ob_get_clean()]);});'
        .'require '.var_export($tmp.'/web/admin/'.($preview?'receipt-document.php':'receipts.php'),true).';';
    $process=proc_open([PHP_BINARY,'-d','display_errors=stderr','-r',$code],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    fclose($pipes[0]); $out=stream_get_contents($pipes[1]); fclose($pipes[1]); $err=stream_get_contents($pipes[2]); fclose($pipes[2]);
    reportCheck(proc_close($process)===0 && $err==='', 'Actual receipt endpoint failed: '.$err);
    return json_decode($out,true,512,JSON_THROW_ON_ERROR);
};
try {
    $operator=$render('governanta',['receipts.run','receipts.view']);
    reportCheck($operator['status']!==403 && str_contains($operator['html'],'FT 42') && str_contains($operator['html'],'02-10-2026'),'Existing operator must see reduced report');
    foreach (['SECRET-','private-master-id','private-page-id','PRIVATE-PARENT','receipt-document.php?id=','name="issuer_name"','name="confirmed"','value="review"','value="retry"'] as $private) reportCheck(!str_contains($operator['html'],$private),'Actual operator HTML leaked individual history/corrections');
    $status=json_decode($render('governanta',['receipts.run'],'',false,true)['html'],true,512,JSON_THROW_ON_ERROR);
    reportCheck($status['history']===null && !str_contains(json_encode($status),'SECRET-') && !str_contains(json_encode($status),'private-page-id'),'Polling report never exposes documents');
    foreach (['review','retry','regroup','repair','replace_source','confirm_buyer','discard'] as $action) reportCheck($render('governanta',['receipts.run'],$action)['status']===403,'Operator forged individual action was not rejected');
    reportCheck($render('governanta',[])['status']===403 && $render('governanta',['receipts.view'])['status']===403,'No/legacy-only access must fail closed');
    reportCheck($render('governanta',['receipts.run'],'',false,false,['correction_files'=>'private-error-id'])['status']===403,'Operator cannot list correction files');
    $rejected=$render('gerente',[],'start');
    reportCheck(str_contains($rejected['html'],'id="receipt-request-error"') && str_contains($rejected['html'],InvoiceText::get('receipt_select_periods')),'Rejected request must have a persistent dedicated error');
    reportCheck(str_contains($rejected['html'],'value="1999"') && preg_match('/name="months\[\]" value="6" checked/',$rejected['html'])===1,'Rejected selection must remain on the form');
    $manager=$render('gerente',[],'',false,false,['filter'=>'all']);
    reportCheck(str_contains($manager['html'],'receipt-document.php?id=private-normal-id'),'Accepted JPEG offers final archive preview');
    $formDom=new DOMDocument();@$formDom->loadHTML($manager['html']);$formPath=new DOMXPath($formDom);
    reportCheck($formPath->query('//form[@data-receipt-review]//input[@name="buyer_confirmed"]')->length===1,'Buyer approval belongs in the same complete details form');
    reportCheck($formPath->query('//form[@data-receipt-review]//input[@name="issuer_nif"]')->length===1,'Supplier NIF is an editable mandatory field');
    reportCheck($formPath->query('//form[@data-receipt-review]//input[@name="issued_on" and @placeholder="DD/MM/AAAA"]')->length===1,'Date has the explicit requested placeholder');
    reportCheck($formPath->query('//input[@value="confirm_buyer"]')->length===0,'No separate partial buyer-only submit');
    reportCheck($formPath->query('//select[@name="process_year"]')->length===1 && $formPath->query('//select[@name="process_month"]')->length===1,'Execution period filters are available');
    $counts=static function(string $html,array $expected): void {
        $dom=new DOMDocument();@$dom->loadHTML($html);$xpath=new DOMXPath($dom);
        $heading=$xpath->query('//*[@data-receipt-history-counts]')->item(0);
        reportCheck($heading!==null,'History must identify its filtered invoice counters');
        preg_match_all('/\d+/', $heading->textContent,$numbers);
        reportCheck(array_map('intval',$numbers[0])===$expected,'History counts must exclude sources and use resolved work');
    };
    $counts($manager['html'],[4,3,1]);
    $counts($render('gerente',[],'',false,false,['filter'=>'success'])['html'],[3,3,0]);
    $counts($render('gerente',[],'',false,false,['filter'=>'problems'])['html'],[1,0,1]);
    $polled=json_decode($render('gerente',[],'',false,true,['filter'=>'problems'])['html'],true,512,JSON_THROW_ON_ERROR);
    $counts($polled['history'],[1,0,1]);
    $dom=new DOMDocument();@$dom->loadHTML($manager['html']);$xpath=new DOMXPath($dom);
    reportCheck($xpath->query('//*[contains(@class,"receipt-process-card")]//*[@id="receipt-status"]')->length===1,'Selected status belongs to process card');
    reportCheck($xpath->query('//*[@data-receipt-refresh]')->length===1,'Exactly one manual refresh action');
    reportCheck($xpath->query('//*[contains(@class,"receipt-process-card")]//*[@id="receipt-processing-message"]')->length===1,'Banner belongs inside process card');
    reportCheck($xpath->query('//*[contains(@class,"receipt-history-heading")]//select[@name="filter"]')->length===1,'Document filter sits beside history counts');
    reportCheck(!str_contains($manager['html'],'Last request selection') && !str_contains($manager['html'],'You can start a new run when processing stops.'),'Repeated intake explanations removed');

    reportCheck($xpath->query('//article[contains(.,"SECRET-ERROR.jpg")]//details[contains(@data-url,"original=1")]')->length===0,'JPEG never offers a source PDF');

    reportCheck(str_contains($manager['html'],'123456789') && !str_contains($manager['html'],'NIF: 500000000'),'Display supplier NIF, never recipient fallback');
    reportCheck(str_contains($manager['html'],'SECRET-NORMAL-NUMBER') && str_contains($manager['html'],'receipt-document.php?id=') && str_contains($manager['html'],'name="confirmed"'),'Manager detailed history/review regressed: '.json_encode([str_contains($manager['html'],'SECRET-NORMAL-NUMBER'),str_contains($manager['html'],'receipt-document.php?id='),str_contains($manager['html'],'name="confirmed"')]));
    reportCheck($render('governanta',['receipts.run'],'',true)['status']===403,'Actual document endpoint denies run-only operator');
    $candidateQuery=['id'=>'private-error-id','selection'=>'synthetic-selection'];
    reportCheck($render('governanta',['receipts.history'],'',true,false,$candidateQuery)['status']===403,'History permission cannot retrieve unrecorded correction bytes');
    reportCheck($render('governanta',['receipts.review'],'',true,false,$candidateQuery)['status']===422,'Delegated reviewer reaches the document eligibility gate');
    reportCheck($render('governanta',['receipts.history'],'',false,false,['correction_files'=>'private-error-id'])['status']===403,'History permission cannot browse correction folders');
    reportCheck($render('gerente',[],'',true)['status']===422,'Actual document endpoint rejects missing document ID');
    $history=$render('governanta',['receipts.history']);
    reportCheck(str_contains($history['html'],'SECRET-NORMAL-NUMBER') && !str_contains($history['html'],'name="confirmed"'),'Future history permission must not imply corrections');
    reportCheck(!str_contains($history['html'],'name="buyer_confirmed"') && !str_contains($history['html'],'value="discard"'),'History alone cannot confirm buyer or delete');
    $review=$render('governanta',['receipts.review']);
    reportCheck(str_contains($review['html'],'name="buyer_confirmed"') && str_contains($review['html'],'value="discard"'),'Delegated review permits buyer confirmation and scoped deletion');
    reportCheck(!str_contains($review['html'],'value="repair"'),'Wrong NIF alone must not offer missing-page repair');
    reportCheck(str_contains($review['html'],'name="confirmed"'),'Future explicit review permission not usable');
    // A failed source still needs its retry/grouping controls, without counting
    // the original again as an invoice or changing the worker's pending state.
    $state=$vault->read(InvoiceReceipts::FILE);
    $state['documents']['private-master-id']['stage']='failed';
    $state['documents']['private-master-id']['error']='drive_permission';
    $vault->save(InvoiceReceipts::FILE,$state);
    $problems=$render('gerente',[],'',false,false,['filter'=>'problems']);
    $counts($problems['html'],[1,0,1]);
    reportCheck(str_contains($problems['html'],'private-master-id') && str_contains($problems['html'],'value="retry"'),'Source error action remains accessible');
    $state=InvoiceReceiptReport::withProcesses($state);
    reportCheck(InvoiceReceiptReport::processPending($state,'legacy'),'Presentation must not hide pending source cleanup from processing');
    $state['processes']['another']=['id'=>'another','requested_at'=>'2026-11-14T12:00:00Z','year'=>2026,'months'=>[11]];
    for($i=0;$i<55;$i++) $state['documents']['extra-'.$i]=array_merge($documents['private-normal-id'],['process_id'=>'another']);
    $vault->save(InvoiceReceipts::FILE,$state);
    $counts($render('gerente',[],'',false,false,['process'=>'legacy'])['html'],[4,3,1]);
    $second=$render('gerente',[],'',false,false,['process'=>'another','page'=>2]);
    $counts($second['html'],[55,55,0]);
    reportCheck(substr_count($second['html'],'<article class="card receipt-invoice"')===5,'Pagination retains matching documents and total counts across pages');
    reportCheck(str_contains($second['html'],'process_year=2026') && str_contains($second['html'],'process_month=11'),'Pagination retains execution period filters');
    $empty=$render('gerente',[],'',false,false,['process_year'=>2025,'process_month'=>1]);
    reportCheck(!str_contains($empty['html'],'SECRET-NORMAL-NUMBER') && str_contains($empty['html'],InvoiceText::get('receipt_no_runs')),'An empty period never falls back to another execution');

    // Real controller POST: all corrections and buyer approval commit together;
    // no external request is needed until the separate worker runs.
    $vault->save('toconline-settings.enc',['nif'=>'500000000','enabled'=>false]);
    $state=['documents'=>['private-error-id'=>array_merge($documents['private-error-id'],['id'=>'private-error-id','company_nif'=>'600000000'])],'requested'=>false,'requested_at'=>gmdate('c'),'year'=>2026,'months'=>[6]];
    $vault->save(InvoiceReceipts::FILE,$state);
    $badPost=['issued_on'=>'02/04/2026','invoice_number'=>'FT 7','issuer_name'=>'Synthetic supplier','issuer_nif'=>'','confirmed'=>'1','buyer_confirmed'=>'1'];
    $bad=$render('gerente',[],'review',false,false,[],$badPost);
    reportCheck(str_contains($bad['html'],'id="receipt-request-error"') && !isset($vault->read(InvoiceReceipts::FILE)['documents']['private-error-id']['company_confirmation']),'Missing supplier NIF cannot partially save buyer confirmation');
    $goodPost=array_replace($badPost,['issuer_nif'=>'123456789']);
    $submitted=$render('governanta',['receipts.review'],'review',false,false,[],$goodPost);
    preg_match('/id="receipt-request-error"[^>]*>(.*?)<\/div>/s',$submitted['html'],$submitError);
    reportCheck($submitted['status']===303,'Delegated review accepts the complete explicit confirmation: '.($submitError[1]??'status '.json_encode($submitted['status'])));
    $saved=$vault->read(InvoiceReceipts::FILE)['documents']['private-error-id'];
    reportCheck($saved['stage']==='ready' && $saved['issued_on']==='2026-04-02' && $saved['company_nif']==='600000000' && $saved['company_confirmation']['nif']==='500000000','Controller queues once, preserves raw buyer NIF and stores configured confirmation separately');
    $queued=$render('gerente',[]);
    $dom=new DOMDocument();@$dom->loadHTML($queued['html']);$path=new DOMXPath($dom);
    reportCheck($path->query('//*[@data-receipt-intake and @disabled]')->length===1,'Year, months and start are locked during automatic work');
    reportCheck($path->query('//*[@id="receipt-processing-message" and not(@hidden)]')->length===1,'Background notice is visible only while automatic work exists');
    reportCheck($path->query('//form[@data-receipt-review]')->length===0 && $path->query('//input[@value="discard"]')->length===0,'Successful review removes the correction and delete controls');
    $poll=json_decode($render('gerente',[],'',false,true)['html'],true,512,JSON_THROW_ON_ERROR);
    reportCheck(!$poll['can_start'] && $poll['processing'] && str_contains(implode(' ',$poll['process_labels']),InvoiceText::get('receipt_run_running')),'Polling agrees with Running and the global intake lock');
    $runningFiltered=json_decode($render('gerente',[],'',false,true,['filter'=>'success'])['html'],true,512,JSON_THROW_ON_ERROR);
    reportCheck($runningFiltered['selected_running'] && str_contains($runningFiltered['history'],'SECRET-ERROR.jpg'),'Running ignores a stale success filter');
    reportCheck(str_contains($runningFiltered['processing_message'],'2026') && str_contains($runningFiltered['processing_message'],'Junho'),'Banner contains actual selected months/year');
    $state=$vault->read(InvoiceReceipts::FILE);
    $state['processes']['stopped']=['id'=>'stopped','requested_at'=>gmdate('c'),'year'=>2026,'months'=>[1]];
    $vault->save(InvoiceReceipts::FILE,$state);
    $finishedState=$state;$finishedState['documents']['private-error-id']['stage']='review';
    $vault->save(InvoiceReceipts::FILE,$finishedState);
    $transition=json_decode($render('gerente',[],'',false,true,['filter'=>'auto','process'=>'legacy'])['html'],true,512,JSON_THROW_ON_ERROR);
    reportCheck(!$transition['selected_running'] && $transition['filter']==='problems','Running-to-pending selects the actionable filter');
    $vault->save(InvoiceReceipts::FILE,$state);
    $stopped=json_decode($render('gerente',[],'',false,true,['process'=>'stopped'])['html'],true,512,JSON_THROW_ON_ERROR);
    reportCheck(!$stopped['selected_running'] && !$stopped['processing'] && !$stopped['can_start'],'Stopped selection has no banner despite other active work');
    $again=$render('gerente',[],'review' ,false,false,[],$goodPost);
    reportCheck($again['status']!==303 && $vault->read(InvoiceReceipts::FILE)['documents']['private-error-id']===$saved,'A repeated confirmation cannot restart or overwrite the queued document');
    echo "Receipt report: totals, duplicate company/number/date, safe errors, actual endpoint role isolation and denied forged POSTs passed.\n";
} finally {
    foreach (glob($tmp.'/sess_*')?:[] as $sessionFile) unlink($sessionFile);
    foreach ([$tmp.'/web/admin/assets',$tmp.'/web/src',$tmp.'/web/assets'] as $link) unlink($link);
    foreach (glob($tmp.'/vault/*')?:[] as $file) unlink($file);
    unlink($tmp.'/web/admin/receipt-history.php'); unlink($tmp.'/web/admin/receipt-document.php'); unlink($tmp.'/web/admin/receipts.php'); unlink($tmp.'/web/lib.php'); unlink($tmp.'/web/config.php');
    rmdir($tmp.'/web/admin'); rmdir($tmp.'/web'); rmdir($tmp.'/vault'); rmdir($tmp);
}
