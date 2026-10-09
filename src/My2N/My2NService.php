<?php
declare(strict_types=1);

require_once __DIR__ . '/My2NGateway.php';
require_once __DIR__ . '/My2NRedactor.php';

final class My2NService
{
    public function __construct(private readonly My2NGateway $gateway, private readonly array $config)
    {
    }

    public function status(): array
    {
        $rawDevices = My2NRedactor::sanitize($this->gateway->listSiteDevices());
        $rawBellGroups = My2NRedactor::sanitize($this->gateway->listBellGroups());
        $bells = $this->normalizeBellGroups($rawBellGroups);
        $intercomDeviceIds = array_values(array_unique(array_map(
            'intval',
            array_column($bells, 'intercomDeviceId')
        )));
        $mobiles = $this->normalizeConfigurations($rawDevices, $intercomDeviceIds);
        $knownMemberIds = array_map('intval', array_column($mobiles, 'memberId'));

        foreach ($bells as &$bell) {
            $bell['unresolvedMemberIds'] = array_values(array_diff(
                $bell['currentMemberIds'],
                $knownMemberIds
            ));
        }
        unset($bell);

        return [
            'siteId' => (int) $this->config['site_id'],
            'dryRun' => ($this->config['allow_writes'] ?? false) !== true,
            'bells' => $bells,
            'mobiles' => $mobiles,
            'readAt' => (new DateTimeImmutable('now', new DateTimeZone($this->config['timezone'])))->format(DATE_ATOM),
        ];
    }

    public function replaceBellMembers(
        string $bellKey,
        array $memberIds,
        array $expectedCurrentMemberIds
    ): array
    {
        if (!preg_match('/^\d+:\d+:\d+:\d+$/', $bellKey)) {
            throw new InvalidArgumentException('Campainha inválida.');
        }
        $requested = $this->normalizeRequestedMemberIds($memberIds, false);
        $expected = $this->normalizeRequestedMemberIds($expectedCurrentMemberIds, true);
        $before = $this->status();
        $beforeBell = $this->bellByKey($before['bells'], $bellKey);
        if (($beforeBell['unresolvedMemberIds'] ?? []) !== []) {
            throw new RuntimeException(
                'Esta campainha tem membros que ainda não foram associados a um telemóvel; a gravação foi bloqueada.',
                409
            );
        }
        $current = $this->normalizeRequestedMemberIds($beforeBell['currentMemberIds'], true);
        if ($current !== $expected) {
            throw new RuntimeException(
                'Os destinatários desta campainha foram alterados entretanto. Atualize a lista antes de tentar novamente.',
                409
            );
        }

        $knownIds = array_map('intval', array_column($before['mobiles'], 'memberId'));
        foreach ($requested as $memberId) {
            if (!in_array($memberId, $knownIds, true)) {
                throw new InvalidArgumentException('Foi selecionado um telemóvel que não pertence a este site.');
            }
        }
        if ($requested === $current) {
            return [
                'changed' => false,
                'bellKey' => $bellKey,
                'beforeMemberIds' => $current,
                'requestedMemberIds' => $requested,
                'status' => $before,
            ];
        }

        $this->gateway->updateBellMembers($bellKey, $requested);
        $after = $this->status();
        $afterBell = $this->bellByKey($after['bells'], $bellKey);
        $confirmed = $this->normalizeRequestedMemberIds($afterBell['currentMemberIds'], true);
        if ($confirmed !== $requested) {
            throw new RuntimeException('A My2N não confirmou todos os destinatários pedidos.', 502);
        }

        return [
            'changed' => true,
            'bellKey' => $bellKey,
            'beforeMemberIds' => $current,
            'requestedMemberIds' => $requested,
            'status' => $after,
        ];
    }

