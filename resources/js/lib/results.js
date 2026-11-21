/*
 * Result views for OCR PDF (recognised text with the engine's own confidence,
 * editable per line) and PDF to JPG (gallery of the real output images).
 */

import { el, formatBytes, icon } from './dom.js';

const percent = (value) => `${Math.round(value * 100)}%`;

/**
 * @param {object} report OCR report from the engine.
 * @param {(corrections: Record<string, Record<string, string>>) => void} onApply
 */
export function renderOcrText(report, onApply) {
    const pages = report.pages || [];
    const threshold = report.review_score ?? 0.75;
    const inputs = [];

    const root = el('section', { className: 'ocr-text' }, [
        el('div', { className: 'checks-head' }, [
            el('h3', { text: 'Teks hasil OCR' }),
            el('span', { className: `checks-count${report.review_pages?.length ? ' is-warn' : ''}`, text: report.review_pages?.length ? `${report.review_pages.length} halaman perlu diperiksa` : 'Tidak ada halaman yang ditandai' }),
        ]),
        el('p', { className: 'field-hint', text: `Skor adalah keyakinan mesin ${report.engine ?? 'OCR'} untuk setiap baris (0-100%). Baris di bawah ${percent(threshold)} disorot. Perbaiki teks yang salah, lalu terapkan agar lapisan teks PDF memakai teks Anda.` }),
    ]);

    pages.forEach((page) => {
        const summary = el('summary', {}, [
            el('strong', { text: `Halaman ${page.page}` }),
            el('span', { className: 'muted', text: ` · ${page.words} kata · ${page.line_count} baris${page.mean_score === null ? '' : ` · rata-rata ${percent(page.mean_score)}`}` }),
            page.review ? el('span', { className: 'queue-status is-failed', text: 'Perlu diperiksa' }) : null,
        ]);
        const body = page.lines
            ? el('ol', { className: 'ocr-lines' }, page.lines.map((line, index) => {
                // A correction applied earlier stays visible and is sent again on the next run.
                const input = el('input', { type: 'text', className: 'input', value: line.applied ?? line.text, 'aria-label': `Halaman ${page.page}, baris ${index + 1}` });
                input.dataset.page = String(page.page);
                input.dataset.line = String(index);
                input.dataset.original = line.text;
                inputs.push(input);
                return el('li', { className: `${line.score < threshold ? 'is-low' : ''}${line.applied ? ' is-corrected' : ''}`, title: line.applied ? `Dikenali mesin: ${line.text}` : null }, [
                    input,
                    el('span', { className: 'ocr-score', title: 'Keyakinan mesin', text: percent(line.score) }),
                ]);
            }))
            : el('p', { className: 'field-hint', text: 'Teks halaman ini tidak ditampilkan karena dokumen sangat panjang. Lapisan teksnya tetap ada di PDF.' });
        root.append(el('details', { className: 'ocr-page', open: page.review || pages.length === 1 }, [summary, body]));
    });

    if (inputs.length) {
        const status = el('p', { className: 'field-hint', 'aria-live': 'polite' });
        const apply = el('button', { type: 'button', className: 'btn btn-secondary', disabled: true }, [icon('refresh'), 'Terapkan koreksi dan proses ulang']);
        const changed = () => inputs.filter((input) => input.value.trim() !== input.dataset.original && input.value.trim() !== '');
        const edited = () => inputs.filter((input) => input.value !== input.defaultValue);
        root.addEventListener('input', () => {
            const count = edited().length;
            apply.disabled = count === 0;
            status.textContent = count ? `${count} baris diubah.` : '';
        });
        apply.addEventListener('click', () => {
            const corrections = {};
            changed().forEach((input) => {
                corrections[input.dataset.page] ??= {};
                corrections[input.dataset.page][input.dataset.line] = input.value.trim().slice(0, 300);
            });
            onApply(corrections);
        });
        root.append(el('div', { className: 'ocr-apply' }, [apply, status]));
    }

    return root;
}

/** Gallery of exported images, loaded from the finished ZIP one by one. */
export function renderImageGallery(payload) {
    const report = payload.report || {};
    const images = report.images || [];
    const memberUrl = (index, download = false) => `${payload.member_base}/${index}${download ? '?unduh=1' : ''}`;

    return el('section', { className: 'image-gallery' }, [
        el('div', { className: 'checks-head' }, [
            el('h3', { text: 'Gambar hasil' }),
            el('span', { className: 'muted', text: `${images.length} gambar · ${formatBytes(report.total_size || 0)}` }),
        ]),
        el('ul', { className: 'gallery-grid' }, images.map((image, index) => el('li', { className: 'gallery-item' }, [
            payload.member_base
                ? el('img', { src: memberUrl(index), alt: `Halaman ${image.page}`, loading: 'lazy', width: String(image.width), height: String(image.height) })
                : null,
            el('p', { className: 'gallery-name', text: image.name }),
            el('p', { className: 'gallery-meta', text: `${image.width} × ${image.height} px · ${formatBytes(image.size)}` }),
            payload.member_base ? el('a', { className: 'btn btn-ghost btn-sm', href: memberUrl(index, true) }, [icon('download'), 'Unduh gambar ini']) : null,
        ]))),
        el('p', { className: 'field-hint', text: 'Unduhan per gambar tidak menghapus hasil. Tombol unduh utama mengirim semua gambar dalam satu ZIP, lalu hasil dihapus dari server.' }),
    ]);
}
