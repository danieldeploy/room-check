<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/src/Notifications/WhatsAppDiskHealthNotifier.php';

function verify(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException('FAIL: ' . $message);
    echo 'PASS: ' . $message . PHP_EOL;
}
function txt(string $day, string $code, string $status = 'warning', float $pct = 90): string {
    return "schema=whm-disk-v1\n".
        "date_lisbon={$day}\n".
        "status={$status}\n".
        "alert_code={$code}\n".
        "quota_percent=" . number_format($pct, 2, '.', '') . "\n".
        "usage_mb=4950.0\n";
}
$day = (new DateTimeImmutable('now', new DateTimeZone('Europe/Lisbon')))->format('Y-m-d');
verify(WhatsAppDiskHealthNotifier::parse(txt($day,'quota90'))['code'] === 'quota90',
       'Accepts 90 percent alert');
verify(WhatsAppDiskHealthNotifier::parse(txt($day,'quota95','warning',95))['code'] === 'quota95',
       'Accepts 95 percent alert');
verify(WhatsAppDiskHealthNotifier::parse(txt($day,'quota98','critical',98))['code'] === 'quota98',
       'Accepts 98 percent alert');
verify(WhatsAppDiskHealthNotifier::parse(txt($day,'quota100','critical',100))['code'] === 'quota100',
       'Accepts 100 percent alert');
verify(WhatsAppDiskHealthNotifier::parse(txt($day,'quota80','warning',80)) === null,
       '80 percent no longer a WhatsApp alert');
verify(WhatsAppDiskHealthNotifier::parse(txt($day,'none','ok',89.9))['code'] === 'none',
       'Accepts healthy report');
verify(WhatsAppDiskHealthNotifier::parse(txt($day,'none','critical',89.9)) === null,
       'Rejects contradictory report status');
verify(WhatsAppDiskHealthNotifier::parse(txt($day,'quota90')."alert_code=quota95\n") === null,
       'Rejects duplicate untrusted keys');
verify(WhatsAppDiskHealthNotifier::parse(str_replace('whm-disk-v1','other',txt($day,'quota90'))) === null,
       'Rejects unknown schema');
verify(WhatsAppDiskHealthNotifier::parse(txt($day,'not_approved')) === null,
       'Rejects arbitrary alert codes');

$dir = sys_get_temp_dir() . '/roomcheck-whm-test-' . bin2hex(random_bytes(8));
if (!mkdir($dir,0700)) throw new RuntimeException('No temporary directory');
$report = $dir . '/whm-disk-' . $day . '.txt';
try {
    file_put_contents($report,txt($day,'quota90'));
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
    $scenarios = [
        // [code, percentage, status, expected result, accepted send count]
        ['none', 89.99, 'ok', 'healthy_no_message', 0],
        ['quota90', 90, 'warning', 'meta_accepted', 1],
        ['quota90', 94.9, 'warning', 'already_notified', 1],
        ['quota95', 95, 'warning', 'meta_accepted', 2],
        ['quota95', 97.9, 'warning', 'already_notified', 2],
        ['quota98', 98, 'critical', 'meta_accepted', 3],
        ['quota98', 99.9, 'critical', 'already_notified', 3],
        ['quota100', 100, 'critical', 'meta_accepted', 4],
        ['quota98', 99, 'critical', 'already_notified', 4],
        ['quota95', 96, 'warning', 'already_notified', 4],
        ['quota90', 92, 'warning', 'already_notified', 4],
        ['none', 89.99, 'ok', 'healthy_no_message', 4],
        ['quota90', 90, 'warning', 'meta_accepted', 5],
    ];
    foreach ($scenarios as [$code, $pct, $status, $expected, $count]) {
        file_put_contents($report,txt($day,$code,$status,$pct));
        $result = $notifier->dispatch($sender,$recipient);
        verify($result === $expected && count($sent) === $count,
               "Quota {$pct}%: {$expected}; sends={$count}");
    }
    verify(count($sent[0]['values']) === 3
           && $sent[0]['template'] === 'whm_storage_alert_v1'
           && $sent[0]['language'] === 'pt_PT',
           'Uses exactly three Meta placeholders and existing locale');

    // A jump from healthy straight to 98% sends exactly one alert at 98%.
    file_put_contents($report,txt($day,'none','ok',80));
    $notifier->dispatch($sender,$recipient);
    file_put_contents($report,txt($day,'quota98','critical',98.5));
    verify($notifier->dispatch($sender,$recipient)==='meta_accepted'
           && count($sent)===6,'Fast occupancy jump sends highest crossed tier once');
    file_put_contents($report,txt($day,'quota95','warning',96));
    verify($notifier->dispatch($sender,$recipient)==='already_notified'
           && count($sent)===6,'Decreasing from 98 to 95 does not resend');

    // A provider error must not cause repeated messages for the same tier.
    file_put_contents($report,txt($day,'quota100','critical',100));
    $failureSender = static fn()=>throw new RuntimeException('unconfirmed');
    verify($notifier->dispatch($failureSender,$recipient)==='delivery_unconfirmed',
           'Ambiguous provider reply is recorded without a duplicate');
    verify($notifier->dispatch($sender,$recipient)==='already_notified'
           && count($sent)===6,'Unconfirmed provider response does not repeat');

    // Existing pre-upgrade state must not trigger another quota90.
    file_put_contents($report,txt($day,'none','ok',85));
    $notifier->dispatch($sender,$recipient);
    file_put_contents($dir.'/whm-disk-whatsapp-state.json',
        '{"code":"quota90","state":"sent","date":"' . $day . '"}');
    file_put_contents($report,txt($day,'quota90','warning',90));
    verify($notifier->dispatch($sender,$recipient)==='already_notified'
           && count($sent)===6,'Legacy quota90 state migrates without resending');
    file_put_contents($report,txt($day,'quota95','warning',95));
    verify($notifier->dispatch($sender,$recipient)==='meta_accepted'
           && count($sent)===7,'Legacy quota90 can escalate to 95');

    verify(is_file($dir.'/whm-disk-whatsapp-state.json'),
           'Deduplication state stored in the private directory');
    verify((fileperms($dir.'/whm-disk-whatsapp-state.json') & 0777)===0600,
           'State has owner-only file permissions');
} finally {
    foreach (glob($dir.'/*') ?: [] as $f) unlink($f);
    rmdir($dir);
}
echo "WhatsApp WHM disk alert tiers contract passed.\n";
