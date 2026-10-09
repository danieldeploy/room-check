<?php
declare(strict_types=1);

final class AdminGuard
{
    public static function requirePermission(array $config, string $permission): array
    {
        self::bootstrap($config);
        return Auth::requirePermission(database(), $config, $permission);
    }

    private static function bootstrap(array $config): void
    {
        $bootstrap = (string) ($config['auth']['bootstrap'] ?? '');
        if ($bootstrap !== '') {
            self::requireExternalBootstrap($bootstrap);
        }
        require_once dirname(__DIR__, 2) . '/lib.php';
        require_once __DIR__ . '/Auth.php';
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
