<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/My2N/My2NRedactor.php';
require_once dirname(__DIR__) . '/src/My2N/My2NGateway.php';
require_once dirname(__DIR__) . '/src/My2N/My2NService.php';
require_once dirname(__DIR__) . '/src/My2N/My2NCredentialStore.php';
require_once dirname(__DIR__) . '/src/Auth/Auth.php';

final class FakeMy2NGateway implements My2NGateway
{
    public array $members = [9001];
    public int $updates = 0;

    public function listMobileConfigurations(): array
    {
        return ['configurations' => [
            [
                'id' => 9001,
                'siteId' => 7001,
                'deviceId' => 8001,
                'name' => 'Test Device',
                'status' => 'NOT_REGISTERED',
                'sipNumber' => '0000000000',
                'sipPassword' => 'must-never-leak',
            ],
            [
                'id' => 9002,
                'siteId' => 7001,
                'deviceId' => 8002,
                'name' => 'Test Device 2',
                'status' => 'REGISTERED',
                'sipNumber' => '0000000002',
            ],
        ]];
    }

    public function getCurrentMembers(): array
    {
        return $this->members;
    }

    public function updateMembers(array $memberIds): array
    {
        $this->members = array_values(array_map('intval', $memberIds));
        $this->updates++;
        return ['members' => $this->members];
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

$gateway = new FakeMy2NGateway();
$service = new My2NService($gateway, $config);
$status = $service->status();
$encoded = json_encode($status, JSON_THROW_ON_ERROR);

assertTrue($status['dryRun'] === true, 'read-only status reports dry-run');
assertTrue(count($status['devices']) === 2, 'mobile configurations are normalized');
assertTrue($status['devices'][0]['memberId'] === 9001, 'member ID is preserved');
assertTrue($status['devices'][0]['status'] === 'NOT_REGISTERED', 'registration status is preserved');
assertTrue($status['devices'][0]['inCurrentGroup'] === true, 'current group membership is marked');
assertTrue(!str_contains(strtolower($encoded), 'sippassword'), 'sipPassword key is removed');
assertTrue(!str_contains($encoded, 'must-never-leak'), 'sipPassword value is removed');

$update = $service->replaceMembers([9002], [9001]);
assertTrue($update['changed'] === true, 'destination group change is reported');
assertTrue($gateway->updates === 1, 'destination group is written once');
assertTrue($update['status']['currentMemberIds'] === [9002], 'destination group change is confirmed');
$noChange = $service->replaceMembers([9002], [9002]);
assertTrue($noChange['changed'] === false, 'unchanged destination group skips the write');
assertTrue($gateway->updates === 1, 'no-op does not write to My2N');
$emptySelectionRejected = false;
try {
    $service->replaceMembers([], [9002]);
} catch (InvalidArgumentException) {
    $emptySelectionRejected = true;
}
assertTrue($emptySelectionRejected, 'an empty destination group is rejected');

$redacted = My2NRedactor::sanitize([
    'safe' => 'ok',
    'nested' => ['session_token' => 'secret-token', 'sipPassword' => 'secret-sip'],
]);
assertTrue(($redacted['safe'] ?? null) === 'ok', 'non-sensitive fields remain');
assertTrue(!isset($redacted['nested']['session_token']), 'session token is removed');
assertTrue(!isset($redacted['nested']['sipPassword']), 'nested sipPassword is removed');

$temporaryDirectory = sys_get_temp_dir() . '/room-check-my2n-' . bin2hex(random_bytes(4));
$credentialFile = $temporaryDirectory . '/credentials.json';
$previousDocumentRoot = $_SERVER['DOCUMENT_ROOT'] ?? null;
$_SERVER['DOCUMENT_ROOT'] = dirname(__DIR__);
$credentialStore = new My2NCredentialStore($credentialFile);
$credentialStore->save('api@example.test', 'provider-password');
assertTrue($credentialStore->isConfigured(), 'My2N credentials are stored outside the web root');
assertTrue($credentialStore->read()['identifier'] === 'api@example.test', 'My2N identifier can be read by the server');
assertTrue(!str_contains((string) $credentialStore->maskedIdentifier(), 'api@example.test'), 'My2N identifier is masked in the portal');
assertTrue((fileperms($credentialFile) & 0777) === 0600, 'My2N credentials file uses mode 0600');
@unlink($credentialFile);
@rmdir($temporaryDirectory);
if ($previousDocumentRoot === null) {
    unset($_SERVER['DOCUMENT_ROOT']);
} else {
    $_SERVER['DOCUMENT_ROOT'] = $previousDocumentRoot;
}

assertTrue(count(Auth::ROLES) === 4, 'exactly four authentication roles exist');
assertTrue(isset(Auth::ROLES['gerente']), 'Gerente role exists');
assertTrue(isset(Auth::ROLES['governanta']), 'Governanta role exists');
assertTrue(isset(Auth::ROLES['tecnico_manutencao']), 'Técnico de Manutenção role exists');
assertTrue(isset(Auth::ROLES['empregada_andares']), 'Empregada de Andares role exists');
assertTrue(array_keys(Auth::ROLES) === array_keys(Auth::DEFAULT_ROLE_PERMISSIONS), 'every role has one default permission set');
foreach (array_keys(Auth::ROLES) as $role) {
    assertTrue(
        Auth::defaultRoleHasPermission($role, Auth::PERMISSION_ROOM_CHECK_VIEW),
        $role . ' can view room checks'
    );
    assertTrue(
        Auth::defaultRoleHasPermission($role, Auth::PERMISSION_ROOM_CHECK_EDIT),
        $role . ' can edit room checks'
    );
}
assertTrue(Auth::defaultRoleHasPermission('gerente', Auth::PERMISSION_USERS_MANAGE), 'Gerente can manage users');
assertTrue(Auth::defaultRoleHasPermission('gerente', Auth::PERMISSION_PERMISSIONS_MANAGE), 'Gerente can manage permissions');
assertTrue(Auth::defaultRoleHasPermission('gerente', Auth::PERMISSION_ZKACCESS_CONFIGURE), 'Gerente can configure ZKAccess');
assertTrue(Auth::defaultRoleHasPermission('gerente', Auth::PERMISSION_MY2N_CREDENTIALS), 'Gerente can manage My2N credentials');
assertTrue(Auth::defaultRoleHasPermission('gerente', Auth::PERMISSION_MY2N_CONTROL), 'Gerente owns future My2N writes');
assertTrue(Auth::defaultRoleHasPermission('gerente', Auth::PERMISSION_MY2N_SCHEDULE), 'Gerente owns future My2N schedules');
assertTrue(Auth::defaultRoleHasPermission('gerente', Auth::PERMISSION_MY2N_ROLLBACK), 'Gerente owns future My2N rollback');
assertTrue(Auth::defaultRoleHasPermission('governanta', Auth::PERMISSION_MY2N_VIEW), 'Governanta can view My2N status');
assertTrue(Auth::defaultRoleHasPermission('tecnico_manutencao', Auth::PERMISSION_ZKACCESS_VIEW), 'Técnico can view ZKAccess status');
assertTrue(!Auth::defaultRoleHasPermission('tecnico_manutencao', Auth::PERMISSION_ZKACCESS_CONFIGURE), 'Técnico cannot configure ZKAccess by default');
assertTrue(Auth::defaultRoleHasPermission('tecnico_manutencao', Auth::PERMISSION_MY2N_VIEW), 'Técnico can view My2N status');
assertTrue(!Auth::defaultRoleHasPermission('empregada_andares', Auth::PERMISSION_ZKACCESS_VIEW), 'Empregada cannot view ZKAccess');
assertTrue(!Auth::defaultRoleHasPermission('empregada_andares', Auth::PERMISSION_MY2N_VIEW), 'Empregada cannot view My2N');
assertTrue(!Auth::defaultRoleHasPermission('governanta', Auth::PERMISSION_USERS_MANAGE), 'Governanta cannot manage users');
$my2nWritePermissions = [
    Auth::PERMISSION_MY2N_CONTROL,
    Auth::PERMISSION_MY2N_SCHEDULE,
    Auth::PERMISSION_MY2N_ROLLBACK,
];
foreach (['governanta', 'tecnico_manutencao', 'empregada_andares'] as $role) {
    foreach ($my2nWritePermissions as $permission) {
        assertTrue(!Auth::defaultRoleHasPermission($role, $permission), $role . ' cannot use ' . $permission);
    }
}
assertTrue(in_array(Auth::PERMISSION_USERS_MANAGE, Auth::LOCKED_ROLE_PERMISSIONS['gerente'], true), 'Gerente user management cannot be removed');
assertTrue(in_array(Auth::PERMISSION_PERMISSIONS_MANAGE, Auth::LOCKED_ROLE_PERMISSIONS['gerente'], true), 'Gerente permission management cannot be removed');
assertTrue(in_array(Auth::PERMISSION_MY2N_CREDENTIALS, Auth::LOCKED_ROLE_PERMISSIONS['gerente'], true), 'Gerente My2N credential management cannot be removed');
$normalized = Auth::normalizePermissions([Auth::PERMISSION_ZKACCESS_CONFIGURE, 'unknown.permission']);
assertTrue(in_array(Auth::PERMISSION_ZKACCESS_CONFIGURE, $normalized, true), 'known permission remains normalized');
assertTrue(in_array(Auth::PERMISSION_ZKACCESS_VIEW, $normalized, true), 'configure permission implies module view');
assertTrue(!in_array('unknown.permission', $normalized, true), 'unknown permissions are removed');
$my2nCredentialPermissions = Auth::normalizePermissions([Auth::PERMISSION_MY2N_CREDENTIALS]);
assertTrue(in_array(Auth::PERMISSION_MY2N_VIEW, $my2nCredentialPermissions, true), 'My2N credential permission implies module view');
assertTrue(!Auth::defaultRoleHasPermission('unknown', Auth::PERMISSION_ROOM_CHECK_VIEW), 'unknown roles have no permissions');
$shortPasswordRejected = false;
try {
    Auth::validatePassword('short');
} catch (InvalidArgumentException) {
    $shortPasswordRejected = true;
}
assertTrue($shortPasswordRejected, 'short passwords are rejected');

echo 'All tests passed.' . PHP_EOL;
