(() => {
    'use strict';
    if (window.AppDialog) return;

    let activeOverlay = null;
    let activeFinish = null;

    const closeActive = () => {
        if (activeFinish) {
            activeFinish(false);
            return;
        }
        activeOverlay?.remove();
        activeOverlay = null;
    };

    const choice = ({ message, actions = [], ariaLabel = '' }) => new Promise((resolve) => {
        closeActive();
        const previousFocus = document.activeElement;

        const overlay = document.createElement('div');
        overlay.className = 'app-dialog-overlay language-decision-overlay';
        const panel = document.createElement('div');
        panel.className = 'app-dialog language-decision-dialog';
        panel.setAttribute('role', 'alertdialog');
        panel.setAttribute('aria-modal', 'true');
        if (ariaLabel) panel.setAttribute('aria-label', String(ariaLabel));

        const copy = document.createElement('p');
        copy.textContent = String(message || '');
        const actionBar = document.createElement('div');
        actionBar.className = 'app-dialog-actions language-decision-actions';

        const finish = (value) => {
            overlay.remove();
            if (activeOverlay === overlay) activeOverlay = null;
            if (activeFinish === finish) activeFinish = null;
            document.removeEventListener('keydown', onKeyDown, true);
            if (previousFocus instanceof HTMLElement && previousFocus.isConnected) previousFocus.focus();
            resolve(value);
        };
        const onKeyDown = (event) => {
            if (event.key !== 'Escape') return;
            event.preventDefault();
            const fallback = actions.find((action) => action.variant === 'secondary')
                ?? actions[actions.length - 1];
            finish(fallback?.value ?? false);
        };

        actions.forEach((action, index) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = `app-dialog-action is-${String(action.variant || (index === 0 ? 'primary' : 'secondary'))}`;
            button.textContent = String(action.label || '');
            button.addEventListener('click', () => finish(action.value));
            actionBar.append(button);
        });

        panel.append(copy, actionBar);
        overlay.append(panel);
        document.body.append(overlay);
        activeOverlay = overlay;
        activeFinish = finish;
        document.addEventListener('keydown', onKeyDown, true);
        actionBar.querySelector('button')?.focus();
    });

    window.AppDialog = {
        choice,
        confirm: (message, options = {}) => choice({
            message,
            ariaLabel: options.ariaLabel || '',
            actions: [
                { label: options.confirmLabel || 'Confirmar', value: true, variant: options.destructive ? 'danger' : 'primary' },
                { label: options.cancelLabel || 'Cancelar', value: false, variant: 'secondary' },
            ],
        }),
        alert: (message, options = {}) => choice({
            message,
            ariaLabel: options.ariaLabel || '',
            actions: [
                { label: options.closeLabel || 'OK', value: true, variant: options.variant || 'primary' },
            ],
        }),
        close: closeActive,
    };

    const confirmedForms = new WeakSet();
    document.addEventListener('submit', async (event) => {
        const form = event.target instanceof HTMLFormElement
            ? event.target.closest('form[data-app-confirm]')
            : null;
        if (!form || confirmedForms.has(form)) return;

        event.preventDefault();
        const confirmed = await window.AppDialog.confirm(form.dataset.appConfirm || '', {
            confirmLabel: form.dataset.appConfirmLabel || 'Confirmar',
            cancelLabel: form.dataset.appCancelLabel || 'Cancelar',
            destructive: form.dataset.appDestructive === '1',
        });
        if (!confirmed) return;

        confirmedForms.add(form);
        form.requestSubmit();
        queueMicrotask(() => confirmedForms.delete(form));
    }, true);
})();
