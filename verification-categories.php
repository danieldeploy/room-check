<?php
declare(strict_types=1);

require __DIR__ . '/lib.php';
$config = require __DIR__ . '/config.php';
require_once __DIR__ . '/src/Auth/Auth.php';
require_once __DIR__ . '/src/Security/Csrf.php';
require_once __DIR__ . '/src/UI/SessionBar.php';
require_once __DIR__ . '/src/UI/VerificationCategoryNavigation.php';
require_once __DIR__ . '/src/I18n/ContentTranslator.php';

try {
    $pdo = database();
    $currentUser = Auth::requirePermission($pdo, $config, Auth::PERMISSION_VERIFICATION_CATEGORIES_MANAGE);
} catch (RuntimeException $exception) {
    if ($exception->getCode() === 401) {
        header('Location: login.php');
        exit;
    }
    http_response_code(403);
    exit($exception->getMessage());
}

$message = null;
$error = null;
$categoryId = (int) ($_GET['category_id'] ?? 0);
$categoryView = (string) ($_GET['category_view'] ?? '');
$storageAvailable = VerificationCategoryRepository::storageAvailable($pdo);
$navigationLists = itemLists($pdo);
foreach ($navigationLists as &$navigationList) {
    $navigationList['displayName'] = Translator::localized(
        (string) ($navigationList['name'] ?? ''),
        (string) ($navigationList['nameEn'] ?? '')
    );
}
unset($navigationList);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        Csrf::validate($_POST['csrf_token'] ?? null);
        $action = (string) ($_POST['action'] ?? '');
        $categoryId = (int) ($_POST['category_id'] ?? 0);
        $categoryView = (string) ($_POST['category_view'] ?? '');
        $name = trim((string) ($_POST['name'] ?? ''));
        $translator = new ContentTranslator($pdo, $config['translation'] ?? []);

        if (in_array($action, ['create_category', 'rename_category'], true)) {
            if ($name === '' || mb_strlen($name) > 80) {
                throw new InvalidArgumentException(SiteTranslations::text(
                    'O nome da área deve ter entre 1 e 80 caracteres.',
                    'The area name must contain between 1 and 80 characters.'
                ));
            }
        }

        if ($action === 'create_category') {
            $categoryId = VerificationCategoryRepository::create(
                $pdo,
                $translator,
                $name,
                (int) $currentUser['id']
            );
            $message = SiteTranslations::text('Área criada e adicionada ao menu.', 'Area created and added to the menu.');
        } elseif ($action === 'rename_category') {
            VerificationCategoryRepository::rename(
                $pdo,
                $translator,
                $categoryId,
                $name
            );
            $message = SiteTranslations::text('Nome da área atualizado.', 'Area name updated.');
        } elseif ($action === 'delete_category') {
            $usage = VerificationCategoryRepository::delete($pdo, $categoryId);
            $deletedLists = (int) $usage['list_count'];
            $deletedItems = (int) $usage['item_count'];
            $message = SiteTranslations::format(
                'Área apagada com {lists} e {items}.',
                'Area deleted with {lists} and {items}.',
                [
                    '{lists}' => $deletedLists . ' ' . SiteTranslations::text(
                        $deletedLists === 1 ? 'lista' : 'listas',
                        $deletedLists === 1 ? 'list' : 'lists'
                    ),
                    '{items}' => $deletedItems . ' ' . SiteTranslations::text(
                        $deletedItems === 1 ? 'item' : 'itens',
                        $deletedItems === 1 ? 'item' : 'items'
                    ),
                ]
            );
            $categoryId = 0;
        } else {
            throw new InvalidArgumentException(SiteTranslations::text('Operação inválida.', 'Invalid action.'));
        }

        Auth::audit($pdo, (int) $currentUser['id'], 'verification_categories_updated', [
            'action' => $action,
            'category_id' => $categoryId,
        ]);
    } catch (PDOException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = $exception->getCode() === '23000'
            ? SiteTranslations::text('Já existe uma área com esse nome.', 'An area with that name already exists.')
            : SiteTranslations::text('Não foi possível guardar a área.', 'Could not save the area.');
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = match (true) {
            $exception instanceof DomainException => SiteTranslations::text(
                'Não é possível apagar. Outro utilizador já atribuiu um item a um empregado.',
                'Cannot delete. Another user has already assigned an item to an employee.'
            ),
            $exception instanceof InvalidArgumentException
                && $exception->getMessage() === 'Área não encontrada.' => SiteTranslations::text(
                    'Área não encontrada.',
                    'Area not found.'
                ),
            $exception instanceof RuntimeException
                && str_contains($exception->getMessage(), '022_verification_categories.sql') => SiteTranslations::text(
                    'A tabela de áreas ainda não existe. Importe a migração 022_verification_categories.sql.',
                    'The areas table does not exist yet. Import migration 022_verification_categories.sql.'
                ),
            default => $exception->getMessage(),
        };
    }
}

