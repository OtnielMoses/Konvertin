/*
 * Smart Compression workspace: document facts on the left, target controls on the
 * right, and an actual-size comparison after processing.
 */

import { bindCompressionOptions, renderCompressionReport } from '../lib/compression.js';
import { el, formatBytes, icon } from '../lib/dom.js';

/** Several PDFs: the same target applies to each file; one row per file with its actual result. */
function renderBatchReport(items) {
    const reports = items.map((item) => ({ name: item.name, ...item.report }));
    const met = reports.filter((report) => report.target_met).length;
    const before = reports.reduce((sum, report) => sum + (report.before || 0), 0);
    const after = reports.reduce((sum, report) => sum + (report.after || 0), 0);
    const allMet = met === reports.length;

    return el('div', { className: 'compress-result' }, [
        el('div', { className: `alert ${allMet ? 'alert-success' : 'alert-warning'}` }, [
            icon(allMet ? 'check-circle' : 'alert'),
            el('p', { text: allMet
                ? `Semua ${reports.length} berkas di bawah target. Total ${formatBytes(before)} menjadi ${formatBytes(after)}.`
                : `${met} dari ${reports.length} berkas di bawah target. Berkas lain berisi hasil terkecil yang benar-benar dicapai.` }),
        ]),
        el('ul', { className: 'batch-sizes' }, reports.map((report) => el('li', { className: report.target_met ? 'is-good' : 'is-miss' }, [
            icon(report.target_met ? 'check' : 'alert'),
            el('span', { className: 'batch-name', text: report.name }),
            el('span', { className: 'batch-size', text: `${formatBytes(report.before)} → ${formatBytes(report.after)}` }),
        ]))),
        el('p', { className: 'field-hint', text: 'Semua hasil dikemas dalam satu ZIP. Untuk berkas yang belum memenuhi target, coba strategi "Utamakan ukuran" atau proses ulang satu per satu dengan target yang lebih longgar.' }),
    ]);
}

export default function initCompress(wb) {
    const { form } = wb;
    const insight = form.querySelector('[data-compress-insight]');
    const original = insight.querySelector('[data-insight-original]');
    const pages = insight.querySelector('[data-insight-pages]');
    const average = insight.querySelector('[data-insight-average]');
    const targetText = insight.querySelector('[data-insight-target]');
    const bar = insight.querySelector('[data-insight-bar]');
    const note = insight.querySelector('[data-insight-note]');

    const controls = bindCompressionOptions(form.querySelector('[data-compress-options]'), { onChange: update });

    function update(target = controls?.target()) {
        const entry = wb.files[0];
        insight.hidden = !entry;
        if (!entry) {
            return;
        }
        if (wb.files.length > 1) {
            // Several files: the target applies per file, so compare it with the largest one.
            const total = wb.files.reduce((sum, item) => sum + item.file.size, 0);
            const largest = Math.max(...wb.files.map((item) => item.file.size));
            const counted = wb.files.every((item) => item.pages);
            original.textContent = `${formatBytes(total)} (${wb.files.length} berkas)`;
            pages.textContent = counted ? String(wb.files.reduce((sum, item) => sum + item.pages, 0)) : '—';
            average.textContent = formatBytes(largest);
            average.previousElementSibling.textContent = 'Berkas terbesar';
            targetText.textContent = target ? `${formatBytes(target)} per berkas` : '—';
            bar.style.width = `${Math.max(2, Math.min(100, ((target ?? 0) / largest) * 100))}%`;
            const over = wb.files.filter((item) => target && item.file.size > target).length;
            note.textContent = !target
                ? 'Tentukan ukuran maksimal hasil di panel pengaturan.'
                : `Target berlaku untuk setiap berkas. ${over} dari ${wb.files.length} berkas perlu diperkecil. Berkas diproses bergantian; hasilnya bisa diunduh satu per satu atau sekaligus dalam ZIP.`;
            return;
        }
        average.previousElementSibling.textContent = 'Rata-rata per halaman';
        const size = entry.file.size;
        original.textContent = formatBytes(size);
        pages.textContent = entry.pages ? String(entry.pages) : entry.locked ? 'Terkunci' : '—';
        average.textContent = entry.pages ? formatBytes(Math.round(size / entry.pages)) : '—';
        targetText.textContent = target ? formatBytes(target) : '—';
        bar.style.width = `${Math.max(2, Math.min(100, ((target ?? 0) / size) * 100))}%`;
        if (!target) {
            note.textContent = 'Tentukan ukuran maksimal hasil di panel pengaturan.';
        } else if (target >= size) {
            note.textContent = 'Berkas sudah di bawah target. Konvertin tetap mencoba optimasi tanpa menurunkan kualitas gambar.';
        } else {
            note.textContent = `Perlu berkurang sekitar ${Math.round((1 - target / size) * 100)}%. Kompresi dimulai dari tahap paling ringan dan berhenti di tahap pertama yang sudah memenuhi target.`;
        }
    }

    wb.on('files', (entries) => {
        controls.setOriginal(entries.length ? Math.max(...entries.map((entry) => entry.file.size)) : null);
        update();
    });
    wb.on('inspected', () => update());

    wb.on('validate', () => {
        const target = controls.target();
        if (!target) {
            return 'Isi ukuran maksimal hasil, misalnya 2 MB.';
        }
        if (target < 10 * 1024) {
            return 'Ukuran target terlalu kecil. Gunakan minimal 10 KB.';
        }
        return null;
    });

    wb.on('result', (payload, container) => {
        if (payload.tool !== 'compress-pdf') {
            return;
        }
        if (payload.report?.items?.length) {
            container.append(renderBatchReport(payload.report.items));
        } else if (payload.report?.before) {
            container.append(renderCompressionReport(payload.report));
        } else {
            return;
        }
        container.hidden = false;
    });
}
