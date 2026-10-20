<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This migration can only run from the command line.\n");
    exit(1);
}

$deployPath = rtrim((string) ($argv[1] ?? ''), DIRECTORY_SEPARATOR);
if ($deployPath === '') {
    fwrite(STDERR, "Usage: php migrate_dynamic_list_item_names.php /path/to/deployment\n");
    exit(1);
}

$configPath = $deployPath . DIRECTORY_SEPARATOR . 'config.php';
if (!is_file($configPath)) {
    fwrite(STDERR, "Deployment config.php not found at {$configPath}.\n");
    exit(1);
}

$config = require $configPath;
$db = is_array($config) ? ($config['db'] ?? []) : [];
if (!is_array($db) || trim((string) ($db['name'] ?? '')) === '' || trim((string) ($db['user'] ?? '')) === '') {
    fwrite(STDERR, "Database configuration is incomplete.\n");
    exit(1);
}

$dsn = sprintf(
    'mysql:host=%s;port=%d;dbname=%s;charset=%s',
    (string) ($db['host'] ?? 'localhost'),
    (int) ($db['port'] ?? 3306),
    (string) $db['name'],
    (string) ($db['charset'] ?? 'utf8mb4')
);

$pdo = new PDO($dsn, (string) $db['user'], (string) ($db['pass'] ?? ''), [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);

function columnExists(PDO $pdo, string $schema, string $table, string $column): bool
{
    $statement = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS\n'
        . 'WHERE TABLE_SCHEMA = :schema AND TABLE_NAME = :table AND COLUMN_NAME = :column'
    );
    $statement->execute(['schema' => $schema, 'table' => $table, 'column' => $column]);
    return (int) $statement->fetchColumn() > 0;
}

$schema = (string) $db['name'];

if (!columnExists($pdo, $schema, 'item_lists', 'name_en')) {
    $pdo->exec('ALTER TABLE item_lists ADD COLUMN name_en VARCHAR(120) NULL AFTER name');
    echo "Added item_lists.name_en\n";
} else {
    echo "item_lists.name_en already exists\n";
}

if (!columnExists($pdo, $schema, 'item_list_items', 'name_en')) {
    $pdo->exec('ALTER TABLE item_list_items ADD COLUMN name_en VARCHAR(80) NULL AFTER name');
    echo "Added item_list_items.name_en\n";
} else {
    echo "item_list_items.name_en already exists\n";
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
SQL);

echo "Dynamic bilingual list/item name migration complete.\n";
