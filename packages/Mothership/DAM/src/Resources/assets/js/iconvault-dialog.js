(function () {
    'use strict';
    if (window.MothershipIconvault) return;
    // The library address comes from the host page (window.MothershipIconvaultUrl, set from config); with none, the
    // dialog stays off and library links behave as ordinary links.
    let origin;
    try { origin = new URL(window.MothershipIconvaultUrl).origin; } catch { return; }
    // An iframe's load event also fires when the browser refuses the frame (a frame-ancestors mismatch shows a
    // blank or broken page), so it proves nothing. Iconvault answers our "hello" with "ready"; only that answer
    // means its page is showing. No answer within READY_WAIT_MS, or no load event at all within LOAD_WAIT_MS:
    // say so and offer the new-tab link. Retry reuses the iframe and its window, so each load is numbered and
    // the answer must echo the number: an answer left over from the previous load does not count.
    const READY_WAIT_MS = 4000;
    const HELLO_EVERY_MS = 1000;
    const LOAD_WAIT_MS = 15000;
    let dialog, frame, status, fallback, opener, previousOverflow, timer, helloTimer, ready, attempt = 0;
    function stopTimers() { clearTimeout(timer); clearInterval(helloTimer); }
    function show(state, text) {
        dialog.dataset.iconvaultState = state;
        status.textContent = text;
        fallback.hidden = state !== 'unavailable';
        frame.hidden = state === 'unavailable';
    }
    function unavailable() { stopTimers(); show('unavailable', 'Iconvault did not open here. Open it in a new tab.'); }
    function loading() {
        ready = false;
        attempt += 1;
        stopTimers();
        show('loading', 'Loading Iconvault…');
        timer = setTimeout(unavailable, LOAD_WAIT_MS);
    }
    function hello() { try { frame.contentWindow.postMessage({type: 'mothership:iconvault:hello', attempt}, origin); } catch {} }
    function awaitReady() {
        if (!dialog.open || ready || !frame.hasAttribute('src')) return;
        stopTimers();
        hello();
        helloTimer = setInterval(hello, HELLO_EVERY_MS);
        timer = setTimeout(unavailable, READY_WAIT_MS);
    }
    function reload() { loading(); frame.src = origin + '/'; }
    function ensureDialog() {
        if (dialog?.isConnected) return;
        dialog = document.createElement('dialog');
        dialog.className = 'dam-iconvault-dialog';
        dialog.setAttribute('aria-labelledby', 'dam-iconvault-title');
        dialog.innerHTML = `<div class="dam-iconvault-shell"><div class="dam-iconvault-bar"><h2 id="dam-iconvault-title">Iconvault</h2><span role="status" aria-live="polite" data-iconvault-status></span><button type="button" data-iconvault-retry>Retry</button><button type="button" data-iconvault-expand aria-pressed="false">Expand</button><a href="${origin}/" target="_blank" rel="noopener noreferrer" data-iconvault-external>Open externally</a><button type="button" data-iconvault-close autofocus aria-label="Close Iconvault">Close</button></div><iframe title="Iconvault icon library" allow="clipboard-write" referrerpolicy="strict-origin-when-cross-origin"></iframe><div class="dam-iconvault-fallback" data-iconvault-fallback hidden><p class="dam-iconvault-fallback-title">Iconvault could not be shown inside The Mothership.</p><p>It still works in its own tab.</p><div class="dam-iconvault-fallback-actions"><a href="${origin}/" target="_blank" rel="noopener noreferrer" data-iconvault-external data-iconvault-fallback-link>Open Iconvault in a new tab</a><button type="button" data-iconvault-retry>Try again</button></div></div></div>`;
        document.body.append(dialog);
        frame = dialog.querySelector('iframe');
        status = dialog.querySelector('[data-iconvault-status]');
        fallback = dialog.querySelector('[data-iconvault-fallback]');
        frame.addEventListener('load', awaitReady);
        frame.addEventListener('error', unavailable);
        dialog.querySelector('[data-iconvault-close]').addEventListener('click', () => dialog.close());
        dialog.querySelectorAll('[data-iconvault-retry]').forEach(button => button.addEventListener('click', reload));
        dialog.querySelector('[data-iconvault-expand]').addEventListener('click', event => {
            const expanded = dialog.classList.toggle('is-expanded');
            event.currentTarget.setAttribute('aria-pressed', String(expanded));
            event.currentTarget.textContent = expanded ? 'Restore size' : 'Expand';
        });
        dialog.addEventListener('close', () => {
            stopTimers();
            document.body.style.overflow = previousOverflow;
            frame.removeAttribute('src');
            if (opener?.isConnected) opener.focus();
        });
        dialog.addEventListener('click', event => { if (event.target === dialog) dialog.close(); });
    }
    function open(trigger) {
        ensureDialog();
        if (dialog.open) return;
        opener = trigger || document.activeElement;
        previousOverflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
        reload();
        dialog.showModal();
    }
    window.MothershipIconvault = {open};
    window.addEventListener('message', event => {
        if (!dialog?.open || event.origin !== origin || event.source !== frame?.contentWindow) return;
        if (event.data?.type === 'mothership:iconvault:ready' && event.data.attempt === attempt) { ready = true; stopTimers(); show('ready', 'Iconvault loaded.'); }
        if (event.data?.type === 'mothership:iconvault:close') dialog.close();
    });
    document.addEventListener('click', event => {
        const link = event.target.closest('a[href]');
        if (!link || link.hasAttribute('data-iconvault-external') || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
        let url;
        try { url = new URL(link.href); } catch { return; }
        if (url.origin !== origin) return;
        event.preventDefault();
        event.stopImmediatePropagation();
        open(link);
    }, true);
    document.addEventListener('mothership:navigate:before', () => { if (dialog?.open) dialog.close(); });
})();
