/*
 * Page editor shared by Edit PDF, Sign PDF, Redact PDF, Crop PDF and PDF Forms.
 * Objects are stored in normalised page coordinates (0..1 of the page as displayed),
 * which the Python engine maps back to PDF space, including rotated pages.
 */

import { button, el, icon, uid } from '../lib/dom.js';
import { renderPage } from '../lib/pdf.js';

const SVG = 'http://www.w3.org/2000/svg';
const DRAG_TYPES = ['rect', 'ellipse', 'highlight', 'redact', 'crop', 'field-text', 'field-checkbox', 'line'];
const RESIZABLE = ['text', 'rect', 'ellipse', 'highlight', 'redact', 'crop', 'image', 'signature', 'field-text', 'field-checkbox'];
const LABELS = {
    text: 'Teks', rect: 'Kotak', ellipse: 'Elips', line: 'Garis', ink: 'Coretan', highlight: 'Highlight', note: 'Komentar',
    image: 'Gambar', signature: 'Tanda tangan', redact: 'Area redaksi', crop: 'Area potong', 'field-text': 'Field teks', 'field-checkbox': 'Checkbox',
};

const clamp = (value) => Math.min(1, Math.max(0, value));

function svgNode(tag, attributes) {
    const node = document.createElementNS(SVG, tag);
    Object.entries(attributes).forEach(([key, value]) => node.setAttribute(key, value));
    return node;
}

function boundsOf(points) {
    const xs = points.map((point) => point[0]);
    const ys = points.map((point) => point[1]);
    return [Math.min(...xs), Math.min(...ys), Math.max(...xs), Math.max(...ys)];
}

