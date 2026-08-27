from pathlib import Path


def replace(path, old, new, count=1):
    p = Path(path)
    s = p.read_text()
    if old not in s:
        raise SystemExit(f'pattern not found in {path}: {old[:120]!r}')
    p.write_text(s.replace(old, new, count))

# LanguageGuard: structured validation exception + offending words.
p = Path('src/I18n/LanguageGuard.php')
s = p.read_text()
marker = 'final class LanguageGuard\n{'
exc = '''final class LanguageValidationException extends InvalidArgumentException
{
    public function __construct(
        string $message,
        public readonly array $invalidWords = [],
        public ?string $fieldKey = null
    ) {
        parent::__construct($message);
    }

    public function withField(string $fieldKey): self
    {
        $this->fieldKey = $fieldKey;
        return $this;
    }
}

final class LanguageGuard
{'''
if marker not in s:
    raise SystemExit('LanguageGuard class marker missing')
s = s.replace(marker, exc, 1)
old = '''        $whole = self::detect($naturalText);
        if ($whole->language === $oppositeLanguage && $whole->isReliable()) {
            self::throwMismatch($expectedLanguage);
        }

        // 2. Validate the components. This catches mixed input where the complete
        // sentence is still dominated by the expected language, e.g.
        // "Check that it is clean. escada" or "Verificar se está limpo. stairs".
        foreach (self::components($text) as $component) {
            if (self::isConfidentOppositeComponent($component, $expectedLanguage)) {
                self::throwMismatch($expectedLanguage);
            }
        }'''
new = '''        $whole = self::detect($naturalText);
        $invalidWords = self::oppositeWords($text, $expectedLanguage);
        if ($whole->language === $oppositeLanguage && $whole->isReliable()) {
            if ($invalidWords === []) {
                $invalidWords = array_slice(self::naturalTokens($text), 0, 20);
            }
            self::throwMismatch($expectedLanguage, $invalidWords);
        }

        // 2. Validate individual words and short components. Return the actual
        // offending words so the client can highlight them without discarding
        // the user's unsaved edit.
        if ($invalidWords !== []) {
            self::throwMismatch($expectedLanguage, $invalidWords);
        }'''
if old not in s:
    raise SystemExit('LanguageGuard assert block missing')
s = s.replace(old, new, 1)
anchor = '''    /**
     * Build language evidence from individual words plus contiguous 2- and
'''
helpers = '''    /** @return string[] */
    private static function naturalTokens(string $text): array
    {
        $lower = mb_strtolower($text, 'UTF-8');
        $tokens = preg_split('/[^\\p{L}\\p{N}_-]+/u', $lower, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        return array_values(array_filter(
            array_slice($tokens, 0, self::MAX_COMPONENT_TOKENS),
            [self::class, 'isNaturalLanguageToken']
        ));
    }

    /** @return string[] */
    private static function oppositeWords(string $text, string $expectedLanguage): array
    {
        $tokens = self::naturalTokens($text);
        if ($tokens === []) {
            return [];
        }
        $invalid = [];
        foreach ($tokens as $token) {
            if (self::isConfidentOppositeComponent($token, $expectedLanguage)) {
                $invalid[$token] = true;
            }
        }
        if ($invalid !== []) {
            return array_keys($invalid);
        }
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            for ($window = 2; $window <= 3 && ($i + $window) <= $count; $window++) {
                $slice = array_slice($tokens, $i, $window);
                if (self::isConfidentOppositeComponent(implode(' ', $slice), $expectedLanguage)) {
                    foreach ($slice as $token) {
                        $invalid[$token] = true;
                    }
                }
            }
        }
        return array_keys($invalid);
    }

'''
if anchor not in s:
    raise SystemExit('LanguageGuard helper insertion point missing')
s = s.replace(anchor, helpers + anchor, 1)
old = '''    private static function throwMismatch(string $expectedLanguage): never
    {
        if ($expectedLanguage === 'en') {
            throw new InvalidArgumentException(
                'This text mixes Portuguese and English. Please write it in English only.'
            );
        }

        throw new InvalidArgumentException(
            'Este texto mistura português e inglês. Escreva-o apenas em português.'
        );
    }'''
