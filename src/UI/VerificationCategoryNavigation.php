<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/I18n/SiteTranslations.php';

final class VerificationCategoryNavigation
{
    public static function render(
        array $categories,
        string $active,
        bool $canManageLists,
        bool $canManageCategories,
        string $prefix = ''
    ): void {
        SiteTranslations::boot();
        $prefix = $prefix === '' ? '' : rtrim($prefix, '/') . '/';
        ?>
        <nav class="module-tabs" aria-label="<?= self::escape(SiteTranslations::text('Categorias da gestão dos espaços', 'Space management categories')) ?>">
            <?php foreach ($categories as $category): ?>
                <?php
                $slug = (string) ($category['slug'] ?? '');
                $name = (string) ($category['display_name'] ?? $category['name'] ?? $slug);
                $isActive = $active === 'category:' . $slug;
                ?>
                <a class="<?= $isActive ? 'active' : '' ?>" href="<?= self::escape($prefix . 'rooms.php?area=' . rawurlencode($slug)) ?>" title="<?= self::escape($name) ?>" <?= $isActive ? 'aria-current="page"' : '' ?>><?= self::escape($name) ?></a>
            <?php endforeach; ?>
            <?php if ($canManageLists): ?>
                <a class="<?= $active === 'lists' ? 'active' : '' ?>" href="<?= self::escape($prefix . 'item-lists.php') ?>" <?= $active === 'lists' ? 'aria-current="page"' : '' ?>><?= self::escape(SiteTranslations::text('Listas de itens', 'Item lists')) ?></a>
            <?php endif; ?>
            <?php if ($canManageCategories): ?>
                <a class="<?= $active === 'categories' ? 'active' : '' ?>" href="<?= self::escape($prefix . 'verification-categories.php') ?>" <?= $active === 'categories' ? 'aria-current="page"' : '' ?>><?= self::escape(SiteTranslations::text('Categorias', 'Categories')) ?></a>
            <?php endif; ?>
        </nav>
        <?php
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
