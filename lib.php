<?php
declare(strict_types=1);

const PROPERTIES = [
    'City Center Guest House' => 6,
    'Welcome Guest House' => 15,
];

const CHECKLIST_ITEMS = [
    'Espelho',
    'Lampadas',
    'Armarios',
    'Cabeceiras',
    'Ventoinhas',
    'Cortinas',
    'Fichas',
    'Camas',
    'Luzes',
    'Portas',
    'Fechaduras',
    'Janelas',
    'Chaves',
    'Placa de Saida',
    'Caixote de Lixo',
    'Paredes',
    'Hangers',
];

function database(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $config = require __DIR__ . '/config.php';
    $db = $config['db'];

    if ($db['name'] === '' || $db['user'] === '') {
        throw new RuntimeException(
            'Base de dados não configurada. Crie config.local.php a partir de config.local.example.php.'
        );
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $db['host'],
        $db['port'],
        $db['name'],
        $db['charset']
    );

    $pdo = new PDO($dsn, $db['user'], $db['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    ensureDynamicListItemNameSchema($pdo, (string) $db['name']);
    backfillLegacyBilingualContent($pdo, $config['translation'] ?? []);

    if (class_exists('Translator') && method_exists('Translator', 'registerDynamic')) {
        $nameRows = $pdo->query(
            "SELECT name AS name_pt, name_en FROM item_lists WHERE NULLIF(TRIM(name_en), '') IS NOT NULL
             UNION ALL
             SELECT name AS name_pt, name_en FROM item_list_items WHERE NULLIF(TRIM(name_en), '') IS NOT NULL"
        )->fetchAll();
        foreach ($nameRows as $nameRow) {
            Translator::registerDynamic((string) $nameRow['name_pt'], (string) $nameRow['name_en']);
        }
    }

    return $pdo;
}

function ensureDynamicListItemNameSchema(PDO $pdo, string $schema): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $columnExists = static function (string $table, string $column) use ($pdo, $schema): bool {
        $statement = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = :schema AND TABLE_NAME = :table AND COLUMN_NAME = :column'
        );
        $statement->execute(['schema' => $schema, 'table' => $table, 'column' => $column]);
        return (int) $statement->fetchColumn() > 0;
    };

    if (!$columnExists('item_lists', 'name_en')) {
        try {
            $pdo->exec('ALTER TABLE item_lists ADD COLUMN name_en VARCHAR(120) NULL AFTER name');
        } catch (PDOException $exception) {
            if (!$columnExists('item_lists', 'name_en')) {
                throw $exception;
            }
        }
    }

    if (!$columnExists('item_list_items', 'name_en')) {
        try {
            $pdo->exec('ALTER TABLE item_list_items ADD COLUMN name_en VARCHAR(80) NULL AFTER name');
        } catch (PDOException $exception) {
            if (!$columnExists('item_list_items', 'name_en')) {
                throw $exception;
            }
        }
    }

    $pdo->exec(<<<'SQL'
UPDATE item_lists
SET name_en = CASE name
    WHEN 'Check Geral' THEN 'General Check'
    WHEN 'Check Geral Quartos' THEN 'General Room Check'
    WHEN 'Check Casas Banho Comuns' THEN 'Shared Bathrooms Check'
    WHEN 'Check Corredores' THEN 'Corridors Check'
    WHEN 'Check Cozinhas' THEN 'Kitchens Check'
    WHEN 'Check Terraços' THEN 'Terraces Check'
    ELSE name_en
END
WHERE NULLIF(TRIM(name_en), '') IS NULL
  AND name IN (
      'Check Geral', 'Check Geral Quartos', 'Check Casas Banho Comuns',
      'Check Corredores', 'Check Cozinhas', 'Check Terraços'
  )
SQL);

    $pdo->exec(<<<'SQL'
UPDATE item_list_items
SET name_en = CASE name
    WHEN 'Espelho' THEN 'Mirror'
    WHEN 'Lampadas' THEN 'Lights'
    WHEN 'Lâmpadas' THEN 'Lights'
    WHEN 'Armarios' THEN 'Wardrobes'
    WHEN 'Armários' THEN 'Wardrobes'
    WHEN 'Cabeceiras' THEN 'Headboards'
    WHEN 'Ventoinhas' THEN 'Fans'
    WHEN 'Cortinas' THEN 'Curtains'
    WHEN 'Fichas' THEN 'Power sockets'
    WHEN 'Camas' THEN 'Beds'
    WHEN 'Luzes' THEN 'Lights'
    WHEN 'Portas' THEN 'Doors'
    WHEN 'Fechaduras' THEN 'Locks'
    WHEN 'Janelas' THEN 'Windows'
    WHEN 'Chaves' THEN 'Keys'
    WHEN 'Placa de Saida' THEN 'Exit sign'
    WHEN 'Placa de Saída' THEN 'Exit sign'
    WHEN 'Caixote de Lixo' THEN 'Waste bin'
    WHEN 'Paredes' THEN 'Walls'
    WHEN 'Extintores' THEN 'Fire extinguishers'
    WHEN 'Item teste' THEN 'Test item'
    ELSE name_en
END
WHERE NULLIF(TRIM(name_en), '') IS NULL
  AND name IN (
      'Espelho', 'Lampadas', 'Lâmpadas', 'Armarios', 'Armários', 'Cabeceiras',
      'Ventoinhas', 'Cortinas', 'Fichas', 'Camas', 'Luzes', 'Portas', 'Fechaduras',
      'Janelas', 'Chaves', 'Placa de Saida', 'Placa de Saída', 'Caixote de Lixo',
      'Paredes', 'Extintores', 'Item teste'
  )
SQL);

    // The original room-check seed pre-dates bilingual content. Backfill the
    // established English text for those legacy default instructions so the
    // English UI reads the English column instead of falling back to Portuguese.
    $pdo->exec(<<<'SQL'
UPDATE item_list_items
SET default_instructions_en = CASE TRIM(default_instructions)
    WHEN 'Verificar se está limpo e sem danos.' THEN 'Check that it is clean and undamaged.'
    WHEN 'Confirmar que todas as lâmpadas acendem.' THEN 'Confirm that all lights turn on.'
    WHEN 'Verificar a limpeza e o funcionamento das portas.' THEN 'Check cleanliness and that the doors work correctly.'
    WHEN 'Confirmar que estão limpas e bem fixas.' THEN 'Confirm that they are clean and securely fitted.'
    WHEN 'Testar o funcionamento e verificar a limpeza.' THEN 'Test operation and check cleanliness.'
    WHEN 'Verificar a limpeza e o movimento das cortinas.' THEN 'Check cleanliness and movement of the curtains.'
    WHEN 'Confirmar que estão fixas e sem danos visíveis.' THEN 'Confirm that they are secure and have no visible damage.'
    WHEN 'Verificar a estabilidade e o estado das camas.' THEN 'Check the stability and condition of the beds.'
    WHEN 'Testar todas as luzes do quarto.' THEN 'Test all room lights.'
    WHEN 'Confirmar que abrem e fecham corretamente.' THEN 'Confirm that they open and close correctly.'
    WHEN 'Testar a fechadura e o trinco da porta.' THEN 'Test the door lock and latch.'
    WHEN 'Verificar abertura, fecho e estado dos vidros.' THEN 'Check opening, closing and the condition of the glass.'
    WHEN 'Confirmar que as chaves estão disponíveis e funcionam.' THEN 'Confirm that the keys are available and work.'
    WHEN 'Verificar se está visível e bem fixada.' THEN 'Confirm that it is visible and securely fitted.'
    WHEN 'Confirmar que está limpo e em bom estado.' THEN 'Confirm that it is clean and in good condition.'
    WHEN 'Verificar manchas, fissuras ou danos.' THEN 'Check for stains, cracks or damage.'
    WHEN 'Confirmar a quantidade e o estado dos cabides.' THEN 'Confirm the number and condition of the hangers.'
    ELSE default_instructions_en
END
WHERE NULLIF(TRIM(default_instructions_en), '') IS NULL
  AND TRIM(default_instructions) IN (
      'Verificar se está limpo e sem danos.',
      'Confirmar que todas as lâmpadas acendem.',
      'Verificar a limpeza e o funcionamento das portas.',
      'Confirmar que estão limpas e bem fixas.',
      'Testar o funcionamento e verificar a limpeza.',
      'Verificar a limpeza e o movimento das cortinas.',
      'Confirmar que estão fixas e sem danos visíveis.',
      'Verificar a estabilidade e o estado das camas.',
      'Testar todas as luzes do quarto.',
      'Confirmar que abrem e fecham corretamente.',
      'Testar a fechadura e o trinco da porta.',
      'Verificar abertura, fecho e estado dos vidros.',
      'Confirmar que as chaves estão disponíveis e funcionam.',
      'Verificar se está visível e bem fixada.',
      'Confirmar que está limpo e em bom estado.',
      'Verificar manchas, fissuras ou danos.',
      'Confirmar a quantidade e o estado dos cabides.'
  )
SQL);

    $done = true;
}

