<?php
declare(strict_types=1);

require_once __DIR__ . '/ContentTranslator.php';
require_once __DIR__ . '/PendingTranslationQueue.php';
require_once __DIR__ . '/TranslationServiceException.php';
require_once dirname(__DIR__) . '/Auth/Auth.php';

final class PendingTranslationConflictException extends RuntimeException {}

final class PendingTranslationProcessor
{
    private const DEFAULT_BATCH_SIZE = 10;
    private const DEFAULT_MAX_ATTEMPTS = 5;
    private int $activeJobId = 0;
    private int $activeGeneration = 0;
    private int $activeActorId = 0;

    public function __construct(private PDO $pdo, private array $config) {}

    /** @return array{processed:int,completed:int,rescheduled:int,failed:int,superseded:int} */
    public function run(): array
    {
        $summary = ['processed' => 0, 'completed' => 0, 'rescheduled' => 0, 'failed' => 0, 'superseded' => 0];
        if (($this->config['pending_enabled'] ?? true) !== true) {
            return $summary;
        }

        $this->recoverAbandonedJobs();
        foreach ($this->dueJobIds() as $id) {
            $job = $this->claim($id);
            if ($job === null) {
                continue;
            }
            $summary['processed']++;
            try {
                $this->process($job);
                $summary['completed']++;
            } catch (PendingTranslationConflictException $exception) {
                $this->finish($id, 'superseded', $exception->getMessage());
                $summary['superseded']++;
            } catch (TranslationQuotaExceededException $exception) {
                $this->reschedule($id, $exception->resetUtc(), $exception->getMessage());
                $summary['rescheduled']++;
                break;
            } catch (TranslationServiceException $exception) {
                if (!$exception->retryable() || (int) $job['attempt_count'] >= $this->maxAttempts()) {
                    $this->finish($id, 'failed', $exception->getMessage());
                    $summary['failed']++;
                } else {
                    $this->retryLater($id, $this->retryDelayMinutes((int) $job['attempt_count']), $exception->getMessage());
                    $summary['rescheduled']++;
                }
            } catch (InvalidArgumentException $exception) {
                $this->finish($id, 'failed', $exception->getMessage());
                $summary['failed']++;
            } catch (PDOException $exception) {
                if ((string) $exception->getCode() === '23000'
                    || (int) $job['attempt_count'] >= $this->maxAttempts()) {
                    $this->finish($id, 'failed', $exception->getMessage());
                    $summary['failed']++;
                } else {
                    $this->retryLater($id, $this->retryDelayMinutes((int) $job['attempt_count']), $exception->getMessage());
                    $summary['rescheduled']++;
                }
            } catch (Throwable $exception) {
                if ((int) $job['attempt_count'] >= $this->maxAttempts()) {
                    $this->finish($id, 'failed', $exception->getMessage());
                    $summary['failed']++;
                } else {
                    $this->retryLater($id, $this->retryDelayMinutes((int) $job['attempt_count']), $exception->getMessage());
                    $summary['rescheduled']++;
                }
            }
        }
        return $summary;
    }

    /** @param array<string,mixed> $job */
    private function process(array $job): void
    {
        $this->activeJobId = (int) $job['id'];
        $this->activeGeneration = (int) $job['generation'];
        $this->activeActorId = (int) ($job['created_by_user_id'] ?? 0);
        $payload = json_decode((string) $job['payload_json'], true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($payload)) {
            throw new InvalidArgumentException('Conteúdo da alteração pendente inválido.');
        }
        $operation = (string) $job['operation_type'];
        $source = (string) $job['source_language'] === 'en' ? 'en' : 'pt';
        $actorId = (int) ($job['created_by_user_id'] ?? 0);

        $this->assertExpectedState($operation, $payload, false);
        $this->requireActorPermission($actorId, $operation);
        $translator = new ContentTranslator($this->pdo, $this->config);

        switch ($operation) {
            case 'api:save_item_list_instructions':
                $this->saveItemListInstructions($translator, $payload, $source, $actorId);
                break;
            case 'api:create_interval':
                $this->createInterval($translator, $payload, $source, $actorId);
                break;
            case 'api:update_interval':
                $this->updateInterval($translator, $payload, $source, $actorId);
                break;
            case 'api:save_checklist':
                $this->saveChecklist($translator, $payload, $source, $actorId);
                break;
            case 'api:set_assignments_atomic':
                $this->saveAssignments($translator, $payload, $source, $actorId, true);
                break;
            case 'api:assign_items':
                $this->saveAssignments($translator, $payload, $source, $actorId, false);
                break;
            case 'item_lists:create_list':
                $this->createList($translator, $payload, $source, $actorId);
                break;
            case 'item_lists:rename_list':
                $this->renameList($translator, $payload, $source, $actorId);
                break;
            case 'item_lists:add_item':
                $this->addItem($translator, $payload, $source, $actorId);
                break;
            case 'item_lists:rename_item':
                $this->renameItem($translator, $payload, $source, $actorId);
                break;
            case 'verification_categories:create_category':
                $this->createCategory($translator, $payload, $source, $actorId);
                break;
            case 'verification_categories:rename_category':
                $this->renameCategory($translator, $payload, $source, $actorId);
                break;
            default:
                throw new InvalidArgumentException('Tipo de alteração pendente não suportado.');
        }
    }

