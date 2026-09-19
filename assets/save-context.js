(() => {
    'use strict';
    // Progressive enhancement: native POST, CSRF, validation and existing async
    // saves keep their contracts. Only presentation state crosses a reload.
    const script = document.currentScript;
    const key = 'room-check:save-context:v1';
    const user = script?.dataset.userId || '';
    const lifetime = 5 * 60 * 1000;
    let pending = null;

    const discard = () => { try { sessionStorage.removeItem(key); } catch (_) {} };
    const pageKey = (address) => {
        const url = new URL(address, location.href);
        url.hash = '';
        // The invoice controller makes default filters explicit after a POST.
        if (url.pathname.endsWith('/invoices.php')) {
            for (const [name, value] of Object.entries({ edit: '0', account: '0', portal: '', property: '', q: '', state: '', tab: 'overview' })) {
                if (url.searchParams.get(name) === value) url.searchParams.delete(name);
            }
        }
        url.searchParams.sort();
        return url.href;
    };
    const selectorFor = (element) => {
        if (!(element instanceof Element)) return '';
        if (element.id) return '#' + CSS.escape(element.id);
        const parts = [];
        while (element && element !== document.body) {
            if (element.id) { parts.unshift('#' + CSS.escape(element.id)); break; }
            const siblings = [...(element.parentElement?.children || [])].filter(sibling => sibling.tagName === element.tagName);
            parts.unshift(element.localName + ':nth-of-type(' + (siblings.indexOf(element) + 1) + ')');
            element = element.parentElement;
        }
        return parts.join(' > ');
    };
    const find = (selector, root = document) => {
        try { return selector ? root.querySelector(selector) : null; } catch (_) { return null; }
    };
    const identity = (form) => {
        if (form.id) return 'id:' + form.id;
        if (form.dataset.saveContextKey) return 'key:' + form.dataset.saveContextKey;
        const action = form.querySelector('input[type="hidden"][name="action"]')?.value || '';
        if (!/^[a-z][a-z0-9_]{0,79}$/.test(action)) return 'path:' + selectorFor(form);
        const parts = ['action:' + action];
        // Only numeric record identifiers, never credentials, CSRF or field data.
        for (const name of ['user_id', 'account_id', 'document_id', 'item_id', 'list_id', 'category_id', 'batch_id', 'task_id']) {
            const value = form.querySelector('input[type="hidden"][name="' + name + '"]')?.value;
            if (/^\d+$/.test(value || '')) parts.push(name + ':' + value);
        }
        return parts.join('|');
    };
    const formFor = (state) => [...document.forms].filter(form => identity(form) === state.form)[state.formIndex] || null;
    const openAncestors = (element) => {
        for (let parent = element?.parentElement; parent; parent = parent.parentElement) {
            if (parent instanceof HTMLDetailsElement) parent.open = true;
        }
    };

    // Invalid required fields may be inside a collapsed section. Let native
    // validation focus and describe the first field after opening its ancestors.
    document.addEventListener('invalid', event => openAncestors(event.target), true);

    // Listen after form/document guards. Re-submissions after a confirmation are
    // new events; a cancelled or fetch-handled submit never creates a snapshot.
    window.addEventListener('submit', event => {
        pending = null;
        discard();
        const form = event.target;
        const button = event.submitter;
        if (!(form instanceof HTMLFormElement) || event.defaultPrevented || form.dataset.saveContext === 'off') return;
        const method = (button?.getAttribute('formmethod') || form.method).toLowerCase();
        const target = button?.getAttribute('formtarget') || form.target;
        const action = new URL(button?.getAttribute('formaction') || form.getAttribute('action') || location.href, location.href);
        if (method !== 'post' || (target && target !== '_self') || action.origin !== location.origin || action.pathname !== location.pathname) return;
        const destination = new URL(form.dataset.saveContextReturn || location.href, location.href);
        if (destination.origin !== location.origin || destination.pathname !== location.pathname) return;
        const anchor = button || form;
        const formId = identity(form);
        pending = {
            event,
            state: {
                user, at: Date.now(), from: pageKey(location.href), to: pageKey(destination.href),
                x: window.scrollX, y: window.scrollY,
                form: formId, formIndex: [...document.forms].filter(item => identity(item) === formId).indexOf(form),
                anchor: selectorFor(anchor), anchorTop: anchor.getBoundingClientRect().top,
                formTop: form.getBoundingClientRect().top,
                details: [...document.querySelectorAll('details')].map(element => [selectorFor(element), element.open]),
            },
        };
    });
    document.addEventListener('click', event => {
        if (event.target.closest?.('a[href]')) { pending = null; discard(); }
    }, true);
    // Persist only when leaving for an uncancelled native submission. This avoids
    // stale snapshots from cancelled dialogs, native validation and async saves.
    window.addEventListener('pagehide', () => {
        if (!pending || pending.event.defaultPrevented) return;
        try { sessionStorage.setItem(key, JSON.stringify(pending.state)); } catch (_) {}
        pending = null;
    });

    let state;
    try { state = JSON.parse(sessionStorage.getItem(key) || 'null'); } catch (_) {}
    discard(); // Single use, also when the destination is another module/tab.
    const navigation = performance.getEntriesByType('navigation')[0]?.type;
    if (!state || state.user !== user || !Number.isFinite(state.at) || Date.now() - state.at > lifetime || state.at > Date.now()
        || ![state.from, state.to].includes(pageKey(location.href)) || navigation === 'back_forward' || navigation === 'reload'
        || !Number.isFinite(state.x) || !Number.isFinite(state.y) || !Array.isArray(state.details)) return;

    for (const entry of state.details) {
        if (!Array.isArray(entry) || entry.length !== 2) continue;
        const [selector, open] = entry;
        const element = find(selector);
        if (element instanceof HTMLDetailsElement) element.open = Boolean(open);
    }
    const form = formFor(state);
    const error = document.querySelector('[data-save-feedback="error"]');
    const feedback = error || document.querySelector('[data-save-feedback="success"]');
    let interrupted = false;
    const previousRestoration = history.scrollRestoration;
    history.scrollRestoration = 'manual';
    const stop = () => { interrupted = true; history.scrollRestoration = previousRestoration; };
    for (const name of ['pointerdown', 'touchstart', 'wheel', 'keydown']) {
        window.addEventListener(name, stop, { once: true, passive: true });
    }
    const restore = () => {
        if (interrupted) return;
        // Some modules already present validation failures in a modal dialog.
        if (document.querySelector('[role="alertdialog"], dialog[open]')) { stop(); return; }
        const invalid = error && (form || document).querySelector('[aria-invalid="true"]');
        if (invalid) {
            openAncestors(invalid);
            if (invalid.getClientRects().length) {
                invalid.focus({ preventScroll: true });
                invalid.scrollIntoView({ block: 'nearest', behavior: 'instant' });
                stop();
                return;
            }
        }
        let anchor = find(state.anchor);
        // Do not anchor to a different record if the DOM changed during saving.
        if (anchor !== form && anchor?.form !== form && !form?.contains(anchor)) anchor = null;
        const offset = anchor ? state.anchorTop : state.formTop;
        anchor = anchor || form;
        const top = anchor?.getClientRects().length && Number.isFinite(offset)
            ? window.scrollY + anchor.getBoundingClientRect().top - Math.min(offset, Math.max(0, window.innerHeight - 80))
            : state.y;
        window.scrollTo({ left: state.x, top: Math.max(0, top), behavior: 'instant' });
    };
    requestAnimationFrame(restore);
    // One final correction for late fonts/styles. Never drag the user back after
    // a deliberate scroll, and never leave history restoration changed globally.
    const loaded = document.readyState === 'complete' ? Promise.resolve()
        : new Promise(resolve => window.addEventListener('load', resolve, { once: true }));
    Promise.race([
        Promise.all([loaded, document.fonts?.ready || Promise.resolve()]),
        new Promise(resolve => setTimeout(resolve, 1500)),
    ]).then(() => requestAnimationFrame(() => { restore(); stop(); }));

    // Use the real server result, including queued/partial outcomes. Never infer
    // "saved" from a reload or from an HTTP 200 response.
    if (feedback?.textContent.trim()) {
        const notice = document.createElement('div');
        notice.className = 'save-context-notice' + (error ? ' is-error' : '');
        notice.setAttribute('role', error ? 'alert' : 'status');
        notice.setAttribute('data-i18n-skip', '');
        const message = document.createElement('span');
        message.textContent = feedback.textContent.trim();
        const close = document.createElement('button');
        close.type = 'button';
        close.textContent = '×';
        close.setAttribute('aria-label', script?.dataset.dismissLabel || 'Close');
        close.addEventListener('click', () => notice.remove());
        notice.append(message, close);
        document.body.append(notice);
        if (!error) setTimeout(() => notice.remove(), 6000);
    }
})();
