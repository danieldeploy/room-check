<?php
declare(strict_types=1);

function assertVerificationCategories(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    echo 'PASS: ' . $message . PHP_EOL;
}

$root = dirname(__DIR__);
$repository = file_get_contents($root . '/src/Checklists/VerificationCategoryRepository.php');
$migration = file_get_contents($root . '/migrations/022_verification_categories.sql');
$auth = file_get_contents($root . '/src/Auth/Auth.php');
$page = file_get_contents($root . '/verification-categories.php');
$navigation = file_get_contents($root . '/src/UI/VerificationCategoryNavigation.php');
$rooms = file_get_contents($root . '/rooms.php');
$lists = file_get_contents($root . '/item-lists.php');
$dialog = file_get_contents($root . '/assets/app-dialog.js');
$categoryDialog = file_get_contents($root . '/assets/verification-categories.js');
$deployment = file_get_contents($root . '/.cpanel.yml');
$permissionsPage = file_get_contents($root . '/admin/permissions.php');

require_once $root . '/src/Auth/Auth.php';

foreach (compact('repository', 'migration', 'auth', 'page', 'navigation', 'rooms', 'lists', 'dialog', 'categoryDialog', 'deployment', 'permissionsPage') as $source) {
    assertVerificationCategories(is_string($source), 'category feature source is readable');
}

