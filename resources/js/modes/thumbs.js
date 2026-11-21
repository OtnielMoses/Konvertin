/*
 * Page thumbnails that reflect the current settings. Split shows which output file
 * each page lands in; Rotate shows the new orientation; PDF to JPG marks the pages
 * to export with their exact pixel size; OCR PDF marks pages without a text layer.
 */

import { el } from '../lib/dom.js';
import { lazyRender, renderPage } from '../lib/pdf.js';

function parsePages(spec, total) {
    const pages = [];
    for (const part of String(spec || '').replace(/\s/g, '').split(',')) {
        if (!part) {
            continue;
        }
        const match = part.match(/^(\d+)(?:-(\d+))?$/);
        if (!match) {
            return null;
        }
        const start = Number(match[1]);
        const end = Number(match[2] || start);
        if (start < 1 || end < start || end > total) {
            return null;
        }
        for (let page = start; page <= end; page += 1) {
            pages.push(page);
        }
    }
    return pages.length ? pages : null;
}

function value(form, field) {
    const checked = form.querySelector(`[name="options[${field}]"]:checked`);
    return checked ? checked.value : form.querySelector(`[name="options[${field}]"]`)?.value;
}

export default function initThumbs(wb) {
    const { form, config } = wb;
    const stage = form.querySelector('[data-thumbs]');
    const grid = form.querySelector('[data-page-grid]');
    const status = form.querySelector('[data-thumbs-status]');
    const summary = form.querySelector('[data-split-summary]');
    const exportInfo = form.querySelector('[data-export-info]');
    let total = 0;
    let frames = [];
    let disconnect = () => {};
    let doc = null;
    let textless = null;
    let infoRun = 0;

    function selectedPages() {
        if (value(form, 'scope') !== 'pages') {
            return Array.from({ length: total }, (_, index) => index + 1);
        }
        return parsePages(value(form, 'pages'), total);
    }

    function markSelection() {
        const pages = selectedPages();
        frames.forEach(({ node }, index) => {
            node.classList.toggle('is-selected', Boolean(pages?.includes(index + 1)));
            node.classList.toggle('is-dim', Boolean(pages) && !pages.includes(index + 1));
        });
        return pages;
    }

    /** Exact output size: page size in points × DPI / 72, as the server renders it. */
    async function describeExport(pages) {
        const run = ++infoRun;
        if (!pages) {
            exportInfo.textContent = `Periksa nomor halaman. Dokumen ini memiliki ${total} halaman.`;
            return;
        }
        const dpi = Number(value(form, 'dpi')) || 150;
        const format = (value(form, 'format') || 'jpg').toUpperCase();
        const page = await doc.getPage(pages[0]);
        if (run !== infoRun) {
            return;
        }
        const viewport = page.getViewport({ scale: dpi / 72 });
        const pixels = `${Math.ceil(viewport.width)} × ${Math.ceil(viewport.height)} piksel`;
        exportInfo.textContent = `${pages.length} halaman menjadi ${pages.length} gambar ${format}${pages.length > 1 ? ' dalam satu ZIP' : ''}. Halaman ${pages[0]}: ${pixels} pada ${dpi} DPI.`
            + (dpi >= 600 ? ' 600 DPI menghasilkan berkas sangat besar; pakai hanya untuk cetak detail.' : '')
            + ' Ukuran berkas sebenarnya tampil setelah diproses.';
    }

    /** Pages whose text layer is (almost) empty, read from the PDF itself. */
    async function findTextless() {
        textless = new Set();
        for (let number = 1; number <= total; number += 1) {
            const page = await doc.getPage(number);
            const content = await page.getTextContent();
            const text = content.items.map((item) => item.str || '').join('').replace(/\s/g, '');
            const frame = frames[number - 1];
            if (!frame) {
                return;
            }
            if (text.length < 20) {
                textless.add(number);
            }
            frame.label.hidden = false;
            frame.label.textContent = text.length < 20 ? 'Perlu OCR' : 'Ada teks';
            frame.label.classList.toggle('is-warn', text.length < 20);
        }
        update();
    }

    function splitGroups() {
        const mode = value(form, 'mode');
        if (mode === 'every') {
            return Array.from({ length: total }, (_, index) => [index + 1]);
        }
        if (mode === 'chunk') {
            const size = Math.max(1, Number(value(form, 'chunk')) || 1);
            const groups = [];
            for (let start = 1; start <= total; start += size) {
                groups.push(Array.from({ length: Math.min(size, total - start + 1) }, (_, index) => start + index));
            }
            return groups;
        }
        if (mode === 'pages') {
            const pages = parsePages(value(form, 'pages'), total);
            return pages ? [Array.from(new Set(pages)).sort((a, b) => a - b)] : null;
        }
        const groups = String(value(form, 'ranges') || '').split(',').filter((part) => part.trim()).map((part) => parsePages(part, total));
        return groups.length && groups.every(Boolean) ? groups : null;
    }

    function update() {
        if (!total) {
            return;
        }

        if (config.slug === 'split-pdf') {
            const groups = splitGroups();
            frames.forEach(({ node, label }, index) => {
                const group = groups ? groups.findIndex((pages) => pages.includes(index + 1)) : -1;
                node.classList.toggle('is-dim', group === -1);
                label.textContent = group === -1 ? '' : `Berkas ${group + 1}`;
                label.hidden = group === -1;
            });
            summary.textContent = groups
                ? `Hasil: ${groups.length} berkas${groups.length > 1 ? ' dalam satu ZIP' : ''}.`
                : `Periksa nomor halaman. Dokumen ini memiliki ${total} halaman.`;
        }

        if (config.slug === 'pdf-to-jpg') {
            describeExport(markSelection());
        }

        if (config.slug === 'ocr-pdf') {
            const pages = markSelection();
            if (!textless) {
                status.textContent = `${total} halaman · memeriksa lapisan teks…`;
            } else if (!pages) {
                status.textContent = `Periksa nomor halaman. Dokumen ini memiliki ${total} halaman.`;
            } else {
                const todo = pages.filter((page) => textless.has(page)).length;
                status.textContent = todo
                    ? `${todo} dari ${pages.length} halaman terpilih perlu OCR.`
                    : `Halaman terpilih sudah punya teks. OCR tidak diperlukan.`;
            }
        }

        if (config.slug === 'rotate-pdf') {
            const angle = Number(value(form, 'angle')) || 0;
            const scope = value(form, 'scope');
            const pages = scope === 'pages' ? parsePages(value(form, 'pages'), total) || [] : null;
            frames.forEach(({ node, canvas }, index) => {
                const affected = pages === null || pages.includes(index + 1);
                canvas.style.transform = affected ? `rotate(${angle}deg) scale(${angle % 180 ? 0.75 : 1})` : '';
                node.classList.toggle('is-selected', affected);
            });
        }
    }

    async function load(entries) {
        disconnect();
        grid.replaceChildren();
        frames = [];
        total = 0;
        stage.hidden = entries.length === 0;
        textless = null;
        if (exportInfo) {
            exportInfo.textContent = '';
        }
        if (!entries.length) {
            return;
        }

        status.textContent = 'Memuat halaman...';

        try {
            doc = await wb.pdf(entries[0]);
            total = doc.numPages;
            status.textContent = `${total} halaman`;

            for (let number = 1; number <= total; number += 1) {
                const canvas = el('canvas');
                const label = el('span', { className: 'group-tag', hidden: true });
                const node = el('div', { className: 'page-thumb', 'data-page': number }, [
                    el('div', { className: 'page-frame' }, [canvas]),
                    el('div', { className: 'page-label' }, [el('span', { text: `Hal. ${number}` }), label]),
                ]);
                grid.append(node);
                frames.push({ node, canvas, label });
            }

            disconnect = lazyRender(frames.map((frame) => frame.node), (node) => {
                const frame = frames[Number(node.dataset.page) - 1];
                renderPage(doc, Number(node.dataset.page), frame.canvas, { width: 100 });
            });

            update();
            if (config.slug === 'ocr-pdf') {
                findTextless();
            }
        } catch (error) {
            status.textContent = error?.locked ? 'PDF terkunci kata sandi. Buka dulu dengan Unlock PDF.' : 'Pratinjau tidak dapat dimuat.';
        }
    }

    wb.on('files', load);
    // Files chosen before this module finished loading.
    if (wb.files.length) {
        load(wb.files);
    }
    form.addEventListener('input', update);
    form.addEventListener('change', update);
}
