<?php
declare(strict_types=1);

require dirname(__DIR__) . '/deploy/sync_cron.php';

function cronAssert(bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
}

$external = "MAILTO=ops@example.test\n15 3 * * * /opt/backups/run >/dev/null 2>&1\n";
$manifest = hubCronManifest();
$managed = hubCronManagedLines($manifest['jobs']);
$oldHub = implode("\n", $manifest['adopt_lines']) . "\n";
$rendered = hubCronRender($external . $oldHub, $managed, $manifest['adopt_lines']);

cronAssert(str_contains($rendered, '15 3 * * * /opt/backups/run'), 'external cron was changed');
cronAssert(str_contains($rendered, 'MAILTO=ops@example.test'), 'external environment was changed');
cronAssert(substr_count($rendered, HUB_CRON_BEGIN) === 1 && substr_count($rendered, HUB_CRON_END) === 1,
    'managed markers are not unique');
cronAssert(substr_count($rendered, 'cron/invoices.php') === 1, 'invoice cron was duplicated');
cronAssert(substr_count($rendered, 'cron/translation-pending.php') === 1, 'translation cron missing');
cronAssert(substr_count($rendered, 'cron/whatsapp-reminders.php') === 1, 'WhatsApp cron missing');
cronAssert(hubCronRender($rendered, $managed, $manifest['adopt_lines']) === $rendered,
    'cron synchronization is not idempotent');

$my2n = "0 4 * * * /usr/bin/php /home/welcome/room-check-private/cron/my2n-scheduler.php\n";
cronAssert(str_starts_with(hubCronRender($my2n, [], []), $my2n), 'unmanaged My2N skeleton cron was changed');
cronAssert(str_contains($rendered, 'INVOICES_NODE_BINARY=/home/welcome/nodevenv/booking-vault-agent/22/bin/node'), 'Node override lost');
$unrelated = "# external entry mentioning /home/welcome/room-check-private/cron/invoices.php\r\nMAILTO=ops@example.test\r\n\n15 3 * * * /opt/backups/run\n\n";
cronAssert(str_starts_with(hubCronRender($unrelated, $managed, []), $unrelated), 'external bytes changed');

foreach ([HUB_CRON_BEGIN . "\n", HUB_CRON_END . "\n", $rendered . $rendered,
    '* * * * * /usr/local/bin/php /home/welcome/room-check-private/cron/invoices.php && /opt/external/run'] as $invalid) {
    try { hubCronRender($invalid, $managed, $manifest['adopt_lines']); throw new LogicException('unsafe cron accepted'); }
    catch (HubCronError) {}
}
$edited = $manifest['jobs'];
$edited[0]['schedule'] = '*/5 * * * *';
array_pop($edited);
$updated = hubCronRender($rendered, hubCronManagedLines($edited), $manifest['adopt_lines']);
cronAssert(str_contains($updated, '*/5 * * * * /usr/local/bin/php'), 'schedule update failed');
cronAssert(!str_contains($updated, 'cron/invoices.php'), 'owned deletion failed');
cronAssert(str_starts_with($updated, $external), 'update touched external cron');
$invalidJobs = $manifest['jobs'];
$invalidJobs[0]['schedule'] = '61 * * * *';
try { hubCronManagedLines($invalidJobs); throw new LogicException('invalid schedule accepted'); }
catch (HubCronError) {}

$fixture = sys_get_temp_dir() . '/hub-cron-' . bin2hex(random_bytes(8));
mkdir($fixture, 0700);
$installed = $external . $oldHub;
$writes = 0;
$run = static function(array $args, string $stdin = '') use (&$installed, &$writes): array {
    if ($args[1] === '-') { $installed = $stdin; $writes++; }
    return ['code' => 0, 'stdout' => $args[1] === '-l' ? $installed : '', 'stderr' => ''];
};
try {
    hubCronSync($manifest, false, $fixture, $run);
    cronAssert($writes === 0 && glob($fixture . '/*') === [], 'check wrote state');
    hubCronSync($manifest, true, $fixture, $run);
    cronAssert($writes === 1 && $installed === $rendered, 'apply failed');
    hubCronSync($manifest, true, $fixture, $run);
    cronAssert($writes === 1, 'idempotent apply wrote again');
    $backups = glob($fixture . '/crontab-*.txt');
    cronAssert(count($backups) === 1 && file_get_contents($backups[0]) === $external . $oldHub
        && (fileperms($backups[0]) & 0777) === 0600, 'private backup failed');
    $reads = 0;
    $race = static function(array $args, string $stdin = '') use (&$reads): array {
        if ($args[1] !== '-l') { throw new LogicException('race caused a write'); }
        return ['code' => 0, 'stdout' => ++$reads === 1 ? '' : '0 1 * * * /opt/new/external', 'stderr' => ''];
    };
    try { hubCronSync($manifest, true, $fixture, $race); throw new LogicException('race was ignored'); }
    catch (HubCronError $e) { cronAssert($e->getMessage() === 'cron_changed_before_write', 'unexpected race error'); }
} finally {
    foreach (glob($fixture . '/*') ?: [] as $file) { unlink($file); }
    rmdir($fixture);
}

echo "project cron tests passed\n";
