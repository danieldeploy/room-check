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

    public function replaceMembers(array $memberIds, array $expectedCurrentMemberIds): array
    {
        $requested = $this->normalizeRequestedMemberIds($memberIds, false);
        $expected = $this->normalizeRequestedMemberIds($expectedCurrentMemberIds, true);
        $before = $this->status();
        $current = $this->normalizeRequestedMemberIds($before['currentMemberIds'], true);
        if ($current !== $expected) {
            throw new RuntimeException(
                'Os destinatários foram alterados entretanto. Atualize a lista antes de tentar novamente.',
                409
            );
        }

        $knownIds = array_map('intval', array_column($before['devices'], 'memberId'));
        foreach ($requested as $memberId) {
            if (!in_array($memberId, $knownIds, true)) {
                throw new InvalidArgumentException('Foi selecionado um telemóvel que não pertence a este site.');
            }
        }
        if ($requested === $current) {
            return [
                'changed' => false,
                'beforeMemberIds' => $current,
                'requestedMemberIds' => $requested,
                'status' => $before,
            ];
        }

        $this->gateway->updateMembers($requested);
        $after = $this->status();
        $confirmed = $this->normalizeRequestedMemberIds($after['currentMemberIds'], true);
        if ($confirmed !== $requested) {
            throw new RuntimeException('A My2N não confirmou todos os destinatários pedidos.', 502);
        }

        return [
            'changed' => true,
            'beforeMemberIds' => $current,
            'requestedMemberIds' => $requested,
            'status' => $after,
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
            $apartment = isset($row['apartment']) && is_array($row['apartment'])
                ? $row['apartment']
                : [];
            $devices[] = [
                'memberId' => $memberId,
                'deviceId' => $this->firstInt($row, ['deviceId', 'device_id']),
                'name' => $this->firstString($row, ['name', 'deviceName', 'displayName']) ?? 'Sem nome',
                'apartmentId' => $this->firstInt($row, ['apartmentId', 'apartment_id'])
                    ?? $this->firstInt($apartment, ['id', 'apartmentId', 'apartment_id']),
                'apartmentName' => $this->firstString($row, ['apartmentName', 'apartment_name'])
                    ?? $this->firstString($apartment, ['name', 'displayName']),
                'status' => strtoupper($this->firstString($row, ['status', 'registrationStatus']) ?? 'UNKNOWN'),
                'sipNumber' => $this->firstString($row, ['sipNumber', 'sip_number']),
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
