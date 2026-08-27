<?php
declare(strict_types=1);

function assertAssignmentTextareaLock(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    echo 'PASS: ' . $message . PHP_EOL;
}

$app = file_get_contents(dirname(__DIR__) . '/assets/app.js');
assertAssignmentTextareaLock(is_string($app), 'assets/app.js is readable');

assertAssignmentTextareaLock(
    str_contains($app, "row.textarea.readOnly = viewingAssignments ? (!active || locked || !sameAssignment) : !canEdit;"),
    'assignment textarea is read-only until the item belongs to the selected employee/date'
);

$checkboxHandler = strpos($app, "assignmentCheckbox.addEventListener('change', () => {");
$pendingLock = $checkboxHandler === false ? false : strpos($app, 'textarea.readOnly = true;', $checkboxHandler);
$queueSave = $checkboxHandler === false ? false : strpos($app, 'queueAssignmentSave([{', $checkboxHandler);
assertAssignmentTextareaLock(
    $checkboxHandler !== false && $pendingLock !== false && $queueSave !== false && $pendingLock < $queueSave,
    'checkbox changes keep the textarea locked while assignment persistence is pending'
);

$selectAll = strpos($app, "if (selectAllItems) selectAllItems.addEventListener('change', () => {");
$selectAllLock = $selectAll === false ? false : strpos($app, 'row.textarea.readOnly = true;', $selectAll);
$selectAllQueue = $selectAll === false ? false : strpos($app, 'queueAssignmentSave(changes, affected);', $selectAll);
assertAssignmentTextareaLock(
    $selectAll !== false && $selectAllLock !== false && $selectAllQueue !== false && $selectAllLock < $selectAllQueue,
    'select-all also locks affected textareas until the batch assignment is saved'
);

assertAssignmentTextareaLock(
    str_contains($app, "if (change.selected) {\n                        assignments[change.itemName] = {")
        && str_contains($app, 'updateAssignmentMode();'),
    'successful assignment save updates assignment state before the textarea can become editable'
);

echo "Assignment textarea lock contract passed.\n";
