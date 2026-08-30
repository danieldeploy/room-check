(() => {
    'use strict';
    if (window.__ROOM_CHECK_IMMEDIATE_EDIT_DECISION__) return;
    window.__ROOM_CHECK_IMMEDIATE_EDIT_DECISION__ = true;

    const config = window.ROOM_CHECK;
    if (!config) return;

    const contextSelector = '#propertySelect, #roomSelect, #listSelect, #intervalSelect, #employeeSelect, #assignmentDate';
    const actionSelector = '#createInterval, #saveInterval, #deleteInterval';
    const previousControlValues = new WeakMap();
    const bypassControls = new WeakSet();
    const bypassActions = new WeakSet();
    let contextIntent = null;
    let decisionDialog = null;

    const allTextareas = () => Array.from(document.querySelectorAll('.check-row textarea'));
    const hasUnsavedValue = (textarea) => textarea.dataset.lastValidValue !== undefined
        && textarea.value !== textarea.dataset.lastValidValue;
    const hasFailedValidation = (textarea) => textarea.dataset.languageSaveFailed === '1'
        || textarea.classList.contains('language-invalid')
        || textarea.getAttribute('aria-invalid') === 'true';
    const blockingTextareas = () => allTextareas().filter((textarea) =>
        hasUnsavedValue(textarea)
        || textarea.dataset.languageNeedsValidation === '1'
        || hasFailedValidation(textarea)
    );
    const hasBlockingEdits = () => blockingTextareas().length > 0;

    const contextControlForTarget = (target) => {
        if (target?.matches?.(contextSelector)) return target;
        if (target?.closest?.('.room-picker-option')) return document.querySelector('#roomSelect');
        return null;
    };

    const guardedLinkForTarget = (target) => {
        const link = target?.closest?.('a[href]');
        return link && link.target !== '_blank' ? link : null;
    };

    const clearFeedbackFor = (textarea) => {
        const feedback = textarea.closest('.check-row')?.querySelector('.problem-field .row-save-feedback');
        if (!feedback) return;
        feedback.textContent = '';
        feedback.removeAttribute('data-i18n-skip');
        feedback.style.color = '';
        feedback.style.height = '';
        feedback.style.minHeight = '';
        feedback.style.lineHeight = '';
        feedback.style.opacity = '';
        feedback.classList.remove('is-visible');
    };

    const restoreEdits = (textareas) => {
        textareas.forEach((textarea) => {
            if (textarea.dataset.lastValidValue !== undefined) {
                textarea.value = textarea.dataset.lastValidValue;
            }
            delete textarea.dataset.languageNeedsValidation;
            delete textarea.dataset.languageSaveFailed;
            textarea.classList.remove('language-invalid');
            textarea.removeAttribute('aria-invalid');
            clearFeedbackFor(textarea);
        });
    };

    const askDecision = (textareas = blockingTextareas()) => new Promise((resolve) => {
        decisionDialog?.remove();
        const hasConfirmedError = textareas.some(hasFailedValidation);
        const overlay = document.createElement('div');
        overlay.className = 'language-decision-overlay';
        const panel = document.createElement('div');
        panel.className = 'language-decision-dialog';
        panel.setAttribute('role', 'alertdialog');
        panel.setAttribute('aria-modal', 'true');
        const message = document.createElement('p');
        message.textContent = String(
            hasConfirmedError
                ? (config.languageDecisionMessage || 'The text could not be saved. Correct it or cancel the edit?')
                : (config.languageDecisionUnsavedMessage || 'There is an unsaved edit. Continue editing or cancel the edit?')
        );
        const actions = document.createElement('div');
        actions.className = 'language-decision-actions';
        const correct = document.createElement('button');
        correct.type = 'button';
        correct.textContent = String(
            hasConfirmedError
                ? (config.languageDecisionCorrect || 'Correct')
                : (config.languageDecisionContinue || 'Continue editing')
        );
        const cancel = document.createElement('button');
        cancel.type = 'button';
        cancel.textContent = String(config.languageDecisionCancel || 'Cancel edit');
        const finish = (value) => {
            overlay.remove();
            decisionDialog = null;
            resolve(value);
        };
        correct.addEventListener('click', () => finish('correct'));
        cancel.addEventListener('click', () => finish('cancel'));
        actions.append(correct, cancel);
        panel.append(message, actions);
        overlay.append(panel);
        document.body.append(overlay);
        decisionDialog = overlay;
        correct.focus();
    });

    const rememberContextValues = () => document.querySelectorAll(contextSelector).forEach((control) => {
        previousControlValues.set(control, control.value);
    });
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', rememberContextValues, { once: true });
    } else {
        rememberContextValues();
    }

    document.addEventListener('focusin', (event) => {
        const control = contextControlForTarget(event.target);
        if (control) previousControlValues.set(control, control.value);
    }, true);

    // Record the navigation/list-change intent before the textarea loses focus.
    // We deliberately do not prevent the pointer action, so native mobile selects
    // still open normally. The blur itself is intercepted below.
    document.addEventListener('pointerdown', (event) => {
        const control = contextControlForTarget(event.target);
        if (control) {
            previousControlValues.set(control, control.value);
            contextIntent = hasBlockingEdits() ? { kind: 'control', target: control } : null;
            return;
        }

        const action = event.target.closest?.(actionSelector);
        if (action) {
            contextIntent = hasBlockingEdits() ? { kind: 'action', target: action } : null;
            return;
        }

        const link = guardedLinkForTarget(event.target);
        if (link) {
            contextIntent = hasBlockingEdits() ? { kind: 'link', target: link } : null;
            return;
        }

        contextIntent = null;
    }, true);

    // A blur caused by a list-changing destination must not start the normal
    // autosave first. Otherwise a still-active edit can be silently persisted
    // before the list-change guard gets a chance to ask the user.
    document.addEventListener('blur', (event) => {
        const textarea = event.target.closest?.('.check-row textarea');
        if (!textarea || !blockingTextareas().includes(textarea)) return;

        const relatedControl = contextControlForTarget(event.relatedTarget);
        const relatedAction = event.relatedTarget?.closest?.(actionSelector);
        const relatedLink = guardedLinkForTarget(event.relatedTarget);
        if (!contextIntent && !relatedControl && !relatedAction && !relatedLink) return;

        event.stopImmediatePropagation();
        event.stopPropagation();
    }, true);

    document.addEventListener('change', async (event) => {
        const control = event.target;
        if (!control?.matches?.(contextSelector)) return;

        if (bypassControls.has(control)) {
            previousControlValues.set(control, control.value);
            return;
        }
        if (!hasBlockingEdits()) {
            previousControlValues.set(control, control.value);
            contextIntent = null;
            return;
        }

        const intended = control.value;
        const previous = previousControlValues.get(control) ?? control.value;
        const blocked = blockingTextareas();
        control.value = previous;
        contextIntent = null;
        event.preventDefault();
        event.stopImmediatePropagation();

        const decision = await askDecision(blocked);
        if (decision === 'correct') {
            (blockingTextareas()[0] || blocked[0])?.focus();
            return;
        }

        restoreEdits(blocked);
        bypassControls.add(control);
        control.value = intended;
        control.dispatchEvent(new Event('change', { bubbles: true }));
        previousControlValues.set(control, intended);
        queueMicrotask(() => bypassControls.delete(control));
    }, true);

    document.addEventListener('click', async (event) => {
        const action = event.target.closest?.(actionSelector);
        if (action && !bypassActions.has(action) && hasBlockingEdits()) {
            const blocked = blockingTextareas();
            contextIntent = null;
            event.preventDefault();
            event.stopImmediatePropagation();
            const decision = await askDecision(blocked);
            if (decision === 'correct') {
                (blockingTextareas()[0] || blocked[0])?.focus();
                return;
            }
            restoreEdits(blocked);
            bypassActions.add(action);
            action.click();
            queueMicrotask(() => bypassActions.delete(action));
            return;
        }

        const link = guardedLinkForTarget(event.target);
        if (!link || !hasBlockingEdits()) return;
        const blocked = blockingTextareas();
        contextIntent = null;
        event.preventDefault();
        event.stopImmediatePropagation();
        const decision = await askDecision(blocked);
        if (decision === 'correct') {
            (blockingTextareas()[0] || blocked[0])?.focus();
            return;
        }
        restoreEdits(blocked);
        window.location.href = link.href;
    }, true);
})();
