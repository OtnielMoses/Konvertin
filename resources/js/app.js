/*
 * Konvertin front end entry point.
 * - Navigation: category dropdown and mobile menu (keyboard and Escape friendly).
 * - Home: tool search and category filter.
 * - Tool pages: the workbench (resources/js/workbench.js) and its mode modules.
 */

import { storage } from './lib/dom.js';
import { initWorkbench } from './workbench.js';

// The old "recently used" list is gone; its leftover entry is removed from this browser.
const RECENT_KEY = 'konvertin-recent';

function initDisclosure(toggle, panel, { closeOnOutside = true } = {}) {
    const close = () => {
        toggle.setAttribute('aria-expanded', 'false');
        panel.hidden = true;
    };

    toggle.addEventListener('click', () => {
        const open = toggle.getAttribute('aria-expanded') !== 'true';
        toggle.setAttribute('aria-expanded', String(open));
        panel.hidden = !open;
        if (open) {
            panel.querySelector('a, button')?.focus();
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && !panel.hidden) {
            close();
            toggle.focus();
        }
    });

    if (closeOnOutside) {
        document.addEventListener('click', (event) => {
            if (!panel.hidden && !panel.contains(event.target) && !toggle.contains(event.target)) {
                close();
            }
        });
    }

    panel.addEventListener('click', (event) => {
        if (event.target.closest('a')) {
            close();
        }
    });
}

function initNavigation() {
    document.querySelectorAll('[data-dropdown]').forEach((dropdown) => {
        initDisclosure(dropdown.querySelector('[data-dropdown-toggle]'), dropdown.querySelector('.nav-menu'));
    });

    const menuToggle = document.querySelector('[data-menu-toggle]');
    const mobileMenu = document.getElementById('menu-mobile');
    if (menuToggle && mobileMenu) {
        initDisclosure(menuToggle, mobileMenu, { closeOnOutside: false });
    }
}

function initHome() {
    const search = document.querySelector('[data-tool-search]');
    if (!search) {
        return;
    }

    const cards = Array.from(document.querySelectorAll('[data-tool-card]'));
    const sections = Array.from(document.querySelectorAll('[data-category-section]'));
    const chips = Array.from(document.querySelectorAll('[data-filter]'));
    const empty = document.querySelector('[data-search-empty]');
    const status = document.querySelector('[data-search-status]');
    let category = 'all';

    const apply = () => {
        const terms = search.value.trim().toLowerCase().split(/\s+/).filter(Boolean);
        let visible = 0;

        cards.forEach((card) => {
            const matchesTerm = terms.every((term) => card.dataset.search.includes(term));
            const matchesCategory = category === 'all' || card.dataset.category === category;
            card.parentElement.hidden = false;
            card.hidden = !(matchesTerm && matchesCategory);
            visible += card.hidden ? 0 : 1;
        });

        sections.forEach((section) => {
            const shown = section.querySelectorAll('[data-tool-card]:not([hidden])').length;
            section.hidden = shown === 0;
            section.querySelectorAll('.tool-grid').forEach((grid) => {
                const gridShown = grid.querySelectorAll('[data-tool-card]:not([hidden])').length;
                grid.hidden = gridShown === 0;
                const label = grid.previousElementSibling;
                if (label?.matches('[data-subgroup]')) {
                    label.hidden = gridShown === 0;
                }
            });
        });

        empty.hidden = visible > 0;
        status.textContent = terms.length || category !== 'all' ? `${visible} alat ditemukan` : '';
    };

    search.addEventListener('input', apply);
    // Enter jumps to the filtered directory; "/" focuses the search from anywhere on the page.
    search.addEventListener('keydown', (event) => {
        if (event.key === 'Enter') {
            event.preventDefault();
            document.querySelector('#semua-alat')?.scrollIntoView({ behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
        }
    });
    document.addEventListener('keydown', (event) => {
        if (event.key === '/' && !event.target.closest('input, textarea, select, [contenteditable]')) {
            event.preventDefault();
            search.focus();
        }
    });
    chips.forEach((chip) => {
        chip.addEventListener('click', () => {
            category = chip.dataset.filter;
            chips.forEach((other) => other.setAttribute('aria-pressed', String(other === chip)));
            apply();
        });
    });
    document.querySelector('[data-search-reset]')?.addEventListener('click', () => {
        search.value = '';
        category = 'all';
        chips.forEach((chip) => chip.setAttribute('aria-pressed', String(chip.dataset.filter === 'all')));
        apply();
        search.focus();
    });
}

function forgetRecentTools() {
    try {
        storage()?.removeItem(RECENT_KEY);
    } catch (error) {
        // Storage may be blocked; nothing to clean up then.
    }
}

const prefersReducedMotion = () => window.matchMedia('(prefers-reduced-motion: reduce)').matches;

/**
 * Elements marked data-reveal fade and rise in once they scroll into view.
 * Without IntersectionObserver or with reduced motion they are simply shown.
 */
function initReveal() {
    const items = Array.from(document.querySelectorAll('[data-reveal]'));
    if (!items.length || !('IntersectionObserver' in window) || prefersReducedMotion()) {
        return;
    }

    document.documentElement.classList.add('reveal-ready');
    const observer = new IntersectionObserver((entries) => {
        entries.forEach((entry) => {
            if (entry.isIntersecting) {
                entry.target.classList.add('is-revealed');
                observer.unobserve(entry.target);
            }
        });
    }, { rootMargin: '0px 0px -6% 0px', threshold: 0.08 });

    items.forEach((item) => observer.observe(item));
}


initNavigation();
initHome();
initReveal();
forgetRecentTools();

document.querySelectorAll('[data-workbench]').forEach((form) => initWorkbench(form));
