/*
 * Browser-side PDF processing (Merge, Rotate, Organize) with pdf-lib, inside a Web
 * Worker so the page stays responsive. Files never leave the browser. Messages:
 * in  { op, files: [{name, bytes}], options, extra?: {bytes}, maxPages }
 * out { type: 'progress', fraction, label } | { type: 'done', bytes, pages } | { type: 'error', code }
 */

import { degrees, PDFDocument, PDFHexString, PDFName } from 'pdf-lib';

class LocalError extends Error {
    constructor(code) {
        super(code);
        this.code = code;
    }
}

function progress(done, total, label) {
    self.postMessage({ type: 'progress', fraction: total ? done / total : null, label });
}

async function load(bytes) {
    try {
        return await PDFDocument.load(bytes, { updateMetadata: false });
    } catch (error) {
        throw new LocalError(error?.constructor?.name === 'EncryptedPDFError' || /encrypt/i.test(String(error?.message)) ? 'encrypted' : 'unreadable');
    }
}

function parsePages(spec, total) {
    const pages = [];
    for (const part of String(spec || '').replace(/\s/g, '').split(',')) {
        if (!part) {
            continue;
        }
        const match = part.match(/^(\d+)(?:-(\d+))?$/);
        if (!match) {
            throw new LocalError('pages');
        }
        const start = Number(match[1]);
        const end = Number(match[2] || start);
        if (start < 1 || end < start || end > total) {
            throw new LocalError('pages');
        }
        for (let page = start; page <= end; page += 1) {
            pages.push(page - 1);
        }
    }
    if (!pages.length) {
        throw new LocalError('pages');
    }
    return pages;
}

function rotatePage(page, angle) {
    page.setRotation(degrees((page.getRotation().angle + angle) % 360));
}

/** One outline entry per source file, pointing at its first page (like the server). */
function addOutline(doc, entries) {
    const { context } = doc;
    const root = context.nextRef();
    const refs = entries.map(() => context.nextRef());
    entries.forEach((entry, index) => {
        const item = context.obj({
            Title: PDFHexString.fromText(entry.title),
            Parent: root,
            Dest: [doc.getPage(entry.page).ref, 'Fit'],
        });
        if (index > 0) {
            item.set(PDFName.of('Prev'), refs[index - 1]);
        }
        if (index < entries.length - 1) {
            item.set(PDFName.of('Next'), refs[index + 1]);
        }
        context.assign(refs[index], item);
    });
    context.assign(root, context.obj({ Type: 'Outlines', First: refs[0], Last: refs[refs.length - 1], Count: refs.length }));
    doc.catalog.set(PDFName.of('Outlines'), root);
}

async function merge({ files, options }) {
    const out = await PDFDocument.create();
    const outline = [];
    for (const [index, file] of files.entries()) {
        progress(index, files.length, `Menggabungkan berkas ${index + 1} dari ${files.length}`);
        const source = await load(file.bytes);
        outline.push({ title: file.name.replace(/\.pdf$/i, '').slice(0, 120), page: out.getPageCount() });
        const pages = await out.copyPages(source, source.getPageIndices());
        pages.forEach((page) => out.addPage(page));
    }
    if (['1', 'true', 'on'].includes(String(options.bookmarks)) && out.getPageCount()) {
        addOutline(out, outline);
    }
    return out;
}

async function rotate({ files, options }) {
    progress(0, 2, 'Membaca PDF');
    const doc = await load(files[0].bytes);
    const angle = Number(options.angle);
    if (![90, 180, 270].includes(angle)) {
        throw new LocalError('generic');
    }
    const indices = options.scope === 'pages' ? parsePages(options.pages, doc.getPageCount()) : doc.getPageIndices();
    progress(1, 2, `Memutar ${indices.length} halaman`);
    indices.forEach((index) => rotatePage(doc.getPage(index), angle));
    return doc;
}

async function organize({ files, options, extra }) {
    progress(0, 3, 'Membaca PDF');
    const sources = [await load(files[0].bytes)];
    if (extra) {
        sources.push(await load(extra.bytes));
    }
    const spec = JSON.parse(options.spec || '[]');
    if (!Array.isArray(spec) || !spec.length) {
        throw new LocalError('pages');
    }
    const out = await PDFDocument.create();
    const base = sources[0].getPage(0).getSize();
    progress(1, 3, `Menyusun ${spec.length} halaman`);
    for (const item of spec) {
        if (item.blank) {
            out.addPage([base.width, base.height]);
            continue;
        }
        const source = sources[Number(item.src || 0)];
        const index = Number(item.page);
        if (!source || !(index >= 0 && index < source.getPageCount())) {
            throw new LocalError('pages');
        }
        const [page] = await out.copyPages(source, [index]);
        out.addPage(page);
        const turn = Number(item.rotate || 0) % 360;
        if (turn) {
            rotatePage(page, turn);
        }
    }
    return out;
}

const OPERATIONS = { merge, rotate, organize };

self.addEventListener('message', async (event) => {
    try {
        const doc = await OPERATIONS[event.data.op](event.data);
        if (event.data.maxPages && doc.getPageCount() > event.data.maxPages) {
            throw new LocalError('too_many_pages');
        }
        progress(0.95, 1, 'Menyimpan PDF');
        const bytes = await doc.save();
        self.postMessage({ type: 'done', bytes, pages: doc.getPageCount() }, [bytes.buffer]);
    } catch (error) {
        self.postMessage({ type: 'error', code: error instanceof LocalError ? error.code : 'generic' });
    }
});
