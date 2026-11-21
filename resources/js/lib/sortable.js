/*
 * Pointer-based sorting for file cards, with a "lifted sheet of paper" feel.
 *
 * Drag start: the card leaves the grid (a placeholder keeps its cell), rises a few
 * pixels, scales up slightly, tilts a little and casts a deeper shadow.
 * During drag: the placeholder moves to the target cell and the other cards slide
 * into place with FLIP, so nothing jumps or resizes.
 * Drop: the card glides into the placeholder's cell and settles, then the caller
 * receives the new position and updates the real order used for processing.
 *
 * Mouse users can drag the whole card; touch and pen users drag by the grip, so the
 * page still scrolls normally. Escape cancels a drag. Buttons on each card offer the
 * same moves for keyboard and screen-reader users.
 */

import { measure, playFrom, reducedMotion } from './motion.js';

const START_DISTANCE = 6;
const DROP_DURATION = 200;
const IGNORE = 'button, a, input, select, textarea, label, [contenteditable]';

export function makeSortable({ root, itemSelector, containerSelector, onDrop }) {
    let pending = null;
    let drag = null;
    let scrollFrame = 0;

    const items = (container) => Array.from(container.querySelectorAll(`:scope > ${itemSelector}`));
    const containers = () => Array.from(root.querySelectorAll(containerSelector)).concat(root.matches(containerSelector) ? [root] : []);
    const movable = () => containers().flatMap((container) => [...items(container), ...container.querySelectorAll(':scope > [data-sort-end], :scope > .sort-placeholder')]);

    function onPointerDown(event) {
        if (event.button !== 0 || drag) {
            return;
        }
        const item = event.target.closest(itemSelector);
        if (!item || !root.contains(item) || item.dataset.sortable === 'false') {
            return;
        }
        const onHandle = Boolean(event.target.closest('[data-drag-handle]'));
        if (event.pointerType !== 'mouse' && !onHandle) {
            return;
        }
        if (!onHandle && event.target.closest(IGNORE)) {
            return;
        }
        if (event.pointerType === 'mouse') {
            event.preventDefault();
        }
        pending = { item, x: event.clientX, y: event.clientY, pointerId: event.pointerId };
        window.addEventListener('pointermove', onPointerMove);
        window.addEventListener('pointerup', onPointerUp);
        window.addEventListener('pointercancel', cancel);
    }

    function start(event) {
        const { item } = pending;
        const rect = item.getBoundingClientRect();
        const placeholder = document.createElement(item.tagName);
        placeholder.className = 'sort-placeholder';
        placeholder.setAttribute('aria-hidden', 'true');
        placeholder.style.height = `${rect.height}px`;

        drag = {
            item,
            rect,
            placeholder,
            origin: { container: item.parentElement, next: item.nextElementSibling },
            offsetX: pending.x - rect.left,
            offsetY: pending.y - rect.top,
            x: event.clientX,
            y: event.clientY,
        };

        item.after(placeholder);
        Object.assign(item.style, {
            position: 'fixed',
            left: `${rect.left}px`,
            top: `${rect.top}px`,
            width: `${rect.width}px`,
            height: `${rect.height}px`,
            margin: '0',
            zIndex: '60',
            pointerEvents: 'none',
        });
        // Next frame so the lift transition runs from the resting state.
        requestAnimationFrame(() => item.classList.add('is-lifted'));
        document.body.classList.add('is-sorting');
        window.addEventListener('keydown', onKey);
        scrollFrame = requestAnimationFrame(autoScroll);
    }

    function onPointerMove(event) {
        if (!drag) {
            if (!pending || Math.hypot(event.clientX - pending.x, event.clientY - pending.y) < START_DISTANCE) {
                return;
            }
            start(event);
        }
        drag.x = event.clientX;
        drag.y = event.clientY;
        drag.item.style.transform = `translate(${drag.x - drag.offsetX - drag.rect.left}px, ${drag.y - drag.offsetY - drag.rect.top}px)`;
        placePlaceholder();
    }

    function containerAt(x, y) {
        const hit = document.elementFromPoint(x, y)?.closest(containerSelector);
        if (hit && root.contains(hit)) {
            return hit;
        }
        // Between lanes: use the nearest container vertically.
        let best = null;
        let distance = Infinity;
        containers().forEach((container) => {
            const box = container.getBoundingClientRect();
            const gap = y < box.top ? box.top - y : y > box.bottom ? y - box.bottom : 0;
            if (gap < distance && x >= box.left - 40 && x <= box.right + 40) {
                best = container;
                distance = gap;
            }
        });
        return best ?? drag.placeholder.parentElement;
    }

    function placePlaceholder() {
        const container = containerAt(drag.x, drag.y);
        const siblings = items(container).filter((node) => node !== drag.item);
        const before = siblings.find((node) => {
            const box = node.getBoundingClientRect();
            if (drag.y < box.top) {
                return true;
            }
            return drag.y <= box.bottom && drag.x < box.left + box.width / 2;
        }) ?? container.querySelector(':scope > [data-sort-end]');

        const { placeholder } = drag;
        if (placeholder.parentElement === container && (before ? placeholder.nextElementSibling === before : placeholder === container.lastElementChild)) {
            return;
        }

        const rects = measure(movable().filter((node) => node !== drag.item));
        if (before) {
            container.insertBefore(placeholder, before);
        } else {
            container.append(placeholder);
        }
        playFrom(rects, { duration: 200 });
    }

    function autoScroll() {
        if (!drag) {
            return;
        }
        const edge = 72;
        if (drag.y < edge) {
            window.scrollBy(0, -Math.ceil((edge - drag.y) / 6));
        } else if (drag.y > window.innerHeight - edge) {
            window.scrollBy(0, Math.ceil((drag.y - window.innerHeight + edge) / 6));
        }
        scrollFrame = requestAnimationFrame(autoScroll);
    }

    function settle(commit) {
        const { item, placeholder, rect, origin } = drag;
        cancelAnimationFrame(scrollFrame);
        window.removeEventListener('keydown', onKey);

        if (!commit) {
            const rects = measure(movable().filter((node) => node !== item));
            origin.container.insertBefore(placeholder, origin.next && origin.next !== placeholder ? origin.next : null);
            playFrom(rects);
        }

        const target = placeholder.getBoundingClientRect();
        const finish = () => {
            placeholder.replaceWith(item);
            item.removeAttribute('style');
            item.classList.remove('is-lifted', 'is-landing');
            document.body.classList.remove('is-sorting');
            const container = item.parentElement;
            drag = null;
            if (commit) {
                onDrop({ item, container, index: items(container).indexOf(item) });
            }
        };

        item.classList.remove('is-lifted');
        if (reducedMotion()) {
            finish();
            return;
        }
        item.classList.add('is-landing');
        item.style.transition = `transform ${DROP_DURATION}ms cubic-bezier(0.2, 0.8, 0.2, 1)`;
        item.style.transform = `translate(${target.left - rect.left}px, ${target.top - rect.top}px)`;
        setTimeout(finish, DROP_DURATION);
    }

    function cleanupListeners() {
        window.removeEventListener('pointermove', onPointerMove);
        window.removeEventListener('pointerup', onPointerUp);
        window.removeEventListener('pointercancel', cancel);
        pending = null;
    }

    function onPointerUp() {
        cleanupListeners();
        if (drag) {
            // A drag must not also count as a click on the card.
            justDragged = true;
            setTimeout(() => {
                justDragged = false;
            }, 0);
            settle(true);
        }
    }

    function cancel() {
        cleanupListeners();
        if (drag) {
            settle(false);
        }
    }

    function onKey(event) {
        if (event.key === 'Escape') {
            event.preventDefault();
            cancel();
        }
    }

    let justDragged = false;
    root.addEventListener('click', (event) => {
        if (justDragged) {
            event.stopPropagation();
            event.preventDefault();
        }
    }, { capture: true });
    root.addEventListener('pointerdown', onPointerDown);

    return {
        get active() {
            return Boolean(drag);
        },
    };
}
