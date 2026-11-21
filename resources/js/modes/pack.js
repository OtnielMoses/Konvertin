/*
 * Assignment Pack Builder.
 *
 * Documents live in section lanes (cover, preliminary, main, appendix). Cards can be
 * dragged within and between lanes, moved with buttons, or re-assigned with the
 * section picker on each card. The final order always follows the section order,
 * which is also what the server enforces. The right panel walks through six steps;
 * the left side shows the board, and in the last step a preview of the planned
 * page sequence with the page labels the numbering rules will produce.
 */

import { bindCompressionOptions, renderCompressionReport } from '../lib/compression.js';
import { el, formatBytes, icon } from '../lib/dom.js';
import { createFileCard, fileKind } from '../lib/filecard.js';
import { measure, playFrom, removeWithMotion } from '../lib/motion.js';
import { lazyRender, renderPage } from '../lib/pdf.js';
import { makeSortable } from '../lib/sortable.js';

const SECTIONS = ['cover', 'preliminary', 'main', 'appendix'];
const NAMES = { cover: 'Sampul', preliminary: 'Bagian awal', main: 'Isi utama', appendix: 'Lampiran' };
const STEP_NAMES = ['Dokumen', 'Susun', 'Halaman', 'Nomor', 'Optimasi', 'Pratinjau'];
const PREVIEW_LIMIT = 60;
const ROMAN = [[1000, 'm'], [900, 'cm'], [500, 'd'], [400, 'cd'], [100, 'c'], [90, 'xc'], [50, 'l'], [40, 'xl'], [10, 'x'], [9, 'ix'], [5, 'v'], [4, 'iv'], [1, 'i']];

function roman(number) {
    let rest = number;
    let text = '';
    ROMAN.forEach(([value, symbol]) => {
        while (rest >= value) {
            text += symbol;
            rest -= value;
        }
    });
    return text;
}

function formatNumber(style, number) {
    if (style === 'roman_lower') {
        return roman(number);
    }
    if (style === 'roman_upper') {
        return roman(number).toUpperCase();
    }
    return String(number);
}

/** Places a new file by its name; the student can always move it. */
function guessSection(name) {
    const lower = name.toLowerCase();
    if (/(cover|sampul|halaman[\s_-]*judul|title[\s_-]*page)/.test(lower)) {
        return 'cover';
    }
    if (/(lampiran|appendix|attachment|screenshot|dokumentasi)/.test(lower)) {
        return 'appendix';
    }
    if (/(kata[\s_-]*pengantar|daftar[\s_-]*isi|abstra|pengesahan|preface|pernyataan|daftar[\s_-]*(gambar|tabel))/.test(lower)) {
        return 'preliminary';
    }
    return 'main';
}

function parsePageList(spec) {
    const pages = new Set();
    String(spec || '').replace(/\s/g, '').split(',').forEach((part) => {
        const match = part.match(/^(\d+)(?:-(\d+))?$/);
        if (match) {
            for (let page = Number(match[1]); page <= Number(match[2] || match[1]); page += 1) {
                pages.add(page);
            }
        }
    });
    return pages;
}

