<?php
declare(strict_types=1);

require_once __DIR__ . '/My2NGateway.php';
require_once __DIR__ . '/My2NRedactor.php';
require_once __DIR__ . '/My2NCredentialStore.php';

final class My2NClient implements My2NGateway
{
    private ?string $sessionToken = null;
    private ?array $destinationGroup = null;

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

    public function listMobileConfigurations(): array
    {
        $path = sprintf(
            '/companies/%d/sites/%d/services/MOBILE_VIDEO/configurations?limit=1000',
            $this->configuredId('company_id'),
            $this->configuredId('site_id')
        );
        return $this->partnerRequest('GET', $path);
    }

    public function getCurrentMembers(): array
    {
        return ['members' => $this->destinationGroup()['members']];
    }

    public function updateMembers(array $memberIds): array
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
        $response = $this->partnerRequest(
            'PUT',
            $this->membersPath(),
            array_values(array_map('intval', $memberIds))
        );
        $this->destinationGroup = null;
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

    private function membersPath(): string
    {
        $group = $this->destinationGroup();
        return sprintf(
            '/companies/%d/sites/%d/devices/%d/features/CONTACT_LIST/%d/contacts/%d/items/RINGING_GROUP/%d/members',
            $this->configuredId('company_id'),
            $this->configuredId('site_id'),
            $this->configuredId('intercom_device_id'),
            $group['featureId'],
            $group['contactId'],
            $group['itemId']
        );
    }

    private function destinationGroup(): array
    {
        if ($this->destinationGroup !== null) {
            return $this->destinationGroup;
        }

        $companyId = $this->configuredId('company_id');
        $siteId = $this->configuredId('site_id');
        $deviceId = $this->configuredId('intercom_device_id');
        $featureId = (int) ($this->config['contact_list_feature_id'] ?? 0);

        if ($featureId < 1) {
            $features = $this->partnerRequest(
                'GET',
                sprintf('/companies/%d/sites/%d/devices/%d/features?limit=1000', $companyId, $siteId, $deviceId)
            );
            $featureId = $this->contactListFeatureId($features);
        }

        $contactList = $this->partnerRequest(
            'GET',
            sprintf(
                '/companies/%d/sites/%d/devices/%d/features/CONTACT_LIST/%d',
                $companyId,
                $siteId,
                $deviceId,
                $featureId
            )
        );

        $this->destinationGroup = $this->destinationGroupFromContactList($contactList, $featureId);
        return $this->destinationGroup;
    }

    private function contactListFeatureId(array $payload): int
    {
        foreach ($this->candidateRows($payload, ['features', 'items', 'data']) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $type = strtoupper((string) ($this->firstScalar(
                $row,
                ['type', 'featureType', 'feature_type', 'name']
            ) ?? ''));
            if ($type !== 'CONTACT_LIST') {
                continue;
            }
            $id = $this->firstInt($row, ['id', 'featureId', 'feature_id']);
            if ($id !== null) {
                return $id;
            }
        }

        throw new RuntimeException('A API My2N não devolveu a lista de contactos da campainha.', 503);
    }

    private function destinationGroupFromContactList(array $payload, int $featureId): array
    {
        $configuredContactId = (int) ($this->config['contact_id'] ?? 0);
        $configuredItemId = (int) ($this->config['ringing_group_item_id'] ?? 0);
        $configuredSipNumber = trim((string) ($this->config['ringing_group_sip_number'] ?? ''));

        foreach ($this->candidateRows($payload, ['contacts']) as $contact) {
            if (!is_array($contact)) {
                continue;
            }
            $contactId = $this->firstInt($contact, ['id', 'contactId', 'contact_id']);
            if ($contactId === null || ($configuredContactId > 0 && $contactId !== $configuredContactId)) {
                continue;
            }
            foreach ($this->candidateRows($contact, ['items']) as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $type = strtoupper((string) ($this->firstScalar($item, ['type', 'itemType', 'item_type']) ?? ''));
                if ($type !== 'RINGING_GROUP') {
                    continue;
                }
                $itemId = $this->firstInt($item, ['id', 'itemId', 'item_id']);
                if ($itemId === null || ($configuredItemId > 0 && $itemId !== $configuredItemId)) {
                    continue;
                }
                $sipNumber = trim((string) ($this->firstScalar(
                    $item,
                    ['sipNumber', 'sip_number', 'number']
                ) ?? ''));
                if ($configuredSipNumber !== '' && $sipNumber !== $configuredSipNumber) {
                    continue;
                }

                $members = isset($item['members']) && is_array($item['members']) ? $item['members'] : [];
                return [
                    'featureId' => $featureId,
                    'contactId' => $contactId,
                    'itemId' => $itemId,
                    'sipNumber' => $sipNumber,
                    'members' => $members,
                ];
            }
        }

        throw new RuntimeException('A API My2N não devolveu o destination group da Welcome Bell.', 503);
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
        return [];
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
                'A ligação My2N foi configurada, mas ainda falta identificar a empresa, o site e o destination group.',
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