new = '''    private static function throwMismatch(string $expectedLanguage, array $invalidWords = []): never
    {
        $invalidWords = array_values(array_unique(array_filter(array_map('strval', $invalidWords))));
        if ($expectedLanguage === 'en') {
            throw new LanguageValidationException(
                'This text mixes Portuguese and English. Please write it in English only.',
                $invalidWords
            );
        }
        throw new LanguageValidationException(
            'Este texto mistura português e inglês. Escreva-o apenas em português.',
            $invalidWords
        );
    }'''
if old not in s:
    raise SystemExit('LanguageGuard throw block missing')
s = s.replace(old, new, 1)
p.write_text(s)

# API: validation-only endpoint, field context, structured 422.
p = Path('api.php')
s = p.read_text()
anchor = "    $contentTranslator = new ContentTranslator($pdo, $config['translation'] ?? []);\n\n"
endpoint = '''    if (($payload['action'] ?? '') === 'validate_bilingual_texts') {
        Auth::requireLogin($pdo, $config);
        Csrf::validate(isset($payload['csrfToken']) ? (string) $payload['csrfToken'] : null);
        $fields = $payload['fields'] ?? null;
        if (!is_array($fields) || count($fields) > 100) {
            throw new InvalidArgumentException('Invalid language validation request.');
        }
        $invalidFields = [];
        foreach ($fields as $field) {
            if (!is_array($field)) continue;
            $fieldKey = trim((string) ($field['fieldKey'] ?? ''));
            $text = trim((string) ($field['text'] ?? ''));
            if (mb_strlen($text) > 5000) {
                throw new InvalidArgumentException('Text is too long.');
            }
            try {
                LanguageGuard::assertExpectedLanguage($text, Translator::locale());
            } catch (LanguageValidationException $exception) {
                $invalidFields[] = [
                    'fieldKey' => $fieldKey,
                    'invalidWords' => $exception->invalidWords,
                    'error' => $exception->getMessage(),
                ];
            }
        }
        if ($invalidFields !== []) {
            jsonResponse([
                'ok' => false,
                'validation' => true,
                'error' => (string) $invalidFields[0]['error'],
                'invalidFields' => $invalidFields,
            ], 422);
        }
        jsonResponse(['ok' => true, 'valid' => true]);
    }

'''
if anchor not in s:
    raise SystemExit('API translator anchor missing')
s = s.replace(anchor, anchor + endpoint, 1)
old = '''            $instructionVersions = $contentTranslator->versions(
                $instructions,
                Translator::locale(),
                (string) ($existingInstruction['verification_instructions'] ?? ''),
                (string) ($existingInstruction['verification_instructions_en'] ?? '')
            );'''
new = '''            try {
                $instructionVersions = $contentTranslator->versions(
                    $instructions,
                    Translator::locale(),
                    (string) ($existingInstruction['verification_instructions'] ?? ''),
                    (string) ($existingInstruction['verification_instructions_en'] ?? '')
                );
            } catch (LanguageValidationException $exception) {
                throw $exception->withField($name);
            }'''
if old not in s:
    raise SystemExit('atomic versions block missing')
s = s.replace(old, new, 1)
old = '''        $problemVersions = $contentTranslator->versions(
            $problem,
            Translator::locale(),
            $existingPt,
            $existingEn
        );'''
new = '''        try {
            $problemVersions = $contentTranslator->versions(
                $problem,
                Translator::locale(),
                $existingPt,
                $existingEn
            );
        } catch (LanguageValidationException $exception) {
            throw $exception->withField($name);
        }'''
if old not in s:
    raise SystemExit('checklist versions block missing')
s = s.replace(old, new, 1)
old = '''} catch (JsonException | InvalidArgumentException $exception) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    jsonResponse(['ok' => false, 'error' => $exception->getMessage()], 422);
} catch (Throwable $exception) {'''
new = '''} catch (LanguageValidationException $exception) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    jsonResponse([
        'ok' => false,
        'validation' => true,
        'error' => $exception->getMessage(),
        'invalidWords' => $exception->invalidWords,
        'fieldKey' => $exception->fieldKey,
    ], 422);
} catch (JsonException | InvalidArgumentException $exception) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    jsonResponse(['ok' => false, 'error' => $exception->getMessage()], 422);
} catch (Throwable $exception) {'''
if old not in s:
    raise SystemExit('API catch block missing')
s = s.replace(old, new, 1)
p.write_text(s)

replace('rooms.php', "            'canAssign' => $canAssign,\n", "            'canAssign' => $canAssign,\n            'locale' => Translator::locale(),\n")

