<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/I18n/LanguageGuard.php';

function assertInvalidEditUx(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    echo 'PASS: ' . $message . PHP_EOL;
}

function assertWrongLanguageWord(string $text, string $expectedLanguage, string $word): void
{
    try {
        LanguageGuard::assertExpectedLanguage($text, $expectedLanguage);
    } catch (LanguageValidationException $exception) {
        assertInvalidEditUx(in_array($word, $exception->invalidWords, true), "server identifies wrong-language word: {$word}");
        return;
    }
    throw new RuntimeException("FAIL: wrong-language word was accepted: {$word}");
}

assertWrongLanguageWord('Check that it is clean and undamaged. nuvem', 'en', 'nuvem');
assertWrongLanguageWord('Verificar se está limpo e sem danos. cloud', 'pt', 'cloud');

$app = file_get_contents(dirname(__DIR__) . '/assets/app.js');
$feedback = file_get_contents(dirname(__DIR__) . '/assets/validation-feedback.js');
$css = file_get_contents(dirname(__DIR__) . '/assets/app.css');
$rooms = file_get_contents(dirname(__DIR__) . '/rooms.php');
$catalog = file_get_contents(dirname(__DIR__) . '/src/I18n/SiteTranslations.php');
$api = file_get_contents(dirname(__DIR__) . '/api.php');

assertInvalidEditUx(is_string($app) && is_string($feedback) && is_string($css) && is_string($rooms) && is_string($catalog) && is_string($api), 'UX sources are readable');
assertInvalidEditUx(str_contains($app, "feedbackKind === 'assignment'"), 'instruction validation failure does not force persisted-state rerender');
assertInvalidEditUx(str_contains($api, "validate_bilingual_texts"), 'server exposes validation-only endpoint');
assertInvalidEditUx(str_contains($api, "'invalidWords' => $exception->invalidWords") || str_contains($api, "'invalidWords' => \\$exception->invalidWords"), 'server returns offending words on validation failure');
assertInvalidEditUx(str_contains($feedback, "textarea.classList.add('language-invalid')"), 'rejected text remains marked invalid');
assertInvalidEditUx(str_contains($feedback, "appendHighlightedText"), 'actual server-reported wrong words are highlighted');
assertInvalidEditUx(str_contains($feedback, "field.invalidWords"), 'validation-only response supplies offending words to the highlight');
assertInvalidEditUx(str_contains($feedback, "textarea.dataset.lastValidValue"), 'last server-confirmed value is retained for cancel edit');
assertInvalidEditUx(str_contains($feedback, "textarea.dataset.languageNeedsValidation = '1'"), 'every changed textarea is marked pending before autosave');
assertInvalidEditUx(str_contains($feedback, "validatePendingTextareas"), 'pending text is validated before context change');
assertInvalidEditUx(str_contains($feedback, "action: 'validate_bilingual_texts'"), 'navigation validation calls server without writing');
assertInvalidEditUx(str_contains($feedback, "waitForPendingSave"), 'valid pending text is flushed before navigation');
assertInvalidEditUx(str_contains($feedback, 'config.languageDecisionMessage'), 'dialog message comes from server-localized config');
assertInvalidEditUx(str_contains($feedback, 'config.languageDecisionCorrect'), 'Correct label comes from server-localized config');
assertInvalidEditUx(str_contains($feedback, 'config.languageDecisionCancel'), 'Cancel edit label comes from server-localized config');
assertInvalidEditUx(str_contains($feedback, "#propertySelect, #roomSelect, #listSelect, #intervalSelect, #employeeSelect, #assignmentDate"), 'context changes are guarded');
assertInvalidEditUx(str_contains($feedback, "#createInterval, #deleteInterval"), 'programmatic interval context changes are guarded');
assertInvalidEditUx(str_contains($feedback, "a[href]"), 'page navigation is guarded');
assertInvalidEditUx(str_contains($feedback, "restorePendingEdits()"), 'cancel edit restores last server-confirmed content before navigation');
assertInvalidEditUx(str_contains($css, '.language-decision-overlay') && str_contains($css, '.language-wrong-segment'), 'highlight and decision dialog styles are present');
assertInvalidEditUx(str_contains($css, '.problem-field { position: relative; min-width: 0; }'), 'highlight layer is anchored to the textarea field');
assertInvalidEditUx(
    str_contains($feedback, "position: 'absolute', left: '0', top: '0'")
        && str_contains($feedback, "width: '100%', height:")
        && str_contains($feedback, 'textarea.offsetHeight')
        && !str_contains($feedback, 'textarea.offsetLeft')
        && !str_contains($feedback, 'textarea.offsetTop'),
    'highlight overlay starts at the textarea origin instead of reusing pre-anchor offsets'
);
assertInvalidEditUx(str_contains($rooms, "'languageDecisionMessage' => SiteTranslations::text("), 'dialog message is declared bilingually server-side');
assertInvalidEditUx(str_contains($rooms, "'languageDecisionCorrect' => SiteTranslations::text('Corrigir', 'Correct')"), 'Correct button is declared bilingually server-side');
assertInvalidEditUx(str_contains($rooms, "'languageDecisionCancel' => SiteTranslations::text('Anular edição', 'Cancel edit')"), 'Cancel edit button is declared bilingually server-side');
assertInvalidEditUx(str_contains($catalog, "'Tem texto errado em Inglês. Quer corrigir, ou anular a edição?' =>"), 'dialog warning is registered in the static translation catalogue');
assertInvalidEditUx(str_contains($catalog, "'Anular edição' => 'Cancel edit'"), 'cancel-edit label is registered in the static translation catalogue');
assertInvalidEditUx(str_contains($feedback, 'delete textarea.dataset.languageNeedsValidation'), 'pending validation marker has explicit success/cancel clear paths');
assertInvalidEditUx(
    str_contains($feedback, 'highlightBackground')
        && str_contains($feedback, 'wrong.style.backgroundColor = highlightBackground(layer)')
        && str_contains($css, '.language-highlight-layer .language-wrong-segment { color: var(--wrong, #b91c1c); font-weight: inherit; }'),
    'invalid-language word covers the underlying glyphs without changing text width'
);
assertInvalidEditUx(
    !str_contains($feedback, "textarea.style.color = 'transparent'")
        && !str_contains($feedback, "textarea.style.backgroundColor = 'transparent'")
        && str_contains($feedback, "color: 'transparent'")
        && str_contains($feedback, "pointerEvents: 'none', zIndex: '3'"),
    'invalid-language highlight never makes the textarea itself transparent or non-interactive'
);

echo "Invalid edit UX contract passed.\n";
