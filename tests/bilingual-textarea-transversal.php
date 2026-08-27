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

assertBilingualTextarea(is_string($itemLists) && is_string($api) && is_string($session) && is_string($client) && is_string($css), 'transversal bilingual textarea sources are readable');

preg_match_all('/<textarea\b[^>]*>/i', (string) $itemLists, $matches);
assertBilingualTextarea(count($matches[0]) >= 2, 'item-list editor exposes its instruction textareas');
foreach ($matches[0] as $tag) {
    assertBilingualTextarea(str_contains($tag, 'data-bilingual-textarea'), 'every item-list textarea opts into the transversal bilingual contract');
}
assertBilingualTextarea(str_contains((string) $itemLists, 'data-bilingual-autosave-action="save_item_list_instructions"'), 'existing item instructions autosave on textarea blur');
assertBilingualTextarea(str_contains((string) $itemLists, 'data-bilingual-new-item="1"'), 'new-item instructions use validation-only until the item exists');
assertBilingualTextarea(str_contains((string) $api, "save_item_list_instructions"), 'API exposes item-list instruction autosave');
assertBilingualTextarea(str_contains((string) $api, 'default_instructions_en = :instructions_en'), 'item-list autosave persists paired PT/EN instruction columns');
assertBilingualTextarea(str_contains((string) $api, 'Translator::locale()'), 'item-list autosave follows the selected login language');
assertBilingualTextarea(str_contains((string) $api, '->versions('), 'item-list autosave uses ContentTranslator/LanguageGuard before writing');
assertBilingualTextarea(str_contains((string) $session, 'assets/bilingual-textareas.js'), 'authenticated pages load the transversal bilingual textarea behavior');
assertBilingualTextarea(str_contains((string) $session, 'assets/bilingual-textareas.css'), 'authenticated pages load the transversal bilingual textarea styles');
assertBilingualTextarea(str_contains((string) $client, "textarea[data-bilingual-textarea]"), 'client behavior is opt-in and reusable across the app');
assertBilingualTextarea(str_contains((string) $client, "textarea.addEventListener('blur'"), 'bilingual textarea persistence starts only on blur');
assertBilingualTextarea(!str_contains((string) $client, "textarea.addEventListener('input', () => {\n            void processTextarea"), 'typing does not trigger persistence');
assertBilingualTextarea(str_contains((string) $client, "action: 'validate_bilingual_texts'"), 'all transversal textareas use the same server-only language validator');
assertBilingualTextarea(str_contains((string) $client, 'invalidWords'), 'server-reported invalid words drive red highlighting');
assertBilingualTextarea(str_contains((string) $client, 'bilingualLastValidValue'), 'last server-confirmed content is retained for cancel edit');
assertBilingualTextarea(str_contains((string) $client, 'askDecision'), 'pending invalid content has Correct/Cancel edit protection');
assertBilingualTextarea(str_contains((string) $client, 'restorePending'), 'Cancel edit restores the last valid value');
assertBilingualTextarea(str_contains((string) $css, '.bilingual-wrong-segment'), 'invalid words have transversal red-highlight styling');
assertBilingualTextarea(str_contains((string) $css, 'textarea[data-bilingual-textarea][readonly]:focus'), 'read-only transversal textareas never look selected');

echo "Transversal bilingual textarea contract passed.\n";
