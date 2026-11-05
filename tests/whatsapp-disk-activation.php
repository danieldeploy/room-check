<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/src/Notifications/WhatsAppDiskHealthNotifier.php';
function check(bool $ok, string $label): void {
    if (!$ok) throw new RuntimeException('FAIL: ' . $label);
    echo "PASS: $label\n";
}
$dir = sys_get_temp_dir() . '/whm-activation-' . bin2hex(random_bytes(6));
mkdir($dir, 0700);
$day = (new DateTimeImmutable('now', new DateTimeZone('Europe/Lisbon')))->format('Y-m-d');
try {
    $n = new WhatsAppDiskHealthNotifier(null, ['disk_alert' => ['enabled' => null]], $dir);
    check($n->dispatch() === 'disabled_until_meta_approval', 'No activation means disabled');
    $calls = 0;
    $send = static function($mobile, $values, $locale, $template) use (&$calls): string {
        $calls++;
        check($mobile === '+351900000000' && count($values) === 3 && $locale === 'pt_PT'
            && $template === 'whm_storage_alert_v1', 'Uses existing recipient and approved dedicated template');
        check(str_contains($values[1], 'sem incidente real'), 'Explicit test without false alarm');
        return 'PRIVATE_META_MESSAGE_ID';
    };
    check($n->activateApprovedTemplateOnce($send, static fn() => '+351900000000') === 'meta_accepted', 'Enables only after Meta accepts test');
    check($n->activateApprovedTemplateOnce($send) === 'meta_accepted' && $calls === 1, 'Never resends activation test');
    file_put_contents($dir . '/whm-disk-' . $day . '.txt', "schema=whm-disk-v1\ndate_lisbon=$day\nstatus=ok\nalert_code=none\nquota_percent=72\n");
    check($n->runAndRecord() === 'healthy_no_message', 'Enabled normal dispatch records healthy check without sending');
    $raw = file_get_contents($dir . '/whm-disk-whatsapp-last-check.json');
    check(!str_contains($raw, 'PRIVATE') && !str_contains($raw, '+351'), 'Heartbeat contains no recipient or message identifier');
    $disabled = new WhatsAppDiskHealthNotifier(null, ['disk_alert' => ['enabled' => false]], $dir);
    check($disabled->dispatch() === 'disabled_until_meta_approval', 'Explicit disable overrides activation');
    check((fileperms($dir . '/whm-disk-alert-activation.json') & 0777) === 0600, 'Activation receipt stays private');
    mkdir($dir . '/failed',0700);
    $failed = new WhatsAppDiskHealthNotifier(null, ['disk_alert' => ['enabled' => null]], $dir . '/failed');
    check($failed->activateApprovedTemplateOnce(static fn() => throw new RuntimeException('uncertain'), static fn() => '+351900000000') === 'unconfirmed', 'Ambiguous result does not activate');
    check($failed->activateApprovedTemplateOnce(static fn() => throw new RuntimeException('must not retry')) === 'unconfirmed', 'Ambiguous test is never retried automatically');
    check($failed->dispatch() === 'disabled_until_meta_approval', 'Failure remains disabled');
} finally {
    foreach (glob($dir . '/failed/*') ?: [] as $p) unlink($p);
    if (is_dir($dir . '/failed')) rmdir($dir . '/failed');
    foreach (glob($dir . '/*') ?: [] as $p) unlink($p);
    rmdir($dir);
}
