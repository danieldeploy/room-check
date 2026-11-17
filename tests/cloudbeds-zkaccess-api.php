<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/ZKAccess/CloudbedsAccessApi.php';

function apiCheck(bool $ok, string $message): void
{
    if (!$ok) throw new RuntimeException($message);
}

function apiThrows(callable $callback, string $message): void
{
    try { $callback(); } catch (Throwable) { return; }
    throw new RuntimeException($message);
}

$calls = [];
$transport = function (string $method, string $url, array $params, array $headers) use (&$calls): array {
    $calls[] = compact('method', 'url', 'params', 'headers');
    if (str_ends_with($url, '/getReservation')) {
        return ['status' => 200, 'body' => json_encode(['success' => true, 'data' => [
            'reservationID' => 'R-1', 'status' => 'confirmed',
        ]], JSON_THROW_ON_ERROR)];
    }
    if (str_ends_with($url, '/getReservationNotes')) {
        return ['status' => 200, 'body' => json_encode(['success' => true, 'data' => []], JSON_THROW_ON_ERROR)];
    }
    return ['status' => 200, 'body' => json_encode(['success' => true, 'message' => 'created'], JSON_THROW_ON_ERROR)];
};

$api = new CloudbedsAccessApi('fixture-key-not-real', $transport);
$reservation = $api->getReservation('property-1', 'R-1');
apiCheck(($reservation['data']['reservationID'] ?? '') === 'R-1', 'Reservation response not returned');
$api->getReservationNotes('property-1', 'R-1');
$api->postReservationNote('property-1', 'R-1', 'Door PIN: 4826 | Valid: 02/11/2026 14:00 - 04/11/2026 11:00');

apiCheck(count($calls) === 3, 'Unexpected Cloudbeds calls');
apiCheck($calls[0]['method'] === 'GET' && str_ends_with($calls[0]['url'], '/getReservation'), 'Wrong getReservation request');
apiCheck($calls[2]['method'] === 'POST' && str_ends_with($calls[2]['url'], '/postReservationNote'), 'Wrong note endpoint');
apiCheck(($calls[2]['params']['reservationNote'] ?? '') === 'Door PIN: 4826 | Valid: 02/11/2026 14:00 - 04/11/2026 11:00',
    'PIN note changed');
apiCheck(($calls[0]['headers']['x-api-key'] ?? '') === 'fixture-key-not-real', 'API key not sent as header');

apiThrows(fn() => new CloudbedsAccessApi(''), 'Empty API key accepted');
apiThrows(fn() => $api->getReservation('', 'R-1'), 'Empty property accepted');
apiThrows(fn() => $api->postReservationNote('property-1', 'R-1', ''), 'Empty note accepted');

$bad = new CloudbedsAccessApi('fixture-key', fn() => ['status' => 500, 'body' => '{"secret":"must-not-be-exposed"}']);
try {
    $bad->getReservation('property-1', 'R-1');
    throw new RuntimeException('HTTP error accepted');
} catch (RuntimeException $e) {
    apiCheck($e->getMessage() === 'cloudbeds_api_error', 'Raw Cloudbeds response leaked into error');
}
$limited = new CloudbedsAccessApi('fixture-key', fn() => ['status' => 429, 'body' => '{}']);
try {
    $limited->getReservation('property-1', 'R-1');
    throw new RuntimeException('Rate limit accepted');
} catch (RuntimeException $e) {
    apiCheck($e->getMessage() === 'cloudbeds_rate_limited', 'Rate limit not classified safely');
}

echo "Cloudbeds access API reservation and note calls passed.\n";
