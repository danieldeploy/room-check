<?php
declare(strict_types=1);

final class BilingualContentMaintenance
{
    private const MAX_TRANSLATION_ATTEMPTS = 48;
    private const SUSPICIOUS_ENGLISH_TARGET_PATTERN =
        'verificar|confirmar|limpeza|funcionamento|lâmpad|estão|está|todas|todos|limpas|limpos|fixas|fixos|disponí|acendem|fechadur|fissur|moldur|danificad|cabides|quarto|corredor|portas|janelas|chaves|cortinas|camas|vidros|manchas|ventoinhas|armários';

    public static function run(PDO $pdo, array $translationConfig, string $schema): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;

        self::ensureIntervalNameColumn($pdo, $schema);
        self::normalizeConfirmedItemNames($pdo);
        self::backfillLegacyContent($pdo, $translationConfig);
        self::registerDynamicTranslations($pdo);
    }

    private static function ensureIntervalNameColumn(PDO $pdo, string $schema): void
    {
        if (self::columnExists($pdo, $schema, 'room_verification_intervals', 'name_en')) {
            return;
        }

        try {
            $pdo->exec('ALTER TABLE room_verification_intervals ADD COLUMN name_en VARCHAR(120) NULL AFTER name');
        } catch (PDOException $exception) {
            if (!self::columnExists($pdo, $schema, 'room_verification_intervals', 'name_en')) {
                throw $exception;
            }
        }
    }

    private static function columnExists(PDO $pdo, string $schema, string $table, string $column): bool
    {
        $statement = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = :schema AND TABLE_NAME = :table AND COLUMN_NAME = :column'
        );
        $statement->execute(['schema' => $schema, 'table' => $table, 'column' => $column]);
        return (int) $statement->fetchColumn() > 0;
    }

    private static function normalizeConfirmedItemNames(PDO $pdo): void
    {
        $renames = [
            ['old' => 'Hangers', 'pt' => 'Cabides', 'en' => 'Hangers'],
            ['old' => 'SOFAS', 'pt' => 'Sofás', 'en' => 'Sofas'],
        ];

        foreach ($renames as $rename) {
            $find = $pdo->prepare('SELECT id, list_id FROM item_list_items WHERE BINARY name = BINARY :old_name');
            $find->execute(['old_name' => $rename['old']]);
            foreach ($find->fetchAll() as $row) {
                $itemId = (int) $row['id'];
                $listId = (int) $row['list_id'];

                $duplicate = $pdo->prepare(
                    'SELECT id FROM item_list_items
                     WHERE list_id = :list_id AND BINARY name = BINARY :new_name AND id <> :id LIMIT 1'
                );
                $duplicate->execute(['list_id' => $listId, 'new_name' => $rename['pt'], 'id' => $itemId]);
                if ($duplicate->fetchColumn()) {
                    continue;
                }

                try {
                    $pdo->beginTransaction();
                    $updateValues = $pdo->prepare(
                        'UPDATE room_checklist_values SET item_name = :new_name
                         WHERE list_id = :list_id AND BINARY item_name = BINARY :old_name'
                    );
                    $updateValues->execute([
                        'new_name' => $rename['pt'], 'list_id' => $listId, 'old_name' => $rename['old'],
                    ]);

                    $updateAssignments = $pdo->prepare(
                        'UPDATE room_item_assignments SET item_name = :new_name
                         WHERE list_id = :list_id AND BINARY item_name = BINARY :old_name'
                    );
                    $updateAssignments->execute([
                        'new_name' => $rename['pt'], 'list_id' => $listId, 'old_name' => $rename['old'],
                    ]);

                    $updateItem = $pdo->prepare(
                        'UPDATE item_list_items SET name = :name_pt, name_en = :name_en WHERE id = :id'
                    );
                    $updateItem->execute([
                        'name_pt' => $rename['pt'], 'name_en' => $rename['en'], 'id' => $itemId,
                    ]);
                    $pdo->commit();
                } catch (Throwable) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                }
            }
        }
    }

    private static function backfillLegacyContent(PDO $pdo, array $translationConfig): void
    {
        if (!class_exists('ContentTranslator')) {
            require_once __DIR__ . '/ContentTranslator.php';
        }

        // The deterministic map repairs established seed text. Validated cache
        // entries extend it so previously translated user content can also repair
        // a bad legacy target without another provider request.
        $known = self::englishRepairDictionary($pdo);
        $translator = ($translationConfig['enabled'] ?? true) === true
            ? new ContentTranslator($pdo, $translationConfig)
            : null;

        $attemptsLeft = self::MAX_TRANSLATION_ATTEMPTS;
        $jobs = [
            [
                'select' => self::legacyTextSelect(
                    'room_verification_intervals', 'id, name AS source_text, name_en AS target_text',
                    'name', 'name_en', 'id'
                ),
                'update' => 'UPDATE room_verification_intervals SET name_en = :translated WHERE id = :id',
                'keys' => ['id' => 'id'],
                'max_length' => 120,
            ],
            [
                'select' => self::legacyTextSelect(
                    'item_lists', 'id, name AS source_text, name_en AS target_text',
                    'name', 'name_en', 'id'
                ),
                'update' => 'UPDATE item_lists SET name_en = :translated WHERE id = :id',
                'keys' => ['id' => 'id'],
                'max_length' => 120,
            ],
            [
                'select' => self::legacyTextSelect(
                    'item_list_items', 'id, name AS source_text, name_en AS target_text',
                    'name', 'name_en', 'id'
                ),
                'update' => 'UPDATE item_list_items SET name_en = :translated WHERE id = :id',
                'keys' => ['id' => 'id'],
                'max_length' => 80,
            ],
            [
                'select' => self::legacyTextSelect(
                    'item_list_items', 'id, default_instructions AS source_text, default_instructions_en AS target_text',
                    'default_instructions', 'default_instructions_en', 'id'
                ),
                'update' => 'UPDATE item_list_items SET default_instructions_en = :translated WHERE id = :id',
                'keys' => ['id' => 'id'],
                'max_length' => null,
            ],
            [
                'select' => self::legacyTextSelect(
                    'room_checklist_values',
                    'list_id, property_name, room_number, item_name, problem AS source_text, problem_en AS target_text',
                    'problem', 'problem_en', 'list_id, property_name, room_number, item_name'
                ),
                'update' => 'UPDATE room_checklist_values SET problem_en = :translated
                             WHERE list_id = :list_id AND property_name = :property_name
                               AND room_number = :room_number AND item_name = :item_name',
                'keys' => [
                    'list_id' => 'list_id', 'property_name' => 'property_name',
                    'room_number' => 'room_number', 'item_name' => 'item_name',
                ],
                'max_length' => null,
            ],
            [
                'select' => self::legacyTextSelect(
                    'room_item_assignments',
                    'id, verification_instructions AS source_text, verification_instructions_en AS target_text',
                    'verification_instructions', 'verification_instructions_en', 'id'
                ),
                'update' => 'UPDATE room_item_assignments SET verification_instructions_en = :translated WHERE id = :id',
                'keys' => ['id' => 'id'],
                'max_length' => null,
            ],
        ];

        foreach ($jobs as $job) {
            if ($attemptsLeft <= 0) {
                break;
            }
            try {
                $rows = $pdo->query($job['select'])->fetchAll();
                $update = $pdo->prepare($job['update']);
            } catch (Throwable) {
                continue;
            }

            foreach ($rows as $row) {
                if ($attemptsLeft <= 0) {
                    break 2;
                }

                $sourceText = trim((string) ($row['source_text'] ?? ''));
                $currentTarget = trim((string) ($row['target_text'] ?? ''));
                if ($sourceText === '') {
                    continue;
                }

                // SQL deliberately over-selects suspicious targets. Keep any text
                // that passes the conservative target-language guard.
                if ($currentTarget !== '' && ContentTranslator::isPlausibleTargetText($currentTarget, 'en')) {
                    continue;
                }

                $translated = $known[$sourceText] ?? null;
                if ($translated === null && $translator instanceof ContentTranslator) {
                    $attemptsLeft--;
                    try {
                        $translated = $translator->translateStrict($sourceText, 'pt', 'en');
                    } catch (Throwable) {
                        $translated = null;
                    }
                }

                $translated = trim((string) $translated);
                if ($translated === '' || !ContentTranslator::isPlausibleTargetText($translated, 'en')) {
                    continue;
                }
                if (is_int($job['max_length']) && mb_strlen($translated) > $job['max_length']) {
                    $translated = mb_substr($translated, 0, $job['max_length']);
                }

                $parameters = ['translated' => $translated];
                foreach ($job['keys'] as $parameter => $column) {
                    $parameters[$parameter] = $row[$column];
                }
                try {
                    $update->execute($parameters);
                } catch (Throwable) {
                    // One legacy row must never break the application request.
                }
            }
        }
    }

    private static function legacyTextSelect(
        string $table,
        string $columns,
        string $sourceColumn,
        string $targetColumn,
        string $orderBy
    ): string {
        $pattern = str_replace("'", "''", self::SUSPICIOUS_ENGLISH_TARGET_PATTERN);
        return "SELECT {$columns}
                FROM {$table}
                WHERE NULLIF(TRIM({$sourceColumn}), '') IS NOT NULL
                  AND (
                      NULLIF(TRIM({$targetColumn}), '') IS NULL
                      OR LOWER({$targetColumn}) REGEXP '{$pattern}'
                  )
                ORDER BY {$orderBy}
                LIMIT 48";
    }

    private static function englishRepairDictionary(PDO $pdo): array
    {
        $dictionary = self::knownEnglishTranslations();
        try {
            $rows = $pdo->query(
                "SELECT source_text, translated_text
                 FROM translation_cache
                 WHERE source_language = 'pt' AND target_language = 'en'
                   AND NULLIF(TRIM(source_text), '') IS NOT NULL
                   AND NULLIF(TRIM(translated_text), '') IS NOT NULL"
            )->fetchAll();
            foreach ($rows as $row) {
                $source = trim((string) ($row['source_text'] ?? ''));
                $translated = trim((string) ($row['translated_text'] ?? ''));
                if ($source !== '' && ContentTranslator::isPlausibleTargetText($translated, 'en')) {
                    $dictionary[$source] = $translated;
                }
            }
        } catch (Throwable) {
            // The cache is optional; deterministic translations remain available.
        }
        return $dictionary;
    }

    private static function registerDynamicTranslations(PDO $pdo): void
    {
        if (!class_exists('Translator') || !method_exists('Translator', 'registerDynamic')) {
            return;
        }

        $queries = [
            "SELECT name AS pt, name_en AS en FROM item_lists",
            "SELECT name AS pt, name_en AS en FROM item_list_items",
            "SELECT name AS pt, name_en AS en FROM room_verification_intervals",
            "SELECT default_instructions AS pt, default_instructions_en AS en FROM item_list_items",
            "SELECT problem AS pt, problem_en AS en FROM room_checklist_values",
            "SELECT verification_instructions AS pt, verification_instructions_en AS en FROM room_item_assignments",
        ];

        foreach ($queries as $query) {
            try {
                foreach ($pdo->query($query)->fetchAll() as $row) {
                    Translator::registerDynamic((string) ($row['pt'] ?? ''), (string) ($row['en'] ?? ''));
                }
            } catch (Throwable) {
                // Dynamic display translations are supplemental; persistence remains authoritative.
            }
        }
    }

    private static function knownEnglishTranslations(): array
    {
        return [
            'Quartos Setembro' => 'September Rooms',
            'Corredores Setembro' => 'September Corridors',
            'Cabides' => 'Hangers',
            'Sofás' => 'Sofas',
            'moldura danificada' => 'damaged frame',
            'Verificar se está limpo e sem danos.' => 'Check that it is clean and undamaged.',
            'Confirmar que todas as lâmpadas acendem.' => 'Confirm that all lights turn on.',
            'Verificar a limpeza e o funcionamento das portas.' => 'Check cleanliness and that the doors work correctly.',
            'Confirmar que estão limpas e bem fixas.' => 'Confirm that they are clean and securely fitted.',
            'Testar o funcionamento e verificar a limpeza.' => 'Test operation and check cleanliness.',
            'Verificar a limpeza e o movimento das cortinas.' => 'Check cleanliness and movement of the curtains.',
            'Confirmar que estão fixas e sem danos visíveis.' => 'Confirm that they are secure and have no visible damage.',
            'Verificar a estabilidade e o estado das camas.' => 'Check the stability and condition of the beds.',
            'Testar todas as luzes do quarto.' => 'Test all room lights.',
            'Confirmar que abrem e fecham corretamente.' => 'Confirm that they open and close correctly.',
            'Testar a fechadura e o trinco da porta.' => 'Test the door lock and latch.',
            'Verificar abertura, fecho e estado dos vidros.' => 'Check opening, closing and the condition of the glass.',
            'Confirmar que as chaves estão disponíveis e funcionam.' => 'Confirm that the keys are available and work.',
            'Verificar se está visível e bem fixada.' => 'Confirm that it is visible and securely fitted.',
            'Confirmar que está limpo e em bom estado.' => 'Confirm that it is clean and in good condition.',
            'Verificar manchas, fissuras ou danos.' => 'Check for stains, cracks or damage.',
            'Confirmar a quantidade e o estado dos cabides.' => 'Confirm the number and condition of the hangers.',
        ];
    }
}
