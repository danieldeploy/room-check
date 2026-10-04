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
