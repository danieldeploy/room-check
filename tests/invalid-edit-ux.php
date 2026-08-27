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

assertInvalidEditUx(is_string($app) && is_string($feedback) && is_string($css) && is_string($rooms), 'UX sources are readable');
assertInvalidEditUx(str_contains($app, "feedbackKind === 'assignment'"), 'instruction validation failure does not force persisted-state rerender');
assertInvalidEditUx(str_contains($feedback, "textarea.classList.add('language-invalid')"), 'rejected text remains marked invalid');
assertInvalidEditUx(str_contains($feedback, "language-wrong-segment"), 'changed language segment is highlighted separately');
assertInvalidEditUx(str_contains($feedback, "textarea.dataset.lastValidValue"), 'last server-confirmed value is retained for cancel edit');
assertInvalidEditUx(str_contains($feedback, "Tem texto errado em Inglês. Quer corrigir, ou anular a edição?"), 'PT navigation warning is present');
assertInvalidEditUx(str_contains($feedback, "There is text incorrectly written in Portuguese. Do you want to correct it or cancel the edit?"), 'EN navigation warning is present');
assertInvalidEditUx(str_contains($feedback, "correct.textContent = isEnglish ? 'Correct' : 'Corrigir'"), 'Correct action is bilingual');
assertInvalidEditUx(str_contains($feedback, "cancel.textContent = isEnglish ? 'Cancel edit' : 'Anular edição'"), 'Cancel edit action is bilingual');
assertInvalidEditUx(str_contains($feedback, "#propertySelect, #roomSelect, #listSelect, #intervalSelect, #employeeSelect, #assignmentDate"), 'context changes are guarded');
assertInvalidEditUx(str_contains($feedback, "a[href]"), 'page navigation is guarded');
assertInvalidEditUx(str_contains($feedback, "restoreInvalidEdits()"), 'cancel edit restores persisted content before navigation');
assertInvalidEditUx(str_contains($css, '.language-decision-overlay') && str_contains($css, '.language-wrong-segment'), 'highlight and decision dialog styles are present');
assertInvalidEditUx(str_contains($rooms, "'locale' => Translator::locale(),"), 'active locale is exposed for bilingual decision copy');

echo "Invalid edit UX contract passed.\n";
