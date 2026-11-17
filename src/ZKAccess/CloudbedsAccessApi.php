<?php
declare(strict_types=1);

/**
 * Minimal Cloudbeds v1.3 client for access automation.
 * The API key is accepted only in memory and is never included in exceptions.
 */
final class CloudbedsAccessApi
{
    private const BASE = 'https://api.cloudbeds.com/api/v1.3/';

    /** @var null|Closure(string,string,array,array): array{status:int,body:string} */
    private ?Closure $transport;

    public function __construct(private readonly string $apiKey, ?callable $transport = null)
    {
        if ($apiKey === '' || strlen($apiKey) > 512 || str_contains($apiKey, "\0")) {
            throw new InvalidArgumentException('invalid_cloudbeds_api_key');
        }
        $this->transport = $transport === null ? null : Closure::fromCallable($transport);
    }

    public function getReservation(string $propertyId, string $reservationId): array
    {
        return $this->request('GET', 'getReservation', [
            'propertyID' => $this->id($propertyId),
            'reservationID' => $this->id($reservationId),
        ]);
    }

    public function getReservationNotes(string $propertyId, string $reservationId): array
    {
        return $this->request('GET', 'getReservationNotes', [
            'propertyID' => $this->id($propertyId),
            'reservationID' => $this->id($reservationId),
        ]);
    }

    public function postReservationNote(string $propertyId, string $reservationId, string $note): array
    {
        $note = trim($note);
        if ($note === '' || mb_strlen($note, 'UTF-8') > 1000 || str_contains($note, "\0")) {
            throw new InvalidArgumentException('invalid_reservation_note');
        }
        return $this->request('POST', 'postReservationNote', [
            'propertyID' => $this->id($propertyId),
            'reservationID' => $this->id($reservationId),
            'reservationNote' => $note,
        ]);
    }

    private function request(string $method, string $endpoint, array $parameters): array
    {
        if (!in_array($endpoint, ['getReservation','getReservationNotes','postReservationNote'], true)) {
            throw new RuntimeException('cloudbeds_api_error');
        }

        if ($this->transport !== null) {
            $response = ($this->transport)($method, self::BASE . $endpoint, $parameters, [
                'accept' => 'application/json',
                'x-api-key' => $this->apiKey,
            ]);
        } else {
            $response = $this->curl($method, self::BASE . $endpoint, $parameters);
        }

        $status = $response['status'] ?? null;
        $body = $response['body'] ?? null;
        if (!is_int($status) || !is_string($body) || strlen($body) > 2097152) {
            throw new RuntimeException('cloudbeds_api_error');
        }
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException($status === 429 ? 'cloudbeds_rate_limited' : 'cloudbeds_api_error');
        }

        try {
            $decoded = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new RuntimeException('cloudbeds_api_error');
        }
        if (!is_array($decoded)) {
            throw new RuntimeException('cloudbeds_api_error');
        }
        if (array_key_exists('success', $decoded) && $decoded['success'] === false) {
            throw new RuntimeException('cloudbeds_api_error');
        }
        return $decoded;
    }

    private function curl(string $method, string $url, array $parameters): array
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('cloudbeds_api_unavailable');
        }

        if ($method === 'GET') {
            $url .= '?' . http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
            $body = null;
        } else {
            $body = http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
        }

        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('cloudbeds_api_unavailable');
        }
        try {
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_MAXREDIRS => 0,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_HTTPHEADER => array_filter([
                    'Accept: application/json',
                    'x-api-key: ' . $this->apiKey,
                    $method === 'POST' ? 'Content-Type: application/x-www-form-urlencoded' : null,
                ]),
                CURLOPT_CUSTOMREQUEST => $method,
            ]);
            if ($body !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            }
            $responseBody = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            if (!is_string($responseBody)) {
                throw new RuntimeException('cloudbeds_api_unavailable');
            }
            return ['status' => $status, 'body' => $responseBody];
        } finally {
            curl_close($ch);
        }
    }

    private function id(string $value): string
    {
        $value = trim($value);
        if ($value === '' || strlen($value) > 64 || str_contains($value, "\0")) {
            throw new InvalidArgumentException('invalid_cloudbeds_id');
        }
        return $value;
    }
}
