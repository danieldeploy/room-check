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
$readonlyCss = file_get_contents(dirname(__DIR__) . '/assets/readonly-textarea.css');
$sessionBar = file_get_contents(dirname(__DIR__) . '/src/UI/SessionBar.php');
assertAssignmentTextareaLock(is_string($app), 'assets/app.js is readable');
assertAssignmentTextareaLock(is_string($readonlyCss), 'read-only textarea stylesheet is readable');
assertAssignmentTextareaLock(is_string($sessionBar), 'SessionBar source is readable');

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

assertAssignmentTextareaLock(
    str_contains($readonlyCss, '.check-row textarea[readonly]:focus')
        && str_contains($readonlyCss, 'outline: none;')
        && str_contains($readonlyCss, 'border-color: #cbd9d7;')
        && str_contains($readonlyCss, 'box-shadow: none;'),
    'read-only room textareas do not display the editable focus ring'
);
assertAssignmentTextareaLock(
    str_contains($sessionBar, "assets/readonly-textarea.css"),
    'authenticated room UI loads the read-only textarea focus override'
);

echo "Assignment textarea lock contract passed.\n";