    private function saveItemListInstructions(
        ContentTranslator $translator,
        array $payload,
        string $source,
        int $actorId
    ): void {
        $listId = (int) ($payload['listId'] ?? 0);
        $itemId = (int) ($payload['itemId'] ?? 0);
        $text = trim((string) ($payload['text'] ?? ''));
        $row = $this->one(
            'SELECT default_instructions, default_instructions_en FROM item_list_items
             WHERE id = :id AND list_id = :list_id',
            ['id' => $itemId, 'list_id' => $listId]
        );
        $versions = $translator->versions(
            $text,
            $source,
            (string) ($row['default_instructions'] ?? ''),
            (string) ($row['default_instructions_en'] ?? '')
        );

        $this->pdo->beginTransaction();
        try {
            $this->assertExpectedState('api:save_item_list_instructions', $payload, true);
            $statement = $this->pdo->prepare(
                'UPDATE item_list_items SET default_instructions = :pt, default_instructions_en = :en
                 WHERE id = :id AND list_id = :list_id'
            );
            $statement->execute(['pt' => $versions['pt'], 'en' => $versions['en'], 'id' => $itemId, 'list_id' => $listId]);
            $this->audit($actorId, 'pending_item_list_instructions_applied', ['list_id' => $listId, 'item_id' => $itemId]);
            $this->completeActiveClaim();
            $this->pdo->commit();
        } catch (Throwable $exception) {
            $this->rollback();
            throw $exception;
        }
    }

    private function createInterval(ContentTranslator $translator, array $payload, string $source, int $actorId): void
    {
        $name = trim((string) ($payload['name'] ?? ''));
        $startDate = (string) ($payload['startDate'] ?? '');
        $endDate = (string) ($payload['endDate'] ?? '');
        $this->validateName($name, 120, 'Nome do intervalo inválido.');
        $this->validateDateRange($startDate, $endDate);
        $versions = $translator->versions($name, $source);
        $this->pdo->beginTransaction();
        try {
            $this->assertActiveClaim();
            $statement = $this->pdo->prepare(
                'INSERT INTO room_verification_intervals (name, name_en, start_date, end_date, created_by_user_id)
                 VALUES (:pt, :en, :start_date, :end_date, :actor)'
            );
            $statement->execute([
                'pt' => $versions['pt'], 'en' => $versions['en'], 'start_date' => $startDate,
                'end_date' => $endDate, 'actor' => $this->requireActor($actorId),
            ]);
            $this->audit($actorId, 'pending_verification_interval_created', ['interval_id' => (int) $this->pdo->lastInsertId()]);
            $this->completeActiveClaim();
            $this->pdo->commit();
        } catch (Throwable $exception) {
            $this->rollback();
            throw $exception;
        }
    }

    private function updateInterval(ContentTranslator $translator, array $payload, string $source, int $actorId): void
    {
        $id = (int) ($payload['intervalId'] ?? 0);
        $name = trim((string) ($payload['name'] ?? ''));
        $startDate = (string) ($payload['startDate'] ?? '');
        $endDate = (string) ($payload['endDate'] ?? '');
        $this->validateName($name, 120, 'Nome do intervalo inválido.');
        $this->validateDateRange($startDate, $endDate);
        $row = $this->one('SELECT name, name_en FROM room_verification_intervals WHERE id = :id', ['id' => $id]);
        $versions = $translator->versions($name, $source, (string) $row['name'], (string) ($row['name_en'] ?? ''));

        $this->pdo->beginTransaction();
        try {
            $this->assertExpectedState('api:update_interval', $payload, true);
            $bounds = $this->one(
                'SELECT MIN(due_date) AS first_due_date, MAX(due_date) AS last_due_date, COUNT(*) AS assignment_count
                 FROM room_item_assignments WHERE interval_id = :id',
                ['id' => $id],
                false
            );
            if ((int) ($bounds['assignment_count'] ?? 0) > 0
                && ($startDate > (string) $bounds['first_due_date'] || $endDate < (string) $bounds['last_due_date'])) {
                throw new InvalidArgumentException('As novas datas do intervalo não incluem todas as atribuições existentes.');
            }
            $statement = $this->pdo->prepare(
                'UPDATE room_verification_intervals
                 SET name = :pt, name_en = :en, start_date = :start_date, end_date = :end_date WHERE id = :id'
            );
            $statement->execute([
                'pt' => $versions['pt'], 'en' => $versions['en'], 'start_date' => $startDate,
                'end_date' => $endDate, 'id' => $id,
            ]);
            $this->audit($actorId, 'pending_verification_interval_updated', ['interval_id' => $id]);
            $this->completeActiveClaim();
            $this->pdo->commit();
        } catch (Throwable $exception) {
            $this->rollback();
            throw $exception;
        }
    }

    private function createList(ContentTranslator $translator, array $payload, string $source, int $actorId): void
    {
        $name = trim((string) ($payload['name'] ?? ''));
        $area = trim((string) ($payload['area'] ?? ''));
        $this->validateName($name, 120, 'Nome da lista inválido.');
        $this->requireCategory($area);
        $versions = $translator->versions($name, $source);
        $this->pdo->beginTransaction();
        try {
            $this->assertActiveClaim();
            $this->requireCategory($area, true);
            $statement = $this->pdo->prepare(
                'INSERT INTO item_lists (name, name_en, area, created_by_user_id) VALUES (:pt, :en, :area, :actor)'
            );
            $statement->execute(['pt' => $versions['pt'], 'en' => $versions['en'], 'area' => $area, 'actor' => $this->requireActor($actorId)]);
            $this->audit($actorId, 'pending_item_list_created', ['list_id' => (int) $this->pdo->lastInsertId()]);
            $this->completeActiveClaim();
            $this->pdo->commit();
        } catch (Throwable $exception) {
            $this->rollback();
            throw $exception;
        }
    }

