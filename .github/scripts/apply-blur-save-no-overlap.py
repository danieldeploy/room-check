from pathlib import Path

# --- Save textarea text only when focus leaves the field ---
app_path = Path('assets/app.js')
app = app_path.read_text()

app = app.replace("    const TEXT_AUTOSAVE_DELAY_MS = 1200;\n", "")
app = app.replace("    const BLUR_AUTOSAVE_DELAY_MS = 120;\n", "    const BLUR_AUTOSAVE_DELAY_MS = 0;\n")
app = app.replace("    const SAVE_BOUNDARY_PATTERN = /[\\s.,;:!?…)}\\]]$/u;\n", "")

helper = """    const textIsReadyForAutosave = (textarea, inputEvent = null) => {\n        const value = textarea.value;\n        if (value.trim() === '') return true;\n        if (inputEvent?.inputType?.startsWith('insertFrom')) return true;\n        const cursor = Number.isInteger(textarea.selectionStart) ? textarea.selectionStart : value.length;\n        if (cursor <= 0) return false;\n        return SAVE_BOUNDARY_PATTERN.test(value.slice(cursor - 1, cursor));\n    };\n\n"""
if helper not in app:
    raise SystemExit('textIsReadyForAutosave helper not found')
app = app.replace(helper, "", 1)

app = app.replace(
    "    const scheduleInstructionSave = (itemName, textarea, delay = TEXT_AUTOSAVE_DELAY_MS) => {\n",
    "    const scheduleInstructionSave = (itemName, textarea, delay = BLUR_AUTOSAVE_DELAY_MS) => {\n",
    1,
)

old_input = """        textarea.addEventListener('input', (event) => {\n            autoGrow(textarea);\n            if (row.classList.contains('assignment-mode')) {\n                if (!assignmentCheckbox || assignmentCheckbox.disabled || !assignmentCheckbox.checked) return;\n                window.clearTimeout(instructionSaveTimers.get(item.name));\n                setStatus('Alterações por guardar');\n                if (textIsReadyForAutosave(textarea, event)) {\n                    scheduleInstructionSave(item.name, textarea);\n                }\n            } else if (canEdit) {\n                clearTimeout(saveTimer);\n                setStatus('Alterações por guardar');\n                if (textIsReadyForAutosave(textarea, event)) {\n                    scheduleSave();\n                }\n            }\n        });\n"""
new_input = """        textarea.addEventListener('input', () => {\n            autoGrow(textarea);\n            if (row.classList.contains('assignment-mode')) {\n                if (!assignmentCheckbox || assignmentCheckbox.disabled || !assignmentCheckbox.checked) return;\n                window.clearTimeout(instructionSaveTimers.get(item.name));\n                instructionSaveTimers.delete(item.name);\n                setStatus('Alterações por guardar');\n            } else if (canEdit) {\n                clearTimeout(saveTimer);\n                setStatus('Alterações por guardar');\n            }\n        });\n"""
if old_input not in app:
    raise SystemExit('textarea input autosave block not found')
app = app.replace(old_input, new_input, 1)
app_path.write_text(app)

# --- Keep red word aligned without visibly doubling the underlying textarea text ---
feedback_path = Path('assets/validation-feedback.js')
feedback = feedback_path.read_text()

anchor = "    const appendHighlightedText = (layer, value, invalidWords) => {\n"
helper2 = "    const highlightBackground = (layer) => layer.dataset.textareaBackground || '#fbfdfd';\n\n"
if helper2 not in feedback:
    if anchor not in feedback:
        raise SystemExit('appendHighlightedText anchor not found')
    feedback = feedback.replace(anchor, helper2 + anchor, 1)

wrong1 = """                const wrong = document.createElement('span');\n                wrong.className = 'language-wrong-segment';\n                wrong.textContent = part;\n                layer.append(wrong);\n"""
wrong1_new = """                const wrong = document.createElement('span');\n                wrong.className = 'language-wrong-segment';\n                wrong.textContent = part;\n                wrong.style.backgroundColor = highlightBackground(layer);\n                layer.append(wrong);\n"""
if wrong1 not in feedback:
    raise SystemExit('primary wrong-word span not found')
feedback = feedback.replace(wrong1, wrong1_new, 1)

