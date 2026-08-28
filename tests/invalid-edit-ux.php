<?php
declare(strict_types=1);

function assertInvalidEditUx(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    echo 'PASS: ' . $message . PHP_EOL;
}

$root = dirname(__DIR__);
$app = file_get_contents($root . '/assets/app.js');
$feedback = file_get_contents($root . '/assets/validation-feedback.js');
$css = file_get_contents($root . '/assets/app.css');
$rooms = file_get_contents($root . '/rooms.php');
$validator = file_get_contents($root . '/translation-validate.php');

assertInvalidEditUx(is_string($app) && is_string($feedback) && is_string($css) && is_string($rooms) && is_string($validator), 'UX sources are readable');
assertInvalidEditUx(str_contains($feedback, "textarea.classList.add('language-invalid')"), 'rejected text remains marked invalid without changing its contents');
assertInvalidEditUx(str_contains($feedback, 'textarea.dataset.lastValidValue'), 'last server-confirmed value is retained for cancel edit');
assertInvalidEditUx(str_contains($feedback, "textarea.dataset.languageNeedsValidation = '1'"), 'changed text is marked pending until blur save finishes');
assertInvalidEditUx(str_contains($feedback, 'flushPendingSaves'), 'pending text is flushed through real save before context navigation');
assertInvalidEditUx(str_contains($feedback, "dispatchEvent(new Event('blur'))"), 'context navigation reuses blur save rather than a separate word validator');
assertInvalidEditUx(str_contains($feedback, 'config.languageDecisionMessage'), 'Correct/Cancel dialog remains localized');
assertInvalidEditUx(str_contains($feedback, 'config.languageDecisionCorrect') && str_contains($feedback, 'config.languageDecisionCancel'), 'Correct/Cancel labels remain localized');
assertInvalidEditUx(str_contains($feedback, "#propertySelect, #roomSelect, #listSelect, #intervalSelect, #employeeSelect, #assignmentDate"), 'context changes remain guarded');
assertInvalidEditUx(str_contains($feedback, "#createInterval, #deleteInterval"), 'interval actions remain guarded');
assertInvalidEditUx(str_contains($feedback, "a[href]"), 'page navigation remains guarded');
assertInvalidEditUx(str_contains($feedback, 'restorePendingEdits'), 'Cancel edit restores last server-confirmed content');
assertInvalidEditUx(!str_contains($feedback, 'language-highlight-layer'), 'duplicate absolute text layer is removed from feedback JavaScript');
assertInvalidEditUx(!str_contains($feedback, 'appendHighlightedText'), 'word-overlay renderer is removed');
assertInvalidEditUx(!str_contains($feedback, 'invalidWords'), 'word-level validation payload is no longer used by the UI');
assertInvalidEditUx(!str_contains($feedback, "position: 'absolute', left: '0', top: '0'"), 'textarea feedback never paints a second text copy over the original');
assertInvalidEditUx(str_contains($feedback, 'Saved: translation correct or ambiguous'), 'successful save explains contextual translation conclusion');
assertInvalidEditUx(str_contains($validator, 'ContentTranslator') && str_contains($validator, '->versions('), 'validation-only flow uses the contextual translation algorithm');
assertInvalidEditUx(
    str_contains($feedback, 'keepValidationTextareaEditable')
        && str_contains($feedback, 'checkbox && checkbox.checked && !checkbox.disabled')
        && str_contains($feedback, 'textarea.readOnly = false'),
    'translation failures keep active assigned textarea editable'
);
assertInvalidEditUx(
    str_contains($app, "feedbackKind === 'instructions'")
        && str_contains($app, 'row?.assignmentCheckbox?.checked && !row.assignmentCheckbox.disabled')
        && str_contains($app, 'row.textarea.readOnly = false'),
    'rejected assignment instructions remain editable without unlocking unassigned items'
);
assertInvalidEditUx(str_contains($rooms, "'languageDecisionMessage' => SiteTranslations::text("), 'decision dialog remains server-localized');

echo "Non-overlay invalid edit UX contract passed.\n";
