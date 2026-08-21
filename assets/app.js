(() => {
    'use strict';

    const config = window.ROOM_CHECK;
    const propertySelect = document.querySelector('#propertySelect');
    const roomSelect = document.querySelector('#roomSelect');
    const checklist = document.querySelector('#checklist');
    const saveStatus = document.querySelector('#saveStatus');
    const intervalSelect = document.querySelector('#intervalSelect');
    const intervalName = document.querySelector('#intervalName');
    const intervalStart = document.querySelector('#intervalStart');
    const intervalEnd = document.querySelector('#intervalEnd');
    const createInterval = document.querySelector('#createInterval');
    const intervalManager = document.querySelector('#intervalManager');
    const editIntervalName = document.querySelector('#editIntervalName');
    const editIntervalStart = document.querySelector('#editIntervalStart');
    const editIntervalEnd = document.querySelector('#editIntervalEnd');
    const saveInterval = document.querySelector('#saveInterval');
    const deleteInterval = document.querySelector('#deleteInterval');
    const employeeSelect = document.querySelector('#employeeSelect');
    const assignmentDate = document.querySelector('#assignmentDate');
    const selectAllItems = document.querySelector('#selectAllItems');
    const assignmentActions = document.querySelector('#assignmentActions');
    const saveAssignments = document.querySelector('#saveAssignments');
    const canEdit = config.canEdit !== false;
    const canAssign = config.canAssign === true;

    let rows = [];
    let saveTimer = null;
    let requestVersion = 0;
    let isLoading = false;
    let assignments = {};
    let roomAssignmentCounts = {};
    const draftPrefix = 'room-check-assignment-draft:v1';

    const selection = () => ({
        property: propertySelect.value,
        room: Number(roomSelect.value),
    });

    const draftKey = () => {
        if (!canAssign) return null;
        const intervalId = Number(intervalSelect?.value || 0);
        const employeeId = Number(employeeSelect?.value || 0);
        const dueDate = assignmentDate?.value || '';
        if (!intervalId || !employeeId || !dueDate) return null;
        const current = selection();
        return [draftPrefix, intervalId, current.property, current.room, employeeId, dueDate].join('|');
    };

    const readDraftData = () => {
        const key = draftKey();
        if (!key) return { items: new Set(), instructions: {} };
        try {
            const value = JSON.parse(window.localStorage.getItem(key) || '[]');
            const items = Array.isArray(value) ? value : value.items;
            const instructions = !Array.isArray(value) && value && typeof value.instructions === 'object'
                ? value.instructions : {};
            return {
                items: new Set(Array.isArray(items) ? items.filter((item) => typeof item === 'string') : []),
                instructions,
            };
        } catch {
            return { items: new Set(), instructions: {} };
        }
    };

    const readOtherDraftAssignments = () => {
        const currentKey = draftKey();
        const intervalId = Number(intervalSelect?.value || 0);
        if (!currentKey || !intervalId) return new Map();
        const current = selection();
        const keyPrefix = [draftPrefix, intervalId, current.property, current.room].join('|') + '|';
        const reserved = new Map();
        try {
            for (let index = 0; index < window.localStorage.length; index += 1) {
                const key = window.localStorage.key(index);
                if (!key || key === currentKey || !key.startsWith(keyPrefix)) continue;
                const parts = key.split('|');
                const dueDate = parts[parts.length - 1] || '';
                const value = JSON.parse(window.localStorage.getItem(key) || '[]');
                const items = Array.isArray(value) ? value : value.items;
                const instructions = !Array.isArray(value) && value && typeof value.instructions === 'object'
                    ? value.instructions : {};
                if (!Array.isArray(items)) continue;
                items.forEach((item) => {
                    if (typeof item === 'string' && !reserved.has(item)) {
                        reserved.set(item, { dueDate, instructions: String(instructions[item] || '') });
                    }
                });
            }
        } catch {
            return new Map();
        }
        return reserved;
    };

    const saveDraft = () => {
        const key = draftKey();
        if (!key) return;
        const selectedItems = rows
            .filter((row) => row.assignmentCheckbox && !row.assignmentCheckbox.disabled && row.assignmentCheckbox.checked)
            .map((row) => row.name);
        const instructions = Object.fromEntries(rows
            .filter((row) => selectedItems.includes(row.name))
            .map((row) => [row.name, row.textarea.value]));
        try {
            window.localStorage.setItem(key, JSON.stringify({ items: selectedItems, instructions }));
            applyRoomAssignmentStates();
            setStatus('Seleção guardada neste browser', 'success');
        } catch {
            setStatus('Não foi possível guardar o rascunho neste browser', 'error');
        }
    };

    const clearDraft = () => {
        const key = draftKey();
        if (!key) return;
        try { window.localStorage.removeItem(key); } catch { /* armazenamento indisponível */ }
    };

    const setStatus = (text, kind = '') => {
        saveStatus.textContent = text;
        saveStatus.dataset.kind = kind;
    };

    const autoGrow = (textarea) => {
        textarea.style.height = 'auto';
        textarea.style.height = `${Math.max(46, textarea.scrollHeight)}px`;
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

    function applyRoomAssignmentStates() {
        const totalItems = config.items.length;
        const draftCounts = new Map();
        const intervalId = Number(intervalSelect?.value || 0);
        const current = selection();
        const keyPrefix = [draftPrefix, intervalId, current.property].join('|') + '|';
        if (intervalId) {
            try {
                for (let index = 0; index < window.localStorage.length; index += 1) {
                    const key = window.localStorage.key(index);
                    if (!key || !key.startsWith(keyPrefix)) continue;
                    const parts = key.split('|');
                    const room = parts[3] || '';
                    const value = JSON.parse(window.localStorage.getItem(key) || '[]');
                    const items = Array.isArray(value) ? value : value.items;
                    if (!Array.isArray(items)) continue;
                    if (!draftCounts.has(room)) draftCounts.set(room, new Set());
                    items.forEach((item) => { if (typeof item === 'string') draftCounts.get(room).add(item); });
                }
            } catch { /* as cores continuam a usar apenas os dados da base de dados */ }
        }
        Array.from(roomSelect.options).forEach((option) => {
            const assignedItems = Math.min(totalItems,
                Number(roomAssignmentCounts[option.value] || 0) + (draftCounts.get(option.value)?.size || 0));
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
    }

    const formatDate = (value) => {
        const [year, month, day] = value.split('-');
        return `${day}/${month}/${year}`;
    };

    const formatIntervalOption = (interval) =>
        `${interval.name} — ${formatDate(interval.startDate)} a ${formatDate(interval.endDate)}`;

    const selectedInterval = () => {
        const intervalId = Number(intervalSelect?.value || 0);
        return config.intervals.find((candidate) => candidate.id === intervalId) || null;
    };

    const syncIntervalManager = () => {
        if (!intervalManager) return;
        const interval = selectedInterval();
        intervalManager.hidden = !interval;
        if (!interval) return;
        editIntervalName.value = interval.name;
        editIntervalStart.value = interval.startDate;
        editIntervalEnd.value = interval.endDate;
    };

    const makeRow = (item) => {
        const row = document.createElement('article');
        row.className = 'check-row';

        const name = document.createElement('h2');
        name.textContent = item.name;

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
                saveDraft();
            } else if (canEdit) {
                textarea.dataset.problem = textarea.value;
                scheduleSave();
            }
        });
        textarea.dataset.problem = item.problem || '';
        const problemField = document.createElement('div');
        problemField.className = 'problem-field';
        const assignmentHint = document.createElement('span');
        assignmentHint.className = 'assignment-hint';
        assignmentHint.textContent = 'A verificar';
        assignmentHint.hidden = true;
        problemField.append(textarea, assignmentHint);

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
        if (canAssign) {
            const assignmentLabel = document.createElement('label');
            assignmentLabel.className = 'assignment-check';
            assignmentCheckbox = document.createElement('input');
            assignmentCheckbox.type = 'checkbox';
            assignmentCheckbox.disabled = true;
            assignmentCheckbox.setAttribute('aria-label', `Atribuir ${item.name}`);
            assignmentCheckbox.addEventListener('change', () => {
                updateSelectAllState();
                saveDraft();
                updateAssignmentMode();
            });
            const marker = document.createElement('span');
            assignmentLabel.append(assignmentCheckbox, marker);
            row.append(name, problemField, status, assignmentLabel);
        } else {
            row.append(name, problemField, status);
        }

        return { element: row, name: item.name, textarea, assignmentHint, status, assignmentCheckbox };
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
        const employeeId = Number(employeeSelect.value);
        const dueDate = assignmentDate ? assignmentDate.value : '';
        if (interval && assignmentDate) {
            assignmentDate.min = interval.startDate;
            assignmentDate.max = interval.endDate;
            if (dueDate < interval.startDate || dueDate > interval.endDate) assignmentDate.value = interval.startDate;
        }
        const selectedDate = assignmentDate ? assignmentDate.value : '';
        const active = Boolean(interval) && employeeId > 0 && selectedDate !== '';
        if (active) clearTimeout(saveTimer);
        const draftData = active ? readDraftData() : { items: new Set(), instructions: {} };
        const draft = draftData.items;
        const otherDrafts = active ? readOtherDraftAssignments() : new Map();
        rows.forEach((row) => {
            row.element.classList.toggle('assignment-mode', active);
            const assignment = assignments[row.name] || {};
            const hasAssignment = Number(assignment.employeeId || 0) > 0;
            const sameAssignment = hasAssignment
                && Number(assignment.employeeId) === employeeId
                && assignment.dueDate === selectedDate;
            const otherDraft = otherDrafts.get(row.name) || null;
            const draftDate = otherDraft?.dueDate || '';
            const lockedByDraft = !hasAssignment && otherDraft !== null;
            const selectedInCurrentDraft = !hasAssignment && draft.has(row.name);
            const locked = (hasAssignment && (!sameAssignment || assignment.completed === true)) || lockedByDraft;
            row.assignmentCheckbox.disabled = !active || locked;
            row.assignmentCheckbox.checked = active
                && (hasAssignment || lockedByDraft || selectedInCurrentDraft);
            const checkboxMarker = row.assignmentCheckbox.nextElementSibling;
            checkboxMarker.classList.toggle('saved-assignment', active && hasAssignment);
            checkboxMarker.classList.toggle(
                'draft-assignment', active && !hasAssignment && (lockedByDraft || selectedInCurrentDraft)
            );
            row.element.classList.toggle('assignment-locked', active && locked);
            if (active && hasAssignment) {
                row.textarea.value = String(assignment.instructions || '');
            } else if (active && lockedByDraft) {
                row.textarea.value = String(otherDraft.instructions || '');
            } else if (active) {
                row.textarea.value = String(draftData.instructions[row.name] || '');
            } else {
                row.textarea.value = row.textarea.dataset.problem || '';
            }
            row.textarea.placeholder = active ? 'Descreva a verificação…' : 'Descreva o problema…';
            row.textarea.setAttribute('aria-label', `${active ? 'Instruções da verificação' : 'Problema identificado'}: ${row.name}`);
            if (active && locked) {
                const state = lockedByDraft
                    ? `já está selecionado num rascunho para ${formatDate(draftDate)}`
                    : (assignment.completed ? 'já foi concluído' : `já está atribuído para ${formatDate(assignment.dueDate)}`);
                row.assignmentCheckbox.parentElement.title = `Este item ${state}.`;
                row.assignmentHint.textContent = lockedByDraft
                    ? `Selecionado para ${formatDate(draftDate)} (rascunho)`
                    : (assignment.completed
                        ? `Verificado em ${formatDate(assignment.dueDate)}`
                        : `Atribuído para ${formatDate(assignment.dueDate)}`);
            } else if (active && selectedInCurrentDraft) {
                row.assignmentCheckbox.parentElement.removeAttribute('title');
                row.assignmentHint.textContent = `Selecionado para ${formatDate(selectedDate)} (rascunho)`;
            } else if (active && sameAssignment) {
                row.assignmentCheckbox.parentElement.removeAttribute('title');
                row.assignmentHint.textContent = `Atribuído para ${formatDate(selectedDate)}`;
            } else {
                row.assignmentCheckbox.parentElement.removeAttribute('title');
                row.assignmentHint.textContent = '';
            }
            row.assignmentHint.hidden = !active || (!locked && !selectedInCurrentDraft && !sameAssignment);
            row.textarea.readOnly = active ? locked : !canEdit;
            row.status.querySelectorAll('button').forEach((button) => { button.disabled = active || !canEdit; });
            autoGrow(row.textarea);
        });
        selectAllItems.disabled = !active;
        assignmentActions.hidden = !active;
        updateSelectAllState();
        const prompt = interval ? 'Escolha a empregada e a data dentro do intervalo' : 'Escolha ou crie um intervalo';
        setStatus(active ? 'Selecione os itens a atribuir' : (canAssign ? prompt : (canEdit ? 'Dados carregados' : 'Apenas consulta')), active ? '' : 'success');
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

    const loadChecklist = async () => {
        const version = ++requestVersion;
        const current = selection();
        isLoading = true;
        clearTimeout(saveTimer);
        setStatus('A carregar…');

        try {
            const params = new URLSearchParams({
                property: current.property,
                room: String(current.room),
                interval_id: intervalSelect ? intervalSelect.value : '0',
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
            if (employeeSelect) employeeSelect.value = '';
            updateAssignmentMode();
            setStatus(canEdit ? 'Dados carregados' : 'Apenas consulta', 'success');
        } catch (error) {
            if (version === requestVersion) {
                roomAssignmentCounts = {};
                applyRoomAssignmentStates();
                renderChecklist(config.items.map((name) => ({ name, problem: '', status: null })));
                setStatus(error.message, 'error');
            }
        } finally {
            if (version === requestVersion) {
                isLoading = false;
            }
        }
    };

    const saveSelectedAssignments = async () => {
        const intervalId = Number(intervalSelect.value);
        const employeeId = Number(employeeSelect.value);
        const dueDate = assignmentDate.value;
        if (!intervalId || !employeeId || !dueDate) return;
        const current = selection();
        const selectedItems = rows
            .filter((row) => row.assignmentCheckbox && !row.assignmentCheckbox.disabled && row.assignmentCheckbox.checked)
            .map((row) => row.name);
        const instructions = Object.fromEntries(rows
            .filter((row) => selectedItems.includes(row.name))
            .map((row) => [row.name, row.textarea.value.trim()]));
        saveAssignments.disabled = true;
        setStatus('A guardar atribuição…');
        try {
            const response = await fetch('api.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                body: JSON.stringify({
                    action: 'assign_items', csrfToken: config.csrfToken, ...current,
                    intervalId, employeeId, dueDate, selectedItems, instructions,
                }),
            });
            const result = await response.json();
            if (!response.ok || !result.ok) throw new Error(result.error || 'Erro ao guardar a atribuição.');
            Object.keys(assignments).forEach((name) => {
                const assignment = assignments[name] || {};
                if (Number(assignment.employeeId) === employeeId
                    && assignment.dueDate === dueDate
                    && !selectedItems.includes(name)) delete assignments[name];
            });
            selectedItems.forEach((name) => { assignments[name] = { employeeId, dueDate, instructions: instructions[name] || '' }; });
            clearDraft();
            roomAssignmentCounts[String(current.room)] = Object.values(assignments)
                .filter((assignment) => Number(assignment.employeeId || 0) > 0).length;
            applyRoomAssignmentStates();
            updateAssignmentMode();
            setStatus('Atribuição guardada', 'success');
        } catch (error) {
            setStatus(error.message, 'error');
        } finally {
            saveAssignments.disabled = false;
        }
    };

    const saveChecklist = async () => {
        if (isLoading || !canEdit) {
            return;
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
                body: JSON.stringify({ ...current, items: collectItems() }),
            });
            const result = await response.json();

            if (!response.ok || !result.ok) {
                throw new Error(result.error || 'Erro ao guardar.');
            }
            if (version === requestVersion) {
                setStatus('Guardado', 'success');
            }
        } catch (error) {
            if (version === requestVersion) {
                setStatus(error.message, 'error');
            }
        }
    };

    const scheduleSave = () => {
        if (isLoading || !canEdit) {
            return;
        }
        clearTimeout(saveTimer);
        setStatus('Alterações por guardar');
        saveTimer = window.setTimeout(saveChecklist, 600);
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
            option.textContent = formatIntervalOption(result.interval);
            intervalSelect.append(option);
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
        const interval = selectedInterval();
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
            if (option) option.textContent = formatIntervalOption(interval);
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
        const interval = selectedInterval();
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
            intervalSelect.value = '';
            employeeSelect.value = '';
            syncIntervalManager();
            await loadChecklist();
            setStatus(`Intervalo apagado (${result.deletedAssignments} atribuições removidas)`, 'success');
        } catch (error) {
            setStatus(error.message, 'error');
        } finally {
            saveInterval.disabled = false;
            deleteInterval.disabled = false;
        }
    };

    propertySelect.addEventListener('change', () => {
        roomAssignmentCounts = {};
        renderRooms(1);
        loadChecklist();
    });
    roomSelect.addEventListener('change', () => {
        applyRoomAssignmentStates();
        loadChecklist();
    });
    if (intervalSelect) intervalSelect.addEventListener('change', async () => {
        employeeSelect.value = '';
        assignments = {};
        roomAssignmentCounts = {};
        applyRoomAssignmentStates();
        rows.forEach((row) => {
            row.assignmentCheckbox.checked = false;
            row.assignmentCheckbox.disabled = true;
            row.assignmentCheckbox.nextElementSibling.classList.remove('saved-assignment', 'draft-assignment');
        });
        syncIntervalManager();
        await loadChecklist();
    });
    if (createInterval) createInterval.addEventListener('click', createVerificationInterval);
    if (saveInterval) saveInterval.addEventListener('click', updateVerificationInterval);
    if (deleteInterval) deleteInterval.addEventListener('click', deleteVerificationInterval);
    if (employeeSelect) employeeSelect.addEventListener('change', updateAssignmentMode);
    if (assignmentDate) assignmentDate.addEventListener('change', updateAssignmentMode);
    if (selectAllItems) selectAllItems.addEventListener('change', () => {
        rows.forEach((row) => { if (!row.assignmentCheckbox.disabled) row.assignmentCheckbox.checked = selectAllItems.checked; });
        updateSelectAllState();
        saveDraft();
    });
    if (saveAssignments) saveAssignments.addEventListener('click', saveSelectedAssignments);

    window.addEventListener('beforeunload', () => clearTimeout(saveTimer));

    if (config.initialProperty && Object.hasOwn(config.properties, config.initialProperty)) {
        propertySelect.value = config.initialProperty;
    }
    renderRooms(Number(config.initialRoom) || 1);
    if (assignmentDate && !assignmentDate.value) assignmentDate.value = config.today;
    renderChecklist(config.items.map((name) => ({ name, problem: '', status: null })));
    loadChecklist();
})();
