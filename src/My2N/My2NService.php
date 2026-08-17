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
        $rawConfigurations = My2NRedactor::sanitize($this->gateway->listMobileConfigurations());
        $rawMembers = My2NRedactor::sanitize($this->gateway->getCurrentMembers());
        $devices = $this->normalizeConfigurations($rawConfigurations);
        $memberIds = $this->normalizeMemberIds($rawMembers);
        $knownIds = array_column($devices, 'memberId');

        foreach ($memberIds as $memberId) {
            if (!in_array($memberId, $knownIds, true)) {
                throw new RuntimeException('O grupo atual contém um membro que não pertence às configurações do site.', 409);
            }
        }

        foreach ($devices as &$device) {
            $device['inCurrentGroup'] = in_array($device['memberId'], $memberIds, true);
        }
        unset($device);

        return [
            'siteId' => (int) $this->config['site_id'],
            'intercomDeviceId' => (int) $this->config['intercom_device_id'],
            'ringingGroupSipNumber' => (string) $this->config['ringing_group_sip_number'],
            'dryRun' => ($this->config['allow_writes'] ?? false) !== true,
            'devices' => $devices,
            'currentMemberIds' => $memberIds,
            'readAt' => (new DateTimeImmutable('now', new DateTimeZone($this->config['timezone'])))->format(DATE_ATOM),
        ];
    }

    private function normalizeConfigurations(array $payload): array
    {
        $rows = $this->candidateRows($payload, ['configurations', 'items', 'data']);
        $devices = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $memberId = $this->firstInt($row, ['id', 'configurationId', 'memberId']);
            if ($memberId === null) {
                continue;
            }
            $siteId = $this->firstInt($row, ['siteId', 'site_id']);
            if ($siteId !== null && $siteId !== (int) $this->config['site_id']) {
                continue;
            }
            $devices[] = [
                'memberId' => $memberId,
                'deviceId' => $this->firstInt($row, ['deviceId', 'device_id']),
                'name' => $this->firstString($row, ['name', 'deviceName', 'displayName']) ?? 'Sem nome',
                'status' => strtoupper($this->firstString($row, ['status', 'registrationStatus']) ?? 'UNKNOWN'),
                'sipNumber' => $this->firstString($row, ['sipNumber', 'sip_number']),
            ];
        }

        usort($devices, static fn(array $a, array $b): int => strcasecmp($a['name'], $b['name']));
        return $devices;
    }

    private function normalizeMemberIds(array $payload): array
    {
        $rows = $this->candidateRows($payload, ['members', 'items', 'data']);
        $ids = [];
        foreach ($rows as $row) {
            if (is_int($row) || (is_string($row) && ctype_digit($row))) {
                $ids[] = (int) $row;
            } elseif (is_array($row)) {
                $id = $this->firstInt($row, ['id', 'configurationId', 'memberId']);
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
