(() => {
    'use strict';
    if (window.__ROOM_CHECK_VALIDATION_FEEDBACK__) return;
    window.__ROOM_CHECK_VALIDATION_FEEDBACK__ = true;

    const nativeFetch = window.fetch.bind(window);
    const config = window.ROOM_CHECK || {};
    let lastEditedRow = null;
    const feedbackTimers = new WeakMap();
    const bypassNavigation = new WeakSet();
    const previousControlValues = new WeakMap();

    const feedbackForRow = (row) => row?.querySelector('.problem-field .row-save-feedback') || null;
    const textareaForRow = (row) => row?.querySelector('.problem-field textarea') || null;

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
    };

    const renderHighlight = (textarea) => {
        if (!textarea) return;
        removeHighlight(textarea);
        const value = textarea.value;
        const baseline = textarea.dataset.lastValidValue ?? '';
        const range = changedRange(baseline, value);
        const computed = window.getComputedStyle(textarea);
        const layer = document.createElement('div');
        layer.className = 'language-highlight-layer';
        layer.setAttribute('aria-hidden', 'true');
        const normal = document.createElement('span');
        normal.textContent = value.slice(0, range.start);
        const wrong = document.createElement('span');
        wrong.className = 'language-wrong-segment';
        wrong.textContent = value.slice(range.start, range.end) || value;
        const tail = document.createElement('span');
        tail.textContent = value.slice(range.end);
        layer.append(normal, wrong, tail);
        Object.assign(layer.style, {
            position: 'absolute', left: `${textarea.offsetLeft}px`, top: `${textarea.offsetTop}px`,
            width: `${textarea.offsetWidth}px`, minHeight: `${textarea.offsetHeight}px`,
            padding: computed.padding, borderWidth: computed.borderWidth, borderStyle: 'solid',
            borderColor: 'transparent', boxSizing: computed.boxSizing, font: computed.font,
            lineHeight: computed.lineHeight, letterSpacing: computed.letterSpacing,
            whiteSpace: 'pre-wrap', overflowWrap: 'anywhere', pointerEvents: 'none', zIndex: '1',
        });
        textarea.parentElement.style.position = 'relative';
        textarea.dataset.languageOriginalColor = textarea.style.color || '';
        textarea.dataset.languageOriginalBackground = textarea.style.backgroundColor || '';
        textarea.style.color = 'transparent';
        textarea.style.backgroundColor = 'transparent';
        textarea.style.caretColor = computed.color;
        textarea.style.position = 'relative';
        textarea.style.zIndex = '2';
        textarea.classList.add('language-invalid');
        textarea.setAttribute('aria-invalid', 'true');
        textarea.parentElement.insertBefore(layer, textarea);
    };

    const showValidationFeedback = (row, message) => {
        const feedback = feedbackForRow(row);
        const textarea = textareaForRow(row);
        if (textarea) renderHighlight(textarea);
        if (!feedback || !message) return;
        window.clearTimeout(feedbackTimers.get(feedback));
        feedback.textContent = String(message);
        feedback.style.color = 'var(--wrong)';
        feedback.style.height = 'auto';
        feedback.style.minHeight = '18px';
        feedback.style.lineHeight = '1.3';
        feedback.classList.add('is-visible');
    };

    const showSavedFeedback = (row) => {
        const feedback = feedbackForRow(row);
        const textarea = textareaForRow(row);
        if (textarea) {
            textarea.dataset.lastValidValue = textarea.value;
            removeHighlight(textarea);
        }
        if (!feedback) return;
        resetFeedback(feedback, true);
        feedbackTimers.set(feedback, window.setTimeout(() => {
            feedback.classList.remove('is-visible');
            feedbackTimers.delete(feedback);
        }, 2000));
    };

    const invalidTextareas = () => Array.from(document.querySelectorAll('.check-row textarea.language-invalid'));

    const restoreInvalidEdits = () => {
        invalidTextareas().forEach((textarea) => {
            textarea.value = textarea.dataset.lastValidValue ?? '';
            removeHighlight(textarea);
            resetFeedback(feedbackForRow(textarea.closest('.check-row')));
        });
    };

    let decisionDialog = null;
    const askInvalidEditDecision = () => new Promise((resolve) => {
        decisionDialog?.remove();
        const overlay = document.createElement('div');
        overlay.className = 'language-decision-overlay';
        const panel = document.createElement('div');
        panel.className = 'language-decision-dialog';
        panel.setAttribute('role', 'dialog');
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

    const contextControl = (target) => target?.matches?.('#propertySelect, #roomSelect, #listSelect, #intervalSelect, #employeeSelect, #assignmentDate');

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
        if (!contextControl(control) || bypassNavigation.has(control) || invalidTextareas().length === 0) return;
        const intended = control.value;
        const previous = previousControlValues.get(control) ?? '';
        control.value = previous;
        event.preventDefault();
        event.stopImmediatePropagation();
        const decision = await askInvalidEditDecision();
        if (decision === 'correct') {
            invalidTextareas()[0]?.focus();
            return;
        }
        restoreInvalidEdits();
        bypassNavigation.add(control);
        control.value = intended;
        control.dispatchEvent(new Event('change', { bubbles: true }));
        queueMicrotask(() => bypassNavigation.delete(control));
    }, true);

    document.addEventListener('click', async (event) => {
        const link = event.target.closest?.('a[href]');
        if (!link || invalidTextareas().length === 0 || link.target === '_blank') return;
        event.preventDefault();
        event.stopImmediatePropagation();
        const decision = await askInvalidEditDecision();
        if (decision === 'correct') {
            invalidTextareas()[0]?.focus();
            return;
        }
        restoreInvalidEdits();
        window.location.href = link.href;
    }, true);

    const isApiPost = (input, init) => {
        const method = String(init?.method || (input instanceof Request ? input.method : 'GET')).toUpperCase();
        const url = String(input instanceof Request ? input.url : input || '');
        return method === 'POST' && /(?:^|\/)api\.php(?:$|[?#])/.test(url);
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
            showValidationFeedback(lastEditedRow, payload?.error || 'Erro ao guardar.');
            return response;
        }
        if (lastEditedRow?.isConnected) showSavedFeedback(lastEditedRow);
        return response;
    };
})();
