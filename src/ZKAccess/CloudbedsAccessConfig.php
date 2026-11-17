<?php
declare(strict_types=1);

final class CloudbedsAccessConfig
{
    public static function load(string $path): array
    {
        if ($path === '' || $path[0] !== '/' || str_contains($path, "\0") || is_link($path)) {
            throw new RuntimeException('cloudbeds_access_not_configured');
        }
        $resolved = realpath($path);
        if ($resolved === false || !is_file($resolved) || !is_readable($resolved)
            || preg_match('~/public_html(?:/|$)~', str_replace('\\', '/', $resolved))) {
            throw new RuntimeException('cloudbeds_access_not_configured');
        }
        if ((fileperms($resolved) & 0077) !== 0) {
            throw new RuntimeException('cloudbeds_access_permissions');
        }
        $raw = file_get_contents($resolved);
        if ($raw === false || strlen($raw) < 2 || strlen($raw) > 65536) {
            throw new RuntimeException('cloudbeds_access_not_configured');
        }
        $data = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($data) || array_is_list($data)) {
            throw new RuntimeException('cloudbeds_access_not_configured');
        }

        $enabled = ($data['enabled'] ?? false) === true;
        if (!$enabled) {
            return ['enabled' => false, 'webhook_token' => null, 'api_key' => null, 'properties' => []];
        }

        $token = $data['webhook_token'] ?? null;
        if (!is_string($token) || !preg_match('/\A[a-f0-9]{64}\z/D', $token)) {
            throw new RuntimeException('cloudbeds_access_not_configured');
        }

        $properties = $data['properties'] ?? null;
        if (!is_array($properties) || array_is_list($properties) || $properties === []) {
            throw new RuntimeException('cloudbeds_access_not_configured');
        }
        $allowedKeys = ['city_center', 'welcome'];
        $normalized = [];
        foreach ($properties as $propertyId => $propertyKey) {
            $propertyId = trim((string) $propertyId);
            if ($propertyId === '' || strlen($propertyId) > 64 || !is_string($propertyKey)
                || !in_array($propertyKey, $allowedKeys, true)) {
                throw new RuntimeException('cloudbeds_access_not_configured');
            }
            $normalized[$propertyId] = $propertyKey;
        }

        $apiKey = $data['api_key'] ?? null;
        if ($apiKey !== null && (!is_string($apiKey) || trim($apiKey) === '' || strlen($apiKey) > 512)) {
            throw new RuntimeException('cloudbeds_access_not_configured');
        }

        return [
            'enabled' => true,
            'webhook_token' => $token,
            'api_key' => is_string($apiKey) ? trim($apiKey) : null,
            'properties' => $normalized,
        ];
    }
}
