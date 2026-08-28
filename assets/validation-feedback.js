(() => {
    'use strict';
    if (window.__ROOM_CHECK_VALIDATION_FEEDBACK__) return;
    window.__ROOM_CHECK_VALIDATION_FEEDBACK__ = true;

    const nativeFetch = window.fetch.bind(window);
    const config = window.ROOM_CHECK || {};
    const translationFeedback = window.ROOM_TRANSLATION_FEEDBACK || {};
    let lastEditedRow = null;
    const feedbackTimers = new WeakMap();
    const bypassNavigation = new WeakSet();
    const bypassActions = new WeakSet();
    const previousControlValues = new WeakMap();

    const feedbackForRow = (row) => row?.querySelector('.problem-field .row-save-feedback') || null;
    const textareaForRow = (row) => row?.querySelector('.problem-field textarea') || null;
    const allRows = () => Array.from(document.querySelectorAll('.check-row'));
    const rowForFieldKey = (fieldKey) => {
        const key = String(fieldKey || '');
        if (!key) return null;
        return allRows().find((row) => row.dataset.fieldKey === key)
            || allRows().find((row) => row.querySelector('h2')?.textContent === key)
            || null;
    };

    // Bind the canonical server-side item key to the actual DOM row before the
    // request leaves the browser. Visible headings can be translated (Espelho ->
    // Mirror), so they are not a stable identifier. Capturing the edited row here
    // also prevents a later tap/blur from stealing feedback from an in-flight save.
    const bindRequestRows = (requestBody, editedRowAtRequest) => {
        const bound = new Map();
        const bind = (fieldKey, row) => {
            const key = String(fieldKey || '');
            if (!key || !row) return;
            row.dataset.fieldKey = key;
            bound.set(key, row);
        };

        const action = requestBody?.action || '';
        if (action === '' && Array.isArray(requestBody?.items)) {
            const rows = allRows();
            requestBody.items.forEach((item, index) => bind(item?.name, rows[index] || null));
            return bound;
        }

        if (action === 'set_assignments_atomic' && Array.isArray(requestBody?.changes)) {
            if (requestBody.changes.length === 1 && editedRowAtRequest) {
                bind(requestBody.changes[0]?.itemName, editedRowAtRequest);
            }
            requestBody.changes.forEach((change) => {
                const key = String(change?.itemName || '');
                if (!key || bound.has(key)) return;
                const alreadyBound = rowForFieldKey(key);
                if (alreadyBound) {
                    bind(key, alreadyBound);
                    return;
                }
                const instructions = String(change?.instructions ?? '').trim();
                const matchingRows = allRows().filter(
                    (row) => textareaForRow(row)?.value.trim() === instructions
                );
                if (matchingRows.length === 1) bind(key, matchingRows[0]);
            });
        }
        return bound;
    };

    const translationResultsFromResponse = (response) => {
        const encoded = response?.headers?.get?.('X-Room-Translation-Results') || '';
        if (!encoded) return [];
        try {
            const base64 = encoded.replace(/-/g, '+').replace(/_/g, '/');
            const padded = base64 + '='.repeat((4 - (base64.length % 4)) % 4);
            const bytes = Uint8Array.from(atob(padded), (character) => character.charCodeAt(0));
            const json = new TextDecoder('utf-8').decode(bytes);
            const parsed = JSON.parse(json);
            return Array.isArray(parsed) ? parsed : [];
        } catch (_) {
            return [];
        }
    };

    // Reused by the transversal bilingual textarea script so every persistent
    // save reads the same server conclusion without a second validation request.
    window.ROOM_TRANSLATION_RESULT_READER = Object.freeze({
        fromResponse: translationResultsFromResponse,
    });

    const keepValidationTextareaEditable = (textarea) => {
        if (!textarea || config.canEdit === false) return;
        const row = textarea.closest('.check-row');
        if (!row) return;
        if (!row.classList.contains('assignment-mode')) {
            textarea.readOnly = false;
            return;
        }
        const checkbox = row.querySelector('.assignment-check input[type="checkbox"]');
        if (checkbox && checkbox.checked && !checkbox.disabled) textarea.readOnly = false;
    };

    const clearInvalidState = (textarea) => {
        if (!textarea) return;
        textarea.classList.remove('language-invalid');
        textarea.removeAttribute('aria-invalid');
        delete textarea.dataset.languageSaveFailed;
    };

    const resetFeedback = (feedback) => {
        if (!feedback) return;
        window.clearTimeout(feedbackTimers.get(feedback));
        feedbackTimers.delete(feedback);
        feedback.textContent = '';
        feedback.style.color = '';
        feedback.style.height = '';
        feedback.style.minHeight = '';
        feedback.style.lineHeight = '';
        feedback.style.opacity = '';
        feedback.classList.remove('is-visible');
    };

    const showFeedback = (row, message, kind = 'error') => {
        const feedback = feedbackForRow(row);
        if (!feedback || !message) return;
        window.clearTimeout(feedbackTimers.get(feedback));
        feedback.textContent = String(message);
        feedback.style.color = kind === 'success' ? 'var(--ok)' : 'var(--wrong)';
        feedback.style.height = 'auto';
        feedback.style.minHeight = '18px';
        feedback.style.lineHeight = '1.3';
        // Keep translation feedback visible independently from app.js's short
        // generic "Guardado" timer. app.js may remove the shared is-visible
        // class after two seconds; the inline opacity keeps this exact server
        // conclusion visible until our own six-second timer expires.
        feedback.style.opacity = '1';
        feedback.classList.add('is-visible');
        if (kind === 'success') {
            feedbackTimers.set(feedback, window.setTimeout(() => {
                feedback.style.opacity = '';
                feedback.classList.remove('is-visible');
                feedbackTimers.delete(feedback);
            }, 6000));
        }
    };

    const showErrorFeedback = (row, message) => {
        const textarea = textareaForRow(row);
        if (textarea) {
            keepValidationTextareaEditable(textarea);
            textarea.classList.add('language-invalid');
            textarea.dataset.languageSaveFailed = '1';
            textarea.setAttribute('aria-invalid', 'true');
        }
        showFeedback(row, message, 'error');
    };

    const markTextareaSaved = (textarea, message = '', showMessage = true) => {
        if (!textarea) return;
        textarea.dataset.lastValidValue = textarea.value;
        delete textarea.dataset.languageNeedsValidation;
        clearInvalidState(textarea);
        if (showMessage) {
            showFeedback(
                textarea.closest('.check-row'),
                message || translationFeedback.saved || 'Saved.',
                'success'
            );
        }
    };

    const pendingTextareas = () => Array.from(document.querySelectorAll(
        '.check-row textarea[data-language-needs-validation="1"]'
    )).filter((textarea) => textarea.value !== (textarea.dataset.lastValidValue ?? textarea.value));

    const restorePendingEdits = () => {
        pendingTextareas().forEach((textarea) => {
            textarea.value = textarea.dataset.lastValidValue ?? '';
            delete textarea.dataset.languageNeedsValidation;
            clearInvalidState(textarea);
            resetFeedback(feedbackForRow(textarea.closest('.check-row')));
            textarea.dispatchEvent(new Event('input', { bubbles: true }));
            delete textarea.dataset.languageNeedsValidation;
        });
    };

    let decisionDialog = null;
    const askInvalidEditDecision = () => new Promise((resolve) => {
        decisionDialog?.remove();
        const overlay = document.createElement('div');
        overlay.className = 'language-decision-overlay';
        const panel = document.createElement('div');
        panel.className = 'language-decision-dialog';
        panel.setAttribute('role', 'alertdialog');
        panel.setAttribute('aria-modal', 'true');
        const message = document.createElement('p');
        message.textContent = String(config.languageDecisionMessage || 'The text could not be saved. Correct it or cancel the edit?');
        const actions = document.createElement('div');
        actions.className = 'language-decision-actions';
        const correct = document.createElement('button');
        correct.type = 'button';
        correct.textContent = String(config.languageDecisionCorrect || 'Correct');
        const cancel = document.createElement('button');
        cancel.type = 'button';
        cancel.textContent = String(config.languageDecisionCancel || 'Cancel edit');
        const finish = (value) => { overlay.remove(); decisionDialog = null; resolve(value); };
        correct.addEventListener('click', () => finish('correct'));
        cancel.addEventListener('click', () => finish('cancel'));
        actions.append(correct, cancel);
        panel.append(message, actions);
        overlay.append(panel);
        document.body.append(overlay);
        decisionDialog = overlay;
        correct.focus();
    });

    const flushPendingSaves = async (textareas) => {
        textareas.forEach((textarea) => textarea.dispatchEvent(new Event('blur')));
        const deadline = Date.now() + 30000;
        while (Date.now() < deadline) {
            if (textareas.every((textarea) => textarea.dataset.languageNeedsValidation !== '1')) return true;
            if (textareas.some((textarea) => textarea.dataset.languageSaveFailed === '1')) return false;
            await new Promise((resolve) => window.setTimeout(resolve, 80));
        }
        showErrorFeedback(
            textareas[0]?.closest('.check-row'),
            translationFeedback.timeout || 'Error: validation/translation timed out.'
        );
        return false;
    };

    const resolvePendingBeforeNavigation = async () => {
        const pending = pendingTextareas();
        if (pending.length === 0) return true;
        if (await flushPendingSaves(pending)) return true;
        const decision = await askInvalidEditDecision();
        if (decision === 'correct') {
            (pendingTextareas()[0] || pending[0])?.focus();
            return false;
        }
        restorePendingEdits();
        return true;
    };

    const contextControl = (target) => target?.matches?.('#propertySelect, #roomSelect, #listSelect, #intervalSelect, #employeeSelect, #assignmentDate');

    document.addEventListener('focusin', (event) => {
        const textarea = event.target.closest?.('.check-row textarea');
        if (textarea) {
            if (textarea.dataset.lastValidValue === undefined) textarea.dataset.lastValidValue = textarea.value;
            keepValidationTextareaEditable(textarea);
        }
        if (contextControl(event.target)) previousControlValues.set(event.target, event.target.value);
    }, true);

    document.addEventListener('pointerdown', (event) => {
        if (contextControl(event.target)) previousControlValues.set(event.target, event.target.value);
    }, true);

    document.addEventListener('input', (event) => {
        const textarea = event.target.closest?.('.check-row textarea');
        if (!textarea) return;
        lastEditedRow = textarea.closest('.check-row');
        if (textarea.dataset.lastValidValue === undefined) textarea.dataset.lastValidValue = textarea.value;
        clearInvalidState(textarea);
        if (textarea.value !== textarea.dataset.lastValidValue) textarea.dataset.languageNeedsValidation = '1';
        else delete textarea.dataset.languageNeedsValidation;
        resetFeedback(feedbackForRow(lastEditedRow));
    }, true);

    document.addEventListener('change', async (event) => {
        const checkbox = event.target.closest?.('.check-row .assignment-check input[type="checkbox"]');
        if (checkbox) {
            lastEditedRow = checkbox.closest('.check-row');
            resetFeedback(feedbackForRow(lastEditedRow));
            return;
        }
        const control = event.target;
        if (!contextControl(control) || bypassNavigation.has(control) || pendingTextareas().length === 0) return;
        const intended = control.value;
        const previous = previousControlValues.get(control) ?? '';
        control.value = previous;
        event.preventDefault();
        event.stopImmediatePropagation();
        if (!(await resolvePendingBeforeNavigation())) return;
        bypassNavigation.add(control);
        control.value = intended;
        control.dispatchEvent(new Event('change', { bubbles: true }));
        previousControlValues.set(control, intended);
        queueMicrotask(() => bypassNavigation.delete(control));
    }, true);

    document.addEventListener('click', async (event) => {
        const action = event.target.closest?.('#createInterval, #deleteInterval');
        if (action && !bypassActions.has(action) && pendingTextareas().length > 0) {
            event.preventDefault();
            event.stopImmediatePropagation();
            if (!(await resolvePendingBeforeNavigation())) return;
            bypassActions.add(action);
            action.click();
            queueMicrotask(() => bypassActions.delete(action));
            return;
        }

        const link = event.target.closest?.('a[href]');
        if (!link || pendingTextareas().length === 0 || link.target === '_blank') return;
        event.preventDefault();
        event.stopImmediatePropagation();
        if (!(await resolvePendingBeforeNavigation())) return;
        window.location.href = link.href;
    }, true);

    const isApiPost = (input, init) => {
        const method = String(init?.method || (input instanceof Request ? input.method : 'GET')).toUpperCase();
        const url = String(input instanceof Request ? input.url : input || '');
        return method === 'POST' && /(?:^|\/)api\.php(?:$|[?#])/.test(url);
    };

    const markSavedRequest = (requestBody, translationResults, editedRowAtRequest, requestRows) => {
        const action = requestBody?.action || '';
        if (action === '' && Array.isArray(requestBody?.items)) {
            requestBody.items.forEach((item, index) => {
                const key = String(item?.name || '');
                const row = requestRows.get(key) || rowForFieldKey(key);
                const textarea = textareaForRow(row);
                if (textarea && textarea.value === String(item?.problem ?? '')) {
                    markTextareaSaved(
                        textarea,
                        String(translationResults[index]?.message || ''),
                        row === editedRowAtRequest
                    );
                }
            });
            return;
        }
        if (action === 'set_assignments_atomic' && Array.isArray(requestBody?.changes)) {
            requestBody.changes.forEach((change, index) => {
                const key = String(change?.itemName || '');
                const row = requestRows.get(key) || rowForFieldKey(key);
                const textarea = textareaForRow(row);
                const instructions = String(change?.instructions ?? '').trim();
                if (textarea && textarea.value.trim() === instructions) {
                    markTextareaSaved(
                        textarea,
                        String(translationResults[index]?.message || ''),
                        row === editedRowAtRequest
                    );
                }
            });
        }
    };

    window.fetch = async (input, init) => {
        const track = isApiPost(input, init);
        let requestBody = null;
        if (track && typeof init?.body === 'string') {
            try { requestBody = JSON.parse(init.body); } catch (_) { requestBody = null; }
        }

        const editedRowAtRequest = lastEditedRow;
        const requestRows = track && requestBody
            ? bindRequestRows(requestBody, editedRowAtRequest)
            : new Map();
        const response = await nativeFetch(input, init);
        if (!track) return response;

        const action = requestBody?.action || '';
        const isTextSave = action === 'set_assignments_atomic'
            || (action === '' && Array.isArray(requestBody?.items));
        if (!isTextSave) return response;

        let payload = null;
        try { payload = await response.clone().json(); } catch (_) { payload = null; }
        if (!response.ok || payload?.ok === false) {
            const fieldKey = String(payload?.fieldKey || '');
            const errorRow = requestRows.get(fieldKey)
                || rowForFieldKey(fieldKey)
                || editedRowAtRequest;
            showErrorFeedback(errorRow, payload?.error || translationFeedback.saveError || 'Error: could not save.');
            return response;
        }

        markSavedRequest(
            requestBody,
            translationResultsFromResponse(response),
            editedRowAtRequest,
            requestRows
        );
        return response;
    };
})();
