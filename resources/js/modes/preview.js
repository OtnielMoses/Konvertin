/*
 * Page Numbers and Watermark: renders one page and overlays an approximation of
 * the result that follows the settings as they change.
 */

import { el } from '../lib/dom.js';
import { renderPage } from '../lib/pdf.js';

const ROMAN = [[1000, 'm'], [900, 'cm'], [500, 'd'], [400, 'cd'], [100, 'c'], [90, 'xc'], [50, 'l'], [40, 'xl'], [10, 'x'], [9, 'ix'], [5, 'v'], [4, 'iv'], [1, 'i']];

function roman(number) {
    let result = '';
    let rest = number;
    ROMAN.forEach(([value, symbol]) => {
        while (rest >= value) {
            result += symbol;
            rest -= value;
        }
    });
    return result;
}

export default function initPreview(wb) {
    const { form, config } = wb;
    const stage = form.querySelector('[data-live-preview]');
    const canvas = stage.querySelector('[data-page-canvas]');
    const overlay = stage.querySelector('[data-preview-overlay]');
    const status = stage.querySelector('[data-preview-status]');
    const lastPage = form.querySelector('[data-last-page]');
    const imageInput = form.querySelector('[data-aux-input="image"]');
    let doc = null;
    let pageSize = null;
    let renderedPage = 0;
    let imageUrl = null;

    const option = (field) => {
        const checked = form.querySelector(`[name="options[${field}]"]:checked`);
        return checked ? checked.value : form.querySelector(`[name="options[${field}]"]`)?.value ?? '';
    };

    function pageNumberLabel(number, total) {
        const formats = {
            n_of_total: `${number} / ${total}`,
            page_n: `Halaman ${number}`,
            page_n_of_total: `Halaman ${number} dari ${total}`,
            roman_lower: roman(number),
            roman_upper: roman(number).toUpperCase(),
        };
        return option('prefix') + (formats[option('format')] ?? String(number)) + option('suffix');
    }

    async function draw() {
        if (!doc) {
            return;
        }

        const total = doc.numPages;
        const first = Math.min(Math.max(1, Number(option('first_page')) || 1), total);
        const page = config.slug === 'page-numbers' ? first : 1;

        if (page !== renderedPage) {
            const width = Math.min(stage.clientWidth - 32, 520);
            pageSize = await renderPage(doc, page, canvas, { width, maxHeight: Math.max(320, window.innerHeight * 0.7) });
            overlay.style.width = canvas.style.width;
            overlay.style.height = canvas.style.height;
            renderedPage = page;
        }

        const scale = overlay.clientWidth / pageSize.width;
        overlay.replaceChildren();

        if (config.slug === 'page-numbers') {
            const last = Number(lastPage.value) || total;
            const size = (Number(option('size')) || 11) * scale;
            const fonts = { helvetica: 'Helvetica, Arial, sans-serif', times: '"Times New Roman", serif', courier: '"Courier New", monospace' };
            const numbered = (Number(option('start')) || 0) + (last - first);
            const label = el('span', {
                text: pageNumberLabel(Number(option('start')) || 0, numbered),
                style: `position:absolute;${option('position') === 'top' ? 'top' : 'bottom'}:${28 * scale - (option('position') === 'top' ? 0 : size * 0.8)}px;`
                    + `${{ left: 'left:', right: 'right:', center: 'left:50%;transform:translateX(-50%);' }[option('align')] || ''}`
                    + `${option('align') === 'center' ? '' : `${28 * scale}px;`}font-size:${size}px;color:${option('color')};font-family:${fonts[option('font')]};white-space:nowrap`,
            });
            overlay.append(label);
            status.textContent = `Halaman ${first}${last > first ? ` sampai ${last}` : ''} diberi nomor`;
            return;
        }

        // Watermark
        const opacity = (Number(option('opacity')) || 30) / 100;
        const rotation = Number(option('rotation')) || 0;
        const position = option('position');
        const margin = 36 * scale;
        const anchor = {
            center: 'left:50%;top:50%;translate:-50% -50%;',
            'top-left': `left:${margin}px;top:${margin}px;`,
            'top-right': `right:${margin}px;top:${margin}px;`,
            'bottom-left': `left:${margin}px;bottom:${margin}px;`,
            'bottom-right': `right:${margin}px;bottom:${margin}px;`,
        }[position];

        if (option('type') === 'image') {
            if (!imageUrl) {
                status.textContent = 'Pilih gambar watermark untuk melihat pratinjau.';
                return;
            }
            const width = (overlay.clientWidth * (Number(option('size')) || 40)) / 100;
            overlay.append(el('img', { src: imageUrl, alt: '', style: `position:absolute;${anchor}width:${width}px;opacity:${opacity};rotate:${-rotation}deg` }));
        } else {
            overlay.append(el('span', {
                text: option('text') || ' ',
                style: `position:absolute;${anchor}font:700 ${(Number(option('size')) || 48) * scale}px Helvetica, Arial, sans-serif;color:${option('color')};opacity:${opacity};rotate:${-rotation}deg;white-space:nowrap`,
            }));
        }
        status.textContent = 'Halaman 1';
    }

    async function load(entries) {
        stage.hidden = entries.length === 0;
        doc = null;
        renderedPage = 0;
        if (!entries.length) {
            return;
        }
        try {
            doc = await wb.pdf(entries[0]);
            if (lastPage) {
                lastPage.placeholder = String(doc.numPages);
                lastPage.max = String(doc.numPages);
            }
            await draw();
        } catch (error) {
            stage.hidden = true;
            wb.showError(error?.locked ? 'PDF terkunci kata sandi. Buka dulu dengan Unlock PDF.' : 'Pratinjau tidak dapat dimuat.');
        }
    }

    imageInput?.addEventListener('change', () => {
        if (imageUrl) {
            URL.revokeObjectURL(imageUrl);
        }
        imageUrl = imageInput.files[0] ? URL.createObjectURL(imageInput.files[0]) : null;
        draw();
    });

    form.addEventListener('input', draw);
    form.addEventListener('change', draw);
    wb.on('files', load);
}
