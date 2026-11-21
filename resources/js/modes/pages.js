/*
 * Organize PDF: a thumbnail board. Every action has a button (keyboard friendly);
 * dragging is an extra shortcut, not the only way to reorder.
 */

import { button, el, uid } from '../lib/dom.js';
import { lazyRender, openPdf, renderPage } from '../lib/pdf.js';

export default function initPages(wb) {
    const { form } = wb;
    const stage = form.querySelector('[data-pages]');
    const grid = form.querySelector('[data-page-grid]');
    const status = form.querySelector('[data-pages-status]');
    const output = form.querySelector('[data-pages-output]');
    const extraInput = form.querySelector('[data-aux-input="extra"]');
    const actions = Object.fromEntries(Array.from(form.querySelectorAll('[data-pages-action]')).map((node) => [node.dataset.pagesAction, node]));

    const docs = [null, null];
    let items = [];
    let original = [];
    let dragKey = null;
    let disconnect = () => {};

    const selected = () => items.filter((item) => item.selected);

    function syncToolbar() {
        const count = selected().length;
        ['rotate', 'delete', 'extract'].forEach((key) => {
            actions[key].disabled = count === 0;
        });
        actions['select-all'].textContent = count === items.length && items.length ? 'Batalkan pilihan' : 'Pilih semua';
        status.textContent = `${items.length} halaman${count ? ` · ${count} terpilih` : ''}`;
        output.value = JSON.stringify(items.map((item) => (item.blank ? { blank: true } : { src: item.src, page: item.page, rotate: item.rotate })));
    }

    function move(from, to) {
        if (to < 0 || to >= items.length) {
            return;
        }
        const [moved] = items.splice(from, 1);
        items.splice(to, 0, moved);
        render();
        grid.children[to]?.querySelector(`[data-move="${to < from ? 'left' : 'right'}"]:not(:disabled)`)?.focus();
    }

    function label(item) {
        if (item.blank) {
            return 'Halaman kosong';
        }
        return item.src === 1 ? `Tambahan hal. ${item.page + 1}` : `Hal. ${item.page + 1}`;
    }

    function render() {
        disconnect();
        grid.replaceChildren();

        items.forEach((item, index) => {
            const canvas = el('canvas');
            const frame = el('div', { className: `page-frame${item.blank ? ' is-blank' : ''}` }, item.blank ? [] : [canvas]);
            const checkbox = el('input', { type: 'checkbox', className: 'page-select', 'aria-label': `Pilih ${label(item)} (posisi ${index + 1})` });
            checkbox.checked = item.selected;
            checkbox.addEventListener('change', () => {
                item.selected = checkbox.checked;
                node.classList.toggle('is-selected', item.selected);
                syncToolbar();
            });

            const left = button('Geser ke kiri', { iconName: 'arrow-up', iconOnly: true, accessibleName: `Geser ${label(item)} ke posisi sebelumnya`, disabled: index === 0, onClick: () => move(index, index - 1) });
            left.dataset.move = 'left';
            left.querySelector('svg').style.transform = 'rotate(-90deg)';
            const right = button('Geser ke kanan', { iconName: 'arrow-down', iconOnly: true, accessibleName: `Geser ${label(item)} ke posisi berikutnya`, disabled: index === items.length - 1, onClick: () => move(index, index + 1) });
            right.dataset.move = 'right';
            right.querySelector('svg').style.transform = 'rotate(-90deg)';

            const tools = el('div', { className: 'page-tools' }, [
                left,
                button('Putar', {
                    iconName: 'rotate', iconOnly: true, accessibleName: `Putar ${label(item)} 90 derajat`, disabled: item.blank,
                    onClick: () => {
                        item.rotate = (item.rotate + 90) % 360;
                        canvas.style.transform = `rotate(${item.rotate}deg)`;
                        syncToolbar();
                    },
                }),
                button('Hapus', {
                    iconName: 'trash', iconOnly: true, className: 'btn btn-danger-ghost btn-sm', accessibleName: `Hapus ${label(item)}`,
                    onClick: () => {
                        items.splice(index, 1);
                        render();
                        grid.children[Math.min(index, items.length - 1)]?.querySelector('input')?.focus();
                    },
                }),
                right,
            ]);

            const node = el('div', { className: `page-thumb${item.selected ? ' is-selected' : ''}`, draggable: 'true', 'data-key': item.key }, [
                checkbox,
                frame,
                el('div', { className: 'page-label' }, [el('span', { text: `${index + 1}` }), el('span', { text: label(item) })]),
                tools,
            ]);

            node.addEventListener('dragstart', (event) => {
                dragKey = item.key;
                node.classList.add('is-dragging');
                event.dataTransfer.effectAllowed = 'move';
                event.dataTransfer.setData('text/plain', item.key);
            });
            node.addEventListener('dragend', () => node.classList.remove('is-dragging'));
            node.addEventListener('dragover', (event) => {
                if (dragKey) {
                    event.preventDefault();
                    node.classList.add('is-drop-target');
                }
            });
            node.addEventListener('dragleave', () => node.classList.remove('is-drop-target'));
            node.addEventListener('drop', (event) => {
                event.preventDefault();
                event.stopPropagation();
                node.classList.remove('is-drop-target');
                const from = items.findIndex((candidate) => candidate.key === dragKey);
                dragKey = null;
                if (from !== -1 && from !== index) {
                    move(from, index);
                }
            });

            if (item.rotate) {
                canvas.style.transform = `rotate(${item.rotate}deg)`;
            }
            node.renderItem = () => !item.blank && docs[item.src] && renderPage(docs[item.src], item.page + 1, canvas, { width: 96 });
            grid.append(node);
        });

        disconnect = lazyRender(Array.from(grid.children), (node) => node.renderItem());
        syncToolbar();
    }

    function pagesOf(doc, src) {
        return Array.from({ length: doc.numPages }, (_, page) => ({ key: uid(), src, page, rotate: 0, blank: false, selected: false }));
    }

    async function load(entries) {
        stage.hidden = entries.length === 0;
        items = [];
        grid.replaceChildren();
        if (!entries.length) {
            return;
        }
        status.textContent = 'Memuat halaman...';
        try {
            docs[0] = await wb.pdf(entries[0]);
            items = pagesOf(docs[0], 0);
            original = items.map((item) => ({ ...item }));
            render();
        } catch (error) {
            status.textContent = error?.locked ? 'PDF terkunci kata sandi. Buka dulu dengan Unlock PDF.' : 'Halaman tidak dapat dimuat.';
        }
    }

    extraInput?.addEventListener('change', async () => {
        const file = extraInput.files[0];
        items = items.filter((item) => item.src !== 1);
        docs[1] = null;
        if (file) {
            try {
                docs[1] = await openPdf(file);
                items = items.concat(pagesOf(docs[1], 1));
            } catch (error) {
                wb.showError(error?.locked ? 'PDF tambahan terkunci kata sandi.' : 'PDF tambahan tidak dapat dibaca.');
            }
        }
        render();
    });

    actions['select-all'].addEventListener('click', () => {
        const all = selected().length !== items.length;
        items.forEach((item) => {
            item.selected = all;
        });
        render();
    });
    actions.rotate.addEventListener('click', () => {
        selected().forEach((item) => {
            if (!item.blank) {
                item.rotate = (item.rotate + 90) % 360;
            }
        });
        render();
    });
    actions.delete.addEventListener('click', () => {
        items = items.filter((item) => !item.selected);
        render();
    });
    actions.extract.addEventListener('click', () => {
        items = selected().map((item) => ({ ...item, selected: false }));
        render();
    });
    actions.blank.addEventListener('click', () => {
        const lastSelected = items.map((item) => item.selected).lastIndexOf(true);
        const at = lastSelected === -1 ? items.length : lastSelected + 1;
        items.splice(at, 0, { key: uid(), src: 0, page: -1, rotate: 0, blank: true, selected: false });
        render();
    });
    actions.reset.addEventListener('click', () => {
        items = original.map((item) => ({ ...item, key: uid(), selected: false })).concat(docs[1] ? pagesOf(docs[1], 1) : []);
        render();
    });

    wb.on('files', load);
    wb.on('validate', () => (items.length === 0 ? 'Susunan halaman kosong. Kembalikan susunan awal atau tambahkan halaman.' : null));
    wb.on('collect', syncToolbar);
}
