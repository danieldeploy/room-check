<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/I18n/LanguageGuard.php';

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
$validator = file_get_contents($root . '/translation-validate.php');

assertValidationFeedback(is_string($feedbackJs) && is_string($sessionBar) && is_string($validator), 'contextual validation sources are readable');
assertValidationFeedback(str_contains($sessionBar, 'assets/validation-feedback.js'), 'authenticated pages load shared save feedback');
assertValidationFeedback(str_contains($feedbackJs, 'payload?.error'), 'server translation errors are rendered inline');
assertValidationFeedback(str_contains($feedbackJs, 'Saved: translation correct or ambiguous'), 'English fallback explains the algorithm conclusion');
assertValidationFeedback(str_contains($sessionBar, 'Guardado: tradução correta ou ambígua') && str_contains($sessionBar, 'Saved: translation correct or ambiguous'), 'success conclusion is declared bilingually server-side');
assertValidationFeedback(str_contains($feedbackJs, 'ROOM_TRANSLATION_FEEDBACK'), 'browser consumes centrally localized translation messages');
assertValidationFeedback(str_contains($feedbackJs, 'flushPendingSaves'), 'navigation flushes the real blur save before leaving');
assertValidationFeedback(str_contains($feedbackJs, "dispatchEvent(new Event('blur'))"), 'navigation uses the same blur/save translation path');
assertValidationFeedback(!str_contains($feedbackJs, 'language-highlight-layer'), 'duplicate text overlay is removed');
assertValidationFeedback(!str_contains($feedbackJs, 'invalidWords'), 'browser no longer performs word-level validation/highlighting');
assertValidationFeedback(!str_contains($feedbackJs, 'confidentLanguage(') && !str_contains($feedbackJs, 'assertExpectedLanguage('), 'browser does not duplicate server language detection');
assertValidationFeedback(str_contains($validator, 'ContentTranslator') && str_contains($validator, '->versions('), 'validation-only endpoint uses the translation/cache algorithm');

try {
    LanguageGuard::assertExpectedLanguage('Verificar a limpeza da cozinha e das janelas.', 'en');
    throw new RuntimeException('FAIL: Portuguese sentence was accepted in EN mode');
} catch (LanguageValidationException $exception) {
    assertValidationFeedback($exception->getMessage() === 'Error: text is clearly PT.', 'English UI reports clearly detected PT');
}
try {
    LanguageGuard::assertExpectedLanguage('Check the kitchen, windows and curtains.', 'pt');
    throw new RuntimeException('FAIL: English sentence was accepted in PT mode');
} catch (LanguageValidationException $exception) {
    assertValidationFeedback($exception->getMessage() === 'Erro: texto claramente EN.', 'Portuguese UI reports clearly detected EN');
}

LanguageGuard::assertExpectedLanguage('Check the fire extinguisher detector and HVAC thermostat.', 'en');
assertValidationFeedback(true, 'technical English no longer creates false inline errors');

echo "Contextual translation feedback contract passed.\n";
