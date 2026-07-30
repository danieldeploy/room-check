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

function jsonResponse(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
