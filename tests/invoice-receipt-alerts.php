<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/src/Invoices/InvoiceVault.php';require_once dirname(__DIR__).'/src/Invoices/InvoiceAlerts.php';
function ac(bool $v,string $m):void{if(!$v)throw new RuntimeException($m);}
ac(method_exists(InvoiceAlerts::class,'receipts'),'receipt exceptions must reach existing manager alerts');
$d=sys_get_temp_dir().'/alert-'.bin2hex(random_bytes(5));mkdir($d,0700);file_put_contents($d.'/master.key',random_bytes(32));chmod($d.'/master.key',0600);
try{$v=new InvoiceVault($d);$p=new PDO('sqlite::memory:');$p->exec("CREATE TABLE invoice_notification_settings(id INTEGER,enabled INTEGER,recipient_user_id INTEGER,template_name TEXT);INSERT INTO invoice_notification_settings VALUES(1,1,1,'invoice_failure');CREATE TABLE users(id INTEGER,role TEXT,is_active INTEGER,mobile TEXT);INSERT INTO users VALUES(1,'gerente',1,'123456789');");
$v->save('receipts-workflow.enc',['requested_at'=>'now','year'=>2026,'months'=>[4],'documents'=>[['id'=>'private','stage'=>'failed','error'=>'drive_permission','name'=>'PRIVATE-INVOICE.pdf','toconline_state'=>'pending']]]);
$a=new InvoiceAlerts($p,[]);$calls=[];$send=static function($number,$values,$template)use(&$calls){$calls[]=$values;return 'provider-message';};
$a->receipts($v,$send);$a->receipts($v,$send);ac(count($calls)===1,'repeated incident sends once');ac(!str_contains(json_encode($calls),'PRIVATE-INVOICE'),'no invoice contents in alert');
$s=$v->read('receipts-workflow.enc');$s['documents'][0]['error']='receipt_missing_pages';$v->save('receipts-workflow.enc',$s);
$a->receipts($v,static function(){throw new RuntimeException('uncertain provider result');});$a->receipts($v,$send);ac(count($calls)===1,'uncertain alert never blindly resent');
echo "Receipt alerts persist, deduplicate and protect private contents\n";
}finally{foreach(glob($d.'/*')?:[]as$f)unlink($f);rmdir($d);}
