<?php
declare(strict_types=1);

require_once __DIR__ . '/My2NModeRepository.php';
require_once __DIR__ . '/My2NService.php';

final class My2NModeService
{
    public function __construct(
        private readonly My2NService $my2n,
        private readonly My2NModeRepository $repository,
        private readonly bool $writesEnabled
    ) {}

    public function activate(string $modeKey, string $trigger, ?string $actor = null, ?string $localDate = null): array
    {
        $this->assertWritesEnabled();
        if (!in_array($trigger, ['manual', 'automatic'], true)) throw new InvalidArgumentException('Tipo de ativação inválido.');
        $mode = $this->repository->mode($modeKey);
        if ($trigger === 'automatic' && (int) ($mode['enabled'] ?? 0) !== 1) return ['skipped' => true, 'reason' => 'disabled'];
        $assignments = $this->normalizeAssignments($mode['assignments'] ?? []);
        if ($assignments === []) throw new RuntimeException('O modo não tem campainhas configuradas.');
        $operationId = self::operationId();
        if (!$this->repository->beginRun($operationId, $mode, $trigger, $localDate, $actor)) {
            return ['skipped' => true, 'reason' => 'already-run'];
        }
        return $this->apply($operationId, $assignments, $modeKey, $trigger, $actor, 'mode-' . $trigger);
    }

    public function rollback(int $snapshotId, ?string $actor = null): array
    {
        $this->assertWritesEnabled();
        $assignments = $this->normalizeAssignments($this->repository->snapshot($snapshotId));
        $mode = ['id' => null];
        $operationId = self::operationId();
        $this->repository->beginRun($operationId, $mode, 'rollback', null, $actor);
        return $this->apply($operationId, $assignments, null, 'rollback', $actor, 'rollback-' . $snapshotId);
    }

    private function apply(string $operationId, array $requested, ?string $modeKey, string $trigger, ?string $actor, string $source): array
    {
        $current = [];
        $snapshotId = null;
        $changed = [];
        try {
            $status = $this->my2n->status();
            foreach ($status['bells'] ?? [] as $bell) $current[(string) $bell['bellKey']] = array_map('intval', $bell['currentMemberIds'] ?? []);
            $before = [];
            foreach ($requested as $assignment) {
                $key = $assignment['bellKey'];
                if (!array_key_exists($key, $current)) throw new InvalidArgumentException('Uma campainha configurada já não existe no site.');
                $before[] = ['bellKey' => $key, 'memberIds' => $current[$key]];
            }
            $snapshotId = $this->repository->saveSnapshot($operationId, $before, $source, $actor);
            $this->repository->attachSnapshot($operationId, $snapshotId);
            foreach ($requested as $assignment) {
                $result = $this->my2n->replaceBellMembers($assignment['bellKey'], $assignment['memberIds'], $current[$assignment['bellKey']]);
                if ($result['changed']) $changed[] = $assignment['bellKey'];
            }
            $this->repository->finishRun($operationId, $trigger === 'rollback' ? 'rolled_back' : 'success');
            $this->repository->audit($operationId, $trigger === 'rollback' ? 'rollback' : 'mode_activation', $modeKey, $trigger, $actor, $snapshotId, true);
            return ['skipped' => false, 'operationId' => $operationId, 'snapshotId' => $snapshotId, 'changedBellKeys' => $changed];
        } catch (Throwable $failure) {
            $rollbackErrors = [];
            foreach (array_reverse($changed) as $bellKey) {
                try {
                    $now = $this->currentMembers($bellKey);
                    $this->my2n->replaceBellMembers($bellKey, $current[$bellKey], $now);
                } catch (Throwable $rollbackFailure) {
                    $rollbackErrors[] = $bellKey . ': ' . $rollbackFailure->getMessage();
                }
            }
            $message = $failure->getMessage();
            if ($rollbackErrors !== []) $message .= ' Rollback parcial falhou: ' . implode('; ', $rollbackErrors);
            $status = $rollbackErrors === [] ? 'rolled_back' : 'rollback_failed';
            $this->repository->finishRun($operationId, $status, $message);
            $this->repository->audit($operationId, 'mode_failure', $modeKey, $trigger, $actor, $snapshotId, false, $message);
            throw new RuntimeException($message, 502, $failure);
        }
    }

    private function currentMembers(string $bellKey): array
    {
        foreach ($this->my2n->status()['bells'] ?? [] as $bell) {
            if (hash_equals((string) $bell['bellKey'], $bellKey)) return array_map('intval', $bell['currentMemberIds'] ?? []);
        }
        throw new RuntimeException('Campainha indisponível durante rollback.');
    }

    private function normalizeAssignments(array $assignments): array
    {
        $normalized = [];
        foreach ($assignments as $assignment) {
            $key = trim((string) ($assignment['bellKey'] ?? ''));
            $ids = $assignment['memberIds'] ?? null;
            if (!preg_match('/^\d+:\d+:\d+:\d+$/', $key) || !is_array($ids) || $ids === []) throw new InvalidArgumentException('Configuração de campainha inválida.');
            $ids = array_values(array_unique(array_map('intval', $ids)));
            if (min($ids) < 1) throw new InvalidArgumentException('Member ID inválido.');
            sort($ids, SORT_NUMERIC);
            $normalized[$key] = ['bellKey' => $key, 'memberIds' => $ids];
        }
        ksort($normalized);
        return array_values($normalized);
    }

    private function assertWritesEnabled(): void
    {
        if (!$this->writesEnabled) throw new RuntimeException('MY2N_ALLOW_WRITES mantém as alterações bloqueadas.', 409);
    }

    private static function operationId(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
