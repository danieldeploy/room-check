(() => {
    'use strict';
    // Shared page context: native submissions, CSRF, validation and existing
    // async actions keep their contracts. Only presentation state is restored.
    const script = document.currentScript;
    const key = 'room-check:save-context:v2';
    const user = script?.dataset.userId || '';
    const lifetime = 5 * 60 * 1000;
    let pending = null;
    let interaction = 0;
    let disclosure = null;
    for (const name of ['pointerdown', 'touchstart', 'wheel', 'keydown']) {
        window.addEventListener(name, () => { interaction++; }, { passive: true });
    }

    const discard = () => { try { sessionStorage.removeItem(key); } catch (_) {} };
    try { sessionStorage.removeItem('room-check:save-context:v1'); } catch (_) {}
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
        for (const name of ['user_id', 'account_id', 'document_id', 'item_id', 'list_id', 'category_id', 'batch_id', 'task_id', 'assignment_id']) {
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
    const samePage = url => url.origin === location.origin && url.pathname === location.pathname;
    const viewModes = ['keep', 'filter', 'page'];
    const visibleTop = top => Math.min(Math.max(16, top), Math.max(16, window.innerHeight - 80));
    const capture = (event, source, destination, mode, button = null) => {
        const form = source instanceof HTMLFormElement ? source : null;
        const target = find(source.dataset.saveContextTarget);
        const anchor = target || button || form || source;
        const formId = form ? identity(form) : '';
        const top = anchor.getBoundingClientRect().top;
        pending = {
            event,
            state: {
                user, at: Date.now(), from: pageKey(location.href), to: pageKey(destination.href), mode,
                x: window.scrollX, y: window.scrollY,
                form: formId, formIndex: form ? [...document.forms].filter(item => identity(item) === formId).indexOf(form) : 0,
                anchor: selectorFor(anchor), target: source.dataset.saveContextTarget || '',
                anchorTop: mode === 'page' || (mode === 'filter' && (top < 16 || top > window.innerHeight - 80)) ? 16 : top,
                formTop: form?.getBoundingClientRect().top,
                // GET results can replace record lists. Only preserve disclosure
                // state within the filter/selector, never apply old row state to new rows.
                details: [...document.querySelectorAll('details')]
                    .filter(element => mode === 'save' || (form && (element.contains(form) || form.contains(element))))
                    .map(element => [selectorFor(element), element.open]),
            },
        };
    };
    const correctDisclosure = ticket => {
        if (disclosure !== ticket || ticket.interaction !== interaction || ticket.event.defaultPrevented
            || !ticket.summary.isConnected || document.querySelector('[role="alertdialog"], dialog[open]')) return;
        const top = ticket.summary.getBoundingClientRect().top;
        window.scrollTo({ left: window.scrollX, top: Math.max(0, window.scrollY + top - visibleTop(ticket.top)), behavior: 'instant' });
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
        if ((target && target !== '_self') || !samePage(action)) return;
        if (method === 'get') {
            // Explicitly mark filters/selectors, so GET navigation and credential
            // forms cannot accidentally become presentation snapshots.
            if (!viewModes.includes(form.dataset.saveContext) || form.querySelector('input[type="password"], input[type="file"]')) return;
            const parameters = new URLSearchParams(new FormData(form));
            if (button?.name && !button.disabled) parameters.append(button.name, button.value);
            action.search = parameters.toString();
            action.hash = '';
            capture(event, form, action, form.dataset.saveContext, button);
        } else if (method === 'post') {
            const destination = new URL(form.dataset.saveContextReturn || location.href, location.href);
            if (samePage(destination)) capture(event, form, destination, 'save', button);
        }
    });
    document.addEventListener('click', event => {
        if (event.target.closest?.('a[href]')) { pending = null; discard(); }
    }, true);
    window.addEventListener('click', event => {
        const link = event.target.closest?.('a[href]');
        if (link) {
            if (event.defaultPrevented || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey
                || link.hasAttribute('download') || (link.target && link.target !== '_self') || !viewModes.includes(link.dataset.saveContext)) return;
            const destination = new URL(link.href, location.href);
            if (samePage(destination) && !destination.hash) capture(event, link, destination, link.dataset.saveContext);
            return;
        }
        const summary = event.target.closest?.('summary');
        if (!summary || !(summary.parentElement instanceof HTMLDetailsElement) || event.defaultPrevented
            || summary.closest('[data-save-context="off"]') || event.target.closest('button, input, select, textarea')) return;
        disclosure = { summary, event, top: summary.getBoundingClientRect().top, interaction };
        const ticket = disclosure;
        requestAnimationFrame(() => correctDisclosure(ticket));
        setTimeout(() => { if (disclosure === ticket) disclosure = null; }, 250);
    });
    document.addEventListener('toggle', event => {
        const ticket = disclosure;
        if (ticket?.summary.parentElement === event.target) {
            // Run after accordion handlers that close an earlier section.
            requestAnimationFrame(() => correctDisclosure(ticket));
        }
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
        || !(state.mode === 'save' ? [state.from, state.to] : [state.to]).includes(pageKey(location.href))
        || navigation === 'back_forward' || (navigation === 'reload' && state.mode === 'save')
        || !Number.isFinite(state.x) || !Number.isFinite(state.y) || !Array.isArray(state.details)) return;

    for (const entry of state.details) {
        if (!Array.isArray(entry) || entry.length !== 2) continue;
        const [selector, open] = entry;
        const element = find(selector);
        if (element instanceof HTMLDetailsElement) element.open = Boolean(open);
    }
    const form = formFor(state);
    const error = state.mode === 'save' ? document.querySelector('[data-save-feedback="error"]') : null;
    const feedback = state.mode === 'save' ? error || document.querySelector('[data-save-feedback="success"]') : null;
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
        let anchor = state.target ? find(state.target) : find(state.anchor);
        // Do not anchor to a different record if the DOM changed during saving.
        if (state.mode === 'save' && anchor !== form && anchor?.form !== form && !form?.contains(anchor)) anchor = null;
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
        notice.append(message);
        if (error) {
            const close = document.createElement('button');
            close.type = 'button';
            close.textContent = '×';
            close.setAttribute('aria-label', script?.dataset.dismissLabel || 'Close');
            close.addEventListener('click', () => notice.remove());
            notice.append(close);
        }
        document.body.append(notice);
        if (!error) setTimeout(() => notice.remove(), 2000);
    }
})();
