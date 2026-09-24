(() => {
    'use strict';
    const config = window.VERIFICATION_CATEGORIES || {};
    const bypass = new WeakSet();

    const interpolate = (template, values) => Object.entries(values).reduce(
        (message, [key, value]) => message.replaceAll(`{${key}}`, String(value)),
        String(template || '')
    );

    const showServerError = () => {
        if (!config.serverError || !window.AppDialog?.alert) return;
        window.AppDialog.alert(String(config.serverError), { closeLabel: config.closeLabel || 'Fechar', variant: 'danger' });
    };

    const countLabel = (count, singular, plural) => `${count} ${count === 1 ? singular : plural}`;

    document.querySelectorAll('form[data-category-delete]').forEach((form) => {
        form.addEventListener('submit', async (event) => {
            if (bypass.has(form)) return;
            event.preventDefault();

            const name = form.dataset.categoryName || '';
            const listCount = Number(form.dataset.listCount || 0);
            const itemCount = Number(form.dataset.itemCount || 0);
            const assignmentCount = Number(form.dataset.assignmentCount || 0);

            if (assignmentCount > 0) {
                await window.AppDialog.alert(interpolate(config.blockedMessage, { name, assignments: assignmentCount }), {
                    closeLabel: config.closeLabel || 'Fechar',
                    variant: 'danger',
                });
                return;
            }

            const message = listCount > 0 || itemCount > 0
                ? interpolate(config.contentMessage, {
                    name,
                    lists: countLabel(listCount, config.listSingular || 'lista', config.listPlural || 'listas'),
                    items: countLabel(itemCount, config.itemSingular || 'item', config.itemPlural || 'itens'),
                })
                : interpolate(config.emptyMessage, { name });
            const confirmed = await window.AppDialog.confirm(message, {
                confirmLabel: config.deleteLabel || 'Continuar e apagar',
                cancelLabel: config.cancelLabel || 'Cancelar',
                destructive: true,
            });
            if (!confirmed) return;

            bypass.add(form);
            form.requestSubmit();
            queueMicrotask(() => bypass.delete(form));
        });
    });

    showServerError();
})();
