<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/I18n/LanguageGuard.php';
require_once __DIR__ . '/support/FakeLexicalLanguageClassifier.php';

function assertValidationFeedback(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    echo 'PASS: ' . $message . PHP_EOL;
}

$root = dirname(__DIR__);
$feedbackJs = file_get_contents($root . '/assets/validation-feedback.js');
$sessionBar = file_get_contents($root . '/src/UI/SessionBar.php');
$feedbackI18n = file_get_contents($root . '/src/I18n/TranslationFeedback.php');
$translator = file_get_contents($root . '/src/I18n/ContentTranslator.php');
$validator = file_get_contents($root . '/translation-validate.php');

assertValidationFeedback(
    is_string($feedbackJs) && is_string($sessionBar) && is_string($feedbackI18n)
        && is_string($translator) && is_string($validator),
    'translation feedback sources are readable'
);
assertValidationFeedback(str_contains($sessionBar, 'assets/validation-feedback.js'), 'authenticated pages load shared save feedback');
assertValidationFeedback(
    str_contains($sessionBar, 'validation-feedback.js?v=') && str_contains($sessionBar, 'filemtime'),
    'shared validation feedback asset is cache-busted after deployment'
);
assertValidationFeedback(
    str_contains($feedbackJs, "feedback.style.opacity = '1'")
        && str_contains($feedbackJs, "feedback.style.opacity = ''"),
    'translation feedback owns its visible lifetime even when generic save feedback removes the shared CSS class'
);
assertValidationFeedback(
    str_contains($feedbackJs, "feedback.setAttribute('data-i18n-skip', '')")
        && str_contains($feedbackJs, "feedback.removeAttribute('data-i18n-skip')")
        && strpos($feedbackJs, "feedback.setAttribute('data-i18n-skip', '')") < strpos($feedbackJs, 'feedback.textContent = String(message)'),
    'server feedback is protected from DOM translation before its literal message is inserted'
);

// Persistent feedback is attached to the canonical server field key before the
// request leaves the browser. This remains stable even when the visible heading
// is translated (Espelho -> Mirror) or the user edits another row meanwhile.
assertValidationFeedback(
    str_contains($feedbackJs, 'row.dataset.fieldKey = key')
        && str_contains($feedbackJs, 'requestRows.get(fieldKey)'),
    'server field keys are bound to the originating DOM row'
);
assertValidationFeedback(
    str_contains($feedbackJs, 'const editedRowAtRequest = lastEditedRow;')
        && str_contains($feedbackJs, 'row === editedRowAtRequest'),
    'success feedback uses the row captured when the request started instead of the latest edited row'
);
assertValidationFeedback(
    !str_contains($feedbackJs, 'row === lastEditedRow'),
    'in-flight success feedback cannot be stolen by a later edit'
);

// Persistable room/assignment text is no longer sent to a separate validator.
assertValidationFeedback(!str_contains($feedbackJs, 'translation-validate.php'), 'room text save has no separate validation endpoint');
assertValidationFeedback(!str_contains($feedbackJs, 'validateTextSave'), 'obsolete prevalidation fetch is removed');
assertValidationFeedback(!str_contains($feedbackJs, 'textFieldsForRequest'), 'obsolete prevalidation request mapper is removed');
assertValidationFeedback(str_contains($feedbackJs, 'const response = await nativeFetch(input, init);'), 'persistent text follows one real API save request');

// Exact server conclusions travel back on the successful save response.
assertValidationFeedback(str_contains($translator, 'X-Room-Translation-Results'), 'server publishes exact save conclusions');
assertValidationFeedback(str_contains($feedbackJs, 'X-Room-Translation-Results'), 'browser reads exact save conclusions');
assertValidationFeedback(str_contains($feedbackJs, 'ROOM_TRANSLATION_RESULT_READER'), 'translation result reader is shared across persistent textarea UIs');
assertValidationFeedback(str_contains($feedbackJs, 'translationResultsFromResponse'), 'green feedback is decoded from the real save response');
assertValidationFeedback(
    str_contains($feedbackJs, 'markSavedRequest(')
        && str_contains($feedbackJs, 'translationResultsFromResponse(response)')
        && str_contains($feedbackJs, 'editedRowAtRequest')
        && str_contains($feedbackJs, 'requestRows'),
    'green feedback is applied to the request-bound row only after persistence succeeds'
);
assertValidationFeedback(!str_contains($feedbackJs, 'sourceConclusion'), 'browser does not reclassify source language');
assertValidationFeedback(!str_contains($feedbackJs, 'translationConclusion'), 'browser does not reclassify translated language');

assertValidationFeedback(str_contains($feedbackJs, 'flushPendingSaves'), 'navigation flushes the real blur save before leaving');
assertValidationFeedback(str_contains($feedbackJs, "dispatchEvent(new Event('blur'))"), 'navigation uses the same real save path');
assertValidationFeedback(!str_contains($feedbackJs, 'language-highlight-layer'), 'duplicate text overlay remains removed');
assertValidationFeedback(!str_contains($feedbackJs, 'invalidWords'), 'browser performs no lexical or word-level validation itself');
assertValidationFeedback(!str_contains($feedbackJs, 'confidentLanguage(') && !str_contains($feedbackJs, 'assertExpectedLanguage('), 'browser contains no server language logic');

// Validation-only endpoint remains for content that cannot yet be persisted.
assertValidationFeedback(
    str_contains($validator, 'ContentTranslator')
        && str_contains($validator, 'sourceConclusion')
        && str_contains($validator, 'translationConclusion')
        && str_contains($validator, "'message'"),
    'validation-only endpoint still exposes exact server conclusions'
);

$lexicon = translationRegressionClassifier();
try {
    LanguageGuard::assertExpectedLanguage('house', 'pt', $lexicon);
    throw new RuntimeException('FAIL: house was accepted in PT mode');
} catch (LanguageValidationException $exception) {
    assertValidationFeedback(
        str_contains($exception->getMessage(), 'claramente EN') && str_contains($exception->getMessage(), 'house'),
        'wrong-language single word receives a clear red error'
    );
}
try {
    LanguageGuard::assertExpectedLanguage('Verificar se está limpo house', 'pt', $lexicon);
    throw new RuntimeException('FAIL: mixed text was accepted in PT mode');
} catch (LanguageValidationException $exception) {
    assertValidationFeedback(
        str_contains($exception->getMessage(), 'mistura PT e EN') && str_contains($exception->getMessage(), 'house'),
        'mixed source identifies the offending EN word'
    );
}
try {
    LanguageGuard::assertExpectedLanguage('Verificar se está limpo qdsffasdfaasdf', 'pt', $lexicon);
    throw new RuntimeException('FAIL: unknown text was accepted in PT mode');
} catch (LanguageValidationException $exception) {
    assertValidationFeedback(
        str_contains($exception->getMessage(), 'palavra não reconhecida') && str_contains($exception->getMessage(), 'qdsffasdfaasdf'),
        'unknown ordinary word receives a clear red error'
    );
}
LanguageGuard::assertExpectedLanguage('extinguisher', 'en', $lexicon);
assertValidationFeedback(true, 'extinguisher is accepted as a real English word');

echo "Single-save translation feedback contract passed.\n";