export default function initEditor(wb) {
    const { form, config } = wb;
    const slug = config.slug;
    const root = form.querySelector('[data-editor]');
    const stage = root.querySelector('[data-editor-stage]');
    const canvas = root.querySelector('[data-page-canvas]');
    const overlay = root.querySelector('[data-overlay]');
    const indicator = root.querySelector('[data-page-indicator]');
    const prev = root.querySelector('[data-page-prev]');
    const next = root.querySelector('[data-page-next]');
    const modeButtons = Array.from(root.querySelectorAll('[data-editor-mode]'));
    const imageInput = root.querySelector('[data-editor-image]');
    const outputs = Array.from(form.querySelectorAll('[data-editor-output]'));
    const countText = form.querySelector('[data-editor-count]');
    const objectList = form.querySelector('[data-object-list]');
    const props = form.querySelector('[data-editor-props]');
    const cropBox = form.querySelector('[data-crop-box]');
    const formFields = root.querySelector('[data-form-fields]');

    let doc = null;
    let pageIndex = 0;
    let pageSize = { width: 595, height: 842 };
    let mode = modeButtons[0]?.dataset.editorMode ?? 'select';
    let objects = [];
    let selectedId = null;
    let interaction = null;
    let fieldSerial = 0;
    let detectedFields = {};

    // ------------------------------------------------------------- render

    function fontPixels(points) {
        return (points / pageSize.height) * overlay.clientHeight;
    }

    function objectNode(object) {
        const [x0, y0, x1, y1] = object.rect;
        const node = el('div', {
            className: `obj obj-${object.type}${object.id === selectedId ? ' is-selected' : ''}`,
            tabindex: '0',
            role: 'button',
            'aria-label': `${LABELS[object.type]}${object.text ? `: ${object.text}` : ''}. Tombol panah untuk menggeser, Delete untuk menghapus.`,
            'data-id': object.id,
            style: `left:${x0 * 100}%;top:${y0 * 100}%;width:${(x1 - x0) * 100}%;height:${(y1 - y0) * 100}%`,
        });

        const color = object.color || '#d94832';
        const stroke = Math.max(1, (object.width || 2) * (overlay.clientWidth / pageSize.width));

        if (object.type === 'text') {
            node.append(el('div', { className: 'obj-text', text: object.text, style: `color:${color};font-size:${fontPixels(object.fontsize || 14)}px` }));
        } else if (object.type === 'rect' || object.type === 'ellipse') {
            const svg = svgNode('svg', { preserveAspectRatio: 'none' });
            const shape = object.type === 'rect'
                ? svgNode('rect', { x: '0', y: '0', width: '100%', height: '100%' })
                : svgNode('ellipse', { cx: '50%', cy: '50%', rx: '50%', ry: '50%' });
            shape.setAttribute('fill', 'none');
            shape.setAttribute('stroke', color);
            shape.setAttribute('stroke-width', String(stroke));
            svg.append(shape);
            node.append(svg);
        } else if (object.type === 'line' || object.type === 'ink') {
            const width = Math.max(x1 - x0, 0.0001);
            const height = Math.max(y1 - y0, 0.0001);
            const svg = svgNode('svg', { viewBox: `${x0} ${y0} ${width} ${height}`, preserveAspectRatio: 'none' });
            const strokes = object.type === 'line' ? [object.points] : object.strokes;
            strokes.forEach((points) => {
                svg.append(svgNode('polyline', {
                    points: points.map((point) => point.join(',')).join(' '),
                    fill: 'none', stroke: color, 'stroke-width': String(stroke), 'stroke-linecap': 'round', 'stroke-linejoin': 'round',
                    'vector-effect': 'non-scaling-stroke',
                }));
            });
            node.append(svg);
        } else if (object.type === 'note') {
            node.append(icon('note'));
        } else if (object.type === 'image' || object.type === 'signature') {
            node.append(el('img', { src: object.data, alt: '' }));
        } else if (object.type === 'field-text' || object.type === 'field-checkbox') {
            node.append(el('span', { className: 'field-hint', text: object.name, style: 'padding:2px 4px;display:block' }));
        }

        if (RESIZABLE.includes(object.type)) {
            node.append(el('span', { className: 'handle', 'data-handle': 'resize', 'aria-hidden': 'true' }));
        }

        node.addEventListener('keydown', (event) => onObjectKey(event, object));
        node.addEventListener('focus', () => select(object.id, false));

        return node;
    }

    function renderObjects() {
        overlay.replaceChildren(...objects.filter((object) => object.page === pageIndex).map(objectNode));
        if (interaction?.draft) {
            overlay.append(objectNode(interaction.draft));
        }
    }

    async function renderCurrentPage() {
        if (!doc) {
            return;
        }
        const width = Math.min(stage.clientWidth - 32, 860);
        pageSize = await renderPage(doc, pageIndex + 1, canvas, { width, maxHeight: Math.max(360, window.innerHeight * 0.75) });
        overlay.style.width = canvas.style.width;
        overlay.style.height = canvas.style.height;
        indicator.textContent = `Halaman ${pageIndex + 1} dari ${doc.numPages}`;
        prev.disabled = pageIndex === 0;
        next.disabled = pageIndex >= doc.numPages - 1;
        renderObjects();
    }

    // -------------------------------------------------------------- state

    function sync() {
        renderObjects();

        const visible = objects.filter((object) => object.type !== 'crop');
        if (countText) {
            const noun = slug === 'sign-pdf' ? 'tanda tangan' : slug === 'redact-pdf' ? 'area' : slug === 'pdf-forms' ? 'field baru' : 'objek';
            countText.textContent = visible.length ? `${visible.length} ${noun} ditambahkan.` : countText.dataset.empty ?? countText.textContent;
        }

        if (objectList) {
            objectList.replaceChildren(...visible.map((object) => el('li', { className: object.id === selectedId ? 'is-selected' : '' }, [
                el('span', { text: `${LABELS[object.type]} · hal. ${object.page + 1}${object.text ? ` · ${object.text}` : ''}` }),
                button('Pilih', { className: 'btn btn-ghost btn-sm', accessibleName: `Pilih ${LABELS[object.type]} di halaman ${object.page + 1}`, onClick: () => focusObject(object) }),
                button('Hapus', { iconName: 'trash', iconOnly: true, className: 'btn btn-danger-ghost btn-sm', accessibleName: `Hapus ${LABELS[object.type]} di halaman ${object.page + 1}`, onClick: () => remove(object.id) }),
            ])));
        }

        syncProps();
        writeOutputs();
    }

    function writeOutputs() {
        const strip = ({ id, ...rest }) => rest;
        outputs.forEach((input) => {
            const key = input.dataset.editorOutput;
            if (key === 'areas') {
                input.value = JSON.stringify(objects.filter((o) => o.type === 'redact').map((o) => ({ page: o.page, rect: o.rect })));
            } else if (key === 'new_fields') {
                input.value = JSON.stringify(objects.filter((o) => o.type.startsWith('field-')).map((o) => ({ page: o.page, type: o.type === 'field-checkbox' ? 'checkbox' : 'text', rect: o.rect, name: o.name })));
            } else {
                input.value = JSON.stringify(objects.filter((o) => o.type !== 'crop').map(strip));
            }
        });

        const crop = objects.find((object) => object.type === 'crop');
        if (cropBox) {
            cropBox.value = crop ? JSON.stringify(crop.rect.map((value) => Number(value.toFixed(5)))) : '';
            const summary = form.querySelector('[data-crop-summary]');
            if (summary && crop) {
                const [x0, y0, x1, y1] = crop.rect;
                summary.textContent = `Area dipilih: ${Math.round((x1 - x0) * 100)}% × ${Math.round((y1 - y0) * 100)}% halaman.`;
            }
        }
    }

    function selected() {
        return objects.find((object) => object.id === selectedId) ?? null;
    }

    function select(id, rerender = true) {
        if (selectedId === id) {
            return;
        }
        selectedId = id;
        if (rerender) {
            sync();
        } else {
            overlay.querySelectorAll('.obj').forEach((node) => node.classList.toggle('is-selected', node.dataset.id === id));
            syncProps();
        }
    }

    function focusObject(object) {
        if (object.page !== pageIndex) {
            pageIndex = object.page;
            selectedId = object.id;
            renderCurrentPage().then(() => overlay.querySelector(`[data-id="${object.id}"]`)?.focus());
            return;
        }
        selectedId = object.id;
        sync();
        overlay.querySelector(`[data-id="${object.id}"]`)?.focus();
    }

    function add(object) {
        const full = { page: pageIndex, color: '#d94832', width: 2, ...object, id: uid() };
        if (full.type === 'crop') {
            objects = objects.filter((existing) => existing.type !== 'crop');
        }
        objects.push(full);
        selectedId = full.id;
        sync();
        return full;
    }

    function remove(id) {
        objects = objects.filter((object) => object.id !== id);
        if (selectedId === id) {
            selectedId = null;
        }
        sync();
        overlay.focus();
    }

    function shift(object, dx, dy) {
        const [x0, y0, x1, y1] = object.rect;
        const moveX = Math.min(Math.max(dx, -x0), 1 - x1);
        const moveY = Math.min(Math.max(dy, -y0), 1 - y1);
        object.rect = [x0 + moveX, y0 + moveY, x1 + moveX, y1 + moveY];
        if (object.points) {
            object.points = object.points.map(([x, y]) => [x + moveX, y + moveY]);
        }
        if (object.strokes) {
            object.strokes = object.strokes.map((stroke) => stroke.map(([x, y]) => [x + moveX, y + moveY]));
        }
    }

    function onObjectKey(event, object) {
        const step = event.shiftKey ? 0.02 : 0.005;
        const moves = { ArrowLeft: [-step, 0], ArrowRight: [step, 0], ArrowUp: [0, -step], ArrowDown: [0, step] };
        if (moves[event.key]) {
            event.preventDefault();
            shift(object, ...moves[event.key]);
            sync();
            overlay.querySelector(`[data-id="${object.id}"]`)?.focus();
        } else if (event.key === 'Delete' || event.key === 'Backspace') {
            event.preventDefault();
            remove(object.id);
        }
    }

    // -------------------------------------------------------------- props

    function syncProps() {
        if (!props) {
            return;
        }
        const object = selected();
        props.hidden = !object || ['image', 'signature', 'redact', 'crop'].includes(object.type);
        if (!object || props.hidden) {
            return;
        }
        props.querySelector('[data-prop="color"]').value = object.color || '#d94832';
        props.querySelector('[data-prop="width"]').value = object.width || 2;
        props.querySelector('[data-prop="fontsize"]').value = object.fontsize || 14;
        const text = props.querySelector('[data-prop="text"]');
        text.value = object.text || '';
        text.closest('.field').hidden = !['text', 'note'].includes(object.type);
        props.querySelector('[data-prop="fontsize"]').closest('.field').hidden = object.type !== 'text';
        props.querySelector('[data-prop="width"]').closest('.field').hidden = !['rect', 'ellipse', 'line', 'ink'].includes(object.type);
    }

    props?.querySelectorAll('[data-prop]').forEach((input) => {
        input.addEventListener('input', () => {
            const object = selected();
            if (!object) {
                return;
            }
            const key = input.dataset.prop;
            object[key] = input.type === 'number' ? Number(input.value) : input.value;
            if (key === 'fontsize' && object.type === 'text') {
                object.rect[3] = Math.min(1, object.rect[1] + (object.fontsize * 1.5 * Math.max(1, (object.text || '').split('\n').length)) / pageSize.height);
            }
            writeOutputs();
            renderObjects();
            objectList && sync();
            input.focus();
        });
    });

    // --------------------------------------------------------- interaction

    function pointOf(event) {
        const box = overlay.getBoundingClientRect();
        return [clamp((event.clientX - box.left) / box.width), clamp((event.clientY - box.top) / box.height)];
    }

    function setMode(next) {
        mode = next;
        modeButtons.forEach((node) => node.setAttribute('aria-pressed', String(node.dataset.editorMode === next)));
        overlay.classList.toggle('is-drawing', next !== 'select');
        if (next === 'image') {
            imageInput?.click();
        }
    }

    modeButtons.forEach((node) => node.addEventListener('click', () => setMode(node.dataset.editorMode)));

    overlay.addEventListener('pointerdown', (event) => {
        if (!doc || event.button > 0) {
            return;
        }
        const point = pointOf(event);
        const target = event.target.closest('.obj');

        if (target && (mode === 'select' || !DRAG_TYPES.concat(['ink', 'text', 'note']).includes(mode))) {
            const object = objects.find((candidate) => candidate.id === target.dataset.id);
            select(object.id);
            interaction = { kind: event.target.dataset.handle ? 'resize' : 'move', object, start: point, origin: object.rect.slice(), points: JSON.stringify([object.points, object.strokes]) };
            overlay.setPointerCapture(event.pointerId);
            return;
        }

        if (DRAG_TYPES.includes(mode)) {
            interaction = { kind: 'draw', start: point, draft: { id: 'draft', page: pageIndex, type: mode, rect: [...point, ...point], points: mode === 'line' ? [point, point] : undefined, color: mode === 'highlight' ? '#facc15' : '#d94832', width: 2, name: '' } };
            overlay.setPointerCapture(event.pointerId);
        } else if (mode === 'ink') {
            interaction = { kind: 'ink', draft: { id: 'draft', page: pageIndex, type: 'ink', strokes: [[point]], rect: [...point, ...point], color: '#d94832', width: 2 } };
            overlay.setPointerCapture(event.pointerId);
        } else if (mode === 'text' || mode === 'note') {
            const height = mode === 'text' ? (14 * 1.5) / pageSize.height : 0.035;
            const width = mode === 'text' ? 0.3 : 0.035 * (pageSize.height / pageSize.width);
            const rect = [point[0], point[1], Math.min(1, point[0] + width), Math.min(1, point[1] + height)];
            add({ type: mode, rect, text: mode === 'text' ? 'Teks baru' : 'Komentar', fontsize: 14 });
            setMode('select');
            props?.querySelector('[data-prop="text"]')?.focus();
            props?.querySelector('[data-prop="text"]')?.select();
        }
    });

    overlay.addEventListener('pointermove', (event) => {
        if (!interaction) {
            return;
        }
        const point = pointOf(event);

        if (interaction.kind === 'draw') {
            const [sx, sy] = interaction.start;
            interaction.draft.rect = [Math.min(sx, point[0]), Math.min(sy, point[1]), Math.max(sx, point[0]), Math.max(sy, point[1])];
            if (interaction.draft.type === 'line') {
                interaction.draft.points = [interaction.start, point];
            }
        } else if (interaction.kind === 'ink') {
            const stroke = interaction.draft.strokes[0];
            stroke.push(point);
            interaction.draft.rect = boundsOf(stroke);
        } else if (interaction.kind === 'move') {
            const [ox0, oy0, ox1, oy1] = interaction.origin;
            const [points, strokes] = JSON.parse(interaction.points);
            interaction.object.rect = [ox0, oy0, ox1, oy1];
            interaction.object.points = points ?? undefined;
            interaction.object.strokes = strokes ?? undefined;
            shift(interaction.object, point[0] - interaction.start[0], point[1] - interaction.start[1]);
        } else if (interaction.kind === 'resize') {
            const [x0, y0] = interaction.origin;
            interaction.object.rect = [x0, y0, Math.max(x0 + 0.01, point[0]), Math.max(y0 + 0.01, point[1])];
        }
        renderObjects();
    });

    overlay.addEventListener('pointerup', () => {
        if (!interaction) {
            return;
        }
        const { kind, draft } = interaction;
        interaction = null;

        if (kind === 'draw') {
            let [x0, y0, x1, y1] = draft.rect;
            if (draft.type === 'field-checkbox' && x1 - x0 < 0.01) {
                const side = 18 / pageSize.width;
                [x1, y1] = [Math.min(1, x0 + side), Math.min(1, y0 + 18 / pageSize.height)];
            }
            const big = draft.type === 'line' ? Math.hypot(x1 - x0, y1 - y0) > 0.01 : x1 - x0 > 0.01 && y1 - y0 > 0.005;
            if (big) {
                const extra = draft.type.startsWith('field-') ? { name: `${draft.type === 'field-checkbox' ? 'checkbox' : 'teks'}_${(fieldSerial += 1)}` } : {};
                add({ ...draft, id: undefined, rect: [x0, y0, x1, y1], ...extra });
            } else {
                renderObjects();
            }
        } else if (kind === 'ink') {
            if (draft.strokes[0].length > 1) {
                add({ ...draft, id: undefined });
            } else {
                renderObjects();
            }
        } else {
            sync();
        }
    });

    imageInput?.addEventListener('change', () => {
        const file = imageInput.files[0];
        imageInput.value = '';
        if (!file) {
            setMode('select');
            return;
        }
        if (file.size > 4 * 1024 * 1024) {
            wb.showError('Gambar terlalu besar. Gunakan gambar di bawah 4 MB.');
            setMode('select');
            return;
        }
        placeImage(file, 'image');
        setMode('select');
    });

    function placeImage(source, type) {
        const reader = new FileReader();
        reader.onload = () => placeDataUrl(reader.result, type);
        reader.readAsDataURL(source);
    }

    function placeDataUrl(data, type) {
        const image = new Image();
        image.onload = () => {
            const width = 0.3;
            const height = Math.min(0.5, (width * pageSize.width * (image.naturalHeight / image.naturalWidth)) / pageSize.height);
            const x0 = 0.35;
            const y0 = 0.4;
            const object = add({ type, data, rect: [x0, y0, x0 + width, y0 + height] });
            overlay.querySelector(`[data-id="${object.id}"]`)?.focus();
        };
        image.src = data;
    }

    prev.addEventListener('click', () => {
        pageIndex = Math.max(0, pageIndex - 1);
        selectedId = null;
        renderCurrentPage();
    });
    next.addEventListener('click', () => {
        pageIndex = Math.min(doc.numPages - 1, pageIndex + 1);
        selectedId = null;
        renderCurrentPage();
    });

    let resizeTimer = null;
    window.addEventListener('resize', () => {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(renderCurrentPage, 200);
    });

    // -------------------------------------------------------------- forms

    async function detectFields() {
        if (!formFields) {
            return;
        }
        const statusNode = formFields.querySelector('[data-form-status]');
        formFields.querySelectorAll('[data-detected]').forEach((node) => node.remove());
        detectedFields = {};

        // Widget annotations are read page by page; this works for AcroForms where
        // pdf.js getFieldObjects() returns nothing.
        const fields = new Map();
        for (let number = 1; number <= doc.numPages; number += 1) {
            const annotations = await (await doc.getPage(number)).getAnnotations();
            annotations.filter((annotation) => annotation.annotationType === 20 && annotation.fieldName).forEach((annotation) => {
                const list = fields.get(annotation.fieldName) ?? [];
                list.push({ ...annotation, page: number - 1 });
                fields.set(annotation.fieldName, list);
            });
        }

        statusNode.textContent = fields.size ? `${fields.size} field ditemukan. Isi nilainya di bawah.` : 'Tidak ada field formulir. Tambahkan field baru dengan alat di atas halaman.';

        Array.from(fields.entries()).forEach(([name, widgets], index) => {
            const first = widgets[0];
            const id = `field-${index}`;
            const caption = `${name} (halaman ${first.page + 1})`;
            let control;

            if (first.fieldType === 'Btn' && first.checkBox) {
                const input = el('input', { type: 'checkbox', id, value: first.exportValue || 'Yes', 'data-field-name': name });
                input.checked = Boolean(first.fieldValue) && first.fieldValue !== 'Off';
                control = el('label', { className: 'check', 'data-detected': true }, [input, caption]);
            } else if (first.fieldType === 'Btn' && first.radioButton) {
                control = el('fieldset', { className: 'segmented', 'data-detected': true }, [el('legend', { text: caption })]);
                widgets.forEach((widget) => {
                    const input = el('input', { type: 'radio', name: `radio-${index}`, value: widget.buttonValue, 'data-field-name': name });
                    input.checked = widget.fieldValue === widget.buttonValue;
                    control.append(el('label', {}, [input, String(widget.buttonValue)]));
                });
            } else if (first.fieldType === 'Ch') {
                const select = el('select', { className: 'select', id, 'data-field-name': name }, (first.options || []).map((item) => {
                    const option = el('option', { value: item.exportValue, text: item.displayValue });
                    option.selected = [].concat(first.fieldValue).includes(item.exportValue);
                    return option;
                }));
                control = el('div', { className: 'field', 'data-detected': true }, [el('label', { for: id, text: caption }), select]);
            } else if (first.fieldType === 'Tx') {
                const input = el('input', { type: 'text', className: 'input', id, value: first.fieldValue || '', 'data-field-name': name });
                control = el('div', { className: 'field', 'data-detected': true }, [el('label', { for: id, text: caption }), input]);
            } else {
                return;
            }
            formFields.append(control);
        });
    }

    function collectFields() {
        const output = form.querySelector('[data-forms-output]');
        if (!output) {
            return;
        }
        const values = {};
        formFields?.querySelectorAll('[data-field-name]').forEach((input) => {
            const name = input.dataset.fieldName;
            if (input.type === 'checkbox') {
                values[name] = input.checked;
            } else if (input.type === 'radio') {
                if (input.checked) {
                    values[name] = input.value;
                }
            } else {
                values[name] = input.value;
            }
        });
        output.value = JSON.stringify(values);
    }

    // ---------------------------------------------------------- lifecycle

    async function load(entries) {
        // Stay hidden until the first page is rendered, so early clicks are never lost.
        root.hidden = true;
        objects = [];
        selectedId = null;
        pageIndex = 0;
        doc = null;
        if (countText && !countText.dataset.empty) {
            countText.dataset.empty = countText.textContent;
        }
        if (!entries.length) {
            sync();
            return;
        }
        try {
            doc = await wb.pdf(entries[0]);
            root.hidden = false;
            await renderCurrentPage();
            sync();
            await detectFields();
        } catch (error) {
            root.hidden = true;
            wb.showError(error?.locked ? 'PDF terkunci kata sandi. Buka dulu dengan Unlock PDF.' : 'Halaman PDF tidak dapat ditampilkan.');
        }
    }

    wb.on('files', load);
    wb.on('collect', () => {
        writeOutputs();
        collectFields();
    });
    wb.on('validate', () => {
        if (slug === 'edit-pdf' && objects.length === 0) {
            return 'Tambahkan minimal satu objek pada halaman sebelum menyimpan.';
        }
        if (slug === 'sign-pdf' && !objects.some((object) => object.type === 'signature')) {
            return 'Buat tanda tangan lalu tempatkan di halaman sebelum menyimpan.';
        }
        if (slug === 'redact-pdf' && !objects.some((object) => object.type === 'redact') && !form.querySelector('[name="options[terms]"]').value.trim()) {
            return 'Tandai area atau tuliskan kata yang ingin diredaksi.';
        }
        if (slug === 'crop-pdf' && form.querySelector('[name="options[mode]"]:checked')?.value === 'box' && !objects.some((object) => object.type === 'crop')) {
            return 'Seret kotak pada pratinjau untuk memilih area yang dipertahankan.';
        }
        return null;
    });

    // Exposed for the signature module.
    wb.editor = {
        placeSignature: (data) => placeDataUrl(data, 'signature'),
        ready: () => doc !== null,
    };

    setMode(mode);
}
