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
    $currentUser = Auth::requirePermission($pdo, $config, Auth::PERMISSION_TASK_ASSIGN);
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
$verificationCategories = verificationCategories($pdo);
$verificationAreas = [];
foreach ($verificationCategories as $category) {
    $verificationAreas[(string) $category['slug']] = (string) $category['display_name'];
}
$listId = (int) ($_GET['list_id'] ?? 0);
$listView = (string) ($_GET['list_view'] ?? '');
$creationFlow = false;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        Csrf::validate($_POST['csrf_token'] ?? null);
        $action = (string) ($_POST['action'] ?? '');
        $listView = (string) ($_POST['list_view'] ?? '');
        $creationFlow = $listView === 'create';
        $listId = (int) ($_POST['list_id'] ?? 0);
        $itemId = (int) ($_POST['item_id'] ?? 0);
        $name = trim((string) ($_POST['name'] ?? ''));
        $area = trim((string) ($_POST['area'] ?? ''));
        $instructions = trim((string) ($_POST['default_instructions'] ?? ''));
        $contentTranslator = new ContentTranslator($pdo, $config['translation'] ?? []);
        if (in_array($action, ['create_list', 'rename_list'], true)) {
            if ($name === '' || mb_strlen($name) > 120) {
                throw new InvalidArgumentException('O nome da lista deve ter entre 1 e 120 caracteres.');
            }
            if (!isset($verificationAreas[$area])) {
                throw new InvalidArgumentException('Escolha uma área a verificar válida.');
            }
        } elseif (in_array($action, ['add_item', 'rename_item'], true)) {
            if ($name === '' || mb_strlen($name) > 80) {
                throw new InvalidArgumentException('O nome do item deve ter entre 1 e 80 caracteres.');
            }
            if (mb_strlen($instructions) > 5000) {
                throw new InvalidArgumentException('A descrição da verificação não pode ultrapassar 5000 caracteres.');
            }
        }

        if ($action === 'create_list') {
            $nameVersions = $contentTranslator->versions($name, Translator::locale());
            $statement = $pdo->prepare(
                'INSERT INTO item_lists (name, name_en, area, created_by_user_id)
                 VALUES (:name_pt, :name_en, :area, :user_id)'
            );
            $statement->execute([
                'name_pt' => $nameVersions['pt'], 'name_en' => $nameVersions['en'],
                'area' => $area, 'user_id' => (int) $currentUser['id'],
            ]);
            $listId = (int) $pdo->lastInsertId();
            $listView = 'create';
            $creationFlow = true;
            $message = 'Lista criada.';
        } elseif ($action === 'rename_list') {
            $oldList = itemList($pdo, $listId);
            $nameVersions = $contentTranslator->versions(
                $name, Translator::locale(),
                (string) $oldList['name'], (string) ($oldList['nameEn'] ?? '')
            );
            $statement = $pdo->prepare(
                'UPDATE item_lists SET name = :name_pt, name_en = :name_en, area = :area WHERE id = :id'
            );
            $statement->execute([
                'name_pt' => $nameVersions['pt'], 'name_en' => $nameVersions['en'],
                'area' => $area, 'id' => $listId,
            ]);
            $message = 'Lista atualizada.';
        } elseif ($action === 'delete_list') {
            $list = itemList($pdo, $listId);
            if ($list['isSystem']) {
                throw new InvalidArgumentException('A lista inicial dos quartos não pode ser apagada.');
            }
            $usage = $pdo->prepare(
                'SELECT (SELECT COUNT(*) FROM room_item_assignments WHERE list_id = :assignment_list)
                      + (SELECT COUNT(*) FROM room_checklist_values WHERE list_id = :checklist_list)'
            );
            $usage->execute(['assignment_list' => $listId, 'checklist_list' => $listId]);
            if ((int) $usage->fetchColumn() > 0) {
                throw new InvalidArgumentException('Esta lista já tem dados ou atribuições e não pode ser apagada.');
            }
            $statement = $pdo->prepare('DELETE FROM item_lists WHERE id = :id');
            $statement->execute(['id' => $listId]);
            $listId = 0;
            $message = 'Lista apagada.';
        } elseif ($action === 'add_item') {
            itemList($pdo, $listId);
            $nameVersions = $contentTranslator->versions($name, Translator::locale());
            $instructionVersions = $contentTranslator->versions($instructions, Translator::locale());
            $position = $pdo->prepare(
                'SELECT COALESCE(MAX(sort_order), 0) + 10 FROM item_list_items WHERE list_id = :list_id'
            );
            $position->execute(['list_id' => $listId]);
            $statement = $pdo->prepare(
                'INSERT INTO item_list_items
                    (list_id, name, name_en, default_instructions, default_instructions_en, sort_order)
                 VALUES (:list_id, :name_pt, :name_en, :instructions_pt, :instructions_en, :sort_order)'
            );
            $statement->execute([
                'list_id' => $listId,
                'name_pt' => $nameVersions['pt'], 'name_en' => $nameVersions['en'],
                'instructions_pt' => $instructionVersions['pt'], 'instructions_en' => $instructionVersions['en'],
                'sort_order' => (int) $position->fetchColumn(),
            ]);
            $message = 'Item adicionado.';
        } elseif ($action === 'rename_item') {
            $pdo->beginTransaction();
            $statement = $pdo->prepare(
                'SELECT name, name_en, default_instructions, default_instructions_en
                 FROM item_list_items WHERE id = :id AND list_id = :list_id FOR UPDATE'
            );
            $statement->execute(['id' => $itemId, 'list_id' => $listId]);
            $oldItem = $statement->fetch();
            if (!is_array($oldItem)) {
                throw new InvalidArgumentException('Item não encontrado.');
            }
            $oldName = (string) $oldItem['name'];
            $nameVersions = $contentTranslator->versions(
                $name, Translator::locale(),
                $oldName, (string) ($oldItem['name_en'] ?? '')
            );
            $instructionVersions = $contentTranslator->versions(
                $instructions, Translator::locale(),
                (string) $oldItem['default_instructions'], (string) ($oldItem['default_instructions_en'] ?? '')
            );
            $newCanonicalName = $nameVersions['pt'];
            $renameValues = $pdo->prepare(
                'UPDATE room_checklist_values SET item_name = :new_name
                 WHERE list_id = :list_id AND item_name = :old_name'
            );
            $renameValues->execute(['new_name' => $newCanonicalName, 'list_id' => $listId, 'old_name' => $oldName]);
            $renameAssignments = $pdo->prepare(
                'UPDATE room_item_assignments SET item_name = :new_name
                 WHERE list_id = :list_id AND item_name = :old_name'
            );
            $renameAssignments->execute(['new_name' => $newCanonicalName, 'list_id' => $listId, 'old_name' => $oldName]);
            $renameItem = $pdo->prepare(
                'UPDATE item_list_items SET name = :name_pt, name_en = :name_en,
                 default_instructions = :instructions_pt, default_instructions_en = :instructions_en WHERE id = :id'
            );
            $renameItem->execute([
                'name_pt' => $newCanonicalName,
                'name_en' => $nameVersions['en'],
                'instructions_pt' => $instructionVersions['pt'],
                'instructions_en' => $instructionVersions['en'],
                'id' => $itemId,
            ]);
            $pdo->commit();
            $message = 'Item atualizado em todos os registos.';
        } elseif ($action === 'delete_item') {
            $statement = $pdo->prepare(
                'SELECT name FROM item_list_items WHERE id = :id AND list_id = :list_id'
            );
            $statement->execute(['id' => $itemId, 'list_id' => $listId]);
            $itemName = $statement->fetchColumn();
            if ($itemName === false) {
                throw new InvalidArgumentException('Item não encontrado.');
            }
            $usage = $pdo->prepare(
                'SELECT (SELECT COUNT(*) FROM room_item_assignments WHERE list_id = :assignment_list AND item_name = :assignment_name)
                      + (SELECT COUNT(*) FROM room_checklist_values WHERE list_id = :checklist_list AND item_name = :checklist_name)'
            );
            $usage->execute([
                'assignment_list' => $listId, 'assignment_name' => $itemName,
                'checklist_list' => $listId, 'checklist_name' => $itemName,
            ]);
            if ((int) $usage->fetchColumn() > 0) {
                throw new InvalidArgumentException('Este item já tem dados ou atribuições e não pode ser apagado.');
            }
            $delete = $pdo->prepare('DELETE FROM item_list_items WHERE id = :id');
            $delete->execute(['id' => $itemId]);
            $message = 'Item apagado.';
        } else {
            throw new InvalidArgumentException('Operação inválida.');
        }
        Auth::audit($pdo, (int) $currentUser['id'], 'item_lists_updated', [
            'action' => $action, 'list_id' => $listId, 'item_id' => $itemId,
        ]);
    } catch (PDOException $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = $exception->getCode() === '23000'
            ? 'Já existe uma lista ou item com esse nome.'
            : 'Não foi possível guardar a alteração.';
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = $exception->getMessage();
    }
}
$isMenuListView = $listView === 'menu';
$isDeleteListView = $listView === 'delete';

