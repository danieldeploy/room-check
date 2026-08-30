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
$storageAvailable = VerificationCategoryRepository::storageAvailable($pdo);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        Csrf::validate($_POST['csrf_token'] ?? null);
        $action = (string) ($_POST['action'] ?? '');
        $categoryId = (int) ($_POST['category_id'] ?? 0);
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
        <?php VerificationCategoryNavigation::render($categories, 'categories', Auth::hasPermission($pdo, $currentUser, Auth::PERMISSION_TASK_ASSIGN), true); ?>
    </header>

    <?php if ($message): ?><div class="notice success" role="status"><?= categoryEscape($message) ?></div><?php endif; ?>
    <?php if (!$storageAvailable): ?>
        <div class="notice error" role="alert"><?= categoryEscape(SiteTranslations::text(
            'Importe migrations/022_verification_categories.sql para ativar a gestão de áreas.',
            'Import migrations/022_verification_categories.sql to enable area management.'
        )) ?></div>
    <?php endif; ?>
    <?php if ($error): ?><noscript><div class="notice error" role="alert"><?= categoryEscape($error) ?></div></noscript><?php endif; ?>

    <details class="list-create-panel" <?= $categories === [] ? 'open' : '' ?>>
        <summary><?= categoryEscape(SiteTranslations::text('Criar nova área', 'Create new area')) ?></summary>
        <form method="post" class="create-list category-create-form">
            <input type="hidden" name="csrf_token" value="<?= categoryEscape(Csrf::token()) ?>">
            <input type="hidden" name="action" value="create_category">
            <label><span><?= categoryEscape(SiteTranslations::text('Nome da área', 'Area name')) ?></span><input name="name" maxlength="80" required placeholder="<?= categoryEscape(SiteTranslations::text('Ex.: Escadas', 'E.g. Stairs')) ?>" <?= $storageAvailable ? '' : 'disabled' ?>></label>
            <button type="submit" <?= $storageAvailable ? '' : 'disabled' ?>><?= categoryEscape(SiteTranslations::text('Criar área', 'Create area')) ?></button>
        </form>
    </details>

    <section class="category-list" aria-label="<?= categoryEscape(SiteTranslations::text('Áreas existentes', 'Existing areas')) ?>">
        <div class="category-list-heading" aria-hidden="true">
            <span><?= categoryEscape(SiteTranslations::text('Área', 'Area')) ?></span>
            <span><?= categoryEscape(SiteTranslations::text('Conteúdo', 'Content')) ?></span>
            <span><?= categoryEscape(SiteTranslations::text('Ações', 'Actions')) ?></span>
        </div>
        <?php if ($categories === []): ?><p class="empty"><?= categoryEscape(SiteTranslations::text('Ainda não existem áreas.', 'There are no areas yet.')) ?></p><?php endif; ?>
        <?php foreach ($categories as $category): ?>
            <article class="category-row">
                <form method="post" class="category-rename-form">
                    <input type="hidden" name="csrf_token" value="<?= categoryEscape(Csrf::token()) ?>">
                    <input type="hidden" name="action" value="rename_category">
                    <input type="hidden" name="category_id" value="<?= (int) $category['id'] ?>">
                    <label><span class="mobile-only"><?= categoryEscape(SiteTranslations::text('Nome da área', 'Area name')) ?></span><input name="name" maxlength="80" required value="<?= categoryEscape((string) $category['display_name']) ?>" aria-label="<?= categoryEscape(SiteTranslations::text('Nome da área', 'Area name')) ?>" <?= $storageAvailable ? '' : 'disabled' ?>></label>
                    <div class="category-counts">
                        <?php $listCount = (int) $category['list_count']; $itemCount = (int) $category['item_count']; ?>
                        <span><?= $listCount ?> <?= categoryEscape(SiteTranslations::text($listCount === 1 ? 'lista' : 'listas', $listCount === 1 ? 'list' : 'lists')) ?></span>
                        <span><?= $itemCount ?> <?= categoryEscape(SiteTranslations::text($itemCount === 1 ? 'item' : 'itens', $itemCount === 1 ? 'item' : 'items')) ?></span>
                        <?php if ((int) $category['assignment_count'] > 0): ?><strong><?= categoryEscape(SiteTranslations::text('Com itens atribuídos', 'Contains assigned items')) ?></strong><?php endif; ?>
                    </div>
                    <button type="submit" <?= $storageAvailable ? '' : 'disabled' ?>><?= categoryEscape(SiteTranslations::text('Guardar', 'Save')) ?></button>
                </form>
                <form method="post" class="category-delete-form" data-category-delete data-category-name="<?= categoryEscape((string) $category['display_name']) ?>" data-list-count="<?= (int) $category['list_count'] ?>" data-item-count="<?= (int) $category['item_count'] ?>" data-assignment-count="<?= (int) $category['assignment_count'] ?>">
                    <input type="hidden" name="csrf_token" value="<?= categoryEscape(Csrf::token()) ?>">
                    <input type="hidden" name="action" value="delete_category">
                    <input type="hidden" name="category_id" value="<?= (int) $category['id'] ?>">
                    <button class="danger subtle" type="submit" <?= $storageAvailable ? '' : 'disabled' ?>><?= categoryEscape(SiteTranslations::text('Apagar', 'Delete')) ?></button>
                </form>
            </article>
        <?php endforeach; ?>
    </section>
</main>
</body>
</html>
