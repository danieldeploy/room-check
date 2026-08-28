<?php
declare(strict_types=1);

function assertBilingualTextarea(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    echo 'PASS: ' . $message . PHP_EOL;
}

$root = dirname(__DIR__);
$itemLists = file_get_contents($root . '/item-lists.php');
$api = file_get_contents($root . '/api.php');
$session = file_get_contents($root . '/src/UI/SessionBar.php');
$client = file_get_contents($root . '/assets/bilingual-textareas.js');
$css = file_get_contents($root . '/assets/bilingual-textareas.css');
$validator = file_get_contents($root . '/translation-validate.php');

assertBilingualTextarea(is_string($itemLists) && is_string($api) && is_string($session) && is_string($client) && is_string($css) && is_string($validator), 'transversal bilingual textarea sources are readable');

$textareaCount = substr_count((string) $itemLists, '<textarea');
$contractCount = substr_count((string) $itemLists, 'data-bilingual-textarea');
assertBilingualTextarea($textareaCount >= 2, 'item-list editor exposes its instruction textareas');
assertBilingualTextarea($contractCount === $textareaCount, 'every item-list textarea opts into the transversal bilingual contract');
assertBilingualTextarea(str_contains((string) $itemLists, 'data-bilingual-autosave-action="save_item_list_instructions"'), 'existing instructions autosave on blur');
assertBilingualTextarea(str_contains((string) $itemLists, 'data-bilingual-new-item="1"'), 'new-item instructions remain validation-only before the item exists');
assertBilingualTextarea(str_contains((string) $api, 'save_item_list_instructions'), 'API exposes item-list instruction autosave');
assertBilingualTextarea(str_contains((string) $api, 'default_instructions_en = :instructions_en'), 'item-list autosave persists paired PT/EN columns');
assertBilingualTextarea(str_contains((string) $api, 'Translator::locale()') && str_contains((string) $api, '->versions('), 'item-list save uses active locale and ContentTranslator');
assertBilingualTextarea(str_contains((string) $session, 'assets/bilingual-textareas.js'), 'authenticated pages load transversal textarea behavior');
assertBilingualTextarea(str_contains((string) $client, "textarea[data-bilingual-textarea]"), 'client behavior is reusable and opt-in');
assertBilingualTextarea(str_contains((string) $client, "textarea.addEventListener('blur'"), 'translation/save starts only on blur');
assertBilingualTextarea(str_contains((string) $client, 'translation-validate.php'), 'validation-only textarea path uses contextual translation endpoint');
assertBilingualTextarea(str_contains((string) $validator, 'ContentTranslator') && str_contains((string) $validator, '->versions('), 'validation-only endpoint invokes translation/cache logic');
assertBilingualTextarea(str_contains((string) $client, 'Saved: translation correct or ambiguous'), 'success feedback reports correct-or-ambiguous conclusion');
assertBilingualTextarea(str_contains((string) $client, 'bilingualLastValidValue'), 'last saved value is retained for Cancel edit');
assertBilingualTextarea(str_contains((string) $client, 'askDecision') && str_contains((string) $client, 'restorePending'), 'Correct/Cancel edit protection remains');
assertBilingualTextarea(!str_contains((string) $client, 'bilingual-highlight-layer'), 'duplicate text overlay is removed');
assertBilingualTextarea(!str_contains((string) $client, 'invalidWords'), 'word-level invalid highlighting is removed');
assertBilingualTextarea(str_contains((string) $css, 'textarea[data-bilingual-textarea][readonly]:focus'), 'read-only textarea appearance remains preserved');

echo "Translation-backed bilingual textarea contract passed.\n";
