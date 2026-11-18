// Saving a file to this device from The Mothership installed to an iPhone or iPad Home Screen.
//
// The installed app is one window with no download manager. Following a file link there replaces the whole app with a
// file preview that has no way back short of force-quitting (operator report 2026-11-11). So in that app, and only
// there, a save is taken over: the file is fetched, held in memory and handed to the system share sheet ("Save to
// Files", "Save Image", AirDrop). The page never navigates, so Home and the Library stay where they are.
//
// Everywhere else (any browser with a download manager, on a desktop or a phone) `active` is false and nothing here
// touches a link or an anchor click.
//
// Inlined, not bundled: into every Home tool frame by HomeToolController::bootstrap(), and into every admin page by
// components/download/preparing.blade.php. Three ways in:
//   a click on <a download href="same origin, blob: or data:">              taken over on window, capture phase
//   a script calling .click() on such an anchor that is not in the page     taken over on HTMLAnchorElement.prototype
//   window.mothershipDeviceSave.save({ url, name }) or ({ blob, name })     called by the Library card and the Home shell
(() => {
    'use strict';
    if (window.mothershipDeviceSave) return;

    // TODO 2026-11-11: replace this careful guess with a ceiling measured on a real iPhone; the file is held in memory
    // before the share sheet opens, and the same guess is in the Video Downloader.
    const MAX_BYTES = 500 * 1024 * 1024;
    const DONE_MS = 4000;
    const text = {
        label: 'Save to this device',
        getting: 'Getting the file…',
        ready: 'Ready. Tap Save to choose where it goes.',
        choosing: 'Choose where to save it.',
        done: 'Done.',
        notSaved: 'Not saved yet. Tap Save to try again.',
        save: 'Save to this device',
        retry: 'Try again',
        close: 'Close',
        cannot: 'This iPhone or iPad cannot save a file from the Home Screen app. Open The Mothership in Safari to save it.',
        large: 'This file is too large to save from the Home Screen app. Open The Mothership in Safari to save it.',
        signedOut: 'You have been signed out. Reload The Mothership and sign in again.',
        denied: 'You do not have access to this file.',
        gone: 'This file is no longer available.',
        failed: 'The file could not be fetched. Check the connection and try again.',
    };

    const standalone = (() => {
        try { return window.top.navigator.standalone === true; } catch (_) { return window.navigator.standalone === true; }
    })();
    // A touch screen tells the iPhone and iPad app from a Mac Dock app, which has a download manager.
    const active = standalone && window.navigator.maxTouchPoints > 0;

    // ---- Pure parts: no DOM, called by the DOM-less test ----------------------------------------------------------

    /** A file name with nothing a file system or a share sheet would trip over. */
    const cleanName = (name) => String(name ?? '').replace(/[\\/\u0000-\u001f\u007f]+/g, '-').replace(/^[\s.-]+|\s+$/g, '').slice(0, 180);
    const nameFromDisposition = (header) => {
        const value = String(header ?? '');
        const extended = value.match(/filename\*\s*=\s*(?:UTF-8|utf-8)''([^;]+)/);
        if (extended) {
            try { return cleanName(decodeURIComponent(extended[1].trim())); } catch (_) { /* fall through to the plain form */ }
        }
        const plain = value.match(/filename\s*=\s*(?:"((?:[^"\\]|\\.)*)"|([^;]+))/);

        return plain ? cleanName((plain[1] ?? plain[2]).replace(/\\(.)/g, '$1').trim()) : '';
    };
    const nameFromUrl = (address) => {
        try {
            const url = new URL(String(address), window.location.href);
            if (url.protocol !== 'http:' && url.protocol !== 'https:') return '';

            return cleanName(decodeURIComponent(url.pathname.split('/').filter(Boolean).pop() ?? ''));
        } catch (_) {
            return '';
        }
    };
    /** The server's name wins, as it does for a browser download; then what the page asked for; then the address. */
    const chooseName = ({ disposition, asked, url }) => nameFromDisposition(disposition) || cleanName(asked) || nameFromUrl(url) || 'download';
    /** Whether a link is a save this page may fetch: a `download` link to this origin or to bytes the page already holds. */
    const takes = (link) => {
        if (!link || typeof link.hasAttribute !== 'function' || !link.hasAttribute('download')) return false;
        const target = String(link.getAttribute('target') ?? '').toLowerCase();
        if (target && target !== '_self') return false;
        let url;
        try { url = new URL(String(link.getAttribute('href') ?? ''), window.location.href); } catch (_) { return false; }
        if (!link.getAttribute('href')) return false;
        if (url.protocol === 'blob:' || url.protocol === 'data:') return true;

        return (url.protocol === 'http:' || url.protocol === 'https:') && url.origin === window.location.origin;
    };
    /** Which sentence a failed answer gets. A redirect that ends on a page is the sign-in page. */
    const failure = ({ status, redirected, type }) => {
        if (status === 401 || status === 419 || (redirected && /^text\/html/i.test(String(type ?? '')))) return 'signedOut';
        if (status === 403) return 'denied';
        if (status === 404 || status === 410) return 'gone';

        return status >= 200 && status < 300 ? '' : 'failed';
    };
    const percent = (loaded, total) => (total > 0 ? Math.max(0, Math.min(99, Math.floor((loaded / total) * 100))) : null);

    // ---- The card -------------------------------------------------------------------------------------------------

    const CSS = `
        :host { all: initial; }
        .wrap { display: flex; justify-content: center; padding: 0 12px calc(12px + env(safe-area-inset-bottom, 0px)); }
        .card { --bg: #fff; --text: #101114; --muted: #5b5f66; --line: #e9eaec; --accent: #2D5BE3; --on-accent: #fff; --danger: #b3261e;
            pointer-events: auto; box-sizing: border-box; width: 100%; max-width: 420px; overflow: hidden; border: 1px solid var(--line);
            border-radius: 14px; background: var(--bg); color: var(--text); box-shadow: 0 12px 32px rgba(16, 17, 20, .24);
            font: 15px/1.4 Inter, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; -webkit-text-size-adjust: 100%; }
        .card.dark { --bg: #17181c; --text: #f2f3f4; --muted: #9aa0a6; --line: #2c2f33; --accent: #ff6b72; --on-accent: #17181c; --danger: #ff8a80; }
        .row { display: flex; align-items: flex-start; gap: 8px; padding: 12px 6px 10px 16px; }
        .words { min-width: 0; flex: 1; }
        p { margin: 0; }
        .name { font-weight: 600; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .status, .alert { margin-top: 2px; font-size: 14px; color: var(--muted); }
        .alert { color: var(--danger); }
        .status:empty, .alert:empty { display: none; }
        button { font: inherit; cursor: pointer; -webkit-tap-highlight-color: transparent; }
        button:focus-visible { outline: 2px solid var(--accent); outline-offset: 2px; }
        .close { flex: none; display: grid; place-items: center; width: 44px; height: 44px; margin: -6px 0; border: 0; border-radius: 10px;
            background: none; color: var(--muted); }
        .bar { height: 4px; overflow: hidden; background: var(--line); }
        .bar span { display: block; width: 0; height: 100%; background: var(--accent); transition: width .2s ease-out; }
        .bar.waiting span { width: 40%; animation: slide 1.2s ease-in-out infinite; }
        @keyframes slide { 0% { transform: translateX(-100%); } 100% { transform: translateX(250%); } }
        @media (prefers-reduced-motion: reduce) { .bar span { transition: none; } .bar.waiting span { animation: none; width: 100%; opacity: .5; } }
        .action { display: block; width: calc(100% - 32px); min-height: 48px; margin: 2px 16px 14px; border: 0; border-radius: 10px;
            background: var(--accent); color: var(--on-accent); font-weight: 600; }
        [hidden] { display: none !important; }
    `;
    const CROSS = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>';

    let view = null;
    let current = null;

    const isDark = () => document.documentElement.classList.contains('dark') || document.documentElement.dataset.theme === 'dark';
    const make = (tag, attributes = {}) => {
        const node = document.createElement(tag);
        for (const [key, value] of Object.entries(attributes)) node.setAttribute(key, value);

        return node;
    };
    const ensureView = () => {
        if (view && view.host.isConnected) return view;
        // Above the Home tool popup and every admin dialog: a save must stay reachable whatever is open.
        const host = make('div', { 'data-device-save': '', style: 'position:fixed;left:0;right:0;bottom:0;z-index:2147483000;pointer-events:none;' });
        const shadow = host.attachShadow({ mode: 'open' });
        const style = make('style');
        style.textContent = CSS;
        const card = make('div', { class: 'card', role: 'group', 'aria-label': text.label });
        const name = make('p', { class: 'name' });
        // Both live regions arrive empty and are filled afterwards, so a screen reader announces the change.
        const status = make('p', { class: 'status', role: 'status' });
        const alert = make('p', { class: 'alert', role: 'alert' });
        const close = make('button', { class: 'close', type: 'button', 'aria-label': text.close, title: text.close });
        close.innerHTML = CROSS;
        const fill = make('span');
        const bar = make('div', { class: 'bar', role: 'progressbar', 'aria-label': text.getting, 'aria-valuemin': '0', 'aria-valuemax': '100' });
        const action = make('button', { class: 'action', type: 'button' });
        const words = make('div', { class: 'words' });
        const row = make('div', { class: 'row' });
        const wrap = make('div', { class: 'wrap' });
        words.append(name, status, alert);
        row.append(words, close);
        bar.append(fill);
        card.append(row, bar, action);
        wrap.append(card);
        shadow.append(style, wrap);
        (document.body || document.documentElement).appendChild(host);
        close.addEventListener('click', () => current?.close());
        action.addEventListener('click', () => current?.act());
        view = { host, card, name, status, alert, bar, fill, action };

        return view;
    };
    const render = (job) => {
        if (job !== current) return;
        const ui = ensureView();
        ui.card.classList.toggle('dark', isDark());
        ui.name.textContent = job.name;
        ui.name.title = job.name;
        const fetching = job.phase === 'fetching';
        ui.bar.hidden = !fetching;
        ui.bar.classList.toggle('waiting', fetching && job.percent === null);
        ui.fill.style.width = job.percent === null ? '' : job.percent + '%';
        if (job.percent === null) ui.bar.removeAttribute('aria-valuenow');
        else ui.bar.setAttribute('aria-valuenow', String(job.percent));
        ui.status.textContent = job.phase === 'error' ? '' : {
            fetching: text.getting + (job.percent === null ? '' : ' ' + job.percent + '%'),
            ready: job.note || text.ready,
            sharing: text.choosing,
            done: text.done,
        }[job.phase];
        ui.alert.textContent = job.phase === 'error' ? text[job.error] : '';
        const retry = job.phase === 'error' && job.error === 'failed' && !!job.url;
        ui.action.hidden = !(job.phase === 'ready' || retry);
        ui.action.textContent = retry ? text.retry : text.save;
    };
    const dismiss = (job) => {
        if (job !== current) return;
        current = null;
        view?.host.remove();
    };

    // ---- A save ---------------------------------------------------------------------------------------------------

    const fault = (key) => Object.assign(new Error(key), { fault: key });
    const fetchFile = async (job) => {
        const response = await window.fetch(job.url, { credentials: 'same-origin', signal: job.abort.signal });
        const type = String(response.headers.get('Content-Type') ?? '').split(';')[0].trim();
        const problem = failure({ status: response.status, redirected: response.redirected, type });
        if (problem) throw fault(problem);
        job.name = chooseName({ disposition: response.headers.get('Content-Disposition'), asked: job.asked, url: job.url });
        const total = Number(response.headers.get('Content-Length')) || 0;
        if (total > MAX_BYTES) throw fault('large');
        let blob;
        if (response.body?.getReader) {
            const reader = response.body.getReader();
            const chunks = [];
            let loaded = 0;
            for (;;) {
                const { done, value } = await reader.read();
                if (done) break;
                loaded += value.byteLength;
                if (loaded > MAX_BYTES) {
                    reader.cancel().catch(() => {});
                    throw fault('large');
                }
                chunks.push(value);
                job.percent = percent(loaded, total);
                render(job);
            }
            blob = new Blob(chunks, { type });
        } else {
            blob = await response.blob();
            if (blob.size > MAX_BYTES) throw fault('large');
        }

        return new File([blob], job.name, { type: blob.type || type });
    };
    const canShare = (file) => {
        try {
            return typeof window.navigator.share === 'function' && typeof window.navigator.canShare === 'function' && window.navigator.canShare({ files: [file] });
        } catch (_) {
            return false;
        }
    };
    const fail = (job, key) => {
        job.phase = 'error';
        job.error = key;
        render(job);
    };
    /** Opens the share sheet. `tapped` says a person just pressed Save, so a refusal is the device's, not a missing tap. */
    const share = async (job, tapped) => {
        if (!canShare(job.file)) {
            fail(job, 'cannot');
            return;
        }
        job.phase = 'sharing';
        job.note = '';
        render(job);
        try {
            await window.navigator.share({ files: [job.file] });
            job.phase = 'done';
            render(job);
            setTimeout(() => dismiss(job), DONE_MS);
        } catch (error) {
            // The sheet was closed without a choice, or it needs a tap this call did not come from: Save stays on offer.
            if (error?.name === 'AbortError' || (error?.name === 'NotAllowedError' && !tapped)) {
                job.phase = 'ready';
                job.note = error.name === 'AbortError' ? text.notSaved : '';
                render(job);
            } else {
                fail(job, 'cannot');
            }
        }
    };
    const run = async (job) => {
        job.phase = 'fetching';
        job.percent = null;
        job.error = '';
        job.abort = new AbortController();
        render(job);
        try {
            if (job.blob) {
                // A file the page built itself never goes through the fetch, so the ceiling is checked here.
                if (job.blob.size > MAX_BYTES) throw fault('large');
                job.file = new File([job.blob], job.name, { type: job.blob.type });
                job.blob = null;
            }
            if (!job.file) job.file = await fetchFile(job);
        } catch (error) {
            if (error?.name !== 'AbortError') fail(job, error?.fault || 'failed');

            return;
        }
        if (job !== current) return;
        await share(job, false);
    };

    /**
     * Saves one file through the share sheet and shows the card that reports on it.
     * Give `url` (this origin, blob: or data:) or `blob`; `name` is the name the page asks for.
     */
    function save(request = {}) {
        current?.close();
        const blob = request.blob instanceof Blob ? request.blob : null;
        const asked = cleanName(request.name);
        const url = blob ? '' : String(request.url ?? '');
        const job = {
            url,
            asked,
            name: asked || nameFromUrl(url) || 'download',
            blob,
            file: null,
            phase: 'fetching',
            percent: null,
            note: '',
            error: '',
            abort: null,
        };
        job.close = () => {
            job.abort?.abort();
            dismiss(job);
        };
        job.act = () => {
            if (job.phase === 'ready') share(job, true);
            else if (job.phase === 'error') run(job);
        };
        current = job;
        run(job);

        return { close: job.close };
    }

    if (active) {
        const fromLink = (link) => ({ url: link.href, name: link.getAttribute('download') || '' });
        // On window, in the capture phase, so this runs before a page's own link handling and before the navigation.
        window.addEventListener('click', (event) => {
            if (event.defaultPrevented || event.button > 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
            const path = typeof event.composedPath === 'function' ? event.composedPath() : [event.target];
            const link = path.find((node) => node instanceof HTMLAnchorElement);
            if (!takes(link)) return;
            event.preventDefault();
            save(fromLink(link));
        }, true);
        // A script that builds an anchor, never adds it to the page and calls .click() sends no event through window.
        // The click still goes out, so the anchor's own handlers run and may tidy up or cancel the save with
        // preventDefault(). The download this app does not have is cancelled before any of them runs, from a
        // parent the anchor is lent for the click, so a handler that stops the event cannot let the file through.
        const nativeClick = HTMLAnchorElement.prototype.click;
        HTMLAnchorElement.prototype.click = function click() {
            if (this.isConnected || !takes(this)) {
                nativeClick.call(this);

                return;
            }
            let cancelled = false;
            const guard = (event) => {
                if (event.target !== this) return;
                cancelled = event.defaultPrevented;
                const prevent = event.preventDefault;
                event.preventDefault = function preventDefault() {
                    cancelled = true;
                    prevent.call(event);
                };
                prevent.call(event);
            };
            const lent = this.parentNode ? null : document.createElement('span');
            lent?.appendChild(this);
            const top = this.getRootNode();
            top.addEventListener('click', guard, true);
            try {
                nativeClick.call(this);
            } finally {
                top.removeEventListener('click', guard, true);
                // A handler may have removed the anchor or put it somewhere itself; only the loan is undone.
                if (lent && this.parentNode === lent) lent.removeChild(this);
            }
            if (!cancelled && takes(this)) save(fromLink(this));
        };
    }

    window.mothershipDeviceSave = { active, save, parts: { cleanName, nameFromDisposition, nameFromUrl, chooseName, takes, failure, percent } };
})();
