<?php
declare(strict_types=1);

final class CloudbedsAccessWebhook
{
    public const SUPPORTED_EVENTS = [
        'reservation/created',
        'reservation/status_changed',
        'reservation/dates_changed',
        'reservation/accommodation_changed',
        'reservation/accommodation_removed',
        'reservation/deleted',
    ];

    public static function ingest(PDO $pdo, array $payload, array $properties): array
    {
        if (array_is_list($payload)) {
            throw new InvalidArgumentException('invalid_webhook_payload');
        }

        $event = self::stringValue($payload, ['event']);
        $propertyId = self::stringValue($payload, ['propertyID', 'propertyId', 'propertyID_str', 'propertyId_str']);
        $reservationId = self::stringValue($payload, ['reservationID', 'reservationId']);
        $timestamp = self::timestamp($payload['timestamp'] ?? null);

        if ($event === '' || $propertyId === '' || $reservationId === '' || $timestamp === null) {
            throw new InvalidArgumentException('invalid_webhook_payload');
        }

        $supported = in_array($event, self::SUPPORTED_EVENTS, true);
        $propertyKey = isset($properties[$propertyId]) && is_string($properties[$propertyId])
            ? trim($properties[$propertyId])
            : null;
        $actionable = $supported && $propertyKey === 'city_center';

        $normalized = [
            'event' => $event,
            'version' => self::optionalString($payload, ['version']),
            'property_id' => $propertyId,
            'reservation_id' => $reservationId,
            'sub_reservation_id' => self::optionalString($payload, ['subReservationID', 'subReservationId']),
            'room_id' => self::optionalString($payload, ['roomID', 'roomId']),
            'previous_room_id' => self::optionalString($payload, ['roomIDPrev', 'roomIdPrev']),
            'timestamp' => $timestamp,
        ];

        // Canonical JSON makes a timeout retry idempotent even if JSON field order changes.
        $canonicalPayload = self::canonicalJson($payload);
        $eventKey = hash('sha256', $canonicalPayload);
        $existing = $pdo->prepare('SELECT id, status FROM zk_access_webhook_events WHERE event_key = :event_key LIMIT 1');
        $existing->execute(['event_key' => $eventKey]);
        $row = $existing->fetch(PDO::FETCH_ASSOC);
        if (is_array($row)) {
            return ['accepted' => true, 'duplicate' => true, 'ignored' => $row['status'] === 'ignored'];
        }

        $statement = $pdo->prepare(
            'INSERT INTO zk_access_webhook_events
                (event_key,event_name,event_version,property_id,property_key,reservation_id,sub_reservation_id,
                 room_id,previous_room_id,event_timestamp,payload_json,status)
             VALUES
                (:event_key,:event_name,:event_version,:property_id,:property_key,:reservation_id,:sub_reservation_id,
                 :room_id,:previous_room_id,:event_timestamp,:payload_json,:status)'
        );

        try {
            $statement->execute([
                'event_key' => $eventKey,
                'event_name' => $normalized['event'],
                'event_version' => $normalized['version'],
                'property_id' => $normalized['property_id'],
                'property_key' => $propertyKey,
                'reservation_id' => $normalized['reservation_id'],
                'sub_reservation_id' => $normalized['sub_reservation_id'],
                'room_id' => $normalized['room_id'],
                'previous_room_id' => $normalized['previous_room_id'],
                'event_timestamp' => $normalized['timestamp'],
                'payload_json' => $canonicalPayload,
                'status' => $actionable ? 'pending' : 'ignored',
            ]);
        } catch (PDOException $e) {
            if (($e->errorInfo[0] ?? $e->getCode()) === '23000') {
                return ['accepted' => true, 'duplicate' => true, 'ignored' => !$actionable];
            }
            throw $e;
        }

        return ['accepted' => true, 'duplicate' => false, 'ignored' => !$actionable];
    }

    private static function stringValue(array $payload, array $keys): string
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $payload) || $payload[$key] === null) {
                continue;
            }
            if (!is_string($payload[$key]) && !is_int($payload[$key])) {
                continue;
            }
            $value = trim((string) $payload[$key]);
            if ($value !== '' && strlen($value) <= 64 && !str_contains($value, "\0")) {
                return $value;
            }
        }
        return '';
    }

    private static function optionalString(array $payload, array $keys): ?string
    {
        $value = self::stringValue($payload, $keys);
        return $value === '' ? null : $value;
    }

    private static function timestamp(mixed $value): ?string
    {
        if ((!is_int($value) && !is_float($value) && !is_string($value)) || !is_numeric($value)) {
            return null;
        }
        $number = (float) $value;
        if (!is_finite($number) || $number <= 0) {
            return null;
        }
        return number_format($number, 6, '.', '');
    }

    private static function canonicalJson(array $payload): string
    {
        $value = self::canonicalize($payload);
        $json = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (strlen($json) > 65536) {
            throw new InvalidArgumentException('invalid_webhook_payload');
        }
        return $json;
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map([self::class, 'canonicalize'], $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = self::canonicalize($item);
        }
        return $value;
    }
}
