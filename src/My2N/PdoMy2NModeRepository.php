<?php
declare(strict_types=1);

require_once __DIR__ . '/My2NModeRepository.php';

final class PdoMy2NModeRepository implements My2NModeRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function mode(string $modeKey): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM my2n_modes WHERE mode_key = :mode LIMIT 1');
        $statement->execute(['mode' => $modeKey]);
        $mode = $statement->fetch();
        if (!is_array($mode)) throw new InvalidArgumentException('Modo My2N desconhecido.');
        $mode['assignments'] = $this->assignments((int) $mode['id']);
        return $mode;
    }

    public function enabledModes(): array
    {
        $rows = $this->pdo->query('SELECT mode_key FROM my2n_modes WHERE enabled = 1 ORDER BY local_start_time')->fetchAll();
        return array_map(fn(array $row): array => $this->mode((string) $row['mode_key']), $rows);
    }

    public function beginRun(string $operationId, array $mode, string $trigger, ?string $localDate, ?string $actor): bool
    {
        try {
            $statement = $this->pdo->prepare('INSERT INTO my2n_mode_runs
                (operation_id, mode_id, trigger_type, local_date, actor, status, started_at)
                VALUES (:operation, :mode, :trigger, :local_date, :actor, \'running\', UTC_TIMESTAMP())');
            $statement->execute(['operation' => $operationId, 'mode' => $mode['id'], 'trigger' => $trigger, 'local_date' => $localDate, 'actor' => $actor]);
            return true;
        } catch (PDOException $exception) {
            if ($trigger === 'automatic' && (string) $exception->getCode() === '23000') return false;
            throw $exception;
        }
    }

    public function saveSnapshot(string $operationId, array $assignments, string $source, ?string $actor): int
    {
        $statement = $this->pdo->prepare('INSERT INTO my2n_member_snapshots
            (operation_id, member_ids_json, source, created_by) VALUES (:operation, :payload, :source, :actor)');
        $statement->execute(['operation' => $operationId, 'payload' => json_encode(['bells' => $assignments], JSON_THROW_ON_ERROR), 'source' => $source, 'actor' => $actor]);
        return (int) $this->pdo->lastInsertId();
    }

    public function attachSnapshot(string $operationId, int $snapshotId): void
    {
        $this->pdo->prepare('UPDATE my2n_mode_runs SET snapshot_id = :snapshot WHERE operation_id = :operation')
            ->execute(['snapshot' => $snapshotId, 'operation' => $operationId]);
    }

    public function snapshot(int $snapshotId): array
    {
        $statement = $this->pdo->prepare('SELECT member_ids_json FROM my2n_member_snapshots WHERE id = :id');
        $statement->execute(['id' => $snapshotId]);
        $payload = $statement->fetchColumn();
        if (!is_string($payload)) throw new InvalidArgumentException('Snapshot My2N inexistente.');
        $decoded = json_decode($payload, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($decoded['bells'] ?? null)) throw new RuntimeException('Snapshot incompatível com rollback multi-campainha.');
        return $decoded['bells'];
    }

    public function finishRun(string $operationId, string $status, ?string $error = null): void
    {
        $this->pdo->prepare('UPDATE my2n_mode_runs SET status = :status, error_message = :error,
            finished_at = UTC_TIMESTAMP() WHERE operation_id = :operation')
            ->execute(['status' => $status, 'error' => $error === null ? null : mb_substr($error, 0, 500), 'operation' => $operationId]);
    }

    public function audit(string $operationId, string $action, ?string $modeKey, string $trigger, ?string $actor, ?int $snapshotId, bool $success, ?string $error = null): void
    {
        $this->pdo->prepare('INSERT INTO my2n_audit_log
            (operation_id, action, mode_key, trigger_type, actor, snapshot_id, dry_run, success, error_message)
            VALUES (:operation, :action, :mode, :trigger, :actor, :snapshot, 0, :success, :error)')
            ->execute(['operation' => $operationId, 'action' => $action, 'mode' => $modeKey, 'trigger' => $trigger, 'actor' => $actor, 'snapshot' => $snapshotId, 'success' => $success ? 1 : 0, 'error' => $error === null ? null : mb_substr($error, 0, 500)]);
    }

    private function assignments(int $modeId): array
    {
        $statement = $this->pdo->prepare('SELECT bell_key, member_ids_json FROM my2n_schedules WHERE mode_id = :mode AND enabled = 1 ORDER BY bell_key');
        $statement->execute(['mode' => $modeId]);
        return array_map(static fn(array $row): array => ['bellKey' => $row['bell_key'], 'memberIds' => json_decode($row['member_ids_json'], true, 16, JSON_THROW_ON_ERROR)], $statement->fetchAll());
    }
}
