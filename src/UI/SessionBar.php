<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/Auth/Auth.php';
require_once dirname(__DIR__) . '/Security/Csrf.php';
require_once dirname(__DIR__) . '/I18n/SiteTranslations.php';

final class SessionBar
{
    private const COMPANY_NAME = 'Active Lines Unip. Lda.';

    public static function render(
        array $user,
        string $basePath = '',
        bool $canManageUsers = false,
        bool $canManagePermissions = false
    ): void {
        SiteTranslations::boot();

        $prefix = $basePath === '' ? '' : rtrim($basePath, '/') . '/';
        $displayName = (string) ($user['display_name'] ?? $user['username'] ?? 'Utilizador');
        $role = (string) ($user['role'] ?? '');
        $roleLabel = Auth::ROLES[$role] ?? $role;
        ?>
        <nav class="session-bar" aria-label="Sessão e navegação principal">
            <div class="session-navigation">
                <a class="session-brand" href="<?= self::escape($prefix . 'index.php') ?>"><?= self::COMPANY_NAME ?></a>
                <div class="session-links">
                    <a href="<?= self::escape($prefix . 'index.php') ?>">Portal</a>
                    <?php if ($role === 'empregada_andares'): ?><a href="<?= self::escape($prefix . 'tasks.php') ?>">Minha agenda</a><?php endif; ?>
                    <?php if ($canManageUsers): ?><a href="<?= self::escape($prefix . 'admin/users.php') ?>">Utilizadores</a><?php endif; ?>
                    <?php if ($canManagePermissions): ?><a href="<?= self::escape($prefix . 'admin/permissions.php') ?>">Permissões</a><?php endif; ?>
                </div>
            </div>
            <div class="session-account">
                <div class="session-user">
                    <strong><?= self::escape($displayName) ?></strong>
                    <span><?= self::escape($roleLabel) ?></span>
                </div>
                <form class="session-logout-form" method="post" action="<?= self::escape($prefix . 'logout.php') ?>">
                    <input type="hidden" name="csrf_token" value="<?= self::escape(Csrf::token()) ?>">
                    <button class="session-logout" type="submit">Sair</button>
                </form>
            </div>
        </nav>
        <?php
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
