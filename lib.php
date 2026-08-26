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

    if (class_exists('Translator') && method_exists('Translator', 'registerDynamic')) {
        try {
            $nameRows = $pdo->query(
                "SELECT name AS name_pt, name_en FROM item_lists WHERE NULLIF(TRIM(name_en), '') IS NOT NULL
                 UNION ALL
                 SELECT name AS name_pt, name_en FROM item_list_items WHERE NULLIF(TRIM(name_en), '') IS NOT NULL"
            )->fetchAll();
            foreach ($nameRows as $nameRow) {
                Translator::registerDynamic((string) $nameRow['name_pt'], (string) $nameRow['name_en']);
            }
        } catch (Throwable) {
            // Keep the application compatible until the bilingual-name migration is applied.
        }
    }

    return $pdo;
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
