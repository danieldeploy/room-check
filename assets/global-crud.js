(() => {
    'use strict';

    const workflowSelector = '[data-global-crud]';
    const panelSelector = 'details[data-crud-action]';
    const boundAttribute = 'data-global-crud-bound';
    const actionOrder = ['new', 'delete', 'edit'];
    const externalActionSelectors = {
        delete: 'input[name="action"][value^="delete_"]',
        edit: 'input[name="action"][value^="rename_"]',
    };

    const resetPanel = (panel) => {
        panel.querySelectorAll('form').forEach((form) => form.reset());

        panel.querySelectorAll('input, textarea, select').forEach((field) => {
            if (field instanceof HTMLInputElement) {
                if (field.type === 'hidden' || field.disabled) {
                    return;
                }
                if (field.type === 'checkbox' || field.type === 'radio') {
                    field.checked = field.defaultChecked;
                    return;
                }
                field.value = '';
                return;
            }

            if (field instanceof HTMLTextAreaElement) {
                if (!field.disabled) {
                    field.value = '';
                }
                return;
            }

            if (field instanceof HTMLSelectElement) {
                const placeholder = Array.from(field.options).find((option) => option.value === '');
                if (placeholder) {
                    field.selectedIndex = placeholder.index;
                }
            }
        });
    };

    const externalContentForAction = (workflow, action) => {
        const selector = externalActionSelectors[action];
        if (!selector) {
            return [];
        }

        const content = new Set();
        document.querySelectorAll(selector).forEach((marker) => {
            if (workflow.contains(marker)) {
                return;
            }

            const container = marker.closest('section.list-card');
            if (container) {
                content.add(container);
            }
        });
        return Array.from(content);
    };

    const hideExternalContent = (workflow, action) => {
        externalContentForAction(workflow, action).forEach((content) => {
            content.hidden = true;
        });
    };

    const bindWorkflow = (workflow) => {
        if (workflow.hasAttribute(boundAttribute)) {
            return;
        }

        workflow.setAttribute(boundAttribute, '');
        const panels = Array.from(workflow.querySelectorAll(`:scope > ${panelSelector}`));
        panels.sort((left, right) => (
            actionOrder.indexOf(left.dataset.crudAction) - actionOrder.indexOf(right.dataset.crudAction)
        ));
        panels.forEach((panel) => workflow.appendChild(panel));

        workflow.addEventListener('toggle', (event) => {
            const openedPanel = event.target;
            if (!(openedPanel instanceof HTMLDetailsElement) || !openedPanel.open || !openedPanel.matches(panelSelector)) {
                return;
            }

            panels.forEach((panel) => {
                if (panel !== openedPanel) {
                    resetPanel(panel);
                    hideExternalContent(workflow, panel.dataset.crudAction);
                    panel.open = false;
                }
            });
        }, true);
    };

    const bindWorkflows = (root = document) => {
        root.querySelectorAll(`${workflowSelector}:not([${boundAttribute}])`).forEach(bindWorkflow);
    };

    bindWorkflows();
    new MutationObserver((mutations) => {
        if (mutations.some((mutation) => mutation.addedNodes.length > 0)) {
            bindWorkflows();
        }
    }).observe(document.documentElement, { childList: true, subtree: true });
})();
