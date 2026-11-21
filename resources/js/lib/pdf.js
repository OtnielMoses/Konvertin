/*
 * pdf.js loader and helpers. The library (~1 MB) is only fetched on pages that
 * show previews. isEvalSupported is off because previews render PDFs from strangers.
 */

let libraryPromise = null;

export function pdfjs() {
    libraryPromise ??= Promise.all([
        import('pdfjs-dist'),
        import('pdfjs-dist/build/pdf.worker.min.mjs?url'),
    ]).then(([lib, worker]) => {
        lib.GlobalWorkerOptions.workerSrc = worker.default;
        return lib;
    });

    return libraryPromise;
}

/**
 * Opens a File as a PDF document. Rejects with { locked: true } for password-protected files.
 */
export async function openPdf(file) {
    const lib = await pdfjs();
    const data = new Uint8Array(await file.arrayBuffer());

    try {
        return await lib.getDocument({ data, isEvalSupported: false, enableXfa: false }).promise;
    } catch (error) {
        if (error?.name === 'PasswordException') {
            throw { locked: true };
        }
        throw error;
    }
}

/**
 * Renders a page into a canvas at the given CSS width. Returns the page size in PDF
 * points (as displayed, including the page's own rotation).
 */
export async function renderPage(doc, pageNumber, canvas, { width, maxHeight = Infinity, extraRotation = 0 } = {}) {
    const page = await doc.getPage(pageNumber);
    const rotation = (page.rotate + extraRotation) % 360;
    const base = page.getViewport({ scale: 1, rotation });
    const ratio = Math.min(window.devicePixelRatio || 1, 2);
    // Fit the page inside both limits so a whole page is visible without scrolling.
    const scale = Math.min(width / base.width, maxHeight / base.height);
    const viewport = page.getViewport({ scale: scale * ratio, rotation });

    canvas.width = Math.floor(viewport.width);
    canvas.height = Math.floor(viewport.height);
    canvas.style.width = `${Math.floor(base.width * scale)}px`;
    canvas.style.height = `${Math.floor(base.height * scale)}px`;

    await page.render({ canvasContext: canvas.getContext('2d'), viewport }).promise;

    return { width: base.width, height: base.height };
}

/**
 * Renders pages lazily as their containers scroll into view.
 */
export function lazyRender(items, render, { root = null, rootMargin = '200px' } = {}) {
    if (!('IntersectionObserver' in window)) {
        items.forEach(render);
        return () => {};
    }

    const observer = new IntersectionObserver(
        (entries) => {
            entries.forEach((entry) => {
                if (entry.isIntersecting) {
                    observer.unobserve(entry.target);
                    render(entry.target);
                }
            });
        },
        { root, rootMargin },
    );

    items.forEach((item) => observer.observe(item));

    return () => observer.disconnect();
}
