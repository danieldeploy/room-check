<?php
declare(strict_types=1);

require_once __DIR__ . '/My2NGateway.php';
require_once __DIR__ . '/My2NRedactor.php';
require_once __DIR__ . '/My2NCredentialStore.php';

final class My2NClient implements My2NGateway
{
    private ?string $sessionToken = null;
    private ?array $siteDevices = null;
    private ?array $bellGroups = null;

    public function __construct(
        private readonly array $config,
        private readonly ?array $credentialOverride = null
    )
    {
    }

    public function authenticate(): void
    {
        $this->sessionToken();
    }

    public function listSiteDevices(): array
    {
        if ($this->siteDevices !== null) {
            return $this->siteDevices;
        }
        $path = sprintf(
            '/companies/%d/sites/%d/devices?limit=1000',
            $this->configuredId('company_id'),
            $this->configuredId('site_id')
        );
        $this->siteDevices = $this->partnerRequest('GET', $path);
        return $this->siteDevices;
    }

    public function listBellGroups(): array
    {
        if ($this->bellGroups !== null) {
            return $this->bellGroups;
        }

        $groups = [];
        foreach ($this->candidateRows($this->listSiteDevices(), ['results', 'devices', 'items', 'data']) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $intercomCandidate = $this->intercomCandidate($row);
            if ($intercomCandidate === false) {
                continue;
            }
            $deviceId = $this->deviceId($row);
            if ($deviceId === null) {
                continue;
            }
            try {
                $features = $this->partnerRequest(
                    'GET',
                    sprintf(
                        '/companies/%d/sites/%d/devices/%d/features?limit=1000',
                        $this->configuredId('company_id'),
                        $this->configuredId('site_id'),
                        $deviceId
                    )
                );
                $featureId = $this->contactListFeatureId($features);
                $contactList = $this->partnerRequest(
                    'GET',
                    sprintf(
                        '/companies/%d/sites/%d/devices/%d/features/CONTACT_LIST/%d',
                        $this->configuredId('company_id'),
                        $this->configuredId('site_id'),
                        $deviceId,
                        $featureId
                    )
                );
            } catch (RuntimeException $exception) {
                if ($intercomCandidate === true) {
                    throw $exception;
                }
                continue;
            }
            foreach ($this->destinationGroupsFromContactList($contactList, $featureId, $row, $deviceId) as $group) {
                $groups[] = $group;
            }
        }

        if ($groups === []) {
            throw new RuntimeException('A API My2N não devolveu nenhuma campainha com destination group neste site.', 503);
        }
        usort($groups, static fn(array $a, array $b): int => strcasecmp($a['bellName'], $b['bellName']));
        $this->bellGroups = $groups;
        return $this->bellGroups;
    }

    public function updateBellMembers(string $bellKey, array $memberIds): array
    {
        if (($this->config['allow_writes'] ?? false) !== true) {
            throw new RuntimeException('My2N está em modo dry-run; escritas estão desativadas.', 409);
        }
        if ($memberIds === []) {
            throw new InvalidArgumentException('Um grupo vazio exige confirmação explícita separada.');
        }
        foreach ($memberIds as $memberId) {
            if (!is_int($memberId) && !ctype_digit((string) $memberId)) {
                throw new InvalidArgumentException('Member ID inválido.');
            }
        }
        $group = $this->bellGroup($bellKey);
        $response = $this->partnerRequest(
            'PUT',
            $this->membersPath($group),
            array_values(array_map('intval', $memberIds))
        );
        $this->bellGroups = null;
        return $response;
    }

    private function partnerRequest(string $method, string $path, ?array $body = null): array
    {
        $headers = ['Authorization: Bearer ' . $this->sessionToken()];
        return $this->request($method, rtrim($this->config['base_url'], '/') . $path, $body, $headers);
    }

