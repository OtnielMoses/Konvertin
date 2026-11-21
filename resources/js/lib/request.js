/*
 * One conversion request: upload with real upload progress, then the server's
 * step-by-step NDJSON stream (progress lines, then "done" or "failed"). Used by the
 * single-file flow and by the processing queue, so both behave the same.
 */

import { uid } from './dom.js';

export function requestId() {
    return window.crypto?.randomUUID?.() ?? `${uid()}${uid()}${uid()}${uid()}`;
}

/** Form fields plus the given files; workflow drafts and browser-only fields are left out. */
export function conversionData(form, files) {
    const data = new FormData(form);
    data.delete('files[]');
    files.forEach((file) => data.append('files[]', file, file.name));
    for (const key of Array.from(data.keys())) {
        if (key.startsWith('wf_') || key === 'processing_location') {
            data.delete(key);
        }
    }
    return data;
}

function messageFrom(xhr, messages) {
    let body = null;
    try {
        body = JSON.parse(xhr.responseText);
    } catch (error) {
        body = null;
    }

    if (xhr.status === 413) {
        return messages.msg02;
    }
    if (xhr.status === 429) {
        return body?.message ?? messages.tooManyRequests;
    }
    if (xhr.status === 419) {
        return messages.pageExpired;
    }
    if (body?.errors) {
        const first = Object.values(body.errors)[0];
        return Array.isArray(first) ? first[0] : String(first);
    }
    return body?.message ?? messages.msg06;
}

/**
 * @returns {{ promise: Promise<{ok: boolean, payload?: object, message?: string, status?: number, retryAfter?: number}>, abort: () => void }}
 */
export function postConversion(url, data, { messages, onUpload = () => {}, onUploaded = () => {}, onProgress = () => {} }) {
    const xhr = new XMLHttpRequest();
    xhr.open('POST', url);
    xhr.setRequestHeader('Accept', 'application/json');
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    // Ask for the step-by-step response (one JSON line per step, result last).
    xhr.setRequestHeader('X-Konvertin-Progress', '1');
    // The server refuses a second request with the same id (double click, resend).
    xhr.setRequestHeader('X-Konvertin-Request', requestId());

    let read = 0;
    let finalEvent = null;
    const streaming = () => (xhr.getResponseHeader('Content-Type') || '').includes('ndjson');
    const readLines = (all = false) => {
        const text = xhr.responseText;
        let end = text.lastIndexOf('\n') + 1;
        if (all && end < text.length) {
            end = text.length;
        }
        if (end <= read) {
            return;
        }
        text.slice(read, end).split('\n').forEach((line) => {
            if (!line.trim()) {
                return;
            }
            try {
                const event = JSON.parse(line);
                if (event.type === 'progress') {
                    onProgress(event);
                } else {
                    finalEvent = event;
                }
            } catch (error) {
                // Ignore a malformed line; the final event decides the outcome.
            }
        });
        read = end;
    };

    const promise = new Promise((resolve) => {
        xhr.upload.addEventListener('progress', (event) => {
            if (event.lengthComputable) {
                onUpload(event.loaded, event.total);
            }
        });
        xhr.upload.addEventListener('load', onUploaded);
        xhr.addEventListener('progress', () => {
            if (xhr.status === 200 && streaming()) {
                readLines();
            }
        });
        xhr.addEventListener('load', () => {
            if (xhr.status !== 200) {
                resolve({ ok: false, status: xhr.status, message: messageFrom(xhr, messages), retryAfter: Number(xhr.getResponseHeader('Retry-After')) || null });
                return;
            }
            try {
                if (!streaming()) {
                    resolve({ ok: true, payload: JSON.parse(xhr.responseText) });
                    return;
                }
                readLines(true);
                resolve(finalEvent?.type === 'done'
                    ? { ok: true, payload: finalEvent.payload }
                    : { ok: false, status: 200, message: finalEvent?.message ?? messages.msg06 });
            } catch (error) {
                resolve({ ok: false, status: 200, message: messages.msg06 });
            }
        });
        xhr.addEventListener('error', () => resolve({ ok: false, status: 0, message: messages.network }));
        xhr.addEventListener('abort', () => resolve({ ok: false, status: 0, aborted: true, message: messages.network }));
    });

    xhr.send(data);

    return { promise, abort: () => xhr.abort() };
}
