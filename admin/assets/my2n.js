(() => {
    'use strict';

    const config = window.MY2N_PANEL;
    const rows = document.querySelector('#deviceRows');
    const status = document.querySelector('#panelStatus');
    const refreshButton = document.querySelector('#refreshButton');

    const text = (value) => value === null || value === undefined || value === '' ? '—' : String(value);

    const statusPill = (value) => {
        const pill = document.createElement('span');
        pill.className = `pill ${String(value).toLowerCase()}`;
        pill.textContent = text(value);
        return pill;
    };

    const render = (data) => {
        document.querySelector('#siteId').textContent = text(data.siteId);
        document.querySelector('#groupSip').textContent = text(data.ringingGroupSipNumber);
        document.querySelector('#readAt').textContent = new Date(data.readAt).toLocaleString('pt-PT');
        document.querySelector('#modeBadge').textContent = data.dryRun ? 'APENAS CONSULTA' : 'ESCRITA AUTORIZADA';

        rows.replaceChildren();
        data.devices.forEach((device) => {
            const row = document.createElement('tr');
            const values = [device.name, null, device.deviceId, device.memberId, device.sipNumber, null];
            values.forEach((value, index) => {
                const cell = document.createElement('td');
                if (index === 1) {
                    cell.append(statusPill(device.status));
                } else if (index === 5) {
                    cell.append(statusPill(device.inCurrentGroup ? 'SIM' : 'NÃO'));
                } else {
                    cell.textContent = text(value);
                }
                row.append(cell);
            });
            rows.append(row);
        });
        status.textContent = `${data.devices.length} aparelho(s) encontrado(s).`;
        status.dataset.kind = 'success';
    };

    const load = async () => {
        refreshButton.disabled = true;
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
            status.textContent = error.message;
            status.dataset.kind = 'error';
        } finally {
            refreshButton.disabled = false;
        }
    };

    refreshButton.addEventListener('click', load);
    load();
})();
