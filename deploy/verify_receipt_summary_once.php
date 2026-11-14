<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/src/Invoices/InvoiceAlerts.php';

/** One owner-authorized synthetic send; no scan data changes, and no uncertain resend. */
function verifyReceiptSummaryOnce(PDO $pdo,InvoiceVault $vault,array $config,?callable $sender=null): string
{
    $file='receipt-summary-probe-v1.enc';
    if ($vault->has($file)) return $vault->read($file)['state']??'uncertain';
    $settings=$pdo->query('SELECT enabled,recipient_user_id FROM invoice_notification_settings WHERE id=1')->fetch(PDO::FETCH_ASSOC);
    if (!$settings || empty($settings['enabled'])) return 'notifications_disabled';
    $q=$pdo->prepare("SELECT mobile FROM users WHERE id=? AND role='gerente' AND is_active=1");$q->execute([$settings['recipient_user_id']]);$mobile=$q->fetchColumn();
    if (!$mobile) return 'recipient_unavailable';
    $receipt=['state'=>'uncertain','started_at'=>gmdate('c')];$vault->save($file,$receipt);
    $when=(new DateTimeImmutable('now',new DateTimeZone('Europe/Lisbon')))->format('d-m-Y H:i');
    $values=['Faturas A4 e pequenos talões','Teste técnico autorizado / '.$when,'Não aplicável — teste',
        'Ligação do resumo WhatsApp testada. Nenhuma fatura foi enviada ou alterada neste teste.','Nenhuma ação necessária. Os próximos resumos indicarão o resultado de cada processamento.'];
    try {
        $id=$sender?$sender($mobile,$values,InvoiceAlerts::RECEIPT_TEMPLATE):(new WhatsAppCloudClient($config))->sendTemplate($mobile,$values,'pt_PT',InvoiceAlerts::RECEIPT_TEMPLATE);
        if (!is_string($id) || $id==='') throw new RuntimeException('delivery_unconfirmed');
        $receipt['state']='meta_accepted';$receipt['message_id']=$id;
    } catch (Throwable) { /* Retain pre-send uncertainty, never raw provider errors. */ }
    $receipt['finished_at']=gmdate('c');$vault->save($file,$receipt);return $receipt['state'];
}
if (realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__) {
    if (PHP_SAPI!=='cli') { http_response_code(404);exit; }
    ini_set('display_errors','0');
    try {
        $app=$argv[1]??'';
        if (realpath($app)!=='/home/welcome/public_html/check') throw new RuntimeException('invalid_app');
        require $app.'/lib.php';$config=require $app.'/config.php';$pdo=database();
        if ((int)$pdo->query("SELECT GET_LOCK('room_check_invoices',10)")->fetchColumn()!==1) throw new RuntimeException('worker_busy');
        try {$state=verifyReceiptSummaryOnce($pdo,new InvoiceVault($config['invoices']['private_dir']),$config['whatsapp']);}
        finally {$pdo->query("SELECT RELEASE_LOCK('room_check_invoices')");}
        echo json_encode(['receipt_summary_probe'=>$state]),PHP_EOL;exit($state==='meta_accepted'?0:1);
    } catch (Throwable) {echo '{"receipt_summary_probe":"unverified"}',PHP_EOL;exit(1);}
}