# App JS state.
p = Path('assets/app.js')
s = p.read_text()
s = s.replace("    const instructionLastAttemptedValues = new Map();\n    const savedFeedbackTimers = new WeakMap();", "    const instructionLastAttemptedValues = new Map();\n    const instructionInvalidValues = new Map();\n    const savedFeedbackTimers = new WeakMap();", 1)
s = s.replace("    let roomAssignmentCounts = {};\n", "    let roomAssignmentCounts = {};\n    let navigationBypass = false;\n    let navigationGuardBusy = false;\n    const contextControlValues = new WeakMap();\n", 1)
s = s.replace("        instructionSaveTimers.clear();\n        instructionLastAttemptedValues.clear();", "        instructionSaveTimers.clear();\n        instructionLastAttemptedValues.clear();\n        instructionInvalidValues.clear();", 1)
old = '''            const instructions = textarea.value.trim();
            if (instructionLastAttemptedValues.get(itemName) === instructions) return;
            instructionLastAttemptedValues.set(itemName, instructions);
            queueAssignmentSave([{ itemName, selected: true, instructions }]).then((saved) => {
                if (!saved && instructionLastAttemptedValues.get(itemName) === instructions) {
                    instructionLastAttemptedValues.delete(itemName);
                }
            });'''
new = '''            const instructions = textarea.value.trim();
            if (instructionInvalidValues.get(itemName) === instructions) return;
            if (instructionLastAttemptedValues.get(itemName) === instructions) return;
            instructionLastAttemptedValues.set(itemName, instructions);
            queueAssignmentSave([{ itemName, selected: true, instructions }]).then((saved) => {
                if (!saved
                    && instructionInvalidValues.get(itemName) !== instructions
                    && instructionLastAttemptedValues.get(itemName) === instructions) {
                    instructionLastAttemptedValues.delete(itemName);
                }
            });'''
if old not in s:
    raise SystemExit('instruction save block missing')
s = s.replace(old, new, 1)

