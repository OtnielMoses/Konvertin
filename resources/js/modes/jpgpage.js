/*
 * JPG to PDF: each card shows the photo on the page it will become, following the
 * chosen paper size, orientation, margin and fit. The numbers mirror the engine
 * (place_image in konvertin_engine.py): paper sizes in points, margins of 0 / 24 / 48 pt,
 * and images measured at 96 dpi (0.75 pt per pixel) for "actual size".
 */

import { el } from '../lib/dom.js';

const PAPER = {
    a4: [595.28, 841.89],
    f4: [609.45, 935.43],
    letter: [612, 792],
    legal: [612, 1008],
};
const MARGINS = { none: 0, small: 24, large: 48 };

export default function initJpgPage(wb) {
    const { form } = wb;

    const option = (field) => {
        const checked = form.querySelector(`[name="options[${field}]"]:checked`);
        return checked ? checked.value : form.querySelector(`[name="options[${field}]"]`)?.value;
    };

    /** Page size in points and the image box inside it, like the engine computes them. */
    function layout(imageWidth, imageHeight) {
        const margin = MARGINS[option('margin')] ?? 24;
        const naturalWidth = imageWidth * 0.75;
        const naturalHeight = imageHeight * 0.75;
        let pageWidth;
        let pageHeight;

        if (option('page_size') === 'image') {
            pageWidth = naturalWidth + 2 * margin;
            pageHeight = naturalHeight + 2 * margin;
        } else {
            const [short, long] = PAPER[option('page_size')] ?? PAPER.a4;
            const orientation = option('orientation');
            const landscape = orientation === 'landscape' || (orientation !== 'portrait' && imageWidth > imageHeight);
            [pageWidth, pageHeight] = landscape ? [long, short] : [short, long];
        }

        const areaWidth = Math.max(1, pageWidth - 2 * margin);
        const areaHeight = Math.max(1, pageHeight - 2 * margin);
        let scale = Math.min(areaWidth / naturalWidth, areaHeight / naturalHeight);
        if (option('fit') === 'actual' && naturalWidth <= areaWidth && naturalHeight <= areaHeight) {
            scale = 1;
        }

        return {
            pageWidth,
            pageHeight,
            marginX: (margin / pageWidth) * 100,
            marginY: (margin / pageHeight) * 100,
            imageWidth: ((naturalWidth * scale) / areaWidth) * 100,
        };
    }

    function apply(entry) {
        const image = entry.preview;
        if (!image || image.tagName !== 'IMG' || !image.naturalWidth) {
            return;
        }

        let page = image.closest('.page-sim');
        if (!page) {
            const holder = image.parentElement;
            if (!holder) {
                return;
            }
            const area = el('span', { className: 'page-sim-area' });
            page = el('span', { className: 'page-sim', 'aria-hidden': 'true' }, [area]);
            holder.replaceChildren(page);
            area.append(image);
        }

        const box = layout(image.naturalWidth, image.naturalHeight);
        page.classList.toggle('is-landscape', box.pageWidth > box.pageHeight);
        page.style.setProperty('--page-ratio', `${box.pageWidth} / ${box.pageHeight}`);
        page.style.setProperty('--margin-x', `${box.marginX}%`);
        page.style.setProperty('--margin-y', `${box.marginY}%`);
        page.style.setProperty('--image-width', `${Math.min(100, box.imageWidth)}%`);
        page.classList.toggle('has-margin', box.marginX > 0);
        page.title = `${Math.round(box.pageWidth / 72 * 25.4)} × ${Math.round(box.pageHeight / 72 * 25.4)} mm`;
    }

    const applyAll = () => wb.files.forEach(apply);

    wb.on('inspected', apply);
    wb.on('files', () => requestAnimationFrame(applyAll));
    form.addEventListener('change', (event) => {
        if (event.target.closest('.settings')) {
            applyAll();
        }
    });
}
