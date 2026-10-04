(() => {
    'use strict';
    document.querySelectorAll('[data-account-details]').forEach(form => {
        const list = form.querySelector('[data-property-list]');
        const template = form.querySelector('[data-property-template]');
        const portal = form.querySelector('[data-account-portal]');
        const add = form.querySelector('[data-add-property]');
        const sync = () => {
            const scoped = portal ? ['airbnb', 'email'].includes(portal.value) : form.dataset.accountScope === '1';
            form.querySelector('[data-account-scope-note]').hidden = !scoped;
            list.querySelectorAll('[data-property-id-field]').forEach(field => {
                field.hidden = scoped;
                field.querySelector('input').required = !scoped;
            });
            const rows = list.querySelectorAll('[data-property-row]');
            rows.forEach(row => { row.querySelector('[data-remove-property]').disabled = rows.length === 1; });
            add.disabled = rows.length >= 50;
        };
        portal?.addEventListener('change', () => {
            // New accounts have no persistent portal identifiers to carry across platforms.
            list.querySelectorAll('[name="property_ids[]"]').forEach(input => { input.value = ''; });
            sync();
        });
        add.addEventListener('click', () => {
            if (list.querySelectorAll('[data-property-row]').length >= 50) return;
            const row = template.content.cloneNode(true);
            list.appendChild(row); sync();
            list.lastElementChild.querySelector('[name="property_labels[]"]').focus();
        });
        list.addEventListener('click', event => {
            const button = event.target.closest('[data-remove-property]');
            if (button && list.querySelectorAll('[data-property-row]').length > 1) { button.closest('[data-property-row]').remove(); sync(); }
        });
        sync();
    });
    document.querySelectorAll('[data-auth-form]').forEach(form => {
        const method = form.querySelector('[data-auth-method]');
        if (!method) return;
        const sync = () => form.querySelectorAll('[data-auth-for]').forEach(fieldset => {
            fieldset.hidden = fieldset.dataset.authFor !== method.value;
            fieldset.disabled = fieldset.hidden;
        });
        method.addEventListener('change', sync); sync();
    });
    document.querySelectorAll('[data-booking-login-live]').forEach(widget => {
        const summary = widget.querySelector('[data-login-summary]');
        const title = widget.querySelector('[data-login-title]');
        const detail = widget.querySelector('[data-login-detail]');
        const next = widget.querySelector('[data-login-next]');
        const updated = widget.querySelector('[data-login-updated]');
        const refresh = widget.querySelector('[data-login-refresh]');
        let timer = null, inFlight = false;
        const phaseNames = ['authenticated', 'captcha', 'sms_waiting', 'sms_submitted',
            'sms_timeout', 'sms_rejected', 'credentials', 'credentials_rejected', 'timeout', 'blocked', 'unknown'];
        const apply = data => {
            if (!data || typeof data.title !== 'string' || typeof data.detail !== 'string' || typeof data.next !== 'string') return;
            title.textContent = data.title;
            detail.textContent = data.detail;
            next.textContent = data.next;
            const phase = phaseNames.includes(data.phase) ? data.phase : 'unknown';
            summary.className = 'invoice-login-summary invoice-login-summary--' + phase;
            if (Number.isInteger(data.updated_at) && data.updated_at > 0) {
                const date = new Date(data.updated_at * 1000);
                updated.dateTime = date.toISOString();
                updated.textContent = new Intl.DateTimeFormat(document.documentElement.lang || 'pt-PT',
                    { dateStyle: 'short', timeStyle: 'short' }).format(date);
            } else {
                updated.removeAttribute('datetime');
                updated.textContent = data.available ? '' : widget.dataset.noDiagnostic;
            }
        };
        const schedule = () => {
            clearTimeout(timer);
            if (!document.hidden) timer = setTimeout(poll, 5000);
        };
        const poll = async () => {
            if (inFlight || document.hidden) return;
            inFlight = true;
            try {
                const response = await fetch(widget.dataset.statusUrl, {
                    method: 'GET', credentials: 'same-origin', cache: 'no-store',
                    headers: { Accept: 'application/json' },
                });
                if (!response.ok) throw new Error('status_unavailable');
                apply(await response.json());
                refresh.textContent = widget.dataset.refreshReady;
            } catch {
                refresh.textContent = widget.dataset.refreshError;
            } finally {
                inFlight = false;
                schedule();
            }
        };
        document.addEventListener('visibilitychange', () => {
            if (!document.hidden) { clearTimeout(timer); poll(); }
            else clearTimeout(timer);
        });
        poll();
    });
    document.querySelectorAll('[data-invoice-filters]').forEach(form => {
        const portal = form.querySelector('[data-filter-portal]');
        const account = form.querySelector('[data-filter-account]');
        const property = form.querySelector('[data-filter-property]');
        const sync = () => {
            [...account.options].forEach(option => { option.hidden = option.value !== '0' && portal.value !== '' && option.dataset.portal !== portal.value; });
            if (account.selectedOptions[0]?.hidden) account.value = '0';
            [...property.options].forEach(option => {
                option.hidden = option.value !== '' && ((portal.value !== '' && option.dataset.portal !== portal.value)
                    || (account.value !== '0' && option.dataset.account !== account.value));
            });
            if (property.selectedOptions[0]?.hidden) property.value = '';
        };
        portal.addEventListener('change', sync); account.addEventListener('change', sync); sync();
    });
})();
