(() => {
    'use strict';
    if (window.__ROOM_CHECK_VALIDATION_FEEDBACK__) return;
    window.__ROOM_CHECK_VALIDATION_FEEDBACK__ = true;

    const nativeFetch = window.fetch.bind(window);
    const config = window.ROOM_CHECK || {};
    let lastEditedRow = null;
    const feedbackTimers = new WeakMap();
    const bypassNavigation = new WeakSet();
    const bypassActions = new WeakSet();
    const previousControlValues = new WeakMap();

    const feedbackForRow = (row) => row?.querySelector('.problem-field .row-save-feedback') || null;
    const textareaForRow = (row) => row?.querySelector('.problem-field textarea') || null;
    const allRows = () => Array.from(document.querySelectorAll('.check-row'));

    const resetFeedback = (feedback, visible = false) => {
        if (!feedback) return;
        window.clearTimeout(feedbackTimers.get(feedback));
        feedbackTimers.delete(feedback);
        feedback.textContent = 'Guardado';
        feedback.style.color = '';
        feedback.style.height = '';
        feedback.style.minHeight = '';
        feedback.style.lineHeight = '';
        feedback.classList.toggle('is-visible', visible);
    };

    const changedRange = (before, after) => {
        before = String(before ?? '');
        after = String(after ?? '');
        let start = 0;
        while (start < before.length && start < after.length && before[start] === after[start]) start += 1;
        let beforeEnd = before.length;
        let afterEnd = after.length;
        while (beforeEnd > start && afterEnd > start && before[beforeEnd - 1] === after[afterEnd - 1]) {
            beforeEnd -= 1;
            afterEnd -= 1;
        }
        while (start > 0 && /[\p{L}\p{N}_-]/u.test(after[start - 1] || '')) start -= 1;
        while (afterEnd < after.length && /[\p{L}\p{N}_-]/u.test(after[afterEnd] || '')) afterEnd += 1;
        return { start, end: Math.max(start, afterEnd) };
    };

    const removeHighlight = (textarea) => {
        if (!textarea) return;
        textarea.classList.remove('language-invalid');
        textarea.removeAttribute('aria-invalid');
        textarea.parentElement?.querySelector('.language-highlight-layer')?.remove();
        if (textarea.dataset.languageOriginalColor !== undefined) {
            textarea.style.color = textarea.dataset.languageOriginalColor;
            delete textarea.dataset.languageOriginalColor;
        }
        if (textarea.dataset.languageOriginalBackground !== undefined) {
            textarea.style.backgroundColor = textarea.dataset.languageOriginalBackground;
            delete textarea.dataset.languageOriginalBackground;
        }
        textarea.style.caretColor = '';
        delete textarea.dataset.languageInvalidWords;
    };

    const highlightBackground = (layer) => layer.dataset.textareaBackground || '#fbfdfd';

    const appendHighlightedText = (layer, value, invalidWords) => {
        const normalized = new Set(
            (Array.isArray(invalidWords) ? invalidWords : [])
                .map((word) => String(word).trim().toLowerCase())
                .filter(Boolean)
        );
        if (normalized.size === 0) return false;

        value.split(/(\p{L}[\p{L}\p{N}_-]*)/u).forEach((part) => {
            if (normalized.has(part.toLowerCase())) {
                const wrong = document.createElement('span');
                wrong.className = 'language-wrong-segment';
                wrong.textContent = part;
                wrong.style.backgroundColor = highlightBackground(layer);
                layer.append(wrong);
            } else {
                layer.append(document.createTextNode(part));
            }
        });
        return true;
    };

    const renderHighlight = (textarea, invalidWords = []) => {
        if (!textarea) return;
        removeHighlight(textarea);
        const value = textarea.value;
        const baseline = textarea.dataset.lastValidValue ?? '';
        const computed = window.getComputedStyle(textarea);
        const layer = document.createElement('div');
        layer.className = 'language-highlight-layer';
        layer.setAttribute('aria-hidden', 'true');
        layer.dataset.textareaBackground = computed.backgroundColor || '#fbfdfd';

        if (!appendHighlightedText(layer, value, invalidWords)) {
            const range = changedRange(baseline, value);
            const normal = document.createElement('span');
            normal.textContent = value.slice(0, range.start);
            const wrong = document.createElement('span');
            wrong.className = 'language-wrong-segment';
            wrong.textContent = value.slice(range.start, range.end) || value;
            wrong.style.backgroundColor = highlightBackground(layer);
            const tail = document.createElement('span');
            tail.textContent = value.slice(range.end);
            layer.append(normal, wrong, tail);
        } else {
            textarea.dataset.languageInvalidWords = JSON.stringify(invalidWords);
        }

        Object.assign(layer.style, {
            position: 'absolute', left: '0', top: '0',
            width: '100%', height: `${textarea.offsetHeight}px`,
            padding: computed.padding, borderWidth: computed.borderWidth, borderStyle: 'solid',
            borderColor: 'transparent', borderRadius: computed.borderRadius,
            boxSizing: computed.boxSizing, font: computed.font,
            lineHeight: computed.lineHeight, letterSpacing: computed.letterSpacing,
            textAlign: computed.textAlign, textIndent: computed.textIndent, wordSpacing: computed.wordSpacing,
            color: 'transparent', whiteSpace: 'pre-wrap', overflowWrap: 'anywhere', overflow: 'hidden',
            pointerEvents: 'none', zIndex: '3',
        });
        textarea.classList.add('language-invalid');
        textarea.dataset.languageNeedsValidation = '1';
        textarea.setAttribute('aria-invalid', 'true');
        textarea.parentElement.insertBefore(layer, textarea);
    };

    const showErrorFeedback = (row, message) => {
        const feedback = feedbackForRow(row);
        if (!feedback || !message) return;
        window.clearTimeout(feedbackTimers.get(feedback));
        feedback.textContent = String(message);
        feedback.style.color = 'var(--wrong)';
        feedback.style.height = 'auto';
        feedback.style.minHeight = '18px';
        feedback.style.lineHeight = '1.3';
        feedback.classList.add('is-visible');
    };

    const showValidationFeedback = (row, message, invalidWords = []) => {
        const textarea = textareaForRow(row);
        if (textarea) renderHighlight(textarea, invalidWords);
        showErrorFeedback(row, message);
    };

    const markTextareaSaved = (textarea, showFeedback = true) => {
        if (!textarea) return;
        textarea.dataset.lastValidValue = textarea.value;
        delete textarea.dataset.languageNeedsValidation;
        removeHighlight(textarea);
        const row = textarea.closest('.check-row');
        const feedback = feedbackForRow(row);
        if (!feedback || !showFeedback) return;
        resetFeedback(feedback, true);
        feedbackTimers.set(feedback, window.setTimeout(() => {
            feedback.classList.remove('is-visible');
            feedbackTimers.delete(feedback);
        }, 2000));
    };

    const showSavedFeedback = (row) => markTextareaSaved(textareaForRow(row), true);

    const pendingTextareas = () => Array.from(document.querySelectorAll(
        '.check-row textarea[data-language-needs-validation="1"]'
    )).filter((textarea) => textarea.value !== (textarea.dataset.lastValidValue ?? textarea.value));

    const invalidTextareas = () => Array.from(document.querySelectorAll(
        '.check-row textarea.language-invalid'
    ));

    const restorePendingEdits = () => {
        pendingTextareas().forEach((textarea) => {
            textarea.value = textarea.dataset.lastValidValue ?? '';
            delete textarea.dataset.languageNeedsValidation;
            removeHighlight(textarea);
            resetFeedback(feedbackForRow(textarea.closest('.check-row')));
            textarea.dispatchEvent(new Event('input', { bubbles: true }));
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
        message.textContent = String(config.languageDecisionMessage || 'Invalid language text. Correct it or cancel the edit?');
        const actions = document.createElement('div');
        actions.className = 'language-decision-actions';
        const correct = document.createElement('button');
        correct.type = 'button';
        correct.textContent = String(config.languageDecisionCorrect || 'Correct');
        const cancel = document.createElement('button');
        cancel.type = 'button';
        cancel.textContent = String(config.languageDecisionCancel || 'Cancel edit');
        actions.append(correct, cancel);
        panel.append(message, actions);
        overlay.append(panel);
        document.body.append(overlay);
        decisionDialog = overlay;
        const finish = (value) => { overlay.remove(); decisionDialog = null; resolve(value); };
        correct.addEventListener('click', () => finish('correct'));
        cancel.addEventListener('click', () => finish('cancel'));
        correct.focus();
    });

    const validatePendingTextareas = async (textareas) => {
        if (textareas.length === 0) return { valid: true, invalid: [] };
        const response = await nativeFetch('api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
            body: JSON.stringify({
                action: 'validate_bilingual_texts',
                csrfToken: config.csrfToken,
                fields: textareas.map((textarea, index) => ({ fieldKey: String(index), text: textarea.value })),
            }),
        });
        let result = null;
        try { result = await response.json(); } catch (_) { result = null; }
        if (response.ok && result?.ok) {
            textareas.forEach((textarea) => removeHighlight(textarea));
            return { valid: true, invalid: [] };
        }
        if (result?.validation === true) {
            const invalid = [];
            (result.invalidFields || []).forEach((field) => {
                const index = Number(field.fieldKey);
                const textarea = Number.isInteger(index) ? textareas[index] : null;
                if (!textarea) return;
                renderHighlight(textarea, field.invalidWords || []);
                showErrorFeedback(textarea.closest('.check-row'), field.error || result.error || 'Invalid language text.');
                invalid.push(textarea);
            });
            return { valid: false, invalid };
        }
        throw new Error(result?.error || 'Could not validate the text.');
    };

    const waitForPendingSave = async (textareas) => {
        textareas.forEach((textarea) => textarea.dispatchEvent(new Event('blur')));
        const deadline = Date.now() + 4500;
        while (Date.now() < deadline) {
            if (textareas.every((textarea) => textarea.dataset.languageNeedsValidation !== '1')) return true;
            if (textareas.some((textarea) => textarea.classList.contains('language-invalid'))) return false;
            await new Promise((resolve) => window.setTimeout(resolve, 60));
        }
        return false;
    };

    const resolvePendingBeforeNavigation = async () => {
        const pending = pendingTextareas();
        if (pending.length === 0) return true;
        let validation;
        try {
            validation = await validatePendingTextareas(pending);
        } catch (error) {
            showErrorFeedback(pending[0]?.closest('.check-row'), error.message);
            pending[0]?.focus();
            return false;
        }
        if (!validation.valid) {
            const decision = await askInvalidEditDecision();
            if (decision === 'correct') {
                (validation.invalid[0] || invalidTextareas()[0])?.focus();
                return false;
            }
            restorePendingEdits();
            return true;
        }
        return waitForPendingSave(pending);
    };

    const contextControl = (target) => target?.matches?.('#propertySelect, #roomSelect, #listSelect, #intervalSelect, #employeeSelect, #assignmentDate');
    const contextAction = (target) => target?.matches?.('#createInterval, #deleteInterval');

    document.addEventListener('focusin', (event) => {
        const textarea = event.target.closest?.('.check-row textarea');
        if (textarea && textarea.dataset.lastValidValue === undefined) textarea.dataset.lastValidValue = textarea.value;
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
        if (textarea.classList.contains('language-invalid')) removeHighlight(textarea);
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

    const markSavedRequest = (requestBody) => {
        const action = requestBody?.action || '';
        if (action === '' && Array.isArray(requestBody?.items)) {
            const rows = allRows();
            requestBody.items.forEach((item, index) => {
                const textarea = textareaForRow(rows[index]);
                if (textarea && textarea.value === String(item.problem ?? '')) markTextareaSaved(textarea, index === requestBody.items.length - 1);
            });
            return;
        }
        if (action === 'set_assignments_atomic' && Array.isArray(requestBody?.changes)) {
            if (requestBody.changes.length === 1 && lastEditedRow?.isConnected) {
                const textarea = textareaForRow(lastEditedRow);
                const instructions = String(requestBody.changes[0]?.instructions ?? '').trim();
                if (textarea && textarea.value.trim() === instructions) markTextareaSaved(textarea, true);
            }
        }
    };

    window.fetch = async (input, init) => {
        const track = isApiPost(input, init);
        let requestBody = null;
        if (track && typeof init?.body === 'string') {
            try { requestBody = JSON.parse(init.body); } catch (_) { requestBody = null; }
        }
        const response = await nativeFetch(input, init);
        if (!track) return response;
        let payload = null;
        try { payload = await response.clone().json(); } catch (_) { payload = null; }
        const action = requestBody?.action || '';
        const isTextSave = action === 'set_assignments_atomic' || action === '';
        if (!isTextSave) return response;
        if (!response.ok || payload?.ok === false) {
            if (payload?.validation === true) {
                showValidationFeedback(lastEditedRow, payload?.error || 'Invalid language text.', payload?.invalidWords || []);
            } else {
                showErrorFeedback(lastEditedRow, payload?.error || 'Erro ao guardar.');
            }
            return response;
        }
        markSavedRequest(requestBody);
        if (lastEditedRow?.isConnected && !requestBody?.items) showSavedFeedback(lastEditedRow);
        return response;
    };
})();
