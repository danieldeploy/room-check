<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/My2N/My2NRedactor.php';
require_once dirname(__DIR__) . '/src/My2N/My2NGateway.php';
require_once dirname(__DIR__) . '/src/My2N/My2NService.php';
require_once dirname(__DIR__) . '/src/My2N/My2NCredentialStore.php';
require_once dirname(__DIR__) . '/src/My2N/My2NClient.php';
require_once dirname(__DIR__) . '/src/Auth/Auth.php';

final class FakeMy2NGateway implements My2NGateway
{
    public array $bells = [
        [
            'bellKey' => '8001:34:56:78',
            'intercomDeviceId' => 8001,
            'bellName' => 'Welcome Bell',
            'groupName' => 'Receção',
            'apartmentId' => 41,
            'apartmentName' => 'Apartment 41',
            'ringingGroupSipNumber' => '0000000001',
            'members' => [9001, 9002],
        ],
        [
            'bellKey' => '8002:35:57:79',
            'intercomDeviceId' => 8002,
            'bellName' => 'Garden Bell',
            'groupName' => 'Portão',
            'apartmentId' => 41,
            'apartmentName' => 'Apartment 41',
            'ringingGroupSipNumber' => '0000000004',
            'members' => [9002],
        ],
    ];
    public int $updates = 0;
    public ?string $lastUpdatedBellKey = null;

    public function listSiteDevices(): array
    {
        return ['results' => [
            [
                'deviceId' => 8001,
                'id' => 8001,
                'name' => 'Welcome Bell',
                'type' => 'IP_INTERCOM',
                'site' => ['id' => 7001],
                'apartment' => ['id' => 41, 'name' => 'Apartment 41'],
                'services' => [
                    'MOBILE_VIDEO' => [
                        'id' => 8999,
                        'status' => 'REGISTERED',
                        'sipNumber' => '0000000099',
                    ],
                ],
            ],
            [
                'deviceId' => 8002,
                'id' => 8002,
                'name' => 'Garden Bell',
                'type' => 'IP_INTERCOM',
                'site' => ['id' => 7001],
                'apartment' => ['id' => 41, 'name' => 'Apartment 41'],
                'services' => [
                    'MOBILE_VIDEO' => [
                        'id' => 8998,
                        'status' => 'REGISTERED',
                        'sipNumber' => '0000000098',
                    ],
                ],
            ],
            [
                'id' => 8101,
                'name' => 'Test Device',
                'site' => ['id' => 7001],
                'apartmentId' => 42,
                'apartment' => ['id' => 42, 'name' => 'Apartment 42'],
                'services' => [
                    'MOBILE_VIDEO' => [
                        'id' => 9001,
                        'status' => 'NOT_REGISTERED',
                        'sipNumber' => '0000000000',
                        'sipPassword' => 'must-never-leak',
                    ],
                    'NOTIFICATION' => [
                        'active' => true,
                        'status' => 'ENABLED',
                        'password' => 'push-secret',
                    ],
                ],
            ],
            [
                'id' => 8102,
                'name' => 'Test Device 2',
                'site' => ['id' => 7001],
                'services' => [
                    'MOBILE_VIDEO' => [
                        'id' => 9002,
                        'status' => 'REGISTERED',
                        'sipNumber' => '0000000002',
                    ],
                ],
            ],
            [
                'id' => 8103,
                'name' => 'Test Device 3',
                'site' => ['id' => 7001],
                'services' => [
                    'MOBILE_VIDEO' => [
                        'id' => 9003,
                        'status' => 'NEVER_REGISTERED',
                        'sipNumber' => '0000000003',
                    ],
                    'CREDENTIALS' => [
                        'active' => true,
                        'status' => 'ENABLED',
                    ],
                ],
            ],
        ]];
    }

    public function listBellGroups(): array
    {
        return $this->bells;
    }

