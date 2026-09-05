<?php
declare(strict_types=1);

require_once __DIR__ . '/TranslationQuotaExceededException.php';

final class PendingTranslationQueue
{
    private const CHANNELS = ['api', 'item_lists', 'verification_categories'];
    private const API_ACTIONS = [
        'save_item_list_instructions',
        'create_interval',
        'update_interval',
        'set_assignments_atomic',
        'assign_items',
        'save_checklist',
    ];
    private const ITEM_LIST_ACTIONS = ['create_list', 'rename_list', 'add_item', 'rename_item'];
    private const CATEGORY_ACTIONS = ['create_category', 'rename_category'];

    public function __construct(private PDO $pdo, private array $config) {}

    /** @return array{id:int,message:string,resetDisplay:string,jobKey:string} */
    public function enqueue(
        string $channel,
        array $request,
        string $sourceLanguage,
        int $actorId,
        TranslationQuotaExceededException $quota
    ): array {
        if (($this->config['pending_enabled'] ?? true) !== true) {
            throw $quota;
        }
        if (!in_array($channel, self::CHANNELS, true)) {
            throw new InvalidArgumentException('Canal de tradução pendente inválido.');
        }

        $sourceLanguage = $sourceLanguage === 'en' ? 'en' : 'pt';
        $normalized = $this->normalizedRequest($channel, $request);
        $operation = $this->operation($channel, $normalized);
        $jobKey = $this->jobKey($channel, $operation, $normalized, $actorId);

        try {
            $this->pdo->beginTransaction();
            $jobLock = $this->pdo->prepare(
                'SELECT id FROM translation_pending_jobs
                 WHERE engine_key = :engine_key AND job_key = :job_key FOR UPDATE'
            );
            $jobLock->execute(['engine_key' => $this->engineKey(), 'job_key' => $jobKey]);
            $jobLock->fetchColumn();
            $normalized['_expected'] = $this->snapshot($channel, $normalized, true);
            $payloadJson = json_encode(
                $normalized,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            );
            if (strlen($payloadJson) > 1000000) {
                throw new InvalidArgumentException('A alteração pendente é demasiado grande.');
            }
            $statement = $this->pdo->prepare(
                "INSERT INTO translation_pending_jobs
                    (engine_key, job_key, channel, operation_type, payload_json, source_language,
                     status, not_before, created_by_user_id)
                 VALUES
                    (:engine_key, :job_key, :channel, :operation_type, :payload_json, :source_language,
                     'pending', :not_before, :created_by)
                 ON DUPLICATE KEY UPDATE
                    channel = VALUES(channel), operation_type = VALUES(operation_type),
                    payload_json = VALUES(payload_json), source_language = VALUES(source_language),
                    status = 'pending', generation = generation + 1,
                    not_before = VALUES(not_before), attempt_count = 0,
                    locked_at = NULL, completed_at = NULL, last_error = NULL,
                    created_by_user_id = VALUES(created_by_user_id), updated_at = CURRENT_TIMESTAMP"
            );
            $statement->execute([
                'engine_key' => $this->engineKey(),
                'job_key' => $jobKey,
                'channel' => $channel,
                'operation_type' => $operation,
                'payload_json' => $payloadJson,
                'source_language' => $sourceLanguage,
                'not_before' => $quota->resetUtc(),
                'created_by' => $actorId > 0 ? $actorId : null,
            ]);
            $idStatement = $this->pdo->prepare(
                'SELECT id FROM translation_pending_jobs WHERE engine_key = :engine_key AND job_key = :job_key'
            );
            $idStatement->execute(['engine_key' => $this->engineKey(), 'job_key' => $jobKey]);
            $id = (int) $idStatement->fetchColumn();
            $audit = $this->pdo->prepare(
                'INSERT INTO auth_audit_log (actor_user_id, action, details_json, ip_key)
                 VALUES (:actor, :action, :details, :ip)'
            );
            $audit->execute([
                'actor' => $actorId > 0 ? $actorId : null,
                'action' => 'translation_change_queued',
                'details' => json_encode([
                    'pending_job_id' => $id,
                    'channel' => $channel,
                    'operation_type' => $operation,
                    'not_before' => $quota->resetUtc(),
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'ip' => hash('sha256', (string) ($_SERVER['REMOTE_ADDR'] ?? 'cli')),
            ]);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            if (!$exception instanceof PDOException) {
                throw $exception;
            }
            $message = self::isMissingQueueTable($exception)
                ? ($sourceLanguage === 'en'
                    ? 'The edit could not be queued. Apply migration 025_translation_pending_jobs.sql.'
                    : 'Não foi possível colocar a alteração em fila. Importe a migração 025_translation_pending_jobs.sql.')
                : ($sourceLanguage === 'en'
                    ? 'The edit could not be queued because the database is temporarily unavailable.'
                    : 'Não foi possível colocar a alteração em fila porque a base de dados está temporariamente indisponível.');
            throw new RuntimeException($message, 0, $exception);
        }

        return [
            'id' => $id,
            'jobKey' => $jobKey,
            'resetDisplay' => $quota->resetDisplay(),
            'message' => self::pendingMessage($sourceLanguage, $quota->resetDisplay()),
        ];
    }

    public function supersedeForAcceptedSave(string $channel, array $request, int $actorId): void
    {
        if (($this->config['pending_enabled'] ?? true) !== true) {
            return;
        }
        if (!$this->pdo->inTransaction()) {
            throw new RuntimeException('A invalidação da tradução pendente exige uma transação.');
        }
        if (!in_array($channel, self::CHANNELS, true)) {
            throw new InvalidArgumentException('Canal de tradução pendente inválido.');
        }

        $normalized = $this->normalizedRequest($channel, $request);
        $operation = $this->operation($channel, $normalized);
        $jobKey = $this->jobKey($channel, $operation, $normalized, $actorId);
        try {
            $lock = $this->pdo->prepare(
                'SELECT id, status FROM translation_pending_jobs
                 WHERE engine_key = :engine_key AND job_key = :job_key FOR UPDATE'
            );
            $lock->execute(['engine_key' => $this->engineKey(), 'job_key' => $jobKey]);
        } catch (PDOException $exception) {
            // A missing optional queue table must not break an otherwise valid
            // immediate bilingual save. Quota-deferred saves still fail closed
            // in enqueue() with the migration-specific operator message.
            if (self::isMissingQueueTable($exception)) {
                return;
            }
            throw $exception;
        }
        $job = $lock->fetch();
        if (!is_array($job) || !in_array((string) $job['status'], ['pending', 'processing'], true)) {
            return;
        }

        $statement = $this->pdo->prepare(
            "UPDATE translation_pending_jobs
             SET status = 'superseded', generation = generation + 1,
                 completed_at = UTC_TIMESTAMP(), locked_at = NULL,
                 last_error = 'Superseded by a later accepted save.'
             WHERE id = :id AND status IN ('pending', 'processing')"
        );
        $statement->execute(['id' => (int) $job['id']]);
    }

    public static function pendingMessage(string $sourceLanguage, string $resetDisplay): string
    {
        return $sourceLanguage === 'en'
            ? "Edit queued for translation. Processing will resume after {$resetDisplay} (Portugal time)."
            : "Alteração guardada para tradução. O processamento será retomado depois de {$resetDisplay} (hora de Portugal).";
    }

    private function normalizedRequest(string $channel, array $request): array
    {
        if ($channel === 'api') {
            $action = trim((string) ($request['action'] ?? ''));
            if ($action === '' && isset($request['items'])) {
                $action = 'save_checklist';
            }
            if (!in_array($action, self::API_ACTIONS, true)) {
                throw new InvalidArgumentException('Operação de tradução pendente inválida.');
            }
            return $this->normalizeApi($action, $request);
        }

        $action = trim((string) ($request['action'] ?? ''));
        $allowed = $channel === 'item_lists' ? self::ITEM_LIST_ACTIONS : self::CATEGORY_ACTIONS;
        if (!in_array($action, $allowed, true)) {
            throw new InvalidArgumentException('Operação de tradução pendente inválida.');
        }

        if ($channel === 'item_lists') {
            return [
                'action' => $action,
                'list_id' => (int) ($request['list_id'] ?? 0),
                'item_id' => (int) ($request['item_id'] ?? 0),
                'name' => trim((string) ($request['name'] ?? '')),
                'area' => trim((string) ($request['area'] ?? '')),
                'default_instructions' => trim((string) ($request['default_instructions'] ?? '')),
            ];
        }

        return [
            'action' => $action,
            'category_id' => (int) ($request['category_id'] ?? 0),
            'name' => trim((string) ($request['name'] ?? '')),
        ];
    }

    private function normalizeApi(string $action, array $request): array
    {
        $normalized = ['action' => $action];
        foreach (['listId', 'itemId', 'intervalId', 'employeeId', 'room'] as $key) {
            if (array_key_exists($key, $request)) {
                $normalized[$key] = (int) $request[$key];
            }
        }
        foreach (['name', 'startDate', 'endDate', 'property', 'dueDate', 'text'] as $key) {
            if (array_key_exists($key, $request)) {
                $normalized[$key] = trim((string) $request[$key]);
            }
        }
        if (array_key_exists('changes', $request)) {
            $normalized['changes'] = $this->normalizeChanges($request['changes']);
        }
        if (array_key_exists('selectedItems', $request)) {
            $normalized['selectedItems'] = is_array($request['selectedItems'])
                ? array_values(array_map('strval', $request['selectedItems']))
                : [];
        }
        if (array_key_exists('instructions', $request)) {
            $normalized['instructions'] = is_array($request['instructions'])
                ? array_map(static fn(mixed $value): string => trim((string) $value), $request['instructions'])
                : [];
        }
        if (array_key_exists('items', $request)) {
            $normalized['items'] = $this->normalizeChecklistItems($request['items']);
        }
        return $normalized;
    }

    private function normalizeChanges(mixed $changes): array
    {
        if (!is_array($changes)) {
            return [];
        }
        $result = [];
        foreach ($changes as $change) {
            if (!is_array($change)) {
                continue;
            }
            $result[] = [
                'itemName' => trim((string) ($change['itemName'] ?? '')),
                'selected' => filter_var($change['selected'] ?? false, FILTER_VALIDATE_BOOL),
                'instructions' => trim((string) ($change['instructions'] ?? '')),
            ];
        }
        return $result;
    }

    private function normalizeChecklistItems(mixed $items): array
    {
        if (!is_array($items)) {
            return [];
        }
        $result = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $status = $item['status'] ?? null;
            $result[] = [
                'name' => trim((string) ($item['name'] ?? '')),
                'problem' => trim((string) ($item['problem'] ?? '')),
                'status' => in_array($status, ['wrong', 'ok'], true) ? $status : null,
            ];
        }
        return $result;
    }

    private function operation(string $channel, array $request): string
    {
        return $channel . ':' . (string) $request['action'];
    }

    private function jobKey(string $channel, string $operation, array $request, int $actorId): string
    {
        $scope = in_array($operation, ['api:set_assignments_atomic', 'api:assign_items'], true)
            ? 'api:assignments'
            : $operation;
        $identity = match ($operation) {
            'api:save_item_list_instructions' => [$request['listId'] ?? 0, $request['itemId'] ?? 0],
            'api:update_interval' => [$request['intervalId'] ?? 0],
            'api:set_assignments_atomic', 'api:assign_items' => [
                $request['intervalId'] ?? 0, $request['listId'] ?? 0,
                $request['property'] ?? '', $request['room'] ?? 0,
            ],
            'api:save_checklist' => [$request['listId'] ?? 0, $request['property'] ?? '', $request['room'] ?? 0],
            'item_lists:rename_list' => [$request['list_id'] ?? 0],
            'item_lists:rename_item' => [$request['list_id'] ?? 0, $request['item_id'] ?? 0],
            'verification_categories:rename_category' => [$request['category_id'] ?? 0],
            default => [$actorId, hash('sha256', json_encode(
                $request,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            ))],
        };
        return hash('sha256', $channel . '|' . $scope . '|' . json_encode($identity, JSON_THROW_ON_ERROR));
    }

    private function snapshot(string $channel, array $request, bool $forUpdate = false): ?string
    {
        $operation = $this->operation($channel, $request);
        $suffix = $forUpdate ? ' FOR UPDATE' : '';
        $rows = match ($operation) {
            'api:save_item_list_instructions' => $this->rows(
                'SELECT id, list_id, default_instructions, default_instructions_en
                 FROM item_list_items WHERE id = :id AND list_id = :list_id' . $suffix,
                ['id' => $request['itemId'] ?? 0, 'list_id' => $request['listId'] ?? 0]
            ),
            'api:update_interval' => $this->rows(
                'SELECT id, name, name_en, start_date, end_date FROM room_verification_intervals WHERE id = :id' . $suffix,
                ['id' => $request['intervalId'] ?? 0]
            ),
            'api:set_assignments_atomic', 'api:assign_items' => $this->assignmentSnapshot($request, $forUpdate),
            'api:save_checklist' => $this->checklistSnapshot($request, $forUpdate),
            'item_lists:rename_list' => $this->rows(
                'SELECT id, name, name_en, area FROM item_lists WHERE id = :id' . $suffix,
                ['id' => $request['list_id'] ?? 0]
            ),
            'item_lists:rename_item' => $this->rows(
                'SELECT id, list_id, name, name_en, default_instructions, default_instructions_en
                 FROM item_list_items WHERE id = :id AND list_id = :list_id' . $suffix,
                ['id' => $request['item_id'] ?? 0, 'list_id' => $request['list_id'] ?? 0]
            ),
            'verification_categories:rename_category' => $this->rows(
                'SELECT id, name, name_en FROM verification_categories WHERE id = :id' . $suffix,
                ['id' => $request['category_id'] ?? 0]
            ),
            default => null,
        };
        return $rows === null ? null : self::stateHash($rows);
    }

    private function assignmentSnapshot(array $request, bool $forUpdate): array
    {
        return [
            'items' => $this->listItemNames((int) ($request['listId'] ?? 0), $forUpdate),
            'rows' => $this->rows(
                'SELECT item_name, assigned_to_user_id, assigned_by_user_id, due_date,
                        verification_instructions, verification_instructions_en, completed_at
                 FROM room_item_assignments
                 WHERE interval_id = :interval_id AND list_id = :list_id
                   AND property_name = :property AND room_number = :room ORDER BY item_name'
                    . ($forUpdate ? ' FOR UPDATE' : ''),
                [
                    'interval_id' => $request['intervalId'] ?? 0,
                    'list_id' => $request['listId'] ?? 0,
                    'property' => $request['property'] ?? '',
                    'room' => $request['room'] ?? 0,
                ]
            ),
        ];
    }

    private function checklistSnapshot(array $request, bool $forUpdate): array
    {
        return [
            'items' => $this->listItemNames((int) ($request['listId'] ?? 0), $forUpdate),
            'rows' => $this->rows(
                'SELECT item_name, problem, problem_en, status FROM room_checklist_values
                 WHERE list_id = :list_id AND property_name = :property AND room_number = :room
                 ORDER BY item_name' . ($forUpdate ? ' FOR UPDATE' : ''),
                [
                    'list_id' => $request['listId'] ?? 0,
                    'property' => $request['property'] ?? '',
                    'room' => $request['room'] ?? 0,
                ]
            ),
        ];
    }

    private function listItemNames(int $listId, bool $forUpdate): array
    {
        return $this->rows(
            'SELECT id, name, name_en, default_instructions, default_instructions_en
             FROM item_list_items WHERE list_id = :list_id ORDER BY sort_order, id'
                . ($forUpdate ? ' FOR UPDATE' : ''),
            ['list_id' => $listId]
        );
    }

    /** @return array<int,array<string,mixed>> */
    private function rows(string $sql, array $parameters): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);
        return $statement->fetchAll();
    }

    public static function stateHash(array $value): string
    {
        return hash('sha256', json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ));
    }

    private function engineKey(): string
    {
        $value = trim((string) ($this->config['engine_key'] ?? 'google-basic-nmt-v2'));
        return $value !== '' ? $value : 'google-basic-nmt-v2';
    }

    private static function isMissingQueueTable(PDOException $exception): bool
    {
        $errorInfo = $exception->errorInfo;
        return (string) $exception->getCode() === '42S02'
            || (is_array($errorInfo) && (int) ($errorInfo[1] ?? 0) === 1146);
    }
}
