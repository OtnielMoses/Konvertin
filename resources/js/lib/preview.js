/*
 * Result preview shown before download, so the user can check the output first.
 * Every page is stacked in a scrollable column (rendered as it scrolls into view),
 * like reading the document. The preview endpoint serves the finished file to the
 * owner's session without deleting it; the one-time download link stays valid.
 */

import { el } from './dom.js';
import { lazyRender, openPdf, renderPage } from './pdf.js';

const TEXT_LIMIT = 30000;

let session = 0;
let stopPages = () => {};

function parts(section) {
    return {
        count: section.querySelector('[data-preview-count]'),
        pages: section.querySelector('[data-preview-pages]'),
        text: section.querySelector('[data-preview-text]'),
        note: section.querySelector('[data-preview-note]'),
    };
}

/** The preview scrolls inside its own column on desktop and with the page on small screens. */
function scrollRoot(section) {
    const style = getComputedStyle(section);
    return /(auto|scroll)/.test(style.overflowY) ? section : null;
}

export function hideResultPreview(section) {
    session += 1;
    stopPages();
    if (section) {
        section.hidden = true;
        parts(section).pages.replaceChildren();
    }
}

/**
 * @param {(pages: number) => void} onPages called with the page count once known
 */
export async function showResultPreview(section, payload, onPages = () => {}) {
    hideResultPreview(section);
    if (!section || !payload.preview_url) {
        return;
    }

    const id = session;
    const ui = parts(section);
    ui.text.hidden = true;
    ui.count.textContent = 'Memuat pratinjau…';
    ui.note.textContent = 'Gulir untuk memeriksa semua halaman. Pratinjau tidak menghapus berkas, dan tautan unduh tetap berlaku satu kali.';
    section.hidden = false;

    try {
        const response = await fetch(payload.preview_url, { credentials: 'same-origin' });
        if (!response.ok) {
            throw new Error(`preview ${response.status}`);
        }

        if (payload.preview_type === 'image') {
            const blob = await response.blob();
            if (id !== session) {
                return;
            }
            const url = URL.createObjectURL(blob);
            const image = el('img', { src: url, alt: 'Pratinjau gambar hasil', className: 'preview-image' });
            image.addEventListener('load', () => {
                ui.count.textContent = `${image.naturalWidth} × ${image.naturalHeight} piksel`;
            }, { once: true });
            ui.pages.replaceChildren(el('figure', { className: 'preview-sheet' }, [image]));
            stopPages = () => URL.revokeObjectURL(url);
            return;
        }

        if (payload.preview_type === 'text') {
            const text = await response.text();
            if (id !== session) {
                return;
            }
            ui.text.textContent = text.length > TEXT_LIMIT
                ? `${text.slice(0, TEXT_LIMIT)}\n\n… Pratinjau dipotong. Isi lengkap ada di berkas unduhan.`
                : text;
            ui.text.hidden = false;
            ui.count.textContent = `${text.split('\n').length} baris`;
            ui.note.textContent = 'Pratinjau tidak menghapus berkas, dan tautan unduh tetap berlaku satu kali.';
            return;
        }

        const doc = await openPdf(await response.blob());
        if (id !== session) {
            return;
        }

        const total = doc.numPages;
        ui.count.textContent = `${total} halaman`;
        onPages(total);

        const width = () => Math.min(ui.pages.clientWidth - 8, 900);
        const frames = [];
        for (let number = 1; number <= total; number += 1) {
            const page = await doc.getPage(number);
            const viewport = page.getViewport({ scale: 1 });
            const frame = el('figure', { className: 'preview-sheet is-loading', 'data-page': String(number) }, [
                el('div', { className: 'preview-sheet-paper', style: `aspect-ratio: ${viewport.width} / ${viewport.height}` }),
                el('figcaption', { text: `Halaman ${number} dari ${total}` }),
            ]);
            frames.push(frame);
        }
        ui.pages.replaceChildren(...frames);

        stopPages = lazyRender(frames, async (frame) => {
            const canvas = el('canvas');
            await renderPage(doc, Number(frame.dataset.page), canvas, { width: width() });
            if (id !== session) {
                return;
            }
            frame.firstChild.append(canvas);
            frame.classList.remove('is-loading');
        }, { root: scrollRoot(section), rootMargin: '1200px 0px' });
    } catch (error) {
        if (id !== session) {
            return;
        }
        ui.pages.replaceChildren();
        ui.count.textContent = '';
        ui.note.textContent = 'Pratinjau tidak dapat dimuat. Anda tetap dapat mengunduh hasilnya.';
    }
}
