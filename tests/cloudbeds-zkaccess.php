<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/ZKAccess/CityCenterAccessMap.php';
require_once dirname(__DIR__) . '/src/ZKAccess/CloudbedsAccessWebhook.php';
require_once dirname(__DIR__) . '/src/ZKAccess/CloudbedsAccessConfig.php';

function zkCheck(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException($message);
    }
}

function zkThrows(callable $callback, string $message): void
{
    try {
        $callback();
    } catch (Throwable) {
        return;
    }
    throw new RuntimeException($message);
}

function zkPdo(): PDO
{
    $pdo = new PDO('sqlite::memory:', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec(
        "CREATE TABLE zk_access_webhook_events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            event_key TEXT NOT NULL UNIQUE,
            event_name TEXT NOT NULL,
            event_version TEXT NULL,
            property_id TEXT NOT NULL,
            property_key TEXT NULL,
            reservation_id TEXT NOT NULL,
            sub_reservation_id TEXT NULL,
            room_id TEXT NULL,
            previous_room_id TEXT NULL,
            event_timestamp NUMERIC NOT NULL,
            payload_json TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT 'pending',
            attempt_count INTEGER NOT NULL DEFAULT 0,
            available_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            locked_at TEXT NULL,
            processed_at TEXT NULL,
            last_error TEXT NULL,
            received_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )"
    );
    return $pdo;
}

$expected = [
    1 => ['level' => 20, 'controller' => 'Board 2 CC', 'door' => 3, 'mask' => 4],
    2 => ['level' => 21, 'controller' => 'Board 2 CC', 'door' => 4, 'mask' => 8],
    3 => ['level' => 22, 'controller' => 'Board 3 CC', 'door' => 1, 'mask' => 1],
    4 => ['level' => 23, 'controller' => 'Board 3 CC', 'door' => 2, 'mask' => 2],
    5 => ['level' => 24, 'controller' => 'Board 3 CC', 'door' => 3, 'mask' => 4],
    6 => ['level' => 25, 'controller' => 'Board 3 CC', 'door' => 4, 'mask' => 8],
];
foreach ($expected as $room => $want) {
    $map = CityCenterAccessMap::forRoom($room);
    zkCheck($map['access_level_ids'] === [$want['level'], 26, 27, 28], 'City Center access levels changed');
    zkCheck($map['controllers'][0]['alias'] === $want['controller'], 'Room controller changed');
    zkCheck($map['controllers'][0]['room_door'] === $want['door'], 'Room door changed');
    zkCheck($map['controllers'][0]['door_mask'] === $want['mask'], 'Room mask changed');
    zkCheck($map['controllers'][1]['alias'] === 'Board 4 CC' && $map['controllers'][1]['door_mask'] === 7,
        'Common Kitchen/Reception/Street mask changed');
}
zkCheck(CityCenterAccessMap::rooms() === [1,2,3,4,5,6], 'Supported City Center rooms changed');
zkThrows(fn() => CityCenterAccessMap::forRoom(7), 'Unsupported room accepted');

$pdo = zkPdo();
$properties = ['7541' => 'city_center', 'welcome-id' => 'welcome'];
$created = [
    'event' => 'reservation/created',
    'version' => '1.0',
    'propertyID' => '7541',
    'reservationID' => 'R-100',
    'timestamp' => 1793224800.123456,
    'startDate' => '2026-11-02',
    'endDate' => '2026-11-04',
];
$result = CloudbedsAccessWebhook::ingest($pdo, $created, $properties);
zkCheck($result === ['accepted' => true, 'duplicate' => false, 'ignored' => false], 'Created event not queued');
$row = $pdo->query("SELECT event_name,property_key,reservation_id,status FROM zk_access_webhook_events")->fetch();
zkCheck($row['event_name'] === 'reservation/created' && $row['property_key'] === 'city_center'
    && $row['reservation_id'] === 'R-100' && $row['status'] === 'pending', 'Queued event fields wrong');