assertVerificationCategories(
    str_contains($migration, 'CREATE TABLE IF NOT EXISTS verification_categories')
        && str_contains($migration, "('rooms', 'Quartos', 'Rooms', 10)")
        && str_contains($migration, 'SELECT DISTINCT area, area, area, 1000'),
    'migration creates and seeds categories while preserving existing areas'
);
assertVerificationCategories(
    str_contains($lists, 'if ($listId > 0 && !array_filter(')
        && !str_contains($lists, "\$listId = (int) (\$lists[0]['id'] ?? 0);")
        && str_contains($lists, '<details class="list-create-panel list-select-panel">')
        && str_contains($lists, '<option value="" <?= $listId === 0 ? \'selected\' : \'\' ?> disabled>'),
    'the list editor waits for an explicit list selection before rendering fields'
);
assertVerificationCategories(
    str_contains($migration, "('gerente', 'verification_categories.manage')")
        && str_contains($auth, "PERMISSION_VERIFICATION_CATEGORIES_MANAGE = 'verification_categories.manage'")
        && str_contains($auth, 'PERMISSION_VERIFICATION_CATEGORIES_MANAGE => [self::PERMISSION_ROOM_CHECK_VIEW]')
        && !in_array(
            Auth::PERMISSION_VERIFICATION_CATEGORIES_MANAGE,
            Auth::LOCKED_ROLE_PERMISSIONS['gerente'] ?? [],
            true
        )
        && str_contains($permissionsPage, 'foreach (Auth::PERMISSIONS as $permission => $meta)')
        && str_contains($permissionsPage, 'foreach (Auth::ROLES as $role => $label)'),
    'category management is delegable through the role-permission matrix and keeps its view dependency'
);
assertVerificationCategories(
    str_contains($page, 'Auth::PERMISSION_VERIFICATION_CATEGORIES_MANAGE')
        && str_contains($page, 'Csrf::validate')
        && str_contains($page, 'ContentTranslator')
        && str_contains($page, 'VerificationCategoryRepository::create')
        && str_contains($page, 'VerificationCategoryRepository::rename')
        && str_contains($page, 'VerificationCategoryRepository::delete')
        && str_contains($repository, '$translator->versions(')
        && str_contains($repository, 'name = :name_pt, name_en = :name_en'),
    'category create, rename and delete actions are permission-protected, CSRF-protected and bilingual'
);
assertVerificationCategories(
    str_contains($repository, '$pdo->beginTransaction()')
        && str_contains($repository, 'SELECT id FROM item_lists WHERE area = :slug FOR UPDATE')
        && str_contains($repository, "if (\$usage['assignment_count'] > 0)")
        && str_contains($repository, 'room_item_assignments')
        && str_contains($repository, 'whatsapp_assignment_reminders')
        && str_contains($repository, '$pdo->rollBack()'),
    'server deletion locks the category contents and blocks every recorded assignment reference'
);
assertVerificationCategories(
    str_contains($repository, 'DELETE checklist_value FROM room_checklist_values')
        && str_contains($repository, 'DELETE item FROM item_list_items')
        && str_contains($repository, 'DELETE FROM item_lists WHERE area = :slug')
        && str_contains($repository, 'DELETE FROM verification_categories WHERE id = :id'),
    'unassigned category contents are deleted in foreign-key-safe order'
);
assertVerificationCategories(
    str_contains($categoryDialog, 'assignmentCount > 0')
        && str_contains($categoryDialog, 'config.blockedMessage')
        && str_contains($categoryDialog, 'config.contentMessage')
        && str_contains($categoryDialog, 'window.AppDialog.confirm')
        && str_contains($categoryDialog, 'window.AppDialog.alert')
        && str_contains($page, 'Não é possível apagar. Outro utilizador já atribuiu um item a um empregado.')
        && str_contains($page, 'Cannot delete. Another user has already assigned an item to an employee.'),
    'the approved popup distinguishes blocked deletion from destructive confirmation'
);
assertVerificationCategories(
    str_contains($dialog, 'window.AppDialog')
        && str_contains($dialog, "form[data-app-confirm]")
        && !str_contains($lists, 'onsubmit="return confirm'),
    'the dialog implementation is global and legacy item-list confirmations use it'
);
assertVerificationCategories(
    str_contains($navigation, 'class="module-menu module-areas-menu" data-global-menu')
        && str_contains($navigation, '$areasActive = $active === \'categories\' || str_starts_with($active, \'category:\');')
        && str_contains($navigation, 'foreach ($categories as $category)')
        && str_contains($navigation, 'foreach ($lists as $list)')
        && str_contains($navigation, "SiteTranslations::text('Áreas', 'Areas')")
        && str_contains($navigation, "SiteTranslations::text('Nova / Editar / Apagar', 'New / Edit / Delete')")
        && str_contains($navigation, "'rooms.php?area=' . rawurlencode(\$slug)")
        && str_contains($navigation, "'item-lists.php?list_id=' . \$listId . '&list_view=menu'")
        && str_contains($rooms, 'verificationCategories($pdo)')
        && str_contains($rooms, '$navigationLists = itemLists($pdo)')
        && str_contains($lists, '$verificationAreas[(string) $category[\'slug\']]'),
    'hierarchical area and list submenus are database-driven'
);
assertVerificationCategories(
    str_contains($lists, '<?php if (!$isMenuListView): ?>')
        && str_contains($lists, 'class="selected-list-summary"')
        && str_contains($lists, '<strong>Lista:</strong>')
        && str_contains($lists, '<strong>Área:</strong>')
        && str_contains($lists, '<?php if ($isMenuListView): ?>')
        && str_contains($lists, 'id="editListForm"')
        && str_contains($lists, 'aria-label="Nome da lista"'),
    'direct menu list view is read-only at the top while the administrative editor keeps list metadata controls'
);
assertVerificationCategories(
    str_contains($lists, '$creationFlow = $listView === \'create\';')
        && str_contains($lists, '$listView = \'create\';')
        && str_contains($lists, '$creationFlow = true;')
        && str_contains($lists, '<button type="submit" <?= $creationFlow ? \'disabled\' : \'\' ?>>Criar lista</button>')
        && str_contains($lists, 'class="add-item create-flow-add-item"')
        && str_contains($lists, 'name="list_view" value="create"'),
    'successful list creation disables Create List and reveals the Add Item row below it'
);
assertVerificationCategories(
    str_contains($lists, '<summary>Selecionar lista para apagar</summary>')
        && str_contains($lists, '<input type="hidden" name="list_view" value="delete">')
        && str_contains($lists, '$isDeleteListView = $listView === \'delete\';')
        && str_contains($lists, '<h2 class="section-title">Apagar lista selecionada</h2>')
        && str_contains($lists, '<?php if ($selectedList && !$creationFlow && !$isDeleteListView): ?>')
        && substr_count($lists, 'name="action" value="delete_list"') === 1,
    'list deletion has a dedicated selector and is absent from the list editor'
);
assertVerificationCategories(
    str_contains($navigation, "SiteTranslations::text('Áreas', 'Areas')")
        && str_contains($navigation, "SiteTranslations::text('Listas', 'Lists')")
        && str_contains($page, "SiteTranslations::text('Criar nova área', 'Create new area')")
        && str_contains($auth, "'label' => 'Gerir áreas'")
        && str_contains($lists, '<title>Listas —')
        && !str_contains($navigation, "SiteTranslations::text('Categorias', 'Categories')")
        && !str_contains($navigation, "SiteTranslations::text('Listas de itens', 'Item lists')"),
    'visible navigation and management terminology uses areas and lists in both languages'
);
assertVerificationCategories(
    str_contains($deployment, 'verification-categories.php'),
    'cPanel deployment includes the new management page'
);

echo "Verification category contract passed.\n";