    private function renameList(ContentTranslator $translator, array $payload, string $source, int $actorId): void
    {
        $id = (int) ($payload['list_id'] ?? 0);
        $name = trim((string) ($payload['name'] ?? ''));
        $area = trim((string) ($payload['area'] ?? ''));
        $this->validateName($name, 120, 'Nome da lista inválido.');
        $this->requireCategory($area);
        $row = $this->one('SELECT name, name_en FROM item_lists WHERE id = :id', ['id' => $id]);
        $versions = $translator->versions($name, $source, (string) $row['name'], (string) ($row['name_en'] ?? ''));

        $this->pdo->beginTransaction();
        try {
            $this->assertExpectedState('item_lists:rename_list', $payload, true);
            $this->requireCategory($area, true);
            $statement = $this->pdo->prepare('UPDATE item_lists SET name = :pt, name_en = :en, area = :area WHERE id = :id');
            $statement->execute(['pt' => $versions['pt'], 'en' => $versions['en'], 'area' => $area, 'id' => $id]);
            $this->audit($actorId, 'pending_item_list_updated', ['list_id' => $id]);
            $this->completeActiveClaim();
            $this->pdo->commit();
        } catch (Throwable $exception) {
            $this->rollback();
            throw $exception;
        }
    }

    private function addItem(ContentTranslator $translator, array $payload, string $source, int $actorId): void
    {
        $listId = (int) ($payload['list_id'] ?? 0);
        $name = trim((string) ($payload['name'] ?? ''));
        $instructions = trim((string) ($payload['default_instructions'] ?? ''));
        $this->validateName($name, 80, 'Nome do item inválido.');
        $this->validateText($instructions);
        $this->one('SELECT id FROM item_lists WHERE id = :id', ['id' => $listId]);
        $nameVersions = $translator->versions($name, $source);
        $instructionVersions = $translator->versions($instructions, $source);

        $this->pdo->beginTransaction();
        try {
            $this->assertActiveClaim();
            $this->one('SELECT id FROM item_lists WHERE id = :id FOR UPDATE', ['id' => $listId]);
            $position = $this->pdo->prepare('SELECT COALESCE(MAX(sort_order), 0) + 10 FROM item_list_items WHERE list_id = :list_id');
            $position->execute(['list_id' => $listId]);
            $statement = $this->pdo->prepare(
                'INSERT INTO item_list_items
                    (list_id, name, name_en, default_instructions, default_instructions_en, sort_order)
                 VALUES (:list_id, :name_pt, :name_en, :instructions_pt, :instructions_en, :sort_order)'
            );
            $statement->execute([
                'list_id' => $listId, 'name_pt' => $nameVersions['pt'], 'name_en' => $nameVersions['en'],
                'instructions_pt' => $instructionVersions['pt'], 'instructions_en' => $instructionVersions['en'],
                'sort_order' => (int) $position->fetchColumn(),
            ]);
            $this->audit($actorId, 'pending_item_list_item_created', ['list_id' => $listId, 'item_id' => (int) $this->pdo->lastInsertId()]);
            $this->completeActiveClaim();
            $this->pdo->commit();
        } catch (Throwable $exception) {
            $this->rollback();
            throw $exception;
        }
    }

    private function renameItem(ContentTranslator $translator, array $payload, string $source, int $actorId): void
    {
        $listId = (int) ($payload['list_id'] ?? 0);
        $itemId = (int) ($payload['item_id'] ?? 0);
        $name = trim((string) ($payload['name'] ?? ''));
        $instructions = trim((string) ($payload['default_instructions'] ?? ''));
        $this->validateName($name, 80, 'Nome do item inválido.');
        $this->validateText($instructions);
        $row = $this->one(
            'SELECT name, name_en, default_instructions, default_instructions_en
             FROM item_list_items WHERE id = :id AND list_id = :list_id',
            ['id' => $itemId, 'list_id' => $listId]
        );
        $nameVersions = $translator->versions($name, $source, (string) $row['name'], (string) ($row['name_en'] ?? ''));
        $instructionVersions = $translator->versions(
            $instructions,
            $source,
            (string) $row['default_instructions'],
            (string) ($row['default_instructions_en'] ?? '')
        );

        $this->pdo->beginTransaction();
        try {
            $this->assertExpectedState('item_lists:rename_item', $payload, true);
            $oldName = (string) $row['name'];
            $newName = (string) $nameVersions['pt'];
            $this->pdo->prepare('UPDATE room_checklist_values SET item_name = :new WHERE list_id = :list AND item_name = :old')
                ->execute(['new' => $newName, 'list' => $listId, 'old' => $oldName]);
            $this->pdo->prepare('UPDATE room_item_assignments SET item_name = :new WHERE list_id = :list AND item_name = :old')
                ->execute(['new' => $newName, 'list' => $listId, 'old' => $oldName]);
            $statement = $this->pdo->prepare(
                'UPDATE item_list_items SET name = :name_pt, name_en = :name_en,
                 default_instructions = :instructions_pt, default_instructions_en = :instructions_en WHERE id = :id'
            );
            $statement->execute([
                'name_pt' => $newName, 'name_en' => $nameVersions['en'],
                'instructions_pt' => $instructionVersions['pt'], 'instructions_en' => $instructionVersions['en'],
                'id' => $itemId,
            ]);
            $this->audit($actorId, 'pending_item_list_item_updated', ['list_id' => $listId, 'item_id' => $itemId]);
            $this->completeActiveClaim();
            $this->pdo->commit();
        } catch (Throwable $exception) {
            $this->rollback();
            throw $exception;
        }
    }

