(() => {
    'use strict';

    const core = window.PaletteCore;
    const $ = selector => document.querySelector(selector);
    const esc = value => String(value ?? '').replace(/[&<>"']/g, char => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[char]));
    // Inside a Home frame localStorage is the account-backed store (home-frame.js); one value may hold 64 KB.
    const KEYS = { library: 'palette-studio:library', working: 'palette-studio:working', view: 'palette-studio:view' };
    const STORE_LIMIT = 60000;
    // Replaceable watermark for PNG exports. None ships: drop an SVG at this path inside the widget to add one.
    const WATERMARK = 'assets/watermark.svg';
    const PNG = { width: 1600, height: 900 };
    const STARTERS = [
        ['Harbour', ['#0B3954', '#087E8B', '#BFD7EA', '#FF5A5F', '#C81D25']],
        ['Orchard', ['#2E4600', '#486B00', '#A2C523', '#7D4427', '#F4E4C1']],
        ['Ember', ['#2D2A32', '#7A1F1F', '#C8553D', '#F28F3B', '#FFD5C2']],
        ['Glacier', ['#0F2A3D', '#1F5F8B', '#6FA8DC', '#CFE8F7', '#F7FBFF']],
        ['Meadow', ['#283618', '#606C38', '#FEFAE0', '#DDA15E', '#BC6C25']],
        ['Dusk', ['#22223B', '#4A4E69', '#9A8C98', '#C9ADA7', '#F2E9E4']],
        ['Citrus', ['#1B4332', '#FFB703', '#FB8500', '#FFE8A3', '#FFFDF5']],
        ['Slate', ['#111418', '#2B3138', '#59636E', '#A5AFBA', '#EEF1F4']],
        ['Coral reef', ['#05668D', '#028090', '#00A896', '#F0F3BD', '#FF6F59']],
        ['Midnight', ['#0B0C10', '#1F2833', '#C5C6C7', '#66FCF1', '#45A29E']],
    ];
    const VISION_LABELS = { deuteranopia: 'deuteranopia', protanopia: 'protanopia', tritanopia: 'tritanopia', achromatopsia: 'achromatopsia' };
    const ICONS = {
        lock: 'M6 11h12v10H6z M8 11V7a4 4 0 0 1 8 0v4',
        unlock: 'M6 11h12v10H6z M8 11V7a4 4 0 0 1 7.5-2',
        left: 'M15 6l-6 6 6 6',
        right: 'M9 6l6 6-6 6',
        up: 'M6 15l6-6 6 6',
        down: 'M6 9l6 6 6-6',
        trash: 'M4 7h16 M9 7V4h6v3 M6 7l1 13h10l1-13',
        rename: 'M4 20h4L19 9l-4-4L4 16z M13 7l4 4',
        copy: 'M8 8h12v12H8z M4 16V4h12',
        star: 'M12 3l2.8 5.7 6.2.9-4.5 4.4 1.1 6.2-5.6-2.9-5.6 2.9 1.1-6.2-4.5-4.4 6.2-.9z',
    };
    const svg = name => `<svg viewBox="0 0 24 24" aria-hidden="true"><path d="${ICONS[name]}"/></svg>`;
    const newId = () => 'p' + Date.now().toString(36) + Math.random().toString(36).slice(2, 7);

    // ---- State --------------------------------------------------------------------------------------------------

    const read = key => { try { const text = localStorage.getItem(key); return text == null ? undefined : JSON.parse(text); } catch { return undefined; } };
    const write = (key, value) => { try { localStorage.setItem(key, JSON.stringify(value)); return true; } catch { return false; } };
    function loadLibrary() {
        const stored = read(KEYS.library);
        if (!Array.isArray(stored)) return STARTERS.map(([name, colors]) => ({ id: newId(), name, colors, favourite: false }));
        return stored.filter(item => item && Array.isArray(item.colors) && item.colors.length && item.colors.length <= core.MAX_SWATCHES && item.colors.every(hex => core.normalizeHex(hex)))
            .map(item => ({ id: typeof item.id === 'string' ? item.id : newId(), name: core.cleanName(item.name, 'Untitled palette'), colors: item.colors.map(core.normalizeHex), favourite: item.favourite === true }));
    }
    let library = loadLibrary();
    function loadWork() {
        const stored = read(KEYS.working);
        if (stored && Array.isArray(stored.swatches) && stored.swatches.length && stored.swatches.length <= core.MAX_SWATCHES && stored.swatches.every(swatch => core.normalizeHex(swatch?.hex))) {
            return {
                name: core.cleanName(stored.name, 'Untitled palette'),
                swatches: stored.swatches.map(swatch => ({ hex: core.normalizeHex(swatch.hex), locked: swatch.locked === true })),
                selected: Math.min(Math.max(0, Number(stored.selected) || 0), stored.swatches.length - 1),
                savedId: typeof stored.savedId === 'string' ? stored.savedId : null,
            };
        }
        const first = library[0] || { id: null, name: STARTERS[0][0], colors: STARTERS[0][1] };
        return { name: first.name, swatches: first.colors.map(hex => ({ hex, locked: false })), selected: 0, savedId: first.id };
    }
    let work = loadWork();
    const storedView = read(KEYS.view) || {};
    const view = {
        vision: core.VISION_TYPES.includes(storedView.vision) ? storedView.vision : 'none',
        harmony: Object.hasOwn(core.HARMONIES, storedView.harmony) ? storedView.harmony : 'complementary',
    };

    const saveWork = () => write(KEYS.working, work);
    const saveView = () => write(KEYS.view, view);
    /** Stores the library if it fits; returns false (and changes nothing) when it would not. */
    function saveLibrary(next) {
        if (JSON.stringify(next).length > STORE_LIMIT) { toast('The library is full. Delete or export some palettes first.', { error: true }); return false; }
        library = next;
        write(KEYS.library, library);
        return true;
    }
    if (read(KEYS.library) === undefined) write(KEYS.library, library);

    const hexes = () => work.swatches.map(swatch => swatch.hex);
    const selectedHex = () => work.swatches[work.selected].hex;
    const shown = hex => view.vision === 'none' ? hex : core.simulate(hex, view.vision);

    // ---- Feedback -----------------------------------------------------------------------------------------------

    let toastTimer;
    function toast(message, { action, onAction, error = false } = {}) {
        const box = $('[data-toast]'), button = $('[data-toast-action]');
        $('[data-toast-text]').textContent = message;
        box.classList.toggle('is-error', error);
        button.hidden = !action;
        button.textContent = action || '';
        button.onclick = action ? () => { box.hidden = true; onAction(); } : null;
        box.hidden = false;
        clearTimeout(toastTimer);
        toastTimer = setTimeout(() => { box.hidden = true; }, action ? 7000 : 3200);
    }
    async function copy(text, label) {
        let copied = false;
        try { await navigator.clipboard.writeText(text); copied = true; }
        catch {
            const area = document.createElement('textarea');
            area.value = text; area.style.position = 'fixed'; area.style.opacity = '0';
            document.body.append(area); area.select();
            try { copied = document.execCommand('copy'); } catch { copied = false; }
            area.remove();
        }
        toast(copied ? `Copied ${label}` : `Couldn’t copy. Select and copy ${label} by hand.`, { error: !copied });
    }

    // ---- Changes ------------------------------------------------------------------------------------------------

    function commit({ editor = true, except = null } = {}) {
        work.selected = Math.min(Math.max(0, work.selected), work.swatches.length - 1);
        saveWork();
        render({ editor, except });
    }
    function setSelectedHex(hex, except) {
        work.swatches[work.selected].hex = core.normalizeHex(hex);
        commit({ except });
    }
    function select(index) {
        if (index < 0 || index >= work.swatches.length) return;
        work.selected = index;
        commit();
    }
    /** Applies a generator's hex list, offering Undo. */
    function applyHexes(next, message) {
        const before = structuredClone(work);
        work.swatches = work.swatches.map((swatch, index) => ({ ...swatch, hex: swatch.locked ? swatch.hex : next[index] }));
        commit();
        toast(message, { action: 'Undo', onAction: () => { work = before; commit(); } });
    }
    const lockedNote = () => { const count = work.swatches.filter(swatch => swatch.locked).length; return count ? ` · ${count} locked kept` : ''; };
    function runGenerate() {
        const kind = view.harmony;
        applyHexes(core.generate({ swatches: work.swatches, selected: work.selected, kind }), `Generated ${$('[data-harmony]').selectedOptions[0].textContent.toLowerCase()} from swatch ${work.selected + 1}${lockedNote()}`);
    }
    function runSurprise() {
        const { hexes: next, kind } = core.surprise({ swatches: work.swatches, selected: work.selected });
        applyHexes(next, `New ${kind.replace('-', ' ')} palette${lockedNote()}`);
    }
    function runNudge() { applyHexes(core.nudge({ swatches: work.swatches }), `Nudged${lockedNote()}`); }
    function savePalette() {
        const name = core.cleanName(work.name, 'Untitled palette');
        const existing = library.findIndex(item => item.id === work.savedId);
        let next;
        if (existing >= 0) next = library.map((item, index) => index === existing ? { ...item, name, colors: hexes() } : item);
        else {
            if (library.length >= core.MAX_LIBRARY) { toast(`The library holds ${core.MAX_LIBRARY} palettes. Delete one first.`, { error: true }); return; }
            const id = newId();
            next = [...library, { id, name, colors: hexes(), favourite: false }];
            work.savedId = id;
        }
        if (!saveLibrary(next)) return;
        work.name = name;
        commit();
        toast(`Saved “${name}”`);
    }
    function openPalette(id) {
        const palette = library.find(item => item.id === id);
        if (!palette) return;
        const before = structuredClone(work);
        work = { name: palette.name, swatches: palette.colors.map(hex => ({ hex, locked: false })), selected: 0, savedId: palette.id };
        commit();
        toast(`Opened “${palette.name}”`, { action: 'Undo', onAction: () => { work = before; commit(); } });
    }

    // ---- Rendering ----------------------------------------------------------------------------------------------

    function render({ editor = true, except = null } = {}) {
        renderSwatches();
        if (editor) renderEditor(except);
        renderPreview();
        renderScales();
        renderContrast();
        renderExport();
        renderLibrary();
        renderSaveState();
    }

    function renderSwatches() {
        const count = work.swatches.length;
        $('[data-swatches]').innerHTML = work.swatches.map((swatch, index) => {
            const colour = shown(swatch.hex), ink = core.textOn(colour), selected = index === work.selected;
            const tags = [selected ? 'Selected' : '', swatch.locked ? 'Locked' : ''].filter(Boolean).map(tag => `<span>${tag}</span>`).join('');
            return `<li class="swatch${selected ? ' is-selected' : ''}" data-index="${index}">
                <div class="swatch-colour" data-pick="${index}" style="background:${colour};color:${ink}">
                    <button type="button" class="swatch-number" data-pick="${index}" aria-pressed="${selected}" aria-label="Select swatch ${index + 1}">${index + 1}</button>
                    ${tags ? `<span class="swatch-tag">${tags}</span>` : ''}
                    <button type="button" class="swatch-hex" data-copy-hex="${index}" aria-label="Copy ${swatch.hex}" title="Copy">${swatch.hex}</button>
                </div>
                <div class="swatch-tools">
                    <button type="button" class="icon-button" data-lock="${index}" aria-pressed="${swatch.locked}" aria-label="${swatch.locked ? 'Unlock' : 'Lock'} swatch ${index + 1}" title="${swatch.locked ? 'Unlock' : 'Lock'}">${svg(swatch.locked ? 'lock' : 'unlock')}</button>
                    <button type="button" class="icon-button" data-move="${index}" data-by="-1" aria-label="Move swatch ${index + 1} left" title="Move left" ${index === 0 ? 'disabled' : ''}>${svg('left')}</button>
                    <button type="button" class="icon-button" data-move="${index}" data-by="1" aria-label="Move swatch ${index + 1} right" title="Move right" ${index === count - 1 ? 'disabled' : ''}>${svg('right')}</button>
                    <button type="button" class="icon-button" data-remove="${index}" aria-label="Remove swatch ${index + 1}" title="Remove" ${count === 1 ? 'disabled' : ''}>${svg('trash')}</button>
                </div>
            </li>`;
        }).join('');
        const add = $('[data-add]');
        add.disabled = count >= core.MAX_SWATCHES;
        add.innerHTML = count >= core.MAX_SWATCHES ? `${core.MAX_SWATCHES} of ${core.MAX_SWATCHES} swatches` : '<span aria-hidden="true">+</span> Add swatch';
        const note = $('[data-vision-note]');
        note.hidden = view.vision === 'none';
        $('[data-vision-text]').textContent = `Showing ${VISION_LABELS[view.vision] || ''}. Display only: copied and exported values, and the contrast table, use the original colours.`;
    }

    const sliderState = { h: 0, s: 0, l: 0 };
    function renderEditor(except) {
        const described = core.describe(selectedHex());
        $('[data-editor-index]').textContent = work.selected + 1;
        $('[data-editor-chip]').style.background = shown(described.hex);
        const values = { hex: described.hex, rgb: described.rgbText, hsl: described.hslText, oklch: described.oklchText };
        for (const [field, value] of Object.entries(values)) {
            const input = $(`[data-field="${field}"]`);
            if (field !== except) { input.value = value; input.removeAttribute('aria-invalid'); }
        }
        if (except !== 'slider') Object.assign(sliderState, { h: Math.round(described.hsl.h), s: Math.round(described.hsl.s), l: Math.round(described.hsl.l) });
        for (const key of ['h', 's', 'l']) {
            $(`[data-slider="${key}"]`).value = sliderState[key];
            $(`[data-out="${key}"]`).textContent = key === 'h' ? `${sliderState.h}°` : `${sliderState[key]}%`;
        }
        if (except == null) $('[data-field-error]').textContent = '';
    }

    function renderPreview() {
        const list = hexes();
        const lum = list.map(hex => core.relativeLuminance(hex));
        const page = lum.indexOf(Math.max(...lum));
        const others = list.map((hex, index) => index).filter(index => index !== page);
        const ink = others.length ? others.reduce((best, index) => core.contrastRatio(list[index], list[page]) > core.contrastRatio(list[best], list[page]) ? index : best, others[0]) : null;
        const pageColour = shown(list[page]), inkColour = ink == null ? core.textOn(pageColour) : shown(list[ink]);
        const sample = $('[data-sample]');
        sample.style.background = pageColour;
        sample.style.color = inkColour;
        const buttonColour = shown(selectedHex());
        for (const selector of ['[data-sample-primary]', '[data-sample-disabled]']) {
            const button = $(selector);
            button.style.background = buttonColour;
            button.style.color = core.textOn(buttonColour);
        }
        const bars = others.length ? others : [page];
        document.querySelectorAll('[data-bar]').forEach((bar, index) => { bar.style.background = shown(list[bars[index % bars.length]]); });
    }

    function renderScales() {
        $('[data-scales]').innerHTML = work.swatches.map((swatch, index) => `<div class="scale-row"><b>${index + 1}</b><div class="scale-stops">${core.scale(swatch.hex).map(stop => {
            const colour = shown(stop.hex);
            return `<button type="button" class="stop" data-copy="${stop.hex}" aria-label="Copy ${stop.stop}: ${stop.hex}" style="background:${colour};color:${core.textOn(colour)}"><small>${stop.stop}</small><span>${stop.hex}</span></button>`;
        }).join('')}</div></div>`).join('');
    }

    const result = pass => pass ? '<span class="result pass"><i aria-hidden="true">✓</i>Pass</span>' : '<span class="result fail"><i aria-hidden="true">✕</i>Fail</span>';
    // Two decimals, rounded as reference checkers print them, except that a ratio just under 3, 4.5 or 7 never
    // prints as the threshold it fails (4.499 shows 4.49, not 4.50).
    const ratioText = ratio => {
        const rounded = Math.round(ratio * 100) / 100;
        return ([3, 4.5, 7].some(limit => ratio < limit && rounded >= limit) ? Math.floor(ratio * 100) / 100 : rounded).toFixed(2);
    };
    function renderContrast() {
        const list = hexes(), pairs = core.contrastPairs(list);
        $('[data-contrast-wrap]').hidden = !pairs.length;
        $('[data-contrast-empty]').hidden = Boolean(pairs.length);
        $('[data-contrast]').innerHTML = pairs.map(pair => `<tr>
            <td class="pair">${pair.first + 1} · ${pair.second + 1}</td>
            <td><span class="aa-sample" style="color:${list[pair.first]};background:${list[pair.second]}" aria-label="${list[pair.first]} on ${list[pair.second]}">Aa</span></td>
            <td class="ratio">${ratioText(pair.ratio)}:1</td>
            <td>${result(pair.aaNormal)}</td><td>${result(pair.aaLarge)}</td><td>${result(pair.aaaNormal)}</td><td>${result(pair.aaaLarge)}</td>
        </tr>`).join('');
        $('[data-contrast-summary]').textContent = pairs.length ? `${pairs.filter(pair => pair.aaNormal).length} of ${pairs.length} pairs pass AA for normal text` : '';
    }

    function renderExport() {
        const box = $('[data-exports]');
        if (!box.childElementCount) box.innerHTML = Object.entries(core.FORMATS).map(([key, format]) => `<button type="button" data-format="${key}">${svg('copy')}Copy ${esc(format.label)}</button>`).join('');
        $('[data-png-name]').textContent = core.fileName(work.name, 'png');
    }

    let renaming = null;
    function renderLibrary() {
        const ordered = core.orderLibrary(library);
        $('[data-library-empty]').hidden = Boolean(ordered.length);
        $('[data-library]').innerHTML = ordered.map((palette, index) => {
            const group = ordered.filter(item => item.favourite === palette.favourite), place = group.indexOf(palette);
            const current = palette.id === work.savedId;
            const name = renaming === palette.id
                ? `<input class="rename-input" data-rename-input="${palette.id}" value="${esc(palette.name)}" maxlength="${core.MAX_NAME}" aria-label="New name for ${esc(palette.name)}">`
                : `<button type="button" class="palette-main" data-open="${palette.id}" aria-label="Open ${esc(palette.name)}"><span class="palette-name">${esc(palette.name)}<small>${palette.colors.length} colour${palette.colors.length === 1 ? "" : "s"}${current ? ' · open now' : ''}</small></span><span class="mini" aria-hidden="true">${palette.colors.map(hex => `<i style="background:${shown(hex)}"></i>`).join('')}</span></button>`;
            return `<li class="palette-row${current ? ' is-current' : ''}" data-row="${index}">
                <button type="button" class="star" data-favourite="${palette.id}" aria-pressed="${palette.favourite}" aria-label="Favourite ${esc(palette.name)}" title="${palette.favourite ? 'Remove from favourites' : 'Add to favourites'}">${svg('star')}</button>
                ${name}
                <div class="palette-tools">
                    <button type="button" class="icon-button" data-rename="${palette.id}" aria-label="Rename ${esc(palette.name)}" title="Rename">${svg('rename')}</button>
                    <button type="button" class="icon-button" data-duplicate="${palette.id}" aria-label="Duplicate ${esc(palette.name)}" title="Duplicate">${svg('copy')}</button>
                    <button type="button" class="icon-button" data-shift="${palette.id}" data-by="-1" aria-label="Move ${esc(palette.name)} up" title="Move up" ${place === 0 ? 'disabled' : ''}>${svg('up')}</button>
                    <button type="button" class="icon-button" data-shift="${palette.id}" data-by="1" aria-label="Move ${esc(palette.name)} down" title="Move down" ${place === group.length - 1 ? 'disabled' : ''}>${svg('down')}</button>
                    <button type="button" class="icon-button" data-delete="${palette.id}" aria-label="Delete ${esc(palette.name)}" title="Delete">${svg('trash')}</button>
                </div>
            </li>`;
        }).join('');
        if (renaming) { const input = $(`[data-rename-input="${renaming}"]`); if (input && document.activeElement !== input) { input.focus(); input.select(); } }
    }

    function renderSaveState() {
        const name = $('[data-name]');
        if (document.activeElement !== name) name.value = work.name;
        const saved = library.find(item => item.id === work.savedId);
        const same = saved && saved.name === core.cleanName(work.name, 'Untitled palette') && saved.colors.join() === hexes().join();
        $('[data-save-state]').textContent = !saved ? 'Not in library' : same ? '✓ Saved' : 'Unsaved changes';
    }

    // ---- PNG ----------------------------------------------------------------------------------------------------

    let watermark;
    /** The replaceable watermark, or null when none is installed. Looked up once per session. */
    function loadWatermark() {
        watermark ??= new Promise(resolve => {
            const image = new Image();
            image.onload = () => resolve(image.naturalWidth || image.width ? image : null);
            image.onerror = () => resolve(null);
            image.src = WATERMARK;
        });
        return watermark;
    }
    async function downloadPng() {
        const button = $('[data-png]');
        button.disabled = true;
        try {
            const canvas = document.createElement('canvas');
            canvas.width = PNG.width; canvas.height = PNG.height;
            const context = canvas.getContext('2d');
            const list = hexes(), width = PNG.width / list.length, font = '"SF Mono", ui-monospace, Menlo, monospace';
            list.forEach((hex, index) => {
                const x = Math.round(index * width), next = Math.round((index + 1) * width);
                context.fillStyle = hex;
                context.fillRect(x, 0, next - x, PNG.height);
                context.fillStyle = core.textOn(hex);
                context.font = `700 ${list.length > 6 ? 28 : 34}px ${font}`;
                context.textBaseline = 'alphabetic';
                context.fillText(hex, x + 32, PNG.height - 48);
            });
            context.fillStyle = core.textOn(list[0]);
            context.font = `600 30px -apple-system, "SF Pro Display", "Segoe UI", sans-serif`;
            context.textBaseline = 'top';
            context.fillText(core.cleanName(work.name, 'Palette'), 32, 36, PNG.width / 2);
            const mark = await loadWatermark();
            if (mark) {
                const natural = { w: mark.naturalWidth || mark.width, h: mark.naturalHeight || mark.height };
                const scale = Math.min(220 / natural.w, 90 / natural.h);
                const w = natural.w * scale, h = natural.h * scale;
                context.drawImage(mark, PNG.width - w - 32, 32, w, h);
            }
            const blob = await new Promise(resolve => canvas.toBlob(resolve, 'image/png'));
            if (!blob) throw new Error('blob');
            download(blob, core.fileName(work.name, 'png'));
            toast(`Downloaded ${core.fileName(work.name, 'png')}${mark ? '' : ' (no watermark installed)'}`);
        } catch {
            toast('The PNG could not be made. Try again.', { error: true });
        } finally { button.disabled = false; }
    }
    function download(blob, name) {
        const url = URL.createObjectURL(blob), link = document.createElement('a');
        link.href = url; link.download = name;
        link.click(); // device-save.js takes this over in the installed iPhone app
        setTimeout(() => URL.revokeObjectURL(url), 60000);
    }

    // ---- Library actions ----------------------------------------------------------------------------------------

    function moveInLibrary(id, by) {
        const ordered = core.orderLibrary(library), index = ordered.findIndex(item => item.id === id), target = index + by;
        if (index < 0 || !ordered[target] || ordered[target].favourite !== ordered[index].favourite) return;
        [ordered[index], ordered[target]] = [ordered[target], ordered[index]];
        saveLibrary(ordered);
        render({ editor: false });
        $(`[data-shift="${id}"][data-by="${by}"]`)?.focus();
    }
    function finishRename(id, value, keep) {
        renaming = null;
        if (keep) {
            const name = core.cleanName(value, '');
            if (name) {
                const wasCurrentName = work.savedId === id && work.name === library.find(item => item.id === id)?.name;
                saveLibrary(library.map(item => item.id === id ? { ...item, name } : item));
                if (wasCurrentName) { work.name = name; saveWork(); }
            }
        }
        render({ editor: false });
        $(`[data-rename="${id}"]`)?.focus();
    }
    async function importFile(file) {
        if (!file) return;
        if (file.size > 2 * 1024 * 1024) { toast('That file is too large to be a palette library.', { error: true }); return; }
        try {
            const { palettes, skipped } = core.importLibrary(await file.text(), { room: core.MAX_LIBRARY - library.length });
            if (palettes.length && !saveLibrary([...library, ...palettes.map(palette => ({ id: newId(), ...palette }))])) return;
            render({ editor: false });
            const added = `Imported ${palettes.length} palette${palettes.length === 1 ? '' : 's'}`;
            toast(skipped.length ? `${added}; skipped ${skipped.length}. ${skipped[0].reason}` : added, { error: !palettes.length });
        } catch (error) { toast(error.message, { error: true }); }
    }

    // ---- Events -------------------------------------------------------------------------------------------------

    document.addEventListener('click', event => {
        const target = event.target.closest('button, [data-pick]');
        if (!target || target.disabled) return;
        const data = target.dataset;
        if (data.copyHex != null) { copy(work.swatches[Number(data.copyHex)].hex, work.swatches[Number(data.copyHex)].hex); return; }
        if (data.pick != null) { select(Number(data.pick)); return; }
        if (data.lock != null) { const swatch = work.swatches[Number(data.lock)]; swatch.locked = !swatch.locked; commit({ editor: false }); $(`[data-lock="${data.lock}"]`)?.focus(); return; }
        if (data.move != null) {
            const from = Number(data.move), to = from + Number(data.by);
            [work.swatches[from], work.swatches[to]] = [work.swatches[to], work.swatches[from]];
            if (work.selected === from) work.selected = to; else if (work.selected === to) work.selected = from;
            commit();
            $(`[data-move="${to}"][data-by="${data.by}"]`)?.focus();
            return;
        }
        if (data.remove != null) {
            const index = Number(data.remove), before = structuredClone(work);
            work.swatches.splice(index, 1);
            work.selected = Math.min(work.selected > index ? work.selected - 1 : work.selected, work.swatches.length - 1);
            commit();
            toast(`Removed swatch ${index + 1}`, { action: 'Undo', onAction: () => { work = before; commit(); } });
            return;
        }
        if (data.copy) { copy(data.copy, data.copy); return; }
        if (data.format) { copy(core.exportAs(data.format, work.name, hexes()), core.FORMATS[data.format].label); return; }
        if (data.open) { openPalette(data.open); return; }
        if (data.favourite) { saveLibrary(library.map(item => item.id === data.favourite ? { ...item, favourite: !item.favourite } : item)); render({ editor: false }); $(`[data-favourite="${data.favourite}"]`)?.focus(); return; }
        if (data.rename) { renaming = data.rename; renderLibrary(); return; }
        if (data.duplicate) {
            if (library.length >= core.MAX_LIBRARY) { toast(`The library holds ${core.MAX_LIBRARY} palettes. Delete one first.`, { error: true }); return; }
            const index = library.findIndex(item => item.id === data.duplicate), original = library[index];
            const copyOf = { ...original, id: newId(), name: core.cleanName(`${original.name.slice(0, core.MAX_NAME - 5)} copy`, 'Palette copy') };
            if (saveLibrary([...library.slice(0, index + 1), copyOf, ...library.slice(index + 1)])) { render({ editor: false }); toast(`Duplicated as “${copyOf.name}”`); }
            return;
        }
        if (data.shift) { moveInLibrary(data.shift, Number(data.by)); return; }
        if (data.delete) {
            const index = library.findIndex(item => item.id === data.delete), removed = library[index];
            saveLibrary(library.filter(item => item.id !== data.delete));
            render({ editor: false });
            toast(`Deleted “${removed.name}”`, { action: 'Undo', onAction: () => { saveLibrary([...library.slice(0, index), removed, ...library.slice(index)]); render({ editor: false }); } });
            return;
        }
        if (target.matches('[data-add]')) {
            const base = core.rgbToOklch(core.parseHex(selectedHex()));
            const hex = core.toHex(core.oklchToRgb({ L: base.L > 0.6 ? base.L - 0.15 : base.L + 0.15, C: base.C, h: base.h + 40 }));
            work.swatches.splice(work.selected + 1, 0, { hex, locked: false });
            work.selected += 1;
            commit();
            return;
        }
        if (target.matches('[data-generate]')) runGenerate();
        else if (target.matches('[data-surprise]')) runSurprise();
        else if (target.matches('[data-nudge]')) runNudge();
        else if (target.matches('[data-save]')) savePalette();
        else if (target.matches('[data-png]')) downloadPng();
        else if (target.matches('[data-vision-off]')) { view.vision = 'none'; $('[data-vision]').value = 'none'; saveView(); render({ editor: false }); $('[data-vision]').focus(); }
        else if (target.matches('[data-export-library]')) {
            download(new Blob([core.exportLibrary(core.orderLibrary(library))], { type: 'application/json' }), 'Palette library.json');
            toast(`Exported ${library.length} palette${library.length === 1 ? '' : 's'}`);
        }
    });

    const parsers = {
        hex: text => core.parseHex(text),
        rgb: text => core.parseRgb(text),
        hsl: text => { const hsl = core.parseHsl(text); return hsl && core.hslToRgb(hsl); },
        oklch: text => { const oklch = core.parseOklch(text); return oklch && core.oklchToRgb(oklch); },
    };
    document.addEventListener('input', event => {
        const target = event.target;
        if (target.matches('[data-field]')) {
            const field = target.dataset.field, rgb = parsers[field](target.value);
            if (!rgb) {
                target.setAttribute('aria-invalid', 'true');
                $('[data-field-error]').textContent = `Keeping ${selectedHex()} until the ${field.toUpperCase()} value is complete.`;
                return;
            }
            target.removeAttribute('aria-invalid');
            $('[data-field-error]').textContent = '';
            setSelectedHex(core.toHex(rgb), field);
        } else if (target.matches('[data-slider]')) {
            sliderState[target.dataset.slider] = Number(target.value);
            setSelectedHex(core.toHex(core.hslToRgb(sliderState)), 'slider');
        } else if (target.matches('[data-name]')) {
            work.name = target.value;
            saveWork();
            renderExport();
            renderSaveState();
        }
    });
    document.addEventListener('change', event => {
        const target = event.target;
        if (target.matches('[data-field]')) { renderEditor(null); }
        else if (target.matches('[data-name]')) { work.name = core.cleanName(target.value, 'Untitled palette'); commit({ editor: false }); }
        else if (target.matches('[data-harmony]')) { view.harmony = target.value; saveView(); }
        else if (target.matches('[data-vision]')) { view.vision = target.value; saveView(); render({ editor: false }); $('[data-editor-chip]').style.background = shown(selectedHex()); }
        else if (target.matches('[data-import]')) { importFile(target.files[0]); target.value = ''; }
    });
    document.addEventListener('focusout', event => {
        if (event.target.matches('[data-rename-input]') && renaming === event.target.dataset.renameInput) finishRename(renaming, event.target.value, true);
    });

    // Escape in a rename field cancels the rename. Home's frame bridge listens for Escape on the document to close
    // the tool, so this runs on the window in the capture phase and stops the event before it gets there.
    window.addEventListener('keydown', event => {
        if (event.key !== 'Escape' || !event.target.matches?.('[data-rename-input]')) return;
        event.preventDefault();
        event.stopPropagation();
        finishRename(event.target.dataset.renameInput, '', false);
    }, true);

    // Shortcuts work only while this frame has focus and never while the person types.
    const TYPING_INPUTS = new Set(['text', 'search', 'email', 'number', 'password', 'tel', 'url', '']);
    const isTyping = element => element?.isContentEditable || ['TEXTAREA', 'SELECT'].includes(element?.tagName)
        || (element?.tagName === 'INPUT' && TYPING_INPUTS.has((element.getAttribute('type') || '').toLowerCase()));
    document.addEventListener('keydown', event => {
        const target = event.target;
        if (target.matches?.('[data-rename-input]')) {
            if (event.key === 'Enter') { event.preventDefault(); finishRename(target.dataset.renameInput, target.value, true); }
            return;
        }
        if (event.defaultPrevented || event.metaKey || event.ctrlKey || event.altKey || isTyping(target)) return;
        const key = event.key.toLowerCase();
        if (event.key === ' ') {
            // Space still presses a focused button or checkbox; anywhere else it generates.
            if (target.closest?.('button, a[href], summary, input')) return;
            event.preventDefault();
            runSurprise();
        } else if (/^[1-8]$/.test(event.key)) {
            const index = Number(event.key) - 1;
            if (index < work.swatches.length) { event.preventDefault(); select(index); }
        } else if (key === 'c') { event.preventDefault(); copy(selectedHex(), selectedHex()); }
        else if (key === 's') { event.preventDefault(); savePalette(); }
    });

    $('[data-harmony]').value = view.harmony;
    $('[data-vision]').value = view.vision;
    saveWork();
    render();
})();