    private function sessionToken(): string
    {
        if ($this->sessionToken !== null) {
            return $this->sessionToken;
        }

        $credentials = $this->credentials();
        $flowResponse = $this->request('GET', rtrim($this->config['auth_url'], '/') . '/api');
        $flowId = $this->flowId($flowResponse);
        if ($flowId === null || $flowId === '') {
            throw new RuntimeException('A autenticação My2N não devolveu flow_id.', 502);
        }

        $loginResponse = $this->request(
            'POST',
            rtrim($this->config['auth_url'], '/') . '?flow=' . rawurlencode((string) $flowId),
            [
                'identifier' => $credentials['identifier'],
                'password' => $credentials['password'],
                'method' => 'password',
            ],
            [],
            false
        );
        $token = $this->findScalar($loginResponse, ['session_token', 'sessionToken', 'token']);
        if ($token === null || $token === '') {
            throw new RuntimeException('A autenticação My2N não devolveu session_token.', 502);
        }

        $this->sessionToken = (string) $token;
        return $this->sessionToken;
    }

    private function credentials(): array
    {
        if ($this->credentialOverride !== null) {
            $identifier = trim((string) ($this->credentialOverride['identifier'] ?? ''));
            $password = (string) ($this->credentialOverride['password'] ?? '');
            if ($identifier === '' || $password === '') {
                throw new RuntimeException('Credenciais My2N incompletas.', 503);
            }
            return ['identifier' => $identifier, 'password' => $password];
        }

        $identifier = getenv('MY2N_IDENTIFIER') ?: '';
        $password = getenv('MY2N_PASSWORD') ?: '';
        $file = (string) ($this->config['secrets_file'] ?? '');

        if ($file !== '') {
            try {
                $stored = (new My2NCredentialStore($file))->read();
                $identifier = $stored['identifier'];
                $password = $stored['password'];
            } catch (RuntimeException $exception) {
                if ($identifier === '' || $password === '') {
                    throw $exception;
                }
            }
        }

        if ($identifier === '' || $password === '') {
            throw new RuntimeException('Credenciais My2N não configuradas no servidor.', 503);
        }

        return ['identifier' => $identifier, 'password' => $password];
    }