    private function createCategory(ContentTranslator $translator, array $payload, string $source, int $actorId): void
    {
        $name = trim((string) ($payload['name'] ?? ''));
        $this->validateName($name, 80, 'Nome da área inválido.');
        $versions = $translator->versions($name, $source);
        $this->pdo->beginTransaction();
        try {
            $this->assertActiveClaim();
            $position = (int) $this->pdo->query('SELECT COALESCE(MAX(sort_order), 0) + 10 FROM verification_categories')->fetchColumn();
            $statement = $this->pdo->prepare(
                'INSERT INTO verification_categories (slug, name, name_en, sort_order, created_by_user_id)
                 VALUES (:slug, :pt, :en, :sort_order, :actor)'
            );
            $statement->execute([
                'slug' => 'category-' . bin2hex(random_bytes(8)), 'pt' => $versions['pt'], 'en' => $versions['en'],
                'sort_order' => $position, 'actor' => $this->requireActor($actorId),
            ]);
            $this->audit($actorId, 'pending_verification_category_created', ['category_id' => (int) $this->pdo->lastInsertId()]);
            $this->completeActiveClaim();
            $this->pdo->commit();
        } catch (Throwable $exception) {
            $this->rollback();
            throw $exception;
        }
    }

    private function renameCategory(ContentTranslator $translator, array $payload, string $source, int $actorId): void
    {
        $id = (int) ($payload['category_id'] ?? 0);
        $name = trim((string) ($payload['name'] ?? ''));
        $this->validateName($name, 80, 'Nome da área inválido.');
        $row = $this->one('SELECT name, name_en FROM verification_categories WHERE id = :id', ['id' => $id]);
        $versions = $translator->versions($name, $source, (string) $row['name'], (string) ($row['name_en'] ?? ''));
        $this->pdo->beginTransaction();
        try {
            $this->assertExpectedState('verification_categories:rename_category', $payload, true);
            $this->pdo->prepare('UPDATE verification_categories SET name = :pt, name_en = :en WHERE id = :id')
                ->execute(['pt' => $versions['pt'], 'en' => $versions['en'], 'id' => $id]);
            $this->audit($actorId, 'pending_verification_category_updated', ['category_id' => $id]);
            $this->completeActiveClaim();
            $this->pdo->commit();
        } catch (Throwable $exception) {
            $this->rollback();
            throw $exception;
        }
    }

