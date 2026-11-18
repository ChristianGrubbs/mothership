import { getDocument, GlobalWorkerOptions } from 'pdfjs-dist/build/pdf.mjs';
import workerUrl from 'pdfjs-dist/build/pdf.worker.min.mjs?url';

GlobalWorkerOptions.workerSrc = workerUrl;

/** One document and worker per expanded viewer; only nearby pages retain canvases. */
export function mountPdfViewer(host, { url, fileName }) {
    const runId = crypto.randomUUID?.() || `pdf-${Date.now()}-${Math.random().toString(36).slice(2)}`;
    const log = (event, ctx = {}) => {
        if (import.meta.env?.VITE_DAM_MEDIA_DEBUG !== 'true') return;
        try {
            const key = 'mothership.media-preview.log';
            const line = JSON.stringify({ ts: new Date().toISOString(), run_id: runId, level: event.endsWith('error') ? 'error' : 'info', event, loc: 'dam/pdf/viewer', ctx });
            sessionStorage.setItem(key, [...(sessionStorage.getItem(key) || '').split('\n').filter(Boolean).slice(-99), line].join('\n'));
        } catch { /* Optional diagnostics never block a document. */ }
    };
    const find = name => host.querySelector(`[data-pdf-${name}]`);
    const scroll = find('scroll'), pagesHost = find('pages'), loading = find('loading'), error = find('error');
    const pageInput = find('page-number'), controls = [...find('toolbar').querySelectorAll('button,input')];
    const events = new AbortController();
    let disposed = false, loadingTask = null, document = null, pages = [], mode = 'width', scale = 1, currentPage = 1;
    let baseSize = { width: 612, height: 792 }, resizeFrame = 0, scrollFrame = 0, layoutGeneration = 0, layoutPending = false;
    const reportError = message => { log('load_error'); if (!disposed) { loading.hidden = true; error.hidden = false; error.textContent = message; } };
    const current = () => { pageInput.value = String(currentPage); find('previous').disabled = !document || currentPage === 1; find('next').disabled = !document || currentPage === pages.length; };
    const cancelPage = entry => {
        entry.generation++;
        entry.renderTask?.cancel();
        entry.renderTask = null;
        entry.canvas?.remove();
        entry.canvas = null;
    };
    async function renderPage(entry) {
        if (disposed || !document || entry.canvas) return;
        const generation = ++entry.generation;
        const canvas = window.document.createElement('canvas');
        canvas.width = 0;
        canvas.height = 0;
        entry.canvas = canvas;
        entry.element.append(canvas);
        try {
            const page = await document.getPage(entry.number);
            if (disposed || generation !== entry.generation) return;
            entry.size = page.getViewport({ scale: 1 });
            const viewport = page.getViewport({ scale });
            // Bound each backing store at 8 MP even on high-density displays/large pages.
            const density = Math.min(window.devicePixelRatio || 1, 2, Math.sqrt(8_000_000 / (viewport.width * viewport.height)));
            canvas.width = Math.max(1, Math.floor(viewport.width * density));
            canvas.height = Math.max(1, Math.floor(viewport.height * density));
            canvas.style.cssText = `display:block;width:${viewport.width}px;height:${viewport.height}px;`;
            canvas.setAttribute('role', 'img');
            canvas.setAttribute('aria-label', `${fileName || 'PDF'} — page ${entry.number}`);
            entry.element.style.width = `${viewport.width}px`;
            entry.element.style.height = `${viewport.height}px`;
            const task = page.render({ canvasContext: canvas.getContext('2d'), viewport, transform: [density, 0, 0, density, 0, 0] });
            entry.renderTask = task;
            await task.promise;
            if (!disposed && generation === entry.generation) { entry.renderTask = null; canvas.dataset.pdfRendered = 'true'; }
        } catch (failure) {
            if (!disposed && generation === entry.generation && failure.name !== 'RenderingCancelledException') reportError('A PDF page could not render. Open the original PDF to continue.');
        }
    }
    const observer = new IntersectionObserver(entries => {
        for (const observed of entries) {
            const entry = pages[Number(observed.target.dataset.pdfPage) - 1];
            if (!entry) continue;
            entry.visible = observed.isIntersecting;
            if (entry.visible) renderPage(entry);
            else cancelPage(entry);
        }
    }, { root: scroll, rootMargin: '600px 0px' });
    function scrollToCurrent() {
        const entry = pages[currentPage - 1];
        if (entry) scroll.scrollTo({ top: Math.max(0, entry.element.offsetTop - 16), left: scroll.scrollLeft });
        current();
    }
    function jump(number) {
        cancelAnimationFrame(scrollFrame);
        currentPage = Math.max(1, Math.min(pages.length, Math.trunc(Number(number)) || 1));
        current();
        if (mode === 'zoom') scrollToCurrent();
        else layout();
    }
    async function layout() {
        if (!document || disposed || !scroll.clientWidth) return;
        const generation = ++layoutGeneration;
        const entry = pages[currentPage - 1];
        layoutPending = true;
        try {
            // Fit follows the selected page, including mixed portrait/landscape documents.
            if (!entry.size) entry.size = (await document.getPage(entry.number)).getViewport({ scale: 1 });
            if (disposed || generation !== layoutGeneration) return;
            const selectedSize = entry.size;
            if (mode === 'width') scale = Math.max(0.1, (scroll.clientWidth - 32) / selectedSize.width);
            if (mode === 'page') scale = Math.max(0.1, Math.min((scroll.clientWidth - 32) / selectedSize.width, (scroll.clientHeight - 32) / selectedSize.height));
            scale = Math.min(5, scale);
            find('zoom').textContent = `${Math.round(scale * 100)}%`;
            find('fit-width').setAttribute('aria-pressed', String(mode === 'width'));
            find('fit-page').setAttribute('aria-pressed', String(mode === 'page'));
            for (const page of pages) {
                cancelPage(page);
                const size = page.size || baseSize;
                page.element.style.width = `${size.width * scale}px`;
                page.element.style.height = `${size.height * scale}px`;
                if (page.visible) renderPage(page);
            }
            scrollToCurrent();
        } catch (failure) {
            if (!disposed && generation === layoutGeneration) reportError('This PDF page could not load. Open the original PDF to continue.');
        } finally {
            if (generation === layoutGeneration) layoutPending = false;
        }
    }
    const on = (name, type, callback) => find(name).addEventListener(type, callback, { signal: events.signal });
    on('fit-width', 'click', () => { mode = 'width'; layout(); });
    on('fit-page', 'click', () => { mode = 'page'; layout(); });
    on('zoom-in', 'click', () => { mode = 'zoom'; scale = Math.min(5, scale * 1.25); layout(); });
    on('zoom-out', 'click', () => { mode = 'zoom'; scale = Math.max(0.1, scale / 1.25); layout(); });
    on('previous', 'click', () => jump(currentPage - 1));
    on('next', 'click', () => jump(currentPage + 1));
    on('page-number', 'change', () => jump(Math.trunc(Number(pageInput.value))));
    scroll.addEventListener('scroll', () => {
        cancelAnimationFrame(scrollFrame);
        scrollFrame = requestAnimationFrame(() => {
            if (disposed || layoutPending) return;
            const top = scroll.getBoundingClientRect().top + 24;
            const entry = pages.find(page => page.element.getBoundingClientRect().bottom > top);
            if (entry && currentPage !== entry.number) { currentPage = entry.number; current(); if (mode !== 'zoom') layout(); }
        });
    }, { signal: events.signal, passive: true });
    const resize = new ResizeObserver(() => {
        cancelAnimationFrame(resizeFrame);
        resizeFrame = requestAnimationFrame(layout);
    });
    resize.observe(scroll);
    controls.forEach(control => control.disabled = true);
    loading.hidden = false;
    error.hidden = true;
    pagesHost.replaceChildren();
    find('count').textContent = '—';
    async function load() {
        try {
            const address = new URL(url, window.location.href);
            if (address.origin !== window.location.origin || !['http:', 'https:'].includes(address.protocol)) throw new Error('Invalid PDF source');
            find('original').href = address.href;
            log('load_start');
            const resources = new URL('pdfjs/', new URL(import.meta.env?.BASE_URL || '/', window.location.href)).href;
            loadingTask = getDocument({
                url: address.href, cMapUrl: resources + 'cmaps/', cMapPacked: true,
                standardFontDataUrl: resources + 'standard_fonts/', wasmUrl: resources + 'wasm/',
            });
            loadingTask.onPassword = () => {
                reportError('This PDF requires a password. Open the original PDF to unlock it.');
                loadingTask.destroy().catch(() => {});
            };
            document = await loadingTask.promise;
            if (disposed) return;
            const firstPage = await document.getPage(1);
            if (disposed) return;
            baseSize = firstPage.getViewport({ scale: 1 });
            pages = Array.from({ length: document.numPages }, (_, index) => {
                const element = window.document.createElement('div');
                element.dataset.pdfPage = String(index + 1);
                element.style.cssText = 'position:relative;margin:16px auto;background:white;box-shadow:0 2px 8px #0006;';
                pagesHost.append(element);
                return { number: index + 1, element, size: index === 0 ? baseSize : null, generation: 0, visible: false, canvas: null, renderTask: null };
            });
            log('load_ready', { pages: pages.length });
            controls.forEach(control => control.disabled = false);
            pageInput.max = String(pages.length);
            find('count').textContent = String(pages.length);
            loading.hidden = true;
            await layout();
            if (disposed) return;
            pages.forEach(entry => observer.observe(entry.element));
        } catch (failure) {
            if (!disposed && error.hidden) reportError('This PDF could not load. Open the original PDF or download the file to continue.');
        }
    }
    load();
    return {
        destroy() {
            if (disposed) return;
            disposed = true;
            log('destroy', { pages: pages.length });
            events.abort();
            observer.disconnect();
            resize.disconnect();
            cancelAnimationFrame(resizeFrame);
            cancelAnimationFrame(scrollFrame);
            pages.forEach(cancelPage);
            pagesHost.replaceChildren();
            return loadingTask?.destroy().catch(() => {});
        },
    };
}
