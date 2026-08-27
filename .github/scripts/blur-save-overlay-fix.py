from pathlib import Path

# 1) Save only on textarea blur: remove input-triggered autosave scheduling.
app_path = Path('assets/app.js')
app = app_path.read_text()
app = app.replace("    const TEXT_AUTOSAVE_DELAY_MS = 1200;\n", "")
app = app.replace("    const BLUR_AUTOSAVE_DELAY_MS = 120;\n", "    const BLUR_AUTOSAVE_DELAY_MS = 0;\n")
app = app.replace("    const SAVE_BOUNDARY_PATTERN = /[\\s.,;:!?…)}\\]]$/u;\n", "")
old_helper = """    const textIsReadyForAutosave = (textarea, inputEvent = null) => {\n        const value = textarea.value;\n        if (value.trim() === '') return true;\n        if (inputEvent?.inputType?.startsWith('insertFrom')) return true;\n        const cursor = Number.isInteger(textarea.selectionStart) ? textarea.selectionStart : value.length;\n        if (cursor <= 0) return false;\n        return SAVE_BOUNDARY_PATTERN.test(value.slice(cursor - 1, cursor));\n    };\n\n"""
app = app.replace(old_helper, "")
app = app.replace("    const scheduleInstructionSave = (itemName, textarea, delay = TEXT_AUTOSAVE_DELAY_MS) => {\n", "    const scheduleInstructionSave = (itemName, textarea, delay = BLUR_AUTOSAVE_DELAY_MS) => {\n")
old_input = """        textarea.addEventListener('input', (event) => {\n            autoGrow(textarea);\n            if (row.classList.contains('assignment-mode')) {\n                if (!assignmentCheckbox || assignmentCheckbox.disabled || !assignmentCheckbox.checked) return;\n                window.clearTimeout(instructionSaveTimers.get(item.name));\n                setStatus('Alterações por guardar');\n                if (textIsReadyForAutosave(textarea, event)) {\n                    scheduleInstructionSave(item.name, textarea);\n                }\n            } else if (canEdit) {\n                clearTimeout(saveTimer);\n                setStatus('Alterações por guardar');\n                if (textIsReadyForAutosave(textarea, event)) {\n                    scheduleSave();\n                }\n            }\n        });\n"""
new_input = """        textarea.addEventListener('input', () => {\n            autoGrow(textarea);\n            if (row.classList.contains('assignment-mode')) {\n                if (!assignmentCheckbox || assignmentCheckbox.disabled || !assignmentCheckbox.checked) return;\n                window.clearTimeout(instructionSaveTimers.get(item.name));\n                instructionSaveTimers.delete(item.name);\n                setStatus('Alterações por guardar');\n            } else if (canEdit) {\n                clearTimeout(saveTimer);\n                setStatus('Alterações por guardar');\n            }\n        });\n"""
if old_input not in app:
    raise SystemExit('textarea input autosave block not found')
app = app.replace(old_input, new_input, 1)
app_path.write_text(app)

# 2) Overlay: keep textarea editable, but cover the underlying wrong word so text does not visually double.
feedback_path = Path('assets/validation-feedback.js')
feedback = feedback_path.read_text()
old_wrong = """                const wrong = document.createElement('span');\n                wrong.className = 'language-wrong-segment';\n                wrong.textContent = part;\n                layer.append(wrong);\n"""
new_wrong = """                const wrong = document.createElement('span');\n                wrong.className = 'language-wrong-segment';\n                wrong.textContent = part;\n                wrong.style.backgroundColor = computedTextareaBackground(layer);\n                layer.append(wrong);\n"""
# Add helper before appendHighlightedText.
anchor = "    const appendHighlightedText = (layer, value, invalidWords) => {\n"
helper = """    const computedTextareaBackground = (layer) => layer.dataset.textareaBackground || '#fbfdfd';\n\n"""
if helper not in feedback:
    feedback = feedback.replace(anchor, helper + anchor, 1)
feedback = feedback.replace(old_wrong, new_wrong, 1)
# Fallback branch wrong span also needs background.
old_wrong2 = """            const wrong = document.createElement('span');\n            wrong.className = 'language-wrong-segment';\n            wrong.textContent = value.slice(range.start, range.end) || value;\n"""
new_wrong2 = """            const wrong = document.createElement('span');\n            wrong.className = 'language-wrong-segment';\n            wrong.textContent = value.slice(range.start, range.end) || value;\n            wrong.style.backgroundColor = computedTextareaBackground(layer);\n"""
feedback = feedback.replace(old_wrong2, new_wrong2, 1)
# Store actual textarea background on the overlay.
anchor2 = "        layer.className = 'language-highlight-layer';\n        layer.setAttribute('aria-hidden', 'true');\n"
replacement2 = "        layer.className = 'language-highlight-layer';\n        layer.setAttribute('aria-hidden', 'true');\n        layer.dataset.textareaBackground = computed.backgroundColor || '#fbfdfd';\n"
if anchor2 not in feedback:
    raise SystemExit('highlight layer anchor not found')
feedback = feedback.replace(anchor2, replacement2, 1)
feedback_path.write_text(feedback)

# 3) Tests / contracts.
autosave_test = Path('tests/autosave-hardening.php')
text = autosave_test.read_text()
text += """\nassertAutosaveHardening(\n    !str_contains($source, 'textIsReadyForAutosave')\n        && !str_contains($source, 'SAVE_BOUNDARY_PATTERN')\n        && str_contains($source, \"textarea.addEventListener('blur'\")\n        && str_contains($source, 'BLUR_AUTOSAVE_DELAY_MS = 0')\n        && !str_contains($source, 'scheduleInstructionSave(item.name, textarea);'),\n    'textarea text saves only when focus leaves the field'\n);\n"""
autosave_test.write_text(text)

ux_test = Path('tests/invalid-edit-ux.php')
text = ux_test.read_text()
text += """\nassertInvalidEditUx(\n    str_contains($feedback, 'computedTextareaBackground')\n        && str_contains($feedback, 'wrong.style.backgroundColor = computedTextareaBackground(layer)')\n        && str_contains($feedback, \"pointerEvents: 'none'\")\n        && !str_contains($feedback, \"textarea.style.color = 'transparent'\"),\n    'wrong-language word overlay covers underlying text without blocking textarea editing'\n);\n"""
ux_test.write_text(text)
