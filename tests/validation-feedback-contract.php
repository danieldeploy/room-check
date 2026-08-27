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

function captureLanguageGuardMessage(string $text, string $locale): string
{
    try {
        LanguageGuard::assertExpectedLanguage($text, $locale);
    } catch (InvalidArgumentException $exception) {
        return $exception->getMessage();
    }
    throw new RuntimeException('FAIL: expected LanguageGuard to reject mixed-language text');
}

$root = dirname(__DIR__);
$feedbackJs = file_get_contents($root . '/assets/validation-feedback.js');
$sessionBar = file_get_contents($root . '/src/UI/SessionBar.php');

assertValidationFeedback(is_string($feedbackJs), 'shared validation feedback JavaScript is readable');
assertValidationFeedback(is_string($sessionBar), 'SessionBar source is readable');
assertValidationFeedback(
    str_contains($sessionBar, "assets/validation-feedback.js"),
    'authenticated pages load the shared validation feedback layer'
);
assertValidationFeedback(
    str_contains($feedbackJs, "payload?.error"),
    'the inline message comes from the server validation response'
);
assertValidationFeedback(
    str_contains($feedbackJs, "feedback.textContent = String(message)"),
    'server validation text is rendered in the same row feedback element used for save feedback'
);
assertValidationFeedback(
    str_contains($feedbackJs, "feedback.classList.add('is-visible')"),
    'validation feedback becomes visibly announced beside the edited item'
);
assertValidationFeedback(
    str_contains($feedbackJs, "feedback.textContent = 'Guardado'"),
    'successful correction restores the normal Saved/Guardado feedback'
);
assertValidationFeedback(
    !str_contains($feedbackJs, 'confidentLanguage(')
        && !str_contains($feedbackJs, 'assertExpectedLanguage('),
    'browser feedback does not duplicate the server-side language detector'
);

$englishMessage = captureLanguageGuardMessage('Check the room quarto', 'en');
$portugueseMessage = captureLanguageGuardMessage('Verificar o quarto room', 'pt');
assertValidationFeedback(
    $englishMessage === 'This text contains errors. Please write it in English only.',
    'English login receives the concise validation message in English'
);
assertValidationFeedback(
    $portugueseMessage === 'Este texto contém erros. Escreva-o apenas em português.',
    'Portuguese login receives the concise validation message in Portuguese'
);
assertValidationFeedback(
    !str_contains($englishMessage, 'mixes Portuguese and English')
        && !str_contains($portugueseMessage, 'mistura português e inglês'),
    'obsolete mixed-language wording cannot return'
);

echo "Inline bilingual validation feedback contract passed.\n";
