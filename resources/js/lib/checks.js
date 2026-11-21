/*
 * A list of measured checks ({group, level, title, detail}) as reported by the
 * engine for Preflight Check and for the conversion quality inspection. Levels are
 * ok, warn and info; there is never a made-up score.
 */

import { el, icon } from './dom.js';

const LEVELS = {
    ok: { icon: 'check-circle', label: 'Baik' },
    warn: { icon: 'alert', label: 'Perlu dicek' },
    info: { icon: 'info', label: 'Info' },
};

function item(check) {
    const level = LEVELS[check.level] ?? LEVELS.info;
    return el('li', { className: `check-item is-${check.level}` }, [
        el('span', { className: 'check-icon', title: level.label }, [icon(level.icon)]),
        el('div', {}, [
            el('p', { className: 'check-title' }, [el('span', { className: 'visually-hidden', text: `${level.label}: ` }), check.title]),
            check.detail ? el('p', { className: 'check-detail', text: check.detail }) : null,
        ]),
    ]);
}

/**
 * @param {Array<{group: string, level: string, title: string, detail: string}>} checks
 * @param {{heading?: string, grouped?: boolean}} options
 */
export function renderChecks(checks, { heading = null, grouped = true } = {}) {
    const warnings = checks.filter((check) => check.level === 'warn').length;
    const root = el('section', { className: 'checks' }, [
        heading ? el('div', { className: 'checks-head' }, [
            el('h3', { text: heading }),
            el('span', { className: `checks-count${warnings ? ' is-warn' : ''}`, text: warnings ? `${warnings} perlu dicek` : 'Tidak ada masalah' }),
        ]) : null,
    ]);

    if (!grouped) {
        root.append(el('ul', { className: 'check-list' }, checks.map(item)));
        return root;
    }

    const groups = new Map();
    checks.forEach((check) => {
        if (!groups.has(check.group)) {
            groups.set(check.group, []);
        }
        groups.get(check.group).push(check);
    });
    groups.forEach((list, group) => {
        root.append(el('h4', { className: 'check-group', text: group }), el('ul', { className: 'check-list' }, list.map(item)));
    });
    return root;
}
