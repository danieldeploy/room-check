<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/My2N/My2NRedactor.php';
require_once dirname(__DIR__) . '/src/My2N/My2NGateway.php';
require_once dirname(__DIR__) . '/src/My2N/My2NService.php';
require_once dirname(__DIR__) . '/src/Auth/Auth.php';

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

assertTrue(count(Auth::ROLES) === 4, 'exactly four authentication roles exist');
assertTrue(isset(Auth::ROLES['gerente']), 'Gerente role exists');
assertTrue(isset(Auth::ROLES['governanta']), 'Governanta role exists');
assertTrue(isset(Auth::ROLES['tecnico_manutencao']), 'Técnico de Manutenção role exists');
assertTrue(isset(Auth::ROLES['empregada_andares']), 'Empregada de Andares role exists');
assertTrue(array_keys(Auth::ROLES) === array_keys(Auth::ROLE_PERMISSIONS), 'every role has one permission set');
foreach (array_keys(Auth::ROLES) as $role) {
    assertTrue(
        Auth::roleHasPermission($role, Auth::PERMISSION_ROOM_CHECK_VIEW),
        $role . ' can view room checks'
    );
    assertTrue(
        Auth::roleHasPermission($role, Auth::PERMISSION_ROOM_CHECK_EDIT),
        $role . ' can edit room checks'
    );
}
assertTrue(Auth::roleHasPermission('gerente', Auth::PERMISSION_USERS_MANAGE), 'Gerente can manage users');
assertTrue(Auth::roleHasPermission('gerente', Auth::PERMISSION_MY2N_CONTROL), 'Gerente owns future My2N writes');
assertTrue(Auth::roleHasPermission('gerente', Auth::PERMISSION_MY2N_SCHEDULE), 'Gerente owns future My2N schedules');
assertTrue(Auth::roleHasPermission('gerente', Auth::PERMISSION_MY2N_ROLLBACK), 'Gerente owns future My2N rollback');
assertTrue(Auth::roleHasPermission('governanta', Auth::PERMISSION_MY2N_VIEW), 'Governanta can view My2N status');
assertTrue(Auth::roleHasPermission('tecnico_manutencao', Auth::PERMISSION_MY2N_VIEW), 'Técnico can view My2N status');
assertTrue(!Auth::roleHasPermission('empregada_andares', Auth::PERMISSION_MY2N_VIEW), 'Empregada cannot view My2N');
assertTrue(!Auth::roleHasPermission('governanta', Auth::PERMISSION_USERS_MANAGE), 'Governanta cannot manage users');
$my2nWritePermissions = [
    Auth::PERMISSION_MY2N_CONTROL,
    Auth::PERMISSION_MY2N_SCHEDULE,
    Auth::PERMISSION_MY2N_ROLLBACK,
];
foreach (['governanta', 'tecnico_manutencao', 'empregada_andares'] as $role) {
    foreach ($my2nWritePermissions as $permission) {
        assertTrue(!Auth::roleHasPermission($role, $permission), $role . ' cannot use ' . $permission);
    }
}
assertTrue(!Auth::roleHasPermission('unknown', Auth::PERMISSION_ROOM_CHECK_VIEW), 'unknown roles have no permissions');
$shortPasswordRejected = false;
try {
    Auth::validatePassword('short');
} catch (InvalidArgumentException) {
    $shortPasswordRejected = true;
}
assertTrue($shortPasswordRejected, 'short passwords are rejected');

echo 'All tests passed.' . PHP_EOL;
