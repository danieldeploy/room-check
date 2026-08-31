(() => {
    'use strict';

    const workflowSelector = '[data-global-crud]';
    const panelSelector = 'details[data-crud-action]';
    const boundAttribute = 'data-global-crud-bound';
    const actionOrder = ['new', 'delete', 'edit'];

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