helper_anchor = "    const renderRooms = (preferredRoom = 1) => {"
helpers = r'''    const normalizeValidationWords = (words) => Array.from(new Set(
        (Array.isArray(words) ? words : []).map((word) => String(word).trim().toLowerCase()).filter(Boolean)
    ));

    const renderLanguageValidation = (row, words) => {
        if (!row?.validationOverlay) return;
        const normalized = normalizeValidationWords(words);
        row.invalidWords = normalized;
        row.element.classList.toggle('has-language-error', normalized.length > 0);
        row.validationOverlay.replaceChildren();
        if (normalized.length === 0) {
            row.validationOverlay.hidden = true;
            return;
        }
        const invalid = new Set(normalized);
        const parts = row.textarea.value.split(/(\p{L}[\p{L}\p{N}_-]*)/u);
        const fragment = document.createDocumentFragment();
        parts.forEach((part) => {
            if (invalid.has(part.toLowerCase())) {
                const span = document.createElement('span');
                span.className = 'invalid-language-word';
                span.textContent = part;
                fragment.append(span);
            } else {
                fragment.append(document.createTextNode(part));
            }
        });
        row.validationOverlay.append(fragment);
        row.validationOverlay.hidden = false;
        row.validationOverlay.style.height = `${row.textarea.offsetHeight}px`;
    };

    const clearLanguageValidation = (row) => {
        if (!row) return;
        row.invalidWords = [];
        row.element.classList.remove('has-language-error');
        if (row.validationOverlay) {
            row.validationOverlay.replaceChildren();
            row.validationOverlay.hidden = true;
        }
    };

    const refreshLanguageValidation = (row) => {
        if (!row?.invalidWords?.length) return;
        const tokens = new Set((row.textarea.value.toLowerCase().match(/\p{L}[\p{L}\p{N}_-]*/gu) || []));
        const stillInvalid = row.invalidWords.filter((word) => tokens.has(word));
        if (stillInvalid.length === 0) clearLanguageValidation(row);
        else renderLanguageValidation(row, stillInvalid);
    };

    const dirtyTextRows = () => rows.filter((row) =>
        row.textarea.value !== (row.textarea.dataset.persistedText ?? row.textarea.value)
    );

    const revertDirtyTextRows = (dirtyRows) => {
        dirtyRows.forEach((row) => {
            row.textarea.value = row.textarea.dataset.persistedText ?? '';
            instructionInvalidValues.delete(row.name);
            instructionLastAttemptedValues.delete(row.name);
            clearLanguageValidation(row);
            autoGrow(row.textarea);
        });
    };

    const focusInvalidRow = (invalidRows) => {
        const row = invalidRows[0];
        if (!row) return;
        row.textarea.focus();
        const first = row.invalidWords?.[0];
        if (!first) return;
        const index = row.textarea.value.toLowerCase().indexOf(first.toLowerCase());
        if (index >= 0) row.textarea.setSelectionRange(index, index + first.length);
    };

    const showInvalidLanguageNavigationDialog = (invalidRows) => new Promise((resolve) => {
        const locale = config.locale === 'en' ? 'en' : 'pt';
        const backdrop = document.createElement('div');
        backdrop.className = 'language-edit-dialog-backdrop';
        const dialog = document.createElement('div');
        dialog.className = 'language-edit-dialog';
        dialog.setAttribute('role', 'alertdialog');
        dialog.setAttribute('aria-modal', 'true');
        const message = document.createElement('p');
        message.textContent = locale === 'en'
            ? 'There is Portuguese text in an English field. Do you want to correct it or cancel the edit?'
            : 'Tem texto em inglês num campo em português. Quer corrigir ou anular a edição?';
        const actions = document.createElement('div');
        actions.className = 'language-edit-dialog-actions';
        const correct = document.createElement('button');
        correct.type = 'button';
        correct.textContent = locale === 'en' ? 'Correct' : 'Corrigir';
        const cancelEdit = document.createElement('button');
        cancelEdit.type = 'button';
        cancelEdit.className = 'secondary';
        cancelEdit.textContent = locale === 'en' ? 'Cancel edit' : 'Anular edição';
        actions.append(correct, cancelEdit);
        dialog.append(message, actions);
        backdrop.append(dialog);
        document.body.append(backdrop);
        const finish = (choice) => {
            backdrop.remove();
            resolve(choice);
        };
        correct.addEventListener('click', () => finish('correct'));
        cancelEdit.addEventListener('click', () => finish('revert'));
        window.setTimeout(() => correct.focus(), 0);
    });

    const validateDirtyTextRows = async (dirtyRows) => {
        const response = await fetch('api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
            body: JSON.stringify({
                action: 'validate_bilingual_texts',
                csrfToken: config.csrfToken,
                fields: dirtyRows.map((row) => ({ fieldKey: row.name, text: row.textarea.value })),
            }),
        });
        const result = await response.json();
        if (response.ok && result.ok) return { valid: true, invalidRows: [] };
        if (result.validation === true) {
            const invalidRows = [];
            (result.invalidFields || []).forEach((field) => {
                const row = rows.find((candidate) => candidate.name === field.fieldKey);
                if (!row) return;
                renderLanguageValidation(row, field.invalidWords || []);
                invalidRows.push(row);
            });
            return { valid: false, invalidRows };
        }
        throw new Error(result.error || 'Language validation failed.');
    };

    const flushDirtyTextRows = async (dirtyRows) => {
        if (checklist.classList.contains('assignment-view-mode')) {
            const changes = dirtyRows
                .filter((row) => row.assignmentCheckbox?.checked && !row.assignmentCheckbox.disabled)
                .map((row) => ({ itemName: row.name, selected: true, instructions: row.textarea.value.trim() }));
            if (changes.length === 0) return true;
            return (await queueAssignmentSave(changes)) === true;
        }
        return (await saveChecklist(checklistSnapshot())) === true;
    };

    const resolveDirtyTextBeforeContextChange = async () => {
        const dirtyRows = dirtyTextRows();
        if (dirtyRows.length === 0) return true;
        let invalidRows = dirtyRows.filter((row) => row.invalidWords?.length);
        if (invalidRows.length === 0) {
            try {
                const validation = await validateDirtyTextRows(dirtyRows);
                if (!validation.valid) invalidRows = validation.invalidRows;
            } catch (error) {
                setStatus(error.message, 'error');
                return false;
            }
        }
        if (invalidRows.length > 0) {
            const choice = await showInvalidLanguageNavigationDialog(invalidRows);
            if (choice === 'correct') {
                focusInvalidRow(invalidRows);
                return false;
            }
            revertDirtyTextRows(dirtyRows);
            return true;
        }
        return flushDirtyTextRows(dirtyRows);
    };

    const guardedContextControls = () => [
        propertySelect, listSelect, roomSelect, intervalSelect, employeeSelect, assignmentDate,
    ].filter(Boolean);

    const rememberContextControlValues = () => {
        guardedContextControls().forEach((control) => contextControlValues.set(control, control.value));
    };

'''
if helper_anchor not in s:
    raise SystemExit('app helper anchor missing')
