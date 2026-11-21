/* Small DOM helpers shared by every module. */

export function icon(name) {
    const source = document.querySelector(`#icon-templates`)?.content.querySelector(`[data-icon-name="${name}"]`);
    return source ? source.cloneNode(true) : document.createTextNode('');
}

export function el(tag, attributes = {}, children = []) {
    const node = document.createElement(tag);

    Object.entries(attributes).forEach(([key, value]) => {
        if (value === null || value === undefined || value === false) {
            return;
        }
        if (key === 'className') {
            node.className = value;
        } else if (key === 'text') {
            node.textContent = value;
        } else if (key.startsWith('on') && typeof value === 'function') {
            node.addEventListener(key.slice(2).toLowerCase(), value);
        } else if (value === true) {
            node.setAttribute(key, '');
        } else {
            node.setAttribute(key, value);
        }
    });

    [].concat(children).forEach((child) => {
        if (child !== null && child !== undefined) {
            node.append(child instanceof Node ? child : document.createTextNode(String(child)));
        }
    });

    return node;
}

export function button(label, { iconName = null, accessibleName = null, className = 'btn btn-ghost btn-sm', onClick, disabled = false, iconOnly = false } = {}) {
    const node = el('button', { type: 'button', className: iconOnly ? `${className} btn-icon` : className, disabled, 'aria-label': accessibleName, onClick });

    if (iconName) {
        node.append(icon(iconName));
    }
    if (!iconOnly) {
        node.append(document.createTextNode(label));
    } else if (!accessibleName) {
        node.setAttribute('aria-label', label);
    }

    return node;
}

export function formatBytes(bytes) {
    if (bytes < 1024) {
        return `${bytes} B`;
    }

    const units = ['KB', 'MB', 'GB'];
    let value = bytes / 1024;
    let unit = 0;

    while (value >= 1024 && unit < units.length - 1) {
        value /= 1024;
        unit += 1;
    }

    return `${value.toLocaleString('id-ID', { maximumFractionDigits: 1 })} ${units[unit]}`;
}

export function extensionOf(name) {
    const dot = name.lastIndexOf('.');
    return dot === -1 ? '' : name.slice(dot + 1).toLowerCase();
}

export function uid() {
    return Math.random().toString(36).slice(2, 10);
}

export function storage() {
    try {
        const probe = '__konvertin__';
        window.localStorage.setItem(probe, '1');
        window.localStorage.removeItem(probe);
        return window.localStorage;
    } catch (error) {
        return null;
    }
}

/**
 * Shows elements marked data-show-when="field:value" inside a scope, based on the
 * current value of the matching input (options[field] or data-field="field").
 */
export function bindShowWhen(scope) {
    const isForm = scope.matches('form');
    const valueOf = (field) => {
        const selector = isForm ? `[name="options[${field}]"]` : `[data-field="${field}"]`;
        const inputs = scope.querySelectorAll(selector);
        for (const input of inputs) {
            if ((input.type === 'radio' || input.type === 'checkbox') && !input.checked) {
                continue;
            }
            return input.value;
        }
        return null;
    };

    const update = () => {
        scope.querySelectorAll('[data-show-when]').forEach((node) => {
            if (node.closest('template')) {
                return;
            }
            const [field, expected] = node.dataset.showWhen.split(':');
            if (node.closest('[data-step], form') !== scope) {
                return;
            }
            node.hidden = valueOf(field) !== expected;
        });

        scope.querySelectorAll('[data-range-value]').forEach((label) => {
            const input = scope.querySelector(`#${CSS.escape(label.dataset.rangeValue)}`);
            if (input) {
                label.textContent = input.value;
            }
        });
    };

    scope.addEventListener('input', update);
    scope.addEventListener('change', update);
    update();

    return update;
}