wrong2 = """            const wrong = document.createElement('span');\n            wrong.className = 'language-wrong-segment';\n            wrong.textContent = value.slice(range.start, range.end) || value;\n"""
wrong2_new = """            const wrong = document.createElement('span');\n            wrong.className = 'language-wrong-segment';\n            wrong.textContent = value.slice(range.start, range.end) || value;\n            wrong.style.backgroundColor = highlightBackground(layer);\n"""
if wrong2 not in feedback:
    raise SystemExit('fallback wrong-word span not found')
feedback = feedback.replace(wrong2, wrong2_new, 1)

layer_anchor = """        layer.className = 'language-highlight-layer';\n        layer.setAttribute('aria-hidden', 'true');\n"""
layer_new = """        layer.className = 'language-highlight-layer';\n        layer.setAttribute('aria-hidden', 'true');\n        layer.dataset.textareaBackground = computed.backgroundColor || '#fbfdfd';\n"""
if layer_anchor not in feedback:
    raise SystemExit('layer setup anchor not found')
feedback = feedback.replace(layer_anchor, layer_new, 1)
feedback_path.write_text(feedback)

# Same font metrics as the textarea: do not make invalid word wider/bolder.
css_path = Path('assets/app.css')
css = css_path.read_text()
css = css.replace(
    '.language-highlight-layer .language-wrong-segment { color: var(--wrong, #b91c1c); font-weight: 700; }',
    '.language-highlight-layer .language-wrong-segment { color: var(--wrong, #b91c1c); font-weight: inherit; }',
    1,
)
css_path.write_text(css)

# --- Regression contracts ---
autosave_path = Path('tests/autosave-hardening.php')
autosave = autosave_path.read_text()
old_assertions = """assertAutosaveHardening(\n    str_contains($source, 'const TEXT_AUTOSAVE_DELAY_MS = 1200;'),\n    'text autosave waits for a real debounce interval'\n);\nassertAutosaveHardening(\n    str_contains($source, 'textIsReadyForAutosave(textarea, event)'),\n    'typing is gated by a word/paste boundary before autosave is scheduled'\n);\nassertAutosaveHardening(\n    str_contains($source, 'SAVE_BOUNDARY_PATTERN'),\n    'word-boundary autosave guard is present'\n);\nassertAutosaveHardening(\n    str_contains($source, \"textarea.addEventListener('blur'\"),\n    'unfinished final words are flushed on blur'\n);\n"""
new_assertions = """assertAutosaveHardening(\n    !str_contains($source, 'TEXT_AUTOSAVE_DELAY_MS')\n        && !str_contains($source, 'textIsReadyForAutosave')\n        && !str_contains($source, 'SAVE_BOUNDARY_PATTERN'),\n    'typing never schedules a text save before blur'\n);\nassertAutosaveHardening(\n    str_contains($source, \"textarea.addEventListener('blur'\")\n        && str_contains($source, 'BLUR_AUTOSAVE_DELAY_MS = 0')\n        && str_contains($source, 'scheduleInstructionSave(item.name, textarea, BLUR_AUTOSAVE_DELAY_MS)')\n        && str_contains($source, 'scheduleSave(BLUR_AUTOSAVE_DELAY_MS)'),\n    'textarea text saves when focus leaves the field'\n);\n"""
if old_assertions not in autosave:
    raise SystemExit('old autosave assertions not found')
autosave = autosave.replace(old_assertions, new_assertions, 1)
autosave_path.write_text(autosave)

ux_path = Path('tests/invalid-edit-ux.php')
ux = ux_path.read_text()
anchor3 = "assertInvalidEditUx(str_contains($feedback, 'delete textarea.dataset.languageNeedsValidation'), 'pending validation marker has explicit success/cancel clear paths');\n"
addition = """assertInvalidEditUx(\n    str_contains($feedback, 'highlightBackground')\n        && str_contains($feedback, 'wrong.style.backgroundColor = highlightBackground(layer)')\n        && str_contains($css, '.language-highlight-layer .language-wrong-segment { color: var(--wrong, #b91c1c); font-weight: inherit; }'),\n    'invalid-language word covers the underlying glyphs without changing text width'\n);\n"""
if addition not in ux:
    if anchor3 not in ux:
        raise SystemExit('invalid-edit UX insertion anchor not found')
    ux = ux.replace(anchor3, anchor3 + addition, 1)
ux_path.write_text(ux)
