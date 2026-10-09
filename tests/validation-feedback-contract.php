<?php
declare(strict_types=1);

function assertTranslationFeedback(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    echo 'PASS: ' . $message . PHP_EOL;
}

$root = dirname(__DIR__);
$client = file_get_contents($root . '/assets/bilingual-textareas.js');
$feedback = file_get_contents($root . '/assets/validation-feedback.js');
$translator = file_get_contents($root . '/src/I18n/ContentTranslator.php');
$api = file_get_contents($root . '/api.php');
assertTranslationFeedback(is_string($client) && is_string($feedback) && is_string($translator) && is_string($api), 'feedback sources are readable');
assertTranslationFeedback(!str_contains($client, 'translation-validate.php'), 'browser has no translation preview endpoint');
assertTranslationFeedback(!str_contains($client, 'validateTextarea'), 'browser has no linguistic validation request');
assertTranslationFeedback(str_contains($client, 'textarea.dataset.bilingualAutosaveAction'), 'blur calls only a real persistence action');
assertTranslationFeedback(str_contains($client, 'allowFormSubmit'), 'new-item text proceeds through its real form submission');
assertTranslationFeedback(!str_contains($api, 'invalidWords') && !str_contains($api, 'LanguageValidationException'), 'API returns no lexical validation contract');
assertTranslationFeedback(str_contains($translator, 'X-Room-Translation-Results'), 'real saves still publish concise per-save feedback');
assertTranslationFeedback(str_contains($feedback, 'X-Room-Translation-Results'), 'room UI reads feedback from the real save response');
assertTranslationFeedback(str_contains($translator, 'Guardado e traduzido.'), 'success feedback describes saving and translation without claiming linguistic validation');

echo "Translation save feedback contract passed.\n";