    private function normalizeBellGroups(array $groups): array
    {
        $bells = [];
        foreach ($groups as $group) {
            if (!is_array($group)) {
                continue;
            }
            $bellKey = trim((string) ($group['bellKey'] ?? ''));
            $intercomDeviceId = $this->firstInt($group, ['intercomDeviceId']);
            if (!preg_match('/^\d+:\d+:\d+:\d+$/', $bellKey) || $intercomDeviceId === null) {
                continue;
            }
            $bells[] = [
                'bellKey' => $bellKey,
                'bellName' => trim((string) ($group['bellName'] ?? '')) ?: ('Campainha ' . $intercomDeviceId),
                'groupName' => trim((string) ($group['groupName'] ?? '')) ?: null,
                'intercomDeviceId' => $intercomDeviceId,
                'apartmentId' => $this->firstInt($group, ['apartmentId']),
                'apartmentName' => $this->firstString($group, ['apartmentName']),
                'ringingGroupSipNumber' => $this->firstString($group, ['ringingGroupSipNumber']),
                'currentMemberIds' => $this->normalizeMemberIds([
                    'members' => isset($group['members']) && is_array($group['members'])
                        ? $group['members']
                        : [],
                ]),
            ];
        }
        usort($bells, static function (array $left, array $right): int {
            $apartment = strcasecmp((string) $left['apartmentName'], (string) $right['apartmentName']);
            return $apartment !== 0 ? $apartment : strcasecmp($left['bellName'], $right['bellName']);
        });
        return $bells;
    }

    private function bellByKey(array $bells, string $bellKey): array
    {
        foreach ($bells as $bell) {
            if (is_array($bell) && hash_equals((string) ($bell['bellKey'] ?? ''), $bellKey)) {
                return $bell;
            }
        }
        throw new InvalidArgumentException('Campainha inválida ou já removida do site.');
    }

    private function normalizeConfigurations(array $payload, array $intercomDeviceIds): array
    {
        $rows = $this->candidateRows(
            $payload,
            ['results', 'configurations', 'deviceConfigurations', 'device_configurations', 'items', 'data']
        );
        $devices = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $mobileConfiguration = [];
            $notificationConfiguration = [];
            $credentialsConfiguration = [];
            if (isset($row['services']) && is_array($row['services'])) {
                foreach ($row['services'] as $serviceType => $serviceConfiguration) {
                    if (!is_string($serviceType) || !is_array($serviceConfiguration)) {
                        continue;
                    }
                    switch (strtoupper($serviceType)) {
                        case 'MOBILE_VIDEO':
                            $mobileConfiguration = $serviceConfiguration;
                            break;
                        case 'NOTIFICATION':
                            $notificationConfiguration = $serviceConfiguration;
                            break;
                        case 'CREDENTIALS':
                            $credentialsConfiguration = $serviceConfiguration;
                            break;
                    }
                }
                if ($mobileConfiguration === []) {
                    continue;
                }
            }

            $memberId = $this->firstInt(
                $mobileConfiguration !== [] ? $mobileConfiguration : $row,
                ['deviceConfigId', 'deviceConfigurationId', 'configurationId', 'memberId', 'id']
            );
            if ($memberId === null) {
                continue;
            }
            $siteId = $this->firstInt($row, ['siteId', 'site_id']);
            if ($siteId === null && isset($row['site']) && is_array($row['site'])) {
                $siteId = $this->firstInt($row['site'], ['id', 'siteId', 'site_id']);
            }
            if ($siteId !== null && $siteId !== (int) $this->config['site_id']) {
                continue;
            }
            $apartment = isset($row['apartment']) && is_array($row['apartment'])
                ? $row['apartment']
                : [];
            $device = isset($row['device']) && is_array($row['device'])
                ? $row['device']
                : [];
            $deviceId = $mobileConfiguration !== []
                ? $this->firstInt($row, ['id', 'deviceId', 'device_id'])
                : $this->firstInt($row, ['deviceId', 'device_id']);
            $deviceId ??= $this->firstInt($device, ['id', 'deviceId', 'device_id']);
            $deviceType = strtoupper(
                $this->firstString($row, ['type', 'deviceType', 'device_type', 'category', 'productType', 'product_type'])
                ?? $this->firstString($device, ['type', 'deviceType', 'device_type', 'category', 'productType', 'product_type'])
                ?? ''
            );
            if (($deviceId !== null && in_array($deviceId, $intercomDeviceIds, true))
                || str_contains($deviceType, 'INTERCOM')) {
                continue;
            }
            $registrationStatus = strtoupper(
                $this->firstString($mobileConfiguration, ['status', 'registrationStatus'])
                ?? $this->firstString($row, ['status', 'registrationStatus'])
                ?? $this->firstString($device, ['status', 'registrationStatus'])
                ?? 'UNKNOWN'
            );
            $pushConfigured = $this->pushConfigurationConfigured($notificationConfiguration)
                || $this->pushConfigurationConfigured($credentialsConfiguration);
            $availability = match ($registrationStatus) {
                'REGISTERED' => 'ONLINE',
                'NEVER_REGISTERED' => 'NEVER_REGISTERED',
                'NOT_REGISTERED' => 'NOT_REGISTERED',
                'DISABLED' => 'DISABLED',
                'UNLICENSED' => 'UNLICENSED',
                default => 'UNKNOWN',
            };
            $devices[] = [
                'memberId' => $memberId,
                'deviceId' => $deviceId,
                'name' => $this->firstString($row, ['name', 'deviceName', 'displayName'])
                    ?? $this->firstString($device, ['name', 'deviceName', 'displayName'])
                    ?? 'Sem nome',
                'apartmentId' => $this->firstInt($row, ['apartmentId', 'apartment_id'])
                    ?? $this->firstInt($apartment, ['id', 'apartmentId', 'apartment_id']),
                'apartmentName' => $this->firstString($row, ['apartmentName', 'apartment_name'])
                    ?? $this->firstString($apartment, ['name', 'displayName']),
                'status' => $registrationStatus,
                'pushConfigured' => $pushConfigured,
                'availability' => $availability,
                'sipNumber' => $this->firstString($mobileConfiguration, ['sipNumber', 'sip_number'])
                    ?? $this->firstString($row, ['sipNumber', 'sip_number'])
                    ?? $this->firstString($device, ['sipNumber', 'sip_number']),
            ];
        }

