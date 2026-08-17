<?php
declare(strict_types=1);

require_once __DIR__ . '/My2NGateway.php';
require_once __DIR__ . '/My2NRedactor.php';

final class My2NClient implements My2NGateway
{
    private ?string $sessionToken = null;

    public function __construct(private readonly array $config)
    {
    }

    public function listMobileConfigurations(): array
    {
        $path = sprintf(
            '/companies/%d/sites/%d/services/MOBILE_VIDEO/configurations?limit=1000',
            $this->config['company_id'],
            $this->config['site_id']
        );
        return $this->partnerRequest('GET', $path);
    }

    public function getCurrentMembers(): array
    {
        return $this->partnerRequest('GET', $this->membersPath());
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
        return $this->partnerRequest('PUT', $this->membersPath(), array_values(array_map('intval', $memberIds)));
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
            ]
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
        $identifier = getenv('MY2N_IDENTIFIER') ?: '';
        $password = getenv('MY2N_PASSWORD') ?: '';
        $file = (string) ($this->config['secrets_file'] ?? '');

        if ($file !== '') {
            $resolved = realpath($file);
            if ($resolved === false || !is_file($resolved)) {
                throw new RuntimeException('Ficheiro externo de segredos My2N não encontrado.', 503);
            }
            $documentRoot = realpath((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));
            if ($documentRoot !== false && str_starts_with($resolved, $documentRoot . DIRECTORY_SEPARATOR)) {
                throw new RuntimeException('O ficheiro de segredos My2N não pode estar em public_html.', 503);
            }
            $decoded = json_decode((string) file_get_contents($resolved), true, 8, JSON_THROW_ON_ERROR);
            $identifier = (string) ($decoded['identifier'] ?? $identifier);
            $password = (string) ($decoded['password'] ?? $password);
        }

        if ($identifier === '' || $password === '') {
            throw new RuntimeException('Credenciais My2N não configuradas no servidor.', 503);
        }

        return ['identifier' => $identifier, 'password' => $password];
    }

    private function request(string $method, string $url, ?array $body = null, array $headers = []): array
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
        $decoded = $raw === '' ? [] : json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        $decoded = My2NRedactor::sanitize(is_array($decoded) ? $decoded : []);
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException('My2N respondeu com HTTP ' . $status . '.', 502);
        }

        return $decoded;
    }

    private function membersPath(): string
    {
        return sprintf(
            '/companies/%d/sites/%d/devices/%d/features/CONTACT_LIST/%d/contacts/%d/items/RINGING_GROUP/%d/members',
            $this->config['company_id'],
            $this->config['site_id'],
            $this->config['intercom_device_id'],
            $this->config['contact_list_feature_id'],
            $this->config['contact_id'],
            $this->config['ringing_group_item_id']
        );
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
        $direct = $this->findScalar($payload, ['flow_id', 'flowId']);
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