s = s.replace(helper_anchor, helpers + helper_anchor, 1)
s = s.replace("        textarea.dataset.problem = item.problem || '';\n        textarea.dataset.defaultInstructions = item.defaultInstructions || '';", "        textarea.dataset.problem = item.problem || '';\n        textarea.dataset.persistedText = item.problem || '';\n        textarea.dataset.defaultInstructions = item.defaultInstructions || '';", 1)
s = s.replace("        textarea.addEventListener('input', (event) => {\n            autoGrow(textarea);", "        textarea.addEventListener('input', (event) => {\n            autoGrow(textarea);\n            const rowState = rows.find((candidate) => candidate.element === row);\n            if (rowState) {\n                if (instructionInvalidValues.has(item.name)\n                    && instructionInvalidValues.get(item.name) !== textarea.value.trim()) {\n                    instructionInvalidValues.delete(item.name);\n                }\n                refreshLanguageValidation(rowState);\n            }", 1)
old = '''        const instructionSaved = document.createElement('span');
        instructionSaved.className = 'row-save-feedback';
        instructionSaved.textContent = 'Guardado';
        instructionSaved.setAttribute('aria-live', 'polite');
        problemField.append(textarea, instructionSaved);'''
new = '''        const validationOverlay = document.createElement('div');
        validationOverlay.className = 'text-validation-overlay';
        validationOverlay.hidden = true;
        validationOverlay.setAttribute('aria-hidden', 'true');
        const instructionSaved = document.createElement('span');
        instructionSaved.className = 'row-save-feedback';
        instructionSaved.textContent = 'Guardado';
        instructionSaved.setAttribute('aria-live', 'polite');
        problemField.append(textarea, validationOverlay, instructionSaved);'''
if old not in s:
    raise SystemExit('problem field block missing')
s = s.replace(old, new, 1)
old = "        return { element: row, name: item.name, textarea, assignmentHint, assignmentSaved, instructionSaved, status, assignmentCheckbox, assignmentLabel, itemHeading };"
new = "        return { element: row, name: item.name, textarea, validationOverlay, invalidWords: [], assignmentHint, assignmentSaved, instructionSaved, status, assignmentCheckbox, assignmentLabel, itemHeading };"
if old not in s:
    raise SystemExit('makeRow return missing')
s = s.replace(old, new, 1)
s = s.replace("            autoGrow(row.textarea);\n        });", "            autoGrow(row.textarea);\n            if (!row.invalidWords?.length) row.textarea.dataset.persistedText = row.textarea.value;\n            if (row.validationOverlay && !row.validationOverlay.hidden) row.validationOverlay.style.height = `${row.textarea.offsetHeight}px`;\n        });", 1)
s = s.replace("            lastSavedChecklistFingerprint = checklistSnapshot().fingerprint;\n            setStatus(canEdit ? 'Dados carregados' : 'Apenas consulta', 'success');", "            lastSavedChecklistFingerprint = checklistSnapshot().fingerprint;\n            rememberContextControlValues();\n            setStatus(canEdit ? 'Dados carregados' : 'Apenas consulta', 'success');", 1)
old = "            if (!response.ok || !result.ok) throw new Error(result.error || 'Erro ao guardar automaticamente.');"
new = """            if (!response.ok || !result.ok) {
                const error = new Error(result.error || 'Erro ao guardar automaticamente.');
                error.validation = result.validation === true;
                error.invalidWords = result.invalidWords || [];
                error.fieldKey = result.fieldKey || null;
                throw error;
            }"""
if old not in s:
    raise SystemExit('assignment response error missing')
s = s.replace(old, new, 1)
old = '''                roomAssignmentCounts[String(current.room)] = Number(result.roomAssignedItems || 0);
                applyRoomAssignmentStates();
                updateAssignmentMode();
                changes.forEach((change) => {'''