    private function saveChecklist(ContentTranslator $translator, array $payload, string $source, int $actorId): void
    {
        $property = trim((string) ($payload['property'] ?? ''));
        $room = (int) ($payload['room'] ?? 0);
        $listId = (int) ($payload['listId'] ?? 0);
        $this->validateRoom($property, $room);
        $items = $this->listItems($listId);
        $allowed = array_fill_keys(array_column($items, 'name'), true);
        $existingRows = $this->checklistRows($listId, $property, $room, false);
        $existing = [];
        foreach ($existingRows as $row) {
            $existing[(string) $row['item_name']] = $row;
        }
        $defaults = [];
        foreach ($items as $item) {
            $defaults[(string) $item['name']] = $source === 'en'
                ? ((string) ($item['default_instructions_en'] ?? '') ?: (string) $item['default_instructions'])
                : ((string) $item['default_instructions'] ?: (string) ($item['default_instructions_en'] ?? ''));
        }

        $normalized = [];
        foreach (($payload['items'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $name = trim((string) ($item['name'] ?? ''));
            if (!isset($allowed[$name])) {
                continue;
            }
            $problem = trim((string) ($item['problem'] ?? ''));
            $this->validateText($problem);
            $row = $existing[$name] ?? [];
            $existingPt = trim((string) ($row['problem'] ?? ''));
            $existingEn = trim((string) ($row['problem_en'] ?? ''));
            if ($existingPt === '' && $existingEn === '' && $problem === trim($defaults[$name] ?? '')) {
                $problem = '';
            }
            $versions = $translator->versions($problem, $source, $existingPt, $existingEn);
            $normalized[$name] = [
                'pt' => $versions['pt'],
                'en' => $versions['en'],
                'status' => in_array($item['status'] ?? null, ['wrong', 'ok'], true) ? $item['status'] : null,
            ];
        }

        $this->pdo->beginTransaction();
        try {
            $this->assertExpectedState('api:save_checklist', $payload, true);
            $statement = $this->pdo->prepare(
                'INSERT INTO room_checklist_values
                    (property_name, room_number, list_id, item_name, problem, problem_en, status)
                 VALUES (:property, :room, :list_id, :item, :pt, :en, :status)
                 ON DUPLICATE KEY UPDATE problem = VALUES(problem), problem_en = VALUES(problem_en),
                    status = VALUES(status), updated_at = CURRENT_TIMESTAMP'
            );
            foreach ($items as $item) {
                $name = (string) $item['name'];
                $value = $normalized[$name] ?? ['pt' => '', 'en' => '', 'status' => null];
                $statement->execute([
                    'property' => $property, 'room' => $room, 'list_id' => $listId, 'item' => $name,
                    'pt' => $value['pt'], 'en' => $value['en'], 'status' => $value['status'],
                ]);
            }
            $this->audit($actorId, 'pending_room_checklist_applied', ['list_id' => $listId, 'property' => $property, 'room' => $room]);
            $this->completeActiveClaim();
            $this->pdo->commit();
        } catch (Throwable $exception) {
            $this->rollback();
            throw $exception;
        }
    }

    private function saveAssignments(
        ContentTranslator $translator,
        array $payload,
        string $source,
        int $actorId,
        bool $atomicChanges
    ): void {
        $property = trim((string) ($payload['property'] ?? ''));
        $room = (int) ($payload['room'] ?? 0);
        $intervalId = (int) ($payload['intervalId'] ?? 0);
        $listId = (int) ($payload['listId'] ?? 0);
        $employeeId = (int) ($payload['employeeId'] ?? 0);
        $dueDate = trim((string) ($payload['dueDate'] ?? ''));
        $this->validateRoom($property, $room);
        $this->validateDate($dueDate);
        $items = $this->listItems($listId);
        $itemNames = array_column($items, 'name');
        $allowed = array_fill_keys($itemNames, true);
        $currentRows = $this->assignmentRows($intervalId, $listId, $property, $room, false);
        $current = [];
        foreach ($currentRows as $row) {
            $current[(string) $row['item_name']] = $row;
        }

        $changes = [];
        if ($atomicChanges) {
            foreach (($payload['changes'] ?? []) as $change) {
                if (!is_array($change)) {
                    throw new InvalidArgumentException('Alteração de atribuição inválida.');
                }
                $name = trim((string) ($change['itemName'] ?? ''));
                if (!isset($allowed[$name]) || isset($changes[$name])) {
                    throw new InvalidArgumentException('Item de atribuição inválido ou repetido.');
                }
                $changes[$name] = [
                    'selected' => (bool) ($change['selected'] ?? false),
                    'instructions' => trim((string) ($change['instructions'] ?? '')),
                ];
            }
        } else {
            $selected = array_fill_keys(array_values(array_intersect(
                $itemNames,
                array_map('strval', is_array($payload['selectedItems'] ?? null) ? $payload['selectedItems'] : [])
            )), true);
            $instructions = is_array($payload['instructions'] ?? null) ? $payload['instructions'] : [];
            foreach ($itemNames as $name) {
                $changes[$name] = [
                    'selected' => isset($selected[$name]),
                    'instructions' => trim((string) ($instructions[$name] ?? '')),
                ];
            }
        }

        foreach ($changes as $name => &$change) {
            if (!$change['selected']) {
                $change['versions'] = ['pt' => '', 'en' => ''];
                continue;
            }
            $this->validateText((string) $change['instructions']);
            $row = $current[$name] ?? [];
            $change['versions'] = $translator->versions(
                (string) $change['instructions'],
                $source,
                (string) ($row['verification_instructions'] ?? ''),
                (string) ($row['verification_instructions_en'] ?? '')
            );
        }
        unset($change);

        $this->pdo->beginTransaction();
        try {
            // Match the live API lock order (interval, employee, assignments)
            // so the background worker does not introduce a reverse lock cycle.
            $this->assertActiveClaim();
            $interval = $this->one(
                'SELECT start_date, end_date FROM room_verification_intervals WHERE id = :id FOR UPDATE',
                ['id' => $intervalId]
            );
            if ($dueDate < (string) $interval['start_date'] || $dueDate > (string) $interval['end_date']) {
                throw new InvalidArgumentException('A data da verificação já não pertence ao intervalo escolhido.');
            }
            $employee = $this->one(
                "SELECT id FROM users WHERE id = :id AND role = 'empregada_andares' AND is_active = 1 FOR UPDATE",
                ['id' => $employeeId]
            );
            if ((int) ($employee['id'] ?? 0) < 1) {
                throw new InvalidArgumentException('A empregada selecionada já não está disponível.');
            }
            $this->assertExpectedState(
                $atomicChanges ? 'api:set_assignments_atomic' : 'api:assign_items',
                $payload,
                true
            );

            $upsert = $this->pdo->prepare(
                'INSERT INTO room_item_assignments
                    (interval_id, list_id, property_name, room_number, item_name, assigned_to_user_id,
                     assigned_by_user_id, due_date, verification_instructions, verification_instructions_en,
                     completed_at, completed_by_user_id)
                 VALUES (:interval_id, :list_id, :property, :room, :item, :assignee, :assigner, :due_date,
                         :instructions, :instructions_en, NULL, NULL)
                 ON DUPLICATE KEY UPDATE assigned_to_user_id = VALUES(assigned_to_user_id),
                    assigned_by_user_id = VALUES(assigned_by_user_id), assigned_at = CURRENT_TIMESTAMP,
                    due_date = VALUES(due_date), verification_instructions = VALUES(verification_instructions),
                    verification_instructions_en = VALUES(verification_instructions_en),
                    completed_at = NULL, completed_by_user_id = NULL'
            );
            $remove = $this->pdo->prepare(
                'DELETE FROM room_item_assignments WHERE interval_id = :interval_id AND list_id = :list_id
                 AND property_name = :property AND room_number = :room AND item_name = :item
                 AND assigned_to_user_id = :assignee AND due_date = :due_date AND completed_at IS NULL'
            );
            foreach ($changes as $name => $change) {
                $existingAssignment = $current[$name] ?? null;
                $mustMatchCurrentOwner = $atomicChanges || $change['selected'];
                if ($mustMatchCurrentOwner && is_array($existingAssignment)
                    && ((int) $existingAssignment['assigned_to_user_id'] !== $employeeId
                        || (string) $existingAssignment['due_date'] !== $dueDate
                        || $existingAssignment['completed_at'] !== null)) {
                    throw new InvalidArgumentException(
                        "O item {$name} já está atribuído ou concluído noutra data deste intervalo."
                    );
                }
                $identity = [
                    'interval_id' => $intervalId, 'list_id' => $listId, 'property' => $property,
                    'room' => $room, 'item' => $name, 'assignee' => $employeeId,
                ];
                if ($change['selected']) {
                    $versions = $change['versions'];
                    $upsert->execute($identity + [
                        'assigner' => $this->requireActor($actorId), 'due_date' => $dueDate,
                        'instructions' => $versions['pt'], 'instructions_en' => $versions['en'],
                    ]);
                } else {
                    $remove->execute($identity + ['due_date' => $dueDate]);
                }
            }
            $this->audit($actorId, 'pending_room_assignments_applied', [
                'interval_id' => $intervalId, 'list_id' => $listId, 'property' => $property,
                'room' => $room, 'assignee' => $employeeId, 'due_date' => $dueDate,
            ]);
            $this->completeActiveClaim();
            $this->pdo->commit();
        } catch (Throwable $exception) {
            $this->rollback();
            throw $exception;
        }
    }

    private function assertExpectedState(string $operation, array $payload, bool $forUpdate): void
    {
        $expected = $payload['_expected'] ?? null;
        if (!is_string($expected) || $expected === '') {
            return;
        }
        if ($forUpdate) {
            $this->assertActiveClaim();
        }
        $current = $this->snapshot($operation, $payload, $forUpdate);
        if ($current === null || !hash_equals($expected, $current)) {
            throw new PendingTranslationConflictException(
                'Alteração pendente ignorada porque o conteúdo foi alterado entretanto.'
            );
        }
    }

    private function assertActiveClaim(): void
    {
        if (!$this->pdo->inTransaction() || $this->activeJobId < 1 || $this->activeGeneration < 1) {
            throw new RuntimeException('O trabalho pendente não está protegido por uma transação.');
        }
        $statement = $this->pdo->prepare(
            "SELECT id FROM translation_pending_jobs
             WHERE id = :id AND generation = :generation AND status = 'processing' FOR UPDATE"
        );
        $statement->execute(['id' => $this->activeJobId, 'generation' => $this->activeGeneration]);
        if ($statement->fetchColumn() === false) {
            throw new PendingTranslationConflictException(
                'Alteração pendente substituída por uma edição mais recente.'
            );
        }
        $actor = $this->pdo->prepare('SELECT id FROM users WHERE id = :id AND is_active = 1 FOR UPDATE');
        $actor->execute(['id' => $this->activeActorId]);
        if ($actor->fetchColumn() === false) {
            throw new InvalidArgumentException('O utilizador que criou a alteração pendente já não está ativo.');
        }
    }

    private function completeActiveClaim(): void
    {
        if (!$this->pdo->inTransaction()) {
            throw new RuntimeException('A conclusão do trabalho pendente exige uma transação.');
        }
        $statement = $this->pdo->prepare(
            "UPDATE translation_pending_jobs SET status = 'completed', completed_at = UTC_TIMESTAMP(),
             locked_at = NULL, last_error = NULL
             WHERE id = :id AND generation = :generation AND status = 'processing'"
        );
        $statement->execute(['id' => $this->activeJobId, 'generation' => $this->activeGeneration]);
        if ($statement->rowCount() !== 1) {
            throw new PendingTranslationConflictException(
                'Alteração pendente substituída antes de ser concluída.'
            );
        }
    }

    private function snapshot(string $operation, array $payload, bool $forUpdate): ?string
    {
        $suffix = $forUpdate ? ' FOR UPDATE' : '';
        $rows = match ($operation) {
            'api:save_item_list_instructions' => $this->rows(
                'SELECT id, list_id, default_instructions, default_instructions_en
                 FROM item_list_items WHERE id = :id AND list_id = :list_id' . $suffix,
                ['id' => $payload['itemId'] ?? 0, 'list_id' => $payload['listId'] ?? 0]
            ),
            'api:update_interval' => $this->rows(
                'SELECT id, name, name_en, start_date, end_date FROM room_verification_intervals WHERE id = :id' . $suffix,
                ['id' => $payload['intervalId'] ?? 0]
            ),
            'api:set_assignments_atomic', 'api:assign_items' => [
                'items' => $this->listItemNameRows((int) ($payload['listId'] ?? 0), $forUpdate),
                'rows' => $this->assignmentRows(
                    (int) ($payload['intervalId'] ?? 0), (int) ($payload['listId'] ?? 0),
                    (string) ($payload['property'] ?? ''), (int) ($payload['room'] ?? 0), $forUpdate
                ),
            ],
            'api:save_checklist' => [
                'items' => $this->listItemNameRows((int) ($payload['listId'] ?? 0), $forUpdate),
                'rows' => $this->checklistRows(
                    (int) ($payload['listId'] ?? 0), (string) ($payload['property'] ?? ''),
                    (int) ($payload['room'] ?? 0), $forUpdate
                ),
            ],
            'item_lists:rename_list' => $this->rows(
                'SELECT id, name, name_en, area FROM item_lists WHERE id = :id' . $suffix,
                ['id' => $payload['list_id'] ?? 0]
            ),
            'item_lists:rename_item' => $this->rows(
                'SELECT id, list_id, name, name_en, default_instructions, default_instructions_en
                 FROM item_list_items WHERE id = :id AND list_id = :list_id' . $suffix,
                ['id' => $payload['item_id'] ?? 0, 'list_id' => $payload['list_id'] ?? 0]
            ),
            'verification_categories:rename_category' => $this->rows(
                'SELECT id, name, name_en FROM verification_categories WHERE id = :id' . $suffix,
                ['id' => $payload['category_id'] ?? 0]
            ),
            default => null,
        };
        return $rows === null ? null : PendingTranslationQueue::stateHash($rows);
    }

    private function checklistRows(int $listId, string $property, int $room, bool $forUpdate): array
    {
        return $this->rows(
            'SELECT item_name, problem, problem_en, status FROM room_checklist_values
             WHERE list_id = :list_id AND property_name = :property AND room_number = :room
             ORDER BY item_name' . ($forUpdate ? ' FOR UPDATE' : ''),
            ['list_id' => $listId, 'property' => $property, 'room' => $room]
        );
    }

    private function assignmentRows(int $intervalId, int $listId, string $property, int $room, bool $forUpdate): array
    {
        return $this->rows(
            'SELECT item_name, assigned_to_user_id, assigned_by_user_id, due_date,
                    verification_instructions, verification_instructions_en, completed_at
             FROM room_item_assignments
             WHERE interval_id = :interval_id AND list_id = :list_id
               AND property_name = :property AND room_number = :room
             ORDER BY item_name' . ($forUpdate ? ' FOR UPDATE' : ''),
            ['interval_id' => $intervalId, 'list_id' => $listId, 'property' => $property, 'room' => $room]
        );
    }

    private function listItems(int $listId): array
    {
        $rows = $this->rows(
            'SELECT name, name_en, default_instructions, default_instructions_en
             FROM item_list_items WHERE list_id = :list_id ORDER BY sort_order, id',
            ['list_id' => $listId]
        );
        if ($rows === []) {
            throw new InvalidArgumentException('A lista já não existe ou não contém itens.');
        }
        return $rows;
    }

    private function listItemNameRows(int $listId, bool $forUpdate): array
    {
        return $this->rows(
            'SELECT id, name, name_en, default_instructions, default_instructions_en
             FROM item_list_items WHERE list_id = :list_id ORDER BY sort_order, id'
                . ($forUpdate ? ' FOR UPDATE' : ''),
            ['list_id' => $listId]
        );
    }

    private function requireCategory(string $slug, bool $forUpdate = false): void
    {
        if ($slug === '') {
            throw new InvalidArgumentException('Área inválida.');
        }
        $this->one(
            'SELECT id FROM verification_categories WHERE slug = :slug' . ($forUpdate ? ' FOR UPDATE' : ''),
            ['slug' => $slug]
        );
    }

    private function validateRoom(string $property, int $room): void
    {
        if (!defined('PROPERTIES') || !isset(PROPERTIES[$property]) || $room < 1 || $room > PROPERTIES[$property]) {
            throw new InvalidArgumentException('Quarto ou alojamento inválido.');
        }
    }

    private function validateName(string $name, int $limit, string $message): void
    {
        if ($name === '' || mb_strlen($name, 'UTF-8') > $limit) {
            throw new InvalidArgumentException($message);
        }
    }

    private function validateText(string $text): void
    {
        if (mb_strlen($text, 'UTF-8') > 5000) {
            throw new InvalidArgumentException('O texto pendente ultrapassa 5000 caracteres.');
        }
    }

    private function validateDateRange(string $startDate, string $endDate): void
    {
        $this->validateDate($startDate);
        $this->validateDate($endDate);
        if ($endDate < $startDate) {
            throw new InvalidArgumentException('A data final não pode ser anterior à data inicial.');
        }
    }

    private function validateDate(string $date): void
    {
        $parts = array_map('intval', explode('-', $date));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)
            || count($parts) !== 3 || !checkdate($parts[1], $parts[2], $parts[0])) {
            throw new InvalidArgumentException('Data pendente inválida.');
        }
    }