/**
 * Translate legacy rows that pre-date bilingual storage.
 *
 * New content is already translated when it is saved. This function only
 * selects rows whose English column is still empty, translates them once and
 * persists the result. A bounded batch prevents a single request from being
 * held up by too many external translation calls; subsequent requests continue
 * with whatever legacy rows remain.
 */
function backfillLegacyBilingualContent(PDO $pdo, array $translationConfig): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    if (($translationConfig['enabled'] ?? true) !== true) {
        return;
    }

    if (!class_exists('ContentTranslator')) {
        require_once __DIR__ . '/src/I18n/ContentTranslator.php';
    }

    $translator = new ContentTranslator($pdo, $translationConfig);
    $attemptsLeft = 24;

    $jobs = [
        [
            'select' => "SELECT id, name AS source_text FROM item_lists
                         WHERE NULLIF(TRIM(name_en), '') IS NULL
                           AND NULLIF(TRIM(name), '') IS NOT NULL
                         ORDER BY id LIMIT 24",
            'update' => "UPDATE item_lists SET name_en = :translated
                         WHERE id = :id AND NULLIF(TRIM(name_en), '') IS NULL",
            'max_length' => 120,
        ],
        [
            'select' => "SELECT id, name AS source_text FROM item_list_items
                         WHERE NULLIF(TRIM(name_en), '') IS NULL
                           AND NULLIF(TRIM(name), '') IS NOT NULL
                         ORDER BY id LIMIT 24",
            'update' => "UPDATE item_list_items SET name_en = :translated
                         WHERE id = :id AND NULLIF(TRIM(name_en), '') IS NULL",
            'max_length' => 80,
        ],
        [
            'select' => "SELECT id, default_instructions AS source_text FROM item_list_items
                         WHERE NULLIF(TRIM(default_instructions_en), '') IS NULL
                           AND NULLIF(TRIM(default_instructions), '') IS NOT NULL
                         ORDER BY id LIMIT 24",
            'update' => "UPDATE item_list_items SET default_instructions_en = :translated
                         WHERE id = :id AND NULLIF(TRIM(default_instructions_en), '') IS NULL",
            'max_length' => null,
        ],
    ];

    foreach ($jobs as $job) {
        if ($attemptsLeft <= 0) {
            break;
        }

        try {
            $rows = $pdo->query($job['select'])->fetchAll();
        } catch (Throwable) {
            continue;
        }

        $update = $pdo->prepare($job['update']);
        foreach ($rows as $row) {
            if ($attemptsLeft <= 0) {
                break 2;
            }
            $attemptsLeft--;

            $sourceText = trim((string) ($row['source_text'] ?? ''));
            if ($sourceText === '') {
                continue;
            }

            try {
                $translated = $translator->translateStrict($sourceText, 'pt', 'en');
            } catch (Throwable) {
                $translated = null;
            }
            $translated = trim((string) $translated);
            if ($translated === '') {
                continue;
            }

            $maxLength = $job['max_length'];
            if (is_int($maxLength) && mb_strlen($translated) > $maxLength) {
                $translated = mb_substr($translated, 0, $maxLength);
            }

            try {
                $update->execute(['translated' => $translated, 'id' => (int) $row['id']]);
            } catch (Throwable) {
                // A single problematic legacy row must not break the page load.
            }
        }
    }
}