export default function initPack(wb) {
    const { form, config } = wb;
    const root = form.querySelector('[data-pack]');
    const board = root.querySelector('[data-pack-board]');
    const previewBox = root.querySelector('[data-pack-preview]');
    const dropzone = form.querySelector('[data-dropzone]');
    const fileInput = form.querySelector('[data-file-input]');
    const groupingBox = form.querySelector('[data-pack-grouping]');
    const sectionsField = form.querySelector('[data-pack-sections]');
    const numberingField = form.querySelector('[data-pack-numbering]');
    const compressBox = form.querySelector('[data-pack-compress]');
    const compressionPanel = form.querySelector('[data-pack-compression]');
    const paper = form.querySelector('[data-pack-paper]');
    const prev = form.querySelector('[data-pack-prev]');
    const next = form.querySelector('[data-pack-next]');
    const liveRegion = root.querySelector('[data-order-status]');
    const lanes = Object.fromEntries(SECTIONS.map((key) => [key, root.querySelector(`[data-lane="${key}"]`)]));
    const lists = Object.fromEntries(SECTIONS.map((key) => [key, root.querySelector(`[data-lane-list="${key}"]`)]));
    const cards = new Map();
    const addCards = {};
    let step = 1;
    let pendingSection = null;

    const compression = bindCompressionOptions(compressionPanel);
    const grouping = () => groupingBox.checked;
    const sectionOf = (entry) => (grouping() ? entry.section : 'main');
    const announce = (text) => {
        liveRegion.textContent = text;
    };

    // ---------------------------------------------------------------- lanes

    SECTIONS.forEach((key) => {
        const label = el('span', { className: 'file-add-label', text: `Tambah ke ${NAMES[key]}` });
        addCards[key] = el('li', { className: 'file-add is-lane', 'data-sort-end': true }, [
            el('button', { type: 'button', className: 'file-add-button', onClick: () => pickFor(key) }, [
                el('span', { className: 'file-add-icon' }, [icon('plus')]),
                label,
            ]),
        ]);
        addCards[key].label = label;
        lists[key].append(addCards[key]);

        // Files dropped from the computer onto a lane go into that lane.
        lanes[key].addEventListener('drop', () => {
            pendingSection = grouping() ? key : 'main';
        });
    });

    function pickFor(section) {
        pendingSection = section;
        fileInput.click();
    }
    root.querySelector('[data-pack-add]').addEventListener('click', () => pickFor(null));

    function sortBySection() {
        const order = new Map(wb.files.map((entry, index) => [entry, index]));
        wb.files.sort((a, b) => SECTIONS.indexOf(sectionOf(a)) - SECTIONS.indexOf(sectionOf(b)) || order.get(a) - order.get(b));
    }

    function membersOf(section) {
        return wb.files.filter((entry) => sectionOf(entry) === section);
    }

    function move(entry, step) {
        const members = membersOf(sectionOf(entry));
        const position = members.indexOf(entry);
        const neighbour = members[position + step];
        if (!neighbour) {
            return;
        }
        const from = wb.files.indexOf(entry);
        const to = wb.files.indexOf(neighbour);
        wb.files[from] = neighbour;
        wb.files[to] = entry;
        wb.setFiles(wb.files);
        const node = cards.get(entry.id)?.node;
        (node?.querySelector(`[data-move="${step < 0 ? 'back' : 'forward'}"]:not(:disabled)`) ?? node?.querySelector('select'))?.focus();
        announce(`${entry.file.name} sekarang di posisi ${position + step + 1} dari ${members.length} di ${laneTitle(sectionOf(entry))}.`);
    }

    function remove(entry) {
        const index = wb.files.indexOf(entry);
        wb.files.splice(index, 1);
        wb.setFiles(wb.files);
        const nextEntry = wb.files[Math.min(index, wb.files.length - 1)];
        (nextEntry ? cards.get(nextEntry.id)?.node.querySelector('.file-remove') : root.querySelector('[data-pack-add]'))?.focus();
        announce(`${entry.file.name} dihapus dari paket.`);
    }

    function changeSection(entry, section) {
        entry.section = section;
        // Moved documents go to the end of their new lane.
        wb.files.splice(wb.files.indexOf(entry), 1);
        wb.files.push(entry);
        sortBySection();
        wb.setFiles(wb.files);
        cards.get(entry.id)?.node.querySelector('select')?.focus();
        announce(`${entry.file.name} dipindahkan ke ${NAMES[section]}.`);
    }

    function sectionPicker(entry) {
        const select = el('select', { className: 'select select-sm', 'aria-label': `Bagian untuk ${entry.file.name}` },
            SECTIONS.map((key) => el('option', { value: key, text: NAMES[key] })));
        select.value = entry.section;
        select.addEventListener('change', () => changeSection(entry, select.value));
        return el('div', { className: 'file-section-pick', 'data-section-pick': true }, [select]);
    }

    function laneTitle(section) {
        return grouping() ? NAMES[section] : 'Dokumen';
    }

    function pagesText(entries) {
        const counted = entries.reduce((sum, entry) => sum + (fileKind(entry) === 'image' ? 1 : entry.pages || 0), 0);
        const pending = entries.some((entry) => fileKind(entry) === 'word' || (fileKind(entry) === 'pdf' && !entry.pages));
        return { counted, pending };
    }

    function renderBoard() {
        const hasFiles = wb.files.length > 0;
        dropzone.hidden = hasFiles;
        board.hidden = !hasFiles || step === 6;
        previewBox.hidden = !hasFiles || step !== 6;

        const ids = new Set(wb.files.map((entry) => entry.id));
        const staying = () => [...Array.from(cards.values()).map((card) => card.node), ...Object.values(addCards)];
        cards.forEach((card, id) => {
            if (!ids.has(id)) {
                cards.delete(id);
                removeWithMotion(card.node, staying());
            }
        });

        const rects = measure(staying());
        SECTIONS.forEach((key) => {
            const visible = grouping() || key === 'main';
            lanes[key].hidden = !visible;
            lanes[key].querySelector('[data-lane-title]').textContent = laneTitle(key);
            lanes[key].querySelector('[data-lane-hint]').hidden = !grouping();
            addCards[key].label.textContent = grouping() ? `Tambah ke ${NAMES[key]}` : 'Tambah dokumen';

            const members = membersOf(key);
            members.forEach((entry, index) => {
                let card = cards.get(entry.id);
                if (!card) {
                    card = createFileCard(entry, wb, {
                        onRemove: () => remove(entry),
                        onMove: (stepBy) => move(entry, stepBy),
                        extra: sectionPicker(entry),
                    });
                    cards.set(entry.id, card);
                }
                card.update({ index, total: members.length, label: String(wb.files.indexOf(entry) + 1) });
                card.node.querySelector('[data-section-pick]').hidden = !grouping();
                card.node.querySelector('select').value = entry.section;
                const expected = lists[key].querySelectorAll(':scope > .file-item')[index];
                if (expected !== card.node) {
                    lists[key].insertBefore(card.node, expected ?? addCards[key]);
                }
            });

            const { counted, pending } = pagesText(members);
            lanes[key].querySelector('[data-lane-meta]').textContent = members.length
                ? `${members.length} dokumen · ${counted}${pending ? '+' : ''} halaman`
                : 'Kosong';
            lanes[key].classList.toggle('is-empty', members.length === 0);
        });
        playFrom(rects);

        const { counted, pending } = pagesText(wb.files);
        root.querySelector('[data-pack-count]').textContent = `${wb.files.length} dokumen · ${formatBytes(wb.files.reduce((sum, entry) => sum + entry.file.size, 0))} · ${counted}${pending ? '+' : ''} halaman`;
        renderSectionSummary();
        if (step === 6) {
            renderPreview();
        }
    }

    makeSortable({
        root: board,
        itemSelector: '.file-item',
        containerSelector: '[data-lane-list]',
        onDrop: ({ item, container }) => {
            const order = [];
            SECTIONS.forEach((key) => {
                lists[key].querySelectorAll(':scope > .file-item').forEach((node) => {
                    const entry = wb.files.find((candidate) => candidate.id === node.dataset.id);
                    if (entry) {
                        if (grouping()) {
                            entry.section = key;
                        }
                        order.push(entry);
                    }
                });
            });
            wb.setFiles(order);
            const entry = order.find((candidate) => candidate.id === item.dataset.id);
            if (entry) {
                const members = membersOf(sectionOf(entry));
                announce(`${entry.file.name} sekarang di ${laneTitle(container.dataset.laneList)}, posisi ${members.indexOf(entry) + 1} dari ${members.length}.`);
            }
        },
    });

    // ------------------------------------------------------------ numbering

    function numberingRules() {
        const rules = {};
        form.querySelectorAll('[data-number-row]').forEach((row) => {
            const continued = row.querySelector('[data-number-continue]').checked;
            rules[row.dataset.numberRow] = {
                style: row.querySelector('[data-number-style]').value,
                start: Number(row.querySelector('[data-number-start]').value) || 0,
                continue: continued,
            };
        });
        return rules;
    }

    function optionValue(field) {
        const checked = form.querySelector(`[name="options[${field}]"]:checked`);
        return checked ? checked.value : form.querySelector(`[name="options[${field}]"]`)?.value;
    }

    /**
     * Mirrors the engine: per section a style and start (or continue from the last
     * number), first-page and hidden-page rules. Pages after a Word document are
     * unknown until conversion, so their labels are reported as unknown.
     */
    function plannedPages() {
        const rules = numberingRules();
        const firstRule = optionValue('first_page_rule');
        const hidden = parsePageList(optionValue('hide_pages'));
        const pages = [];
        let current = null;
        let style = 'none';
        let number = 0;
        let last = 0;
        let unknown = false;
        let globalUnknown = false;
        let globalIndex = 0;

        wb.files.forEach((entry) => {
            const section = sectionOf(entry);
            if (section !== current) {
                current = section;
                const rule = rules[section] ?? { style: 'none' };
                style = rule.style;
                if (style !== 'none') {
                    if (rule.continue) {
                        number = last;
                    } else {
                        number = rule.start - 1;
                        unknown = false;
                    }
                }
            }

            const kind = fileKind(entry);
            const count = kind === 'image' ? 1 : entry.pages ?? null;
            if (count === null) {
                pages.push({ entry, section, placeholder: true });
                if (style !== 'none') {
                    unknown = true;
                }
                globalUnknown = true;
                return;
            }

            for (let offset = 0; offset < count; offset += 1) {
                globalIndex += 1;
                let label = null;
                if (style !== 'none') {
                    number += 1;
                    last = number;
                    const hiddenHere = (offset === 0 && firstRule === 'hide') || (!globalUnknown && hidden.has(globalIndex));
                    label = hiddenHere ? null : unknown ? '?' : formatNumber(style, number);
                }
                pages.push({ entry, section, offset, label, first: offset === 0, chapterStart: offset === 0 && firstRule === 'bottom_center' });
            }
        });

        return pages;
    }

    function labelRange(pages) {
        const labels = pages.map((page) => page.label).filter(Boolean);
        if (!labels.length) {
            return 'tanpa nomor';
        }
        return labels.length === 1 ? labels[0] : `${labels[0]} sampai ${labels[labels.length - 1]}`;
    }

    // -------------------------------------------------------------- preview

    let stopPreview = () => {};

    function renderPreview() {
        stopPreview();
        const pages = plannedPages();
        const outline = root.querySelector('[data-pack-outline]');
        const grid = root.querySelector('[data-pack-pages]');
        const note = root.querySelector('[data-preview-note]');
        const position = optionValue('number_position');
        const align = optionValue('number_align');
        const known = pages.filter((page) => !page.placeholder).length;
        const hasWord = pages.some((page) => page.placeholder);

        root.querySelector('[data-preview-summary]').textContent = `${wb.files.length} dokumen · ${known}${hasWord ? '+' : ''} halaman`;

        outline.replaceChildren(...SECTIONS.filter((key) => membersOf(key).length).map((key) => el('li', {}, [
            el('strong', { text: laneTitle(key) }),
            el('span', { className: 'muted', text: ` · nomor ${labelRange(pages.filter((page) => page.section === key && !page.placeholder))}` }),
            el('ol', {}, membersOf(key).map((entry) => {
                const own = pages.filter((page) => page.entry === entry);
                const count = own[0]?.placeholder ? 'halaman dihitung saat diproses' : `${own.length} halaman, nomor ${labelRange(own)}`;
                return el('li', { text: `${entry.file.name} (${count})` });
            })),
        ])));

        note.textContent = [
            hasWord ? 'Jumlah halaman dokumen Word baru diketahui setelah diubah ke PDF, jadi nomor setelahnya ditandai "?" di pratinjau ini. Hasil akhir memakai nomor yang benar.' : '',
            known > PREVIEW_LIMIT ? `Pratinjau menampilkan ${PREVIEW_LIMIT} halaman pertama.` : '',
        ].filter(Boolean).join(' ');

        const tiles = [];
        let shown = 0;
        pages.forEach((page) => {
            if (page.placeholder) {
                tiles.push(el('div', { className: 'page-thumb is-placeholder' }, [
                    el('div', { className: 'page-frame' }, [icon('doc-word'), el('span', { text: 'Halaman Word' })]),
                    el('p', { className: 'page-label', text: page.entry.file.name }),
                ]));
                return;
            }
            if (shown >= PREVIEW_LIMIT) {
                return;
            }
            shown += 1;
            const frame = el('div', { className: 'page-frame is-loading', 'data-entry': page.entry.id, 'data-offset': String(page.offset) });
            const vertical = page.chapterStart ? 'bottom' : position;
            const horizontal = page.chapterStart ? 'center' : align;
            if (page.label) {
                frame.append(el('span', { className: `planned-number at-${vertical} align-${horizontal}`, text: page.label }));
            }
            tiles.push(el('div', { className: 'page-thumb' }, [
                frame,
                el('p', { className: 'page-label', text: `${NAMES[page.section] && grouping() ? `${NAMES[page.section]} · ` : ''}${page.label ? `nomor ${page.label}` : 'tanpa nomor'}` }),
            ]));
        });
        grid.replaceChildren(...tiles);

        stopPreview = lazyRender(Array.from(grid.querySelectorAll('.page-frame[data-entry]')), async (frame) => {
            const entry = wb.files.find((candidate) => candidate.id === frame.dataset.entry);
            if (!entry) {
                return;
            }
            try {
                if (fileKind(entry) === 'image') {
                    frame.prepend(el('img', { src: entry.preview?.src ?? URL.createObjectURL(entry.file), alt: '' }));
                } else {
                    const canvas = el('canvas');
                    await renderPage(await wb.pdf(entry), Number(frame.dataset.offset) + 1, canvas, { width: 150, maxHeight: 200 });
                    frame.prepend(canvas);
                }
            } finally {
                frame.classList.remove('is-loading');
            }
        });
    }

    // ---------------------------------------------------------------- steps

    function renderSectionSummary() {
        const list = form.querySelector('[data-pack-section-summary]');
        const used = grouping() ? SECTIONS : ['main'];
        list.replaceChildren(...used.map((key) => {
            const members = membersOf(key);
            return el('li', { className: members.length ? '' : 'is-empty' }, [
                el('span', { text: laneTitle(key) }),
                el('span', { className: 'muted', text: members.length ? `${members.length} dokumen` : 'kosong' }),
            ]);
        }));

        form.querySelectorAll('[data-number-row]').forEach((row) => {
            const key = row.dataset.numberRow;
            row.hidden = !grouping() && key !== 'main';
            row.querySelector('[data-number-title]').textContent = laneTitle(key) + (grouping() && !membersOf(key).length ? ' (kosong)' : '');
            row.classList.toggle('is-unused', grouping() && !membersOf(key).length);
        });
    }

    function renderChecklist() {
        const pages = plannedPages();
        const known = pages.filter((page) => !page.placeholder).length;
        const items = [
            `${wb.files.length} dokumen, ${known}${pages.some((page) => page.placeholder) ? '+' : ''} halaman`,
            paper.value === 'original' ? 'Ukuran kertas mengikuti dokumen asli' : `Kertas ${paper.options[paper.selectedIndex].text}`,
            ...SECTIONS.filter((key) => membersOf(key).length).map((key) => `${laneTitle(key)}: nomor ${labelRange(pages.filter((page) => page.section === key && !page.placeholder))}`),
            compressBox.checked ? `Dikompres sampai maks. ${compression.target() ? formatBytes(compression.target()) : '(isi target)'}` : 'Tanpa kompresi',
        ];
        form.querySelector('[data-pack-checklist]').replaceChildren(...items.map((text) => el('li', {}, [icon('check'), text])));
    }

    function go(target, { focus = true } = {}) {
        step = Math.max(1, Math.min(6, target));
        root.querySelectorAll('[data-step-go]').forEach((button) => {
            const number = Number(button.dataset.stepGo);
            if (number === step) {
                button.setAttribute('aria-current', 'step');
            } else {
                button.removeAttribute('aria-current');
            }
            button.classList.toggle('is-done', number < step);
        });
        form.querySelectorAll('[data-pack-step]').forEach((panel) => {
            panel.hidden = Number(panel.dataset.packStep) !== step;
        });
        prev.hidden = step === 1;
        next.hidden = step === 6;
        if (step < 6) {
            next.replaceChildren(document.createTextNode(`Berikutnya: ${STEP_NAMES[step]}`), icon('chevron-right'));
        }
        if (step === 6) {
            renderChecklist();
        }
        renderBoard();
        if (focus) {
            form.querySelector(`[data-pack-step="${step}"] .step-title`)?.focus({ preventScroll: true });
        }
    }

    root.querySelectorAll('[data-step-go]').forEach((button) => button.addEventListener('click', () => go(Number(button.dataset.stepGo))));
    prev.addEventListener('click', () => go(step - 1));
    next.addEventListener('click', () => go(step + 1));

    // ---------------------------------------------------------- form wiring

    groupingBox.addEventListener('change', () => {
        sortBySection();
        wb.setFiles(wb.files);
    });

    compressBox.addEventListener('change', () => {
        compressionPanel.hidden = !compressBox.checked;
        compression.setOriginal(wb.files.reduce((sum, entry) => sum + entry.file.size, 0) || null);
    });

    paper.addEventListener('change', () => {
        form.querySelector('[data-paper-options]').hidden = paper.value === 'original';
        form.querySelector('[data-paper-hint]').textContent = paper.value === 'original'
            ? 'Halaman PDF tetap memakai ukurannya sendiri. Gambar ditempatkan di kertas A4.'
            : 'Halaman yang ukurannya berbeda diperkecil atau diperbesar secara proporsional agar utuh di kertas yang dipilih.';
    });

    form.querySelectorAll('[data-number-row]').forEach((row) => {
        const continued = row.querySelector('[data-number-continue]');
        const style = row.querySelector('[data-number-style]');
        const sync = () => {
            row.querySelector('[data-number-start]').disabled = continued.checked || style.value === 'none';
            continued.disabled = style.value === 'none';
            row.querySelector('[data-number-start-box]').classList.toggle('is-off', style.value === 'none');
        };
        continued.addEventListener('change', sync);
        style.addEventListener('change', sync);
        sync();
    });

    form.addEventListener('change', () => {
        if (step === 6) {
            renderChecklist();
        }
    });

    wb.describeSelection = () => {
        if (!wb.files.length) {
            return 'Belum ada dokumen.';
        }
        const { counted, pending } = pagesText(wb.files);
        return `${wb.files.length} dokumen · ${counted}${pending ? '+' : ''} halaman · ${formatBytes(wb.files.reduce((sum, entry) => sum + entry.file.size, 0))}`;
    };

    wb.on('files', (entries) => {
        entries.forEach((entry) => {
            entry.section ??= pendingSection ?? (grouping() ? guessSection(entry.file.name) : 'main');
        });
        pendingSection = null;
        const before = wb.files.map((entry) => entry.id).join();
        sortBySection();
        if (wb.files.map((entry) => entry.id).join() !== before) {
            // Keeps the shared list in section order; setFiles re-enters this hook once.
            wb.setFiles(wb.files);
            return;
        }
        renderBoard();
        wb.updateSummary();
    });

    wb.on('inspected', () => {
        renderBoard();
        wb.updateSummary();
    });

    wb.on('collect', () => {
        sectionsField.value = JSON.stringify(wb.files.map(sectionOf));
        numberingField.value = JSON.stringify(numberingRules());
    });

    wb.on('validate', () => {
        if (wb.files.some((entry) => entry.locked)) {
            return 'Ada PDF yang terkunci kata sandi. Buka dulu dengan Unlock PDF, lalu masukkan lagi.';
        }
        if (wb.files.some((entry) => entry.unreadable)) {
            return 'Ada berkas yang tidak dapat dibaca. Hapus berkas yang bertanda "Tidak terbaca".';
        }
        if (!config.wordReady && wb.files.some((entry) => fileKind(entry) === 'word')) {
            return 'Dokumen Word belum dapat diubah di server ini. Simpan sebagai PDF terlebih dahulu, lalu ganti kartunya.';
        }
        if (compressBox.checked && !compression.target()) {
            go(5);
            return 'Isi ukuran maksimal hasil kompresi, atau matikan kompresi.';
        }
        return null;
    });

    wb.on('reset', () => {
        go(1, { focus: false });
    });

    wb.on('result', (payload, container) => {
        if (payload.tool !== 'assignment-pack') {
            return;
        }
        const report = payload.report || {};
        const reset = form.querySelector('[data-region="result"] [data-reset]');
        if (reset) {
            reset.lastChild.textContent = 'Buat paket baru';
        }

        const stats = el('dl', { className: 'report' }, [
            ['Halaman', String(report.pages)],
            ['Dokumen', String(report.documents?.length ?? 0)],
            ['Ukuran', formatBytes(report.size ?? 0)],
        ].map(([label, text]) => el('div', { className: 'stat' }, [el('dt', { text: label }), el('dd', { text })])));

        const sections = el('ol', { className: 'pack-outline' }, (report.sections || []).map((section) => el('li', {}, [
            el('strong', { text: grouping() ? NAMES[section.section] : 'Dokumen' }),
            el('span', { className: 'muted', text: ` · ${section.pages} halaman · ${section.first_label ? `nomor ${section.first_label}${section.last_label && section.last_label !== section.first_label ? ` sampai ${section.last_label}` : ''}` : 'tanpa nomor'}` }),
            el('ol', {}, (report.documents || []).filter((doc) => doc.section === section.section).map((doc) => el('li', {
                text: `${doc.title} (halaman ${doc.start}${doc.pages > 1 ? `–${doc.start + doc.pages - 1}` : ''})`,
            }))),
        ])));

        container.append(stats, el('h3', { className: 'result-subhead', text: 'Isi paket' }), sections);
        if (report.compression) {
            container.append(el('h3', { className: 'result-subhead', text: 'Kompresi' }), renderCompressionReport(report.compression));
        }
        container.hidden = false;
    });

    go(1, { focus: false });
}
