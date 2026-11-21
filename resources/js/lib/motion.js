/*
 * Motion helpers. FLIP keeps layout changes (reorder, add, remove) continuous:
 * record positions, change the DOM, then animate each element from where it was.
 * Every helper becomes a no-op when the visitor asks for reduced motion.
 */

const EASE_OUT = 'cubic-bezier(0.2, 0.8, 0.2, 1)';

export function reducedMotion() {
    return window.matchMedia('(prefers-reduced-motion: reduce)').matches;
}

/** Positions of elements before a DOM change. */
export function measure(elements) {
    const rects = new Map();
    elements.forEach((node) => {
        if (node?.isConnected) {
            rects.set(node, node.getBoundingClientRect());
        }
    });
    return rects;
}

/** Animates elements from their measured positions to their current ones. */
export function playFrom(rects, { duration = 220 } = {}) {
    if (reducedMotion()) {
        return;
    }
    rects.forEach((before, node) => {
        if (!node.isConnected) {
            return;
        }
        const now = node.getBoundingClientRect();
        const dx = before.left - now.left;
        const dy = before.top - now.top;
        if (Math.abs(dx) < 0.5 && Math.abs(dy) < 0.5) {
            return;
        }
        node.animate([{ transform: `translate(${dx}px, ${dy}px)` }, { transform: 'translate(0, 0)' }], { duration, easing: EASE_OUT });
    });
}

export function flip(elements, mutate, options) {
    const rects = measure(elements);
    mutate();
    playFrom(rects, options);
}

/** Fades and shrinks an element out, then removes it and lets its neighbours close the gap. */
export function removeWithMotion(node, neighbours = []) {
    const finish = () => {
        const rects = measure(neighbours);
        node.remove();
        playFrom(rects);
    };

    if (reducedMotion() || !node.isConnected) {
        finish();
        return;
    }

    node.style.pointerEvents = 'none';
    node.animate([{ opacity: 1, transform: 'scale(1)' }, { opacity: 0, transform: 'scale(0.94)' }], { duration: 160, easing: 'ease-in', fill: 'forwards' })
        .finished.then(finish, finish);
}
