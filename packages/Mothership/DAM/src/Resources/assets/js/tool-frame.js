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
        const notice = $('[data-tool-notice]'), noticeText = $('[data-tool-notice-text]');
        const back = $('[data-tool-back]'), reload = $('[data-tool-reload]'), status = $('[data-tool-status]');
        const sets = $('[data-tool-sets]'), setsBtn = $('[data-tool-sets-btn]'), setName = $('[data-tool-set-name]'), setsMenu = $('[data-tool-sets-menu]');
        const exportButtons = root.querySelectorAll('[data-tool-export]'), libraryButtons = root.querySelectorAll('[data-tool-library]'), foot = $('[data-tool-foot]');
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
        // This page draws the tool's bar, so the tool need not draw its own way to Export and its saved sets.
        url.searchParams.set('bar', '1');
        if (id === 'looper') url.searchParams.set('apiBase', root.dataset.homeBase + '/service/looper');

        const iframe = document.createElement('iframe');
        iframe.title = title;
        iframe.allow = 'fullscreen; clipboard-read; clipboard-write';
        iframe.dataset.toolWindow = '';

        const post = (type, payload) => iframe.contentWindow?.postMessage({protocol: PROTOCOL, type, payload}, location.origin);
        const sendTheme = () => post('theme', {theme: theme()});
        // Where the fold of a folded screen crosses the tool's own box, in the tool's pixels. A framed page is told
        // of one viewport segment only, so the tool cannot find the fold itself. A fold that misses the box is none.
        const sendFold = () => {
            let segments;
            try { segments = window.viewport?.segments; } catch { segments = null; }
            const box = iframe.getBoundingClientRect();
            let fold = null;
            if (segments?.length === 2 && box.width > 0 && box.height > 0) {
                const [a, b] = segments;
                if (b.left >= a.right) fold = {mode: 'columns', start: a.right - box.left, end: b.left - box.left, size: box.width};
                else if (b.top >= a.bottom) fold = {mode: 'rows', start: a.bottom - box.top, end: b.top - box.top, size: box.height};
            }
            const crosses = fold && fold.start > 0 && fold.end < fold.size;
            post('fold', crosses ? {mode: fold.mode, start: Math.round(fold.start), end: Math.round(fold.end)} : {mode: null});
        };
        const currentDocument = () => { try { return iframe.contentDocument; } catch { return null; } };

        // What the tool has told us, each fact tied to the document that said it: a reload or a move inside the frame
        // loads a new document, which must say it again.
        // `away`: the frame shows a file the tool opened in its own place. The tool's page went when the file came,
        // and what it held went with it, so nothing is left to lose and nothing asks about work.
        let dirty = false, booted = false, launched = false, leaving = false, away = false;
        let noWorkDocument = null, reportsWorkDocument = null, discardedDocument = null;
        const holdsNoWork = () => Boolean(noWorkDocument) && noWorkDocument === currentDocument();
        const reportsWork = () => Boolean(reportsWorkDocument) && reportsWorkDocument === currentDocument();
        // Work may be lost when the tool reported work, or when nothing in its current document promised to report
        // it (a tool without the bridge, a tool that has not loaded).
        const mayHoldWork = () => !away && !holdsNoWork() && (dirty || !reportsWork());

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
        // Every try at loading the tool gets its own thirty seconds: the first, a Reload, and a Reload after that.
        // The launch is reported slow once; a later try is the person's own, not a launch.
        let slow = 0, slowReported = false;
        const armSlow = () => {
            clearTimeout(slow);
            slow = setTimeout(() => {
                if (!launched && !slowReported) { slowReported = true; track({name: 'home.launch.slow', props: {phase: 'timeout'}}); }
                showNote({text: words.slow, actions: true});
            }, 30000);
        };
        signal.addEventListener('abort', () => clearTimeout(slow));

        // The question sits in its own band under the bar. It is the page's, not the tool's: nothing the tool does
        // can write over it, and it needs no browser dialog.
        let pending = null;
        // A file in the frame stays in view: the page says what happened in a band above it and never covers it.
        // The band steps aside while a question is up, so one filled control shows at a time.
        const showNotice = () => { notice.hidden = !away || Boolean(pending); };
        const setAway = on => { away = on; noticeText.textContent = on ? words.file : ''; showNotice(); };
        const dismissAsk = () => {
            if (!pending) return false;
            const {opener} = pending;
            pending = null;
            ask.hidden = true;
            showNotice();
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
            showNotice();
            accept.focus();
        };
        cancel.addEventListener('click', () => dismissAsk());
        accept.addEventListener('click', () => { const action = pending?.action; dismissAsk(); action?.(); });

        const leave = to => { leaving = true; clearTimeout(slow); if (!launched) finishLaunch({phase: 'abandoned'}); location.assign(to || returnTo); };
        // A tool that has not opened holds nothing, whatever it has or has not promised.
        const askClose = (opener, to) => {
            const work = booted && mayHoldWork();
            raiseAsk({text: work ? words['ask-close-work'] : words['ask-close'], label: work ? words['discard-close'] : words.close, discards: work, opener, action: () => leave(to)});
        };

        // The bar's status line: a setting that could not be saved, or what the tool reports (a file saved to the
        // Library, with a link to it). The words are the tool's; they are set as text, and a link must stay in the app.
        const showStatus = shown => {
            status.replaceChildren(typeof shown?.text === 'string' ? shown.text.slice(0, 200) : '');
            status.classList.toggle('is-error', shown?.tone === 'error');
            let href = '';
            try { const to = new URL(shown.link.url, location.href); if (to.origin === location.origin && typeof shown.link.label === 'string') href = to.href; } catch { href = ''; }
            if (!href) return;
            const link = document.createElement('a');
            link.href = href;
            link.textContent = shown.link.label.slice(0, 60);
            link.dataset.noAjaxNav = '';
            link.addEventListener('click', leaveLink);
            status.append(' ', link);
        };

        // What the tool says it has (the bridge's chrome call): its saved sets, Export, Save to Library. The bar shows
        // a control only while the tool's current document offers it, and tells the tool what was pressed.
        const command = (name, extra = {}) => post('command', {name, ...extra});
        // A press on the bar sends the tool a command and puts the focus in the tool. A sheet the tool opens for it
        // closes with the focus back on the bar's control, as a menu or a dialog of this page would: inside the tool, a
        // closing sheet can only give the focus back to what had it there. `pressed` is the control that sent the last
        // command, and `barSheet` whether the open sheet is the one the bridge says that command opened.
        let pressed = null, barSheet = false;
        const fromBar = (control, name, extra) => { pressed = control; command(name, extra); iframe.focus(); };
        const sheetMoved = ({open, fromBar: opened}) => {
            if (open) { barSheet = opened === true && Boolean(pressed); return; }
            const back = barSheet ? pressed : null;
            barSheet = false;
            // Only while the focus is still in the tool: a person who has moved on in this page keeps their place.
            if (back?.isConnected && !back.inert && back.getClientRects().length && document.activeElement === iframe) back.focus();
        };
        const closeSets = ({focus = false} = {}) => {
            if (setsMenu.hidden) return false;
            setsMenu.hidden = true;
            setsBtn.setAttribute('aria-expanded', 'false');
            if (focus) setsBtn.focus();
            return true;
        };
        const openSets = () => {
            setsMenu.hidden = false;
            setsBtn.setAttribute('aria-expanded', 'true');
            (setsMenu.querySelector('[aria-checked="true"]') || setsMenu.querySelector('button'))?.focus();
        };
        const menuItem = ({role, label, note, checked, press}) => {
            const item = document.createElement('button');
            item.type = 'button';
            item.setAttribute('role', role);
            if (role === 'menuitemradio') item.setAttribute('aria-checked', String(checked));
            const name = document.createElement('span');
            name.textContent = label;
            item.append(name);
            if (note) { const small = document.createElement('small'); small.textContent = note; item.append(small); }
            item.addEventListener('click', () => { closeSets(); press(); });
            return item;
        };
        const showChrome = shown => {
            const list = Array.isArray(shown?.sets) ? shown.sets.filter(set => typeof set?.id === 'string' && typeof set?.name === 'string').slice(0, 200) : [];
            const name = typeof shown?.set === 'string' ? shown.set : '';
            const manage = typeof shown?.manage === 'string' ? shown.manage.slice(0, 60) : '';
            closeSets();
            sets.hidden = !list.length && !manage;
            setName.textContent = name || words.sets;
            setsBtn.title = name;
            setsMenu.setAttribute('aria-label', typeof shown?.setsLabel === 'string' ? shown.setsLabel.slice(0, 60) : words.sets);
            const items = list.map(set => menuItem({role: 'menuitemradio', label: set.name, note: set.current === true ? words['sets-open'] : '', checked: set.current === true, press: () => fromBar(setsBtn, 'open-set', {id: set.id})}));
            if (manage) items.push(menuItem({role: 'menuitem', label: manage, press: () => fromBar(setsBtn, 'manage-sets')}));
            setsMenu.replaceChildren(...items);
            exportButtons.forEach(button => { button.hidden = shown?.export !== true; });
            libraryButtons.forEach(button => { button.hidden = shown?.library !== true; });
            foot.hidden = shown?.export !== true && !(shown?.library === true && libraryButtons.length);
        };
        // A sheet open inside the tool holds the one filled control, so the bar's own actions step back until it closes.
        const setSheet = open => {
            root.toggleAttribute('data-tool-sheet', open);
            [sets, ...exportButtons, ...libraryButtons].forEach(control => { control.inert = open; });
        };
        setsBtn.addEventListener('click', () => { if (!closeSets()) openSets(); });
        setsBtn.addEventListener('keydown', event => { if (event.key === 'ArrowDown' && setsMenu.hidden) { event.preventDefault(); openSets(); } });
        // In the menu the arrow keys walk the items, Home and End jump, and Escape closes it and nothing else.
        setsMenu.addEventListener('keydown', event => {
            const items = [...setsMenu.querySelectorAll('button')], at = items.indexOf(document.activeElement);
            const to = {ArrowDown: at + 1, ArrowUp: at - 1, Home: 0, End: items.length - 1}[event.key];
            if (to !== undefined) { event.preventDefault(); items[(to + items.length) % items.length]?.focus(); }
            else if (event.key === 'Escape') { event.preventDefault(); event.stopPropagation(); closeSets({focus: true}); }
            else if (event.key === 'Tab') closeSets();
        });
        // A press anywhere else closes the menu. A press inside the tool never reaches this page, but it takes the
        // focus from this window, which says the same.
        document.addEventListener('pointerdown', event => { if (!sets.contains(event.target)) closeSets(); }, {signal});
        window.addEventListener('blur', () => closeSets(), {signal});
        exportButtons.forEach(button => button.addEventListener('click', () => fromBar(button, 'export')));
        libraryButtons.forEach(button => button.addEventListener('click', () => fromBar(button, 'library')));
        const reloadTool = () => {
            // What the old document reported is forgotten, and its promise to report is dropped and not taken again
            // (its ready message may still be on the way): until the new document promises, leaving asks.
            dirty = false;
            reportsWorkDocument = null;
            discardedDocument = currentDocument();
            showChrome(null);
            setSheet(false);
            barSheet = false;
            showStatus(null);
            setAway(false);
            showNote({text: words.reloading});
            armSlow();
            iframe.src = url.href;
        };

        // A plain click on Back leaves at once when no work can be lost; otherwise it asks first. A click that opens
        // a new tab or window is left to the browser.
        // A function declaration, so the status line's link, built above, can use it.
        function leaveLink(event) {
            if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
            event.preventDefault();
            const to = event.currentTarget.href;
            if (booted && mayHoldWork()) askClose(event.currentTarget, to); else leave(to);
        }
        back.addEventListener('click', leaveLink);
        $('[data-tool-note-back]').addEventListener('click', leaveLink);
        const askReload = opener => {
            if (booted && mayHoldWork()) raiseAsk({text: words['ask-reload'], label: words['discard-reload'], discards: true, opener, action: reloadTool});
            else reloadTool();
        };
        reload.addEventListener('click', () => askReload(reload));
        noteReload.addEventListener('click', () => reloadTool());
        $('[data-tool-notice-reload]').addEventListener('click', () => reloadTool());

        // Escape backs out of the question first; otherwise it asks. It never closes a tool by itself, so an Escape
        // meant for a menu inside the tool can not throw work away.
        const escape = opener => { if (!dismissAsk()) askClose(opener); };
        document.addEventListener('keydown', event => { if (event.key === 'Escape' && !event.defaultPrevented) { event.preventDefault(); escape(document.activeElement instanceof HTMLElement && document.activeElement !== document.body ? document.activeElement : back); } }, {signal});

        iframe.addEventListener('load', () => {
            clearTimeout(slow);
            setSheet(false);
            barSheet = false;
            let identity;
            try { identity = iframe.contentWindow?.__MOTHERSHIP_FRAME__?.widget; } catch { identity = null; }
            if (identity === id) { booted = true; finishLaunch({phase: 'loaded'}); setAway(false); note.hidden = true; sendTheme(); sendFold(); post('active', {active: true}); return; }
            // A tool page names itself. A first load that does not is a broken session. A later one is the tool
            // moving on (to a file's page, say): a page gets the whole window, never a second copy of the app
            // inside the frame; a file stays here in view, under a band whose Reload is the way back to the tool.
            let moved = '', page = false;
            try { moved = booted ? iframe.contentWindow.location.href : ''; page = ['text/html', 'application/xhtml+xml'].includes(iframe.contentDocument?.contentType); } catch { moved = ''; }
            if (moved && moved !== 'about:blank' && page) { window.location.assign(moved); return; }
            // The tool's page went, and what it offered in the bar went with it.
            showChrome(null);
            if (moved && moved !== 'about:blank') { dirty = false; note.hidden = true; setAway(true); return; }
            finishLaunch({phase: 'error', error_code: 'frame_identity_missing'});
            showNote({text: words.failed, actions: true, lead: true});
        });
        iframe.addEventListener('error', () => { clearTimeout(slow); finishLaunch({phase: 'error', error_code: 'frame_load_failed'}); showNote({text: words.failed, actions: true, lead: true}); });

        window.addEventListener('message', event => {
            if (event.source !== iframe.contentWindow || event.origin !== location.origin || event.data?.protocol !== PROTOCOL) return;
            const {type, payload} = event.data;
            if (type === 'ready') { sendTheme(); sendFold(); }
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
            if (type === 'preference-error') showStatus({text: payload?.message || words.preferences, tone: 'error'});
            if (type === 'chrome') showChrome(payload);
            if (type === 'notice') showStatus(payload);
            if (type === 'sheet') { setSheet(payload?.open === true); sheetMoved({open: payload?.open === true, fromBar: payload?.fromBar}); }
            // A tool may send params as it finishes loading, before anyone touched it. With the bridge, work comes
            // through dirty, so its params are not counted; a frame without the bridge can not say, so they are.
            const work = ['dirty', 'media-loaded'].includes(type) || (type === 'params' && !reportsWork());
            if (work && !holdsNoWork()) dirty = true;
            if (type === 'escape') escape(iframe);
        }, {signal});

        const observer = new MutationObserver(sendTheme);
        observer.observe(document.documentElement, {attributes: true, attributeFilter: ['class']});
        signal.addEventListener('abort', () => observer.disconnect());
        // The fold is told again whenever it or the tool's box moves: a new pose, a resized window, a band or the
        // phone's action bar coming and going above or below the tool.
        window.addEventListener('mothership:fold', sendFold, {signal});
        window.addEventListener('resize', sendFold, {signal});
        if ('ResizeObserver' in window) {
            const sized = new ResizeObserver(sendFold);
            sized.observe(iframe);
            signal.addEventListener('abort', () => sized.disconnect());
        }
        // Closing the tab, or the browser's own Back, asks through the browser while the tool holds reported work.
        window.addEventListener('beforeunload', event => { if (!leaving && dirty && !holdsNoWork()) { event.preventDefault(); event.returnValue = ''; } }, {signal});

        track({name: 'home.launch.attempted', props: {phase: 'opening'}});
        armSlow();
        iframe.src = url.href;
        stage.prepend(iframe);
    };
    // The app mounts on DOMContentLoaded and rebuilds #app as it does. Start on the task after it; `load` is the
    // second chance for a mount that landed after the first start, which finds a root it has not started.
    const afterMount = () => setTimeout(start, 0);
    if (document.readyState !== 'loading') afterMount(); else { document.addEventListener('DOMContentLoaded', afterMount, {once: true}); window.addEventListener('load', afterMount, {once: true}); }
})();
