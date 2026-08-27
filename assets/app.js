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
    const BLUR_AUTOSAVE_DELAY_MS = 0;

    let rows = [];
    let saveTimer = null;
    let lastSavedChecklistFingerprint = '';
    const instructionSaveTimers = new Map();
    const instructionLastAttemptedValues = new Map();
    const savedFeedbackTimers = new WeakMap();
    let assignmentSaveQueue = Promise.resolve();
    let requestVersion = 0;
    let isLoading = false;
    let assignments = {};
    let roomAssignmentCounts = {};

    const clearInstructionSaveTimers = () => {
        instructionSaveTimers.forEach((timer) => window.clearTimeout(timer));
        instructionSaveTimers.clear();
        instructionLastAttemptedValues.clear();
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

    const scheduleInstructionSave = (itemName, textarea, delay = BLUR_AUTOSAVE_DELAY_MS) => {
        window.clearTimeout(instructionSaveTimers.get(itemName));
        instructionSaveTimers.set(itemName, window.setTimeout(() => {
            instructionSaveTimers.delete(itemName);
            const instructions = textarea.value.trim();
            if (instructionLastAttemptedValues.get(itemName) === instructions) return;
            instructionLastAttemptedValues.set(itemName, instructions);
            queueAssignmentSave([{ itemName, selected: true, instructions }]).then((saved) => {
                if (!saved && instructionLastAttemptedValues.get(itemName) === instructions) {
                    instructionLastAttemptedValues.delete(itemName);
                }
            });
        }, delay));
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
        textarea.addEventListener('input', () => {
            autoGrow(textarea);
            if (row.classList.contains('assignment-mode')) {
                if (!assignmentCheckbox || assignmentCheckbox.disabled || !assignmentCheckbox.checked) return;
                window.clearTimeout(instructionSaveTimers.get(item.name));
                instructionSaveTimers.delete(item.name);
                setStatus('Alterações por guardar');
            } else if (canEdit) {
                clearTimeout(saveTimer);
                setStatus('Alterações por guardar');
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
        textarea.dataset.defaultInstructions = item.defaultInstructions || '';
        const problemField = document.createElement('div');
        problemField.className = 'problem-field';
        const assignmentHint = document.createElement('span');
        assignmentHint.className = 'assignment-hint';
        assignmentHint.textContent = 'A verificar';
        assignmentHint.hidden = true;
        const instructionSaved = document.createElement('span');
        instructionSaved.className = 'row-save-feedback';
        instructionSaved.textContent = 'Guardado';
        instructionSaved.setAttribute('aria-live', 'polite');
        problemField.append(textarea, instructionSaved);

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

        return { element: row, name: item.name, textarea, assignmentHint, assignmentSaved, instructionSaved, status, assignmentCheckbox, assignmentLabel, itemHeading };
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
            if (!response.ok || !result.ok) throw new Error(result.error || 'Erro ao guardar automaticamente.');
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
            // Keep rejected instruction text visible so the user can correct it.
            // Checkbox/assignment failures still resync from persisted state.
            if (contextMatches() && feedbackKind === 'assignment') updateAssignmentMode();
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
        if (isLoading || !canEdit || snapshot.fingerprint === lastSavedChecklistFingerprint) {
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
                throw new Error(result.error || 'Erro ao guardar.');
            }
            if (version === requestVersion) {
                lastSavedChecklistFingerprint = snapshot.fingerprint;
                const persistedByName = new Map(snapshot.items.map((item) => [item.name, item.problem]));
                rows.forEach((row) => {
                    if (persistedByName.has(row.name)) row.textarea.dataset.problem = persistedByName.get(row.name);
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
                setStatus(error.message, 'error');
            }
            return false;
        }
    };

    const scheduleSave = (delay = BLUR_AUTOSAVE_DELAY_MS) => {
        if (isLoading || !canEdit) {
            return;
        }
        clearTimeout(saveTimer);
        const snapshot = checklistSnapshot();
        if (snapshot.fingerprint === lastSavedChecklistFingerprint) {
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
    renderChecklist(config.items.map((name) => ({
        name, problem: '', status: null,
        defaultInstructions: config.itemDefaults?.[name] || '',
    })));
    loadChecklist();
    loadWhatsAppReminder();
})();