    private function requireActor(int $actorId): int
    {
        if ($actorId < 1) {
            throw new InvalidArgumentException('Utilizador da alteração pendente inválido.');
        }
        $statement = $this->pdo->prepare('SELECT id FROM users WHERE id = :id AND is_active = 1');
        $statement->execute(['id' => $actorId]);
        if ($statement->fetchColumn() === false) {
            throw new InvalidArgumentException('O utilizador que criou a alteração pendente já não está ativo.');
        }
        return $actorId;
    }

    private function requireActorPermission(int $actorId, string $operation): void
    {
        $permission = match ($operation) {
            'api:save_checklist' => Auth::PERMISSION_ROOM_CHECK_EDIT,
            'verification_categories:create_category',
            'verification_categories:rename_category' => Auth::PERMISSION_VERIFICATION_CATEGORIES_MANAGE,
            'api:save_item_list_instructions',
            'api:create_interval',
            'api:update_interval',
            'api:set_assignments_atomic',
            'api:assign_items',
            'item_lists:create_list',
            'item_lists:rename_list',
            'item_lists:add_item',
            'item_lists:rename_item' => Auth::PERMISSION_TASK_ASSIGN,
            default => null,
        };
        if ($permission === null) {
            throw new InvalidArgumentException('Operação pendente sem permissão associada.');
        }
        $statement = $this->pdo->prepare(
            'SELECT id, role, is_active FROM users WHERE id = :id AND is_active = 1'
        );
        $statement->execute(['id' => $actorId]);
        $actor = $statement->fetch();
        if (!is_array($actor) || !Auth::hasPermission($this->pdo, $actor, $permission)) {
            throw new InvalidArgumentException(
                'O utilizador já não tem permissão para aplicar a alteração pendente.'
            );
        }
    }