$lists = itemLists($pdo);
if ($listId > 0 && !array_filter($lists, static fn(array $list): bool => $list['id'] === $listId)) {
    $listId = 0;
}
$selectedList = array_values(array_filter($lists, static fn(array $list): bool => $list['id'] === $listId))[0] ?? null;
$itemRows = [];
$selectedAreaName = '';
if ($selectedList) {
    $selectedList['displayName'] = Translator::localized(
        (string) $selectedList['name'], (string) ($selectedList['nameEn'] ?? '')
    );
    $selectedAreaName = $verificationAreas[(string) $selectedList['area']] ?? (string) $selectedList['area'];
    $statement = $pdo->prepare(
        'SELECT id, name, name_en, default_instructions, default_instructions_en FROM item_list_items
         WHERE list_id = :list_id ORDER BY sort_order, id'
    );
    $statement->execute(['list_id' => $listId]);
    $itemRows = $statement->fetchAll();
    foreach ($itemRows as &$itemRow) {
        $itemRow['name'] = Translator::localized(
            (string) $itemRow['name'], (string) ($itemRow['name_en'] ?? '')
        );
        $itemRow['default_instructions'] = Translator::localized(
            (string) $itemRow['default_instructions'], (string) ($itemRow['default_instructions_en'] ?? '')
        );
    }
    unset($itemRow);
}
$canManageUsers = Auth::hasPermission($pdo, $currentUser, Auth::PERMISSION_USERS_MANAGE);
$canManagePermissions = Auth::hasPermission($pdo, $currentUser, Auth::PERMISSION_PERMISSIONS_MANAGE);
$canManageCategories = Auth::hasPermission($pdo, $currentUser, Auth::PERMISSION_VERIFICATION_CATEGORIES_MANAGE);
function listEscape(string $value): string { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
header('Cache-Control: no-store');
?>
<!doctype html>
<html lang="pt">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#0f766e">
    <title>Listas — <?= htmlspecialchars(PortalBrand::name(), ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="stylesheet" href="assets/item-lists.css?v=<?= (int) filemtime(__DIR__ . '/assets/item-lists.css') ?>-area-navigation-1">
    <link rel="stylesheet" href="assets/session.css?v=<?= (int) filemtime(__DIR__ . '/assets/session.css') ?>">
</head>
<body
    data-bilingual-decision-message="<?= listEscape(SiteTranslations::text(
        'Existe texto incorretamente escrito em português. Quer corrigir ou anular a edição?',
        'There is text incorrectly written in English. Do you want to correct it or cancel the edit?'
    )) ?>"
    data-bilingual-correct="<?= listEscape(SiteTranslations::text('Corrigir', 'Correct')) ?>"
    data-bilingual-cancel="<?= listEscape(SiteTranslations::text('Anular edição', 'Cancel edit')) ?>"
    data-bilingual-saved="<?= listEscape(SiteTranslations::text('Guardado', 'Saved')) ?>"
