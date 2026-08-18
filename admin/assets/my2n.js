(() => {
    'use strict';

    const config = window.MY2N_PANEL;
    const rows = document.querySelector('#deviceRows');
    const status = document.querySelector('#panelStatus');
    const refreshButton = document.querySelector('#refreshButton');
    const destinationForm = document.querySelector('#destinationForm');
    const saveMembersButton = document.querySelector('#saveMembersButton');
    const selectionSummary = document.querySelector('#selectionSummary');
    const currentAssignments = new Map();
    const modeActionStatus = document.querySelector('#modeActionStatus');

    const text = (value) => value === null || value === undefined || value === '' ? '—' : String(value);
    const normalizedIds = (values) => [...new Set(values.map(Number))].sort((a, b) => a - b);
    const sameIds = (left, right) => JSON.stringify(normalizedIds(left)) === JSON.stringify(normalizedIds(right));

    const statusPill = (value) => {
        const pill = document.createElement('span');
        pill.className = `pill ${String(value).toLowerCase()}`;
        pill.textContent = text(value);
        return pill;
    };

    const availability = (device) => {
        const value = String(device.availability || 'UNKNOWN').toUpperCase();
        const labels = {
            ONLINE: 'ONLINE',
            NEVER_REGISTERED: 'NUNCA REGISTADO',
            NOT_REGISTERED: 'NÃO REGISTADO',
            DISABLED: 'DESATIVADO',
            UNLICENSED: 'SEM LICENÇA',
            OFFLINE: 'OFFLINE',
            UNKNOWN: 'DESCONHECIDO',
        };
        const wrap = document.createElement('div');
        wrap.className = 'device-state';
        const pill = statusPill(labels[value] || labels.UNKNOWN);
        pill.classList.add(`availability-${value.toLowerCase()}`);
        wrap.append(pill);
        const sipDetail = document.createElement('small');
        sipDetail.textContent = `SIP: ${text(device.status)}`;
        const pushDetail = document.createElement('small');
        pushDetail.textContent = device.pushConfigured === true
            ? 'Push: configurado'
            : 'Push: não configurado';
        wrap.append(sipDetail, pushDetail);
        return wrap;
    };

    const identityCell = (primary, secondary) => {
        const wrap = document.createElement('div');
        wrap.className = 'identity-cell';
        const strong = document.createElement('strong');
        strong.textContent = text(primary);
        const small = document.createElement('small');
        small.textContent = secondary;
        wrap.append(strong, small);
        return wrap;
    };

    const selectedForBell = (bellKey) => normalizedIds(
        [...rows.querySelectorAll(`input[data-bell-key="${CSS.escape(bellKey)}"]:checked`)]
            .map((input) => input.dataset.memberId)
    );

    const changedAssignments = () => [...currentAssignments.entries()]
        .filter(([bellKey, memberIds]) => !sameIds(selectedForBell(bellKey), memberIds))
        .map(([bellKey, memberIds]) => ({
            bellKey,
            memberIds: selectedForBell(bellKey),
            expectedMemberIds: memberIds,
        }));

    const updateSelectionSummary = () => {
        const selectedRoutes = [...rows.querySelectorAll('input[data-member-id]:checked')].length;
        const changes = changedAssignments();
        selectionSummary.textContent = `${selectedRoutes} associação(ões) campainha–telemóvel selecionada(s)${changes.length > 0 ? ` — ${changes.length} campainha(s) com alterações por guardar` : ''}.`;
        if (saveMembersButton) {
            const hasEmptyChangedGroup = changes.some((assignment) => assignment.memberIds.length === 0);
            saveMembersButton.disabled = !config.canControl
                || !config.writesEnabled
                || changes.length === 0
                || hasEmptyChangedGroup;
        }
    };

    const appendCell = (row, content, className = '') => {
        const cell = document.createElement('td');
        if (className) cell.className = className;
        if (content instanceof Node) {
            cell.append(content);
        } else {
            cell.textContent = text(content);
        }
        row.append(cell);
    };

    const render = (data) => {
        const bells = Array.isArray(data.bells) ? data.bells : [];
        const mobiles = Array.isArray(data.mobiles) ? data.mobiles : [];
        document.querySelector('#siteId').textContent = text(data.siteId);
        document.querySelector('#bellCount').textContent = String(bells.length);
        document.querySelector('#readAt').textContent = new Date(data.readAt).toLocaleString('pt-PT');
        document.querySelector('#modeBadge').textContent = data.dryRun ? 'ALTERAÇÕES BLOQUEADAS' : 'ALTERAÇÕES AUTORIZADAS';

        currentAssignments.clear();
        bells.forEach((bell) => currentAssignments.set(
            String(bell.bellKey),
            normalizedIds(bell.currentMemberIds || [])
        ));

        rows.replaceChildren();
        bells.forEach((bell) => {
            mobiles.forEach((mobile, mobileIndex) => {
                const row = document.createElement('tr');
                if (mobileIndex === 0) row.classList.add('bell-row-start');

                appendCell(row, identityCell(
                    bell.bellName,
                    `Device ${text(bell.intercomDeviceId)} · Grupo SIP ${text(bell.ringingGroupSipNumber)}`
                ), 'bell-cell');
                appendCell(row, bell.apartmentName);
                appendCell(row, identityCell(
                    mobile.name,
                    `Device ${text(mobile.deviceId)} · Member ${text(mobile.memberId)}`
                ));
                appendCell(row, mobile.apartmentName);
                appendCell(row, availability(mobile));
                appendCell(row, mobile.sipNumber);

                const label = document.createElement('label');
                label.className = 'member-toggle';
                const checkbox = document.createElement('input');
                checkbox.type = 'checkbox';
                checkbox.dataset.bellKey = String(bell.bellKey);
                checkbox.dataset.memberId = String(mobile.memberId);
                checkbox.checked = (bell.currentMemberIds || []).map(Number).includes(Number(mobile.memberId));
                checkbox.disabled = !config.canControl;
                checkbox.setAttribute('aria-label', `${text(mobile.name)} atende ${text(bell.bellName)}`);
                const labelText = document.createElement('span');
                labelText.className = 'member-toggle-text';
                labelText.textContent = checkbox.checked ? 'Atende' : 'Não atende';
                checkbox.addEventListener('change', () => {
                    labelText.textContent = checkbox.checked ? 'Atende' : 'Não atende';
                    updateSelectionSummary();
                });
                label.append(checkbox, labelText);
                appendCell(row, label);
                rows.append(row);
            });
        });

        if (bells.length === 0 || mobiles.length === 0) {
            const emptyRow = document.createElement('tr');
            const emptyCell = document.createElement('td');
            emptyCell.colSpan = 7;
            emptyCell.className = 'empty-cell';
            emptyCell.textContent = bells.length === 0
                ? 'Não foi encontrada nenhuma campainha com destination group neste site.'
                : 'Não foi encontrado nenhum telemóvel MOBILE_VIDEO neste site.';
            emptyRow.append(emptyCell);
            rows.append(emptyRow);
        }

        const unresolved = bells.reduce(
            (total, bell) => total + (Array.isArray(bell.unresolvedMemberIds) ? bell.unresolvedMemberIds.length : 0),
            0
        );
        const activeRoutes = bells.reduce(
            (total, bell) => total + (Array.isArray(bell.currentMemberIds) ? bell.currentMemberIds.length : 0),
            0
        );
        status.textContent = unresolved > 0
            ? `${bells.length} campainha(s), ${mobiles.length} telemóvel(is); ${unresolved} membro(s) ainda sem associação.`
            : `${bells.length} campainha(s), ${mobiles.length} telemóvel(is); ${activeRoutes} associação(ões) ativa(s).`;
        status.dataset.kind = unresolved > 0 ? 'warning' : 'success';
        updateSelectionSummary();
    };

    const load = async () => {
        refreshButton.disabled = true;
        if (saveMembersButton) saveMembersButton.disabled = true;
        status.textContent = 'A consultar campainhas, apartamentos e telemóveis na My2N…';
        status.dataset.kind = '';
        try {
            const response = await fetch(config.statusUrl, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
                cache: 'no-store',
            });
            const result = await response.json();
            if (!response.ok || !result.ok) {
                throw new Error(result.error || 'Não foi possível consultar My2N.');
            }
            render(result.data);
        } catch (error) {
            rows.replaceChildren();
            currentAssignments.clear();
            selectionSummary.textContent = 'Não foi possível carregar as associações.';
            status.textContent = error.message;
            status.dataset.kind = 'error';
        } finally {
            refreshButton.disabled = false;
        }
    };

    destinationForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (!saveMembersButton) return;
        const assignments = changedAssignments();
        if (assignments.length === 0) return;
        if (assignments.some((assignment) => assignment.memberIds.length === 0)) {
            status.textContent = 'Cada campainha deve manter pelo menos um telemóvel destinatário.';
            status.dataset.kind = 'error';
            return;
        }
        if (!window.confirm(`Guardar alterações em ${assignments.length} campainha(s)?`)) {
            return;
        }

        saveMembersButton.disabled = true;
        refreshButton.disabled = true;
        status.textContent = 'A guardar e confirmar as associações na My2N…';
        status.dataset.kind = '';
        let latestData = null;
        try {
            for (const assignment of assignments) {
                const response = await fetch(config.membersUrl, {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': config.csrfToken,
                    },
                    credentials: 'same-origin',
                    cache: 'no-store',
                    body: JSON.stringify(assignment),
                });
                const result = await response.json();
                if (!response.ok || !result.ok) {
                    throw new Error(result.error || 'Não foi possível guardar os destinatários.');
                }
                latestData = result.data;
            }
            if (latestData) render(latestData);
            status.textContent = 'Associações atualizadas e confirmadas na My2N.';
            status.dataset.kind = 'success';
        } catch (error) {
            await load();
            status.textContent = `${error.message} A lista foi atualizada para mostrar o estado confirmado.`;
            status.dataset.kind = 'error';
        } finally {
            refreshButton.disabled = false;
            updateSelectionSummary();
        }
    });

    refreshButton.addEventListener('click', load);
    const runOperationalAction = async (url, payload, confirmation) => {
        if (!window.confirm(confirmation)) return;
        modeActionStatus.textContent = 'A executar e confirmar todas as campainhas…';
        modeActionStatus.dataset.kind = '';
        try {
            const response = await fetch(url, {
                method: 'POST',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-Token': config.csrfToken },
                credentials: 'same-origin', cache: 'no-store', body: JSON.stringify(payload),
            });
            const result = await response.json();
            if (!response.ok || !result.ok) throw new Error(result.error || 'Operação My2N falhou.');
            modeActionStatus.textContent = `Operação confirmada. Snapshot de retorno: ${text(result.data.snapshotId)}.`;
            modeActionStatus.dataset.kind = 'success';
            await load();
        } catch (error) {
            modeActionStatus.textContent = `${error.message} Consulte a auditoria antes de repetir.`;
            modeActionStatus.dataset.kind = 'error';
        }
    };
    document.querySelectorAll('.mode-action').forEach((button) => button.addEventListener('click', () => {
        runOperationalAction(config.modeUrl, { modeKey: button.dataset.modeKey }, `Ativar o modo em todas as campainhas configuradas?`);
    }));
    const rollbackButton = document.querySelector('#rollbackButton');
    if (rollbackButton) rollbackButton.addEventListener('click', () => {
        const snapshotId = Number(document.querySelector('#rollbackSnapshotId').value);
        if (!Number.isInteger(snapshotId) || snapshotId < 1) {
            modeActionStatus.textContent = 'Indique um snapshot válido.';
            modeActionStatus.dataset.kind = 'error';
            return;
        }
        runOperationalAction(config.rollbackUrl, { snapshotId }, `Repor todas as campainhas do snapshot ${snapshotId}?`);
    });
    load();
})();
