<?php
declare(strict_types=1);

final class AdminGuard
{
    public static function requireAdmin(array $config): array
    {
        self::startSession();

        $bootstrap = (string) ($config['auth']['bootstrap'] ?? '');
        if ($bootstrap !== '') {
            self::requireExternalBootstrap($bootstrap);
        }

        $user = $_SESSION['user'] ?? null;
        $role = is_array($user) ? strtolower(trim((string) ($user['role'] ?? ''))) : '';
        $allowedRoles = array_map('strtolower', $config['auth']['admin_roles'] ?? []);

        if (!is_array($user) || !in_array($role, $allowedRoles, true)) {
            throw new RuntimeException('Acesso administrativo necessário.', 403);
        }

        return $user;
    }

    private static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        session_start();
    }

    private static function requireExternalBootstrap(string $path): void
    {
        $resolved = realpath($path);
        if ($resolved === false || !is_file($resolved)) {
            throw new RuntimeException('Bootstrap de autenticação indisponível.', 503);
        }

        require_once $resolved;
    }
}