    private function audit(int $actorId, string $action, array $details): void
    {
        Auth::audit($this->pdo, $actorId > 0 ? $actorId : null, $action, $details);
    }

    /** @return array<string,mixed> */
    private function one(string $sql, array $parameters, bool $required = true): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);
        $row = $statement->fetch();
        if (!is_array($row)) {
            if (!$required) {
                return [];
            }
            throw new PendingTranslationConflictException('O registo da alteração pendente já não existe.');
        }
        return $row;
    }

    /** @return array<int,array<string,mixed>> */
    private function rows(string $sql, array $parameters): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);
        return $statement->fetchAll();
    }

    /** @return int[] */
    private function dueJobIds(): array
    {
        $limit = $this->batchSize();
        $statement = $this->pdo->query(
            "SELECT id FROM translation_pending_jobs
             WHERE engine_key = " . $this->pdo->quote($this->engineKey()) . "
               AND status = 'pending' AND not_before <= UTC_TIMESTAMP()
             ORDER BY not_before, id LIMIT {$limit}"
        );
        return array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @return array<string,mixed>|null */
    private function claim(int $id): ?array
    {
        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->prepare(
                "SELECT id, operation_type, payload_json, source_language, created_by_user_id,
                        attempt_count, generation
                 FROM translation_pending_jobs WHERE id = :id AND engine_key = :engine_key
                   AND status = 'pending' AND not_before <= UTC_TIMESTAMP() FOR UPDATE"
            );
            $statement->execute(['id' => $id, 'engine_key' => $this->engineKey()]);
            $job = $statement->fetch();
            if (!is_array($job)) {
                $this->pdo->commit();
                return null;
            }
            $this->pdo->prepare(
                "UPDATE translation_pending_jobs SET status = 'processing', locked_at = UTC_TIMESTAMP(),
                 attempt_count = attempt_count + 1, last_error = NULL WHERE id = :id"
            )->execute(['id' => $id]);
            $job['attempt_count'] = (int) $job['attempt_count'] + 1;
            $this->pdo->commit();
            return $job;
        } catch (Throwable $exception) {
            $this->rollback();
            throw $exception;
        }
    }

    private function recoverAbandonedJobs(): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE translation_pending_jobs SET status = 'pending', locked_at = NULL,
             not_before = UTC_TIMESTAMP(), last_error = 'Recovered after an interrupted worker run.'
             WHERE engine_key = :engine_key AND status = 'processing'
               AND locked_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 20 MINUTE)"
        );
        $statement->execute(['engine_key' => $this->engineKey()]);
    }

    private function finish(int $id, string $status, ?string $error = null): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE translation_pending_jobs SET status = :status, completed_at = UTC_TIMESTAMP(),
             locked_at = NULL, last_error = :error
             WHERE id = :id AND generation = :generation AND status = 'processing'"
        );
        $statement->execute([
            'status' => $status, 'error' => $error === null ? null : mb_substr($error, 0, 1000),
            'id' => $id, 'generation' => $this->activeGeneration,
        ]);
    }

    private function reschedule(int $id, string $notBefore, string $error): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE translation_pending_jobs SET status = 'pending', not_before = :not_before,
             attempt_count = GREATEST(attempt_count - 1, 0), locked_at = NULL, last_error = :error
             WHERE id = :id AND generation = :generation AND status = 'processing'"
        );
        $statement->execute([
            'not_before' => $notBefore, 'error' => mb_substr($error, 0, 1000),
            'id' => $id, 'generation' => $this->activeGeneration,
        ]);
    }

    private function retryLater(int $id, int $minutes, string $error): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE translation_pending_jobs SET status = 'pending',
             not_before = DATE_ADD(UTC_TIMESTAMP(), INTERVAL {$minutes} MINUTE),
             locked_at = NULL, last_error = :error
             WHERE id = :id AND generation = :generation AND status = 'processing'"
        );
        $statement->execute([
            'error' => mb_substr($error, 0, 1000), 'id' => $id,
            'generation' => $this->activeGeneration,
        ]);
    }

    private function rollback(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    private function batchSize(): int
    {
        return max(1, min(50, (int) ($this->config['pending_worker_batch_size'] ?? self::DEFAULT_BATCH_SIZE)));
    }

    private function maxAttempts(): int
    {
        return max(1, min(20, (int) ($this->config['pending_max_attempts'] ?? self::DEFAULT_MAX_ATTEMPTS)));
    }

    private function retryDelayMinutes(int $attemptCount): int
    {
        return min(60, 5 * (2 ** min(4, max(0, $attemptCount - 1))));
    }

    private function engineKey(): string
    {
        $value = trim((string) ($this->config['engine_key'] ?? 'google-basic-nmt-v2'));
        return $value !== '' ? $value : 'google-basic-nmt-v2';
    }
}
