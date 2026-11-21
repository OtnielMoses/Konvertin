/*
 * Processing queue: several documents go to the server one at a time (one request
 * per file), so one failure never stops the others. Every card shows its own
 * status; failed files can be retried, waiting files cancelled, finished files
 * downloaded one by one or together as a ZIP. Progress comes from the server's
 * real steps; without them the card shows a working indicator, not a number.
 */

import { el, formatBytes, icon } from './dom.js';
import { conversionData, postConversion } from './request.js';

const STATUS = {
    waiting: 'Menunggu',
    queued: 'Dalam antrean',
    processing: 'Diproses',
    completed: 'Selesai',
    failed: 'Gagal',
    cancelled: 'Dibatalkan',
};

function duration(seconds) {
    if (seconds < 60) {
        return `${Math.max(1, Math.round(seconds))} detik`;
    }
    return `${Math.round(seconds / 60)} menit`;
}

export function createQueue(wb, { region, messages, onFinished = () => {} }) {
    const { form, config } = wb;
    const list = region.querySelector('[data-queue-list]');
    const stats = region.querySelector('[data-queue-stats]');
    const summary = region.querySelector('[data-queue-summary]');
    const zipButton = region.querySelector('[data-queue-zip]');
    const retryAll = region.querySelector('[data-queue-retry]');
    const cancelAll = region.querySelector('[data-queue-cancel]');
    const resetButton = region.querySelector('[data-queue-reset]');
    const notice = region.querySelector('[data-queue-notice]');
    let entries = [];
    let running = false;
    let current = null;

    function detailFor(entry) {
        if (entry.status === 'completed') {
            const report = entry.payload.report || {};
            if (report.before && report.after) {
                return `${formatBytes(report.before)} → ${formatBytes(report.after)}${report.target ? (report.target_met ? ' · target tercapai' : ' · target tidak tercapai') : ''}`;
            }
            return `${entry.payload.filename} · ${entry.payload.size}`;
        }
        return entry.label || '';
    }

    function render() {
        list.replaceChildren(...entries.map((entry) => {
            const actions = [];
            if (entry.status === 'waiting') {
                actions.push(el('button', { type: 'button', className: 'btn btn-ghost btn-sm', onClick: () => cancel(entry) }, ['Batalkan']));
            }
            if (entry.status === 'cancelled') {
                actions.push(el('button', { type: 'button', className: 'btn btn-ghost btn-sm', onClick: () => requeue(entry) }, ['Masukkan lagi']));
            }
            if (entry.status === 'failed') {
                actions.push(el('button', { type: 'button', className: 'btn btn-secondary btn-sm', onClick: () => requeue(entry) }, [icon('refresh'), 'Ulangi']));
            }
            if (entry.status === 'completed' && !entry.downloaded) {
                actions.push(el('a', {
                    className: 'btn btn-secondary btn-sm',
                    href: entry.payload.download_url,
                    onClick: () => setTimeout(() => {
                        entry.downloaded = true;
                        render();
                    }, 300),
                }, [icon('download'), 'Unduh']));
            }
            if (entry.status === 'completed' && entry.downloaded) {
                actions.push(el('span', { className: 'muted queue-done-note', text: 'Sudah diunduh' }));
            }

            const working = entry.status === 'processing' || entry.status === 'queued';
            const fraction = entry.progress;
            return el('li', { className: `queue-item is-${entry.status}`, 'data-queue-item': entry.id }, [
                el('div', { className: 'queue-main' }, [
                    el('p', { className: 'queue-name', text: entry.file.name }),
                    el('span', { className: `queue-status is-${entry.status}`, text: STATUS[entry.status] }),
                ]),
                el('p', { className: 'queue-detail', role: entry.status === 'failed' ? 'alert' : null, text: detailFor(entry) }),
                working ? el('div', {
                    className: `queue-bar${fraction === null ? ' is-working' : ''}`,
                    role: 'progressbar',
                    'aria-label': `Kemajuan ${entry.file.name}`,
                    'aria-valuemin': '0',
                    'aria-valuemax': '100',
                    'aria-valuenow': fraction === null ? null : String(Math.round(fraction * 100)),
                }, [el('span', { style: fraction === null ? '' : `width:${Math.round(fraction * 100)}%` })]) : null,
                actions.length ? el('div', { className: 'queue-actions-row' }, actions) : null,
            ]);
        }));

        const count = (status) => entries.filter((entry) => entry.status === status).length;
        const done = entries.filter((entry) => entry.status === 'completed');
        const bytes = done.reduce((sum, entry) => sum + (entry.payload.bytes || 0), 0);
        const remaining = count('waiting') + count('queued') + count('processing');
        const times = done.filter((entry) => entry.finished).map((entry) => (entry.finished - entry.started) / 1000);

        const items = [
            ['Total', entries.length],
            ['Berhasil', done.length],
            ['Diproses', count('processing') + count('queued')],
            ['Menunggu', count('waiting')],
            ['Gagal', count('failed')],
            ['Dibatalkan', count('cancelled')],
            ['Ukuran hasil', bytes ? formatBytes(bytes) : '—'],
        ];
        stats.replaceChildren(...items.map(([label, value]) => el('div', { className: 'stat' }, [el('dt', { text: label }), el('dd', { text: String(value) })])));

        // An estimate only once real durations exist, based on the files finished so far.
        const average = times.length ? times.reduce((sum, value) => sum + value, 0) / times.length : null;
        summary.textContent = remaining
            ? `${remaining} berkas belum selesai.${average ? ` Perkiraan sisa waktu sekitar ${duration(average * remaining)}, dihitung dari rata-rata berkas yang sudah selesai.` : ''}`
            : `Antrean selesai: ${done.length} berhasil${count('failed') ? `, ${count('failed')} gagal` : ''}${count('cancelled') ? `, ${count('cancelled')} dibatalkan` : ''}.`;

        const zippable = done.filter((entry) => !entry.downloaded);
        zipButton.disabled = zippable.length === 0;
        zipButton.lastChild.textContent = zippable.length ? `Unduh ${zippable.length} hasil sekaligus (ZIP)` : 'Unduh semua hasil (ZIP)';
        retryAll.hidden = count('failed') === 0;
        cancelAll.hidden = count('waiting') === 0;
        resetButton.disabled = running;
    }

    function cancel(entry) {
        if (entry.status === 'waiting') {
            entry.status = 'cancelled';
            entry.label = 'Tidak akan diproses.';
            render();
        }
    }

    function requeue(entry) {
        entry.status = 'waiting';
        entry.label = 'Menunggu giliran.';
        entry.progress = null;
        render();
        run();
    }

    function dataFor(entry) {
        const data = conversionData(form, [entry.file]);
        // Page choices refer to one document, so several documents always use all pages.
        if (data.has('options[scope]')) {
            data.set('options[scope]', 'all');
            data.delete('options[pages]');
        }
        data.delete('options[corrections]');
        return data;
    }

    async function process(entry) {
        entry.status = 'queued';
        entry.progress = 0;
        entry.label = 'Mengunggah berkas';
        entry.started = Date.now();
        render();

        const request = postConversion(form.action, dataFor(entry), {
            messages,
            onUpload: (loaded, total) => {
                entry.label = `Mengunggah ${formatBytes(loaded)} dari ${formatBytes(total)}`;
                entry.progress = (loaded / total) * 0.3;
                render();
            },
            onUploaded: () => {
                entry.label = 'Berkas diterima, menunggu giliran diproses';
                entry.progress = null;
                render();
            },
            onProgress: (event) => {
                entry.status = 'processing';
                entry.label = event.label;
                entry.progress = event.fraction === null || event.fraction === undefined ? null : 0.3 + event.fraction * 0.7;
                render();
            },
        });
        current = request;
        const outcome = await request.promise;
        current = null;

        if (outcome.ok) {
            entry.status = 'completed';
            entry.payload = outcome.payload;
            entry.finished = Date.now();
            return;
        }

        // Rate limit: wait for the server's own signal, then try the same file again.
        if (outcome.status === 429) {
            const wait = Math.max(5, outcome.retryAfter || 20);
            entry.status = 'queued';
            entry.progress = null;
            entry.label = `Server sedang sibuk. Dicoba lagi otomatis dalam ${wait} detik.`;
            render();
            await new Promise((resolve) => setTimeout(resolve, wait * 1000));
            if (entry.status === 'queued') {
                await process(entry);
            }
            return;
        }

        entry.status = 'failed';
        entry.label = outcome.message;
    }

    async function run() {
        if (running) {
            return;
        }
        running = true;
        render();
        let next = entries.find((entry) => entry.status === 'waiting');
        while (next) {
            await process(next);
            render();
            next = entries.find((entry) => entry.status === 'waiting');
        }
        running = false;
        render();
        onFinished(entries);
    }

    zipButton.addEventListener('click', async () => {
        const ids = entries.filter((entry) => entry.status === 'completed' && !entry.downloaded).map((entry) => entry.payload.id);
        notice.textContent = 'Menyiapkan ZIP…';
        zipButton.disabled = true;
        try {
            const response = await fetch(form.dataset.bundleUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': form.querySelector('input[name="_token"]').value,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({ ids }),
            });
            const body = await response.json().catch(() => ({}));
            if (!response.ok) {
                notice.textContent = body.message ?? messages.msg06;
                render();
                return;
            }
            notice.textContent = body.skipped
                ? `ZIP berisi ${body.files} hasil (${formatBytes(body.bytes)}). ${body.skipped} hasil sudah tidak tersedia.`
                : `ZIP berisi ${body.files} hasil (${formatBytes(body.bytes)}). Unduhan dimulai.`;
            window.location.assign(body.download_url);
            render();
        } catch (error) {
            notice.textContent = messages.network;
            render();
        }
    });

    retryAll.addEventListener('click', () => {
        entries.filter((entry) => entry.status === 'failed').forEach((entry) => {
            entry.status = 'waiting';
            entry.label = 'Menunggu giliran.';
        });
        render();
        run();
    });

    cancelAll.addEventListener('click', () => {
        entries.filter((entry) => entry.status === 'waiting').forEach(cancel);
    });

    return {
        get running() {
            return running;
        },
        start(files) {
            notice.textContent = '';
            entries = files.map((entry, index) => ({
                id: `${index}-${entry.id}`,
                file: entry.file,
                status: 'waiting',
                label: 'Menunggu giliran.',
                progress: null,
                payload: null,
                downloaded: false,
            }));
            render();
            run();
        },
        stop() {
            entries.filter((entry) => entry.status === 'waiting').forEach(cancel);
            current?.abort();
        },
    };
}
