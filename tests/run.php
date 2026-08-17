<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/My2N/My2NRedactor.php';
require_once dirname(__DIR__) . '/src/My2N/My2NGateway.php';
require_once dirname(__DIR__) . '/src/My2N/My2NService.php';

final class FakeMy2NGateway implements My2NGateway
{
    public function listMobileConfigurations(): array
    {
        return ['configurations' => [[
            'id' => 9001,
            'siteId' => 7001,
            'deviceId' => 8001,
            'name' => 'Test Device',
            'status' => 'NOT_REGISTERED',
            'sipNumber' => '0000000000',
            'sipPassword' => 'must-never-leak',
        ]]];
    }

    public function getCurrentMembers(): array
    {
        return [9001];
    }

    public function updateMembers(array $memberIds): array
    {
        throw new RuntimeException('Writes must not be called by read-only tests.');
    }
}

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    echo 'PASS: ' . $message . PHP_EOL;
}

$config = [
    'site_id' => 7001,
    'intercom_device_id' => 8001,
    'ringing_group_sip_number' => '0000000001',
    'timezone' => 'Europe/Lisbon',
    'allow_writes' => false,
];

$service = new My2NService(new FakeMy2NGateway(), $config);
$status = $service->status();
$encoded = json_encode($status, JSON_THROW_ON_ERROR);

assertTrue($status['dryRun'] === true, 'read-only status reports dry-run');
assertTrue(count($status['devices']) === 1, 'one mobile configuration is normalized');
assertTrue($status['devices'][0]['memberId'] === 9001, 'member ID is preserved');
assertTrue($status['devices'][0]['status'] === 'NOT_REGISTERED', 'registration status is preserved');
assertTrue($status['devices'][0]['inCurrentGroup'] === true, 'current group membership is marked');
assertTrue(!str_contains(strtolower($encoded), 'sippassword'), 'sipPassword key is removed');
assertTrue(!str_contains($encoded, 'must-never-leak'), 'sipPassword value is removed');

$redacted = My2NRedactor::sanitize([
    'safe' => 'ok',
    'nested' => ['session_token' => 'secret-token', 'sipPassword' => 'secret-sip'],
]);
assertTrue(($redacted['safe'] ?? null) === 'ok', 'non-sensitive fields remain');
assertTrue(!isset($redacted['nested']['session_token']), 'session token is removed');
assertTrue(!isset($redacted['nested']['sipPassword']), 'nested sipPassword is removed');

echo 'All tests passed.' . PHP_EOL;
