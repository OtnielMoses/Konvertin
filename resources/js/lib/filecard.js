/*
 * Square document cards shared by every tool workspace and the Assignment Pack board.
 * A card shows a proportional preview (first PDF page or the image itself, never
 * stretched), the file name, format, size, page count and status, plus a grip for
 * dragging and buttons that do the same moves without a pointer.
 */

import { button, el, extensionOf, formatBytes, icon } from './dom.js';
import { renderPage } from './pdf.js';

const WORD = ['doc', 'docx'];
const IMAGES = ['jpg', 'jpeg', 'png'];

export function fileKind(entry) {
    const extension = extensionOf(entry.file.name);
    if (WORD.includes(extension)) {
        return 'word';
    }
    if (IMAGES.includes(extension)) {
        return 'image';
    }
    return extension === 'pdf' ? 'pdf' : 'other';
}

function describe(entry) {
    const parts = [extensionOf(entry.file.name).toUpperCase(), formatBytes(entry.file.size)];
    if (entry.pages) {
        parts.push(`${entry.pages} halaman`);
    }
    return parts.join(' · ');
}

function status(entry) {
    if (entry.locked) {
        return { tone: 'warning', text: 'Terkunci kata sandi' };
    }
    if (entry.unreadable) {
        return { tone: 'error', text: 'Tidak terbaca' };
    }
    if (fileKind(entry) === 'word') {
        return { tone: 'neutral', text: 'Diubah ke PDF saat diproses' };
    }
    return entry.inspected ? { tone: 'success', text: 'Siap' } : { tone: 'neutral', text: 'Membaca berkas' };
}

/**
 * Reads page count and draws the preview once per file; the result is kept on the
 * entry so re-rendering or moving a card never redraws it.
 */
async function inspect(entry, wb) {
    entry.previewPromise ??= (async () => {
        const kind = fileKind(entry);
        if (kind === 'image') {
            entry.preview = el('img', { src: URL.createObjectURL(entry.file), alt: '', draggable: 'false' });
            await entry.preview.decode().catch(() => {
                entry.unreadable = true;
            });
        } else if (kind === 'pdf') {
            try {
                const doc = await wb.pdf(entry);
                entry.pages = doc.numPages;
                const canvas = el('canvas');
                await renderPage(doc, 1, canvas, { width: 200, maxHeight: 200 });
                entry.preview = canvas;
            } catch (error) {
                if (error?.locked) {
                    entry.locked = true;
                } else {
                    entry.unreadable = true;
                }
            }
        }
        entry.inspected = true;
    })();
    return entry.previewPromise;
}

export function createFileCard(entry, wb, { onRemove, onMove, extra = null, removeVerb = 'Hapus' }) {
    const kind = fileKind(entry);
    const name = entry.file.name;
    const order = el('span', { className: 'file-order', 'aria-hidden': 'true' });
    const preview = el('div', { className: `file-preview is-loading kind-${kind}` }, [
        icon(kind === 'word' ? 'doc-word' : kind === 'image' ? 'image' : 'page'),
        kind === 'word' ? el('span', { className: 'file-preview-tag', text: extensionOf(name).toUpperCase() }) : null,
    ]);
    const meta = el('p', { className: 'file-meta', text: describe(entry) });
    const pill = el('span', { className: 'file-status' });
    const back = button('Geser ke kiri', { iconName: 'chevron-left', iconOnly: true, accessibleName: `Pindahkan ${name} satu posisi ke depan`, onClick: () => onMove(-1) });
    const forward = button('Geser ke kanan', { iconName: 'chevron-right', iconOnly: true, accessibleName: `Pindahkan ${name} satu posisi ke belakang`, onClick: () => onMove(1) });
    back.dataset.move = 'back';
    forward.dataset.move = 'forward';

    const node = el('li', { className: 'file-item is-entering', 'data-id': entry.id }, [
        el('article', { className: 'file-card' }, [
            el('div', { className: 'file-card-thumb' }, [
                preview,
                order,
                el('span', { className: 'file-grip', 'data-drag-handle': true, title: 'Seret untuk memindahkan', 'aria-hidden': 'true' }, [icon('grip')]),
                button(removeVerb, { iconName: 'x', iconOnly: true, className: 'btn file-remove', accessibleName: `${removeVerb} ${name}`, onClick: onRemove }),
            ]),
            el('div', { className: 'file-card-body' }, [
                el('p', { className: 'file-name', title: name, text: name }),
                meta,
                el('div', { className: 'file-card-foot' }, [pill, el('span', { className: 'file-moves' }, [back, forward])]),
                extra,
            ]),
        ]),
    ]);
    node.addEventListener('animationend', () => node.classList.remove('is-entering'), { once: true });

    const refreshStatus = () => {
        const current = status(entry);
        pill.className = `file-status tone-${current.tone}`;
        pill.textContent = current.text;
        meta.textContent = describe(entry);
    };

    refreshStatus();
    inspect(entry, wb).then(() => {
        if (entry.preview) {
            preview.replaceChildren(entry.preview);
            if (!entry.previewShown) {
                entry.previewShown = true;
                entry.preview.animate?.([{ opacity: 0 }, { opacity: 1 }], { duration: 220, easing: 'ease-out' });
            }
        }
        preview.classList.remove('is-loading');
        refreshStatus();
        wb.hooks?.inspected?.forEach((callback) => callback(entry));
    });

    return {
        node,
        update({ index, total, label = null, orderable = true }) {
            order.textContent = label ?? String(index + 1);
            order.hidden = !orderable && label === null;
            back.disabled = index === 0;
            forward.disabled = index === total - 1;
            node.querySelector('.file-moves').hidden = !orderable;
            node.querySelector('.file-grip').hidden = !orderable;
            node.dataset.sortable = orderable ? 'true' : 'false';
        },
        refreshStatus,
    };
}