    private function request(
        string $method,
        string $url,
        ?array $body = null,
        array $headers = [],
        bool $sanitizeResponse = true
    ): array
    {
        if (!extension_loaded('curl')) {
            throw new RuntimeException('A extensão PHP cURL é necessária.', 503);
        }

        $handle = curl_init($url);
        $requestHeaders = array_merge(['Accept: application/json'], $headers);
        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => $requestHeaders,
        ]);
        if ($body !== null) {
            $requestHeaders[] = 'Content-Type: application/json';
            curl_setopt($handle, CURLOPT_HTTPHEADER, $requestHeaders);
            curl_setopt($handle, CURLOPT_POSTFIELDS, json_encode($body, JSON_THROW_ON_ERROR));
        }

        $raw = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);

        if ($raw === false) {
            throw new RuntimeException('Falha de rede My2N: ' . $error, 502);
        }
        $decoded = $this->decodeResponse($raw, $sanitizeResponse);
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException('My2N respondeu com HTTP ' . $status . '.', 502);
        }

        return $decoded;
    }

    private function decodeResponse(string $raw, bool $sanitizeResponse): array
    {
        $decoded = $raw === '' ? [] : json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            return [];
        }

        return $sanitizeResponse ? My2NRedactor::sanitize($decoded) : $decoded;
    }

    private function membersPath(array $group): string
    {
        return sprintf(
            '/companies/%d/sites/%d/devices/%d/features/CONTACT_LIST/%d/contacts/%d/items/RINGING_GROUP/%d/members',
            $this->configuredId('company_id'),
            $this->configuredId('site_id'),
            (int) $group['intercomDeviceId'],
            $group['featureId'],
            $group['contactId'],
            $group['itemId']
        );
    }

    private function bellGroup(string $bellKey): array
    {
        foreach ($this->listBellGroups() as $group) {
            if (hash_equals((string) $group['bellKey'], $bellKey)) {
                return $group;
            }
        }
        throw new InvalidArgumentException('Campainha inválida ou já removida do site.');
    }

    private function contactListFeatureId(array $payload): int
    {
        $featureId = $this->typedFeatureId($payload, 'CONTACT_LIST');
        if ($featureId !== null) {
            return $featureId;
        }

        throw new RuntimeException('A API My2N não devolveu a lista de contactos da campainha.', 503);
    }

    private function typedFeatureId(array $payload, string $expectedType): ?int
    {
        $directType = strtoupper((string) ($this->firstScalar(
            $payload,
            ['type', 'feature', 'featureType', 'feature_type', 'name']
        ) ?? ''));
        if ($directType === $expectedType) {
            $directId = $this->firstInt($payload, ['id', 'featureId', 'feature_id']);
            if ($directId !== null) {
                return $directId;
            }
        }

        foreach ($payload as $key => $value) {
            if (is_string($key) && strtoupper($key) === $expectedType) {
                if (is_int($value) || (is_string($value) && ctype_digit($value))) {
                    return (int) $value;
                }
                if (is_array($value)) {
                    $id = $this->firstIntRecursive($value, ['id', 'featureId', 'feature_id']);
                    if ($id !== null) {
                        return $id;
                    }
                }
            }
        }

        foreach ($this->candidateRows($payload, ['features', 'items', 'data']) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $type = strtoupper((string) ($this->firstScalar(
                $row,
                ['type', 'feature', 'featureType', 'feature_type', 'name']
            ) ?? ''));
            if ($type !== $expectedType) {
                continue;
            }
            $id = $this->firstInt($row, ['id', 'featureId', 'feature_id']);
            if ($id !== null) {
                return $id;
            }
        }

        foreach ($payload as $value) {
            if (is_array($value)) {
                $id = $this->typedFeatureId($value, $expectedType);
                if ($id !== null) {
                    return $id;
                }
            }
        }

        return null;
    }

    private function destinationGroupsFromContactList(
        array $payload,
        int $featureId,
        array $deviceRow,
        int $deviceId
    ): array
    {
        $groups = [];
        $baseName = trim((string) ($this->firstScalar(
            $deviceRow,
            ['name', 'deviceName', 'displayName']
        ) ?? ('Campainha ' . $deviceId)));
        $apartment = isset($deviceRow['apartment']) && is_array($deviceRow['apartment'])
            ? $deviceRow['apartment']
            : [];
        foreach ($this->candidateRows($payload, ['contacts']) as $contact) {
            if (!is_array($contact)) {
                continue;
            }
            $contactId = $this->firstInt($contact, ['id', 'contactId', 'contact_id']);
            if ($contactId === null) {
                continue;
            }
            $contactName = trim((string) ($this->firstScalar(
                $contact,
                ['name', 'displayName', 'label']
            ) ?? ''));
            foreach ($this->candidateRows($contact, ['items']) as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $type = strtoupper((string) ($this->firstScalar($item, ['type', 'itemType', 'item_type']) ?? ''));
                if ($type !== 'RINGING_GROUP') {
                    continue;
                }
                $itemId = $this->firstInt($item, ['id', 'itemId', 'item_id']);
                if ($itemId === null) {
                    continue;
                }
                $sipNumber = trim((string) ($this->firstScalar(
                    $item,
                    ['sipNumber', 'sip_number', 'number']
                ) ?? ''));
                $members = isset($item['members']) && is_array($item['members']) ? $item['members'] : [];
                $itemName = trim((string) ($this->firstScalar(
                    $item,
                    ['name', 'displayName', 'label']
                ) ?? ''));
                $groups[] = [
                    'bellKey' => implode(':', [$deviceId, $featureId, $contactId, $itemId]),
                    'intercomDeviceId' => $deviceId,
                    'bellName' => $baseName,
                    'groupName' => $contactName !== '' ? $contactName : $itemName,
                    'apartmentId' => $this->firstInt($deviceRow, ['apartmentId', 'apartment_id'])
                        ?? $this->firstInt($apartment, ['id', 'apartmentId', 'apartment_id']),
                    'apartmentName' => $this->firstScalar($deviceRow, ['apartmentName', 'apartment_name'])
                        ?? $this->firstScalar($apartment, ['name', 'displayName']),
                    'featureId' => $featureId,
                    'contactId' => $contactId,
                    'itemId' => $itemId,
                    'ringingGroupSipNumber' => $sipNumber,
                    'members' => $members,
                ];
            }
        }

        if (count($groups) > 1) {
            foreach ($groups as &$group) {
                $groupName = trim((string) ($group['groupName'] ?? ''));
                if ($groupName !== '' && strcasecmp($groupName, $baseName) !== 0) {
                    $group['bellName'] = $baseName . ' — ' . $groupName;
                } else {
                    $group['bellName'] = $baseName . ' — ' . $group['itemId'];
                }
            }
            unset($group);
        }

        return $groups;
    }

    private function intercomCandidate(array $row): ?bool
    {
        $device = isset($row['device']) && is_array($row['device']) ? $row['device'] : [];
        foreach ([$row, $device] as $candidate) {
            $type = strtoupper((string) ($this->firstScalar(
                $candidate,
                ['type', 'deviceType', 'device_type', 'category', 'productType', 'product_type']
            ) ?? ''));
            if (str_contains($type, 'INTERCOM')) {
                return true;
            }
            if ($type !== '' && (str_contains($type, 'MOBILE') || str_contains($type, 'SMARTPHONE'))) {
                return false;
            }
        }

        $services = isset($row['services']) && is_array($row['services']) ? $row['services'] : [];
        foreach (array_keys($services) as $serviceType) {
            $normalizedType = strtoupper((string) $serviceType);
            if (in_array($normalizedType, ['NOTIFICATION', 'CREDENTIALS'], true)) {
                return false;
            }
        }
        return null;
    }

    private function deviceId(array $row): ?int
    {
        $id = $this->firstInt($row, ['id', 'deviceId', 'device_id']);
        if ($id !== null) {
            return $id;
        }
        $device = isset($row['device']) && is_array($row['device']) ? $row['device'] : [];
        return $this->firstInt($device, ['id', 'deviceId', 'device_id']);
    }

    private function candidateRows(array $payload, array $containerKeys): array
    {
        if (array_is_list($payload)) {
            return $payload;
        }
        foreach ($containerKeys as $key) {
            if (isset($payload[$key]) && is_array($payload[$key])) {
                return $this->candidateRows($payload[$key], $containerKeys);
            }
        }
        foreach ($payload as $value) {
            if (!is_array($value)) {
                continue;
            }
            $rows = $this->candidateRows($value, $containerKeys);
            if ($rows !== []) {
                return $rows;
            }
        }
        return [];
    }

    private function firstIntRecursive(array $payload, array $keys): ?int
    {
        $id = $this->firstInt($payload, $keys);
        if ($id !== null) {
            return $id;
        }
        foreach ($payload as $value) {
            if (is_array($value)) {
                $id = $this->firstIntRecursive($value, $keys);
                if ($id !== null) {
                    return $id;
                }
            }
        }
        return null;
    }

    private function firstInt(array $row, array $keys): ?int
    {
        $value = $this->firstScalar($row, $keys);
        return $value !== null && ctype_digit((string) $value) ? (int) $value : null;
    }

    private function firstScalar(array $row, array $keys): string|int|float|bool|null
    {
        foreach ($keys as $key) {
            if (isset($row[$key]) && is_scalar($row[$key])) {
                return $row[$key];
            }
        }
        return null;
    }

    private function configuredId(string $key): int
    {
        $value = (int) ($this->config[$key] ?? 0);
        if ($value < 1) {
            throw new RuntimeException(
                'A ligação My2N foi configurada, mas ainda falta identificar a empresa e o site.',
                503
            );
        }
        return $value;
    }

    private function findScalar(array $payload, array $keys): string|int|null
    {
        foreach ($keys as $key) {
            if (isset($payload[$key]) && is_scalar($payload[$key])) {
                return $payload[$key];
            }
        }
        foreach ($payload as $value) {
            if (is_array($value)) {
                $found = $this->findScalar($value, $keys);
                if ($found !== null) {
                    return $found;
                }
            }
        }
        return null;
    }

    private function flowId(array $payload): string|int|null
    {
        $direct = $this->findScalar($payload, ['flow_id', 'flowId', 'id']);
        if ($direct !== null) {
            return $direct;
        }
        if (isset($payload['flow']) && is_scalar($payload['flow'])) {
            return $payload['flow'];
        }
        if (isset($payload['flow']['id']) && is_scalar($payload['flow']['id'])) {
            return $payload['flow']['id'];
        }
        return null;
    }
}
