(() => {
    'use strict';

    const config = window.ROOM_CHECK;
    const propertySelect = document.querySelector('#propertySelect');
    const roomSelect = document.querySelector('#roomSelect');
    const selectorsPanel = roomSelect.closest('.selectors');
    const checklist = document.querySelector('#checklist');
    const checklistCard = checklist.closest('.checklist-card');
    const assignmentHeadingControl = document.querySelector('.assignment-heading-control');
    const listSelect = document.querySelector('#listSelect');
    const saveStatus = document.querySelector('#saveStatus');
    const intervalSelect = document.querySelector('#intervalSelect');
    const intervalDates = document.querySelector('#intervalDates');
    const intervalName = document.querySelector('#intervalName');
    const intervalStart = document.querySelector('#intervalStart');
    const intervalEnd = document.querySelector('#intervalEnd');
    const createInterval = document.querySelector('#createInterval');
    const intervalManager = document.querySelector('#intervalManager');
    const editIntervalSelect = document.querySelector('#editIntervalSelect');
    const editIntervalName = document.querySelector('#editIntervalName');
    const editIntervalStart = document.querySelector('#editIntervalStart');
    const editIntervalEnd = document.querySelector('#editIntervalEnd');
    const saveInterval = document.querySelector('#saveInterval');
    const deleteInterval = document.querySelector('#deleteInterval');
    const employeeSelect = document.querySelector('#employeeSelect');
    const assignmentDate = document.querySelector('#assignmentDate');
    const whatsappReminderEnabled = document.querySelector('#whatsappReminderEnabled');
    const whatsappReminderTime = document.querySelector('#whatsappReminderTime');
    const whatsappReminderSaved = document.querySelector('#whatsappReminderSaved');
    const selectAllItems = document.querySelector('#selectAllItems');
    const canEdit = config.canEdit !== false;
    const canAssign = config.canAssign === true;
    const TEXT_AUTOSAVE_DELAY_MS = 1200;
    const BLUR_AUTOSAVE_DELAY_MS = 120;
    const SAVE_BOUNDARY_PATTERN = /[\s.,;:!?…)}\]]$/u;

    let rows = [];
    let saveTimer = null;
    let lastSavedChecklistFingerprint = '';
    let lastInvalidChecklistFingerprint = '';
    const instructionSaveTimers = new Map();
    const instructionLastAttemptedValues = new Map();
    const instructionInvalidValues = new Map();
    const savedFeedbackTimers = new WeakMap();
    let assignmentSaveQueue = Promise.resolve();
    let requestVersion = 0;
    let isLoading = false;
    let assignments = {};
    let roomAssignmentCounts = {};
    let navigationBypass = false;
    let navigationGuardBusy = false;
    const contextControlValues = new WeakMap();

    const clearInstructionSaveTimers = () => {
        instructionSaveTimers.forEach((timer) => window.clearTimeout(timer));
        instructionSaveTimers.clear();
        instructionLastAttemptedValues.clear();
        instructionInvalidValues.clear();
    };

    const showSavedFeedback = (element) => {
        if (!element) return;
        window.clearTimeout(savedFeedbackTimers.get(element));
        element.classList.add('is-visible');
        savedFeedbackTimers.set(element, window.setTimeout(() => {
            element.classList.remove('is-visible');
            savedFeedbackTimers.delete(element);
        }, 2000));
    };

    roomSelect.classList.add('room-select-native');
    const roomPicker = document.createElement('div');
    roomPicker.className = 'room-picker';
    const roomPickerButton = document.createElement('button');
    roomPickerButton.type = 'button';
    roomPickerButton.className = 'room-picker-button';
    roomPickerButton.setAttribute('aria-haspopup', 'listbox');
    roomPickerButton.setAttribute('aria-expanded', 'false');
    roomPickerButton.setAttribute('aria-label', 'Quarto');
    const roomPickerMenu = document.createElement('div');
    roomPickerMenu.className = 'room-picker-menu';
    roomPickerMenu.setAttribute('role', 'listbox');
    roomPickerMenu.setAttribute('aria-label', 'Quarto');
    roomPickerMenu.hidden = true;
    roomPicker.append(roomPickerButton, roomPickerMenu);
    roomSelect.insertAdjacentElement('afterend', roomPicker);

    const selection = () => ({
        property: propertySelect.value,
        room: Number(roomSelect.value),
        listId: Number(listSelect.value),
    });

    const employeeName = (employeeId) => config.employees
        .find((employee) => Number(employee.id) === Number(employeeId))?.display_name || 'Funcionária não identificada';

    const setStatus = (text, kind = '') => {
        saveStatus.textContent = text;
        saveStatus.dataset.kind = kind;
    };

    const autoGrow = (textarea) => {
        textarea.style.height = 'auto';
        textarea.style.height = `${Math.max(46, textarea.scrollHeight)}px`;
    };

    const textIsReadyForAutosave = (textarea, inputEvent = null) => {
        const value = textarea.value;
        if (value.trim() === '') return true;
        if (inputEvent?.inputType?.startsWith('insertFrom')) return true;
        const cursor = Number.isInteger(textarea.selectionStart) ? textarea.selectionStart : value.length;
        if (cursor <= 0) return false;
        return SAVE_BOUNDARY_PATTERN.test(value.slice(cursor - 1, cursor));
    };

    const scheduleInstructionSave = (itemName, textarea, delay = TEXT_AUTOSAVE_DELAY_MS) => {
        window.clearTimeout(instructionSaveTimers.get(itemName));
        instructionSaveTimers.set(itemName, window.setTimeout(() => {
            instructionSaveTimers.delete(itemName);
            const instructions = textarea.value.trim();
            if (instructionInvalidValues.get(itemName) === instructions) return;
            if (instructionLastAttemptedValues.get(itemName) === instructions) return;
            instructionLastAttemptedValues.set(itemName, instructions);
            queueAssignmentSave([{ itemName, selected: true, instructions }]).then((saved) => {
                if (!saved
                    && instructionInvalidValues.get(itemName) !== instructions
                    && instructionLastAttemptedValues.get(itemName) === instructions) {
                    instructionLastAttemptedValues.delete(itemName);
                }
            });
        }, delay));
    };

    const normalizeValidationWords = (words) => Array.from(new Set(
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
            if (!row.invalidWords?.length) row.textarea.dataset.persistedText = row.textarea.value;
            if (row.validationOverlay && !row.validationOverlay.hidden) row.validationOverlay.style.height = `${row.textarea.offsetHeight}px`;
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

    const renderRooms = (preferredRoom = 1) => {
        const count = config.properties[propertySelect.value];
        roomSelect.replaceChildren();

        for (let room = 1; room <= count; room += 1) {
            const option = document.createElement('option');
            option.value = String(room);
            option.textContent = String(room);
            roomSelect.append(option);
        }

        roomSelect.value = String(Math.min(preferredRoom, count));
        applyRoomAssignmentStates();
    };

    const closeRoomPicker = () => {
        roomPickerMenu.hidden = true;
        roomPickerButton.setAttribute('aria-expanded', 'false');
        selectorsPanel.classList.remove('room-picker-open');
    };

    function syncRoomPicker() {
        const selectedValue = roomSelect.value;
        roomPickerMenu.replaceChildren();
        Array.from(roomSelect.options).forEach((option) => {
            const menuOption = document.createElement('button');
            menuOption.type = 'button';
            menuOption.className = `room-picker-option ${Array.from(option.classList).join(' ')}`;
            menuOption.dataset.value = option.value;
            menuOption.textContent = option.textContent;
            menuOption.title = option.title;
            menuOption.setAttribute('role', 'option');
            menuOption.setAttribute('aria-selected', String(option.value === selectedValue));
            roomPickerMenu.append(menuOption);
        });
        const selected = roomSelect.selectedOptions[0];
        roomPickerButton.textContent = selected?.textContent || '';
        roomPickerButton.classList.remove('room-full', 'room-partial', 'room-empty');
        if (selected) roomPickerButton.classList.add(...Array.from(selected.classList));
    }

    function applyRoomAssignmentStates() {
        const totalItems = config.items.length;
        Array.from(roomSelect.options).forEach((option) => {
            const assignedItems = Math.min(totalItems, Number(roomAssignmentCounts[option.value] || 0));
            const state = assignedItems === 0 ? 'empty' : (assignedItems >= totalItems ? 'full' : 'partial');
            option.classList.remove('room-full', 'room-partial', 'room-empty');
            option.classList.add(`room-${state}`);
            option.title = state === 'full'
                ? 'Todos os itens atribuídos neste intervalo'
                : (state === 'partial' ? `${assignedItems} de ${totalItems} itens atribuídos neste intervalo` : 'Nenhum item atribuído neste intervalo');
        });
        const selected = roomSelect.selectedOptions[0];
        roomSelect.classList.remove('room-full', 'room-partial', 'room-empty');
        if (selected) roomSelect.classList.add(...Array.from(selected.classList));
        syncRoomPicker();
    }

    const formatDate = (value) => {
        const [year, month, day] = value.split('-');
        return `${day}/${month}/${year}`;
    };

    const formatIntervalOption = (interval) =>
        `${interval.name} — ${formatDate(interval.startDate)} a ${formatDate(interval.endDate)}`;

    const syncIntervalDates = () => {
        if (!intervalDates) return;
        const interval = selectedInterval();
        intervalDates.textContent = interval
            ? `${formatDate(interval.startDate)} a ${formatDate(interval.endDate)}`
            : '';
    };

    const selectedInterval = () => {
        const intervalId = Number(intervalSelect?.value || 0);
        return config.intervals.find((candidate) => candidate.id === intervalId) || null;
    };

    const selectedEditInterval = () => {
        const intervalId = Number(editIntervalSelect?.value || 0);
        return config.intervals.find((candidate) => candidate.id === intervalId) || null;
    };

    const applyIntervalDateLimits = () => {
        if (!editIntervalStart || !editIntervalEnd) return;
        const interval = selectedEditInterval();
        editIntervalStart.removeAttribute('min');
        editIntervalStart.removeAttribute('max');
        editIntervalEnd.removeAttribute('min');
        editIntervalEnd.removeAttribute('max');
        editIntervalStart.title = '';
        editIntervalEnd.title = '';
        if (!interval) return;

        const latestStart = [interval.firstDueDate, editIntervalEnd.value].filter(Boolean).sort()[0] || '';
        const earliestEnd = [interval.lastDueDate, editIntervalStart.value].filter(Boolean).sort().at(-1) || '';
        if (latestStart) editIntervalStart.max = latestStart;
        if (earliestEnd) editIntervalEnd.min = earliestEnd;
        editIntervalStart.title = interval.firstDueDate
            ? `Não pode ser posterior à primeira atribuição (${formatDate(interval.firstDueDate)})`
            : '';
        editIntervalEnd.title = interval.lastDueDate
            ? `Não pode ser anterior à última atribuição (${formatDate(interval.lastDueDate)})`
            : '';
    };

    const syncIntervalManager = () => {
        if (!intervalManager) return;
        const interval = selectedEditInterval();
        editIntervalName.value = interval?.name || '';
        editIntervalStart.value = interval?.startDate || '';
        editIntervalEnd.value = interval?.endDate || '';
        applyIntervalDateLimits();
        [editIntervalName, editIntervalStart, editIntervalEnd, saveInterval, deleteInterval]
            .forEach((control) => { control.disabled = !interval; });
    };

    const makeRow = (item) => {
        const row = document.createElement('article');
        row.className = 'check-row';

        const name = document.createElement('h2');
        name.textContent = item.name;
        const itemHeading = document.createElement('div');
        itemHeading.className = 'item-heading';

        const textarea = document.createElement('textarea');
        textarea.value = item.problem || '';
        textarea.placeholder = 'Descreva o problema…';
        textarea.rows = 1;
        textarea.maxLength = 5000;
        textarea.readOnly = !canEdit;
        textarea.setAttribute('aria-label', `Problema identificado: ${item.name}`);
        textarea.addEventListener('input', (event) => {
            autoGrow(textarea);
            lastInvalidChecklistFingerprint = '';
            const rowState = rows.find((candidate) => candidate.element === row);
            if (rowState) {
                if (instructionInvalidValues.has(item.name)
                    && instructionInvalidValues.get(item.name) !== textarea.value.trim()) {
                    instructionInvalidValues.delete(item.name);
                }
                refreshLanguageValidation(rowState);
            }
            if (row.classList.contains('assignment-mode')) {
                if (!assignmentCheckbox || assignmentCheckbox.disabled || !assignmentCheckbox.checked) return;
                window.clearTimeout(instructionSaveTimers.get(item.name));
                setStatus('Alterações por guardar');
                if (textIsReadyForAutosave(textarea, event)) {
                    scheduleInstructionSave(item.name, textarea);
                }
            } else if (canEdit) {
                clearTimeout(saveTimer);
                setStatus('Alterações por guardar');
                if (textIsReadyForAutosave(textarea, event)) {
                    scheduleSave();
                }
            }
        });
        textarea.addEventListener('blur', () => {
            if (row.classList.contains('assignment-mode')) {
                if (!assignmentCheckbox || assignmentCheckbox.disabled || !assignmentCheckbox.checked) return;
                scheduleInstructionSave(item.name, textarea, BLUR_AUTOSAVE_DELAY_MS);
            } else if (canEdit) {
                scheduleSave(BLUR_AUTOSAVE_DELAY_MS);
            }
        });
        textarea.dataset.problem = item.problem || '';
        textarea.dataset.persistedText = item.problem || '';
        textarea.dataset.defaultInstructions = item.defaultInstructions || '';
        const problemField = document.createElement('div');
        problemField.className = 'problem-field';
        const assignmentHint = document.createElement('span');
        assignmentHint.className = 'assignment-hint';
        assignmentHint.textContent = 'A verificar';
        assignmentHint.hidden = true;
        const validationOverlay = document.createElement('div');
        validationOverlay.className = 'text-validation-overlay';
        validationOverlay.hidden = true;
        validationOverlay.setAttribute('aria-hidden', 'true');
        const instructionSaved = document.createElement('span');
        instructionSaved.className = 'row-save-feedback';
        instructionSaved.textContent = 'Guardado';
        instructionSaved.setAttribute('aria-live', 'polite');
        problemField.append(textarea, validationOverlay, instructionSaved);

        const assignmentSaved = document.createElement('span');
        assignmentSaved.className = 'row-save-feedback assignment-save-feedback';
        assignmentSaved.textContent = 'Guardado';
        assignmentSaved.setAttribute('aria-live', 'polite');

        const status = document.createElement('div');
        status.className = 'status-options';
        status.setAttribute('role', 'group');
        status.setAttribute('aria-label', `Estado: ${item.name}`);

        const buttons = [
            ['wrong', 'Problema', 'wrong'],
            ['ok', 'OK', 'ok'],
        ].map(([value, label, className]) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.textContent = label;
            button.className = `status-button ${className}`;
            button.dataset.value = value;
            button.disabled = !canEdit;
            button.setAttribute('aria-pressed', String(item.status === value));
            button.addEventListener('click', () => {
                if (!canEdit) return;
                const wasSelected = button.getAttribute('aria-pressed') === 'true';
                status.querySelectorAll('button').forEach((candidate) => {
                    candidate.setAttribute('aria-pressed', 'false');
                });
                button.setAttribute('aria-pressed', String(!wasSelected));
                scheduleSave();
            });
            return button;
        });

        status.append(...buttons);
        let assignmentCheckbox = null;
        let assignmentLabel = null;
        if (canAssign) {
            assignmentLabel = document.createElement('label');
            assignmentLabel.className = 'assignment-check is-hidden';
            assignmentLabel.hidden = true;
            assignmentCheckbox = document.createElement('input');
            assignmentCheckbox.type = 'checkbox';
            assignmentCheckbox.disabled = true;
            assignmentCheckbox.setAttribute('aria-label', `Atribuir ${item.name}`);
            assignmentCheckbox.addEventListener('change', () => {
                window.clearTimeout(instructionSaveTimers.get(item.name));
                instructionSaveTimers.delete(item.name);
                textarea.readOnly = true;
                updateSelectAllState();
                queueAssignmentSave([{
                    itemName: item.name,
                    selected: assignmentCheckbox.checked,
                    instructions: textarea.value.trim(),
                }], [assignmentCheckbox]);
            });
            const marker = document.createElement('span');
            assignmentLabel.append(assignmentCheckbox, marker);
            const itemTitle = document.createElement('div');
            itemTitle.className = 'item-title';
            itemTitle.append(name);
            itemHeading.append(assignmentLabel, itemTitle, assignmentHint, assignmentSaved);
            row.append(itemHeading, problemField, status);
        } else {
            itemHeading.append(name);
            row.append(itemHeading, problemField, status);
        }

        return { element: row, name: item.name, textarea, validationOverlay, invalidWords: [], assignmentHint, assignmentSaved, instructionSaved, status, assignmentCheckbox, assignmentLabel, itemHeading };
    };

    function updateSelectAllState() {
        if (!selectAllItems) return;
        const checkboxes = rows
            .map((row) => row.assignmentCheckbox)
            .filter((checkbox) => checkbox && !checkbox.disabled);
        const checked = checkboxes.filter((checkbox) => checkbox.checked).length;
        selectAllItems.checked = checkboxes.length > 0 && checked === checkboxes.length;
        selectAllItems.indeterminate = checked > 0 && checked < checkboxes.length;
    }

    const updateAssignmentMode = () => {
        if (!canAssign) return;
        const interval = selectedInterval();
        syncIntervalDates();
        const employeeId = Number(employeeSelect.value);
        const dueDate = assignmentDate ? assignmentDate.value : '';
        if (interval && assignmentDate) {
            assignmentDate.min = interval.startDate;
            assignmentDate.max = interval.endDate;
            if (dueDate < interval.startDate || dueDate > interval.endDate) assignmentDate.value = interval.startDate;
        }
        const selectedDate = assignmentDate ? assignmentDate.value : '';
        const viewingAssignments = Boolean(interval);
        checklist.classList.toggle('assignment-view-mode', viewingAssignments);
        const active = viewingAssignments && employeeId > 0 && selectedDate !== '';
        checklistCard.classList.toggle('assignment-controls-visible', active);
        if (assignmentHeadingControl) assignmentHeadingControl.hidden = !active;
        if (active) clearTimeout(saveTimer);
        rows.forEach((row) => {
            row.element.classList.toggle('assignment-mode', viewingAssignments);
            const assignment = assignments[row.name] || {};
            const hasAssignment = Number(assignment.employeeId || 0) > 0;
            const sameAssignment = active && hasAssignment
                && Number(assignment.employeeId) === employeeId
                && assignment.dueDate === selectedDate;
            const sameEmployeeOtherDate = active && hasAssignment
                && Number(assignment.employeeId) === employeeId
                && assignment.dueDate !== selectedDate
                && assignment.completed !== true;
            const locked = hasAssignment && (!active || !sameAssignment || assignment.completed === true);
            row.assignmentLabel.hidden = !active;
            row.assignmentLabel.classList.toggle('is-hidden', !active);
            row.itemHeading.classList.toggle('has-assignment-check', active);
            row.assignmentCheckbox.disabled = !active || locked;
            row.assignmentCheckbox.checked = viewingAssignments && hasAssignment;
            const checkboxMarker = row.assignmentCheckbox.nextElementSibling;
            checkboxMarker.classList.toggle('saved-assignment', viewingAssignments && hasAssignment);
            checkboxMarker.classList.toggle('locked-assignment', viewingAssignments && locked);
            checkboxMarker.classList.toggle(
                'same-employee-other-date', active && locked && sameEmployeeOtherDate
            );
            row.element.classList.toggle('assignment-locked', viewingAssignments && locked);
            if (viewingAssignments && hasAssignment) {
                row.textarea.value = String(assignment.instructions || '').trim()
                    || row.textarea.dataset.problem
                    || row.textarea.dataset.defaultInstructions
                    || '';
            } else if (viewingAssignments) {
                row.textarea.value = row.textarea.dataset.problem
                    || row.textarea.dataset.defaultInstructions
                    || '';
            } else {
                row.textarea.value = row.textarea.dataset.problem || '';
            }
            row.textarea.placeholder = viewingAssignments ? 'Descreva a verificação…' : 'Descreva o problema…';
            row.textarea.setAttribute('aria-label', `${viewingAssignments ? 'Instruções da verificação' : 'Problema identificado'}: ${row.name}`);
            if (viewingAssignments && hasAssignment) {
                const state = assignment.completed ? 'já foi concluído' : `já está atribuído para ${formatDate(assignment.dueDate)}`;
                if (locked) row.assignmentCheckbox.parentElement.title = `Este item ${state}.`;
                else row.assignmentCheckbox.parentElement.removeAttribute('title');
                row.assignmentHint.textContent = assignment.completed
                    ? `Verificado em ${formatDate(assignment.dueDate)} — ${employeeName(assignment.employeeId)}`
                    : `Atribuído para ${formatDate(assignment.dueDate)} — ${employeeName(assignment.employeeId)}`;
            } else {
                row.assignmentCheckbox.parentElement.removeAttribute('title');
                row.assignmentHint.textContent = '';
            }
            row.assignmentHint.classList.toggle(
                'editable-assignment', active && !locked && sameAssignment
            );
            row.assignmentHint.classList.toggle(
                'same-employee-other-date', active && locked && sameEmployeeOtherDate
            );
            row.assignmentHint.hidden = !viewingAssignments || !hasAssignment;
            row.textarea.readOnly = viewingAssignments ? (!active || locked || !sameAssignment) : !canEdit;
            row.status.querySelectorAll('button').forEach((button) => { button.disabled = viewingAssignments || !canEdit; });
            autoGrow(row.textarea);
            if (!row.invalidWords?.length) {
                row.textarea.dataset.persistedText = row.textarea.value;
            }
            if (row.validationOverlay && !row.validationOverlay.hidden) {
                row.validationOverlay.style.height = `${row.textarea.offsetHeight}px`;
            }
        });
        const hasLockedItems = active && rows.some((row) => row.assignmentCheckbox.disabled);
        selectAllItems.disabled = !active || hasLockedItems;
        selectAllItems.parentElement.title = hasLockedItems
            ? 'Existem itens atribuídos a outra empregada ou data. Altere apenas as checkboxes disponíveis.'
            : '';
        updateSelectAllState();
        const prompt = interval ? 'Situação das atribuições — escolha a empregada e a data para alterar' : 'Escolha ou crie um intervalo';
        setStatus(active ? 'As alterações são guardadas automaticamente' : (canAssign ? prompt : (canEdit ? 'Dados carregados' : 'Apenas consulta')), active ? 'success' : 'success');
    };

    const renderChecklist = (items) => {
        checklist.replaceChildren();
        rows = items.map(makeRow);
        checklist.append(...rows.map((row) => row.element));
        requestAnimationFrame(() => rows.forEach((row) => autoGrow(row.textarea)));
    };

    const collectItems = () => rows.map((row) => {
        const selected = row.status.querySelector('[aria-pressed="true"]');
        return {
            name: row.name,
            problem: row.textarea.value,
            status: selected ? selected.dataset.value : null,
        };
    });

    const checklistSnapshot = () => {
        const items = collectItems();
        return { items, fingerprint: JSON.stringify(items) };
    };

    const loadChecklist = async () => {
        const version = ++requestVersion;
        const current = selection();
        if (!current.listId) {
            clearTimeout(saveTimer);
            assignments = {};
            roomAssignmentCounts = {};
            renderChecklist([]);
            applyRoomAssignmentStates();
            checklist.classList.remove('assignment-view-mode');
            checklist.setAttribute('aria-busy', 'false');
            isLoading = false;
            setStatus('Sem listas nesta área');
            return;
        }
        isLoading = true;
        checklist.classList.toggle('assignment-view-mode', Boolean(selectedInterval()));
        checklist.setAttribute('aria-busy', 'true');
        clearTimeout(saveTimer);
        setStatus('A carregar…');

        try {
            const params = new URLSearchParams({
                property: current.property,
                room: String(current.room),
                interval_id: intervalSelect ? intervalSelect.value : '0',
                list_id: String(current.listId),
            });
            const response = await fetch(`api.php?${params}`, {
                headers: { Accept: 'application/json' },
                cache: 'no-store',
            });
            const result = await response.json();

            if (!response.ok || !result.ok) {
                throw new Error(result.error || 'Erro ao carregar.');
            }
            if (version !== requestVersion) {
                return;
            }

            assignments = result.assignments || {};
            roomAssignmentCounts = result.roomAssignmentCounts || {};
            applyRoomAssignmentStates();
            renderChecklist(result.items);
            updateAssignmentMode();
            lastSavedChecklistFingerprint = checklistSnapshot().fingerprint;
            rememberContextControlValues();
            setStatus(canEdit ? 'Dados carregados' : 'Apenas consulta', 'success');
        } catch (error) {
            if (version === requestVersion) {
                roomAssignmentCounts = {};
                applyRoomAssignmentStates();
                renderChecklist(config.items.map((name) => ({
                    name, problem: '', status: null,
                    defaultInstructions: config.itemDefaults?.[name] || '',
                })));
                setStatus(error.message, 'error');
            }
        } finally {
            if (version === requestVersion) {
                isLoading = false;
                checklist.setAttribute('aria-busy', 'false');
            }
        }
    };

    const queueAssignmentSave = (changes, affectedCheckboxes = [], feedbackKind = affectedCheckboxes.length ? 'assignment' : 'instructions') => {
        const intervalId = Number(intervalSelect.value);
        const listId = Number(listSelect.value);
        const employeeId = Number(employeeSelect.value);
        const dueDate = assignmentDate.value;
        if (!intervalId || !listId || !employeeId || !dueDate || changes.length === 0) return Promise.resolve(false);
        const effectiveChanges = changes.filter((change) => {
            const existing = assignments[change.itemName];
            if (!change.selected) return Boolean(existing);
            if (!existing) return true;
            return Number(existing.employeeId) !== employeeId
                || existing.dueDate !== dueDate
                || String(existing.instructions || '').trim() !== String(change.instructions || '').trim();
        });
        if (effectiveChanges.length === 0) return Promise.resolve(false);
        changes = effectiveChanges;
        const current = selection();
        const contextMatches = () => Number(intervalSelect.value) === intervalId
            && Number(listSelect.value) === listId
            && Number(employeeSelect.value) === employeeId
            && assignmentDate.value === dueDate
            && propertySelect.value === current.property
            && Number(roomSelect.value) === current.room;
        affectedCheckboxes.forEach((checkbox) => { checkbox.disabled = true; });
        setStatus('A guardar automaticamente…');
        assignmentSaveQueue = assignmentSaveQueue.catch(() => false).then(async () => {
            const response = await fetch('api.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                body: JSON.stringify({
                    action: 'set_assignments_atomic', csrfToken: config.csrfToken, ...current,
                    intervalId, listId, employeeId, dueDate, changes,
                }),
            });
            const result = await response.json();
            if (!response.ok || !result.ok) {
                const error = new Error(result.error || 'Erro ao guardar automaticamente.');
                error.validation = result.validation === true;
                error.invalidWords = result.invalidWords || [];
                error.fieldKey = result.fieldKey || null;
                throw error;
            }
            const interval = config.intervals.find((candidate) => candidate.id === intervalId);
            if (interval && result.intervalBounds) {
                interval.firstDueDate = result.intervalBounds.firstDueDate;
                interval.lastDueDate = result.intervalBounds.lastDueDate;
                if (selectedEditInterval()?.id === intervalId) applyIntervalDateLimits();
            }
            if (contextMatches()) {
                changes.forEach((change) => {
                    if (change.selected) {
                        assignments[change.itemName] = {
                            employeeId, dueDate, instructions: change.instructions || '', completed: false,
                            reminderTime: assignments[change.itemName]?.reminderTime || null,
                            reminderStatus: assignments[change.itemName]?.reminderStatus || null,
                        };
                    } else {
                        delete assignments[change.itemName];
                    }
                });
                roomAssignmentCounts[String(current.room)] = Number(result.roomAssignedItems || 0);
                changes.forEach((change) => {
                    instructionInvalidValues.delete(change.itemName);
                    instructionLastAttemptedValues.delete(change.itemName);
                    clearLanguageValidation(rows.find((candidate) => candidate.name === change.itemName));
                });
                applyRoomAssignmentStates();
                updateAssignmentMode();
                changes.forEach((change) => {
                    const row = rows.find((candidate) => candidate.name === change.itemName);
                    showSavedFeedback(feedbackKind === 'assignment' ? row?.assignmentSaved : row?.instructionSaved);
                });
            }
            setStatus('Guardado automaticamente', 'success');
            return true;
        }).catch((error) => {
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
        });
        return assignmentSaveQueue;
    };

    const loadWhatsAppReminder = async () => {
        if (!whatsappReminderEnabled) return;
        const employeeId = Number(employeeSelect.value); const dueDate = assignmentDate.value; const listId = Number(listSelect.value);
        whatsappReminderEnabled.disabled = !employeeId || !dueDate || !listId;
        whatsappReminderEnabled.checked = false; whatsappReminderTime.value = '09:00'; whatsappReminderTime.disabled = true;
        if (!employeeId || !dueDate || !listId) return;
        try {
            const response = await fetch('api.php', { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                body: JSON.stringify({ action: 'get_whatsapp_reminder', csrfToken: config.csrfToken, employeeId, listId, dueDate, property: propertySelect.value }) });
            const result = await response.json(); if (!response.ok || !result.ok) throw new Error(result.error || 'Erro ao carregar alerta.');
            whatsappReminderEnabled.checked = Boolean(result.reminderTime);
            whatsappReminderTime.value = result.reminderTime || '09:00';
            whatsappReminderTime.disabled = !result.reminderTime;
        } catch (error) { setStatus(error.message, 'error'); }
    };
    const saveWhatsAppReminder = async () => {
        const employeeId = Number(employeeSelect.value); const dueDate = assignmentDate.value; const listId = Number(listSelect.value);
        if (!employeeId || !dueDate || !listId) return;
        whatsappReminderEnabled.disabled = true; whatsappReminderTime.disabled = true;
        try {
            const response = await fetch('api.php', { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                body: JSON.stringify({ action: 'schedule_whatsapp_reminder', csrfToken: config.csrfToken, employeeId, listId, dueDate, property: propertySelect.value,
                    enabled: whatsappReminderEnabled.checked, time: whatsappReminderTime.value }) });
            const result = await response.json(); if (!response.ok || !result.ok) throw new Error(result.error || 'Erro ao guardar alerta.');
            showSavedFeedback(whatsappReminderSaved);
        } catch (error) { setStatus(error.message, 'error'); }
        whatsappReminderEnabled.disabled = false; whatsappReminderTime.disabled = !whatsappReminderEnabled.checked;
    };

    const saveChecklist = async (snapshot = checklistSnapshot()) => {
        if (isLoading || !canEdit
            || snapshot.fingerprint === lastSavedChecklistFingerprint
            || snapshot.fingerprint === lastInvalidChecklistFingerprint) {
            return false;
        }

        const current = selection();
        const version = requestVersion;
        setStatus('A guardar…');

        try {
            const response = await fetch('api.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                },
                body: JSON.stringify({ ...current, items: snapshot.items }),
            });
            const result = await response.json();

            if (!response.ok || !result.ok) {
                const error = new Error(result.error || 'Erro ao guardar.');
                error.validation = result.validation === true;
                error.invalidWords = result.invalidWords || [];
                error.fieldKey = result.fieldKey || null;
                throw error;
            }
            if (version === requestVersion) {
                lastSavedChecklistFingerprint = snapshot.fingerprint;
                lastInvalidChecklistFingerprint = '';
                const persistedByName = new Map(snapshot.items.map((item) => [item.name, item.problem]));
                rows.forEach((row) => {
                    if (persistedByName.has(row.name)) {
                        const persisted = persistedByName.get(row.name);
                        row.textarea.dataset.problem = persisted;
                        row.textarea.dataset.persistedText = persisted;
                        clearLanguageValidation(row);
                    }
                });
                if (checklistSnapshot().fingerprint === snapshot.fingerprint) {
                    setStatus('Guardado', 'success');
                } else {
                    setStatus('Alterações por guardar');
                }
            }
            return true;
        } catch (error) {
            if (version === requestVersion) {
                if (error.validation === true) {
                    lastInvalidChecklistFingerprint = snapshot.fingerprint;
                    const row = rows.find((candidate) => candidate.name === error.fieldKey);
                    if (row) renderLanguageValidation(row, error.invalidWords || []);
                }
                setStatus(error.message, 'error');
            }
            return false;
        }
    };

    const scheduleSave = (delay = TEXT_AUTOSAVE_DELAY_MS) => {
        if (isLoading || !canEdit) {
            return;
        }
        clearTimeout(saveTimer);
        const snapshot = checklistSnapshot();
        if (snapshot.fingerprint === lastSavedChecklistFingerprint
            || snapshot.fingerprint === lastInvalidChecklistFingerprint) {
            return;
        }
        setStatus('Alterações por guardar');
        saveTimer = window.setTimeout(() => saveChecklist(snapshot), delay);
    };

    const createVerificationInterval = async () => {
        const name = intervalName.value.trim();
        const startDate = intervalStart.value;
        const endDate = intervalEnd.value;
        if (!name || !startDate || !endDate) {
            setStatus('Preencha o nome e as duas datas do intervalo', 'error');
            return;
        }
        createInterval.disabled = true;
        setStatus('A criar intervalo…');
        try {
            const response = await fetch('api.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                body: JSON.stringify({
                    action: 'create_interval', csrfToken: config.csrfToken, name, startDate, endDate,
                }),
            });
            const result = await response.json();
            if (!response.ok || !result.ok) throw new Error(result.error || 'Erro ao criar o intervalo.');
            config.intervals.unshift(result.interval);
            const option = document.createElement('option');
            option.value = String(result.interval.id);
            option.textContent = result.interval.name;
            intervalSelect.append(option);
            const editOption = document.createElement('option');
            editOption.value = String(result.interval.id);
            editOption.textContent = formatIntervalOption(result.interval);
            editIntervalSelect.append(editOption);
            intervalSelect.value = String(result.interval.id);
            assignmentDate.value = result.interval.startDate;
            intervalName.value = '';
            intervalStart.value = '';
            intervalEnd.value = '';
            syncIntervalManager();
            await loadChecklist();
            setStatus('Intervalo criado', 'success');
        } catch (error) {
            setStatus(error.message, 'error');
        } finally {
            createInterval.disabled = false;
        }
    };

    const updateVerificationInterval = async () => {
        const interval = selectedEditInterval();
        if (!interval) return;
        const name = editIntervalName.value.trim();
        const startDate = editIntervalStart.value;
        const endDate = editIntervalEnd.value;
        if (!name || !startDate || !endDate) {
            setStatus('Preencha o nome e as duas datas do intervalo', 'error');
            return;
        }
        saveInterval.disabled = true;
        deleteInterval.disabled = true;
        setStatus('A guardar intervalo…');
        try {
            const response = await fetch('api.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                body: JSON.stringify({
                    action: 'update_interval', csrfToken: config.csrfToken,
                    intervalId: interval.id, name, startDate, endDate,
                }),
            });
            const result = await response.json();
            if (!response.ok || !result.ok) throw new Error(result.error || 'Erro ao guardar o intervalo.');
            Object.assign(interval, result.interval);
            const option = intervalSelect.querySelector(`option[value="${interval.id}"]`);
            if (option) option.textContent = interval.name;
            const editOption = editIntervalSelect.querySelector(`option[value="${interval.id}"]`);
            if (editOption) editOption.textContent = formatIntervalOption(interval);
            updateAssignmentMode();
            syncIntervalManager();
            setStatus('Intervalo atualizado', 'success');
        } catch (error) {
            setStatus(error.message, 'error');
        } finally {
            saveInterval.disabled = false;
            deleteInterval.disabled = false;
        }
    };

    const deleteVerificationInterval = async () => {
        const interval = selectedEditInterval();
        if (!interval) return;
        const confirmed = window.confirm(
            `Apagar o intervalo “${interval.name}”? Todas as atribuições deste intervalo também serão apagadas. Esta ação não pode ser anulada.`
        );
        if (!confirmed) return;
        saveInterval.disabled = true;
        deleteInterval.disabled = true;
        setStatus('A apagar intervalo…');
        try {
            const response = await fetch('api.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                body: JSON.stringify({
                    action: 'delete_interval', csrfToken: config.csrfToken, intervalId: interval.id,
                }),
            });
            const result = await response.json();
            if (!response.ok || !result.ok) throw new Error(result.error || 'Erro ao apagar o intervalo.');
            config.intervals = config.intervals.filter((candidate) => candidate.id !== interval.id);
            intervalSelect.querySelector(`option[value="${interval.id}"]`)?.remove();
            editIntervalSelect.querySelector(`option[value="${interval.id}"]`)?.remove();
            editIntervalSelect.value = '';
            const deletedActiveInterval = Number(intervalSelect.value || 0) === interval.id;
            if (deletedActiveInterval) {
                intervalSelect.value = '';
                employeeSelect.value = '';
            }
            syncIntervalManager();
            if (deletedActiveInterval) await loadChecklist();
            setStatus(`Intervalo apagado (${result.deletedAssignments} atribuições removidas)`, 'success');
        } catch (error) {
            setStatus(error.message, 'error');
        } finally {
            saveInterval.disabled = false;
            deleteInterval.disabled = false;
        }
    };

    document.addEventListener('change', (event) => {
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

    propertySelect.addEventListener('change', () => {
        clearInstructionSaveTimers();
        roomAssignmentCounts = {};
        renderRooms(1);
        loadChecklist();
        loadWhatsAppReminder();
    });
    listSelect.addEventListener('change', () => {
        clearInstructionSaveTimers();
        const list = config.lists.find((candidate) => Number(candidate.id) === Number(listSelect.value));
        config.items = Array.isArray(list?.items) ? list.items : [];
        config.itemDefaults = list?.defaults || {};
        assignments = {};
        roomAssignmentCounts = {};
        applyRoomAssignmentStates();
        renderChecklist(config.items.map((name) => ({
            name, problem: '', status: null,
            defaultInstructions: config.itemDefaults?.[name] || '',
        })));
        loadChecklist();
        loadWhatsAppReminder();
    });
    roomSelect.addEventListener('change', () => {
        clearInstructionSaveTimers();
        applyRoomAssignmentStates();
        loadChecklist();
    });
    roomPickerButton.addEventListener('click', () => {
        const willOpen = roomPickerMenu.hidden;
        roomPickerMenu.hidden = !willOpen;
        roomPickerButton.setAttribute('aria-expanded', String(willOpen));
        selectorsPanel.classList.toggle('room-picker-open', willOpen);
        if (willOpen) roomPickerMenu.querySelector('[aria-selected="true"]')?.focus();
    });
    roomPickerMenu.addEventListener('click', (event) => {
        const option = event.target.closest('.room-picker-option');
        if (!option) return;
        roomSelect.value = option.dataset.value;
        closeRoomPicker();
        roomSelect.dispatchEvent(new Event('change', { bubbles: true }));
        roomPickerButton.focus();
    });
    roomPickerMenu.addEventListener('keydown', (event) => {
        const options = Array.from(roomPickerMenu.querySelectorAll('.room-picker-option'));
        const index = options.indexOf(document.activeElement);
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            const step = event.key === 'ArrowDown' ? 1 : -1;
            options[(index + step + options.length) % options.length]?.focus();
        } else if (event.key === 'Escape') {
            closeRoomPicker();
            roomPickerButton.focus();
        }
    });
    document.addEventListener('click', (event) => {
        if (!roomPicker.contains(event.target)) closeRoomPicker();
    });
    if (intervalSelect) intervalSelect.addEventListener('change', async () => {
        clearInstructionSaveTimers();
        employeeSelect.value = '';
        assignments = {};
        roomAssignmentCounts = {};
        applyRoomAssignmentStates();
        rows.forEach((row) => {
            row.assignmentCheckbox.checked = false;
            row.assignmentCheckbox.disabled = true;
            row.assignmentCheckbox.nextElementSibling.classList.remove('saved-assignment');
        });
        await loadChecklist();
    });
    if (createInterval) createInterval.addEventListener('click', createVerificationInterval);
    if (saveInterval) saveInterval.addEventListener('click', updateVerificationInterval);
    if (deleteInterval) deleteInterval.addEventListener('click', deleteVerificationInterval);
    if (editIntervalSelect) editIntervalSelect.addEventListener('change', syncIntervalManager);
    if (editIntervalStart) editIntervalStart.addEventListener('change', applyIntervalDateLimits);
    if (editIntervalEnd) editIntervalEnd.addEventListener('change', applyIntervalDateLimits);
    if (employeeSelect) employeeSelect.addEventListener('change', () => {
        clearInstructionSaveTimers();
        updateAssignmentMode();
        loadWhatsAppReminder();
    });
    if (assignmentDate) assignmentDate.addEventListener('change', () => {
        clearInstructionSaveTimers();
        updateAssignmentMode();
        loadWhatsAppReminder();
    });
    if (whatsappReminderEnabled) whatsappReminderEnabled.addEventListener('change', () => { whatsappReminderTime.disabled = !whatsappReminderEnabled.checked; saveWhatsAppReminder(); });
    if (whatsappReminderTime) whatsappReminderTime.addEventListener('change', () => { if (whatsappReminderEnabled.checked) saveWhatsAppReminder(); });
    if (selectAllItems) selectAllItems.addEventListener('change', () => {
        const changes = [];
        const affected = [];
        rows.forEach((row) => {
            if (row.assignmentCheckbox.disabled) return;
            row.assignmentCheckbox.checked = selectAllItems.checked;
            row.textarea.readOnly = true;
            changes.push({
                itemName: row.name,
                selected: selectAllItems.checked,
                instructions: row.textarea.value.trim(),
            });
            affected.push(row.assignmentCheckbox);
        });
        updateSelectAllState();
        queueAssignmentSave(changes, affected);
    });

    window.addEventListener('beforeunload', () => {
        clearTimeout(saveTimer);
        clearInstructionSaveTimers();
        if (!canEdit || isLoading || checklist.classList.contains('assignment-view-mode')) return;
        const snapshot = checklistSnapshot();
        if (snapshot.fingerprint === lastSavedChecklistFingerprint) return;
        const current = selection();
        fetch('api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
            body: JSON.stringify({ ...current, items: snapshot.items }),
            keepalive: true,
        }).catch(() => {});
    });

    if (config.initialProperty && Object.hasOwn(config.properties, config.initialProperty)) {
        propertySelect.value = config.initialProperty;
    }
    renderRooms(Number(config.initialRoom) || 1);
    if (assignmentDate && !assignmentDate.value) assignmentDate.value = config.today;
    rememberContextControlValues();
    renderChecklist(config.items.map((name) => ({
        name, problem: '', status: null,
        defaultInstructions: config.itemDefaults?.[name] || '',
    })));
    loadChecklist();
    loadWhatsAppReminder();
})();