function validateSelection(string $property, int $room): void
{
    if (!array_key_exists($property, PROPERTIES)) {
        throw new InvalidArgumentException('Alojamento inválido.');
    }

    if ($room < 1 || $room > PROPERTIES[$property]) {
        throw new InvalidArgumentException('Quarto inválido para o alojamento selecionado.');
    }
}

function itemLists(PDO $pdo): array
{
    $rows = $pdo->query(
        'SELECT list_row.id, list_row.name, list_row.name_en, list_row.area, list_row.is_system,
                item.name AS item_name, item.name_en AS item_name_en,
                item.default_instructions, item.default_instructions_en
         FROM item_lists list_row
         LEFT JOIN item_list_items item ON item.list_id = list_row.id
         ORDER BY list_row.is_system DESC, list_row.name, item.sort_order, item.id'
    )->fetchAll();
    $lists = [];
    foreach ($rows as $row) {
        $id = (int) $row['id'];
        if (!isset($lists[$id])) {
            $lists[$id] = [
                'id' => $id,
                'name' => (string) $row['name'],
                'nameEn' => (string) ($row['name_en'] ?? ''),
                'area' => (string) $row['area'],
                'isSystem' => (bool) $row['is_system'],
                'items' => [],
                'itemNamesEn' => [],
                'defaults' => [],
            ];
        }
        if ($row['item_name'] !== null) {
            $itemName = (string) $row['item_name'];
            $lists[$id]['items'][] = $itemName;
            $lists[$id]['itemNamesEn'][$itemName] = (string) ($row['item_name_en'] ?? '');
            $lists[$id]['defaults'][$itemName] = Translator::localized(
                (string) $row['default_instructions'], (string) ($row['default_instructions_en'] ?? '')
            );
        }
    }
    return array_values($lists);
}

function itemList(PDO $pdo, int $listId): array
{
    foreach (itemLists($pdo) as $list) {
        if ((int) $list['id'] === $listId) {
            return $list;
        }
    }
    throw new InvalidArgumentException('Escolha uma lista de itens válida.');
}

function jsonResponse(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
