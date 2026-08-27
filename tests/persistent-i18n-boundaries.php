<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/I18n/SiteTranslations.php';

function assertPersistentI18n(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    echo 'PASS: ' . $message . PHP_EOL;
}

$root = dirname(__DIR__);
$read = static function (string $path) use ($root): string {
    $content = file_get_contents($root . '/' . $path);
    if (!is_string($content)) {
        throw new RuntimeException('Unable to read ' . $path);
    }
    return $content;
};

$itemLists = $read('item-lists.php');
$rooms = $read('rooms.php');
$api = $read('api.php');
$tasks = $read('tasks.php');

// Editable bilingual content must use the active persisted language, never the
// canonical PT column merely because it is the internal identity.
assertPersistentI18n(
    !str_contains($itemLists, "value=\"<?= listEscape($selectedList['name']) ?>\""),
    'list edit input cannot render the canonical PT name directly'
);
assertPersistentI18n(
    str_contains($itemLists, 'Translator::localized('),
    'item-list editor localizes persisted bilingual values explicitly'
);
assertPersistentI18n(
    str_contains($rooms, "interval['name'] = Translator::localized("),
    'interval editor receives the active persisted language'
);
assertPersistentI18n(
    str_contains($api, "'problem' => Translator::localized("),
    'room problem editor receives the active persisted language'
);
assertPersistentI18n(
    str_contains($api, "'instructions' => Translator::localized("),
    'assignment instructions receive the active persisted language'
);

// Display-only persistent content must also be localized from its paired DB
// columns at the server/data boundary instead of relying on accidental DOM
// replacement of the canonical Portuguese text.
assertPersistentI18n(
    str_contains($rooms, "'displayName' => Translator::localized("),
    'room list view-model exposes an explicit localized list name'
);
assertPersistentI18n(
    str_contains($rooms, "htmlspecialchars((string) $list['displayName']"),
    'room list selector renders the localized view-model value'
);
assertPersistentI18n(
    str_contains($tasks, "NULLIF(TRIM(list_row.name_en)"),
    'employee task list names select the active persisted language'
);
assertPersistentI18n(
    str_contains($tasks, "NULLIF(TRIM(item.name_en)"),
    'employee task item names select the active persisted language'
);
assertPersistentI18n(
    str_contains($tasks, "NULLIF(TRIM(v.problem_en)"),
    'employee task problem text selects the active persisted language'
);

// Machine identity must remain canonical. Display localization must never
// replace internal list IDs, assignment IDs or canonical item keys.
assertPersistentI18n(
    str_contains($api, '$listItems = $selectedList[\'items\'];'),
    'API keeps canonical item keys for writes and assignment identity'
);
assertPersistentI18n(
    str_contains($tasks, 'a.item_name AS canonical_item_name') || str_contains($tasks, 'a.item_name,'),
    'task query keeps access to the canonical item identity'
);

echo "Persistent bilingual boundary audit passed.\n";
