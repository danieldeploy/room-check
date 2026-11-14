<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/src/Invoices/InvoiceReceipts.php';
function ck(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
$dir=sys_get_temp_dir().'/receipt-confirm-'.bin2hex(random_bytes(6));mkdir($dir,0700);file_put_contents($dir.'/master.key',random_bytes(32));chmod($dir.'/master.key',0600);
try {
    $v=new InvoiceVault($dir);$p=new PDO('sqlite::memory:');$p->exec('CREATE TABLE invoice_document_delivery(document_id INTEGER,toconline_state TEXT);INSERT INTO invoice_document_delivery VALUES(7,"toc_submitted")');
    $v->save('toconline-settings.enc',['nif'=>'500000000','sender'=>'test@welcomehostel.pt']);
    $start=time();$key=str_repeat('a',64);$entry=['document_id'=>'receipt:scan','nif'=>'500000000','sender'=>'test@welcomehostel.pt','state'=>'toc_submitted','attempted_at'=>gmdate('c',$start)];
    $v->save('toconline-ledger.enc',[$key=>$entry,'portal'=>array_replace($entry,['document_id'=>7])]);
    $v->save(InvoiceReceipts::FILE,['requested'=>false,'documents'=>['scan'=>['stage'=>'archived','toconline_state'=>'toc_submitted']]]);
    $sends=0;$toc=new InvoiceToconline($p,$v,new stdClass,static function()use(&$sends){$sends++;return true;});
    $toc->reconcile($start+299);$d=$v->read(InvoiceReceipts::FILE)['documents']['scan'];
    ck(($d['toc_next_check']??0)===$start+300,'receipt exposes first five-minute confirmation check');
    for($i=1;$i<=6;$i++) {
        $toc->reconcile($start+$i*300);$ledger=$v->read('toconline-ledger.enc');
        ck($ledger[$key]['receipt_checks']===$i,'one real confirmation check per due interval');
        ck($ledger[$key]['state']===($i===6?'toc_no_confirmation':'toc_submitted'),'receipt timeout after sixth check at 30 minutes');
        $toc->reconcile($start+$i*300);ck($v->read('toconline-ledger.enc')[$key]['receipt_checks']===$i,'repeated cron does not consume a second check');
    }
    ck($p->query('SELECT toconline_state FROM invoice_document_delivery')->fetchColumn()==='toc_submitted','portal retains existing 72-hour window');
    $proof=['authentication'=>['verified'=>true,'code'=>'verified','recipient'=>'test@welcomehostel.pt','signed_at'=>gmdate('c')],'outcome'=>'toc_existing'];
    $toc->receive('no_reply@toconline.pt','','Foram recusados os seguintes ficheiros por já constarem no seu arquivo: receipt-'.$key.'.pdf',hash('sha256','late'),$proof);
    ck($v->read(InvoiceReceipts::FILE)['documents']['scan']['toconline_state']==='toc_existing','late authenticated reply may resolve timeout');
    ck($sends===0,'confirmation queries and expiry never send an invoice');
    echo "Receipt six-check timeout, late reply and portal isolation passed\n";
}finally{foreach(glob($dir.'/*')?:[] as $f)unlink($f);rmdir($dir);}
