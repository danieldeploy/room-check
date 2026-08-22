<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';
$config = require __DIR__ . '/config.php';
require_once __DIR__ . '/src/Auth/Auth.php';
require_once __DIR__ . '/src/Security/Csrf.php';
require_once __DIR__ . '/src/UI/SessionBar.php';

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
$listId = (int) ($_GET['list_id'] ?? 0);
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        Csrf::validate($_POST['csrf_token'] ?? null);
        $action = (string) ($_POST['action'] ?? '');
        $listId = (int) ($_POST['list_id'] ?? 0);
        $itemId = (int) ($_POST['item_id'] ?? 0);
        $name = trim((string) ($_POST['name'] ?? ''));
        if (in_array($action, ['create_list', 'rename_list'], true)) {
            if ($name === '' || mb_strlen($name) > 120) {
                throw new InvalidArgumentException('O nome da lista deve ter entre 1 e 120 caracteres.');
            }
        } elseif (in_array($action, ['add_item', 'rename_item'], true)) {
            if ($name === '' || mb_strlen($name) > 80) {
                throw new InvalidArgumentException('O nome do item deve ter entre 1 e 80 caracteres.');
            }
        }

        if ($action === 'create_list') {
            $statement = $pdo->prepare(
                'INSERT INTO item_lists (name, created_by_user_id) VALUES (:name, :user_id)'
            );
            $statement->execute(['name' => $name, 'user_id' => (int) $currentUser['id']]);
            $listId = (int) $pdo->lastInsertId();
            $message = 'Lista criada.';
        } elseif ($action === 'rename_list') {
            itemList($pdo, $listId);
            $statement = $pdo->prepare('UPDATE item_lists SET name = :name WHERE id = :id');
            $statement->execute(['name' => $name, 'id' => $listId]);
            $message = 'Nome da lista atualizado.';
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
            $position = $pdo->prepare(
                'SELECT COALESCE(MAX(sort_order), 0) + 10 FROM item_list_items WHERE list_id = :list_id'
            );
            $position->execute(['list_id' => $listId]);
            $statement = $pdo->prepare(
                'INSERT INTO item_list_items (list_id, name, sort_order) VALUES (:list_id, :name, :sort_order)'
            );
            $statement->execute([
                'list_id' => $listId, 'name' => $name, 'sort_order' => (int) $position->fetchColumn(),
            ]);
            $message = 'Item adicionado.';
        } elseif ($action === 'rename_item') {
            $pdo->beginTransaction();
            $statement = $pdo->prepare(
                'SELECT name FROM item_list_items WHERE id = :id AND list_id = :list_id FOR UPDATE'
            );
            $statement->execute(['id' => $itemId, 'list_id' => $listId]);
            $oldName = $statement->fetchColumn();
            if ($oldName === false) {
                throw new InvalidArgumentException('Item não encontrado.');
            }
            $renameValues = $pdo->prepare(
                'UPDATE room_checklist_values SET item_name = :new_name
                 WHERE list_id = :list_id AND item_name = :old_name'
            );
            $renameValues->execute(['new_name' => $name, 'list_id' => $listId, 'old_name' => $oldName]);
            $renameAssignments = $pdo->prepare(
                'UPDATE room_item_assignments SET item_name = :new_name
                 WHERE list_id = :list_id AND item_name = :old_name'
            );
            $renameAssignments->execute(['new_name' => $name, 'list_id' => $listId, 'old_name' => $oldName]);
            $renameItem = $pdo->prepare('UPDATE item_list_items SET name = :name WHERE id = :id');
            $renameItem->execute(['name' => $name, 'id' => $itemId]);
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

$lists = itemLists($pdo);
if ($listId === 0 || !array_filter($lists, static fn(array $list): bool => $list['id'] === $listId)) {
    $listId = (int) ($lists[0]['id'] ?? 0);
}
$selectedList = array_values(array_filter($lists, static fn(array $list): bool => $list['id'] === $listId))[0] ?? null;
$itemRows = [];
if ($selectedList) {
    $statement = $pdo->prepare(
        'SELECT id, name FROM item_list_items WHERE list_id = :list_id ORDER BY sort_order, id'
    );
    $statement->execute(['list_id' => $listId]);
    $itemRows = $statement->fetchAll();
}
$canManageUsers = Auth::hasPermission($pdo, $currentUser, Auth::PERMISSION_USERS_MANAGE);
$canManagePermissions = Auth::hasPermission($pdo, $currentUser, Auth::PERMISSION_PERMISSIONS_MANAGE);
function listEscape(string $value): string { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
header('Cache-Control: no-store');
?>
<!doctype html>
<html lang="pt">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#0f766e">
    <title>Listas de Itens — Active Lines Unip. Lda.</title>
    <link rel="stylesheet" href="assets/item-lists.css?v=<?= (int) filemtime(__DIR__ . '/assets/item-lists.css') ?>">
    <link rel="stylesheet" href="assets/session.css?v=<?= (int) filemtime(__DIR__ . '/assets/session.css') ?>">
</head>
<body>
<main class="lists-shell">
    <?php SessionBar::render($currentUser, '', $canManageUsers, $canManagePermissions); ?>
    <header class="module-header">
        <p class="eyebrow">Operações do Alojamento</p>
        <nav class="module-tabs" aria-label="Gestão de quartos e listas">
            <a href="rooms.php">GESTÃO QUARTOS</a>
            <a class="active" href="item-lists.php" aria-current="page">LISTAS DE ITENS</a>
        </nav>
    </header>
    <?php if ($message): ?><div class="notice success" role="status"><?= listEscape($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="notice error" role="alert"><?= listEscape($error) ?></div><?php endif; ?>

    <section class="list-toolbar">
        <form method="get">
            <label><span>Lista a editar</span><select name="list_id" onchange="this.form.submit()">
                <?php foreach ($lists as $list): ?><option value="<?= $list['id'] ?>" <?= $list['id'] === $listId ? 'selected' : '' ?>><?= listEscape($list['name']) ?></option><?php endforeach; ?>
            </select></label>
        </form>
        <form method="post" class="create-list">
            <input type="hidden" name="csrf_token" value="<?= listEscape(Csrf::token()) ?>">
            <input type="hidden" name="action" value="create_list">
            <label><span>Nova lista</span><input name="name" maxlength="120" required placeholder="Nome da nova lista"></label>
            <button type="submit">Criar lista</button>
        </form>
    </section>

    <?php if ($selectedList): ?>
    <section class="list-card">
        <div class="list-card-heading">
            <form method="post" class="rename-list">
                <input type="hidden" name="csrf_token" value="<?= listEscape(Csrf::token()) ?>">
                <input type="hidden" name="action" value="rename_list">
                <input type="hidden" name="list_id" value="<?= $listId ?>">
                <input name="name" maxlength="120" required value="<?= listEscape($selectedList['name']) ?>" aria-label="Nome da lista">
                <button type="submit">Guardar nome</button>
            </form>
            <?php if (!$selectedList['isSystem']): ?><form method="post" onsubmit="return confirm('Apagar esta lista?')">
                <input type="hidden" name="csrf_token" value="<?= listEscape(Csrf::token()) ?>">
                <input type="hidden" name="action" value="delete_list"><input type="hidden" name="list_id" value="<?= $listId ?>">
                <button class="danger" type="submit">Apagar lista</button>
            </form><?php endif; ?>
        </div>
        <div class="items-heading"><span>Itens da lista</span><span>Ações</span></div>
        <?php if ($itemRows === []): ?><p class="empty">Esta lista ainda não tem itens.</p><?php endif; ?>
        <?php foreach ($itemRows as $item): ?>
            <div class="item-row">
                <form method="post" class="rename-item">
                    <input type="hidden" name="csrf_token" value="<?= listEscape(Csrf::token()) ?>">
                    <input type="hidden" name="action" value="rename_item"><input type="hidden" name="list_id" value="<?= $listId ?>"><input type="hidden" name="item_id" value="<?= (int) $item['id'] ?>">
                    <input name="name" maxlength="80" required value="<?= listEscape((string) $item['name']) ?>" aria-label="Nome do item">
                    <button type="submit">Guardar</button>
                </form>
                <form method="post" onsubmit="return confirm('Apagar este item?')">
                    <input type="hidden" name="csrf_token" value="<?= listEscape(Csrf::token()) ?>">
                    <input type="hidden" name="action" value="delete_item"><input type="hidden" name="list_id" value="<?= $listId ?>"><input type="hidden" name="item_id" value="<?= (int) $item['id'] ?>">
                    <button class="danger subtle" type="submit">Apagar</button>
                </form>
            </div>
        <?php endforeach; ?>
        <form method="post" class="add-item">
            <input type="hidden" name="csrf_token" value="<?= listEscape(Csrf::token()) ?>">
            <input type="hidden" name="action" value="add_item"><input type="hidden" name="list_id" value="<?= $listId ?>">
            <label><span>Novo item</span><input name="name" maxlength="80" required placeholder="Nome do item"></label>
            <button type="submit">Adicionar item</button>
        </form>
    </section>
    <?php endif; ?>
</main>
</body>
</html>
