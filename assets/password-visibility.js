(() => {
    'use strict';
    const english = (document.documentElement.lang || '').toLowerCase().startsWith('en');
    const show = english ? 'Show password' : 'Mostrar palavra-passe';
    const hide = english ? 'Hide password' : 'Ocultar palavra-passe';
    const icon = '<svg aria-hidden="true" viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg>';
    function enhance(root) {
        root.querySelectorAll('input[type="password"]:not([data-password-visibility])').forEach(input => {
            input.dataset.passwordVisibility = '1';
            const wrapper = document.createElement('span');
            wrapper.className = 'password-visibility';
            input.parentNode.insertBefore(wrapper, input);
            wrapper.appendChild(input);
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'password-visibility-toggle';
            button.setAttribute('aria-label', show);
            button.setAttribute('aria-pressed', 'false');
            button.innerHTML = icon;
            wrapper.appendChild(button);
            button.addEventListener('click', event => {
                event.preventDefault();
                const visible = input.type === 'password';
                input.type = visible ? 'text' : 'password';
                button.setAttribute('aria-label', visible ? hide : show);
                button.setAttribute('aria-pressed', visible ? 'true' : 'false');
                input.focus({ preventScroll: true });
            });
        });
    }
    enhance(document);
    new MutationObserver(records => {
        for (const record of records) for (const node of record.addedNodes) {
            if (node.nodeType === 1 && (node.matches?.('input[type="password"]') || node.querySelector?.('input[type="password"]'))) {
                enhance(document); return;
            }
        }
    }).observe(document.body, { childList: true, subtree: true });
})();
