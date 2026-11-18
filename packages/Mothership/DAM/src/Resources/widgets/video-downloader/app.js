(() => {
    'use strict';
    // The tool is served at …/home/tools/video-downloader/index.html; its API is …/home/video/.
    const api = new URL('../../video/', location.href);
    const frame = window.__MOTHERSHIP_FRAME__ || {};
    const $ = selector => document.querySelector(selector);
    const finder = $('[data-finder]'), urlField = $('[data-url]'), findButton = $('[data-find]');
    const help = $('[data-help]'), error = $('[data-error]');
    const found = $('[data-found]'), choices = $('[data-choices]'), addButton = $('[data-add]'), addError = $('[data-add-error]');
    const list = $('[data-jobs]'), empty = $('[data-empty]'), listError = $('[data-list-error]');
    const smallerOption = $('[data-smaller-option]'), smaller = $('[data-smaller]');
    const smallerHelp = $('[data-smaller-help]'), smallerNote = $('[data-smaller-note]');
    const ACTIVE = ['queued', 'running', 'processing', 'optimizing'];
    // An app installed to an iPhone or iPad Home Screen has no browser bar and no download manager: following a file
    // link there replaces the whole app with a file preview that has no way back (operator report 2026-11-11). So in
    // that app the file is fetched here, with progress, and handed to the share sheet (Save Video, Save to Files).
    // A touch screen tells that app from a web app in the Mac Dock, which has a download manager and keeps the plain
    // file link (the same test as assets/js/device-save.js).
    const inApp = (() => { try { return window.top.navigator.standalone === true; } catch { return navigator.standalone === true; } })() && navigator.maxTouchPoints > 0;
    const canShareFiles = (() => { try { return !!navigator.canShare && navigator.canShare({ files: [new File([''], 'video.mp4', { type: 'video/mp4' })] }); } catch { return false; } })();
    // The fetched file is held in memory until it is saved, so it has a ceiling. TODO 2026-11-11: 500 MB is a careful
    // guess, not yet measured on an iPhone; raise or lower it once a large save has been tried on real hardware.
    const APP_SAVE_MAX = 500 * 1024 * 1024;
    const saves = new Map();       // installed app only: job id -> {phase: fetching|ready|error, loaded, total, file, message}
    let canOptimize = false;       // whether the studio can make files smaller right now
    let optimizeMax = null;        // the largest video the studio makes smaller, in bytes; null: no limit
    let skew = 0;                  // studio clock minus this device's clock, in seconds
    let current = null;            // the video the link field last found: {url, title, choices}
    const started = new Set();     // downloads asked for in this window: each saves by itself once when ready
    const rows = new Map();
    let timer = null, loaded = false;

    async function call(path, options = {}) {
        const response = await fetch(new URL(path, api), {
            method: options.method || 'GET', credentials: 'same-origin',
            headers: { Accept: 'application/json', ...(options.body ? { 'Content-Type': 'application/json' } : {}), 'X-CSRF-TOKEN': frame.csrf || '', 'X-Requested-With': 'XMLHttpRequest' },
            body: options.body ? JSON.stringify(options.body) : undefined,
        });
        let payload = null;
        try { payload = await response.json(); } catch { payload = null; }
        if (!response.ok) {
            const message = response.status === 429 && !payload?.message?.includes('downloads waiting') ? 'That was a lot of requests at once. Wait a minute, then try again.'
                : response.status === 419 || response.status === 401 ? 'You have been signed out. Reload The Mothership and sign in again.'
                : payload?.errors ? Object.values(payload.errors)[0][0]
                : payload?.message || 'Something went wrong. Try again.';
            throw Object.assign(new Error(message), { status: response.status });
        }
        return payload;
    }

    const size = bytes => {
        if (!bytes) return '';
        if (bytes < 1024 * 1024) return Math.max(1, Math.round(bytes / 1024)) + ' KB';
        if (bytes < 1024 * 1024 * 1024) return (bytes / 1048576).toFixed(bytes < 10485760 ? 1 : 0) + ' MB';
        return (bytes / 1073741824).toFixed(1) + ' GB';
    };
    const length = seconds => {
        if (!seconds) return '';
        const h = Math.floor(seconds / 3600), m = Math.floor(seconds % 3600 / 60), s = seconds % 60;
        return h ? `${h} hr ${m} min` : m ? `${m} min ${s} sec` : `${s} sec`;
    };
    const remaining = seconds => seconds < 60 ? 'less than a minute left' : seconds < 3600 ? `about ${Math.round(seconds / 60)} min left` : `about ${(seconds / 3600).toFixed(1)} hr left`;
    const until = epoch => new Date(epoch * 1000).toLocaleString([], { weekday: 'short', hour: 'numeric', minute: '2-digit' });
    const show = (node, text) => { node.textContent = text || ''; node.hidden = !text; };

    // --- Find a video ---------------------------------------------------------------------------------------
    finder.addEventListener('submit', async event => {
        event.preventDefault();
        const url = urlField.value.trim();
        show(error, '');
        if (!/^https?:\/\/\S+$/i.test(url)) {
            show(error, url ? 'That does not look like a link. It should start with https://' : 'Paste the link to a video first.');
            urlField.focus();
            return;
        }
        findButton.disabled = true; findButton.textContent = 'Looking…'; help.hidden = true; found.hidden = true;
        try {
            const video = await call('probe', { method: 'POST', body: { url } });
            current = { url, title: video.title, choices: video.choices };
            $('[data-found-title]').textContent = video.title;
            $('[data-found-title]').title = video.title;
            $('[data-found-meta]').textContent = [video.site, video.uploader, length(video.duration)].filter(Boolean).join(' · ');
            choices.replaceChildren(...video.choices.map((choice, index) => {
                const label = document.createElement('label'); label.className = 'choice';
                const input = Object.assign(document.createElement('input'), { type: 'radio', name: 'choice', value: choice.id, checked: 'default' in choice ? choice.default : index === 0 });
                const name = Object.assign(document.createElement('span'), { textContent: choice.label });
                const weight = Object.assign(document.createElement('small'), { textContent: choice.size ? 'about ' + size(choice.size) : '' });
                label.append(input, name, weight);
                return label;
            }));
            show(addError, '');
            smaller.checked = false;
            offerSmaller();
            found.hidden = false;
            addButton.focus();
        } catch (failure) {
            show(error, failure.message);
            help.hidden = false;
        } finally {
            findButton.disabled = false; findButton.textContent = 'Find video';
        }
    });

    // Offered for video only: the studio's optimizer does not take audio files. It says its limit up front, and
    // says so again when the size picked looks larger than the limit.
    function offerSmaller() {
        const picked = choices.querySelector('input:checked');
        const offered = !!(canOptimize && picked && picked.value !== 'mp3');
        smallerOption.hidden = !offered;
        smallerHelp.textContent = 'Same picture size, takes a little longer.'
            + (optimizeMax ? ` Only for videos up to ${size(optimizeMax)}: a larger one is saved as it is.` : '');
        const estimate = offered && smaller.checked && optimizeMax && current ? current.choices.find(choice => choice.id === picked.value)?.size : 0;
        show(smallerNote, estimate > optimizeMax ? `This size is about ${size(estimate)}, over the ${size(optimizeMax)} limit, so it will be saved as it is, not made smaller.` : '');
    }
    choices.addEventListener('change', offerSmaller);
    smaller.addEventListener('change', offerSmaller);

    const dismiss = () => { found.hidden = true; current = null; show(smallerNote, ''); };
    $('[data-dismiss]').addEventListener('click', () => { dismiss(); urlField.focus(); });

    async function add(url, choice, title, optimize) {
        const { job } = await call('jobs', { method: 'POST', body: { url, choice, title, optimize: !!optimize } });
        started.add(job.id);
        await refresh();
        return job;
    }

    addButton.addEventListener('click', async () => {
        if (!current) return;
        const picked = choices.querySelector('input:checked');
        addButton.disabled = true; show(addError, '');
        try {
            await add(current.url, picked.value, current.title, !smallerOption.hidden && smaller.checked);
            dismiss(); urlField.value = ''; help.hidden = false;
            list.firstElementChild?.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
        } catch (failure) {
            show(addError, failure.message);
        } finally {
            addButton.disabled = false;
        }
    });

    // --- Your downloads -------------------------------------------------------------------------------------
    const fileUrl = job => new URL(`jobs/${job.id}/file`, api).href;

    // Seconds the studio has been at it, by the studio's clock: right after a reload, and on a device whose clock is off.
    const since = epoch => Math.max(0, Math.round(Date.now() / 1000 + skew - epoch));
    const overLimit = job => !!(job.optimize && optimizeMax && job.total > optimizeMax);

    function status(job) {
        if (job.status === 'queued') return job.position ? `Waiting, number ${job.position} in line` : 'Waiting to start';
        if (job.status === 'processing') return 'Finishing the file';
        if (job.status === 'optimizing') {
            // The studio's optimizer reports no percent, so this shows time spent, never an invented measure.
            return job.optimize_started_at ? `Making the file smaller · ${length(since(job.optimize_started_at)) || 'just started'} so far`
                : 'Waiting its turn to be made smaller';
        }
        if (job.status === 'running') {
            if (!job.downloaded) return 'Starting';
            // A percent of the whole file when the studio knows every part's size; otherwise of the part in hand, and it says which.
            const parts = [job.part ? `Downloading part ${job.part}${job.parts ? ' of ' + job.parts : ''}, ${job.percent}%` : `Downloading, ${job.percent}%`];
            if (job.total) parts.push(`${size(job.downloaded)} of ${size(job.total)}`);
            if (job.eta) parts.push(remaining(job.eta));
            if (!job.part && overLimit(job)) parts.push(`over ${size(optimizeMax)}, so it will be saved as it is`);
            return parts.join(' · ');
        }
        if (job.status === 'done') {
            const local = saves.get(job.id);
            if (local?.phase === 'fetching') {
                if (!local.total) return 'Getting it onto this device';
                return `Getting it onto this device, ${Math.min(99, Math.floor(local.loaded / local.total * 100))}% · ${size(local.loaded) || '0 KB'} of ${size(local.total)}`;
            }
            if (local?.phase === 'ready') return local.note || `Ready to save · ${size(job.size)} · tap Save video, then choose Save Video or Save to Files`;
            if (local?.phase === 'error') return local.message;
            const note = job.optimized === 'smaller' ? `made ${size(job.saved)} smaller`
                : job.optimized === 'none' ? 'already as small as it gets'
                // The limit is named only while it still explains this file: it may have been changed since.
                : job.optimized === 'too_large' ? `too large to make smaller${optimizeMax && job.size > optimizeMax ? ` (over ${size(optimizeMax)})` : ''}`
                : job.optimized === 'failed' ? 'could not be made smaller' : '';
            return ['Ready', size(job.size), note, `kept until ${until(job.expires_at)}`].filter(Boolean).join(' · ');
        }
        return job.error || 'This download did not finish.';
    }

    function row(job) {
        let item = rows.get(job.id);
        if (!item) {
            item = document.createElement('li');
            item.innerHTML = '<div><p class="job-title"></p><p class="job-status"></p></div><div class="job-actions"></div><div class="job-bar" hidden><i></i></div>';
            rows.set(job.id, item);
        }
        const state = job.status === 'running' && !job.downloaded ? 'starting'
            : job.status === 'optimizing' && !job.optimize_started_at ? 'waiting' : job.status;
        const local = job.status === 'done' ? saves.get(job.id) : null;
        const shown = state + (job.can_optimize ? '+optimize' : '') + (local ? '+' + local.phase : '');
        if (item.dataset.state !== shown) {
            item.dataset.state = shown;
            item.className = 'job is-' + state + (local ? ' is-save-' + local.phase : '');
            const actions = item.querySelector('.job-actions');
            const button = (text, kind, act) => { const b = Object.assign(document.createElement('button'), { type: 'button', className: kind, textContent: text }); b.dataset.act = act; return b; };
            if (job.status === 'done') {
                let save;
                if (!inApp) {
                    save = Object.assign(document.createElement('a'), { className: 'outline', textContent: 'Save to this device', href: fileUrl(job) });
                    save.setAttribute('download', job.filename || '');
                } else {
                    save = local?.phase === 'ready' ? button('Save video', 'primary', 'share')
                        : local?.phase === 'fetching' ? button('Stop', 'quiet', 'stop-save')
                        : button(local?.retry ? 'Try again' : 'Save to this device', 'outline', 'save');
                }
                const busy = local?.phase === 'fetching';
                // The lesser actions are one group: where the row is too narrow for all of them, they drop below the save button together.
                const more = Object.assign(document.createElement('span'), { className: 'job-more' });
                more.append(...(job.can_optimize ? [button('Optimize', 'quiet', 'optimize')] : []), button('Remove', 'quiet', 'remove'));
                actions.replaceChildren(save, ...(busy ? [] : [more]));
            } else if (job.status === 'failed') {
                actions.replaceChildren(button('Try again', 'outline', 'retry'), button('Remove', 'quiet', 'remove'));
            } else {
                // While a finished file is being made smaller there is nothing to cancel: the button removes the download.
                actions.replaceChildren(button(job.status === 'optimizing' ? 'Remove' : 'Cancel', 'quiet', 'remove'));
            }
        }
        item.classList.toggle('is-save-error', local?.phase === 'error' || !!local?.note);
        item.dataset.id = job.id;
        item.job = job;
        const title = item.querySelector('.job-title');
        title.textContent = job.title; title.title = job.title;
        item.querySelector('.job-status').textContent = [job.label, status(job)].filter(Boolean).join(' · ');
        const bar = item.querySelector('.job-bar');
        const fetching = local?.phase === 'fetching';
        // A file waiting its turn to be made smaller is not being worked on, so it shows no moving bar.
        bar.hidden = !(fetching || ['running', 'processing'].includes(job.status) || state === 'optimizing');
        bar.firstElementChild.style.width = (fetching ? (local.total ? Math.floor(local.loaded / local.total * 100) : 0) : job.percent) + '%';
        return item;
    }

    function save(job) {
        // One hands-free save on the device that asked. Browsers may hold it back; the Save button is always there.
        const link = Object.assign(document.createElement('a'), { href: fileUrl(job) });
        link.setAttribute('download', job.filename || '');
        document.body.append(link); link.click(); link.remove();
    }

    // --- What Home is told ----------------------------------------------------------------------------------------
    // Home asks "Discard and close?" before it closes a tool, unless the tool's ready message says it holds nothing
    // to export (payload.holdsWork: false). Downloads run on the studio and stay in the list, so closing loses only
    // one thing: a file brought onto this device for the share sheet and not saved yet. While there is one the tool
    // says true, and it says so again each time that changes. A Home that does not know the declaration ignores it.
    // Taking the declaration back is not enough for a Home that asks only about work a tool has reported, so the
    // file is then reported as work as well (dirty). Saying false again clears that report.
    let toldHome = null;
    function tellHome() {
        if (window.parent === window) return;
        const holdsWork = [...saves.values()].some(local => local.phase === 'fetching' || (local.phase === 'ready' && !local.shared));
        if (holdsWork === toldHome) return;
        toldHome = holdsWork;
        const tell = (type, payload = {}) => window.parent.postMessage({ protocol: 'mothership-widget/v1', type, payload }, location.origin);
        tell('ready', { holdsWork });
        if (holdsWork) tell('dirty');
    }

    // --- Saving inside the installed iPhone or iPad app -----------------------------------------------------------
    const paint = id => { const item = rows.get(id); if (item?.job) row(item.job); tellHome(); };
    const drop = id => { const local = saves.get(id); saves.delete(id); local?.abort?.abort(); paint(id); };
    const fail = (job, message, retry = false) => { saves.set(job.id, { phase: 'error', size: job.size, message, retry }); paint(job.id); };

    // Brings the finished file onto this device, showing how far along it is. `thenShare` opens the share sheet at
    // the end when the tap that asked is still fresh enough for the browser to allow it; otherwise Save video does.
    async function prepare(job, thenShare) {
        if (saves.get(job.id)?.phase === 'fetching') return;
        // Only a tap replaces a file that is on its way or waiting to be saved. A download that finishes meanwhile
        // keeps its Save to this device button instead of taking the place of the one the person asked for.
        if (!thenShare && [...saves.values()].some(other => other.phase === 'fetching' || other.phase === 'ready')) return;
        if (!canShareFiles) return fail(job, 'This iPhone or iPad cannot save a video from the Home Screen app. Open The Mothership in Safari to save it.');
        if (job.size > APP_SAVE_MAX) return fail(job, `This video is ${size(job.size)}, too large to save from the Home Screen app (the limit is ${size(APP_SAVE_MAX)}). Open The Mothership in Safari to save it, or download a smaller size.`);
        for (const id of [...saves.keys()]) if (id !== job.id) drop(id);   // one file in memory at a time
        const abort = new AbortController();
        const local = { phase: 'fetching', loaded: 0, total: job.size || 0, size: job.size, abort };
        saves.set(job.id, local); paint(job.id);
        try {
            const response = await fetch(fileUrl(job), { credentials: 'same-origin', signal: abort.signal });
            if (!response.ok) throw Object.assign(new Error('The file did not arrive.'), { status: response.status });
            local.total = Number(response.headers.get('Content-Length')) || local.total;
            const reader = response.body.getReader();
            const pieces = [];
            let chunks = [], held = 0, painted = 0;
            for (;;) {
                const { done, value } = await reader.read();
                if (done) break;
                chunks.push(value); held += value.length; local.loaded += value.length;
                // Folding the chunks into blobs as they arrive keeps this page from holding the whole file twice.
                if (held >= 8 * 1048576) { pieces.push(new Blob(chunks)); chunks = []; held = 0; }
                if (Date.now() - painted > 250) { painted = Date.now(); paint(job.id); }
            }
            pieces.push(new Blob(chunks));
            if (saves.get(job.id) !== local) return;
            local.file = new File(pieces, job.filename || 'video.mp4', { type: (response.headers.get('Content-Type') || '').split(';')[0].trim() || 'video/mp4' });
            if (!navigator.canShare({ files: [local.file] })) return fail(job, 'This kind of file cannot be saved from the Home Screen app. Open The Mothership in Safari to save it.');
            local.phase = 'ready'; paint(job.id);
            if (thenShare) share(job, true);
        } catch (failure) {
            if (saves.get(job.id) !== local) return;
            if (failure.name === 'AbortError') { saves.delete(job.id); paint(job.id); return; }
            fail(job, failure.status === 404 || failure.status === 410 ? 'That file is no longer kept. Download it again.'
                : failure.status === 401 || failure.status === 419 ? 'You have been signed out. Reload The Mothership and sign in again.'
                : 'The video did not reach this device. Check the connection, then try again.', !failure.status);
        }
    }

    // Opens the share sheet for a file that is already on this device. Must be called straight from a tap: the
    // browser refuses a sheet nobody asked for, which is what `unasked` allows for after a long fetch.
    function share(job, unasked) {
        const local = saves.get(job.id);
        if (!local?.file) return;
        if (local.note) { local.note = ''; paint(job.id); }
        let asked;
        try { asked = navigator.share({ files: [local.file] }); } catch (failure) { asked = Promise.reject(failure); }
        // The sheet reports back only when something was done with the file: from then on closing the tool loses nothing.
        asked.then(() => { if (saves.get(job.id) === local) { local.shared = true; tellHome(); } }).catch(failure => {
            // Not a failure: the sheet was closed, it is already open, or the tap went stale after a long fetch. Save video is still there.
            if (['AbortError', 'InvalidStateError'].includes(failure.name) || (unasked && failure.name === 'NotAllowedError')) return;
            if (saves.get(job.id) !== local) return;
            // Said in the row, where it stays until the next tap: the line under the list is cleared every time the list refreshes.
            local.note = 'The share sheet did not open. Tap Save video again.'; paint(job.id);
        });
    }

    async function refresh() {
        clearTimeout(timer);
        let delay = 20000;
        try {
            const { jobs, optimize, optimize_max: limit, now } = await call('jobs');
            canOptimize = !!optimize;
            optimizeMax = limit || null;
            if (now) skew = now - Date.now() / 1000;
            if (!found.hidden) offerSmaller();
            show(listError, '');
            const seen = new Set(jobs.map(job => job.id));
            for (const [id, item] of rows) if (!seen.has(id)) { item.remove(); rows.delete(id); }
            // A file held for saving belongs to one finished file: gone with the download, and stale once it is remade smaller.
            for (const [id, local] of [...saves]) {
                const job = jobs.find(candidate => candidate.id === id);
                if (!job || job.status !== 'done' || job.size !== local.size) drop(id);
            }
            jobs.forEach((job, index) => {
                const item = row(job);
                if (list.children[index] !== item) list.insertBefore(item, list.children[index] || null);
                // Once, on the device that asked: a browser saves the file by itself; the installed app brings it here, ready for Save video.
                if (job.status === 'done' && started.delete(job.id)) inApp ? prepare(job, false) : save(job);
                if (job.status === 'failed') started.delete(job.id);
            });
            empty.hidden = jobs.length > 0;
            if (jobs.some(job => ACTIVE.includes(job.status))) delay = 2000;
            loaded = true;
        } catch (failure) {
            show(listError, loaded ? 'Lost touch with the studio. Your downloads are kept; this list will catch up by itself.' : failure.message);
            delay = 8000;
        }
        tellHome();
        if (!document.hidden) timer = setTimeout(refresh, delay);
    }

    list.addEventListener('click', async event => {
        const button = event.target.closest('button[data-act]');
        if (!button) return;
        const job = button.closest('li').job;
        // These three answer at once and repaint the row themselves; Save video must reach the share sheet inside this tap.
        if (button.dataset.act === 'share') return share(job, false);
        if (button.dataset.act === 'save') return prepare(job, true);
        if (button.dataset.act === 'stop-save') return drop(job.id);
        button.disabled = true;
        try {
            if (button.dataset.act === 'retry') {
                await call(`jobs/${job.id}`, { method: 'DELETE' });
                await add(job.url, job.choice, job.title, job.optimize);
            } else if (button.dataset.act === 'optimize') {
                await call(`jobs/${job.id}/optimize`, { method: 'POST' });
                await refresh();
            } else {
                started.delete(job.id);
                await call(`jobs/${job.id}`, { method: 'DELETE' });
                drop(job.id);   // only once the download is gone: a removal that fails leaves the fetched file with its Save video button
                await refresh();
            }
        } catch (failure) {
            show(listError, failure.message);
            button.disabled = false;
        }
    });

    document.addEventListener('visibilitychange', () => { if (!document.hidden) refresh(); });
    tellHome();
    refresh();
})();