>
<main class="lists-shell">
    <?php SessionBar::render($currentUser, '', $canManageUsers, $canManagePermissions); ?>
    <header class="module-header">
        <p class="eyebrow">GESTÃO DOS ESPAÇOS</p>
        <?php VerificationCategoryNavigation::render(
            $verificationCategories,
            $lists,
            $selectedList ? 'list:' . $listId : 'lists',
            true,
            $canManageCategories
        ); ?>
    </header>
    <?php if ($message): ?><div class="notice success" role="status"><?= listEscape($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="notice error" role="alert"><?= listEscape($error) ?></div><?php endif; ?>

    <?php if (!$isMenuListView): ?>
    <section class="global-crud-workflow" data-global-crud>
    <details class="list-create-panel" data-crud-action="new" <?= $creationFlow ? 'open' : '' ?>>
        <summary>Criar nova lista</summary>
        <form method="post" class="create-list">
            <input type="hidden" name="csrf_token" value="<?= listEscape(Csrf::token()) ?>">
            <input type="hidden" name="action" value="create_list">
            <label><span>Nova lista</span><input name="name" maxlength="120" required placeholder="Nome da nova lista" value="<?= $creationFlow && $selectedList ? listEscape((string) $selectedList['displayName']) : '' ?>" <?= $creationFlow ? 'disabled' : '' ?>></label>
            <label class="list-area"><span>Área a verificar</span><select name="area" required <?= $creationFlow ? 'disabled' : '' ?>><?php foreach ($verificationAreas as $value => $label): ?><option value="<?= listEscape($value) ?>" <?= $creationFlow && $selectedList && $selectedList['area'] === $value ? 'selected' : '' ?>><?= listEscape($label) ?></option><?php endforeach; ?></select></label>
            <button type="submit" <?= $creationFlow ? 'disabled' : '' ?>>Criar lista</button>
        </form>
        <?php if ($creationFlow && $selectedList): ?>
        <form method="post" class="add-item create-flow-add-item">
            <input type="hidden" name="csrf_token" value="<?= listEscape(Csrf::token()) ?>">
            <input type="hidden" name="action" value="add_item"><input type="hidden" name="list_id" value="<?= $listId ?>"><input type="hidden" name="list_view" value="create">
            <label><span>Novo item</span><input name="name" maxlength="80" required placeholder="Nome do item"></label>
            <label class="new-instructions"><span>Descrição da verificação</span><textarea name="default_instructions" maxlength="5000" rows="1" placeholder="Descreva a verificação…" data-bilingual-textarea data-bilingual-new-item="1"></textarea></label>
            <button type="submit">Adicionar item</button>
        </form>
        <?php endif; ?>
    </details>

    <details class="list-create-panel list-select-panel list-delete-panel" data-crud-action="delete" <?= $isDeleteListView ? 'open' : '' ?>>
        <summary>Selecionar lista para apagar</summary>
        <form method="get" class="list-selector">
            <input type="hidden" name="list_view" value="delete">
            <label><span>Lista</span><select name="list_id" required onchange="this.form.submit()">
                <option value="" <?= !$isDeleteListView || $listId === 0 ? 'selected' : '' ?> disabled>Escolher lista</option>
                <?php foreach ($lists as $list): ?><option value="<?= $list['id'] ?>" <?= $isDeleteListView && $list['id'] === $listId ? 'selected' : '' ?>><?= listEscape(Translator::localized((string) $list['name'], (string) ($list['nameEn'] ?? ''))) ?></option><?php endforeach; ?>
            </select></label>
        </form>
    </details>

    <details class="list-create-panel list-select-panel" data-crud-action="edit">
        <summary>Selecionar lista para editar</summary>
        <form method="get" class="list-selector">
            <label><span>Lista</span><select name="list_id" required onchange="this.form.submit()">
                <option value="" <?= $listId === 0 ? 'selected' : '' ?> disabled>Escolher lista</option>
                <?php foreach ($lists as $list): ?><option value="<?= $list['id'] ?>" <?= $list['id'] === $listId ? 'selected' : '' ?>><?= listEscape(Translator::localized((string) $list['name'], (string) ($list['nameEn'] ?? ''))) ?></option><?php endforeach; ?>
            </select></label>
        </form>
    </details>
    </section>
    <?php endif; ?>

    <?php if ($selectedList && $isDeleteListView): ?>
    <section class="list-card list-delete-card">
        <h2 class="section-title">Apagar lista selecionada</h2>
        <div class="selected-list-summary" role="status">
            <span><strong>Lista:</strong> <?= listEscape((string) $selectedList['displayName']) ?></span>
            <span><strong>Área:</strong> <?= listEscape($selectedAreaName) ?></span>
        </div>
        <small class="list-protection-note <?= $selectedList['isSystem'] ? '' : 'is-placeholder' ?>" <?= $selectedList['isSystem'] ? '' : 'aria-hidden="true"' ?>>
            <?= $selectedList['isSystem'] ? 'Lista base protegida — não pode ser apagada.' : 'Lista base protegida' ?>
        </small>
        <form method="post" class="delete-list-form" <?= $selectedList['isSystem'] ? '' : sprintf(
            'data-app-confirm="%s" data-app-confirm-label="%s" data-app-cancel-label="%s" data-app-destructive="1"',
            listEscape(SiteTranslations::text('Apagar esta lista?', 'Delete this list?')),
            listEscape(SiteTranslations::text('Apagar', 'Delete')),
            listEscape(SiteTranslations::text('Cancelar', 'Cancel'))
        ) ?>>
            <input type="hidden" name="csrf_token" value="<?= listEscape(Csrf::token()) ?>">
            <input type="hidden" name="action" value="delete_list"><input type="hidden" name="list_id" value="<?= $listId ?>"><input type="hidden" name="list_view" value="delete">
            <button class="danger" type="submit" <?= $selectedList['isSystem'] ? 'disabled title="Lista base protegida"' : '' ?>>Apagar lista</button>
        </form>
    </section>
    <?php endif; ?>

    <?php if ($selectedList && !$creationFlow && !$isDeleteListView): ?>
    <section class="list-card">
        <?php if ($isMenuListView): ?>
        <div class="selected-list-summary" role="status">
            <span><strong>Lista:</strong> <?= listEscape((string) $selectedList['displayName']) ?></span>
            <span><strong>Área:</strong> <?= listEscape($selectedAreaName) ?></span>
        </div>
        <?php else: ?>
        <h2 class="section-title">Editar lista selecionada</h2>
        <div class="list-card-heading">
            <form method="post" class="rename-list" id="editListForm">
                <input type="hidden" name="csrf_token" value="<?= listEscape(Csrf::token()) ?>">
                <input type="hidden" name="action" value="rename_list">
                <input type="hidden" name="list_id" value="<?= $listId ?>">
                <label><span>Nome da lista</span><input name="name" maxlength="120" required value="<?= listEscape((string) $selectedList['displayName']) ?>" aria-label="Nome da lista"></label>
                <label class="list-area"><span>Área a verificar</span><select name="area" required><?php foreach ($verificationAreas as $value => $label): ?><option value="<?= listEscape($value) ?>" <?= $selectedList['area'] === $value ? 'selected' : '' ?>><?= listEscape($label) ?></option><?php endforeach; ?></select></label>
            </form>
            <div class="list-edit-actions">
                <div class="list-action-buttons">
                    <button type="submit" form="editListForm">Guardar lista</button>
                </div>
            </div>
        </div>
        <?php endif; ?>
        <div class="items-heading"><span>Item</span><span>Descreva a verificação</span><span>Ações</span></div>
        <?php if ($itemRows === []): ?><p class="empty">Esta lista ainda não tem itens.</p><?php endif; ?>
        <?php foreach ($itemRows as $item): ?>
            <div class="item-row">
                <form method="post" class="rename-item">
                    <input type="hidden" name="csrf_token" value="<?= listEscape(Csrf::token()) ?>">
                    <input type="hidden" name="action" value="rename_item"><input type="hidden" name="list_id" value="<?= $listId ?>"><input type="hidden" name="item_id" value="<?= (int) $item['id'] ?>"><?php if ($isMenuListView): ?><input type="hidden" name="list_view" value="menu"><?php endif; ?>
                    <input name="name" maxlength="80" required value="<?= listEscape((string) $item['name']) ?>" aria-label="Nome do item">
                    <textarea name="default_instructions" maxlength="5000" rows="1" placeholder="Descreva a verificação…" aria-label="Descrição da verificação: <?= listEscape((string) $item['name']) ?>" data-bilingual-textarea data-bilingual-autosave-action="save_item_list_instructions" data-list-id="<?= $listId ?>" data-item-id="<?= (int) $item['id'] ?>"><?= listEscape((string) $item['default_instructions']) ?></textarea>
                    <button type="submit">Guardar</button>
                </form>
                <form method="post"
                      data-app-confirm="<?= listEscape(SiteTranslations::text('Apagar este item?', 'Delete this item?')) ?>"
                      data-app-confirm-label="<?= listEscape(SiteTranslations::text('Apagar', 'Delete')) ?>"
                      data-app-cancel-label="<?= listEscape(SiteTranslations::text('Cancelar', 'Cancel')) ?>"
                      data-app-destructive="1">
                    <input type="hidden" name="csrf_token" value="<?= listEscape(Csrf::token()) ?>">
                    <input type="hidden" name="action" value="delete_item"><input type="hidden" name="list_id" value="<?= $listId ?>"><input type="hidden" name="item_id" value="<?= (int) $item['id'] ?>"><?php if ($isMenuListView): ?><input type="hidden" name="list_view" value="menu"><?php endif; ?>
                    <button class="danger subtle" type="submit">Apagar</button>
                </form>
            </div>
        <?php endforeach; ?>
        <form method="post" class="add-item">
            <input type="hidden" name="csrf_token" value="<?= listEscape(Csrf::token()) ?>">
            <input type="hidden" name="action" value="add_item"><input type="hidden" name="list_id" value="<?= $listId ?>"><?php if ($isMenuListView): ?><input type="hidden" name="list_view" value="menu"><?php endif; ?>
            <label><span>Novo item</span><input name="name" maxlength="80" required placeholder="Nome do item"></label>
            <label class="new-instructions"><span>Descrição da verificação</span><textarea name="default_instructions" maxlength="5000" rows="1" placeholder="Descreva a verificação…" data-bilingual-textarea data-bilingual-new-item="1"></textarea></label>
            <button type="submit">Adicionar item</button>
        </form>
    </section>
    <?php endif; ?>
</main>
</body>
</html>