$reordered = [
    'endDate' => '2026-11-04',
    'reservationID' => 'R-100',
    'propertyID' => '7541',
    'timestamp' => 1793224800.123456,
    'event' => 'reservation/created',
    'startDate' => '2026-11-02',
    'version' => '1.0',
];
$duplicate = CloudbedsAccessWebhook::ingest($pdo, $reordered, $properties);
zkCheck($duplicate['duplicate'] === true && (int)$pdo->query('SELECT COUNT(*) FROM zk_access_webhook_events')->fetchColumn() === 1,
    'Canonical duplicate protection failed');

$changed = [
    'event' => 'reservation/accommodation_changed',
    'propertyId' => '7541',
    'reservationId' => 'R-101',
    'subReservationId' => 'S-101',
    'roomId' => 'ROOM-2',
    'roomIdPrev' => 'ROOM-1',
    'timestamp' => '1793224900.5',
];
$result = CloudbedsAccessWebhook::ingest($pdo, $changed, $properties);
zkCheck($result['ignored'] === false, 'Accommodation change unexpectedly ignored');
$row = $pdo->query("SELECT room_id,previous_room_id,sub_reservation_id FROM zk_access_webhook_events WHERE reservation_id='R-101'")->fetch();
zkCheck($row['room_id'] === 'ROOM-2' && $row['previous_room_id'] === 'ROOM-1' && $row['sub_reservation_id'] === 'S-101',
    'Accommodation identifiers were not normalized');

$removed = [
    'event' => 'reservation/accommodation_removed',
    'propertyId' => '7541',
    'reservationId' => 'R-102',
    'subReservationId' => 'S-102',
    'roomId' => 'ROOM-3',
    'timestamp' => 1793224950,
];
zkCheck(CloudbedsAccessWebhook::ingest($pdo, $removed, $properties)['ignored'] === false,
    'Removed accommodation should be actionable');

$welcome = $created;
$welcome['reservationID'] = 'R-200';
$welcome['propertyID'] = 'welcome-id';
$welcome['timestamp'] = 1793225000;
zkCheck(CloudbedsAccessWebhook::ingest($pdo, $welcome, $properties)['ignored'] === true,
    'Non-City-Center property should be ignored');

$unsupported = $created;
$unsupported['reservationID'] = 'R-300';
$unsupported['event'] = 'guest/created';
$unsupported['timestamp'] = 1793225100;
zkCheck(CloudbedsAccessWebhook::ingest($pdo, $unsupported, $properties)['ignored'] === true,
    'Unsupported event should be ignored');

zkThrows(fn() => CloudbedsAccessWebhook::ingest($pdo, ['event' => 'reservation/created'], $properties),
    'Incomplete webhook accepted');

$tmp = tempnam(sys_get_temp_dir(), 'cbzk-');
if ($tmp === false) throw new RuntimeException('temp file unavailable');
try {
    file_put_contents($tmp, json_encode([
        'enabled' => true,
        'webhook_token' => str_repeat('a', 64),
        'api_key' => 'fixture-api-key',
        'properties' => ['7541' => 'city_center'],
    ], JSON_THROW_ON_ERROR));
    chmod($tmp, 0600);
    $config = CloudbedsAccessConfig::load($tmp);
    zkCheck($config['enabled'] === true && $config['properties']['7541'] === 'city_center',
        'Private configuration not loaded');

    file_put_contents($tmp, json_encode(['enabled' => false], JSON_THROW_ON_ERROR));
    chmod($tmp, 0600);
    $disabled = CloudbedsAccessConfig::load($tmp);
    zkCheck($disabled['enabled'] === false && $disabled['api_key'] === null, 'Disabled config exposed values');

    file_put_contents($tmp, json_encode([
        'enabled' => true,
        'webhook_token' => 'weak',
        'properties' => ['7541' => 'city_center'],
    ], JSON_THROW_ON_ERROR));
    chmod($tmp, 0600);
    zkThrows(fn() => CloudbedsAccessConfig::load($tmp), 'Weak webhook token accepted');
} finally {
    @unlink($tmp);
}

echo "Cloudbeds webhook idempotency, private config and verified City Center ZKAccess mapping passed.\n";
