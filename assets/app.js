(() => {
    'use strict';

    const config = window.ROOM_CHECK;
    const propertySelect = document.querySelector('#propertySelect');
    const roomSelect = document.querySelector('#roomSelect');
    const checklist = document.querySelector('#checklist');
    const saveStatus = document.querySelector('#saveStatus');
    const canEdit = config.canEdit !== false;

    let rows = [];
    let saveTimer = null;
    let requestVersion = 0;
    let isLoading = false;

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

        const status = document.createElement('div');
        status.className = 'status-options';
        status.setAttribute('role', 'group');
        status.setAttribute('aria-label', `Estado: ${item.name}`);

        const buttons = [
            ['wrong', 'Wrong', 'wrong'],
            ['ok', 'Ok', 'ok'],
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
        row.append(name, textarea, status);

        return { element: row, name: item.name, textarea, status };
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

            renderChecklist(result.items);
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

    propertySelect.addEventListener('change', () => {
        renderRooms(1);
        loadChecklist();
    });
    roomSelect.addEventListener('change', loadChecklist);

    window.addEventListener('beforeunload', () => clearTimeout(saveTimer));

    renderRooms(1);
    renderChecklist(config.items.map((name) => ({ name, problem: '', status: null })));
    loadChecklist();
})();
