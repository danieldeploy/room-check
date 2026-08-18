<?php
declare(strict_types=1);

require_once __DIR__ . '/My2NClient.php';
require_once __DIR__ . '/My2NService.php';
require_once __DIR__ . '/PdoMy2NModeRepository.php';
require_once __DIR__ . '/My2NModeService.php';

final class My2NModeFactory
{
    public static function create(PDO $pdo, array $config): My2NModeService
    {
        return new My2NModeService(
            new My2NService(new My2NClient($config['my2n']), $config['my2n']),
            new PdoMy2NModeRepository($pdo),
            ($config['my2n']['allow_writes'] ?? false) === true
        );
    }
}

