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
        'SELECT list_row.id, list_row.name, list_row.is_system,
                item.name AS item_name, item.default_instructions
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
                'isSystem' => (bool) $row['is_system'],
                'items' => [],
                'defaults' => [],
            ];
        }
        if ($row['item_name'] !== null) {
            $itemName = (string) $row['item_name'];
            $lists[$id]['items'][] = $itemName;
            $lists[$id]['defaults'][$itemName] = (string) $row['default_instructions'];
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
