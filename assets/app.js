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

    const selection = () => ({
        property: propertySelect.value,
        room: Number(roomSelect.value),
    });

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
            if (canEdit) scheduleSave();
        });
        textarea.dataset.problem = item.problem || '';

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
            assignmentCheckbox.addEventListener('change', updateSelectAllState);
            const marker = document.createElement('span');
            assignmentLabel.append(assignmentCheckbox, marker);
            row.append(name, textarea, status, assignmentLabel);
        } else {
            row.append(name, textarea, status);
        }

        return { element: row, name: item.name, textarea, status, assignmentCheckbox };
    };

    function updateSelectAllState() {
        if (!selectAllItems) return;
        const checkboxes = rows.map((row) => row.assignmentCheckbox).filter(Boolean);
        const checked = checkboxes.filter((checkbox) => checkbox.checked).length;
        selectAllItems.checked = checkboxes.length > 0 && checked === checkboxes.length;
        selectAllItems.indeterminate = checked > 0 && checked < checkboxes.length;
    }

    const updateAssignmentMode = () => {
        if (!canAssign) return;
        const intervalId = Number(intervalSelect.value);
        const interval = config.intervals.find((candidate) => candidate.id === intervalId);
        const employeeId = Number(employeeSelect.value);
        const dueDate = assignmentDate ? assignmentDate.value : '';
        if (interval && assignmentDate) {
            assignmentDate.min = interval.startDate;
            assignmentDate.max = interval.endDate;
            if (dueDate < interval.startDate || dueDate > interval.endDate) assignmentDate.value = interval.startDate;
        }
        const selectedDate = assignmentDate ? assignmentDate.value : '';
        const active = Boolean(interval) && employeeId > 0 && selectedDate !== '';
        rows.forEach((row) => {
            row.element.classList.toggle('assignment-mode', active);
            row.assignmentCheckbox.disabled = !active;
            const assignment = assignments[row.name] || {};
            row.assignmentCheckbox.checked = active
                && Number(assignment.employeeId || 0) === employeeId
                && assignment.dueDate === selectedDate;
            row.textarea.value = active ? '' : row.textarea.dataset.problem;
            row.textarea.placeholder = active ? 'A verificar' : 'Descreva o problema…';
            row.textarea.readOnly = active || !canEdit;
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
            renderChecklist(result.items);
            if (employeeSelect) employeeSelect.value = '';
            updateAssignmentMode();
            setStatus(canEdit ? 'Dados carregados' : 'Apenas consulta', 'success');
        } catch (error) {
            if (version === requestVersion) {
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
            .filter((row) => row.assignmentCheckbox && row.assignmentCheckbox.checked)
            .map((row) => row.name);
        saveAssignments.disabled = true;
        setStatus('A guardar atribuição…');
        try {
            const response = await fetch('api.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                body: JSON.stringify({
                    action: 'assign_items', csrfToken: config.csrfToken, ...current,
                    intervalId, employeeId, dueDate, selectedItems,
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
            selectedItems.forEach((name) => { assignments[name] = { employeeId, dueDate }; });
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
            option.textContent = `${result.interval.name} — ${result.interval.startDate} a ${result.interval.endDate}`;
            intervalSelect.append(option);
            intervalSelect.value = String(result.interval.id);
            assignmentDate.value = result.interval.startDate;
            intervalName.value = '';
            intervalStart.value = '';
            intervalEnd.value = '';
            await loadChecklist();
            setStatus('Intervalo criado', 'success');
        } catch (error) {
            setStatus(error.message, 'error');
        } finally {
            createInterval.disabled = false;
        }
    };

    propertySelect.addEventListener('change', () => {
        renderRooms(1);
        loadChecklist();
    });
    roomSelect.addEventListener('change', loadChecklist);
    if (intervalSelect) intervalSelect.addEventListener('change', async () => {
        employeeSelect.value = '';
        await loadChecklist();
    });
    if (createInterval) createInterval.addEventListener('click', createVerificationInterval);
    if (employeeSelect) employeeSelect.addEventListener('change', updateAssignmentMode);
    if (assignmentDate) assignmentDate.addEventListener('change', updateAssignmentMode);
    if (selectAllItems) selectAllItems.addEventListener('change', () => {
        rows.forEach((row) => { if (!row.assignmentCheckbox.disabled) row.assignmentCheckbox.checked = selectAllItems.checked; });
        updateSelectAllState();
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
