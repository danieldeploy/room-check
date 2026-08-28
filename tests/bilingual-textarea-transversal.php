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
$feedback = file_get_contents($root . '/assets/validation-feedback.js');
$css = file_get_contents($root . '/assets/bilingual-textareas.css');
$validator = file_get_contents($root . '/translation-validate.php');

assertBilingualTextarea(
    is_string($itemLists) && is_string($api) && is_string($session) && is_string($client)
        && is_string($feedback) && is_string($css) && is_string($validator),
    'transversal bilingual textarea sources are readable'
);

$textareaCount = substr_count((string) $itemLists, '<textarea');
$contractCount = substr_count((string) $itemLists, 'data-bilingual-textarea');
assertBilingualTextarea($textareaCount >= 2, 'item-list editor exposes instruction textareas');
assertBilingualTextarea($contractCount === $textareaCount, 'every item-list textarea opts into the transversal bilingual contract');
assertBilingualTextarea(str_contains((string) $itemLists, 'data-bilingual-autosave-action="save_item_list_instructions"'), 'existing instructions expose a real persistence action');
assertBilingualTextarea(str_contains((string) $itemLists, 'data-bilingual-new-item="1"'), 'new-item instructions are identifiable before the item exists');
assertBilingualTextarea(str_contains((string) $api, 'save_item_list_instructions'), 'API persists existing item-list instructions');
assertBilingualTextarea(str_contains((string) $api, 'default_instructions_en = :instructions_en'), 'item-list autosave persists PT/EN pair');
assertBilingualTextarea(str_contains((string) $api, 'Translator::locale()') && str_contains((string) $api, '->versions('), 'real item-list save owns validation and translation');

assertBilingualTextarea(str_contains((string) $session, 'assets/bilingual-textareas.js'), 'authenticated pages load transversal textarea behavior');
assertBilingualTextarea(str_contains((string) $client, "textarea[data-bilingual-textarea]"), 'client behavior stays reusable and opt-in');
assertBilingualTextarea(str_contains((string) $client, "textarea.addEventListener('blur'"), 'translation/save starts on blur, not while typing');

// Existing text goes directly to api.php. Only not-yet-persistable content uses
// translation-validate.php.
assertBilingualTextarea(str_contains((string) $client, 'if (persistWhenPossible && textarea.dataset.bilingualAutosaveAction)'), 'persistable textarea selects real save path first');
assertBilingualTextarea(str_contains((string) $client, 'return autosaveTextarea(textarea);'), 'persistable textarea saves directly without a separate validation fetch');
assertBilingualTextarea(str_contains((string) $client, 'const validation = await validateTextarea(textarea);'), 'validation-only path remains for new/non-persistable text');
assertBilingualTextarea(str_contains((string) $client, 'translation-validate.php'), 'non-persistable text uses validation-only endpoint');
assertBilingualTextarea(str_contains((string) $validator, 'ContentTranslator') && str_contains((string) $validator, '->versions('), 'validation-only endpoint uses the same server algorithm');

assertBilingualTextarea(str_contains((string) $client, 'ROOM_TRANSLATION_RESULT_READER'), 'existing textarea reads conclusion from real save response');
assertBilingualTextarea(str_contains((string) $feedback, 'ROOM_TRANSLATION_RESULT_READER'), 'shared save feedback exposes the server-result reader');
assertBilingualTextarea(!str_contains((string) $client, 'Saved: translation correct or ambiguous'), 'obsolete generic success conclusion is removed');
assertBilingualTextarea(str_contains((string) $client, 'bilingualLastValidValue'), 'last saved value is retained for Cancel edit');
assertBilingualTextarea(str_contains((string) $client, 'askDecision') && str_contains((string) $client, 'restorePending'), 'Correct/Cancel edit protection remains');
assertBilingualTextarea(!str_contains((string) $client, 'bilingual-highlight-layer'), 'duplicate text overlay is absent');
assertBilingualTextarea(!str_contains((string) $client, 'invalidWords'), 'browser contains no word-level invalid highlighting');
assertBilingualTextarea(str_contains((string) $css, 'textarea[data-bilingual-textarea][readonly]:focus'), 'read-only textarea appearance remains preserved');

echo "Single-save bilingual textarea contract passed.\n";
