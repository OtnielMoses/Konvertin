/*
 * Runs a browser-side PDF operation in a fresh Web Worker. The worker is closed
 * after every job so its memory is released at once.
 */

export const LOCAL_LIMIT_BYTES = 150 * 1024 * 1024;

const OPERATIONS = { 'merge-pdf': 'merge', 'rotate-pdf': 'rotate', 'organize-pdf': 'organize' };

const MESSAGES = {
    encrypted: 'Berkas diproteksi kata sandi. Buka dulu dengan alat Unlock PDF, lalu coba lagi.',
    unreadable: 'Berkas tidak dapat dibaca sebagai PDF di peramban. Coba pilih "Di server Konvertin".',
    pages: 'Nomor halaman di luar jangkauan dokumen. Periksa kembali nomor atau rentang halaman.',
    too_many_pages: 'Dokumen melebihi batas jumlah halaman untuk alat ini.',
    generic: 'Berkas tidak dapat diproses di peramban. Coba pilih "Di server Konvertin".',
};

export function supportsLocal(slug) {
    return Boolean(OPERATIONS[slug]) && typeof Worker !== 'undefined';
}

/**
 * @param {string} slug
 * @param {{ files: File[], options: Record<string, string>, extra?: File|null, maxPages: number }} input
 * @param {(event: {fraction: number|null, label: string}) => void} onProgress
 * @returns {Promise<{ blob: Blob, pages: number }>}
 */
export async function runLocal(slug, { files, options, extra = null, maxPages }, onProgress) {
    const payload = {
        op: OPERATIONS[slug],
        files: await Promise.all(files.map(async (file) => ({ name: file.name, bytes: new Uint8Array(await file.arrayBuffer()) }))),
        extra: extra ? { bytes: new Uint8Array(await extra.arrayBuffer()) } : null,
        options,
        maxPages,
    };

    return new Promise((resolve, reject) => {
        const worker = new Worker(new URL('./local-worker.js', import.meta.url), { type: 'module' });
        const finish = () => worker.terminate();
        worker.addEventListener('message', (event) => {
            const message = event.data;
            if (message.type === 'progress') {
                onProgress(message);
            } else if (message.type === 'done') {
                finish();
                resolve({ blob: new Blob([message.bytes], { type: 'application/pdf' }), pages: message.pages });
            } else {
                finish();
                reject(new Error(MESSAGES[message.code] ?? MESSAGES.generic));
            }
        });
        worker.addEventListener('error', () => {
            finish();
            reject(new Error(MESSAGES.generic));
        });
        worker.postMessage(payload, [...payload.files.map((file) => file.bytes.buffer), ...(payload.extra ? [payload.extra.bytes.buffer] : [])]);
    });
}