new = '''                roomAssignmentCounts[String(current.room)] = Number(result.roomAssignedItems || 0);
                changes.forEach((change) => {
                    instructionInvalidValues.delete(change.itemName);
                    instructionLastAttemptedValues.delete(change.itemName);
                    clearLanguageValidation(rows.find((candidate) => candidate.name === change.itemName));
                });
                applyRoomAssignmentStates();
                updateAssignmentMode();
                changes.forEach((change) => {'''
if old not in s:
    raise SystemExit('assignment success update missing')
s = s.replace(old, new, 1)
old = '''        }).catch((error) => {
            if (contextMatches()) updateAssignmentMode();
            setStatus(error.message, 'error');
            return false;
        });'''
new = '''        }).catch((error) => {
            if (contextMatches()) {
                if (error.validation === true) {
                    const fieldKey = error.fieldKey || changes[0]?.itemName || '';
                    const row = rows.find((candidate) => candidate.name === fieldKey);
                    if (row) {
                        renderLanguageValidation(row, error.invalidWords || []);
                        instructionInvalidValues.set(fieldKey, row.textarea.value.trim());
                    }
                } else {
                    updateAssignmentMode();
                }
            }
            setStatus(error.message, 'error');
            return false;
        });'''
if old not in s:
    raise SystemExit('assignment catch missing')
s = s.replace(old, new, 1)
old = '''            if (!response.ok || !result.ok) {
                throw new Error(result.error || 'Erro ao guardar.');
            }'''
new = '''            if (!response.ok || !result.ok) {
                const error = new Error(result.error || 'Erro ao guardar.');
                error.validation = result.validation === true;
                error.invalidWords = result.invalidWords || [];
                error.fieldKey = result.fieldKey || null;
                throw error;
            }'''
if old not in s:
    raise SystemExit('checklist response block missing')
s = s.replace(old, new, 1)
old = '''                rows.forEach((row) => {
                    if (persistedByName.has(row.name)) row.textarea.dataset.problem = persistedByName.get(row.name);
                });'''
new = '''                rows.forEach((row) => {
                    if (persistedByName.has(row.name)) {
                        const persisted = persistedByName.get(row.name);
                        row.textarea.dataset.problem = persisted;
                        row.textarea.dataset.persistedText = persisted;
                        clearLanguageValidation(row);
                    }
                });'''
if old not in s:
    raise SystemExit('checklist persisted block missing')
s = s.replace(old, new, 1)
old = '''        } catch (error) {
            if (version === requestVersion) {
                setStatus(error.message, 'error');
            }
            return false;
        }
    };

    const scheduleSave ='''
new = '''        } catch (error) {
            if (version === requestVersion) {
                if (error.validation === true) {
                    const row = rows.find((candidate) => candidate.name === error.fieldKey);
                    if (row) renderLanguageValidation(row, error.invalidWords || []);
                }
                setStatus(error.message, 'error');
            }
            return false;
        }
    };

    const scheduleSave ='''
if old not in s:
    raise SystemExit('checklist catch anchor missing')
s = s.replace(old, new, 1)
anchor = "    propertySelect.addEventListener('change', () => {"
guard = r'''    document.addEventListener('change', (event) => {
        const control = event.target;
        if (!guardedContextControls().includes(control) || navigationBypass) {
            if (guardedContextControls().includes(control)) contextControlValues.set(control, control.value);
            return;
        }
        const previousValue = contextControlValues.get(control);
        const nextValue = control.value;
        if (previousValue === undefined || previousValue === nextValue || dirtyTextRows().length === 0) {
            contextControlValues.set(control, nextValue);
            return;
        }
        event.preventDefault();
        event.stopImmediatePropagation();
        control.value = previousValue;
        if (navigationGuardBusy) return;
        navigationGuardBusy = true;
        resolveDirtyTextBeforeContextChange().then((proceed) => {
            if (!proceed) return;
            navigationBypass = true;
            control.value = nextValue;
            control.dispatchEvent(new Event('change', { bubbles: true }));
            navigationBypass = false;
            contextControlValues.set(control, nextValue);
        }).finally(() => { navigationGuardBusy = false; });
    }, true);

'''
if anchor not in s:
    raise SystemExit('context listener anchor missing')
s = s.replace(anchor, guard + anchor, 1)
s = s.replace("    renderRooms(Number(config.initialRoom) || 1);\n    if (assignmentDate && !assignmentDate.value) assignmentDate.value = config.today;", "    renderRooms(Number(config.initialRoom) || 1);\n    if (assignmentDate && !assignmentDate.value) assignmentDate.value = config.today;\n    rememberContextControlValues();", 1)
p.write_text(s)

