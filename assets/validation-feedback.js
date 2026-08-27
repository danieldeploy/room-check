(() => {
    'use strict';

    if (window.__ROOM_CHECK_VALIDATION_FEEDBACK__) return;
    window.__ROOM_CHECK_VALIDATION_FEEDBACK__ = true;

    const nativeFetch = window.fetch.bind(window);
    let lastEditedRow = null;
    const feedbackTimers = new WeakMap();

    const feedbackForRow = (row) => row?.querySelector('.problem-field .row-save-feedback') || null;

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

    const showValidationFeedback = (row, message) => {
        const feedback = feedbackForRow(row);
        if (!feedback || !message) return;
        window.clearTimeout(feedbackTimers.get(feedback));
        feedback.textContent = String(message);
        feedback.style.color = 'var(--wrong)';
        feedback.style.height = 'auto';
        feedback.style.minHeight = '18px';
        feedback.style.lineHeight = '1.3';
        feedback.classList.add('is-visible');
        feedbackTimers.set(feedback, window.setTimeout(() => {
            feedback.classList.remove('is-visible');
            feedbackTimers.delete(feedback);
        }, 6500));
    };

    const showSavedFeedback = (row) => {
        const feedback = feedbackForRow(row);
        if (!feedback) return;
        resetFeedback(feedback, true);
        feedbackTimers.set(feedback, window.setTimeout(() => {
            feedback.classList.remove('is-visible');
            feedbackTimers.delete(feedback);
        }, 2000));
    };

    document.addEventListener('input', (event) => {
        const textarea = event.target.closest?.('.check-row textarea');
        if (!textarea) return;
        lastEditedRow = textarea.closest('.check-row');
        resetFeedback(feedbackForRow(lastEditedRow));
    }, true);

    document.addEventListener('change', (event) => {
        const checkbox = event.target.closest?.('.check-row .assignment-check input[type="checkbox"]');
        if (!checkbox) return;
        lastEditedRow = checkbox.closest('.check-row');
        resetFeedback(feedbackForRow(lastEditedRow));
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
