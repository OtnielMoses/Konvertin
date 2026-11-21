/*
 * Workbench: the shared upload -> process -> result flow used by every tool.
 * Tool-specific behaviour lives in resources/js/modes/*.js and plugs in through
 * the hooks on the `wb` object (collect, validate, result, reset, files).
 */

import { renderChecks } from './lib/checks.js';
import { bindShowWhen, el, extensionOf, formatBytes, icon, uid } from './lib/dom.js';
import { createFileCard } from './lib/filecard.js';
import { storeHandoff, takeHandoff } from './lib/handoff.js';
import { LOCAL_LIMIT_BYTES, runLocal, supportsLocal } from './lib/local.js';
import { measure, playFrom, removeWithMotion } from './lib/motion.js';
import { openPdf } from './lib/pdf.js';
import { hideResultPreview, showResultPreview } from './lib/preview.js';
import { createQueue } from './lib/queue.js';
import { conversionData, postConversion } from './lib/request.js';
import { renderImageGallery, renderOcrText } from './lib/results.js';
import { makeSortable } from './lib/sortable.js';

const MODES = {
    bookmarks: () => import('./modes/bookmarks.js'),
    thumbs: () => import('./modes/thumbs.js'),
    pages: () => import('./modes/pages.js'),
    editor: () => import('./modes/editor.js'),
    forms: () => import('./modes/editor.js'),
    preview: () => import('./modes/preview.js'),
    scan: () => import('./modes/scan.js'),
    workflow: () => import('./modes/workflow.js'),
    compress: () => import('./modes/compress.js'),
    pack: () => import('./modes/pack.js'),
};

const naturalOrder = new Intl.Collator('id', { numeric: true, sensitivity: 'base' });

function formatElapsed(seconds) {
    return `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`;
}

