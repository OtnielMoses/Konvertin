/*
 * Compare PDF result viewer: both versions side by side, the current change
 * highlighted on each, and a list to step through every change.
 */

import { button, el } from '../lib/dom.js';
import { renderPage } from '../lib/pdf.js';

export default function initCompare(wb) {
    wb.on('result', async (payload, extra, wide) => {
        if (payload.tool !== 'compare-pdf') {
            return;
        }
        const container = wide ?? extra;

        const report = payload.report || {};
        const changes = report.changes || [];
        const summary = report.summary || {};
        const docs = await Promise.all(wb.files.slice(0, 2).map((entry) => wb.pdf(entry)));
        let current = 0;

        container.hidden = false;
        container.append(el('div', { className: 'compare-summary' }, [
            el('span', { className: 'status-pill added', text: `${summary.added || 0} ditambahkan` }),
            el('span', { className: 'status-pill removed', text: `${summary.removed || 0} dihapus` }),
            el('span', { className: 'status-pill changed', text: `${summary.changed || 0} diubah` }),
        ]));

        if (!changes.length) {
            container.append(el('div', { className: 'alert alert-success', style: 'margin-top:12px' }, [el('p', { text: 'Tidak ada perbedaan teks antara kedua versi.' })]));
            return;
        }

        const columns = [0, 1].map((side) => {
            const canvas = el('canvas');
            const boxes = el('div', { className: 'overlay' });
            const caption = el('span', { className: 'muted' });
            const column = el('div', { className: 'compare-col' }, [
                el('h3', {}, [side === 0 ? 'Versi lama ' : 'Versi baru ', caption]),
                el('div', { className: 'editor-stage' }, [el('div', { className: 'page-wrap' }, [canvas, boxes])]),
            ]);
            return { canvas, boxes, caption, column, page: -1 };
        });

        const counter = el('span', { 'aria-live': 'polite' });
        const list = el('div', { className: 'change-list', role: 'list' });
        const prev = button('Sebelumnya', { iconName: 'chevron-left', className: 'btn btn-secondary btn-sm', onClick: () => show(current - 1) });
        const next = button('Berikutnya', { iconName: 'chevron-right', className: 'btn btn-secondary btn-sm', onClick: () => show(current + 1) });

        container.append(
            el('div', { className: 'toolbar-row', style: 'justify-content:center;margin:14px 0' }, [prev, counter, next]),
            el('div', { className: 'compare-grid' }, columns.map((column) => column.column)),
            el('h3', { text: 'Daftar perubahan', style: 'margin:16px 0 8px;font-size:0.9375rem;text-align:left;width:100%' }),
            list,
        );
        if (report.truncated) {
            container.append(el('p', { className: 'field-hint', text: 'Hanya 500 perubahan pertama yang ditampilkan. Berkas unduhan menandai semuanya.' }));
        }

        const typeLabel = { added: 'Ditambahkan', removed: 'Dihapus', changed: 'Diubah' };
        changes.forEach((change, index) => {
            const item = el('button', { type: 'button', className: 'change-item', role: 'listitem', onClick: () => show(index) }, [
                el('strong', { text: `${index + 1}. ${typeLabel[change.type]}` }),
                change.a_text ? el('del', { text: change.a_text }) : null,
                change.b_text ? el('ins', { text: change.b_text }) : null,
            ]);
            list.append(item);
        });

        async function drawSide(side, words) {
            const column = columns[side];
            const doc = docs[side];
            const page = words.length ? words[0].page : column.page === -1 ? 0 : column.page;
            if (page !== column.page) {
                const width = Math.min(column.column.clientWidth - 24, 520);
                await renderPage(doc, page + 1, column.canvas, { width, maxHeight: Math.max(320, window.innerHeight * 0.7) });
                column.boxes.style.width = column.canvas.style.width;
                column.boxes.style.height = column.canvas.style.height;
                column.page = page;
            }
            column.caption.textContent = `· halaman ${page + 1}`;
            column.boxes.replaceChildren(...words.filter((word) => word.page === page).map((word) => {
                const [x0, y0, x1, y1] = word.box;
                return el('span', {
                    className: `diff-box ${side === 0 ? 'removed' : 'added'} is-current`,
                    style: `left:${x0 * 100}%;top:${y0 * 100}%;width:${(x1 - x0) * 100}%;height:${(y1 - y0) * 100}%`,
                });
            }));
        }

        async function show(index) {
            current = Math.max(0, Math.min(changes.length - 1, index));
            const change = changes[current];
            counter.textContent = `Perubahan ${current + 1} dari ${changes.length}`;
            prev.disabled = current === 0;
            next.disabled = current === changes.length - 1;
            Array.from(list.children).forEach((item, position) => item.classList.toggle('is-current', position === current));
            list.children[current]?.scrollIntoView({ block: 'nearest' });
            await Promise.all([drawSide(0, change.a), drawSide(1, change.b)]);
        }

        show(0);
    });
}
