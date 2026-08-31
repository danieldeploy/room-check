(() => {
    'use strict';

    const menuSelector = 'details[data-global-menu]';
    const boundAttribute = 'data-global-menu-bound';

    const closeMenus = (except = null) => {
        document.querySelectorAll(`${menuSelector}[open]`).forEach((menu) => {
            if (menu !== except) {
                menu.open = false;
            }
        });
    };

    const bindMenus = (root = document) => {
        root.querySelectorAll(`${menuSelector}:not([${boundAttribute}])`).forEach((menu) => {
            menu.setAttribute(boundAttribute, '');
            menu.addEventListener('toggle', () => {
                if (menu.open) {
                    closeMenus(menu);
                }
            });
        });
    };

    bindMenus();

    new MutationObserver((mutations) => {
        if (mutations.some((mutation) => mutation.addedNodes.length > 0)) {
            bindMenus();
        }
    }).observe(document.documentElement, { childList: true, subtree: true });

    document.addEventListener('pointerdown', (event) => {
        if (!event.target.closest(menuSelector)) {
            closeMenus();
        }
    });

    document.addEventListener('click', (event) => {
        if (event.target.closest(`${menuSelector} a, ${menuSelector} [data-global-menu-action]`)) {
            closeMenus();
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            closeMenus();
        }
    });
})();
