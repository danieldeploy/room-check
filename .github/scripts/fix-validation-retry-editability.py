from pathlib import Path

feedback_path = Path('assets/validation-feedback.js')
feedback = feedback_path.read_text()
anchor = "    const allRows = () => Array.from(document.querySelectorAll('.check-row'));\n"
helper = """

    const keepValidationTextareaEditable = (textarea) => {
        if (!textarea || config.canEdit === false) return;
        const row = textarea.closest('.check-row');
        if (!row) return;
        if (!row.classList.contains('assignment-mode')) {
            textarea.readOnly = false;
            return;
        }
        const checkbox = row.querySelector('.assignment-check input[type=\"checkbox\"]');
        if (checkbox && checkbox.checked && !checkbox.disabled) textarea.readOnly = false;
    };
"""
if helper.strip() not in feedback:
    if anchor not in feedback:
        raise SystemExit('allRows anchor not found')
    feedback = feedback.replace(anchor, anchor + helper, 1)

old_render = """    const renderHighlight = (textarea, invalidWords = []) => {
        if (!textarea) return;
        removeHighlight(textarea);
"""
new_render = """    const renderHighlight = (textarea, invalidWords = []) => {
        if (!textarea) return;
        keepValidationTextareaEditable(textarea);
        removeHighlight(textarea);
"""
if old_render in feedback:
    feedback = feedback.replace(old_render, new_render, 1)
elif new_render not in feedback:
    raise SystemExit('renderHighlight anchor not found')

old_focus = """    document.addEventListener('focusin', (event) => {
        const textarea = event.target.closest?.('.check-row textarea');
        if (textarea && textarea.dataset.lastValidValue === undefined) textarea.dataset.lastValidValue = textarea.value;
        if (contextControl(event.target)) previousControlValues.set(event.target, event.target.value);
    }, true);
"""
new_focus = """    document.addEventListener('focusin', (event) => {
        const textarea = event.target.closest?.('.check-row textarea');
        if (textarea) {
            if (textarea.dataset.lastValidValue === undefined) textarea.dataset.lastValidValue = textarea.value;
            keepValidationTextareaEditable(textarea);
        }
        if (contextControl(event.target)) previousControlValues.set(event.target, event.target.value);
    }, true);
"""
if old_focus in feedback:
    feedback = feedback.replace(old_focus, new_focus, 1)
elif new_focus not in feedback:
    raise SystemExit('focusin anchor not found')

feedback_path.write_text(feedback)

app_path = Path('assets/app.js')
app = app_path.read_text()
old_catch = """        }).catch((error) => {
            // Keep rejected instruction text visible so the user can correct it.
            // Checkbox/assignment failures still resync from persisted state.
            if (contextMatches() && feedbackKind === 'assignment') updateAssignmentMode();
            setStatus(error.message, 'error');
            return false;
        });
"""
new_catch = """        }).catch((error) => {
            // Keep rejected instruction text visible and editable so the user can
            // retry as many times as needed. Checkbox/assignment failures still
            // resync from persisted state.
            if (contextMatches() && feedbackKind === 'assignment') updateAssignmentMode();
            if (contextMatches() && feedbackKind === 'instructions') {
                changes.forEach((change) => {
                    const row = rows.find((candidate) => candidate.name === change.itemName);
                    if (row?.assignmentCheckbox?.checked && !row.assignmentCheckbox.disabled) {
                        row.textarea.readOnly = false;
                    }
                });
            }
            setStatus(error.message, 'error');
            return false;
        });
"""
if old_catch in app:
    app = app.replace(old_catch, new_catch, 1)
elif new_catch not in app:
    raise SystemExit('assignment catch anchor not found')
app_path.write_text(app)

test_path = Path('tests/invalid-edit-ux.php')
test = test_path.read_text()
anchor_test = """assertInvalidEditUx(
    !str_contains($feedback, \"textarea.style.color = 'transparent'\")
        && !str_contains($feedback, \"textarea.style.backgroundColor = 'transparent'\")
        && str_contains($feedback, \"color: 'transparent'\")
        && str_contains($feedback, \"pointerEvents: 'none', zIndex: '3'\"),
    'invalid-language highlight never makes the textarea itself transparent or non-interactive'
);
"""
addition = """
assertInvalidEditUx(
    str_contains($feedback, 'keepValidationTextareaEditable')
        && str_contains($feedback, 'checkbox && checkbox.checked && !checkbox.disabled')
        && str_contains($feedback, 'textarea.readOnly = false'),
    'language validation errors keep an active assigned textarea editable on every retry'
);
assertInvalidEditUx(
    str_contains($app, \"feedbackKind === 'instructions'\")
        && str_contains($app, 'row?.assignmentCheckbox?.checked && !row.assignmentCheckbox.disabled')
        && str_contains($app, 'row.textarea.readOnly = false'),
    'rejected assignment instructions explicitly restore editability without unlocking unassigned items'
);
"""
if addition.strip() not in test:
    if anchor_test not in test:
        raise SystemExit('invalid-edit UX insertion anchor not found')
    test = test.replace(anchor_test, anchor_test + addition, 1)
test_path.write_text(test)
