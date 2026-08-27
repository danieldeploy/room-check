<?php
declare(strict_types=1);

function assertInvalidEditUx(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    echo 'PASS: ' . $message . PHP_EOL;
}

$app = file_get_contents(dirname(__DIR__) . '/assets/app.js');
$feedback = file_get_contents(dirname(__DIR__) . '/assets/validation-feedback.js');
$css = file_get_contents(dirname(__DIR__) . '/assets/app.css');
$rooms = file_get_contents(dirname(__DIR__) . '/rooms.php');
$catalog = file_get_contents(dirname(__DIR__) . '/src/I18n/SiteTranslations.php');

assertInvalidEditUx(is_string($app) && is_string($feedback) && is_string($css) && is_string($rooms) && is_string($catalog), 'UX sources are readable');
assertInvalidEditUx(str_contains($app, "feedbackKind === 'assignment'"), 'instruction validation failure does not force persisted-state rerender');
assertInvalidEditUx(str_contains($feedback, "textarea.classList.add('language-invalid')"), 'rejected text remains marked invalid');
assertInvalidEditUx(str_contains($feedback, "language-wrong-segment"), 'changed language segment is highlighted separately');
assertInvalidEditUx(str_contains($feedback, "textarea.dataset.lastValidValue"), 'last server-confirmed value is retained for cancel edit');
assertInvalidEditUx(str_contains($feedback, "textarea.dataset.languageNeedsValidation = '1'"), 'rejected edit remains pending until server validation succeeds');
assertInvalidEditUx(str_contains($feedback, 'data-language-needs-validation'), 'navigation also guards a correction that has not yet been revalidated');
assertInvalidEditUx(str_contains($feedback, 'config.languageDecisionMessage'), 'dialog message comes from server-localized config');
assertInvalidEditUx(str_contains($feedback, 'config.languageDecisionCorrect'), 'Correct label comes from server-localized config');
assertInvalidEditUx(str_contains($feedback, 'config.languageDecisionCancel'), 'Cancel edit label comes from server-localized config');
assertInvalidEditUx(str_contains($feedback, "#propertySelect, #roomSelect, #listSelect, #intervalSelect, #employeeSelect, #assignmentDate"), 'context changes are guarded');
assertInvalidEditUx(str_contains($feedback, "a[href]"), 'page navigation is guarded');
assertInvalidEditUx(str_contains($feedback, "restoreInvalidEdits()"), 'cancel edit restores persisted content before navigation');
assertInvalidEditUx(str_contains($css, '.language-decision-overlay') && str_contains($css, '.language-wrong-segment'), 'highlight and decision dialog styles are present');
assertInvalidEditUx(str_contains($rooms, "'languageDecisionMessage' => SiteTranslations::text("), 'dialog message is declared bilingually server-side');
assertInvalidEditUx(str_contains($rooms, "'languageDecisionCorrect' => SiteTranslations::text('Corrigir', 'Correct')"), 'Correct button is declared bilingually server-side');
assertInvalidEditUx(str_contains($rooms, "'languageDecisionCancel' => SiteTranslations::text('Anular edição', 'Cancel edit')"), 'Cancel edit button is declared bilingually server-side');
assertInvalidEditUx(str_contains($catalog, "'Tem texto errado em Inglês. Quer corrigir, ou anular a edição?' =>"), 'dialog warning is registered in the static translation catalogue');
assertInvalidEditUx(str_contains($catalog, "'Anular edição' => 'Cancel edit'"), 'cancel-edit label is registered in the static translation catalogue');

echo "Invalid edit UX contract passed.\n";
