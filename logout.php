<?php
declare(strict_types=1);

$config = require __DIR__ . '/config.php';
require_once __DIR__ . '/src/Auth/Auth.php';
require_once __DIR__ . '/src/Security/Csrf.php';

Auth::startSession($config);
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Método não permitido.');
}

try {
    Csrf::validate($_POST['csrf_token'] ?? null);
} catch (Throwable) {
    http_response_code(403);
    exit('Token CSRF inválido.');
}

Auth::logout();
header('Location: login.php');
exit;
