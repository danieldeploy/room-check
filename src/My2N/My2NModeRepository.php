<?php
declare(strict_types=1);

interface My2NModeRepository
{
    public function mode(string $modeKey): array;
    public function enabledModes(): array;
    public function beginRun(string $operationId, array $mode, string $trigger, ?string $localDate, ?string $actor): bool;
    public function saveSnapshot(string $operationId, array $assignments, string $source, ?string $actor): int;
    public function attachSnapshot(string $operationId, int $snapshotId): void;
    public function snapshot(int $snapshotId): array;
    public function finishRun(string $operationId, string $status, ?string $error = null): void;
    public function audit(string $operationId, string $action, ?string $modeKey, string $trigger, ?string $actor, ?int $snapshotId, bool $success, ?string $error = null): void;
}

