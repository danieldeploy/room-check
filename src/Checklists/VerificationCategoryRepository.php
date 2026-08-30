<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/I18n/Translator.php';
require_once dirname(__DIR__) . '/I18n/ContentTranslator.php';

final class VerificationCategoryRepository
{
    private const DEFAULTS = [
        ['slug' => 'rooms', 'name' => 'Quartos', 'name_en' => 'Rooms', 'sort_order' => 10],
        ['slug' => 'shared_bathrooms', 'name' => 'Casas de banho comuns', 'name_en' => 'Shared bathrooms', 'sort_order' => 20],
        ['slug' => 'corridors', 'name' => 'Corredores', 'name_en' => 'Corridors', 'sort_order' => 30],
        ['slug' => 'kitchens', 'name' => 'Cozinhas', 'name_en' => 'Kitchens', 'sort_order' => 40],
        ['slug' => 'terraces', 'name' => 'Terraços', 'name_en' => 'Terraces', 'sort_order' => 50],
    ];

    public static function storageAvailable(PDO $pdo): bool
    {
        return self::tableAvailable($pdo, 'verification_categories');
    }

    public static function all(PDO $pdo, bool $withUsage = false): array
    {
        if (!self::storageAvailable($pdo)) {
            return array_map(static function (array $category): array {
                $category['id'] = 0;
                $category['list_count'] = 0;
                $category['item_count'] = 0;
                $category['assignment_count'] = 0;
                $category['display_name'] = Translator::localized($category['name'], $category['name_en']);
                return $category;
            }, self::DEFAULTS);
        }

        $reminderUsage = $withUsage && self::tableAvailable($pdo, 'whatsapp_assignment_reminders')
            ? ' + (SELECT COUNT(*) FROM whatsapp_assignment_reminders reminder
                    INNER JOIN item_lists list_row ON list_row.id = reminder.list_id
                    WHERE list_row.area = category.slug)'
            : '';
        $usageColumns = $withUsage
            ? ",
                (SELECT COUNT(*) FROM item_lists list_row WHERE list_row.area = category.slug) AS list_count,
                (SELECT COUNT(*) FROM item_list_items item
                    INNER JOIN item_lists list_row ON list_row.id = item.list_id
                    WHERE list_row.area = category.slug) AS item_count,
                ((SELECT COUNT(*) FROM room_item_assignments assignment
                    INNER JOIN item_lists list_row ON list_row.id = assignment.list_id
                    WHERE list_row.area = category.slug)" . $reminderUsage . ') AS assignment_count'
            : ', 0 AS list_count, 0 AS item_count, 0 AS assignment_count';

        $rows = $pdo->query(
            'SELECT category.id, category.slug, category.name, category.name_en, category.sort_order'
            . $usageColumns
            . ' FROM verification_categories category ORDER BY category.sort_order, category.id'
        )->fetchAll();

        return array_map(static function (array $row): array {
            return [
                'id' => (int) $row['id'],
                'slug' => (string) $row['slug'],
                'name' => (string) $row['name'],
                'name_en' => (string) ($row['name_en'] ?? ''),
                'display_name' => Translator::localized((string) $row['name'], (string) ($row['name_en'] ?? '')),
                'sort_order' => (int) $row['sort_order'],
                'list_count' => (int) $row['list_count'],
                'item_count' => (int) $row['item_count'],
                'assignment_count' => (int) $row['assignment_count'],
            ];
        }, $rows);
    }

    public static function exists(PDO $pdo, string $slug): bool
    {
        foreach (self::all($pdo) as $category) {
            if (hash_equals((string) $category['slug'], $slug)) {
                return true;
            }
        }
        return false;
    }

    public static function get(PDO $pdo, int $categoryId): array
    {
        self::requireStorage($pdo);
        $row = self::find($pdo, $categoryId);
        if ($row === null) {
            throw new InvalidArgumentException('Categoria não encontrada.');
        }
        return [
            'id' => (int) $row['id'],
            'slug' => (string) $row['slug'],
            'name' => (string) $row['name'],
            'name_en' => (string) ($row['name_en'] ?? ''),
            'display_name' => Translator::localized((string) $row['name'], (string) ($row['name_en'] ?? '')),
            'sort_order' => (int) $row['sort_order'],
        ];
    }

