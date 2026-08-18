(() => {
    'use strict';

    const config = window.MY2N_PANEL;
    const rows = document.querySelector('#deviceRows');
    const status = document.querySelector('#panelStatus');
    const refreshButton = document.querySelector('#refreshButton');
    const destinationForm = document.querySelector('#destinationForm');
    const saveMembersButton = document.querySelector('#saveMembersButton');
    const selectionSummary = document.querySelector('#selectionSummary');
    let currentMemberIds = [];

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
        const value = String(device.availability || 'OFFLINE').toUpperCase();
        const labels = {
            ONLINE: 'ONLINE',
            BACKGROUND_READY: 'PUSH ATIVO',
            OFFLINE: 'OFFLINE',
        };
        const wrap = document.createElement('div');
        wrap.className = 'device-state';
        wrap.append(statusPill(labels[value] || labels.OFFLINE));
        const detail = document.createElement('small');
        detail.textContent = `SIP: ${text(device.status)}`;
        wrap.append(detail);
        return wrap;
    };

    const selectedMemberIds = () => normalizedIds(
        [...rows.querySelectorAll('input[name="memberIds"]:checked')].map((input) => input.value)
    );

    const updateSelectionSummary = () => {
        const selected = selectedMemberIds();
        const changed = !sameIds(selected, currentMemberIds);
        selectionSummary.textContent = `${selected.length} telemóvel(is) selecionado(s) para receber chamadas${changed ? ' — alteração por guardar' : ''}.`;
        if (saveMembersButton) {
            saveMembersButton.disabled = !config.canControl || !config.writesEnabled || !changed || selected.length === 0;
        }
    };

    const render = (data) => {
        document.querySelector('#siteId').textContent = text(data.siteId);
        document.querySelector('#groupSip').textContent = text(data.ringingGroupSipNumber);
        document.querySelector('#readAt').textContent = new Date(data.readAt).toLocaleString('pt-PT');
        document.querySelector('#modeBadge').textContent = data.dryRun ? 'ALTERAÇÕES BLOQUEADAS' : 'ALTERAÇÕES AUTORIZADAS';
        currentMemberIds = normalizedIds(data.currentMemberIds || []);
        const unresolvedMemberIds = normalizedIds(data.unresolvedMemberIds || []);

        rows.replaceChildren();
        data.devices.forEach((device) => {
            const row = document.createElement('tr');
            [device.name, device.apartmentName, null, device.deviceId, device.memberId, device.sipNumber, null].forEach((value, index) => {
                const cell = document.createElement('td');
                if (index === 2) {
                    cell.append(availability(device));
                } else if (index === 6) {
                    const label = document.createElement('label');
                    label.className = 'member-toggle';
                    const checkbox = document.createElement('input');
                    checkbox.type = 'checkbox';
                    checkbox.name = 'memberIds';
                    checkbox.value = String(device.memberId);
                    checkbox.checked = device.inCurrentGroup === true;
                    checkbox.disabled = !config.canControl;
                    checkbox.setAttribute('aria-label', `${text(device.name)} recebe chamadas`);
                    const labelText = document.createElement('span');
                    labelText.className = 'member-toggle-text';
                    labelText.textContent = checkbox.checked ? 'No grupo' : 'Fora do grupo';
                    checkbox.addEventListener('change', () => {
                        labelText.textContent = checkbox.checked ? 'No grupo' : 'Fora do grupo';
                        updateSelectionSummary();
                    });
                    label.append(checkbox, labelText);
                    cell.append(label);
                } else {
                    cell.textContent = text(value);
                }
                row.append(cell);
            });
            rows.append(row);
        });
        status.textContent = unresolvedMemberIds.length > 0
            ? `${data.devices.length} aparelho(s) encontrado(s); ${unresolvedMemberIds.length} membro(s) do grupo ainda sem associação.`
            : `${data.devices.length} aparelho(s) encontrado(s); ${currentMemberIds.length} no destination group.`;
        status.dataset.kind = unresolvedMemberIds.length > 0 ? 'warning' : 'success';
        updateSelectionSummary();
    };

    const load = async () => {
        refreshButton.disabled = true;
        if (saveMembersButton) saveMembersButton.disabled = true;
        status.textContent = 'A consultar My2N…';
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
            currentMemberIds = [];
            selectionSummary.textContent = 'Não foi possível carregar os destinatários.';
            status.textContent = error.message;
            status.dataset.kind = 'error';
        } finally {
            refreshButton.disabled = false;
        }
    };

    destinationForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (!saveMembersButton) return;
        const memberIds = selectedMemberIds();
        if (memberIds.length === 0) {
            status.textContent = 'Selecione pelo menos um destinatário para a campainha.';
            status.dataset.kind = 'error';
            return;
        }
        if (!window.confirm(`Guardar ${memberIds.length} destinatário(s) no destination group?`)) {
            return;
        }

        saveMembersButton.disabled = true;
        refreshButton.disabled = true;
        status.textContent = 'A guardar e confirmar os destinatários na My2N…';
        status.dataset.kind = '';
        try {
            const response = await fetch(config.membersUrl, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': config.csrfToken,
                },
                credentials: 'same-origin',
                cache: 'no-store',
                body: JSON.stringify({ memberIds, expectedMemberIds: currentMemberIds }),
            });
            const result = await response.json();
            if (!response.ok || !result.ok) {
                throw new Error(result.error || 'Não foi possível guardar os destinatários.');
            }
            render(result.data);
            status.textContent = result.changed
                ? 'Destinatários atualizados e confirmados na My2N.'
                : 'Os destinatários já estavam atualizados.';
            status.dataset.kind = 'success';
        } catch (error) {
            status.textContent = error.message;
            status.dataset.kind = 'error';
            updateSelectionSummary();
        } finally {
            refreshButton.disabled = false;
        }
    });

    refreshButton.addEventListener('click', load);
    load();
})();
