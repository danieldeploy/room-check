(() => {
    'use strict';

    const script = document.currentScript;
    const apiUrl = script?.dataset.bilingualApi || 'api.php';
    const selector = 'textarea[data-bilingual-textarea]';
    const translationFeedback = window.ROOM_TRANSLATION_FEEDBACK || {};
    const savePromises = new WeakMap();
    const bypassForms = new WeakSet();
    const bypassLinks = new WeakSet();

    const textareas = () => Array.from(document.querySelectorAll(selector));
    const csrfToken = (textarea) => textarea.closest('form')?.querySelector('input[name="csrf_token"]')?.value || '';
    const fieldFor = (textarea) => {
        if (textarea.parentElement?.classList.contains('bilingual-textarea-field')) return textarea.parentElement;
        const field = document.createElement('div');
        field.className = 'bilingual-textarea-field';
        textarea.parentNode.insertBefore(field, textarea);
        field.append(textarea);
        return field;
    };
    const feedbackFor = (textarea) => {
        const field = fieldFor(textarea);
        let feedback = field.querySelector('.bilingual-textarea-feedback');
        if (!feedback) {
            feedback = document.createElement('span');
            feedback.className = 'bilingual-textarea-feedback';
            feedback.setAttribute('aria-live', 'polite');
            field.append(feedback);
        }
        return feedback;
    };
    const clearInvalid = (textarea) => {
        textarea.classList.remove('bilingual-language-invalid');
        textarea.removeAttribute('aria-invalid');
        delete textarea.dataset.bilingualInvalid;
    };
    const markInvalid = (textarea) => {
        textarea.classList.add('bilingual-language-invalid');
        textarea.dataset.bilingualInvalid = '1';
        textarea.setAttribute('aria-invalid', 'true');
    };
    const clearFeedback = (textarea) => {
        const feedback = feedbackFor(textarea);
        feedback.textContent = '';
        feedback.classList.remove('is-visible', 'is-error', 'is-saved');
    };
    const showFeedback = (textarea, message, kind) => {
        const feedback = feedbackFor(textarea);
        feedback.textContent = String(message || '');
        feedback.classList.remove('is-error', 'is-saved');
        feedback.classList.add('is-visible', kind === 'saved' ? 'is-saved' : 'is-error');
    };
    const responseMessage = (response) => {
        const reader = window.ROOM_TRANSLATION_RESULT_READER;
        const results = reader?.fromResponse?.(response);
        return String(results?.[0]?.message || '');
    };

    // Existing instructions go through their real save. New-item instructions
    // are translated only when their containing form creates the item.
    const autosaveTextarea = async (textarea) => {
        const action = textarea.dataset.bilingualAutosaveAction || '';
        if (!action) return true;
        const requestValue = textarea.value;
        try {
            const response = await fetch(apiUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                body: JSON.stringify({
                    action,
                    csrfToken: csrfToken(textarea),
                    listId: Number(textarea.dataset.listId || 0),
                    itemId: Number(textarea.dataset.itemId || 0),
                    text: requestValue
                })
            });
            let payload = null;
            try { payload = await response.json(); } catch (_) { payload = null; }
            if (!response.ok || payload?.ok === false) {
                markInvalid(textarea);
                showFeedback(textarea, payload?.error || translationFeedback.saveError || 'Error: could not save.', 'error');
                return false;
            }
            if (textarea.value !== requestValue) {
                textarea.dataset.bilingualPending = '1';
                clearInvalid(textarea);
                return true;
            }
            textarea.dataset.bilingualLastValidValue = requestValue;
            delete textarea.dataset.bilingualPending;
            clearInvalid(textarea);
            showFeedback(
                textarea,
                responseMessage(response) || translationFeedback.saved || 'Saved.',
                'saved'
            );
            return true;
        } catch (_) {
            markInvalid(textarea);
            showFeedback(textarea, translationFeedback.saveError || 'Error: could not save.', 'error');
            return false;
        }
    };

    const processTextarea = (textarea, persistWhenPossible = true, allowFormSubmit = false) => {
        const existing = savePromises.get(textarea);
        if (existing) return existing;
        const promise = (async () => {
            if (textarea.value === (textarea.dataset.bilingualLastValidValue ?? textarea.value)) {
                delete textarea.dataset.bilingualPending;
                clearInvalid(textarea);
                return true;
            }

            if (persistWhenPossible && textarea.dataset.bilingualAutosaveAction) {
                return autosaveTextarea(textarea);
            }

            // A field without a persistence action must never call a translation
            // preview endpoint. Its normal form submission owns translation/save.
            return allowFormSubmit;
        })().finally(() => savePromises.delete(textarea));
        savePromises.set(textarea, promise);
        return promise;
    };

    const pending = () => textareas().filter((textarea) => textarea.dataset.bilingualPending === '1' || textarea.dataset.bilingualInvalid === '1');
    const restorePending = () => {
        pending().forEach((textarea) => {
            textarea.value = textarea.dataset.bilingualLastValidValue ?? '';
            delete textarea.dataset.bilingualPending;
            clearInvalid(textarea);
            clearFeedback(textarea);
            textarea.dispatchEvent(new Event('input', { bubbles: true }));
            delete textarea.dataset.bilingualPending;
        });
    };
    const askDecision = () => new Promise((resolve) => {
        const overlay = document.createElement('div');
        overlay.className = 'bilingual-decision-overlay';
        const dialog = document.createElement('div');
        dialog.className = 'bilingual-decision-dialog';
        dialog.setAttribute('role', 'alertdialog');
        dialog.setAttribute('aria-modal', 'true');
        const message = document.createElement('p');
        message.textContent = document.body?.dataset.bilingualDecisionMessage || 'The text could not be saved. Correct it or cancel the edit?';
        const actions = document.createElement('div');
        actions.className = 'bilingual-decision-actions';
        const correct = document.createElement('button');
        correct.type = 'button';
        correct.textContent = document.body?.dataset.bilingualCorrect || 'Correct';
        const cancel = document.createElement('button');
        cancel.type = 'button';
        cancel.textContent = document.body?.dataset.bilingualCancel || 'Cancel edit';
        const finish = (value) => { overlay.remove(); resolve(value); };
        correct.addEventListener('click', () => finish('correct'));
        cancel.addEventListener('click', () => finish('cancel'));
        actions.append(correct, cancel);
        dialog.append(message, actions);
        overlay.append(dialog);
        document.body.append(overlay);
        correct.focus();
    });
    const resolvePending = async () => {
        const fields = pending();
        if (fields.length === 0) return true;
        const results = await Promise.all(fields.map((textarea) => processTextarea(textarea, true)));
        if (results.every(Boolean)) return true;
        const decision = await askDecision();
        if (decision === 'correct') {
            (pending()[0] || fields[0])?.focus();
            return false;
        }
        restorePending();
        return true;
    };

    const initTextarea = (textarea) => {
        fieldFor(textarea);
        textarea.dataset.bilingualLastValidValue = textarea.value;
        textarea.addEventListener('input', () => {
            clearInvalid(textarea);
            clearFeedback(textarea);
            if (textarea.value === (textarea.dataset.bilingualLastValidValue ?? '')) delete textarea.dataset.bilingualPending;
            else textarea.dataset.bilingualPending = '1';
        });
        textarea.addEventListener('blur', () => {
            if (textarea.dataset.bilingualPending === '1' && textarea.dataset.bilingualAutosaveAction) {
                void processTextarea(textarea, true);
            }
        });
    };

    const init = () => {
        textareas().forEach(initTextarea);
        if (textareas().length === 0) return;

        document.addEventListener('submit', async (event) => {
            const form = event.target;
            if (!(form instanceof HTMLFormElement) || bypassForms.has(form)) return;
            const ownFields = Array.from(form.querySelectorAll(selector));
            if (ownFields.length > 0) {
                event.preventDefault();
                event.stopImmediatePropagation();
                const results = await Promise.all(ownFields.map((textarea) =>
                    processTextarea(textarea, Boolean(textarea.dataset.bilingualAutosaveAction), true)
                ));
                if (!results.every(Boolean)) return;
                bypassForms.add(form);
                form.requestSubmit(event.submitter || undefined);
                queueMicrotask(() => bypassForms.delete(form));
                return;
            }
            if (pending().length === 0) return;
            event.preventDefault();
            event.stopImmediatePropagation();
            if (!(await resolvePending())) return;
            bypassForms.add(form);
            form.requestSubmit(event.submitter || undefined);
            queueMicrotask(() => bypassForms.delete(form));
        }, true);

        document.addEventListener('click', async (event) => {
            const link = event.target.closest?.('a[href]');
            if (!link || bypassLinks.has(link) || pending().length === 0 || link.target === '_blank') return;
            event.preventDefault();
            event.stopImmediatePropagation();
            if (!(await resolvePending())) return;
            bypassLinks.add(link);
            window.location.href = link.href;
        }, true);
    };

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, { once: true });
    else init();
})();
