/*
 * Sign PDF: create a visual signature by drawing, typing, or uploading an image,
 * then place it on the current page through the shared editor.
 */

export default function initSignature(wb) {
    const { form } = wb;
    const maker = form.querySelector('[data-signature-maker]');
    const tabs = Array.from(maker.querySelectorAll('[data-sig-tab]'));
    const panels = Array.from(maker.querySelectorAll('[data-sig-panel]'));
    const pad = maker.querySelector('[data-sig-pad]');
    const nameInput = maker.querySelector('[data-sig-name]');
    const typed = maker.querySelector('[data-sig-typed]');
    const fileInput = maker.querySelector('[data-sig-file]');
    const context = pad.getContext('2d');
    let active = 'draw';
    let drawn = false;
    let uploaded = null;
    let last = null;

    function activate(name, focus = false) {
        active = name;
        tabs.forEach((tab) => {
            const selected = tab.dataset.sigTab === name;
            tab.setAttribute('aria-selected', String(selected));
            tab.tabIndex = selected ? 0 : -1;
            if (selected && focus) {
                tab.focus();
            }
        });
        panels.forEach((panel) => {
            panel.hidden = panel.dataset.sigPanel !== name;
        });
    }

    tabs.forEach((tab, index) => {
        tab.addEventListener('click', () => activate(tab.dataset.sigTab));
        tab.addEventListener('keydown', (event) => {
            const delta = { ArrowRight: 1, ArrowLeft: -1 }[event.key];
            if (delta) {
                event.preventDefault();
                activate(tabs[(index + delta + tabs.length) % tabs.length].dataset.sigTab, true);
            }
        });
    });

    function padPoint(event) {
        const box = pad.getBoundingClientRect();
        return [((event.clientX - box.left) / box.width) * pad.width, ((event.clientY - box.top) / box.height) * pad.height];
    }

    context.lineWidth = 3;
    context.lineCap = 'round';
    context.lineJoin = 'round';
    context.strokeStyle = '#111827';

    pad.addEventListener('pointerdown', (event) => {
        pad.setPointerCapture(event.pointerId);
        last = padPoint(event);
    });
    pad.addEventListener('pointermove', (event) => {
        if (!last) {
            return;
        }
        const point = padPoint(event);
        context.beginPath();
        context.moveTo(...last);
        context.lineTo(...point);
        context.stroke();
        last = point;
        drawn = true;
    });
    pad.addEventListener('pointerup', () => {
        last = null;
    });

    maker.querySelector('[data-sig-clear]').addEventListener('click', () => {
        context.clearRect(0, 0, pad.width, pad.height);
        drawn = false;
    });

    nameInput.addEventListener('input', () => {
        typed.textContent = nameInput.value;
    });

    fileInput.addEventListener('change', () => {
        const file = fileInput.files[0];
        uploaded = null;
        if (!file) {
            return;
        }
        if (!['image/png', 'image/jpeg'].includes(file.type) || file.size > 2 * 1024 * 1024) {
            wb.showError('Gunakan gambar PNG atau JPG di bawah 2 MB.');
            fileInput.value = '';
            return;
        }
        const reader = new FileReader();
        reader.onload = () => {
            uploaded = reader.result;
        };
        reader.readAsDataURL(file);
    });

    /** Crops the drawing to its ink so the placed signature has no empty margin. */
    function trimmedPad() {
        const { width, height } = pad;
        const pixels = context.getImageData(0, 0, width, height).data;
        let x0 = width;
        let y0 = height;
        let x1 = 0;
        let y1 = 0;
        for (let y = 0; y < height; y += 1) {
            for (let x = 0; x < width; x += 1) {
                if (pixels[(y * width + x) * 4 + 3] > 0) {
                    x0 = Math.min(x0, x);
                    y0 = Math.min(y0, y);
                    x1 = Math.max(x1, x);
                    y1 = Math.max(y1, y);
                }
            }
        }
        const padding = 6;
        const crop = document.createElement('canvas');
        crop.width = Math.max(1, x1 - x0 + padding * 2);
        crop.height = Math.max(1, y1 - y0 + padding * 2);
        crop.getContext('2d').drawImage(pad, x0 - padding, y0 - padding, crop.width, crop.height, 0, 0, crop.width, crop.height);
        return crop.toDataURL('image/png');
    }

    function typedImage(text) {
        const canvas = document.createElement('canvas');
        const context2 = canvas.getContext('2d');
        const font = 'italic 600 72px Montserrat, "Segoe UI", sans-serif';
        context2.font = font;
        canvas.width = Math.ceil(context2.measureText(text).width) + 24;
        canvas.height = 100;
        context2.font = font;
        context2.fillStyle = '#111827';
        context2.textBaseline = 'middle';
        context2.fillText(text, 12, 52);
        return canvas.toDataURL('image/png');
    }

    maker.querySelector('[data-sig-place]').addEventListener('click', () => {
        if (!wb.editor?.ready()) {
            wb.showError('Pilih PDF terlebih dahulu.');
            return;
        }

        let data = null;
        if (active === 'draw' && drawn) {
            data = trimmedPad();
        } else if (active === 'type' && nameInput.value.trim()) {
            data = typedImage(nameInput.value.trim());
        } else if (active === 'upload' && uploaded) {
            data = uploaded;
        }

        if (!data) {
            wb.showError(active === 'draw' ? 'Gambar tanda tangan terlebih dahulu.' : active === 'type' ? 'Ketik nama terlebih dahulu.' : 'Pilih gambar tanda tangan terlebih dahulu.');
            return;
        }

        wb.showError('');
        wb.editor.placeSignature(data);
    });
}
