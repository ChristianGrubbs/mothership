(() => {
    'use strict';
    // The tool frame page (dam::tools.frame): one tool in a frame under the top bar every tool shares. The rules for
    // leaving are the ones Home's tool panel proved (home.js): ask only when work may be lost, never through
    // window.confirm, and a stray Escape never closes a tool.
    const PROTOCOL = 'mothership-widget/v1';
    // Started roots are tracked by element, not by a mark in the markup: the app's mount rebuilds #app from the
    // server's markup, and a mark there would be copied to the rebuilt root, which would then never start.
    const started = (window.__mothershipToolFrameStarted ??= new WeakSet());
    const start = () => {
        const root = document.querySelector('[data-tool-frame]');
        if (!root || started.has(root)) return;
        started.add(root);
        // One live frame at a time: if a root was started and then replaced, what it listened for goes with it.
        window.__mothershipToolFrameLife?.abort();
        const life = (window.__mothershipToolFrameLife = new AbortController());
        const {signal} = life;
        const id = root.dataset.tool, title = root.dataset.toolTitle;
        const words = JSON.parse(root.dataset.words);
        const $ = selector => root.querySelector(selector);
        const stage = $('[data-tool-stage]'), note = $('[data-tool-note]'), noteText = $('[data-tool-note-text]'), noteActions = $('[data-tool-note-actions]');
        const ask = $('[data-tool-ask]'), question = $('[data-tool-question]'), accept = $('[data-tool-ask-accept]'), cancel = $('[data-tool-ask-cancel]');
        const back = $('[data-tool-back]'), reload = $('[data-tool-reload]'), status = $('[data-tool-status]');
        const launchId = globalThis.crypto?.randomUUID?.() || `tool-${Date.now().toString(36)}-${Math.random().toString(36).slice(2)}`;
        const began = performance.now();
        const track = ({name, props}) => {
            try { window.damTrack?.(name, null, {widget_id: id, launch_id: launchId, ...props}, 'tools', Math.max(0, Math.round(performance.now() - began))); } catch { /* Analytics never blocks a tool. */ }
        };

        // Back returns to where the person came from. From the Tools page (or from nowhere: a bookmark, a new tab)
        // that is Tools, and the button says so. From another page of the app it is that page, and it says "Back".
        let returnTo = root.dataset.toolsUrl;
        try {
            const tools = new URL(returnTo), from = document.referrer ? new URL(document.referrer) : null;
            const app = tools.pathname.replace(/\/tools\/?$/, '/');
            const fromApp = from && from.origin === location.origin && from.pathname.startsWith(app) && !from.pathname.startsWith(tools.pathname.replace(/\/$/, '') + '/') && !/\/login\/?$/.test(from.pathname);
            if (fromApp && from.pathname.replace(/\/$/, '') !== tools.pathname.replace(/\/$/, '')) {
                returnTo = from.href;
                back.href = returnTo;
                back.setAttribute('aria-label', words.back);
                back.title = words.back;
                const noteBack = $('[data-tool-note-back]');
                noteBack.href = returnTo;
                noteBack.textContent = words.back;
            }
        } catch { /* An address that will not parse leaves Back on Tools. */ }

        const theme = () => document.documentElement.classList.contains('dark') ? 'dark' : 'light';
        const url = new URL(root.dataset.toolUrl);
        url.searchParams.set('mode', 'embedded');
        url.searchParams.set('embed', '1');
        url.searchParams.set('theme', theme());
        url.searchParams.set('account', root.dataset.user);
        if (id === 'looper') url.searchParams.set('apiBase', root.dataset.homeBase + '/service/looper');

        const iframe = document.createElement('iframe');
        iframe.title = title;
        iframe.allow = 'fullscreen; clipboard-read; clipboard-write';
        iframe.dataset.toolWindow = '';

        const post = (type, payload) => iframe.contentWindow?.postMessage({protocol: PROTOCOL, type, payload}, location.origin);
        const sendTheme = () => post('theme', {theme: theme()});
        const currentDocument = () => { try { return iframe.contentDocument; } catch { return null; } };

        // What the tool has told us, each fact tied to the document that said it: a reload or a move inside the frame
        // loads a new document, which must say it again.
        let dirty = false, booted = false, launched = false, leaving = false;
        let noWorkDocument = null, reportsWorkDocument = null, discardedDocument = null;
        const holdsNoWork = () => Boolean(noWorkDocument) && noWorkDocument === currentDocument();
        const reportsWork = () => Boolean(reportsWorkDocument) && reportsWorkDocument === currentDocument();
        // Work may be lost when the tool reported work, or when nothing in its current document promised to report
        // it (a tool without the bridge, a frame that left the tool, a tool that has not loaded).
        const mayHoldWork = () => !holdsNoWork() && (dirty || !reportsWork());

        // `lead` makes Reload the one filled control: the tool is not coming, so Reload is the next step. While the
        // tool is only slow it may still arrive, and nothing is pressed on the person.
        const noteReload = $('[data-tool-note-reload]');
        const showNote = ({text, actions = false, lead = false}) => {
            noteText.textContent = text;
            noteActions.hidden = !actions;
            noteReload.classList.toggle('bridge-btn-act', lead);
            noteReload.classList.toggle('bridge-btn-card', !lead);
            note.hidden = false;
        };
        const finishLaunch = ({phase, error_code}) => {
            if (launched) return;
            launched = true;
            // Frame identity proves delivery, not that the tool's own work or export succeeds.
            track({name: phase === 'loaded' ? 'home.launch.loaded' : phase === 'abandoned' ? 'home.launch.abandoned' : 'home.launch.failed', props: {phase, ...(error_code ? {error_code} : {})}});
        };
        const slow = setTimeout(() => { track({name: 'home.launch.slow', props: {phase: 'timeout'}}); showNote({text: words.slow, actions: true}); }, 30000);
        signal.addEventListener('abort', () => clearTimeout(slow));

        // The question sits in its own band under the bar. It is the page's, not the tool's: nothing the tool does
        // can write over it, and it needs no browser dialog.
        let pending = null;
        const dismissAsk = () => {
            if (!pending) return false;
            const {opener} = pending;
            pending = null;
            ask.hidden = true;
            opener?.focus();
            return true;
        };
        const raiseAsk = ({text, label, discards, opener, action}) => {
            if (pending) return;
            pending = {opener, action};
            question.textContent = text;
            accept.textContent = label;
            accept.classList.toggle('is-discard', discards);
            ask.hidden = false;
            accept.focus();
        };
        cancel.addEventListener('click', () => dismissAsk());
        accept.addEventListener('click', () => { const action = pending?.action; dismissAsk(); action?.(); });

        const leave = () => { leaving = true; clearTimeout(slow); if (!launched) finishLaunch({phase: 'abandoned'}); location.assign(returnTo); };
        // A tool that has not opened holds nothing, whatever it has or has not promised.
        const askClose = opener => {
            const work = booted && mayHoldWork();
            raiseAsk({text: work ? words['ask-close-work'] : words['ask-close'], label: work ? words['discard-close'] : words.close, discards: work, opener, action: leave});
        };
        const reloadTool = () => {
            // What the old document reported is forgotten, and its promise to report is dropped and not taken again
            // (its ready message may still be on the way): until the new document promises, leaving asks.
            dirty = false;
            reportsWorkDocument = null;
            discardedDocument = currentDocument();
            showNote({text: words.reloading});
            iframe.src = url.href;
        };

        // A plain click on Back leaves at once when no work can be lost; otherwise it asks first. A click that opens
        // a new tab or window is left to the browser.
        const leaveLink = event => {
            if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
            event.preventDefault();
            if (booted && mayHoldWork()) askClose(event.currentTarget); else leave();
        };
        back.addEventListener('click', leaveLink);
        $('[data-tool-note-back]').addEventListener('click', leaveLink);
        const askReload = opener => {
            if (booted && mayHoldWork()) raiseAsk({text: words['ask-reload'], label: words['discard-reload'], discards: true, opener, action: reloadTool});
            else reloadTool();
        };
        reload.addEventListener('click', () => askReload(reload));
        noteReload.addEventListener('click', () => reloadTool());

        // Escape backs out of the question first; otherwise it asks. It never closes a tool by itself, so an Escape
        // meant for a menu inside the tool can not throw work away.
        const escape = opener => { if (!dismissAsk()) askClose(opener); };
        document.addEventListener('keydown', event => { if (event.key === 'Escape' && !event.defaultPrevented) { event.preventDefault(); escape(document.activeElement instanceof HTMLElement && document.activeElement !== document.body ? document.activeElement : back); } }, {signal});

        iframe.addEventListener('load', () => {
            clearTimeout(slow);
            let identity;
            try { identity = iframe.contentWindow?.__MOTHERSHIP_FRAME__?.widget; } catch { identity = null; }
            if (identity === id) { booted = true; finishLaunch({phase: 'loaded'}); note.hidden = true; sendTheme(); post('active', {active: true}); return; }
            // A tool page names itself. A first load that does not is a broken session. A later one is the tool
            // moving on (to a file's page, say): a page gets the whole window, never a second copy of the app
            // inside the frame; a file stays here, with Reload as the way back to the tool.
            let moved = '', page = false;
            try { moved = booted ? iframe.contentWindow.location.href : ''; page = ['text/html', 'application/xhtml+xml'].includes(iframe.contentDocument?.contentType); } catch { moved = ''; }
            if (moved && moved !== 'about:blank' && page) { window.location.assign(moved); return; }
            if (moved && moved !== 'about:blank') { showNote({text: words.file, actions: true, lead: true}); return; }
            finishLaunch({phase: 'error', error_code: 'frame_identity_missing'});
            showNote({text: words.failed, actions: true, lead: true});
        });
        iframe.addEventListener('error', () => { clearTimeout(slow); finishLaunch({phase: 'error', error_code: 'frame_load_failed'}); showNote({text: words.failed, actions: true, lead: true}); });

        window.addEventListener('message', event => {
            if (event.source !== iframe.contentWindow || event.origin !== location.origin || event.data?.protocol !== PROTOCOL) return;
            const {type, payload} = event.data;
            if (type === 'ready') sendTheme();
            // A catalogue-style tool says in its ready message that it holds nothing (holdsWork:false): nothing typed
            // into it is work. holdsWork:true takes that back and is itself a report of work.
            if (type === 'ready' && typeof payload?.holdsWork === 'boolean') {
                noWorkDocument = payload.holdsWork ? null : currentDocument();
                dirty = holdsNoWork() ? false : (payload.holdsWork ? true : dirty);
            }
            // The bridge promises in its ready message to report work as it starts. The promise belongs to the
            // document that made it, and a document a reload discarded can not make it.
            if (type === 'ready' && payload?.reportsWork === true) {
                const current = currentDocument();
                if (current && current !== discardedDocument) { reportsWorkDocument = current; discardedDocument = null; }
            }
            if (type === 'preference-error') status.textContent = payload?.message || words.preferences;
            // A tool may send params as it finishes loading, before anyone touched it. With the bridge, work comes
            // through dirty, so its params are not counted; a frame without the bridge can not say, so they are.
            const work = ['dirty', 'media-loaded'].includes(type) || (type === 'params' && !reportsWork());
            if (work && !holdsNoWork()) dirty = true;
            if (type === 'escape') escape(iframe);
        }, {signal});

        const observer = new MutationObserver(sendTheme);
        observer.observe(document.documentElement, {attributes: true, attributeFilter: ['class']});
        signal.addEventListener('abort', () => observer.disconnect());
        // Closing the tab, or the browser's own Back, asks through the browser while the tool holds reported work.
        window.addEventListener('beforeunload', event => { if (!leaving && dirty && !holdsNoWork()) { event.preventDefault(); event.returnValue = ''; } }, {signal});

        track({name: 'home.launch.attempted', props: {phase: 'opening'}});
        iframe.src = url.href;
        stage.prepend(iframe);
    };
    // The app mounts on DOMContentLoaded and rebuilds #app as it does. Start on the task after it; `load` is the
    // second chance for a mount that landed after the first start, which finds a root it has not started.
    const afterMount = () => setTimeout(start, 0);
    if (document.readyState !== 'loading') afterMount(); else { document.addEventListener('DOMContentLoaded', afterMount, {once: true}); window.addEventListener('load', afterMount, {once: true}); }
})();