    public function updateBellMembers(string $bellKey, array $memberIds): array
    {
        foreach ($this->bells as &$bell) {
            if ($bell['bellKey'] === $bellKey) {
                $bell['members'] = array_values(array_map('intval', $memberIds));
                $this->lastUpdatedBellKey = $bellKey;
                $this->updates++;
                return ['members' => $bell['members']];
            }
        }
        unset($bell);
        throw new InvalidArgumentException('Unknown bell key.');
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
    'timezone' => 'Europe/Lisbon',
    'allow_writes' => false,
];

$gateway = new FakeMy2NGateway();
$service = new My2NService($gateway, $config);
$status = $service->status();
$encoded = json_encode($status, JSON_THROW_ON_ERROR);

assertTrue($status['dryRun'] === true, 'read-only status reports dry-run');
assertTrue(count($status['bells']) === 2, 'all bell destination groups are normalized');
assertTrue(count($status['mobiles']) === 3, 'mobile configurations are normalized');
assertTrue($status['mobiles'][0]['memberId'] === 9001, 'member ID is preserved');
assertTrue($status['bells'][0]['unresolvedMemberIds'] === [], 'all bell members are associated with a mobile');
assertTrue($status['mobiles'][0]['status'] === 'NOT_REGISTERED', 'registration status is preserved');
assertTrue($status['mobiles'][0]['pushConfigured'] === true, 'configured notification service is detected');
assertTrue(
    $status['mobiles'][0]['availability'] === 'NOT_REGISTERED',
    'push configuration does not hide an unregistered SIP state'
);
assertTrue($status['mobiles'][1]['availability'] === 'ONLINE', 'registered device is online');
assertTrue(
    $status['mobiles'][2]['availability'] === 'NEVER_REGISTERED',
    'push configuration does not hide a device that never registered'
);
assertTrue($status['mobiles'][0]['apartmentId'] === 42, 'mobile apartment ID is read from the API payload');
assertTrue($status['mobiles'][0]['apartmentName'] === 'Apartment 42', 'mobile apartment name is read from the API payload');
assertTrue($status['bells'][0]['apartmentId'] === 41, 'bell apartment ID is dynamic');
assertTrue($status['bells'][1]['apartmentId'] === 41, 'several bells can belong to the same apartment');
assertTrue($status['bells'][0]['currentMemberIds'] === [9002], 'bell assignments remain independent');
assertTrue($status['bells'][1]['currentMemberIds'] === [9001, 9002], 'each bell keeps its own mobile list');
assertTrue(!str_contains(strtolower($encoded), 'sippassword'), 'sipPassword key is removed');
assertTrue(!str_contains($encoded, 'must-never-leak'), 'sipPassword value is removed');

$welcomeBellKey = '8001:34:56:78';
$update = $service->replaceBellMembers($welcomeBellKey, [9003], [9001, 9002]);
assertTrue($update['changed'] === true, 'destination group change is reported');
assertTrue($gateway->updates === 1, 'destination group is written once');
assertTrue($gateway->lastUpdatedBellKey === $welcomeBellKey, 'only the selected bell is written');
$updatedWelcomeBell = array_values(array_filter(
    $update['status']['bells'],
    static fn(array $bell): bool => $bell['bellKey'] === $welcomeBellKey
))[0];
assertTrue($updatedWelcomeBell['currentMemberIds'] === [9003], 'bell destination change is confirmed');
$gardenBell = array_values(array_filter(
    $update['status']['bells'],
    static fn(array $bell): bool => $bell['bellKey'] === '8002:35:57:79'
))[0];
assertTrue($gardenBell['currentMemberIds'] === [9002], 'changing one bell does not change another bell');
$noChange = $service->replaceBellMembers($welcomeBellKey, [9003], [9003]);
assertTrue($noChange['changed'] === false, 'unchanged destination group skips the write');
assertTrue($gateway->updates === 1, 'no-op does not write to My2N');
$emptySelectionRejected = false;
try {
    $service->replaceBellMembers($welcomeBellKey, [], [9003]);
} catch (InvalidArgumentException) {
    $emptySelectionRejected = true;
}
assertTrue($emptySelectionRejected, 'an empty destination group is rejected');

$gateway->bells[0]['members'] = [9001, 9999];
$statusWithUnknownMember = $service->status();
$welcomeWithUnknownMember = array_values(array_filter(
    $statusWithUnknownMember['bells'],
    static fn(array $bell): bool => $bell['bellKey'] === $welcomeBellKey
))[0];
assertTrue(
    $welcomeWithUnknownMember['unresolvedMemberIds'] === [9999],
    'unknown bell members do not prevent read-only status'
);
$unknownMemberWriteRejected = false;
try {
    $service->replaceBellMembers($welcomeBellKey, [9001], [9001, 9999]);
} catch (RuntimeException) {
    $unknownMemberWriteRejected = true;
}
assertTrue($unknownMemberWriteRejected, 'writes remain blocked while a group member is unresolved');

$redacted = My2NRedactor::sanitize([
    'safe' => 'ok',
    'nested' => ['session_token' => 'secret-token', 'sipPassword' => 'secret-sip'],
]);
assertTrue(($redacted['safe'] ?? null) === 'ok', 'non-sensitive fields remain');
assertTrue(!isset($redacted['nested']['session_token']), 'session token is removed');
assertTrue(!isset($redacted['nested']['sipPassword']), 'nested sipPassword is removed');

$authClient = new My2NClient([
    'auth_url' => 'https://example.invalid',
    'base_url' => 'https://example.invalid',
]);
$flowIdMethod = new ReflectionMethod(My2NClient::class, 'flowId');
$flowIdMethod->setAccessible(true);
assertTrue(
    $flowIdMethod->invoke($authClient, ['id' => 'flow-123']) === 'flow-123',
    'Auth v2 top-level id is accepted as the login flow identifier'
);
$decodeResponseMethod = new ReflectionMethod(My2NClient::class, 'decodeResponse');
$decodeResponseMethod->setAccessible(true);
$rawLoginResponse = $decodeResponseMethod->invoke(
    $authClient,
    '{"session_token":"token-for-test"}',
    false
);
assertTrue(
    ($rawLoginResponse['session_token'] ?? null) === 'token-for-test',
    'Auth v2 session token remains available for internal extraction'
);
$sanitizedLoginResponse = $decodeResponseMethod->invoke(
    $authClient,
    '{"session_token":"token-for-test"}',
    true
);
assertTrue(
    !isset($sanitizedLoginResponse['session_token']),
    'session token is removed from sanitized provider responses'
);

$featureIdMethod = new ReflectionMethod(My2NClient::class, 'contactListFeatureId');
$featureIdMethod->setAccessible(true);
assertTrue(
    $featureIdMethod->invoke($authClient, [
        'features' => [
            ['id' => 12, 'type' => 'BUTTON_CONFIGURATION'],
            ['id' => 34, 'feature' => 'CONTACT_LIST'],
        ],
    ]) === 34,
    'CONTACT_LIST feature is discovered from the current API payload'
);
assertTrue(
    $featureIdMethod->invoke($authClient, [
        'data' => [
            'features' => [
                'CONTACT_LIST' => ['id' => 35],
            ],
        ],
    ]) === 35,
    'CONTACT_LIST feature is discovered from nested and keyed API payloads'
);
$destinationGroupsMethod = new ReflectionMethod(My2NClient::class, 'destinationGroupsFromContactList');
$destinationGroupsMethod->setAccessible(true);
$destinationGroups = $destinationGroupsMethod->invoke($authClient, [
    'contacts' => [[
        'id' => 56,
        'name' => 'Receção',
        'items' => [[
            'id' => 78,
            'type' => 'RINGING_GROUP',
            'sipNumber' => '3507254897',
            'members' => [9001, 9002],
        ]],
    ]],
], 34, [
    'id' => 8001,
    'name' => 'Welcome Bell',
    'type' => 'IP_INTERCOM',
    'apartment' => ['id' => 41, 'name' => 'Apartment 41'],
], 8001);
$destinationGroup = $destinationGroups[0];
assertTrue(count($destinationGroups) === 1, 'all bell destination groups are discovered');
assertTrue($destinationGroup['bellKey'] === '8001:34:56:78', 'bell key is stable and based on My2N IDs');
assertTrue($destinationGroup['featureId'] === 34, 'destination group keeps the discovered feature ID');
assertTrue($destinationGroup['contactId'] === 56, 'destination group contact is discovered');
assertTrue($destinationGroup['itemId'] === 78, 'destination group item is discovered');
assertTrue($destinationGroup['members'] === [9001, 9002], 'destination members are read automatically');
assertTrue($destinationGroup['apartmentId'] === 41, 'bell apartment is read automatically');

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