$categories = verificationCategories($pdo, true);
$isDeleteCategoryView = $categoryView === 'delete';
if ($categoryId > 0 && !array_filter($categories, static fn(array $category): bool => $category['id'] === $categoryId)) {
    $categoryId = 0;
}
$selectedCategory = array_values(array_filter(
    $categories,
    static fn(array $category): bool => $category['id'] === $categoryId
))[0] ?? null;
$canManageUsers = Auth::hasPermission($pdo, $currentUser, Auth::PERMISSION_USERS_MANAGE);
$canManagePermissions = Auth::hasPermission($pdo, $currentUser, Auth::PERMISSION_PERMISSIONS_MANAGE);

function categoryEscape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

header('Cache-Control: no-store');
?>
<!doctype html>
<html lang="<?= categoryEscape(Translator::locale()) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#0f766e">
    <title><?= categoryEscape(SiteTranslations::text('Áreas', 'Areas')) ?> — <?= categoryEscape(PortalBrand::name()) ?></title>
    <link rel="stylesheet" href="assets/item-lists.css?v=<?= (int) filemtime(__DIR__ . '/assets/item-lists.css') ?>-dynamic-categories-1">
    <link rel="stylesheet" href="assets/verification-categories.css?v=<?= (int) filemtime(__DIR__ . '/assets/verification-categories.css') ?>">
    <link rel="stylesheet" href="assets/session.css?v=<?= (int) filemtime(__DIR__ . '/assets/session.css') ?>">
    <script>
        window.VERIFICATION_CATEGORIES = <?= json_encode([
            'serverError' => $error,
            'blockedMessage' => SiteTranslations::text(
                'Não é possível apagar. Outro utilizador já atribuiu um item a um empregado.',
                'Cannot delete. Another user has already assigned an item to an employee.'
            ),
            'contentMessage' => SiteTranslations::text(
                'A área “{name}” contém {lists} e {items}. Todo esse conteúdo será apagado. Quer continuar?',
                'The “{name}” area contains {lists} and {items}. All of this content will be deleted. Do you want to continue?'
            ),
            'emptyMessage' => SiteTranslations::text(
                'Quer apagar a área “{name}”?',
                'Do you want to delete the “{name}” area?'
            ),
            'deleteLabel' => SiteTranslations::text('Continuar e apagar', 'Continue and delete'),
            'cancelLabel' => SiteTranslations::text('Cancelar', 'Cancel'),
            'closeLabel' => SiteTranslations::text('Fechar', 'Close'),
            'listSingular' => SiteTranslations::text('lista', 'list'),
            'listPlural' => SiteTranslations::text('listas', 'lists'),
            'itemSingular' => SiteTranslations::text('item', 'item'),
            'itemPlural' => SiteTranslations::text('itens', 'items'),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    </script>
    <script src="assets/verification-categories.js?v=<?= (int) filemtime(__DIR__ . '/assets/verification-categories.js') ?>" defer></script>
</head>
<body>
<main class="lists-shell categories-shell">
    <?php SessionBar::render($currentUser, '', $canManageUsers, $canManagePermissions); ?>
    <header class="module-header">
        <p class="eyebrow"><?= categoryEscape(SiteTranslations::text('Gestão dos espaços', 'Space management')) ?></p>
        <?php VerificationCategoryNavigation::render($categories, $navigationLists, 'categories', Auth::hasPermission($pdo, $currentUser, Auth::PERMISSION_TASK_ASSIGN), true); ?>
    </header>

    <?php if ($message): ?><div class="notice success" role="status"><?= categoryEscape($message) ?></div><?php endif; ?>
    <?php if (!$storageAvailable): ?>
        <div class="notice error" role="alert"><?= categoryEscape(SiteTranslations::text(
            'Importe migrations/022_verification_categories.sql para ativar a gestão de áreas.',
            'Import migrations/022_verification_categories.sql to enable area management.'
        )) ?></div>
    <?php endif; ?>
    <?php if ($error): ?><noscript><div class="notice error" role="alert"><?= categoryEscape($error) ?></div></noscript><?php endif; ?>

    <section class="global-crud-workflow" data-global-crud>
    <details class="list-create-panel" data-crud-action="new" <?= $categories === [] ? 'open' : '' ?>>
        <summary><?= categoryEscape(SiteTranslations::text('Criar nova área', 'Create new area')) ?></summary>
        <form method="post" class="create-list category-create-form">
            <input type="hidden" name="csrf_token" value="<?= categoryEscape(Csrf::token()) ?>">
            <input type="hidden" name="action" value="create_category">
            <label><span><?= categoryEscape(SiteTranslations::text('Nome da área', 'Area name')) ?></span><input name="name" maxlength="80" required placeholder="<?= categoryEscape(SiteTranslations::text('Ex.: Escadas', 'E.g. Stairs')) ?>" <?= $storageAvailable ? '' : 'disabled' ?>></label>
            <button type="submit" <?= $storageAvailable ? '' : 'disabled' ?>><?= categoryEscape(SiteTranslations::text('Criar área', 'Create area')) ?></button>
        </form>
    </details>

    <details class="list-create-panel list-select-panel list-delete-panel" data-crud-action="delete" <?= $isDeleteCategoryView ? 'open' : '' ?>>
        <summary><?= categoryEscape(SiteTranslations::text('Selecionar área para apagar', 'Select area to delete')) ?></summary>
        <form method="get" class="list-selector">
            <input type="hidden" name="category_view" value="delete">
            <label><span><?= categoryEscape(SiteTranslations::text('Área', 'Area')) ?></span><select name="category_id" required onchange="this.form.submit()">
                <option value="" <?= !$isDeleteCategoryView || !$selectedCategory ? 'selected' : '' ?> disabled><?= categoryEscape(SiteTranslations::text('Escolher área', 'Choose area')) ?></option>
                <?php foreach ($categories as $category): ?><option value="<?= (int) $category['id'] ?>" <?= $isDeleteCategoryView && $category['id'] === $categoryId ? 'selected' : '' ?>><?= categoryEscape((string) $category['display_name']) ?></option><?php endforeach; ?>
            </select></label>
        </form>
    </details>

    <details class="list-create-panel list-select-panel" data-crud-action="edit" <?= $selectedCategory && !$isDeleteCategoryView ? 'open' : '' ?>>
        <summary><?= categoryEscape(SiteTranslations::text('Selecionar área para editar', 'Select area to edit')) ?></summary>
        <form method="get" class="list-selector">
            <input type="hidden" name="category_view" value="edit">
            <label><span><?= categoryEscape(SiteTranslations::text('Área', 'Area')) ?></span><select name="category_id" required onchange="this.form.submit()">
                <option value="" <?= !$selectedCategory || $isDeleteCategoryView ? 'selected' : '' ?> disabled><?= categoryEscape(SiteTranslations::text('Escolher área', 'Choose area')) ?></option>
                <?php foreach ($categories as $category): ?><option value="<?= (int) $category['id'] ?>" <?= !$isDeleteCategoryView && $category['id'] === $categoryId ? 'selected' : '' ?>><?= categoryEscape((string) $category['display_name']) ?></option><?php endforeach; ?>
            </select></label>
        </form>
    </details>
    </section>

    <?php if ($selectedCategory && !$isDeleteCategoryView): ?>
        <section class="list-card category-edit-card">
            <h2 class="section-title"><?= categoryEscape(SiteTranslations::text('Editar área selecionada', 'Edit selected area')) ?></h2>
            <form method="post" class="category-edit-form">
                <input type="hidden" name="csrf_token" value="<?= categoryEscape(Csrf::token()) ?>">
                <input type="hidden" name="action" value="rename_category">
                <input type="hidden" name="category_id" value="<?= $categoryId ?>">
                <input type="hidden" name="category_view" value="edit">
                <label><span><?= categoryEscape(SiteTranslations::text('Nome da área', 'Area name')) ?></span><input name="name" maxlength="80" required value="<?= categoryEscape((string) $selectedCategory['display_name']) ?>" <?= $storageAvailable ? '' : 'disabled' ?>></label>
                <button type="submit" <?= $storageAvailable ? '' : 'disabled' ?>><?= categoryEscape(SiteTranslations::text('Guardar área', 'Save area')) ?></button>
            </form>
        </section>
    <?php endif; ?>

    <?php if ($selectedCategory && $isDeleteCategoryView): ?>
        <?php $listCount = (int) $selectedCategory['list_count']; $itemCount = (int) $selectedCategory['item_count']; ?>
        <section class="list-card category-delete-card">
            <h2 class="section-title"><?= categoryEscape(SiteTranslations::text('Apagar área selecionada', 'Delete selected area')) ?></h2>
            <div class="selected-list-summary" role="status">
                <span><strong><?= categoryEscape(SiteTranslations::text('Área:', 'Area:')) ?></strong> <?= categoryEscape((string) $selectedCategory['display_name']) ?></span>
                <span><?= $listCount ?> <?= categoryEscape(SiteTranslations::text($listCount === 1 ? 'lista' : 'listas', $listCount === 1 ? 'list' : 'lists')) ?></span>
                <span><?= $itemCount ?> <?= categoryEscape(SiteTranslations::text($itemCount === 1 ? 'item' : 'itens', $itemCount === 1 ? 'item' : 'items')) ?></span>
                <?php if ((int) $selectedCategory['assignment_count'] > 0): ?><strong class="category-blocked"><?= categoryEscape(SiteTranslations::text('Com itens atribuídos', 'Contains assigned items')) ?></strong><?php endif; ?>
            </div>
            <form method="post" class="category-delete-form" data-category-delete data-category-name="<?= categoryEscape((string) $selectedCategory['display_name']) ?>" data-list-count="<?= $listCount ?>" data-item-count="<?= $itemCount ?>" data-assignment-count="<?= (int) $selectedCategory['assignment_count'] ?>">
                <input type="hidden" name="csrf_token" value="<?= categoryEscape(Csrf::token()) ?>">
                <input type="hidden" name="action" value="delete_category">
                <input type="hidden" name="category_id" value="<?= $categoryId ?>">
                <input type="hidden" name="category_view" value="delete">
                <button class="danger" type="submit" <?= $storageAvailable ? '' : 'disabled' ?>><?= categoryEscape(SiteTranslations::text('Apagar área', 'Delete area')) ?></button>
            </form>
        </section>
    <?php endif; ?>
</main>
</body>
</html>
