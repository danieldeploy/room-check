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
$feedbackI18n = file_get_contents($root . '/src/I18n/TranslationFeedback.php');
$validator = file_get_contents($root . '/translation-validate.php');

assertValidationFeedback(is_string($feedbackJs) && is_string($sessionBar) && is_string($feedbackI18n) && is_string($validator), 'contextual validation sources are readable');
assertValidationFeedback(str_contains($sessionBar, 'assets/validation-feedback.js'), 'authenticated pages load shared save feedback');
assertValidationFeedback(str_contains($feedbackJs, 'validateTextSave'), 'real text save is prevalidated before persistence');
assertValidationFeedback(str_contains($feedbackJs, "const validationUrl = 'translation-validate.php'"), 'room text saves use translation-backed validation endpoint');
assertValidationFeedback(str_contains($feedbackJs, 'nativeFetch(validationUrl'), 'prevalidation bypasses the wrapped save fetch and cannot recurse');
assertValidationFeedback(str_contains($feedbackJs, 'translationValidationMessage'), 'real server conclusion is retained until the save succeeds');
assertValidationFeedback(str_contains($feedbackJs, 'result?.message'), 'green row message comes from the server algorithm result');
assertValidationFeedback(str_contains($feedbackJs, 'sourceConclusion') === false, 'browser does not reinterpret source-language conclusions itself');
assertValidationFeedback(str_contains($feedbackJs, 'translationConclusion') === false, 'browser does not reinterpret translation conclusions itself');
assertValidationFeedback(str_contains($feedbackJs, 'flushPendingSaves'), 'navigation flushes the real blur save before leaving');
assertValidationFeedback(str_contains($feedbackJs, "dispatchEvent(new Event('blur'))"), 'navigation uses the same blur/save translation path');
assertValidationFeedback(!str_contains($feedbackJs, 'language-highlight-layer'), 'duplicate text overlay remains removed');
assertValidationFeedback(!str_contains($feedbackJs, 'invalidWords'), 'browser no longer performs word-level validation/highlighting');
assertValidationFeedback(!str_contains($feedbackJs, 'confidentLanguage(') && !str_contains($feedbackJs, 'assertExpectedLanguage('), 'browser does not duplicate server language detection');
assertValidationFeedback(str_contains($validator, 'sourceConclusion') && str_contains($validator, 'translationConclusion') && str_contains($validator, "'message'"), 'validation endpoint returns explicit source, target and green message metadata');

try {
    LanguageGuard::assertExpectedLanguage('new house', 'pt');
    throw new RuntimeException('FAIL: short English phrase was accepted in PT mode');
} catch (LanguageValidationException $exception) {
    assertValidationFeedback($exception->getMessage() === 'Erro: texto claramente EN.', 'short wrong-language source reports a clear red error');
}
try {
    LanguageGuard::assertExpectedLanguage('new house na nossa rua', 'pt');
    throw new RuntimeException('FAIL: mixed text was accepted in PT mode');
} catch (LanguageValidationException $exception) {
    assertValidationFeedback($exception->getMessage() === 'Erro: o texto mistura PT e EN.', 'mixed source reports a clear red error');
}

LanguageGuard::assertExpectedLanguage('Check the fire extinguisher detector and HVAC thermostat.', 'en');
assertValidationFeedback(true, 'technical English no longer creates false inline errors');

echo "Contextual translation feedback contract passed.\n";
