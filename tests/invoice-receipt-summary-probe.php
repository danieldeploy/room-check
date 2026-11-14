<?php
declare(strict_types=1);
require dirname(__DIR__).'/deploy/verify_receipt_summary_once.php';
function sp(bool $v,string $m):void{if(!$v)throw new RuntimeException($m);}
$dir=sys_get_temp_dir().'/summary-probe-'.bin2hex(random_bytes(5));mkdir($dir,0700);file_put_contents($dir.'/master.key',random_bytes(32));chmod($dir.'/master.key',0600);
try{
 $p=new PDO('sqlite::memory:');$p->exec("CREATE TABLE invoice_notification_settings(id INTEGER,enabled INTEGER,recipient_user_id INTEGER);INSERT INTO invoice_notification_settings VALUES(1,1,1);CREATE TABLE users(id INTEGER,role TEXT,is_active INTEGER,mobile TEXT);INSERT INTO users VALUES(1,'gerente',1,'123456789');");$v=new InvoiceVault($dir);$calls=0;
 $sender=static function($to,$values,$template)use(&$calls,$v){$calls++;sp($to==='123456789' && $template===InvoiceAlerts::RECEIPT_TEMPLATE && count($values)===5,'fixed approved manager/template/contract');sp($v->has('receipt-summary-probe-v1.enc'),'receipt saved before any external send');return 'synthetic-message';};
 sp(verifyReceiptSummaryOnce($p,$v,[],$sender,false)==='awaiting_template_approval' && $calls===0,'unapproved template cannot send');
 sp(verifyReceiptSummaryOnce($p,$v,[],$sender,true)==='meta_accepted','acceptance recorded');verifyReceiptSummaryOnce($p,$v,[],$sender,true);sp($calls===1,'repeat release never repeats the test');
 unlink($v->path('receipt-summary-probe-v1.enc'));verifyReceiptSummaryOnce($p,$v,[],static function(){throw new RuntimeException('private provider reply');},true);
 sp(verifyReceiptSummaryOnce($p,$v,[],$sender,true)==='uncertain' && $calls===1,'uncertain test never blindly resent');
 unlink($v->path('receipt-summary-probe-v1.enc'));$p->exec('UPDATE invoice_notification_settings SET enabled=0');sp(verifyReceiptSummaryOnce($p,$v,[],$sender,true)==='notifications_disabled' && $calls===1,'respects existing notification preference');
 echo "Receipt summary probe recipient, contract and once-only boundaries passed\n";
}finally{foreach(glob($dir.'/*')?:[]as$f)unlink($f);rmdir($dir);}
