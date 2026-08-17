<?php
declare(strict_types=1);

final class AdminGuard
{
    public static function requireAdmin(array $config): array
    {
        $bootstrap = (string) ($config['auth']['bootstrap'] ?? '');
        if ($bootstrap !== '') {
            self::requireExternalBootstrap($bootstrap);
        }
        require_once dirname(__DIR__, 2) . '/lib.php';
        require_once __DIR__ . '/Auth.php';
        return Auth::requireRole(database(), $config, $config['auth']['admin_roles'] ?? []);
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