# CSS overlay and dialog.
p = Path('assets/app.css')
s = p.read_text() + r'''

/* Language validation: keep unsaved text visible; overlay only offending words. */
.problem-field { position: relative; }
.text-validation-overlay { position: absolute; z-index: 3; top: 0; left: 0; width: 100%; min-height: 46px; padding: 11px 13px; overflow: hidden; border: 1px solid transparent; border-radius: 11px; color: transparent; background: transparent; font: inherit; line-height: 1.45; white-space: pre-wrap; overflow-wrap: break-word; pointer-events: none; }
.text-validation-overlay[hidden] { display: none; }
.invalid-language-word { color: var(--wrong); }
.check-row.has-language-error textarea { border-color: #dc6b62; background: #fffafa; }
.language-edit-dialog-backdrop { position: fixed; z-index: 1000; inset: 0; display: grid; place-items: center; padding: 20px; background: rgba(16, 42, 67, .38); }
.language-edit-dialog { width: min(520px, 100%); padding: 22px; border-radius: 16px; background: #fff; box-shadow: 0 24px 60px rgba(16, 42, 67, .25); }
.language-edit-dialog p { margin: 0 0 18px; color: var(--ink); font-weight: 700; line-height: 1.45; }
.language-edit-dialog-actions { display: flex; justify-content: flex-end; gap: 10px; flex-wrap: wrap; }
.language-edit-dialog-actions button { min-height: 42px; padding: 0 18px; border: 0; border-radius: 11px; color: #fff; background: var(--brand); font-weight: 800; cursor: pointer; }
.language-edit-dialog-actions button.secondary { color: var(--ink); border: 1px solid #cbd9d7; background: #fff; }
'''
p.write_text(s)

# Contract tests.
Path('tests/invalid-language-edit-preservation.php').write_text(r'''<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/src/I18n/LanguageGuard.php';
function okp(bool $c, string $m): void { if (!$c) throw new RuntimeException('FAIL: '.$m); echo 'PASS: '.$m.PHP_EOL; }
try {
    LanguageGuard::assertExpectedLanguage('Check that it is clean and undamaged. nuvem', 'en');
    throw new RuntimeException('FAIL: nuvem was accepted');
} catch (LanguageValidationException $e) {
    okp(in_array('nuvem', $e->invalidWords, true), 'EN validation reports Portuguese offending word');
}
try {
    LanguageGuard::assertExpectedLanguage('Verificar se está limpo e sem danos. cloud', 'pt');
    throw new RuntimeException('FAIL: cloud was accepted');
} catch (LanguageValidationException $e) {
    okp(in_array('cloud', $e->invalidWords, true), 'PT validation reports English offending word');
}
$api = file_get_contents(dirname(__DIR__).'/api.php');
$app = file_get_contents(dirname(__DIR__).'/assets/app.js');
$css = file_get_contents(dirname(__DIR__).'/assets/app.css');
$rooms = file_get_contents(dirname(__DIR__).'/rooms.php');
okp(str_contains($api, 'validate_bilingual_texts'), 'server exposes validation-only endpoint');
okp(str_contains($api, "'invalidWords' => $exception->invalidWords"), '422 includes offending words');
okp(str_contains($app, 'text-validation-overlay'), 'client overlays wrong-language word without replacing textarea');
okp(str_contains($app, 'language-edit-dialog'), 'context change has correction/cancel dialog');
okp(str_contains($app, 'resolveDirtyTextBeforeContextChange'), 'context changes validate pending text first');
okp(str_contains($css, '.invalid-language-word'), 'wrong-language word has separate red style');
okp(str_contains($rooms, "'locale' => Translator::locale()"), 'client receives active locale');
echo "Invalid-language edit preservation contract passed.\n";
''')

p = Path('.github/workflows/ci.yml')
s = p.read_text()
old = '          php tests/autosave-hardening.php\n'
if old not in s:
    raise SystemExit('CI autosave test line missing')
p.write_text(s.replace(old, old + '          php tests/invalid-language-edit-preservation.php\n', 1))

# Clean one-shot files before commit.
Path('.github/workflows/apply-invalid-text-preservation.yml').unlink(missing_ok=True)
Path('.github/scripts/apply-invalid-text-preservation.py').unlink(missing_ok=True)