        usort($devices, static fn(array $a, array $b): int => strcasecmp($a['name'], $b['name']));
        return $devices;
    }

    private function normalizeRequestedMemberIds(array $memberIds, bool $allowEmpty): array
    {
        $normalized = [];
        foreach ($memberIds as $memberId) {
            if (!is_int($memberId) && !(is_string($memberId) && ctype_digit($memberId))) {
                throw new InvalidArgumentException('Member ID inválido.');
            }
            $value = (int) $memberId;
            if ($value < 1) {
                throw new InvalidArgumentException('Member ID inválido.');
            }
            $normalized[] = $value;
        }
        $normalized = array_values(array_unique($normalized));
        sort($normalized, SORT_NUMERIC);
        if (!$allowEmpty && $normalized === []) {
            throw new InvalidArgumentException('Selecione pelo menos um destinatário para a campainha.');
        }
        return $normalized;
    }

    private function normalizeMemberIds(array $payload): array
    {
        $rows = $this->candidateRows($payload, ['members', 'items', 'data']);
        $ids = [];
        foreach ($rows as $row) {
            if (is_int($row) || (is_string($row) && ctype_digit($row))) {
                $ids[] = (int) $row;
            } elseif (is_array($row)) {
                $id = $this->firstInt(
                    $row,
                    ['deviceConfigId', 'deviceConfigurationId', 'configurationId', 'memberId', 'id']
                );
                if ($id !== null) {
                    $ids[] = $id;
                }
            }
        }
        $ids = array_values(array_unique($ids));
        sort($ids, SORT_NUMERIC);
        return $ids;
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

    private function pushConfigurationConfigured(array $configuration): bool
    {
        if ($configuration === []) {
            return false;
        }
        $active = $this->firstBool($configuration, ['active', 'enabled']);
        $status = strtoupper($this->firstString($configuration, ['status', 'state']) ?? '');
        if ($active === false || in_array(
            $status,
            ['DISABLED', 'UNLICENSED', 'NOT_REGISTERED', 'NEVER_REGISTERED', 'ERROR'],
            true
        )) {
            return false;
        }
        return $active === true || in_array($status, ['ACTIVE', 'ENABLED', 'READY', 'REGISTERED'], true);
    }

    private function firstBool(array $row, array $keys): ?bool
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $row)) {
                continue;
            }
            if (is_bool($row[$key])) {
                return $row[$key];
            }
            if (is_int($row[$key]) && in_array($row[$key], [0, 1], true)) {
                return $row[$key] === 1;
            }
            if (is_string($row[$key])) {
                $value = strtolower(trim($row[$key]));
                if (in_array($value, ['1', 'true', 'yes', 'on', 'enabled'], true)) {
                    return true;
                }
                if (in_array($value, ['0', 'false', 'no', 'off', 'disabled'], true)) {
                    return false;
                }
            }
        }
        return null;
    }

    private function firstInt(array $row, array $keys): ?int
    {
        foreach ($keys as $key) {
            if (isset($row[$key]) && (is_int($row[$key]) || ctype_digit((string) $row[$key]))) {
                return (int) $row[$key];
            }
        }
        return null;
    }

    private function firstString(array $row, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (isset($row[$key]) && is_scalar($row[$key])) {
                return (string) $row[$key];
            }
        }
        return null;
    }
}
