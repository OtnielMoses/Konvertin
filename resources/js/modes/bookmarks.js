/*
 * Bookmark PDF: reads the existing outline with pdf.js, lets the user edit it as a
 * flat list with levels (title, target page, order, indent), and sends it as
 * options[outline]. Each existing bookmark keeps its index ("source") so the server
 * can keep its original destination when the target page did not change.
 */

import { button, el } from '../lib/dom.js';
import { renderPage } from '../lib/pdf.js';

export default function initBookmarks(wb) {
    const { form } = wb;
    const stage = form.querySelector('[data-bookmarks]');
    const list = form.querySelector('[data-bookmark-list]');
    const empty = form.querySelector('[data-bookmark-empty]');
    const status = form.querySelector('[data-bookmark-status]');
    const output = form.querySelector('[data-outline-output]');
    const canvasBox = form.querySelector('[data-bookmark-canvas]');
    const pageLabel = form.querySelector('[data-bookmark-page]');
    const announce = form.querySelector('[data-bookmark-announce]');
    let doc = null;
    let total = 0;
    let items = [];
    let selected = -1;
    let shownPage = 1;
    let original = '';

    const say = (text) => {
        announce.textContent = text;
    };

    /** Each level is at most one deeper than the one above; the first is level 1. */
    function normalize() {
        let previous = 0;
        items.forEach((item) => {
            item.level = Math.max(1, Math.min(item.level, previous + 1));
            previous = item.level;
        });
    }

    async function show(page) {
        if (!doc) {
            return;
        }
        shownPage = Math.min(Math.max(1, page), total);
        pageLabel.textContent = `Halaman ${shownPage} dari ${total}`;
        const canvas = el('canvas');
        await renderPage(doc, shownPage, canvas, { width: Math.min(canvasBox.clientWidth || 320, 420), maxHeight: 520 });
        canvasBox.replaceChildren(canvas);
    }

    function serialize() {
        output.value = JSON.stringify(items.map(({ level, title, page, source }) => ({ level, title: title.trim(), page, ...(source === null ? {} : { source }) })));
    }

    function render(focus = null) {
        normalize();
        empty.hidden = items.length > 0;
        status.textContent = items.length ? `${items.length} bookmark${JSON.stringify(items) === original ? '' : ' · ada perubahan'}` : 'Belum ada bookmark';
        list.replaceChildren(...items.map((item, index) => {
            const title = el('input', {
                type: 'text', className: 'input', value: item.title, maxlength: '200',
                'aria-label': `Judul bookmark ${index + 1}, tingkat ${item.level}`,
                onInput: (event) => {
                    item.title = event.target.value;
                    serialize();
                    status.textContent = `${items.length} bookmark · ada perubahan`;
                },
                onFocus: () => select(index, false),
            });
            const page = el('input', {
                type: 'number', className: 'input bookmark-page', value: String(item.page), min: '1', max: String(total),
                'aria-label': `Halaman tujuan bookmark ${index + 1}`,
                onChange: (event) => {
                    item.page = Math.min(Math.max(1, Number(event.target.value) || 1), total);
                    event.target.value = String(item.page);
                    serialize();
                    show(item.page);
                },
                onFocus: () => select(index, false),
            });
            const row = el('li', {
                className: `bookmark-item${index === selected ? ' is-selected' : ''}`,
                style: `--level:${item.level - 1}`,
                'data-index': String(index),
            }, [
                el('span', { className: 'bookmark-level', text: `T${item.level}` }),
                title,
                page,
                el('div', { className: 'bookmark-tools' }, [
                    button('Naikkan tingkat', { iconName: 'chevron-left', iconOnly: true, accessibleName: `Jadikan bookmark ${index + 1} satu tingkat lebih tinggi`, disabled: item.level === 1, onClick: () => shift(index, -1) }),
                    button('Turunkan tingkat', { iconName: 'chevron-right', iconOnly: true, accessibleName: `Jadikan bookmark ${index + 1} sub-bookmark`, disabled: index === 0 || item.level > items[index - 1].level, onClick: () => shift(index, 1) }),
                    button('Naik', { iconName: 'arrow-up', iconOnly: true, accessibleName: `Pindahkan bookmark ${index + 1} ke atas`, disabled: index === 0, onClick: () => move(index, -1) }),
                    button('Turun', { iconName: 'arrow-down', iconOnly: true, accessibleName: `Pindahkan bookmark ${index + 1} ke bawah`, disabled: index === items.length - 1, onClick: () => move(index, 1) }),
                    button('Hapus', { iconName: 'trash', iconOnly: true, className: 'btn btn-danger-ghost btn-sm', accessibleName: `Hapus bookmark ${index + 1}`, onClick: () => remove(index) }),
                ]),
            ]);
            row.addEventListener('click', (event) => {
                if (event.target === row || event.target.classList.contains('bookmark-level')) {
                    select(index);
                }
            });
            return row;
        }));
        serialize();
        if (focus) {
            list.querySelector(`[data-index="${focus.index}"] ${focus.selector}`)?.focus();
        }
    }

    function select(index, rerender = true) {
        if (selected === index && !rerender) {
            return;
        }
        selected = index;
        list.querySelectorAll('.bookmark-item').forEach((row) => row.classList.toggle('is-selected', Number(row.dataset.index) === index));
        if (items[index]) {
            show(items[index].page);
        }
    }

    function shift(index, step) {
        items[index].level += step;
        render({ index, selector: 'input[type="text"]' });
        say(`Bookmark ${index + 1} sekarang tingkat ${items[index].level}.`);
    }

    function move(index, step) {
        const target = index + step;
        [items[index], items[target]] = [items[target], items[index]];
        selected = target;
        render({ index: target, selector: 'input[type="text"]' });
        say(`Bookmark dipindah ke posisi ${target + 1}.`);
    }

    function remove(index) {
        const [gone] = items.splice(index, 1);
        selected = Math.min(index, items.length - 1);
        render();
        say(`Bookmark "${gone.title}" dihapus.`);
    }

    form.querySelector('[data-bookmark-add]').addEventListener('click', () => {
        // A new bookmark goes after the selected one and all of its sub-bookmarks,
        // at the same level, so existing children keep their parent.
        const base = selected >= 0 ? selected : items.length - 1;
        const level = items[base]?.level ?? 1;
        let after = base;
        while (items[after + 1] && items[after + 1].level > level) {
            after += 1;
        }
        items.splice(after + 1, 0, { level, title: `Bookmark halaman ${shownPage}`, page: shownPage, source: null });
        selected = after + 1;
        render({ index: selected, selector: 'input[type="text"]' });
        list.querySelector(`[data-index="${selected}"] input[type="text"]`)?.select();
        say(`Bookmark baru untuk halaman ${shownPage}.`);
    });
    form.querySelector('[data-bookmark-prev]').addEventListener('click', () => show(shownPage - 1));
    form.querySelector('[data-bookmark-next]').addEventListener('click', () => show(shownPage + 1));

    async function flatten(outline, level, into) {
        for (const node of outline || []) {
            let page = null;
            try {
                const destination = typeof node.dest === 'string' ? await doc.getDestination(node.dest) : node.dest;
                if (Array.isArray(destination) && destination[0]) {
                    page = typeof destination[0] === 'number' ? destination[0] + 1 : (await doc.getPageIndex(destination[0])) + 1;
                }
            } catch (error) {
                page = null;
            }
            into.push({ level, title: node.title || 'Tanpa judul', page: page ?? 1, source: into.length, unknown: page === null });
            await flatten(node.items, level + 1, into);
        }
        return into;
    }

    async function load(entries) {
        stage.hidden = entries.length === 0;
        items = [];
        selected = -1;
        doc = null;
        list.replaceChildren();
        if (!entries.length) {
            return;
        }
        status.textContent = 'Membaca bookmark…';
        try {
            doc = await wb.pdf(entries[0]);
            total = doc.numPages;
            items = await flatten(await doc.getOutline(), 1, []);
            original = JSON.stringify(items);
            const unknown = items.filter((item) => item.unknown).length;
            render();
            if (unknown) {
                status.textContent += ` · ${unknown} bookmark tujuannya tidak dikenali (ditampilkan ke halaman 1; periksa sebelum menyimpan)`;
            }
            selected = items.length ? 0 : -1;
            show(items[0]?.page ?? 1);
            if (items.length) {
                select(0);
            }
        } catch (error) {
            status.textContent = error?.locked ? 'PDF terkunci kata sandi. Buka dulu dengan Unlock PDF.' : 'PDF tidak dapat dibaca.';
        }
    }

    wb.on('files', load);
    // Files chosen before this module finished loading.
    if (wb.files.length) {
        load(wb.files);
    }
    wb.on('validate', () => {
        if (items.some((item) => !item.title.trim())) {
            return 'Setiap bookmark perlu judul.';
        }
        return null;
    });
    wb.on('collect', serialize);
}
