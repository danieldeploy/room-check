<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/I18n/SiteTranslations.php';

final class VerificationCategoryNavigation
{
    public static function render(
        array $categories,
        array $lists,
        string $active,
        bool $canManageLists,
        bool $canManageCategories,
        string $prefix = ''
    ): void {
        SiteTranslations::boot();
        $prefix = $prefix === '' ? '' : rtrim($prefix, '/') . '/';
        ?>
        <nav class="module-tabs" aria-label="<?= self::escape(SiteTranslations::text('Áreas da gestão dos espaços', 'Space management areas')) ?>">
            <?php $areasActive = $active === 'categories' || str_starts_with($active, 'category:'); ?>
            <details class="module-menu module-areas-menu" data-global-menu>
                <summary class="<?= $areasActive ? 'active' : '' ?>"><?= self::escape(SiteTranslations::text('Áreas', 'Areas')) ?></summary>
                <div class="module-submenu">
                    <?php if ($canManageCategories): ?>
                        <a class="module-submenu-edit <?= $active === 'categories' ? 'active' : '' ?>" href="<?= self::escape($prefix . 'verification-categories.php') ?>" <?= $active === 'categories' ? 'aria-current="page"' : '' ?>><?= self::escape(SiteTranslations::text('Nova / Apagar / Editar', 'New / Delete / Edit')) ?></a>
                    <?php endif; ?>
                    <?php foreach ($categories as $category): ?>
                        <?php
                        $slug = (string) ($category['slug'] ?? '');
                        $name = (string) ($category['display_name'] ?? $category['name'] ?? $slug);
                        $isActive = $active === 'category:' . $slug;
                        ?>
                        <a class="module-submenu-list <?= $isActive ? 'active' : '' ?>" href="<?= self::escape($prefix . 'rooms.php?area=' . rawurlencode($slug)) ?>" title="<?= self::escape($name) ?>" <?= $isActive ? 'aria-current="page"' : '' ?>><?= self::escape($name) ?></a>
                    <?php endforeach; ?>
                </div>
            </details>
            <?php if ($canManageLists): ?>
                <?php $listsActive = $active === 'lists' || str_starts_with($active, 'list:'); ?>
                <details class="module-menu" data-global-menu>
                    <summary class="<?= $listsActive ? 'active' : '' ?>"><?= self::escape(SiteTranslations::text('Listas', 'Lists')) ?></summary>
                    <div class="module-submenu">
                        <a class="module-submenu-edit <?= $active === 'lists' ? 'active' : '' ?>" href="<?= self::escape($prefix . 'item-lists.php') ?>" <?= $active === 'lists' ? 'aria-current="page"' : '' ?>><?= self::escape(SiteTranslations::text('Nova / Apagar / Editar', 'New / Delete / Edit')) ?></a>
                        <?php foreach ($lists as $list): ?>
                            <?php
                            $listId = (int) ($list['id'] ?? 0);
                            $listName = (string) ($list['displayName'] ?? $list['display_name'] ?? $list['name'] ?? '');
                            $listIsActive = $active === 'list:' . $listId;
                            ?>
                            <a class="module-submenu-list <?= $listIsActive ? 'active' : '' ?>" href="<?= self::escape($prefix . 'item-lists.php?list_id=' . $listId . '&list_view=menu') ?>" title="<?= self::escape($listName) ?>" <?= $listIsActive ? 'aria-current="page"' : '' ?>><?= self::escape($listName) ?></a>
                        <?php endforeach; ?>
                    </div>
                </details>
            <?php endif; ?>
        </nav>
        <?php
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