    public static function create(
        PDO $pdo,
        ContentTranslator $translator,
        string $name,
        int $actorId
    ): int
    {
        self::requireStorage($pdo);
        $versions = $translator->versions($name, Translator::locale());
        $position = (int) $pdo->query('SELECT COALESCE(MAX(sort_order), 0) + 10 FROM verification_categories')->fetchColumn();
        $statement = $pdo->prepare(
            'INSERT INTO verification_categories (slug, name, name_en, sort_order, created_by_user_id)
             VALUES (:slug, :name_pt, :name_en, :sort_order, :actor)'
        );

        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                $statement->execute([
                    'slug' => 'category-' . bin2hex(random_bytes(8)),
                    'name_pt' => (string) $versions['pt'],
                    'name_en' => (string) $versions['en'],
                    'sort_order' => $position,
                    'actor' => $actorId,
                ]);
                return (int) $pdo->lastInsertId();
            } catch (PDOException $exception) {
                if ($exception->getCode() !== '23000' || $attempt === 2) {
                    throw $exception;
                }
            }
        }

        throw new RuntimeException('Não foi possível criar a categoria.');
    }

    public static function rename(
        PDO $pdo,
        ContentTranslator $translator,
        int $categoryId,
        string $name
    ): void
    {
        self::requireStorage($pdo);
        $category = self::get($pdo, $categoryId);
        $versions = $translator->versions(
            $name,
            Translator::locale(),
            (string) $category['name'],
            (string) $category['name_en']
        );
        $statement = $pdo->prepare(
            'UPDATE verification_categories SET name = :name_pt, name_en = :name_en WHERE id = :id'
        );
        $statement->execute([
            'name_pt' => (string) $versions['pt'],
            'name_en' => (string) $versions['en'],
            'id' => $categoryId,
        ]);
        if ($statement->rowCount() < 1 && !self::find($pdo, $categoryId)) {
            throw new InvalidArgumentException('Categoria não encontrada.');
        }
    }

    public static function delete(PDO $pdo, int $categoryId): array
    {
        self::requireStorage($pdo);
        $pdo->beginTransaction();
        try {
            $category = self::find($pdo, $categoryId, true);
            if ($category === null) {
                throw new InvalidArgumentException('Categoria não encontrada.');
            }

            $lockLists = $pdo->prepare('SELECT id FROM item_lists WHERE area = :slug FOR UPDATE');
            $lockLists->execute(['slug' => $category['slug']]);
            $listIds = array_map('intval', $lockLists->fetchAll(PDO::FETCH_COLUMN));

            $usage = self::usageForSlug($pdo, (string) $category['slug']);
            if ($usage['assignment_count'] > 0) {
                throw new DomainException(
                    'Não é possível apagar. Outro utilizador já atribuiu um item a um empregado.'
                );
            }

            if ($listIds !== []) {
                $deleteValues = $pdo->prepare(
                    'DELETE checklist_value FROM room_checklist_values checklist_value
                     INNER JOIN item_lists list_row ON list_row.id = checklist_value.list_id
                     WHERE list_row.area = :slug'
                );
                $deleteValues->execute(['slug' => $category['slug']]);

                $deleteItems = $pdo->prepare(
                    'DELETE item FROM item_list_items item
                     INNER JOIN item_lists list_row ON list_row.id = item.list_id
                     WHERE list_row.area = :slug'
                );
                $deleteItems->execute(['slug' => $category['slug']]);

                $deleteLists = $pdo->prepare('DELETE FROM item_lists WHERE area = :slug');
                $deleteLists->execute(['slug' => $category['slug']]);
            }

            $deleteCategory = $pdo->prepare('DELETE FROM verification_categories WHERE id = :id');
            $deleteCategory->execute(['id' => $categoryId]);
            $pdo->commit();
            return $usage;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    private static function find(PDO $pdo, int $categoryId, bool $forUpdate = false): ?array
    {
        $statement = $pdo->prepare(
            'SELECT id, slug, name, name_en, sort_order FROM verification_categories WHERE id = :id'
            . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $statement->execute(['id' => $categoryId]);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }

    private static function usageForSlug(PDO $pdo, string $slug): array
    {
        $reminderUsage = self::tableAvailable($pdo, 'whatsapp_assignment_reminders')
            ? ' + (SELECT COUNT(*) FROM whatsapp_assignment_reminders reminder
                    INNER JOIN item_lists list_row ON list_row.id = reminder.list_id
                    WHERE list_row.area = :reminder_slug)'
            : '';
        $statement = $pdo->prepare(
            'SELECT
                (SELECT COUNT(*) FROM item_lists list_row WHERE list_row.area = :list_slug) AS list_count,
                (SELECT COUNT(*) FROM item_list_items item
                    INNER JOIN item_lists list_row ON list_row.id = item.list_id
                    WHERE list_row.area = :item_slug) AS item_count,
                ((SELECT COUNT(*) FROM room_item_assignments assignment
                    INNER JOIN item_lists list_row ON list_row.id = assignment.list_id
                    WHERE list_row.area = :assignment_slug)' . $reminderUsage . ') AS assignment_count'
        );
        $parameters = [
            'list_slug' => $slug,
            'item_slug' => $slug,
            'assignment_slug' => $slug,
        ];
        if ($reminderUsage !== '') {
            $parameters['reminder_slug'] = $slug;
        }
        $statement->execute($parameters);
        $row = $statement->fetch() ?: [];
        return [
            'list_count' => (int) ($row['list_count'] ?? 0),
            'item_count' => (int) ($row['item_count'] ?? 0),
            'assignment_count' => (int) ($row['assignment_count'] ?? 0),
        ];
    }

    private static function requireStorage(PDO $pdo): void
    {
        if (!self::storageAvailable($pdo)) {
            throw new RuntimeException(
                'A tabela de categorias ainda não existe. Importe a migração 022_verification_categories.sql.'
            );
        }
    }

    private static function tableAvailable(PDO $pdo, string $table): bool
    {
        if (!in_array($table, ['verification_categories', 'whatsapp_assignment_reminders'], true)) {
            return false;
        }
        try {
            $pdo->query('SELECT 1 FROM ' . $table . ' LIMIT 1');
            return true;
        } catch (PDOException) {
            return false;
        }
    }
}