export function initWorkbench(form) {
    const config = JSON.parse(form.dataset.config);
    const { messages } = config;

    const regions = {
        input: form.querySelector('[data-region="input"]'),
        progress: form.querySelector('[data-region="progress"]'),
        result: form.querySelector('[data-region="result"]'),
        downloaded: form.querySelector('[data-region="downloaded"]'),
        queue: form.querySelector('[data-region="queue"]'),
    };

    const settings = form.querySelector('[data-settings]');
    const fileInput = form.querySelector('[data-file-input]');
    const urlInput = form.querySelector('[data-url-input]');
    const dropzone = form.querySelector('[data-dropzone]');
    const filePanel = form.querySelector('[data-file-panel]');
    const fileList = form.querySelector('[data-file-list]');
    const fileEmpty = form.querySelector('[data-file-empty]');
    const fileCount = form.querySelector('[data-file-count]');
    const errorRegion = form.querySelector('[data-error-region]');
    const submit = form.querySelector('[data-submit]');
    const progressLabel = form.querySelector('[data-progress-label]');
    const progressValue = form.querySelector('[data-progress-value]');
    const progressTrack = form.querySelector('[data-progress-track]');
    const progressActivity = form.querySelector('[data-progress-activity]');
    const progressElapsed = form.querySelector('[data-progress-elapsed]');
    // Share of the bar for the upload; the rest follows the server's real steps.
    const uploadShare = config.usesUrl ? 0 : 0.3;
    let shown = 0;

    let busy = false;
    let elapsedTimer = null;

    // Merge, Rotate, Organize: in this browser (nothing uploaded) or on the server.
    const locationInputs = Array.from(form.querySelectorAll('[name="processing_location"]'));
    const processingBadge = form.querySelector('[data-processing-badge]');
    const processingHint = form.querySelector('[data-processing-hint]');
    const serverHint = processingHint?.textContent ?? '';
    const isLocal = () => supportsLocal(config.slug) && locationInputs.find((input) => input.checked)?.value === 'browser';

    function showLocation() {
        if (!locationInputs.length) {
            return;
        }
        const local = isLocal();
        if (processingBadge) {
            processingBadge.lastChild.textContent = local ? 'Diproses di peramban' : 'Diproses di server Konvertin';
            processingBadge.classList.toggle('is-local', local);
        }
        if (processingHint) {
            processingHint.textContent = local
                ? 'Diproses di peramban ini: berkas tidak diunggah ke server. Hasil hanya ada di tab ini sampai Anda menutup atau memuat ulang halaman.'
                : serverHint;
        }
    }
    if (locationInputs.length && !supportsLocal(config.slug)) {
        // No Web Worker support: keep the server, which always works.
        locationInputs.find((input) => input.value === 'server').checked = true;
        locationInputs.find((input) => input.value === 'browser').disabled = true;
    }
    locationInputs.forEach((input) => input.addEventListener('change', showLocation));
    showLocation();

    const wb = {
        form,
        config,
        files: [],
        hooks: { files: [], collect: [], validate: [], result: [], reset: [], inspected: [] },
        showsFileList: !['scan', 'pack'].includes(config.mode),

        on(name, callback) {
            this.hooks[name].push(callback);
        },

        /** Returns (and caches) the pdf.js document for a selected file. */
        pdf(entry) {
            entry.pdfPromise ??= openPdf(entry.file);
            return entry.pdfPromise;
        },

        addFiles(list) {
            const incoming = Array.from(list).map((file) => ({ id: uid(), file, pages: null, locked: false }));
            if (incoming.length === 0) {
                return;
            }
            // Too many files are reported by validation instead of being dropped silently.
            wb.files = config.maxFiles > 1 ? wb.files.concat(incoming) : [incoming[0]];
            showError('');
            filesChanged();
        },

        setFiles(entries) {
            wb.files = entries;
            filesChanged();
        },

        showError,
    };

    const actionSummary = form.querySelector('[data-action-summary]');

    /** One line above the main button: how many files, their size, and pages once known. */
    function updateSummary() {
        if (!actionSummary || wb.describeSelection) {
            if (actionSummary && wb.describeSelection) {
                actionSummary.textContent = wb.describeSelection();
            }
            return;
        }
        if (!wb.files.length) {
            actionSummary.textContent = 'Belum ada berkas.';
            return;
        }
        const bytes = wb.files.reduce((sum, entry) => sum + entry.file.size, 0);
        const pages = wb.files.reduce((sum, entry) => sum + (entry.pages || 0), 0);
        const allCounted = wb.files.every((entry) => entry.pages);
        actionSummary.textContent = `${wb.files.length} berkas · ${formatBytes(bytes)}${pages ? ` · ${pages}${allCounted ? '' : '+'} halaman` : ''}`;
    }
    wb.updateSummary = updateSummary;

    function filesChanged() {
        renderFiles();
        updateSummary();
        wb.hooks.files.forEach((callback) => callback(wb.files));
        if (!wb.showsFileList) {
            dropzone?.classList.toggle('is-compact', wb.files.length > 0);
        }
    }

    // -------------------------------------------------------------- errors

    function showError(message) {
        errorRegion.replaceChildren();

        if (!message) {
            return;
        }

        errorRegion.append(
            el('div', { className: 'alert alert-error', role: 'alert' }, [icon('alert'), el('p', { text: message })]),
        );
    }

    // -------------------------------------------------------------- files

    const cards = new Map();
    const compactModes = ['editor', 'forms', 'pages', 'preview', 'compare', 'thumbs', 'workflow', 'bookmarks'];
    const orderable = config.maxFiles > 1;
    let addCard = null;

    if (fileList) {
        fileList.classList.toggle('is-compact', compactModes.includes(config.mode));
        const addLabel = el('span', { className: 'file-add-label', 'data-add-label': true });
        addCard = el('li', { className: 'file-add', 'data-sort-end': true }, [
            el('button', { type: 'button', className: 'file-add-button', onClick: () => fileInput?.click() }, [
                el('span', { className: 'file-add-icon' }, [icon('plus')]),
                addLabel,
                el('span', { className: 'file-add-hint', text: `${config.formatsText} · ${config.limitText}` }),
            ]),
        ]);
        fileList.append(addCard);

        if (orderable) {
            makeSortable({
                root: fileList,
                itemSelector: '.file-item',
                containerSelector: '[data-file-list]',
                onDrop: () => {
                    const order = Array.from(fileList.querySelectorAll(':scope > .file-item')).map((node) => node.dataset.id);
                    wb.files.sort((a, b) => order.indexOf(a.id) - order.indexOf(b.id));
                    filesChanged();
                    announce(`Urutan diperbarui. ${wb.files.map((entry, index) => `${index + 1}. ${entry.file.name}`).join(', ')}`);
                },
            });
        }
    }

    const liveRegion = form.querySelector('[data-order-status]');
    function announce(text) {
        if (liveRegion) {
            liveRegion.textContent = text;
        }
    }

    function moveFile(from, to) {
        if (to < 0 || to >= wb.files.length) {
            return;
        }
        const [moved] = wb.files.splice(from, 1);
        wb.files.splice(to, 0, moved);
        filesChanged();
        const node = cards.get(moved.id)?.node;
        (node?.querySelector(`[data-move="${to < from ? 'back' : 'forward'}"]:not(:disabled)`) ?? node?.querySelector('.file-remove'))?.focus();
        announce(`${moved.file.name} sekarang di posisi ${to + 1} dari ${wb.files.length}.`);
    }

    function removeFile(entry) {
        const index = wb.files.indexOf(entry);
        wb.files.splice(index, 1);
        filesChanged();
        const next = wb.files[Math.min(index, wb.files.length - 1)];
        (next ? cards.get(next.id)?.node.querySelector('.file-remove') : null)?.focus() ?? addCard?.querySelector('button')?.focus();
        announce(`${entry.file.name} dihapus dari daftar.`);
    }

    function cardLabel(index) {
        if (config.slug === 'compare-pdf') {
            return index === 0 ? 'Lama' : 'Baru';
        }
        return orderable ? String(index + 1) : null;
    }

    /** Natural name order, so "foto 2" comes before "foto 10". */
    form.querySelector('[data-sort-names]')?.addEventListener('click', () => {
        wb.files.sort((a, b) => naturalOrder.compare(a.file.name, b.file.name));
        filesChanged();
        announce(`Diurutkan menurut nama. ${wb.files.map((entry, index) => `${index + 1}. ${entry.file.name}`).join(', ')}`);
    });

    function renderFiles() {
        if (!fileList || !wb.showsFileList) {
            if (filePanel) {
                filePanel.hidden = true;
            }
            return;
        }

        const showing = wb.showsFileList && wb.files.length > 0;
        filePanel.hidden = !showing;
        if (wb.showsFileList) {
            dropzone.hidden = showing;
        }
        fileEmpty.hidden = wb.files.length > 0;
        fileCount.textContent = wb.files.length
            ? `${wb.files.length}${config.maxFiles > 1 ? ` dari maks. ${config.maxFiles}` : ''} berkas · ${formatBytes(wb.files.reduce((sum, entry) => sum + entry.file.size, 0))}`
            : '';

        const ids = new Set(wb.files.map((entry) => entry.id));
        const staying = () => [...Array.from(cards.values()).map((card) => card.node), addCard];
        cards.forEach((card, id) => {
            if (!ids.has(id)) {
                cards.delete(id);
                removeWithMotion(card.node, staying());
            }
        });

        const rects = measure(staying());
        wb.files.forEach((entry, index) => {
            let card = cards.get(entry.id);
            if (!card) {
                card = createFileCard(entry, wb, {
                    onRemove: () => removeFile(entry),
                    onMove: (step) => moveFile(wb.files.indexOf(entry), wb.files.indexOf(entry) + step),
                });
                cards.set(entry.id, card);
            }
            card.update({ index, total: wb.files.length, label: cardLabel(index), orderable });
            // Move a card only when it is out of place, so untouched cards are never re-inserted.
            const expected = fileList.querySelectorAll(':scope > .file-item')[index];
            if (expected !== card.node) {
                fileList.insertBefore(card.node, expected ?? addCard);
            }
        });
        playFrom(rects);

        const full = config.maxFiles > 1 && wb.files.length >= config.maxFiles;
        addCard.hidden = full;
        addCard.querySelector('[data-add-label]').textContent = config.maxFiles > 1 ? 'Tambah berkas' : 'Ganti berkas';
    }

    // ---------------------------------------------------------- validation

    async function validate() {
        if (config.usesUrl) {
            const value = urlInput.value.trim();
            if (value === '') {
                return messages.urlRequired;
            }
            try {
                const url = new URL(value);
                return (url.protocol === 'http:' || url.protocol === 'https:') && url.hostname.includes('.') ? null : messages.urlInvalid;
            } catch (error) {
                return messages.urlInvalid;
            }
        }

        if (wb.files.length === 0) {
            return messages.msg03;
        }
        if (wb.files.some((entry) => !config.extensions.includes(extensionOf(entry.file.name)))) {
            return messages.msg01;
        }
        if (wb.files.length > config.maxFiles) {
            return config.maxFiles === config.minFiles ? messages.filesExact : `Maksimal ${config.maxFiles} berkas per proses. Saat ini ${wb.files.length} berkas dipilih.`;
        }
        // Photos have their own limit; documents use the per-file limit.
        const isPhoto = (entry) => ['jpg', 'jpeg', 'png'].includes(extensionOf(entry.file.name));
        const limitFor = (entry) => (isPhoto(entry) ? config.maxImageBytes : config.maxBytes);
        const oversized = wb.files.find((entry) => entry.file.size > limitFor(entry));
        if (oversized) {
            return messages.fileTooLarge
                .replace(':name', oversized.file.name)
                .replace(':size', formatBytes(oversized.file.size))
                .replace(':limit', `${formatBytes(limitFor(oversized))} per ${isPhoto(oversized) ? 'foto' : 'berkas'}`);
        }
        const total = wb.files.reduce((sum, entry) => sum + entry.file.size, 0);
        if (config.maxRequestBytes && total > config.maxRequestBytes * 0.98) {
            return messages.requestTooLarge
                .replace(':size', formatBytes(total))
                .replace(':limit', formatBytes(config.maxRequestBytes));
        }
        if (wb.files.length < config.minFiles) {
            if (config.isMerge) {
                return messages.mergeMinimum;
            }
            return config.maxFiles === config.minFiles ? messages.filesExact : messages.filesMinimum;
        }
        if (wb.files.some((entry) => entry.pages && entry.pages > config.maxPages)) {
            return `Dokumen melebihi batas ${config.maxPages} halaman.`;
        }
        if (isLocal() && total > LOCAL_LIMIT_BYTES) {
            return `Total berkas ${formatBytes(total)} terlalu besar untuk diproses di peramban (maks. ${formatBytes(LOCAL_LIMIT_BYTES)}). Pilih "Di server Konvertin".`;
        }

        for (const check of wb.hooks.validate) {
            const problem = await check();
            if (problem) {
                return problem;
            }
        }

        return null;
    }

    // ------------------------------------------------------------ progress

    function showRegion(name) {
        Object.entries(regions).forEach(([key, node]) => {
            if (node) {
                node.hidden = key !== name;
            }
        });
        settings.closest('.settings').hidden = name !== 'input';
        form.classList.toggle('is-queue', name === 'queue');
    }

    function stopTimer() {
        if (elapsedTimer) {
            clearInterval(elapsedTimer);
            elapsedTimer = null;
        }
    }

    function startClock() {
        stopTimer();
        const started = Date.now();
        progressElapsed.textContent = '0:00';
        elapsedTimer = setInterval(() => {
            progressElapsed.textContent = formatElapsed(Math.floor((Date.now() - started) / 1000));
        }, 1000);
    }

    /** Overall bar, 0..1. It only moves forward and only on real events. */
    function setBar(fraction, { measurable = true } = {}) {
        shown = Math.max(shown, Math.min(1, fraction));
        const percent = Math.round(shown * 100);
        progressTrack.firstElementChild.style.width = `${percent}%`;
        progressTrack.setAttribute('aria-valuenow', String(percent));
        progressTrack.classList.toggle('is-working', !measurable);
        progressValue.textContent = `${percent}%`;
    }

    function setStep(current) {
        const order = ['upload', 'process', 'finish'];
        form.querySelectorAll('[data-progress-step]').forEach((item) => {
            const position = order.indexOf(item.dataset.progressStep);
            item.classList.toggle('is-done', position < order.indexOf(current));
            item.classList.toggle('is-current', item.dataset.progressStep === current);
        });
        regions.progress.dataset.phase = current;
    }

    function setActivity(text) {
        if (progressActivity.textContent === text) {
            return;
        }
        progressActivity.textContent = text;
        progressActivity.classList.remove('is-changing');
        void progressActivity.offsetWidth;
        progressActivity.classList.add('is-changing');
    }

    function showUploading(loaded, total) {
        progressLabel.textContent = 'Mengunggah berkas';
        setStep('upload');
        setBar((total ? loaded / total : 0) * uploadShare);
        setActivity(total
            ? `Mengirim ${wb.files.length} berkas · ${formatBytes(loaded)} dari ${formatBytes(total)}`
            : `Mengirim ${wb.files.length} berkas`);
    }

    function showProcessing() {
        if (regions.progress.dataset.phase === 'process') {
            return;
        }
        progressLabel.textContent = 'Memproses berkas';
        setStep('process');
        setBar(uploadShare, { measurable: false });
        setActivity(config.usesUrl ? 'Menghubungi halaman web' : 'Berkas diterima, menunggu giliran diproses');
    }

    /** A step reported by the server while it works. */
    function showServerProgress(event) {
        showProcessing();
        setActivity(event.label);
        if (event.fraction === null || event.fraction === undefined) {
            setBar(shown, { measurable: false });
        } else {
            setBar(uploadShare + event.fraction * (0.98 - uploadShare));
        }
    }

    function setBusy(state) {
        busy = state;
        submit.disabled = state;
        submit.setAttribute('aria-busy', String(state));
    }

    function fail(message) {
        stopTimer();
        setBusy(false);
        showRegion('input');
        showError(message);
        submit.focus();
    }

    // -------------------------------------------------------------- result

    function stat(label, value, good = false) {
        return el('div', { className: `stat${good ? ' is-good' : ''}` }, [el('dt', { text: label }), el('dd', { text: value })]);
    }

    function renderReport(payload) {
        const report = payload.report || {};
        const box = regions.result.querySelector('[data-report]');
        const extra = regions.result.querySelector('[data-report-extra]');
        const wide = regions.result.querySelector('[data-report-wide]');
        wide.replaceChildren();
        wide.hidden = true;
        box.replaceChildren();
        extra.replaceChildren();
        box.hidden = true;
        extra.hidden = true;
        const stats = [];

        switch (payload.tool) {
            case 'jpg-to-pdf':
                if (report.files) {
                    stats.push(stat('Berkas PDF', String(report.files)));
                }
                break;
            case 'repair-pdf': {
                const labels = { intact: 'Berkas utuh, tidak ada kerusakan yang terdeteksi.', repaired: 'Struktur PDF berhasil diperbaiki.', partial: 'Sebagian berhasil dipulihkan. Halaman yang rusak dilewati.' };
                extra.append(el('div', { className: `alert ${report.status === 'partial' ? 'alert-warning' : 'alert-success'}` }, [icon(report.status === 'partial' ? 'alert' : 'check-circle'), el('p', { text: labels[report.status] ?? '' })]));
                stats.push(stat('Halaman dipulihkan', `${report.pages_recovered} dari ${report.pages_total}`));
                if (report.failed_pages?.length) {
                    stats.push(stat('Halaman gagal', report.failed_pages.join(', ')));
                }
                break;
            }
            case 'ocr-pdf': {
                stats.push(stat('Halaman di-OCR', `${report.pages_ocr} dari ${report.pages_total}`), stat('Kata terbaca', String(report.words)));
                const notes = [];
                if (report.skipped_pages?.length) {
                    notes.push(`Halaman ${report.skipped_pages.join(', ')} sudah punya teks, jadi tidak di-OCR.`);
                }
                if (report.failed_pages?.length) {
                    notes.push(`Halaman ${report.failed_pages.join(', ')} gagal dikenali dan dibiarkan tanpa lapisan teks.`);
                }
                if (notes.length) {
                    extra.append(el('div', { className: `alert ${report.failed_pages?.length ? 'alert-warning' : 'alert-info'}` }, [icon('info'), el('p', { text: notes.join(' ') })]));
                }
                if (report.pages?.length) {
                    wide.append(renderOcrText(report, applyCorrections));
                    wide.hidden = false;
                }
                break;
            }
            case 'pdf-to-jpg':
                if (report.images?.length) {
                    stats.push(stat('Gambar', String(report.files)), stat('Format', `${String(report.format).toUpperCase()} · ${report.dpi} DPI`), stat('Total ukuran', formatBytes(report.total_size)));
                    if (report.images.length > 1) {
                        wide.append(renderImageGallery(payload));
                        wide.hidden = false;
                    }
                }
                break;
            case 'bookmark-pdf':
                stats.push(stat('Bookmark', String(report.bookmarks)), stat('Tingkat', String(report.levels)));
                if (report.unchanged) {
                    extra.append(el('div', { className: 'alert alert-info' }, [icon('info'), el('p', { text: 'Tidak ada perubahan, jadi bookmark asli dipertahankan apa adanya.' })]));
                }
                break;
            case 'split-pdf':
                stats.push(stat('Berkas hasil', String(report.files)));
                break;
            case 'redact-pdf':
                stats.push(stat('Bagian diredaksi', String(report.marks)), stat('Halaman terdampak', String(report.pages)));
                if (report.verified) {
                    extra.append(el('div', { className: 'alert alert-success' }, [icon('check-circle'), el('p', {
                        text: 'Terverifikasi: hasil diperiksa ulang, tidak ada teks yang masih bisa dicari atau disalin di bagian yang diredaksi, dan metadata sudah kosong.',
                    })]));
                }
                break;
            case 'preflight-pdf': {
                const warnings = report.warnings ?? 0;
                if (report.scanned_pages?.length) {
                    extra.append(el('div', { className: 'alert alert-warning' }, [icon('alert'), el('div', {}, [
                        el('p', { text: `${report.scanned_pages.length} halaman berupa gambar tanpa teks. OCR membuat teksnya bisa dicari dan disalin.` }),
                        el('button', { type: 'button', className: 'btn btn-secondary btn-sm', onClick: () => handOriginal('ocr-pdf') }, ['Jalankan OCR untuk PDF ini']),
                    ])]));
                }
                stats.push(stat('Halaman diperiksa', String(report.pages)), stat('Perlu dicek', String(warnings), warnings === 0));
                if (report.removed?.length) {
                    stats.push(stat('Halaman dihapus', String(report.removed.length), true));
                    extra.append(el('div', { className: 'alert alert-success' }, [icon('check-circle'), el('p', {
                        text: `Halaman ${report.removed.join(', ')} dihapus. PDF hasil berisi ${report.pages_after} halaman.`,
                    })]));
                } else if (report.cleaned) {
                    extra.append(el('div', { className: 'alert alert-info' }, [icon('info'), el('p', { text: 'Tidak ada halaman kosong atau ganda yang perlu dihapus.' })]));
                }
                wide.append(renderChecks(report.checks || [], { heading: 'Hasil pemeriksaan' }));
                wide.hidden = false;
                break;
            }
            case 'pdf-forms':
                stats.push(stat('Field diisi', String(report.filled)), stat('Field baru', String(report.added)));
                break;
            case 'pdf-to-excel':
                stats.push(stat('Tabel ditemukan', String(report.tables)));
                break;
            case 'scan-to-pdf':
                stats.push(stat('Halaman', String(report.pages)));
                if (report.edges_detected) {
                    stats.push(stat('Tepi kertas ditemukan', `${report.edges_detected} foto`));
                }
                if (report.ocr) {
                    stats.push(stat('Kata terbaca (OCR)', String(report.words)));
                }
                break;
            case 'create-workflow':
                extra.append(el('ol', { className: 'wf-status' }, (report.steps || []).map((step, index) => el('li', {}, [icon('check'), `Langkah ${index + 1}: ${wb.workflowLabel?.(step.op) ?? step.op} selesai`]))));
                break;
            default:
                break;
        }

        // Conversions from PDF report what was measurably carried over (text, images, tables).
        if (report.quality?.checks?.length) {
            extra.append(renderChecks(report.quality.checks, { heading: 'Pemeriksaan hasil', grouped: false }));
            const scanned = report.quality.checks.some((check) => check.level === 'warn' && /tanpa teks|lapisan teks/.test(check.title));
            const ocrBox = form.querySelector('[name="options[ocr_first]"][type="checkbox"]');
            if (scanned && ocrBox && !ocrBox.checked && !ocrBox.disabled) {
                extra.append(el('button', { type: 'button', className: 'btn btn-secondary btn-block', onClick: () => {
                    ocrBox.checked = true;
                    rerun(true);
                } }, [icon('refresh'), 'Ulangi dengan OCR untuk halaman scan']));
            }
        }
        if (report.ocr_pages) {
            stats.push(stat('Halaman di-OCR', String(report.ocr_pages)));
        }

        if (stats.length) {
            box.append(...stats);
            box.hidden = false;
        }
        if (extra.childElementCount) {
            extra.hidden = false;
        }

        wb.hooks.result.forEach((callback) => callback(payload, extra, wide));
    }

    /** Fill the bar, mark the last step, then show the result. */
    function finish(payload) {
        progressLabel.textContent = 'Selesai';
        setStep('finish');
        setBar(1);
        setActivity('Hasil siap diperiksa');
        setTimeout(() => succeed(payload), 450);
    }

    const resultNote = regions.result.querySelector('[data-result-note]');
    const resultExpiry = regions.result.querySelector('[data-result-expiry]');
    const serverTexts = [resultNote?.textContent, resultExpiry?.textContent];
    let localUrl = null;

    function releaseLocal() {
        if (localUrl) {
            URL.revokeObjectURL(localUrl);
            localUrl = null;
        }
    }
    window.addEventListener('pagehide', releaseLocal);

    function succeed(payload) {
        stopTimer();
        setBusy(false);
        lastPayload = payload;

        const link = regions.result.querySelector('[data-result-link]');
        link.href = payload.download_url;
        // The file name is shown in the card above; the button stays short.
        link.lastChild.textContent = payload.local ? 'Simpan hasil' : 'Unduh hasil';
        if (payload.local) {
            link.setAttribute('download', payload.filename);
            resultNote.textContent = 'Selesai diproses di peramban Anda. Berkas tidak diunggah ke server.';
            resultExpiry.textContent = 'Hasil hanya ada di tab ini dan bisa diunduh berulang kali. Menutup atau memuat ulang halaman menghapusnya dari memori.';
        } else {
            link.removeAttribute('download');
            [resultNote.textContent, resultExpiry.textContent] = serverTexts;
        }
        regions.result.querySelector('[data-result-name]').textContent = payload.filename;
        regions.result.querySelector('[data-result-size]').textContent = payload.size;
        const pagesLabel = regions.result.querySelector('[data-result-pages]');
        pagesLabel.textContent = payload.report?.pages ? ` · ${payload.report.pages} halaman` : '';

        renderReport(payload);
        showRegion('result');
        regions.result.querySelector('[data-result-heading]').focus();
        const previewSection = regions.result.querySelector('[data-result-preview]');
        showResultPreview(previewSection, payload, (pages) => {
            pagesLabel.textContent = ` · ${pages} halaman`;
        });
        // Results without a file preview (ZIP) still show their report, e.g. the image gallery.
        const wideReport = previewSection.querySelector('[data-report-wide]');
        if (!payload.preview_url && !wideReport.hidden) {
            previewSection.hidden = false;
            previewSection.querySelector('[data-preview-count]').textContent = '';
            previewSection.querySelector('[data-preview-note]').textContent = '';
        }
        offerHandoff(payload);
    }

    // ------------------------------------------------------------- handoff

    const handoff = regions.result.querySelector('[data-handoff]');
    const handoffSelect = handoff?.querySelector('[data-handoff-select]');
    const handoffHint = handoff?.querySelector('[data-handoff-hint]');
    handoffSelect?.querySelector(`option[data-slug="${config.slug}"]`)?.remove();
    let handoffSource = null;
    let lastPayload = null;

    /**
     * What the next tool receives: the PDF result, or the original PDF when the
     * result is only a report (Preflight Check without cleaning).
     */
    function offerHandoff(payload) {
        const reportOnly = payload.tool === 'preflight-pdf' && payload.report && !payload.report.cleaned;
        if (reportOnly && wb.files[0]) {
            handoffSource = { original: wb.files[0].file };
        } else {
            handoffSource = payload.preview_url && payload.preview_type === 'pdf' ? { payload } : null;
        }
        if (handoff) {
            handoff.hidden = !handoffSource || !handoffSelect?.options.length;
            handoffHint.textContent = handoffSource?.original
                ? 'Yang diteruskan adalah PDF asli Anda, bukan laporannya, tanpa perlu diunggah ulang dari komputer.'
                : 'Hasil ini dipindahkan otomatis ke alat berikutnya, jadi tidak perlu diunduh lalu diunggah lagi. Tautan unduh di sini tetap berlaku.';
        }
    }

    async function sendTo(url, source) {
        try {
            let blob;
            let name;
            if (source.original) {
                blob = source.original;
                name = source.original.name;
            } else {
                const response = await fetch(source.payload.preview_url, { credentials: 'same-origin' });
                if (!response.ok) {
                    throw new Error(`handoff ${response.status}`);
                }
                blob = await response.blob();
                name = source.payload.filename;
            }
            await storeHandoff({ blob, name, from: config.name });
            window.location.href = url;
        } catch (error) {
            showError('Hasil tidak dapat diteruskan (mungkin sudah diunduh atau kedaluwarsa). Unduh hasilnya, lalu unggah di alat berikutnya.');
        }
    }

    function handOriginal(slug) {
        const option = handoffSelect?.querySelector(`option[data-slug="${slug}"]`);
        if (option && wb.files[0]) {
            sendTo(option.value, { original: wb.files[0].file });
        }
    }

    handoff?.querySelector('[data-handoff-go]')?.addEventListener('click', () => {
        if (handoffSource && handoffSelect.value) {
            sendTo(handoffSelect.value, handoffSource);
        }
    });

    /** Loads a file handed over by the previous tool into this tool's file list. */
    async function receiveHandoff() {
        const incoming = await takeHandoff();
        if (!incoming || !fileInput || !config.extensions.includes('pdf')) {
            return;
        }
        if (Date.now() - incoming.at > config.ttlMinutes * 60 * 1000) {
            showError('Berkas dari alat sebelumnya sudah kedaluwarsa. Unggah berkasnya secara manual.');
            return;
        }
        wb.addFiles([new File([incoming.blob], incoming.name, { type: 'application/pdf' })]);
        errorRegion.append(el('div', { className: 'alert alert-info', role: 'status' }, [
            icon('info'),
            el('p', { text: `Berkas dari ${incoming.from} sudah dimuat: ${incoming.name}. Atur pengaturannya, lalu proses.` }),
        ]));
    }

    /** Back to the settings with the same files, optionally starting again at once. */
    function rerun(start = false) {
        stopTimer();
        setBusy(false);
        showRegion('input');
        showError('');
        if (start) {
            form.requestSubmit();
        } else {
            submit.focus();
        }
    }

    function applyCorrections(corrections) {
        const field = form.querySelector('[data-ocr-corrections]');
        if (field) {
            field.value = JSON.stringify(corrections);
            rerun(true);
        }
    }

    function reset() {
        stopTimer();
        if (queue?.running) {
            return;
        }
        hideResultPreview(regions.result.querySelector('[data-result-preview]'));
        releaseLocal();
        const corrections = form.querySelector('[data-ocr-corrections]');
        if (corrections) {
            corrections.value = '';
        }
        setBusy(false);
        wb.files = [];
        if (fileInput) {
            fileInput.value = '';
        }
        if (urlInput) {
            urlInput.value = '';
        }
        filesChanged();
        wb.hooks.reset.forEach((callback) => callback());
        showError('');
        showRegion('input');
        (fileInput ?? urlInput)?.focus();
    }

    // --------------------------------------------------------------- send

    function startProgress() {
        showError('');
        shown = 0;
        delete regions.progress.dataset.phase;
        showRegion('progress');
        startClock();
    }

    async function send() {
        for (const collect of wb.hooks.collect) {
            await collect();
        }

        startProgress();
        if (config.usesUrl) {
            showProcessing();
        } else {
            showUploading(0, 0);
        }

        const outcome = await postConversion(form.action, conversionData(form, wb.files.map((entry) => entry.file)), {
            messages,
            onUpload: showUploading,
            onUploaded: showProcessing,
            onProgress: showServerProgress,
        }).promise;

        if (outcome.ok) {
            finish(outcome.payload);
        } else {
            fail(outcome.message);
        }
    }

    /** Merge, Rotate, Organize in this browser: nothing is uploaded. */
    async function sendLocal() {
        for (const collect of wb.hooks.collect) {
            await collect();
        }

        const options = {};
        new FormData(form).forEach((value, key) => {
            const match = key.match(/^options\[(.+)\]$/);
            if (match && typeof value === 'string') {
                options[match[1]] = value;
            }
        });

        startProgress();
        progressLabel.textContent = 'Memproses di peramban';
        setStep('process');
        setActivity('Membaca berkas di peramban, tanpa mengunggah');

        try {
            const files = wb.files.map((entry) => entry.file);
            const result = await runLocal(config.slug, {
                files,
                options,
                extra: form.querySelector('[data-aux-input="extra"]')?.files?.[0] ?? null,
                maxPages: config.maxPages,
            }, (event) => {
                setActivity(event.label);
                setBar(event.fraction ?? shown, { measurable: event.fraction !== null });
            });

            releaseLocal();
            localUrl = URL.createObjectURL(result.blob);
            const stem = files[0].name.replace(/\.pdf$/i, '');
            const names = { 'merge-pdf': 'gabungan.pdf', 'rotate-pdf': `${stem}-diputar.pdf`, 'organize-pdf': `${stem}-disusun.pdf` };
            finish({
                local: true,
                tool: config.slug,
                filename: names[config.slug],
                size: formatBytes(result.blob.size),
                bytes: result.blob.size,
                download_url: localUrl,
                preview_url: localUrl,
                preview_type: 'pdf',
                report: { pages: result.pages },
            });
        } catch (error) {
            fail(error.message);
        }
    }

    const queue = regions.queue ? createQueue(wb, { region: regions.queue, messages }) : null;

    async function sendQueue() {
        for (const collect of wb.hooks.collect) {
            await collect();
        }
        showError('');
        showRegion('queue');
        setBusy(false);
        regions.queue.querySelector('[data-queue-heading]').focus();
        queue.start(wb.files.slice());
    }

    form.addEventListener('submit', async (event) => {
        event.preventDefault();

        // Guard against double submission while a request is running.
        if (busy || submit.disabled) {
            return;
        }

        setBusy(true);
        const problem = await validate();

        if (problem) {
            setBusy(false);
            showError(problem);
            return;
        }

        if (isLocal()) {
            sendLocal();
        } else if (queue && config.queue && wb.files.length > 1) {
            sendQueue();
        } else {
            send();
        }
    });

    // ------------------------------------------------------------- inputs

    if (fileInput) {
        fileInput.addEventListener('change', () => {
            wb.addFiles(fileInput.files);
            fileInput.value = '';
        });

        // Files can be dropped anywhere on the work area, also after the drop zone
        // has made room for the card grid.
        const target = regions.input;
        const hasFiles = (event) => Array.from(event.dataTransfer?.types ?? []).includes('Files');
        let depth = 0;
        const setOver = (state) => {
            target.classList.toggle('is-file-over', state);
            dropzone.classList.toggle('is-dragging', state);
        };
        target.addEventListener('dragenter', (event) => {
            if (hasFiles(event)) {
                event.preventDefault();
                depth += 1;
                setOver(true);
            }
        });
        target.addEventListener('dragover', (event) => {
            if (hasFiles(event)) {
                event.preventDefault();
                event.dataTransfer.dropEffect = 'copy';
            }
        });
        target.addEventListener('dragleave', (event) => {
            if (hasFiles(event)) {
                depth = Math.max(0, depth - 1);
                setOver(depth > 0);
            }
        });
        target.addEventListener('drop', (event) => {
            if (!hasFiles(event)) {
                return;
            }
            event.preventDefault();
            depth = 0;
            setOver(false);
            wb.addFiles(event.dataTransfer.files);
        });
    }

    urlInput?.addEventListener('input', () => showError(''));

    regions.result.querySelector('[data-result-link]').addEventListener('click', () => {
        // A browser-made result stays in this tab and can be saved again.
        if (lastPayload?.local) {
            return;
        }
        // The browser starts the download; the server deletes the files once sent.
        setTimeout(() => {
            showRegion('downloaded');
            form.querySelector('[data-downloaded-heading]').focus();
        }, 400);
    });

    form.querySelectorAll('[data-reset], [data-queue-reset]').forEach((node) => node.addEventListener('click', reset));
    form.querySelector('[data-rerun]')?.addEventListener('click', () => rerun());

    bindShowWhen(form);
    renderFiles();
    wb.on('inspected', updateSummary);

    const extras = {
        'compare-pdf': () => import('./modes/compare.js'),
        'sign-pdf': () => import('./modes/signature.js'),
        'jpg-to-pdf': () => import('./modes/jpgpage.js'),
    };
    const loading = [MODES[config.mode], extras[config.slug]]
        .filter(Boolean)
        .map((load) => load().then((module) => module.default(wb)));

    // Modes listen for file changes, so a handed-over file is added once they are ready.
    Promise.all(loading).then(receiveHandoff, receiveHandoff);

    return wb;
}
