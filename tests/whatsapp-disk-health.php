<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/src/Notifications/WhatsAppDiskHealthNotifier.php';

function verify(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException('FAIL: ' . $message);
    echo 'PASS: ' . $message . PHP_EOL;
}
function txt(string $day, string $code, string $status = 'warning'): string {
    return "schema=whm-disk-v1\n".
        "date_lisbon={$day}\n".
        "status={$status}\n".
        "alert_code={$code}\n".
        "quota_percent=82.51\n".
        "usage_mb=4950.0\n";
}
$day = (new DateTimeImmutable('now', new DateTimeZone('Europe/Lisbon')))->format('Y-m-d');
verify(WhatsAppDiskHealthNotifier::parse(txt($day,'quota80'))['code'] === 'quota80',
       'Accepts a valid warning report with exact fields');
verify(WhatsAppDiskHealthNotifier::parse(txt($day,'none','ok'))['code'] === 'none',
       'Accepts a healthy report');
verify(WhatsAppDiskHealthNotifier::parse(txt($day,'none','critical')) === null,
       'Rejects contradictory report status');
verify(WhatsAppDiskHealthNotifier::parse(txt($day,'quota80')."alert_code=quota90\n") === null,
       'Rejects duplicate untrusted keys');
verify(WhatsAppDiskHealthNotifier::parse(str_replace('whm-disk-v1','other',txt($day,'quota80'))) === null,
       'Rejects unknown schema');
verify(WhatsAppDiskHealthNotifier::parse(txt($day,'not_approved')) === null,
       'Rejects arbitrary alert codes');

$dir = sys_get_temp_dir() . '/roomcheck-whm-test-' . bin2hex(random_bytes(8));
if (!mkdir($dir,0700)) throw new RuntimeException('No temporary directory');
$report = $dir . '/whm-disk-' . $day . '.txt';
try {
    file_put_contents($report,txt($day,'quota80'));
    $disabled = new WhatsAppDiskHealthNotifier(null,[
        'disk_alert'=>['enabled'=>false,'template_name'=>WhatsAppDiskHealthNotifier::TEMPLATE]
    ],$dir);
    verify($disabled->dispatch(static fn()=>throw new RuntimeException('sent')) ===
           'disabled_until_meta_approval','Does not send before Meta approval');
    $notifier = new WhatsAppDiskHealthNotifier(null,[
        'disk_alert'=>['enabled'=>true,'template_name'=>WhatsAppDiskHealthNotifier::TEMPLATE]
    ],$dir);
    $sent = [];
    $sender = static function($mobile,$values,$language,$template) use (&$sent) {
        $sent[]=['mobile'=>$mobile,'values'=>$values,'language'=>$language,'template'=>$template];
        return 'mock-meta-message-id';
    };
    $recipient = static fn() => '+351900000000';
    verify($notifier->dispatch($sender,$recipient)==='meta_accepted',
           'Test sender accepts first incident');
    verify(count($sent)===1 && count($sent[0]['values'])===3
           && $sent[0]['template']==='whm_storage_alert_v1'
           && $sent[0]['language']==='pt_PT',
           'Uses exactly three Meta template placeholders and Portuguese locale');
    verify($notifier->dispatch($sender,$recipient)==='already_notified'
           && count($sent)===1,'Does not send same incident again');
    file_put_contents($report,txt($day,'none','ok'));
    verify($notifier->dispatch($sender,$recipient)==='healthy_no_message',
           'Healthy report resets deduplication without sending');
    file_put_contents($report,txt($day,'quota90','critical'));
    verify($notifier->dispatch($sender,$recipient)==='meta_accepted'
           && count($sent)===2,'Escalated incident sends once');

    file_put_contents($report,txt($day,'growth','warning'));
    $failureSender = static fn()=>throw new RuntimeException('unconfirmed');
    verify($notifier->dispatch($failureSender,$recipient)==='delivery_unconfirmed',
           'Provider errors do not expose sensitive information');
    verify($notifier->dispatch($sender,$recipient)==='already_notified'
           && count($sent)===2,'Unconfirmed provider response cannot cause duplicate delivery');
    verify(is_file($dir.'/whm-disk-whatsapp-state.json'),
           'Deduplication state stored in the private directory');
    verify((fileperms($dir.'/whm-disk-whatsapp-state.json') & 0777)===0600,
           'State has owner-only file permissions');
} finally {
    foreach (glob($dir.'/*') ?: [] as $f) unlink($f);
    rmdir($dir);
}
echo "WhatsApp WHM disk alert contract passed.\n";
