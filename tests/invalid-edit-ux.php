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
$translator = file_get_contents($root . '/src/I18n/ContentTranslator.php');

assertInvalidEditUx(is_string($app) && is_string($feedback) && is_string($css) && is_string($rooms) && is_string($validator) && is_string($translator), 'UX sources are readable');
assertInvalidEditUx(str_contains($feedback, "textarea.classList.add('language-invalid')"), 'rejected text remains marked invalid without changing its contents');
assertInvalidEditUx(str_contains($feedback, 'textarea.dataset.lastValidValue'), 'last server-confirmed value is retained for cancel edit');
assertInvalidEditUx(str_contains($feedback, "textarea.dataset.languageNeedsValidation = '1'"), 'changed text is marked pending until blur save finishes');
assertInvalidEditUx(str_contains($feedback, 'flushPendingSaves'), 'pending text is flushed through real save before context navigation');
assertInvalidEditUx(str_contains($feedback, "dispatchEvent(new Event('blur'))"), 'context navigation reuses the same real save path');
assertInvalidEditUx(str_contains($feedback, 'config.languageDecisionMessage'), 'Correct/Cancel dialog remains localized');
assertInvalidEditUx(str_contains($feedback, 'config.languageDecisionCorrect') && str_contains($feedback, 'config.languageDecisionCancel'), 'Correct/Cancel labels remain localized');
assertInvalidEditUx(str_contains($feedback, "#propertySelect, #roomSelect, #listSelect, #intervalSelect, #employeeSelect, #assignmentDate"), 'all checklist context selections remain guarded');
assertInvalidEditUx(str_contains($feedback, "#createInterval, #saveInterval, #deleteInterval"), 'all interval actions that can replace visible checklist state remain guarded');
assertInvalidEditUx(str_contains($feedback, "a[href]"), 'page navigation remains guarded');
assertInvalidEditUx(str_contains($feedback, 'restoreBlockingEdits'), 'Cancel edit restores last server-confirmed content for pending or failed edits');
assertInvalidEditUx(str_contains($feedback, 'hasFailedValidation') && str_contains($feedback, 'hasBlockingEdits'), 'visible red validation failures remain navigation blockers');
assertInvalidEditUx(!str_contains($feedback, 'language-highlight-layer'), 'duplicate absolute text layer is removed from feedback JavaScript');
assertInvalidEditUx(!str_contains($feedback, 'appendHighlightedText'), 'word-overlay renderer is removed');
assertInvalidEditUx(!str_contains($feedback, 'invalidWords'), 'word-level validation payload is not used by the UI');
assertInvalidEditUx(!str_contains($feedback, "position: 'absolute', left: '0', top: '0'"), 'textarea feedback never paints a second text copy over the original');

assertInvalidEditUx(!str_contains($feedback, 'validateTextSave'), 'obsolete validation-before-save HTTP request is removed');
assertInvalidEditUx(!str_contains($feedback, 'translation-validate.php'), 'room/assignment persistent save does not call validation-only endpoint');
assertInvalidEditUx(str_contains($feedback, 'X-Room-Translation-Results'), 'successful real save exposes exact server conclusion to UI');
assertInvalidEditUx(str_contains($translator, 'X-Room-Translation-Results'), 'server emits translation result metadata on the same save response');
assertInvalidEditUx(
    str_contains($feedback, 'markSavedRequest(')
        && str_contains($feedback, 'translationResultsFromResponse(response)')
        && str_contains($feedback, 'editedRowAtRequest')
        && str_contains($feedback, 'requestRows'),
    'green conclusion appears only after real persistence success on the request-bound row'
);
assertInvalidEditUx(str_contains($validator, 'ContentTranslator') && str_contains($validator, '->versions('), 'validation-only flow remains for non-persistable text');

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

// HAR #7 regression: explicitly saved empty instructions must remain empty and
// must never be silently replaced with the default instruction on rerender.
assertInvalidEditUx(str_contains($app, "String(assignment.instructions ?? '')"), 'explicit empty assignment instruction is rendered as empty');
assertInvalidEditUx(!str_contains($app, "String(assignment.instructions || '').trim()\n                    || row.textarea.dataset.problem"), 'old empty-to-default fallback chain is removed');
assertInvalidEditUx(str_contains($rooms, "'languageDecisionMessage' => SiteTranslations::text("), 'decision dialog remains server-localized');

echo "Single-save non-overlay invalid edit UX contract passed.\n";
