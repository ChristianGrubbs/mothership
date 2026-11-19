(() => {
    'use strict';

    const C = window.PlaqueCore, E = window.PlaqueEncode, S = window.PlaqueSounds;
    const $ = (selector, root = document) => root.querySelector(selector);
    const $$ = (selector, root = document) => [...root.querySelectorAll(selector)];
    // The tool is served at <admin>/home/tools/plaque/index.html; host endpoints are relative to <admin>/.
    const ADMIN = new URL('../../../', location.href);
    const makePath = d => new Path2D(d);
    const esc = value => String(value ?? '').replace(/[&<>"']/g, ch => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch]));
    const slug = value => (String(value || 'plaques').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 60) || 'plaques');
    const fmt = seconds => { const s = Math.max(0, seconds); return `${Math.floor(s / 60)}:${String(Math.floor(s % 60)).padStart(2, '0')}.${String(Math.floor(s * 100) % 100).padStart(2, '0')}`; };
    const log = (event, ctx = {}) => { if (localStorage.getItem('plaque:debug') === '1') console.info(JSON.stringify({ ts: new Date().toISOString(), event, ctx })); };
    // The page that frames the tool (home-frame.js) draws its bar: the set, Export and Save to Library. Where no bar is
    // drawn, the Plaques pane carries its own way to the same sheets.
    const frame = window.MothershipFrame || null;
    const root = $('[data-plq]');

    const state = { set: C.newSet(), current: null, selected: new Set(), draft: null, draftPreset: null, scope: 'current', t: 0, playing: false, muted: false, closeUp: null };
    const presetLinks = new Map(); // plaque id -> user preset id, for "Update preset"
    let userPresets = [];

    // ---- persistence: IndexedDB for the set and user presets (the frame's localStorage is a small per-account store)
    // The frame bridge scopes localStorage to the signed-in account but not IndexedDB, so each account gets its own
    // database: someone signing in after another person on the same browser never sees that person's work.
    const account = String((window.__MOTHERSHIP_FRAME__ || {}).account ?? 'local').replace(/[^A-Za-z0-9_-]/g, '') || 'local';
    const db = (() => {
        let opening;
        const open = () => opening ||= new Promise((resolve, reject) => {
            const request = indexedDB.open(`mothership-plaque-${account}`, 1);
            request.onupgradeneeded = () => { request.result.createObjectStore('sets'); request.result.createObjectStore('presets', { keyPath: 'id' }); };
            request.onsuccess = () => resolve(request.result);
            request.onerror = () => reject(request.error);
        });
        const run = (store, mode, fn) => open().then(handle => new Promise((resolve, reject) => {
            const tx = handle.transaction(store, mode), result = fn(tx.objectStore(store));
            tx.oncomplete = () => resolve(result?.result);
            tx.onerror = () => reject(tx.error);
        }));
        return {
            get: (store, key) => run(store, 'readonly', s => s.get(key)),
            put: (store, value, key) => run(store, 'readwrite', s => (key === undefined ? s.put(value) : s.put(value, key))),
            all: store => run(store, 'readonly', s => s.getAll()),
            del: (store, key) => run(store, 'readwrite', s => s.delete(key)),
        };
    })();
    let saveTimer = null, keeps = 0;
    function kept(text, error = false) {
        const line = $('[data-save-state]');
        line.textContent = text;
        line.dataset.state = error ? 'error' : '';
    }
    // ---- what the page around the tool is told about work --------------------------------------------------------------
    // The editor is marked data-agh-ephemeral, so typing in it reports no work by itself: every change to the set is
    // kept on this device shortly after. Closing the tool loses work only while a change waits to be kept, after this
    // browser refused to keep one, or while an open preset draft has been edited (a draft lives in memory until it is
    // added to the set). The tool says it holds work while any of these is true (ready with holdsWork: true, and
    // dirty, as the Video Downloader does), and holdsWork: false once none is. It speaks only when that changes.
    let keeping = 'kept', toldHost = null; // keeping: 'kept', 'waiting' or 'refused'
    function tellHost() {
        const holdsWork = keeping !== 'kept' || Boolean(state.draft && state.draftEdited);
        if (window.parent === window || holdsWork === toldHost) return;
        toldHost = holdsWork;
        const tell = (type, payload = {}) => window.parent.postMessage({ protocol: 'mothership-widget/v1', type, payload }, location.origin);
        tell('ready', { holdsWork });
        if (holdsWork) tell('dirty');
    }
    function autosave() {
        clearTimeout(saveTimer);
        kept('Keeping…');
        keeping = 'waiting';
        tellHost();
        saveTimer = setTimeout(async () => {
            saveTimer = null;
            const attempt = ++keeps;
            try {
                await db.put('sets', { set: C.exportSet(state.set), current: state.current }, 'current');
                // A later change may be waiting, or already being kept: only the latest attempt speaks.
                if (attempt !== keeps || saveTimer) return;
                kept('Kept on this device');
                keeping = 'kept';
            } catch (error) {
                log('autosave.failed', { message: String(error) });
                if (attempt !== keeps) return;
                kept('Not kept: this browser refused to store the set. Export it to keep it.', true);
                keeping = 'refused';
            }
            tellHost();
        }, 600);
    }

    // ---- history: snapshots of the set as JSON, plus the asset map they refer to ---------------------------------------
    // Within one set the asset map only grows, so a snapshot keeps a reference to it rather than a copy. An import swaps
    // in a new map, so undoing or redoing across an import swaps the map back with the plaques that use it.
    const history = { past: [], future: [], lastKey: null, lastAt: 0 };
    const snapshot = () => ({ json: JSON.stringify({ name: state.set.name, output: state.set.output, plaques: state.set.plaques, current: state.current }), assets: state.set.assets });
    function change(key, mutate, { rebuild = false } = {}) {
        const now = Date.now();
        if (!(key && key === history.lastKey && now - history.lastAt < 1200)) {
            history.past.push(snapshot());
            if (history.past.length > 100) history.past.shift();
        }
        history.future = [];
        history.lastKey = key; history.lastAt = now;
        mutate();
        afterChange({ rebuild });
    }
    function restore(entry) {
        const snap = JSON.parse(entry.json);
        Object.assign(state.set, { name: snap.name, output: snap.output, plaques: snap.plaques });
        if (entry.assets !== state.set.assets) { state.set.assets = entry.assets; images.clear(); buffers.clear(); thumbs.clear(); preloadAll(); }
        state.current = state.set.plaques.some(p => p.id === snap.current) ? snap.current : state.set.plaques[0]?.id ?? null;
        state.selected = new Set([...state.selected].filter(id => state.set.plaques.some(p => p.id === id)));
    }
    function undo() { if (!history.past.length) return; history.future.push(snapshot()); restore(history.past.pop()); history.lastKey = null; afterChange({ rebuild: true, full: true }); toast('Undone'); }
    function redo() { if (!history.future.length) return; history.past.push(snapshot()); restore(history.future.pop()); history.lastKey = null; afterChange({ rebuild: true, full: true }); toast('Redone'); }
    // The set's name, all of it: the field grows to the lines its words take. While it is being typed in, the field
    // is the source, so a cleared field is not filled back in under the caret.
    function showName() {
        const field = $('[data-set-name]');
        if (document.activeElement !== field) field.value = state.set.name;
        if (!field.offsetParent) return; // in a pane that is not shown; fitted when it is
        field.style.height = 'auto';
        field.style.height = `${field.scrollHeight}px`;
    }
    function afterChange({ rebuild = false, full = false } = {}) {
        if (full) syncOutputControls();
        showName();
        renderList();
        if (rebuild) renderInspector();
        updateButtons();
        clampTime();
        draw();
        autosave();
    }

    // ---- assets: images decode once; audio decodes once per source ----------------------------------------------------
    const images = new Map();
    function loadImage(id) {
        const asset = state.set.assets[id];
        if (!asset || !asset.type.startsWith('image/')) return Promise.resolve(null);
        if (!images.has(id)) {
            const img = new Image();
            const ready = new Promise(resolve => { img.onload = () => { resolve(img); draw(); renderList(); }; img.onerror = () => resolve(null); });
            img.src = asset.data;
            images.set(id, { img, ready });
        }
        return images.get(id).ready;
    }
    const resolve = id => { if (!id) return null; const entry = images.get(id); if (!entry) { loadImage(id); return null; } return entry.img.complete && entry.img.naturalWidth ? entry.img : null; };
    const preloadAll = () => Promise.all(Object.keys(state.set.assets).map(loadImage));
    let audioContext;
    const ac = () => (audioContext ||= new (window.AudioContext || window.webkitAudioContext)());
    const buffers = new Map();
    function getAudio(source) {
        if (!source || source === 'none') return Promise.resolve(null);
        if (!buffers.has(source)) {
            let promise;
            if (source.startsWith('builtin:')) promise = S.builtin(source.slice(8));
            else {
                const asset = state.set.assets[source.slice(6)];
                promise = asset ? fetch(asset.data).then(r => r.arrayBuffer()).then(bytes => ac().decodeAudioData(bytes)).catch(() => null) : Promise.resolve(null);
            }
            buffers.set(source, promise);
        }
        return buffers.get(source);
    }
    function addAsset(file, kind) {
        return new Promise((done, fail) => {
            if (!file.type.startsWith(`${kind}/`)) { fail(new Error(kind === 'image' ? 'Choose an image file (PNG, JPEG, GIF, WebP or SVG).' : 'Choose an audio file (WAV, MP3, OGG, M4A).')); return; }
            if (file.size > 8 * 1024 * 1024) { fail(new Error('Choose a file under 8 MB.')); return; }
            const reader = new FileReader();
            reader.onload = () => {
                const id = C.uid('a');
                const probe = C.validateSet({ format: C.FORMAT, version: C.VERSION, plaques: [], assets: { [id]: { name: file.name, data: reader.result } } });
                if (!probe.ok || !probe.set.assets[id]) { fail(new Error('That file type is not supported here.')); return; }
                state.set.assets[id] = probe.set.assets[id];
                if (kind === 'image') loadImage(id);
                done(id);
            };
            reader.onerror = () => fail(new Error('The file could not be read.'));
            reader.readAsDataURL(file);
        });
    }

    // ---- what is being edited and played ------------------------------------------------------------------------------
    const plaqueById = id => state.set.plaques.find(p => p.id === id) || null;
    const editing = () => state.draft || plaqueById(state.current);
    function scopePlaques(scope = state.scope) {
        if (state.draft) return [state.draft];
        if (scope === 'set') return state.set.plaques;
        if (scope === 'selected') { const chosen = state.set.plaques.filter(p => state.selected.has(p.id)); return chosen.length ? chosen : state.set.plaques.filter(p => p.id === state.current); }
        const current = plaqueById(state.current);
        return current ? [current] : [];
    }
    function scopeLabel(scope) { return { current: 'This plaque', selected: 'Selected plaques', set: 'Whole set' }[scope]; }

    // ---- preview: the same drawFrame the exporters use -----------------------------------------------------------------
    // Preview and every export draw into canvases made with the same settings, so they rasterize the same way: a
    // GPU-backed canvas and a CPU-backed one anti-alias moving edges differently. CPU everywhere also keeps the GIF
    // encoder's pixel reads fast.
    const FRAME_CONTEXT = { willReadFrequently: true };
    const frameCanvas = () => { const c = document.createElement('canvas'); return { c, ctx: c.getContext('2d', FRAME_CONTEXT) }; };
    const canvas = $('[data-preview]'), pctx = canvas.getContext('2d', FRAME_CONTEXT);
    // Close-up: a whole 1920 x 1080 frame shown under 480 CSS px wide puts a plaque's title at about 6 px. There the
    // preview frames the plaques in play instead: the canvas takes the stage's own box (whose size the output's shape
    // still sets, so nothing round it moves) and the plaques are centred and fitted to it, at one zoom for the whole
    // run. Exports never see it. It is on by default, except while the Output pane is open (that pane sets the frame,
    // so it shows all of it); the switch overrides both.
    const CLOSE_UP_BELOW = 480;
    const stageBox = { width: 0, height: 0 };
    new ResizeObserver(([entry]) => {
        const { width, height } = entry.contentRect;
        if (Math.abs(width - stageBox.width) < 1 && Math.abs(height - stageBox.height) < 1) return;
        Object.assign(stageBox, { width, height });
        draw();
    }).observe($('[data-stage]'));
    // The width the whole frame is shown at on this stage, whichever of the stage's width or height limits it.
    const frameShown = output => Math.min(stageBox.width, stageBox.height * output.width / output.height);
    const closeUpCan = () => state.set.output.mode === 'frame' && stageBox.height > 1 && frameShown(state.set.output) < CLOSE_UP_BELOW;
    const closeUpOn = () => closeUpCan() && (state.closeUp ?? root.dataset.view !== 'output');
    function closeUpScene(output, plaques) {
        const ratio = Math.min(window.devicePixelRatio || 1, 3);
        const size = { width: Math.max(2, Math.round(stageBox.width * ratio / 2) * 2), height: Math.max(2, Math.round(stageBox.height * ratio / 2) * 2) };
        let w = 1, h = 1;
        for (const plaque of plaques) { const box = C.layout(pctx, plaque); w = Math.max(w, box.width); h = Math.max(h, box.height); }
        const fit = Math.min(size.width * 0.84 / w, size.height * 0.76 / h);
        return { size, output: { ...output, width: size.width, height: size.height, anchor: 'center', margin: 0, scale: fit * 1080 / Math.min(size.width, size.height) } };
    }
    let rafPending = false;
    function draw() {
        if (rafPending) return;
        rafPending = true;
        requestAnimationFrame(() => { rafPending = false; paint(); });
    }
    function paint() {
        const plaques = scopePlaques();
        const empty = !state.set.plaques.length && !state.draft;
        $('[data-empty]').hidden = !empty;
        root.toggleAttribute('data-empty-set', empty);
        $('[data-transport]').hidden = empty;
        const stage = $('[data-stage]');
        // An empty set's stage takes its size from the stylesheet, so the last plaque's frame shape goes with it.
        if (empty) { stage.style.aspectRatio = ''; canvas.width = 1; canvas.height = 1; return; }
        const frameSize = C.canvasSize(pctx, state.set.output, plaques);
        stage.style.aspectRatio = `${Math.max(frameSize.width / frameSize.height, 1.2)}`;
        const closeUp = closeUpOn(), toggle = $('[data-action="close-up"]');
        toggle.hidden = !closeUpCan();
        toggle.setAttribute('aria-pressed', String(closeUp));
        const { size, output } = closeUp ? closeUpScene(state.set.output, plaques) : { size: frameSize, output: state.set.output };
        if (canvas.width !== size.width || canvas.height !== size.height) { canvas.width = size.width; canvas.height = size.height; }
        C.drawFrame(pctx, { output, plaques, size, background: null }, state.t, resolve, makePath);
        const line = C.timeline(plaques);
        $('[data-clock]').textContent = `${fmt(state.t)} / ${fmt(line.duration)}`;
        const scrub = $('[data-scrub]');
        scrub.max = String(line.duration || 0);
        if (document.activeElement !== scrub) scrub.value = String(state.t);
    }
    function clampTime() {
        const line = C.timeline(scopePlaques());
        state.t = Math.min(Math.max(0, state.t), line.duration);
    }
    function showStill() {
        const plaques = scopePlaques();
        if (plaques.length === 1) state.t = C.stillTime(plaques[0]);
        clampTime();
        draw();
    }

    // ---- playback: the audio clock drives the playhead so sound and picture stay together --------------------------
    let playback = null;
    async function play() {
        const plaques = scopePlaques();
        const line = C.timeline(plaques);
        if (!line.duration) return;
        if (state.t >= line.duration - 0.001) state.t = 0;
        const ctx = ac();
        await ctx.resume();
        const { events } = C.soundSchedule(plaques);
        const loaded = await Promise.all(events.map(event => getAudio(event.source)));
        const master = ctx.createGain();
        master.gain.value = state.muted ? 0 : 1;
        master.connect(ctx.destination);
        const lead = 0.06, startCtx = ctx.currentTime + lead, startT = state.t, sources = [];
        events.forEach((event, index) => {
            const buffer = loaded[index];
            if (!buffer || event.at + buffer.duration <= startT) return;
            const src = ctx.createBufferSource(), gain = ctx.createGain();
            src.buffer = buffer; gain.gain.value = event.volume;
            src.connect(gain).connect(master);
            if (event.at >= startT) src.start(startCtx + event.at - startT);
            else src.start(startCtx, startT - event.at);
            sources.push(src);
        });
        playback = { startCtx, startT, sources, master, duration: line.duration };
        state.playing = true;
        updatePlayButton();
        const tick = () => {
            if (!playback) return;
            const t = playback.startT + Math.max(0, ctx.currentTime - playback.startCtx);
            state.t = Math.min(t, playback.duration);
            paint();
            if (t >= playback.duration) { stop(); return; }
            playback.raf = requestAnimationFrame(tick);
        };
        playback.raf = requestAnimationFrame(tick);
    }
    function stop() {
        if (playback) { cancelAnimationFrame(playback.raf); for (const src of playback.sources) { try { src.stop(); } catch { /* not started */ } } playback.master.disconnect(); }
        playback = null;
        state.playing = false;
        updatePlayButton();
        draw();
    }
    function updatePlayButton() {
        $('[data-play-label]').textContent = state.playing ? 'Pause' : 'Play';
        $('[data-play-glyph]').innerHTML = state.playing ? '<rect x="14" y="4" width="4" height="16" rx="1"/><rect x="6" y="4" width="4" height="16" rx="1"/>' : '<polygon points="6 3 20 12 6 21 6 3"/>';
    }
    function step(direction) {
        stop();
        const fps = state.set.output.fps;
        state.t = Math.round(state.t * fps + direction) / fps;
        clampTime();
        draw();
    }
    async function previewSound(source, volume = 0.8) {
        const buffer = await getAudio(source);
        if (!buffer) return;
        const ctx = ac(); await ctx.resume();
        const src = ctx.createBufferSource(), gain = ctx.createGain();
        src.buffer = buffer; gain.gain.value = volume;
        src.connect(gain).connect(ctx.destination); src.start();
    }

    // ---- list ----------------------------------------------------------------------------------------------------------
    const thumbs = new Map();
    function thumbnail(plaque, width = 128) {
        const off = document.createElement('canvas'), ctx = off.getContext('2d');
        const output = { ...state.set.output, mode: 'tight', scale: 1 };
        const size = C.canvasSize(ctx, output, [plaque]);
        const ratio = width / size.width;
        off.width = width; off.height = Math.max(1, Math.round(size.height * ratio));
        const big = document.createElement('canvas'); big.width = size.width; big.height = size.height;
        C.drawFrame(big.getContext('2d'), { output, plaques: [plaque], size, background: null }, C.stillTime(plaque), resolve, makePath);
        ctx.drawImage(big, 0, 0, off.width, off.height);
        return off;
    }
    function renderList() {
        const list = $('[data-list]');
        const plaques = state.set.plaques;
        $('[data-count]').textContent = plaques.length ? `${plaques.length} · ${fmt(C.timeline(plaques).duration)}` : '';
        $('[data-list-empty]').hidden = plaques.length > 0;
        $('[data-list-tools]').hidden = plaques.length < 2;
        list.innerHTML = plaques.map((plaque, index) => `
            <li class="row${plaque.id === state.current && !state.draft ? ' is-current' : ''}" data-id="${plaque.id}" draggable="true" aria-current="${plaque.id === state.current && !state.draft ? 'true' : 'false'}">
                <label class="row-check"><input type="checkbox" aria-label="Select ${esc(plaque.title || 'plaque')}" data-select ${state.selected.has(plaque.id) ? 'checked' : ''}></label>
                <canvas class="thumb" width="128" height="80" data-thumb></canvas>
                <span class="row-text"><strong>${esc(plaque.title.split('\n')[0] || 'Untitled plaque')}</strong><span>${index + 1} · ${plaque.kind === 'social' ? 'Social' : plaque.amount.enabled ? 'Amount' : 'Plaque'} · ${C.plaqueLength(plaque).toFixed(1)} s</span></span>
                <span class="row-move"><button type="button" data-move="-1" aria-label="Move up"><svg viewBox="0 0 24 24"><path d="M6 15l6-6 6 6"/></svg></button><button type="button" data-move="1" aria-label="Move down"><svg viewBox="0 0 24 24"><path d="M6 9l6 6 6-6"/></svg></button></span>
            </li>`).join('');
        $$('[data-thumb]', list).forEach((slot, index) => {
            const plaque = plaques[index], key = JSON.stringify(plaque) + (plaque.visual.assetId && resolve(plaque.visual.assetId) ? '1' : '0');
            let cached = thumbs.get(plaque.id);
            if (!cached || cached.key !== key) { cached = { key, canvas: thumbnail(plaque) }; thumbs.set(plaque.id, cached); }
            const ctx = slot.getContext('2d');
            ctx.clearRect(0, 0, slot.width, slot.height);
            const c = cached.canvas, k = Math.min(slot.width / c.width, slot.height / c.height);
            ctx.drawImage(c, (slot.width - c.width * k) / 2, (slot.height - c.height * k) / 2, c.width * k, c.height * k);
        });
        renderBulkHint();
    }
    function selectPlaque(id) {
        if (state.draft) return;
        stop();
        state.current = id;
        renderList();
        renderInspector();
        showStill();
        autosave();
    }
    function addPlaques(plaques, { after = state.current, key = 'add' } = {}) {
        change(key, () => {
            const index = state.set.plaques.findIndex(p => p.id === after);
            state.set.plaques.splice(index < 0 ? state.set.plaques.length : index + 1, 0, ...plaques);
            state.current = plaques[0].id;
        }, { rebuild: true });
        showStill();
    }
    function removePlaques(ids) {
        if (!ids.length) return;
        change('remove', () => {
            const index = state.set.plaques.findIndex(p => p.id === state.current);
            state.set.plaques = state.set.plaques.filter(p => !ids.includes(p.id));
            ids.forEach(id => state.selected.delete(id));
            if (!plaqueById(state.current)) state.current = state.set.plaques[Math.min(Math.max(0, index), state.set.plaques.length - 1)]?.id ?? null;
        }, { rebuild: true });
        toast(ids.length > 1 ? `${ids.length} plaques deleted` : 'Plaque deleted', { undo: true });
    }
    function duplicate(id) {
        const source = plaqueById(id);
        if (!source) return;
        const copy = JSON.parse(JSON.stringify(source));
        copy.id = C.uid('p');
        addPlaques([copy], { after: id, key: 'duplicate' });
    }
    function move(id, by) {
        const list = state.set.plaques, index = list.findIndex(p => p.id === id), target = index + by;
        if (index < 0 || target < 0 || target >= list.length) return;
        change('move', () => { const [item] = list.splice(index, 1); list.splice(target, 0, item); });
        $(`[data-id="${id}"] [data-move="${by}"]`)?.focus();
    }

    // ---- inspector -----------------------------------------------------------------------------------------------------
    const getPath = (obj, path) => path.split('.').reduce((value, key) => value?.[key], obj);
    const setPath = (obj, path, value) => { const keys = path.split('.'), last = keys.pop(); keys.reduce((value, key) => value[key], obj)[last] = value; };
    const iconSvg = name => `<svg viewBox="0 0 24 24" aria-hidden="true"><path d="${C.ICONS[name]}"/></svg>`;
    const options = (pairs, value) => pairs.map(([v, label]) => `<option value="${esc(v)}"${String(v) === String(value) ? ' selected' : ''}>${esc(label)}</option>`).join('');
    const seg = (path, value, pairs) => `<div class="segmented" role="group">${pairs.map(([v, label]) => `<button type="button" data-seg="${path}" data-value="${esc(v)}" aria-pressed="${String(v) === String(value)}">${esc(label)}</button>`).join('')}</div>`;
    const field = (label, control, extra = '') => `<label class="field"${extra}>${label}${control}</label>`;
    const num = (path, value, min, max, stepValue, label, unit = '') => field(`<span class="with-out"><span>${label}</span><output>${value}${unit}</output></span>`, `<input type="range" data-k="${path}" min="${min}" max="${max}" step="${stepValue}" value="${value}" data-unit="${unit}">`);
    const numBox = (path, value, min, max, stepValue, label) => field(label, `<input type="number" data-k="${path}" min="${min}" max="${max}" step="${stepValue}" value="${value}">`);
    const colorBox = (path, value, label) => field(label, `<input type="color" data-k="${path}" value="${value}">`);
    const soundOptions = value => {
        const audioAssets = Object.entries(state.set.assets).filter(([, a]) => a.type.startsWith('audio/'));
        return options([['none', 'No sound'], ...S.names.map(n => [`builtin:${n}`, S.labels[n]]), ...audioAssets.map(([id, a]) => [`asset:${id}`, a.name])], value);
    };
    const assetChip = (id, label) => { const a = state.set.assets[id]; return a ? `<span class="asset-chip"><img src="${esc(a.data)}" alt="">${esc(a.name)}</span>` : `<span class="asset-chip">${label}</span>`; };

    function renderInspector() {
        const host = $('[data-inspector]');
        const p = editing();
        if (!p) {
            host.innerHTML = '<h2>Nothing to edit</h2><p class="tk-empty">Add a plaque, or pick one from the list, and edit it here.</p>';
            return;
        }
        const s = p.style, glyphs = Object.keys(C.ICONS).filter(n => n !== 'cursor');
        const visual = p.kind === 'social' ? `
            <div class="pair">
                <div class="field">Page image${assetChip(p.social.pageAssetId, 'None yet')}<label class="tk-btn is-small file-button">Choose…<input type="file" accept="image/*" data-upload="social.pageAssetId"></label></div>
                <div class="field">Platform image${assetChip(p.social.platformAssetId, 'None yet')}<label class="tk-btn is-small file-button">Choose…<input type="file" accept="image/*" data-upload="social.platformAssetId"></label></div>
            </div>` : `
            <div class="field">Visual${seg('visual.type', p.visual.type, [['icon', 'Icon'], ['symbol', 'Symbol or emoji'], ['image', 'Image'], ['none', 'None']])}</div>
            ${p.visual.type === 'icon' ? `<div class="icon-grid" role="group" aria-label="Icon">${glyphs.map(n => `<button type="button" data-seg="visual.icon" data-value="${n}" aria-pressed="${p.visual.icon === n}" aria-label="${n}" title="${n}">${iconSvg(n)}</button>`).join('')}</div>` : ''}
            ${p.visual.type === 'symbol' ? field('Symbol', `<input data-k="visual.symbol" value="${esc(p.visual.symbol)}" maxlength="8" placeholder="★ or 🎉">`) : ''}
            ${p.visual.type === 'image' ? `<div class="field">Image${assetChip(p.visual.assetId, 'No image yet')}<label class="tk-btn is-small file-button">Choose image…<input type="file" accept="image/*" data-upload="visual.assetId"></label></div>` : ''}
            ${p.visual.type !== 'none' ? `<div class="field">Shape${seg('visual.shape', p.visual.shape, [['rounded', 'Rounded'], ['circle', 'Circle'], ['square', 'Square']])}</div>` : ''}`;
        const social = p.kind === 'social' ? `
            <details open><summary>Click effects</summary><div class="fields">
                <p class="lead">Each action is a timed click: the pointer moves to the button, presses it, and the button changes to its done wording.</p>
                ${p.social.actions.map((a, i) => `<div class="action-card"><header><span>Action ${i + 1}</span><button type="button" class="tk-link" data-remove-action="${i}">Remove</button></header>
                    <div class="pair">${field('Button', `<input data-k="social.actions.${i}.label" value="${esc(a.label)}" maxlength="24">`)}${field('After the click', `<input data-k="social.actions.${i}.doneLabel" value="${esc(a.doneLabel)}" maxlength="24">`)}</div>
                    <div class="triple">${field('Glyph', `<select data-k="social.actions.${i}.glyph">${options(glyphs.map(n => [n, n]), a.glyph)}</select>`)}${numBox(`social.actions.${i}.at`, a.at, 0, 65, 0.05, 'Click at (s)')}${field('Sound', `<select data-k="social.actions.${i}.sound">${soundOptions(a.sound)}</select>`)}</div></div>`).join('')}
                ${p.social.actions.length < C.LIMITS.actions ? '<button type="button" class="tk-btn is-small" data-action="add-action">Add a click</button>' : ''}
            </div></details>` : '';
        const linked = presetLinks.get(p.id), linkedPreset = userPresets.find(u => u.id === linked);
        host.innerHTML = `
            ${state.selected.size > 1 && !state.draft ? bulkPanel() : ''}
            <h2>${state.draft ? 'Preset draft' : 'Edit plaque'}</h2>
            <p class="lead">${state.draft ? 'Changes stay in the draft until you add it to the set.' : 'Changes apply to the plaque selected in the list.'}</p>
            <div class="inspector-columns"><div>
            <details open><summary>Content</summary><div class="fields">
                <div class="field">Type${seg('kind', p.kind, [['standard', 'Plaque'], ['social', 'Social']])}</div>
                ${field(p.kind === 'social' ? 'Page name' : 'Title', `<textarea rows="2" data-k="title" maxlength="400">${esc(p.title)}</textarea>`)}
                ${field(p.kind === 'social' ? 'Handle or line' : 'Subtitle', `<textarea rows="2" data-k="subtitle" maxlength="400">${esc(p.subtitle)}</textarea>`)}
                <p class="lead">Press Return for a line break.</p>
                ${visual}
            </div></details>
            ${social}
            <details${p.amount.enabled ? ' open' : ''}><summary>Amount<span class="summary-value">${p.amount.enabled ? esc(C.formatAmount(p.amount, p.amount.value)) : 'Off'}</span></summary><div class="fields">
                <label class="check"><input type="checkbox" data-k="amount.enabled"${p.amount.enabled ? ' checked' : ''}> Show an amount</label>
                <div class="pair">${numBox('amount.value', p.amount.value, -1e12, 1e12, 'any', 'Value')}${numBox('amount.decimals', p.amount.decimals, 0, 4, 1, 'Decimals')}</div>
                <div class="pair">${field('Before', `<input data-k="amount.prefix" value="${esc(p.amount.prefix)}" maxlength="12">`)}${field('After', `<input data-k="amount.suffix" value="${esc(p.amount.suffix)}" maxlength="24">`)}</div>
                <label class="check"><input type="checkbox" data-k="amount.thousands"${p.amount.thousands ? ' checked' : ''}> Thousands separators</label>
                <label class="check"><input type="checkbox" data-k="amount.countUp"${p.amount.countUp ? ' checked' : ''}> Count up as it enters</label>
            </div></details>
            </div><div>
            <details open><summary>Style</summary><div class="fields">
                <div class="triple">${colorBox('style.background', s.background, 'Card')}${colorBox('style.text', s.text, 'Text')}${colorBox('style.accent', s.accent, 'Accent')}</div>
                <div class="pair">${field('Font', `<select data-k="style.font">${options([['system', 'Sans'], ['rounded', 'Rounded'], ['serif', 'Serif'], ['condensed', 'Condensed'], ['mono', 'Mono']], s.font)}</select>`)}${field('Title weight', `<select data-k="style.titleWeight">${options([[400, 'Regular'], [500, 'Medium'], [600, 'Semibold'], [700, 'Bold'], [800, 'Extra bold'], [900, 'Black']], s.titleWeight)}</select>`)}</div>
                ${p.kind === 'social' ? '' : `<div class="field">Layout${seg('style.layout', s.layout, [['row', 'Visual beside text'], ['column', 'Visual above text']])}</div>`}
                <div class="field">Align${seg('style.align', s.align, [['left', 'Left'], ['center', 'Center']])}</div>
                <div class="field">Long text${seg('style.fit', s.fit, [['wrap', 'Wrap'], ['shrink', 'Shrink to fit']])}</div>
                ${num('style.width', s.width, 240, 1600, 10, 'Width', ' px')}
                ${num('style.titleSize', s.titleSize, 12, 120, 1, 'Title size', ' px')}
                ${num('style.subtitleSize', s.subtitleSize, 10, 90, 1, 'Subtitle size', ' px')}
                ${num('style.radius', s.radius, 0, 80, 1, 'Corner radius', ' px')}
                <label class="check"><input type="checkbox" data-k="style.uppercase"${s.uppercase ? ' checked' : ''}> Uppercase title</label>
                <label class="check"><input type="checkbox" data-k="style.shadow"${s.shadow ? ' checked' : ''}> Shadow</label>
                <label class="check"><input type="checkbox" data-k="style.border"${s.border ? ' checked' : ''}> Accent border</label>
            </div></details>
            <details open><summary>Timing and motion<span class="summary-value">${C.plaqueLength(p).toFixed(2)} s</span></summary><div class="fields">
                <div class="triple">${numBox('timing.in', p.timing.in, 0, 5, 0.05, 'Entrance (s)')}${numBox('timing.hold', p.timing.hold, 0, 60, 0.1, 'Hold (s)')}${numBox('timing.out', p.timing.out, 0, 5, 0.05, 'Exit (s)')}</div>
                <div class="pair">${field('Enters', `<select data-k="animation.enter">${options(C.ENTER.map(v => [v, label(v)]), p.animation.enter)}</select>`)}${field('Exits', `<select data-k="animation.exit">${options(C.ENTER.map(v => [v, label(v)]), p.animation.exit)}</select>`)}</div>
                <div class="pair">${field('Easing', `<select data-k="animation.easing">${options([['ease-out', 'Smooth stop'], ['ease-in-out', 'Smooth both ends'], ['linear', 'Even'], ['back', 'Overshoot']], p.animation.easing)}</select>`)}${numBox('animation.distance', p.animation.distance, 0, 600, 5, 'Slide distance')}</div>
            </div></details>
            <details open><summary>Sound</summary><div class="fields">
                <div class="pair">${field('Sound', `<select data-k="sound.source">${soundOptions(p.sound.source)}</select>`)}<div class="field">Your own<label class="tk-btn is-small file-button">Upload audio…<input type="file" accept="audio/*" data-upload-sound></label></div></div>
                ${num('sound.volume', p.sound.volume, 0, 1, 0.05, 'Volume')}
                ${numBox('sound.offset', p.sound.offset, -5, 60, 0.05, 'Start offset (s, from the plaque start)')}
                <button type="button" class="tk-btn is-small" data-action="hear-sound"${p.sound.source === 'none' ? ' disabled' : ''}>Hear it</button>
            </div></details>
            </div></div>
            <div class="button-row">
                ${state.draft ? '' : '<button type="button" class="tk-btn is-small" data-action="duplicate">Duplicate</button><button type="button" class="tk-btn is-small" data-action="delete">Delete</button>'}
                <button type="button" class="tk-btn is-small" data-action="save-preset">Save as preset</button>
                ${linkedPreset ? `<button type="button" class="tk-btn is-small" data-action="update-preset">Update “${esc(linkedPreset.name)}”</button>` : ''}
            </div>`;
    }
    const label = value => ({ fade: 'Fade', 'slide-up': 'Slide up', 'slide-down': 'Slide down', 'slide-left': 'Slide left', 'slide-right': 'Slide right', pop: 'Pop', none: 'Cut' }[value]);
    function bulkPanel() {
        return `<div class="bulk" data-bulk><h3>Bulk edit · ${state.selected.size} selected</h3><p>Copy settings from the plaque shown below to every selected plaque.</p>
            <div class="checks">${[['timing', 'Timing'], ['animation', 'Motion'], ['style', 'Style'], ['sound', 'Sound'], ['amount', 'Amount format']].map(([k, l]) => `<label class="check"><input type="checkbox" data-bulk-part="${k}"${k === 'timing' ? ' checked' : ''}> ${l}</label>`).join('')}</div>
            <div class="button-row"><button type="button" class="tk-btn is-small" data-action="bulk-apply">Apply to ${state.selected.size}</button><button type="button" class="tk-btn is-small" data-action="bulk-delete">Delete ${state.selected.size}</button></div></div>`;
    }
    // The panel comes and goes with a selection of two or more; between those, only its count changes, in place, so
    // the parts ticked in it stay ticked.
    function renderBulkHint() {
        const bulk = $('[data-bulk]'), size = state.selected.size;
        if ((size > 1 && !state.draft) !== Boolean(bulk)) { renderInspector(); return; }
        if (!bulk) return;
        bulk.querySelector('h3').textContent = `Bulk edit · ${size} selected`;
        bulk.querySelector('[data-action="bulk-apply"]').textContent = `Apply to ${size}`;
        bulk.querySelector('[data-action="bulk-delete"]').textContent = `Delete ${size}`;
    }

    // One handler for every bound control. Typing in one field within a second is one undo step.
    function coerce(input, current) {
        if (input.type === 'checkbox') return input.checked;
        if (input.type === 'range' || input.type === 'number') {
            const value = Number(input.value);
            if (!Number.isFinite(value)) return current;
            return C.clamp(value, Number(input.min || -Infinity), Number(input.max || Infinity), current);
        }
        if (typeof current === 'number') return Number(input.value);
        return input.value;
    }
    function edit(path, value, { rebuild = false } = {}) {
        const p = editing();
        if (!p) return;
        const apply = () => {
            if (path === 'kind') {
                const fresh = C.defaultPlaque(value);
                p.kind = value;
                p.social = value === 'social' ? fresh.social : null;
                if (value === 'social') { p.style.layout = 'row'; p.timing.hold = Math.max(p.timing.hold, 3); }
            } else setPath(p, path, value);
        };
        if (state.draft) { apply(); afterDraftChange(rebuild); return; }
        change(`${p.id}:${path}`, apply, { rebuild });
    }
    function afterDraftChange(rebuild) { state.draftEdited = true; tellHost(); if (rebuild) renderInspector(); draw(); }
    const inspector = $('[data-inspector]');
    inspector.addEventListener('input', event => {
        const input = event.target.closest('[data-k]');
        if (!input) return;
        const p = editing(), path = input.dataset.k;
        const value = coerce(input, getPath(p, path));
        const out = input.closest('.field')?.querySelector('output');
        if (out) out.textContent = `${value}${input.dataset.unit || ''}`;
        const structural = ['amount.enabled', 'sound.source'].includes(path) || path.endsWith('.glyph');
        edit(path, value, { rebuild: false });
        if (structural) renderInspector();
        if (path === 'amount.enabled' && value) showStill();
    });
    inspector.addEventListener('click', event => {
        const segButton = event.target.closest('[data-seg]');
        if (segButton) {
            const path = segButton.dataset.seg, raw = segButton.dataset.value;
            edit(path, raw, { rebuild: true });
            if (path === 'visual.type' && raw === 'image' && !editing().visual.assetId) $('[data-upload="visual.assetId"]')?.click();
            return;
        }
        const remove = event.target.closest('[data-remove-action]');
        if (remove) { const i = Number(remove.dataset.removeAction); editCommit(p => p.social.actions.splice(i, 1)); }
    });
    inspector.addEventListener('change', async event => {
        const upload = event.target.closest('[data-upload]'), soundUpload = event.target.closest('[data-upload-sound]');
        const file = event.target.files?.[0];
        if (!file || (!upload && !soundUpload)) return;
        try {
            const id = await addAsset(file, upload ? 'image' : 'audio');
            if (upload) { edit(upload.dataset.upload, id, { rebuild: true }); if (upload.dataset.upload === 'visual.assetId') edit('visual.type', 'image', { rebuild: true }); }
            else edit('sound.source', `asset:${id}`, { rebuild: true });
        } catch (error) { toast(error.message); }
    });
    function editCommit(mutate) {
        const p = editing();
        if (state.draft) { mutate(p); afterDraftChange(true); return; }
        change(`${p.id}:structure`, () => mutate(p), { rebuild: true });
    }

    // ---- presets -------------------------------------------------------------------------------------------------------
    let presetTab = 'starter';
    async function loadUserPresets() { try { userPresets = (await db.all('presets')).sort((a, b) => a.name.localeCompare(b.name)); } catch { userPresets = []; } }
    function presetCard(preset, mine = false) {
        return `<button type="button" class="preset" data-preset="${esc(preset.id)}"${mine ? ' data-mine="1"' : ''}><canvas width="380" height="166" data-preset-thumb="${esc(preset.id)}"></canvas><strong>${esc(preset.name)}</strong><span>${mine ? 'Your preset' : esc(preset.group[0].toUpperCase() + preset.group.slice(1))}</span></button>`;
    }
    function paintPresetThumbs(root, list) {
        $$('[data-preset-thumb]', root).forEach(slot => {
            const preset = list.find(p => p.id === slot.dataset.presetThumb);
            if (!preset) return;
            const plaque = preset.plaque ? preset.plaque : C.fromPreset(preset);
            const thumb = thumbnail(plaque, 380), ctx = slot.getContext('2d');
            const k = Math.min(slot.width / thumb.width, slot.height / thumb.height);
            ctx.drawImage(thumb, (slot.width - thumb.width * k) / 2, (slot.height - thumb.height * k) / 2, thumb.width * k, thumb.height * k);
        });
    }
    function renderPresets() {
        const tabs = [['starter', 'Starter'], ['topic', 'Topics'], ['social', 'Social'], ['donation', 'Donations'], ['mine', 'Mine']];
        $('[data-preset-tabs]').innerHTML = tabs.map(([id, name]) => `<button type="button" class="tk-seg-opt" role="tab" data-tab="${id}" aria-selected="${presetTab === id}">${name}</button>`).join('');
        const grid = $('[data-preset-grid]');
        if (presetTab === 'mine') {
            grid.innerHTML = userPresets.length ? userPresets.map(p => presetCard(p, true)).join('') : '<p class="preset-empty tk-empty">No presets of your own yet. Edit a plaque, then choose “Save as preset”.</p>';
            paintPresetThumbs(grid, userPresets);
        } else {
            const list = C.PRESETS.filter(p => p.group === presetTab);
            grid.innerHTML = list.map(p => presetCard(p)).join('');
            paintPresetThumbs(grid, list);
        }
    }
    function renderTopics() {
        const matches = C.suggestTopics($('[data-topic-input]').value);
        $('[data-topic-results]').innerHTML = matches.map(p => `<button type="button" data-preset="${p.id}">${esc(p.name)}</button>`).join('');
    }
    function startDraft(preset, mine) {
        stop();
        const plaque = mine ? { ...JSON.parse(JSON.stringify(preset.plaque)), id: C.uid('p') } : C.fromPreset(preset);
        state.draft = plaque;
        state.draftEdited = false;
        tellHost();
        state.draftPreset = mine ? preset.id : null;
        $('[data-draft-banner]').hidden = false;
        $('[data-draft-name]').textContent = preset.name;
        $('[data-sheet="presets"]').close();
        renderList(); renderInspector(); showStill(); updateButtons();
    }
    function endDraft(commit) {
        const draft = state.draft;
        state.draft = null;
        state.draftEdited = false;
        $('[data-draft-banner]').hidden = true;
        if (commit && draft) {
            if (state.draftPreset) presetLinks.set(draft.id, state.draftPreset);
            addPlaques([draft]);
            toast('Added to the set');
        } else { renderList(); renderInspector(); showStill(); updateButtons(); }
        state.draftPreset = null;
        tellHost(); // added: the set's own save now carries the work; discarded: nothing is left to lose
    }
    async function savePreset(update) {
        const p = editing();
        if (!p) return;
        let record;
        if (update) {
            record = userPresets.find(u => u.id === presetLinks.get(p.id));
            if (!record) return;
            record.plaque = JSON.parse(JSON.stringify(p));
        } else {
            const name = window.prompt('Name this preset', p.title.split('\n')[0].slice(0, 40) || 'My preset');
            if (!name) return;
            record = { id: C.uid('u'), name: name.slice(0, 60), group: 'mine', plaque: JSON.parse(JSON.stringify(p)) };
        }
        // Presets are plaques with no set around them: images they use travel inside the preset.
        const used = [p.visual.assetId, p.social?.pageAssetId, p.social?.platformAssetId, p.sound.source.startsWith('asset:') ? p.sound.source.slice(6) : null].filter(Boolean);
        record.assets = Object.fromEntries(used.filter(id => state.set.assets[id]).map(id => [id, state.set.assets[id]]));
        await db.put('presets', record);
        presetLinks.set(p.id, record.id);
        await loadUserPresets();
        renderInspector();
        toast(update ? `Preset “${record.name}” updated` : `Saved preset “${record.name}”`);
    }

    // ---- output controls -------------------------------------------------------------------------------------------
    function syncOutputControls() {
        const o = state.set.output;
        $$('[data-out]').forEach(input => { input.value = String(o[input.dataset.out]); });
        $$('[data-frame-only]').forEach(node => { node.hidden = o.mode !== 'frame'; });
        $$('[data-custom-only]').forEach(node => { node.hidden = o.mode !== 'frame' || o.frame !== 'custom'; });
        $('[data-anchors]').innerHTML = C.ANCHORS.map(a => `<button type="button" data-anchor="${a}" aria-pressed="${o.anchor === a}" aria-label="${a.replace('-', ' ')}" title="${a.replace('-', ' ')}"></button>`).join('');
        $('[data-output-summary]').textContent = o.mode === 'frame' ? `${o.width} × ${o.height} · ${o.fps} fps · ${o.anchor.replace('-', ' ')}` : `Tight crop · ${o.fps} fps`;
    }
    $('[data-output-panel]').addEventListener('change', event => {
        const input = event.target.closest('[data-out]');
        if (!input) return;
        const key = input.dataset.out;
        change(`output:${key}`, () => {
            const o = state.set.output;
            let value = input.value;
            if (key === 'fps') value = [...input.options].some(option => Number(option.value) === Number(value)) ? Number(value) : o.fps;
            else if (['width', 'height', 'scale', 'margin'].includes(key)) value = C.clamp(Number(value), Number(input.min), Number(input.max), o[key]);
            if (key === 'width' || key === 'height') value = Math.round(value / 2) * 2;
            o[key] = value;
            if (key === 'frame' && C.FRAMES[value]) [o.width, o.height] = C.FRAMES[value];
        });
        syncOutputControls();
    });
    $('[data-output-panel]').addEventListener('click', event => {
        const button = event.target.closest('[data-anchor]');
        if (!button) return;
        change('output:anchor', () => { state.set.output.anchor = button.dataset.anchor; });
        syncOutputControls();
    });

    // ---- export ----------------------------------------------------------------------------------------------------
    const FORMATS = [
        ['png', 'PNG still', 'Full alpha. One plaque gives one PNG; several give a zip with one PNG each, at the moment each is fully in.'],
        ['apng', 'APNG animation', 'Full alpha, every frame. Plays in browsers and most editors that read PNG.'],
        ['gif', 'GIF animation', 'Binary transparency: each pixel is either fully shown or fully clear, so soft shadows become hard edges.'],
        ['sequence', 'PNG sequence (zip)', 'One PNG per frame with full alpha, numbered from 00000. Import it as an image sequence in any editor.'],
        ['webm-key', 'WebM, chroma key', 'A solid key colour behind the plaques, with the sound mixed in. Key the colour out in your editor. Records in real time.'],
        ['wav', 'Sound (WAV)', '48 kHz 16-bit PCM, every sound at the moment its plaque or click appears.'],
        ['json', 'Set file (JSON)', 'The whole set, images and sounds included, to open again here or hand to an agent.'],
    ];
    let alphaWebm = null; // the optional transparent WebM shows only when this browser really records alpha
    async function probeAlphaWebm() {
        if (alphaWebm !== null) return alphaWebm;
        alphaWebm = false;
        try {
            const type = ['video/webm;codecs=vp9', 'video/webm;codecs=vp8'].find(t => window.MediaRecorder?.isTypeSupported(t));
            if (!type) return false;
            const c = document.createElement('canvas'); c.width = 64; c.height = 64;
            const x = c.getContext('2d');
            const blob = await record(c, type, 6, 30, n => { x.clearRect(0, 0, 64, 64); x.fillStyle = '#ff0000'; x.fillRect(0, 0, 32, 64); }, null);
            const video = document.createElement('video'); video.muted = true; video.src = URL.createObjectURL(blob);
            await new Promise((done, fail) => { video.onloadeddata = done; video.onerror = fail; setTimeout(fail, 3000); });
            const r = document.createElement('canvas'); r.width = 64; r.height = 64;
            const rc = r.getContext('2d', { willReadFrequently: true }); rc.drawImage(video, 0, 0);
            const right = rc.getImageData(48, 32, 1, 1).data, left = rc.getImageData(16, 32, 1, 1).data;
            URL.revokeObjectURL(video.src);
            alphaWebm = right[3] < 16 && left[3] > 240 ? type : false;
        } catch { alphaWebm = false; }
        log('export.alpha-webm-probe', { supported: Boolean(alphaWebm) });
        return alphaWebm;
    }
    async function openExport() {
        const scope = $('[data-export-scope]');
        // A choice set aside for the set file at the last opening belongs to that opening: start from this one's.
        scope.disabled = false;
        delete scope.dataset.before;
        scope.value = state.selected.size > 1 ? 'selected' : state.scope;
        const formats = FORMATS.slice();
        if (await probeAlphaWebm()) formats.splice(5, 0, ['webm-alpha', 'WebM, transparent', 'VP9 or VP8 with an alpha channel, recorded in real time. Plays transparent in browsers and editors that read WebM alpha.']);
        const select = $('[data-export-format]'), saved = localStorage.getItem('plaque:format');
        select.innerHTML = options(formats.map(([v, l]) => [v, l]), saved && formats.some(f => f[0] === saved) ? saved : 'png');
        select.dataset.hints = JSON.stringify(Object.fromEntries(formats.map(([v, , h]) => [v, h])));
        exportOptions();
        $('[data-export-status]').textContent = '';
        $('[data-export-status]').className = 'tk-status';
        showSheet('export');
    }
    // The commit says what it does for the chosen format.
    const EXPORT_LABELS = { png: 'Download PNG', apng: 'Download APNG', gif: 'Download GIF', sequence: 'Download frames', 'webm-key': 'Record WebM', 'webm-alpha': 'Record WebM', wav: 'Download WAV', json: 'Download set file' };
    function exportOptions() {
        const format = $('[data-export-format]').value;
        $('[data-export-hint]').textContent = JSON.parse($('[data-export-format]').dataset.hints || '{}')[format] || '';
        $('[data-export-options]').innerHTML = format === 'webm-key'
            ? `<label class="tk-field">Key colour<select class="tk-select" data-key-color>${options([['#00ff00', 'Green'], ['#0000ff', 'Blue'], ['#ff00ff', 'Magenta']], localStorage.getItem('plaque:key') || '#00ff00')}</select></label>`
            : format === 'wav' ? `<label class="tk-check"><input type="checkbox" data-pad${state.set.output.padSound ? ' checked' : ''}><span>Pad with silence to the animation length</span></label>` : '';
        // The set file always holds the whole set, so the choice says so while it is set aside, and comes back after.
        const scope = $('[data-export-scope]');
        if (format === 'json') { if (!scope.disabled) scope.dataset.before = scope.value; scope.value = 'set'; }
        else if (scope.disabled && scope.dataset.before) scope.value = scope.dataset.before;
        scope.disabled = format === 'json';
        $('[data-action="run-export"]').textContent = EXPORT_LABELS[format] || 'Download';
        $('[data-action="copy-agent"]').hidden = format !== 'json';
    }
    let exportJob = null;
    function progress(fraction) { $('[data-export-progress]').hidden = fraction == null; $('[data-export-bar]').style.width = `${Math.round((fraction || 0) * 100)}%`; }
    function download(blob, name) {
        const url = URL.createObjectURL(blob), link = document.createElement('a');
        link.href = url; link.download = name; document.body.append(link); link.click(); link.remove();
        setTimeout(() => URL.revokeObjectURL(url), 60000);
    }
    // Lets the page repaint between frames. A message, unlike a timer, is not throttled while the tab is in the background.
    const yieldChannel = new MessageChannel();
    const yieldToPage = () => new Promise(done => { yieldChannel.port1.onmessage = () => done(); yieldChannel.port2.postMessage(0); });
    const toBytes = blob => blob.arrayBuffer().then(b => new Uint8Array(b));
    const pngBlob = c => new Promise(done => c.toBlob(done, 'image/png'));
    // Draws every frame of `plaques` with drawFrame and hands each to onFrame. The frames are exactly the ones the
    // preview shows at t = n / fps.
    async function frames(plaques, { background = null, onFrame, job, fps: rate = null }) {
        await preloadAll();
        const { c, ctx } = frameCanvas();
        const output = state.set.output, size = C.canvasSize(ctx, output, plaques);
        c.width = size.width; c.height = size.height;
        const line = C.timeline(plaques), fps = rate || output.fps, count = C.frameCount(line, fps);
        for (let n = 0; n < count; n++) {
            if (job?.cancelled) throw new Error('cancelled');
            C.drawFrame(ctx, { output, plaques, size, background }, n / fps, resolve, makePath);
            await onFrame(c, ctx, n, count);
            if (n % 4 === 0) { progress((n + 1) / count); await yieldToPage(); }
        }
        return { size, count, fps };
    }
    async function stills(plaques) {
        await preloadAll();
        const files = [];
        const output = state.set.output;
        for (const [index, plaque] of plaques.entries()) {
            const { c, ctx } = frameCanvas();
            const size = C.canvasSize(ctx, output, [plaque]);
            c.width = size.width; c.height = size.height;
            C.drawFrame(ctx, { output, plaques: [plaque], size, background: null }, C.stillTime(plaque), resolve, makePath);
            files.push({ name: `${String(index + 1).padStart(2, '0')}-${slug(plaque.title)}.png`, blob: await pngBlob(c) });
        }
        return files;
    }
    // Real-time recording through MediaRecorder: frame n is requested at n / fps seconds after the start.
    function record(c, type, count, fps, drawN, audioPlan) {
        return new Promise((done, fail) => {
            const stream = c.captureStream(0), track = stream.getVideoTracks()[0];
            let audio = null;
            if (audioPlan) {
                audio = new AudioContext();
                const dest = audio.createMediaStreamDestination();
                stream.addTrack(dest.stream.getAudioTracks()[0]);
                audioPlan.dest = dest; audioPlan.ctx = audio;
            }
            const recorder = new MediaRecorder(stream, { mimeType: type, videoBitsPerSecond: 12e6 });
            const chunks = [];
            recorder.ondataavailable = e => { if (e.data.size) chunks.push(e.data); };
            recorder.onstop = () => { audio?.close(); done(new Blob(chunks, { type: type.split(';')[0] })); };
            recorder.onerror = e => fail(e.error || new Error('recording failed'));
            recorder.start();
            const start = performance.now();
            if (audioPlan) audioPlan.start(audio.currentTime + 0.02);
            let n = 0;
            const next = () => {
                if (audioPlan?.job?.cancelled) { recorder.stop(); return; }
                if (n >= count) { setTimeout(() => recorder.stop(), 1000 / fps + 30); return; }
                drawN(n); track.requestFrame(); n++;
                if (audioPlan) progress(n / count);
                setTimeout(next, Math.max(0, start + n * 1000 / fps - performance.now()));
            };
            next();
        });
    }
    async function webm(plaques, { alpha, keyColor, job }) {
        const type = alpha ? alphaWebm : ['video/webm;codecs=vp9,opus', 'video/webm;codecs=vp8,opus', 'video/webm'].find(t => MediaRecorder.isTypeSupported(t));
        if (!type) throw new Error('This browser cannot record WebM.');
        await preloadAll();
        const { c, ctx } = frameCanvas();
        const output = state.set.output, size = C.canvasSize(ctx, output, plaques);
        c.width = size.width; c.height = size.height;
        const line = C.timeline(plaques), count = C.frameCount(line, output.fps);
        const { events } = C.soundSchedule(plaques), loaded = await Promise.all(events.map(e => getAudio(e.source)));
        const audioPlan = alpha ? null : {
            job,
            start(at) {
                events.forEach((e, i) => { if (!loaded[i]) return; const src = this.ctx.createBufferSource(), g = this.ctx.createGain(); src.buffer = loaded[i]; g.gain.value = e.volume; src.connect(g).connect(this.dest); src.start(at + e.at); });
            },
        };
        return record(c, type, count, output.fps, n => C.drawFrame(ctx, { output, plaques, size, background: alpha ? null : keyColor }, n / output.fps, resolve, makePath), audioPlan || { job, start() {} });
    }
    async function wavBlob(plaques, pad) {
        const { events, duration } = C.soundSchedule(plaques);
        const loaded = await Promise.all(events.map(e => getAudio(e.source)));
        const soundEnd = events.reduce((end, e, i) => Math.max(end, loaded[i] ? e.at + loaded[i].duration : 0), 0);
        const length = pad ? Math.max(duration, soundEnd) : soundEnd;
        if (!length) throw new Error('These plaques have no sound to export.');
        const rate = S.RATE, ctx = new OfflineAudioContext(2, Math.ceil(length * rate), rate);
        events.forEach((e, i) => { if (!loaded[i]) return; const src = ctx.createBufferSource(), g = ctx.createGain(); src.buffer = loaded[i]; g.gain.value = e.volume; src.connect(g).connect(ctx.destination); src.start(e.at); });
        const rendered = await ctx.startRendering();
        return new Blob([E.wav([rendered.getChannelData(0), rendered.getChannelData(1)], rate)], { type: 'audio/wav' });
    }
    // Builds the files for one export choice. Used by Export (download) and Save to library (upload).
    async function buildExport(format, scope, options = {}) {
        const plaques = scopePlaques(scope), base = slug(state.set.name);
        if (format === 'json') return [{ name: `${base}.plaque.json`, blob: new Blob([JSON.stringify(C.exportSet(state.set), null, 2)], { type: 'application/json' }) }];
        if (!plaques.length) throw new Error('There are no plaques to export.');
        const job = options.job;
        if (format === 'png') {
            const files = await stills(plaques);
            if (files.length === 1 || options.separate) return files.length === 1 ? [{ ...files[0], name: `${base}.png` }] : files;
            return [{ name: `${base}-stills.zip`, blob: new Blob([E.zip(await Promise.all(files.map(async f => ({ name: f.name, data: await toBytes(f.blob) }))))], { type: 'application/zip' }) }];
        }
        if (format === 'apng') {
            const pngs = [];
            const { fps } = await frames(plaques, { job, onFrame: async c => pngs.push(await toBytes(await pngBlob(c))) });
            return [{ name: `${base}.apng.png`, blob: new Blob([E.apng(pngs, pngs.map(() => [1, fps]))], { type: 'image/apng' }) }];
        }
        if (format === 'gif') {
            const list = [];
            const { size, count, fps } = await frames(plaques, { job, fps: E.gifFps(state.set.output.fps), onFrame: (c, ctx) => list.push({ rgba: ctx.getImageData(0, 0, c.width, c.height).data }) });
            const delays = E.gifDelays(count, fps);
            list.forEach((f, i) => { f.delay = delays[i]; });
            return [{ name: `${base}.gif`, blob: new Blob([E.gif(list, size.width, size.height)], { type: 'image/gif' }) }];
        }
        if (format === 'sequence') {
            const files = [];
            await frames(plaques, { job, onFrame: async (c, ctx, n) => files.push({ name: `${base}_${String(n).padStart(5, '0')}.png`, data: await toBytes(await pngBlob(c)) }) });
            return [{ name: `${base}-frames.zip`, blob: new Blob([E.zip(files)], { type: 'application/zip' }) }];
        }
        if (format === 'webm-key' || format === 'webm-alpha') {
            const blob = await webm(plaques, { alpha: format === 'webm-alpha', keyColor: options.keyColor || '#00ff00', job });
            return [{ name: `${base}${format === 'webm-alpha' ? '-alpha' : '-key'}.webm`, blob }];
        }
        if (format === 'wav') return [{ name: `${base}.wav`, blob: await wavBlob(plaques, options.pad ?? state.set.output.padSound) }];
        throw new Error('Unknown format.');
    }
    async function runExport() {
        const format = $('[data-export-format]').value, scope = $('[data-export-scope]').value;
        const status = $('[data-export-status]');
        const keyColor = $('[data-key-color]')?.value, pad = $('[data-pad]')?.checked;
        if (keyColor) localStorage.setItem('plaque:key', keyColor);
        localStorage.setItem('plaque:format', format);
        stop();
        exportJob = { cancelled: false };
        $('[data-action="run-export"]').disabled = true;
        $('[data-action="cancel-export"]').hidden = false;
        $('[data-export-close]').hidden = true;
        status.className = 'tk-status'; status.textContent = format.startsWith('webm') ? 'Recording in real time…' : 'Rendering…';
        progress(0);
        const started = performance.now();
        try {
            const files = await buildExport(format, scope, { keyColor, pad, job: exportJob });
            files.forEach(f => download(f.blob, f.name));
            status.textContent = `Exported ${files.map(f => f.name).join(', ')} (${Math.round(files.reduce((s, f) => s + f.blob.size, 0) / 1024)} KB).`;
            log('export.done', { format, scope, ms: Math.round(performance.now() - started), bytes: files.reduce((s, f) => s + f.blob.size, 0) });
        } catch (error) {
            status.className = error.message === 'cancelled' ? 'tk-status' : 'tk-status is-error';
            status.textContent = error.message === 'cancelled' ? 'Export cancelled.' : `Export failed: ${error.message}`;
            log('export.failed', { format, message: error.message });
        } finally {
            exportJob = null; progress(null);
            $('[data-action="run-export"]').disabled = false;
            $('[data-action="cancel-export"]').hidden = true;
            $('[data-export-close]').hidden = false;
        }
    }

    // ---- open / import: everything goes through validateSet -----------------------------------------------------------
    async function importSet(input, source) {
        const result = C.validateSet(input);
        const list = $('[data-open-errors]');
        if (!result.ok) { list.innerHTML = result.errors.map(e => `<li>${esc(e)}</li>`).join(''); log('import.refused', { source, errors: result.errors.length }); return false; }
        endDraftSilently();
        change('import', () => {
            state.set = result.set;
            state.current = state.set.plaques[0]?.id ?? null;
            state.selected = new Set();
        }, { rebuild: true });
        images.clear(); buffers.clear(); thumbs.clear();
        await preloadAll();
        syncOutputControls();
        showStill();
        $('[data-sheet="open"]').close();
        toast(result.errors.length ? `Opened with ${result.errors.length} fix${result.errors.length > 1 ? 'es' : ''}: ${result.errors[0]}` : `Opened “${state.set.name}”`);
        log('import.done', { source, plaques: state.set.plaques.length, fixes: result.errors.length });
        return true;
    }
    function endDraftSilently() { if (state.draft) { state.draft = null; state.draftEdited = false; state.draftPreset = null; $('[data-draft-banner]').hidden = true; tellHost(); } }
    async function openDialog() {
        $('[data-open-errors]').innerHTML = '';
        $('[data-paste-text]').value = '';
        showSheet('open');
        const box = $('[data-library-sets]');
        if (!hostContext.library) { box.innerHTML = '<p class="tk-empty">Sets kept in the Library open here for accounts with Library access. Files and pasted sets open for everyone.</p>'; return; }
        box.innerHTML = '<p class="tk-note">Finding sets…</p>';
        try {
            const response = await fetch(new URL('home/plaque/sets', ADMIN), { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
            if (!response.ok) throw new Error(String(response.status));
            const { sets } = await response.json();
            box.innerHTML = sets.length ? sets.map(s => `<button type="button" data-library-set="${esc(s.download)}"><strong>${esc(s.name)}</strong><span>${esc(s.updated)}</span></button>`).join('') : '<p class="tk-empty">No sets in the Library yet. Save to Library keeps one there, and an agent can save a <code>.plaque.json</code> file there too.</p>';
        } catch {
            box.innerHTML = '<p class="tk-empty">The Library cannot be reached from here. Files and pasted sets still open.</p>';
        }
    }

    // ---- Save to Library, through the frame's bridge, which records the tool with each file -------------------------
    // A render, the sound and the set file, into a folder this account may upload to. The files are made from the set
    // as it is when Save is pressed, and the sheet holds until the Library answers. A failure that arrives after the
    // sheet was closed is said in the bar (or a toast), and again when the sheet is opened.
    let folders = null, saving = false, wantWav = true, lateError = '';
    function setLibrary({ message = '', error = false } = {}) {
        const none = Array.isArray(folders) && !folders.length;
        const scope = $('[data-library-scope]').value, base = slug(state.set.name);
        const sound = C.soundSchedule(scopePlaques(scope)).events.length > 0;
        const wav = $('[data-library-wav]');
        wav.checked = sound && wantWav;
        wav.disabled = saving || !sound;
        $('[data-library-wav-name]').textContent = sound ? `${base}.wav` : 'These plaques have no sound';
        $('[data-library-set-name]').textContent = `${base}.plaque.json · opens again with “Open a set”`;
        $('[data-folder-field]').hidden = none;
        $('[data-folder-empty]').hidden = !none;
        for (const sel of ['[data-library-format]', '[data-library-scope]', '[data-library-folder]']) $(sel).disabled = saving;
        const go = $('[data-action="save-library"]');
        go.disabled = saving || none || !folders;
        go.textContent = saving ? 'Saving…' : 'Save';
        const line = $('[data-library-status]');
        line.textContent = message || (saving ? 'Rendering and saving to the Library…' : '');
        line.classList.toggle('is-error', error);
    }
    async function openLibrary() {
        if (!frame?.library || !state.set.plaques.length || state.draft) return;
        if (!saving) {
            const format = localStorage.getItem('plaque:library-format');
            if (format && [...$('[data-library-format]').options].some(o => o.value === format)) $('[data-library-format]').value = format;
            $('[data-library-scope]').value = state.selected.size > 1 ? 'selected' : 'set';
        }
        showSheet('library');
        const late = lateError;
        lateError = '';
        if (folders) { setLibrary(late ? { message: late, error: true } : {}); return; }
        setLibrary({ message: 'Finding your folders…' });
        try {
            folders = await frame.folders();
            const select = $('[data-library-folder]'), remembered = localStorage.getItem('plaque:folder');
            select.innerHTML = folders.map(f => `<option value="${esc(f.id)}">${'\u00a0\u00a0'.repeat(f.depth)}${esc(f.name)}</option>`).join('');
            if (folders.some(f => String(f.id) === remembered)) select.value = remembered;
            setLibrary(late ? { message: late, error: true } : {});
        } catch (error) {
            folders = null;
            setLibrary({ message: `${error.message || 'Your folders could not be read.'} Close this and try again.`, error: true });
        }
    }
    async function saveToLibrary() {
        if (saving || !folders?.length) return;
        const folder = $('[data-library-folder]').value, format = $('[data-library-format]').value, scope = $('[data-library-scope]').value;
        const withWav = $('[data-library-wav]').checked;
        saving = true;
        setLibrary();
        let failed = null;
        try {
            const files = [];
            if (format !== 'none') files.push(...await buildExport(format, scope, { separate: true, keyColor: '#00ff00' }));
            if (withWav && C.soundSchedule(scopePlaques(scope)).events.length) files.push(...await buildExport('wav', scope, { pad: state.set.output.padSound }));
            files.push(...await buildExport('json', scope));
            const saved = await frame.save({ files, folder });
            localStorage.setItem('plaque:folder', folder);
            localStorage.setItem('plaque:library-format', format);
            const text = `Saved ${saved.count} file${saved.count === 1 ? '' : 's'} to ${folders.find(f => String(f.id) === folder)?.name || 'the Library'}.`;
            $('[data-sheet="library"]').close();
            frame.notice({ text, ...(saved.url ? { link: { label: 'Open in Library', url: saved.url } } : {}) });
            // Where the page draws a bar, its status line says it once, with the link. Elsewhere the tool says it.
            if (!frame.bar) toast(text);
            log('library.saved', { files: files.length, format, scope });
        } catch (error) {
            failed = error;
            log('library.failed', { message: error.message });
        } finally {
            saving = false;
        }
        if (!failed) return;
        const message = failed.message || 'The files could not be saved. Try again.';
        if ($('[data-sheet="library"]').open) {
            setLibrary({ message, error: true });
            // Save was disabled while it ran, which dropped the focus; give it back so Enter tries again.
            $('[data-action="save-library"]').focus();
            return;
        }
        lateError = message;
        frame.notice({ text: `Not saved to the Library: ${message}`, tone: 'error' });
        if (!frame.bar) toast(`Not saved to the Library: ${message}`, { error: true });
    }
    // The set as JSON on the clipboard, for an agent to edit and hand back through "Open a set".
    async function copyForAgent() {
        const status = $('[data-export-status]');
        const json = JSON.stringify(C.exportSet(state.set), null, 2);
        status.className = 'tk-status';
        try {
            await navigator.clipboard.writeText(json);
            status.textContent = `Copied ${state.set.plaques.length} plaque${state.set.plaques.length === 1 ? '' : 's'} as ${Math.round(json.length / 1024)} KB of JSON. An agent hands it back through “Open a set”.`;
        } catch {
            download(new Blob([json], { type: 'application/json' }), `${slug(state.set.name)}.plaque.json`);
            status.textContent = 'The clipboard is not available here, so the set was downloaded as a file instead.';
        }
    }

    // ---- AI generation through the host's AI integration --------------------------------------------------------------
    // The server holds the system prompt and talks to the Magic AI platform; the tool only sends the brief.
    let aiResult = null;
    const hostContext = { library: false, ai: false };
    async function probeHost() {
        try {
            const response = await fetch(new URL('home/plaque/context', ADMIN), { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
            if (response.ok) Object.assign(hostContext, await response.json());
        } catch { /* offline: everything but the Library and AI still works */ }
        $$('[data-ai-open]').forEach(button => { button.hidden = !hostContext.ai; });
        log('host.context', hostContext);
    }
    async function generate() {
        const field = name => $(`[data-ai="${name}"]`).value.trim();
        const description = field('description');
        const status = $('[data-ai-status]');
        if (!description) { status.className = 'tk-status is-error'; status.textContent = 'Describe the video first.'; return; }
        const count = name => Math.max(0, Math.min(12, Math.round(Number(field(name)) || 0)));
        const brief = { description, themed: count('themed'), social: count('social'), donation: count('donation'), length: field('length') || null, destination: field('destination') || null, tone: field('tone') || null };
        status.className = 'tk-status'; status.textContent = 'Asking the AI…';
        $('[data-action="ai-generate"]').disabled = true;
        try {
            const response = await fetch(new URL('home/plaque/generate', ADMIN), { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify(brief) });
            const body = await response.json().catch(() => ({}));
            if (!response.ok) throw new Error(response.status === 429 ? 'too many drafts in a minute' : body.message || `the server answered ${response.status}`);
            const textOut = String(body.content || '');
            const start = textOut.indexOf('{'), end = textOut.lastIndexOf('}');
            if (start < 0 || end < start) throw new Error('the answer held no JSON');
            const parsed = JSON.parse(textOut.slice(start, end + 1));
            // The answer passes through the same importer as any file, merged over full defaults first.
            const plaques = (Array.isArray(parsed.plaques) ? parsed.plaques : []).map(p => C.merge(C.defaultPlaque(p?.kind === 'social' ? 'social' : 'standard'), p && typeof p === 'object' ? p : {}));
            const result = C.validateSet({ format: C.FORMAT, version: C.VERSION, name: parsed.name || 'Generated set', output: state.set.output, plaques });
            if (!result.ok || !result.set.plaques.length) throw new Error(result.errors[0] || 'no plaques came back');
            aiResult = result.set;
            const preview = $('[data-ai-preview]');
            preview.hidden = false;
            preview.innerHTML = aiResult.plaques.map((p, i) => `<div><canvas data-ai-thumb="${i}"></canvas><span><strong>${esc(p.title)}</strong><br>${esc(p.subtitle)}</span></div>`).join('');
            $$('[data-ai-thumb]', preview).forEach(slot => { const t = thumbnail(aiResult.plaques[Number(slot.dataset.aiThumb)], 180); slot.width = t.width; slot.height = t.height; slot.getContext('2d').drawImage(t, 0, 0); });
            status.textContent = `${aiResult.plaques.length} plaques ready. Append them, or replace the set. Undo reverts either in one step.`;
            setAiCommit(true);
            log('ai.generated', { plaques: aiResult.plaques.length, fixes: result.errors.length });
        } catch (error) {
            status.className = 'tk-status is-error';
            status.textContent = `Nothing generated: ${error.message}. Presets and topic suggestions still work offline.`;
        } finally { $('[data-action="ai-generate"]').disabled = false; }
    }
    // One filled button at a time: Generate until there is an answer, then Append (the next step), with Generate again
    // left quiet beside it.
    function setAiCommit(ready) {
        const append = $('[data-action="ai-append"]'), replace = $('[data-action="ai-replace"]'), go = $('[data-action="ai-generate"]');
        append.hidden = !ready;
        replace.hidden = !ready || !state.set.plaques.length;
        append.classList.toggle('is-act', ready);
        go.classList.toggle('is-act', !ready);
        go.textContent = ready ? 'Generate again' : 'Generate';
    }
    function openAi() {
        aiResult = null;
        $('[data-ai-preview]').hidden = true;
        const status = $('[data-ai-status]');
        status.className = 'tk-status'; status.textContent = '';
        setAiCommit(false);
        showSheet('ai');
    }
    function applyAi(replace) {
        if (!aiResult) return;
        const incoming = aiResult.plaques.map(p => ({ ...p, id: C.uid('p') }));
        endDraftSilently();
        change('ai', () => {
            if (replace) state.set.plaques = incoming; else state.set.plaques.push(...incoming);
            if (replace && aiResult.name) state.set.name = aiResult.name;
            state.current = incoming[0].id;
        }, { rebuild: true });
        aiResult = null;
        $('[data-sheet="ai"]').close();
        showStill();
        toast(replace ? 'Set replaced. Undo brings the old one back.' : `${incoming.length} plaques appended`);
    }

    // ---- toolbar, keyboard, misc ----------------------------------------------------------------------------------
    let toastTimer;
    // Says what just happened, once; a delete's toast also offers Undo and stays up longer so it can be reached.
    function toast(message, { undo: offerUndo = false, error = false } = {}) {
        const node = $('[data-toast]');
        const text = document.createElement('span');
        text.textContent = message;
        node.replaceChildren(text);
        if (offerUndo) {
            const button = document.createElement('button');
            button.type = 'button'; button.className = 'tk-link'; button.textContent = 'Undo';
            button.addEventListener('click', () => { node.hidden = true; undo(); });
            node.append(button);
        }
        node.classList.toggle('is-error', error);
        node.hidden = false;
        clearTimeout(toastTimer); toastTimer = setTimeout(() => { node.hidden = true; }, offerUndo || error ? 6000 : 3200);
    }
    const openSheet = () => $('dialog[open]');
    function showSheet(name) {
        const sheet = $(`[data-sheet="${name}"]`);
        if (sheet.open) return;
        openSheet()?.close();
        sheet.showModal();
        const first = sheet.querySelector('.tk-sheet-body :is(button, select, input[type="checkbox"], input[type="radio"], input[type="search"]):not(:disabled)')
            ?? sheet.querySelector('.tk-sheet-foot button:not(:disabled):not([hidden])');
        first?.focus();
    }
    // Under 1100 px the panes share the space under the preview; a segment picks which one shows.
    function showPane(name) {
        root.dataset.view = name;
        draw(); // the close-up preview stands down while the Output pane is open
        $$('[data-view-opt]').forEach(b => b.setAttribute('aria-pressed', String(b.dataset.viewOpt === name)));
        try { localStorage.setItem('plaque:view', name); } catch { /* the choice is only remembered where storage works */ }
        showName();
    }
    // The page's bar: the set's name, a set menu holding only "Open a set…", and Export and Save to Library while
    // there is a set to take out. A preset draft hides both, so "Add to set" is the one filled button.
    let told = '';
    function tellBar() {
        if (!frame) return;
        const offer = state.set.plaques.length > 0 && !state.draft;
        const bar = { set: state.set.name, sets: [], setsLabel: 'Plaque sets', manage: 'Open a set…', export: offer, library: offer };
        const next = JSON.stringify(bar);
        if (next === told) return;
        told = next;
        frame.chrome(bar);
    }
    function updateButtons() {
        $('[data-action="undo"]').disabled = !history.past.length;
        $('[data-action="redo"]').disabled = !history.future.length;
        const none = !state.set.plaques.length;
        $('[data-own-bar] [data-action="open-export"]').disabled = none && !state.draft;
        $('[data-own-bar] [data-action="open-library"]').disabled = none || Boolean(state.draft);
        tellBar();
    }
    const actions = {
        'add-blank': () => {
            endDraftSilently();
            addPlaques([C.defaultPlaque()], { key: 'add-blank' });
            // Where Edit shares the space with the list, a new plaque goes straight to its editor.
            if (getComputedStyle($('[data-pane="edit"]')).display === 'none') showPane('edit');
            $('[data-k="title"]')?.focus(); $('[data-k="title"]')?.select();
        },
        'open-presets': async () => { await loadUserPresets(); renderPresets(); renderTopics(); showSheet('presets'); },
        'commit-draft': () => endDraft(true),
        'discard-draft': () => endDraft(false),
        undo, redo,
        play: () => (state.playing ? stop() : play()),
        'step-back': () => step(-1),
        'step-forward': () => step(1),
        'close-up': () => { state.closeUp = !closeUpOn(); draw(); },
        'select-all': () => { state.selected = new Set(state.set.plaques.map(p => p.id)); renderList(); },
        'select-none': () => { state.selected.clear(); renderList(); },
        duplicate: () => duplicate(state.current),
        delete: () => removePlaques([state.current]),
        'bulk-delete': () => removePlaques([...state.selected]),
        'bulk-apply': () => {
            const source = plaqueById(state.current), parts = $$('[data-bulk-part]:checked').map(i => i.dataset.bulkPart);
            if (!source || !parts.length) { toast('Tick at least one group to copy.'); return; }
            change('bulk', () => {
                for (const p of state.set.plaques) {
                    if (!state.selected.has(p.id) || p.id === source.id) continue;
                    for (const part of parts) {
                        if (part === 'amount') Object.assign(p.amount, { prefix: source.amount.prefix, suffix: source.amount.suffix, decimals: source.amount.decimals, thousands: source.amount.thousands, countUp: source.amount.countUp });
                        else p[part] = JSON.parse(JSON.stringify(source[part]));
                    }
                }
            }, { rebuild: true });
            toast(`Copied ${parts.join(', ')} to ${state.selected.size - (state.selected.has(source.id) ? 1 : 0)} plaques`);
        },
        'add-action': () => editCommit(p => p.social.actions.push({ label: 'Like', doneLabel: 'Liked', glyph: 'thumbs-up', at: Math.min(65, (p.social.actions.at(-1)?.at ?? 1) + 1.2), sound: 'builtin:click' })),
        'hear-sound': () => { const p = editing(); if (p) previewSound(p.sound.source, p.sound.volume); },
        'save-preset': () => savePreset(false),
        'update-preset': () => savePreset(true),
        'open-export': openExport,
        'run-export': runExport,
        'cancel-export': () => { if (exportJob) exportJob.cancelled = true; },
        'open-set': openDialog,
        'paste-set': async () => { try { const text = await navigator.clipboard.readText(); await importSet(text, 'clipboard'); } catch { $('[data-open-errors]').innerHTML = '<li>The clipboard could not be read here. Paste into the box below instead.</li>'; } },
        'import-text': () => importSet($('[data-paste-text]').value, 'paste'),
        'open-library': openLibrary,
        'save-library': saveToLibrary,
        'copy-agent': copyForAgent,
        'ai-generate': generate,
        'ai-append': () => applyAi(false),
        'ai-replace': () => applyAi(true),
    };
    document.addEventListener('click', event => {
        const button = event.target.closest('[data-action]');
        if (button && actions[button.dataset.action] && !button.disabled) { event.preventDefault(); actions[button.dataset.action](); return; }
        const preset = event.target.closest('[data-preset]');
        if (preset) {
            const mine = preset.dataset.mine === '1';
            const record = mine ? userPresets.find(p => p.id === preset.dataset.preset) : C.PRESETS.find(p => p.id === preset.dataset.preset);
            if (!record) return;
            if (mine) Object.assign(state.set.assets, record.assets || {});
            startDraft(record, mine);
            return;
        }
        const tab = event.target.closest('[data-tab]');
        if (tab) { presetTab = tab.dataset.tab; renderPresets(); return; }
        const librarySet = event.target.closest('[data-library-set]');
        if (librarySet) {
            fetch(librarySet.dataset.librarySet, { credentials: 'same-origin' }).then(r => { if (!r.ok) throw new Error(); return r.text(); })
                .then(text => importSet(text, 'library')).catch(() => { $('[data-open-errors]').innerHTML = '<li>That file could not be downloaded from the library.</li>'; });
        }
        if (event.target.closest('[data-ai-open]')) { openAi(); return; }
        const close = event.target.closest('[data-sheet-close]');
        if (close) { close.closest('dialog').close(); return; }
        const view = event.target.closest('[data-view-opt]');
        if (view) showPane(view.dataset.viewOpt);
    });
    const list = $('[data-list]');
    list.addEventListener('click', event => {
        const row = event.target.closest('[data-id]');
        if (!row) return;
        const moveButton = event.target.closest('[data-move]');
        if (moveButton) { move(row.dataset.id, Number(moveButton.dataset.move)); return; }
        if (event.target.closest('[data-select], .row-check')) return; // the box, or the 44 px target round it on touch
        if (event.shiftKey || event.metaKey || event.ctrlKey) { toggleSelect(row.dataset.id); return; }
        selectPlaque(row.dataset.id);
    });
    list.addEventListener('change', event => { const box = event.target.closest('[data-select]'); if (box) toggleSelect(box.closest('[data-id]').dataset.id, box.checked); });
    function toggleSelect(id, on = !state.selected.has(id)) { if (on) state.selected.add(id); else state.selected.delete(id); renderList(); }
    let dragId = null;
    list.addEventListener('dragstart', event => { const row = event.target.closest('[data-id]'); if (!row) return; dragId = row.dataset.id; row.classList.add('dragging'); event.dataTransfer.effectAllowed = 'move'; event.dataTransfer.setData('text/plain', dragId); });
    list.addEventListener('dragover', event => { const row = event.target.closest('[data-id]'); if (!dragId || !row) return; event.preventDefault(); $$('.drop-before', list).forEach(r => r.classList.remove('drop-before')); row.classList.add('drop-before'); });
    list.addEventListener('dragend', () => { dragId = null; $$('.dragging, .drop-before', list).forEach(r => r.classList.remove('dragging', 'drop-before')); });
    list.addEventListener('drop', event => {
        const row = event.target.closest('[data-id]');
        if (!dragId || !row || row.dataset.id === dragId) return;
        event.preventDefault();
        const moving = dragId;
        change('move', () => { const items = state.set.plaques, from = items.findIndex(p => p.id === moving), [item] = items.splice(from, 1); items.splice(items.findIndex(p => p.id === row.dataset.id), 0, item); });
    });

    $('[data-set-name]').addEventListener('input', event => change('name', () => { state.set.name = event.target.value.replace(/\s*[\r\n]+\s*/g, ' ').slice(0, 120) || 'Untitled set'; }));
    $('[data-set-name]').addEventListener('keydown', event => { if (event.key === 'Enter') { event.preventDefault(); event.target.blur(); } });
    $('[data-scope]').addEventListener('change', event => { stop(); state.scope = event.target.value; state.t = 0; if (state.scope === 'current') showStill(); else draw(); });
    $('[data-mute]').addEventListener('change', event => { state.muted = event.target.checked; if (playback) playback.master.gain.value = state.muted ? 0 : 1; });
    $('[data-scrub]').addEventListener('input', event => { stop(); state.t = Number(event.target.value); paint(); });
    $('[data-export-format]').addEventListener('change', exportOptions);
    $('[data-topic-input]').addEventListener('input', renderTopics);
    $('[data-open-file]').addEventListener('change', async event => { const file = event.target.files?.[0]; if (file) await importSet(await file.text(), 'file'); event.target.value = ''; });
    $$('dialog').forEach(dialog => dialog.addEventListener('close', () => { if (dialog.dataset.sheet === 'export' && exportJob) exportJob.cancelled = true; }));
    $('[data-library-scope]').addEventListener('change', () => setLibrary());
    $('[data-library-wav]').addEventListener('change', event => { wantWav = event.target.checked; });
    // In the frame, the page's bar carries Open, Export and Save to Library; on its own the tool shows its own row.
    frame?.on('manage-sets', openDialog);
    frame?.on('export', openExport);
    frame?.on('library', openLibrary);
    $('[data-own-bar]').hidden = Boolean(frame?.bar);
    $('[data-own-bar] [data-action="open-library"]').hidden = !frame?.library;
    document.addEventListener('keydown', event => {
        const typing = event.target.closest?.('input:not([type="checkbox"]):not([type="range"]), textarea, select, [contenteditable]');
        if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'z' && !typing) { event.preventDefault(); (event.shiftKey ? redo : undo)(); return; }
        if (typing || $('dialog[open]')) return;
        if (event.key === ' ' && !event.target.closest('button')) { event.preventDefault(); actions.play(); }
        else if (event.key === 'ArrowLeft') { event.preventDefault(); step(-1); }
        else if (event.key === 'ArrowRight') { event.preventDefault(); step(1); }
        else if ((event.key === 'Delete' || event.key === 'Backspace') && event.target.closest('[data-list]')) { event.preventDefault(); removePlaques(state.selected.size > 1 ? [...state.selected] : [state.current]); }
    });
    window.addEventListener('resize', () => { draw(); showName(); });

    // ---- start: restore the autosaved set through the importer, then probe AI ------------------------------------------
    (async () => {
        try {
            const saved = await db.get('sets', 'current');
            if (saved?.set) {
                const result = C.validateSet(saved.set);
                if (result.ok) { state.set = result.set; state.current = result.set.plaques.some(p => p.id === saved.current) ? saved.current : result.set.plaques[0]?.id ?? null; }
            }
        } catch (error) { log('restore.failed', { message: String(error) }); }
        await loadUserPresets();
        showName();
        let view = 'plaques';
        try { view = localStorage.getItem('plaque:view') || view; } catch { /* storage refused: start on the list */ }
        showPane(['plaques', 'edit', 'output'].includes(view) ? view : 'plaques');
        syncOutputControls();
        renderList(); renderInspector(); updateButtons();
        await preloadAll();
        showStill();
        kept(state.set.plaques.length ? 'Kept on this device' : '');
        probeHost();
    })();
})();
