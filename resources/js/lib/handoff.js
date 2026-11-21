/*
 * Hands one PDF to the next tool without a manual download and upload. The file is
 * kept in this browser's IndexedDB only until the next tool page reads it (it is
 * deleted on read), so results made in the browser and original files work too.
 */

const DB_NAME = 'konvertin-handoff';
const STORE = 'items';
const KEY = 'next';

function open() {
    return new Promise((resolve, reject) => {
        const request = window.indexedDB.open(DB_NAME, 1);
        request.onupgradeneeded = () => request.result.createObjectStore(STORE);
        request.onsuccess = () => resolve(request.result);
        request.onerror = () => reject(request.error);
    });
}

function run(mode, action) {
    return open().then((db) => new Promise((resolve, reject) => {
        const transaction = db.transaction(STORE, mode);
        const result = action(transaction.objectStore(STORE));
        transaction.oncomplete = () => {
            db.close();
            resolve(result?.result);
        };
        transaction.onerror = () => {
            db.close();
            reject(transaction.error);
        };
    }));
}

/** @param {{ blob: Blob, name: string, from: string }} item */
export function storeHandoff(item) {
    return run('readwrite', (store) => store.put({ ...item, at: Date.now() }, KEY));
}

/** Reads and deletes the waiting file, or resolves null. */
export async function takeHandoff() {
    if (!window.indexedDB) {
        return null;
    }
    try {
        const item = await run('readonly', (store) => store.get(KEY));
        if (item) {
            await run('readwrite', (store) => store.delete(KEY));
        }
        return item ?? null;
    } catch (error) {
        return null;
    }
}
