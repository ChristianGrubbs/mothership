(() => {
    'use strict';

    const core = window.PaletteCore;
    // The page that frames the tool (home-frame.js). Opened from disk there is none, and the tool stands alone.
    const frame = window.MothershipFrame || null;
    const $ = selector => document.querySelector(selector);
    const esc = value => String(value ?? '').replace(/[&<>"']/g, char => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[char]));
    const plural = (count, word) => `${count} ${word}${count === 1 ? '' : 's'}`;
    // Inside a frame localStorage is the account-backed store (home-frame.js); one value may hold 64 KB.
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
    const PANES = ['swatches', 'scales', 'contrast'];
    // What Export offers: the text formats the core writes, and the picture.
    const EXPORTS = [...Object.entries(core.FORMATS).map(([key, format]) => ({ key, label: format.label, image: false })), { key: 'png', label: 'PNG image', image: true }];
    const ICONS = {
        lock: 'M6 11h12v10H6z M8 11V7a4 4 0 0 1 8 0v4',
        unlock: 'M6 11h12v10H6z M8 11V7a4 4 0 0 1 7.5-2',
        up: 'M6 15l6-6 6 6',
        down: 'M6 9l6 6 6-6',
        trash: 'M4 7h16 M9 7V4h6v3 M6 7l1 13h10l1-13',
        rename: 'M4 20h4L19 9l-4-4L4 16z M13 7l4 4',
        copy: 'M8 8h12v12H8z M4 16V4h12',
        star: 'M12 3l2.8 5.7 6.2.9-4.5 4.4 1.1 6.2-5.6-2.9-5.6 2.9 1.1-6.2-4.5-4.4 6.2-.9z',
    };
    const svg = name => `<svg class="tk-ic" viewBox="0 0 24 24" aria-hidden="true"><path d="${ICONS[name]}"/></svg>`;
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
        // Which part shows where there is room for one, what Export last made, and the Library folder last saved into.
        pane: PANES.includes(storedView.pane) ? storedView.pane : 'swatches',
        format: EXPORTS.some(item => item.key === storedView.format) ? storedView.format : EXPORTS[0].key,
        folder: typeof storedView.folder === 'string' ? storedView.folder : null,
    };

    const saveWork = () => write(KEYS.working, work);
    const saveView = () => write(KEYS.view, view);
    /** Stores the saved palettes if they fit; returns false (and changes nothing) when they would not. */
    function saveLibrary(next) {
        if (JSON.stringify(next).length > STORE_LIMIT) { say('Your saved palettes are full. Delete or export some first.', { error: true }); return false; }
        library = next;
        write(KEYS.library, library);
        return true;
    }
    if (read(KEYS.library) === undefined) write(KEYS.library, library);

    const hexes = () => work.swatches.map(swatch => swatch.hex);
    const selectedHex = () => work.swatches[work.selected].hex;
    const shown = hex => view.vision === 'none' ? hex : core.simulate(hex, view.vision);

    // ---- Feedback -----------------------------------------------------------------------------------------------

    const openSheet = () => document.querySelector('dialog[open]');
    let toastTimer;
    /** Says what just happened: in the open sheet's status line, or in the toast when no sheet is open. */
    function say(message, { action, onAction, error = false } = {}) {
        const sheet = openSheet();
        const box = sheet ? sheet.querySelector('.tk-status') : $('[data-toast]');
        const text = document.createElement('span');
        text.textContent = message;
        box.replaceChildren(text);
        if (action) {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'tk-link';
            button.textContent = action;
            button.addEventListener('click', () => { if (sheet) box.replaceChildren(); else box.hidden = true; onAction(); });
            box.append(' ', button);
        }
        box.classList.toggle('is-error', error);
        if (sheet) return;
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
            (openSheet() || document.body).append(area); area.select();
            try { copied = document.execCommand('copy'); } catch { copied = false; }
            area.remove();
        }
        say(copied ? `Copied ${label}` : `Couldn’t copy. Select and copy ${label} by hand.`, { error: !copied });
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
        say(message, { action: 'Undo', onAction: () => { work = before; commit(); } });
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
            if (library.length >= core.MAX_LIBRARY) { say(`You can keep ${core.MAX_LIBRARY} saved palettes. Delete one first.`, { error: true }); return; }
            const id = newId();
            next = [...library, { id, name, colors: hexes(), favourite: false }];
            work.savedId = id;
        }
        if (!saveLibrary(next)) return;
        work.name = name;
        commit();
        say(`Saved “${name}”`);
    }
    function openPalette(id) {
        const palette = library.find(item => item.id === id);
        if (!palette) return;
        const before = structuredClone(work);
        work = { name: palette.name, swatches: palette.colors.map(hex => ({ hex, locked: false })), selected: 0, savedId: palette.id };
        openSheet()?.close();
        commit();
        say(`Opened “${palette.name}”`, { action: 'Undo', onAction: () => { work = before; commit(); } });
    }

    // ---- Rendering ----------------------------------------------------------------------------------------------

    function render({ editor = true, except = null } = {}) {
        renderSwatches();
        if (editor) renderEditor(except);
        renderSample();
        renderScales();
        renderContrast();
        renderExport();
        renderSaved();
        renderSaveState();
        tellBar();
    }

    const rowHtml = (swatch, index, selected) => {
        const colour = shown(swatch.hex), n = index + 1, lock = swatch.locked ? 'Unlock' : 'Lock';
        const tags = [selected ? 'Selected' : '', swatch.locked ? 'Locked' : ''].filter(Boolean).join(' · ');
        return `<button type="button" class="ps-chip" data-pick="${index}" aria-pressed="${selected}" aria-label="Select swatch ${n}" style="background:${colour};color:${core.textOn(colour)}">${n}</button>
            <button type="button" class="ps-hex tk-mono" data-copy-hex="${index}" aria-label="Copy ${swatch.hex}" title="Copy">${swatch.hex}</button>
            <span class="ps-row-end">${tags ? `<span class="ps-tags">${tags}</span>` : ''}<button type="button" class="tk-btn is-quiet" data-lock="${index}" aria-pressed="${swatch.locked}" aria-label="${lock} swatch ${n}" title="${lock}">${svg(swatch.locked ? 'lock' : 'unlock')}</button></span>`;
    };
    function renderSwatches() {
        const count = work.swatches.length, list = $('[data-swatches]'), editor = $('[data-editor]');
        // One item per swatch, kept from render to render. The editor sits inside the selected one and is moved only
        // when the selection changes, so a field that is being typed in is never rebuilt under the person's hands.
        while (list.children.length < count) {
            const item = document.createElement('li');
            item.className = 'ps-swatch';
            item.innerHTML = '<div class="ps-swatch-row"></div>';
            list.append(item);
        }
        const home = list.children[work.selected];
        if (editor.parentElement !== home) home.append(editor);
        while (list.children.length > count) list.lastElementChild.remove();
        [...list.children].forEach((item, index) => {
            const selected = index === work.selected, row = item.firstElementChild;
            item.classList.toggle('is-selected', selected);
            row.dataset.pick = index;
            row.innerHTML = rowHtml(work.swatches[index], index, selected);
        });
        $('[data-count]').textContent = `${count} of ${core.MAX_SWATCHES}`;
        const add = $('[data-add]');
        add.disabled = count >= core.MAX_SWATCHES;
        add.textContent = count >= core.MAX_SWATCHES ? `${core.MAX_SWATCHES} of ${core.MAX_SWATCHES} swatches` : 'Add swatch';
        $('[data-vision-note]').hidden = view.vision === 'none';
        $('[data-vision-text]').textContent = `Showing ${VISION_LABELS[view.vision] || ''}. Display only: copied and exported values, and the contrast figures, use the original colours. `;
    }

    const sliderState = { h: 0, s: 0, l: 0 };
    function renderEditor(except) {
        const described = core.describe(selectedHex()), count = work.swatches.length;
        $('[data-editor-index]').textContent = work.selected + 1;
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
        $('[data-move][data-by="-1"]').disabled = work.selected === 0;
        $('[data-move][data-by="1"]').disabled = work.selected === count - 1;
        $('[data-remove]').disabled = count === 1;
        if (except == null) $('[data-field-error]').textContent = '';
    }

    function renderSample() {
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
        $('[data-scales]').innerHTML = work.swatches.map((swatch, index) => `<div class="ps-scale"><b>${index + 1}</b><div class="ps-stops">${core.scale(swatch.hex).map(stop => {
            const colour = shown(stop.hex);
            return `<button type="button" class="ps-stop" data-copy="${stop.hex}" aria-label="Copy ${stop.stop}: ${stop.hex}" title="${stop.stop} · ${stop.hex}" style="background:${colour};color:${core.textOn(colour)}"><small>${stop.stop}</small><span>${stop.hex}</span></button>`;
        }).join('')}</div></div>`).join('');
    }

    // Two decimals, rounded as reference checkers print them, except that a ratio just under 3, 4.5 or 7 never
    // prints as the threshold it fails (4.499 shows 4.49, not 4.50).
    const ratioText = ratio => {
        const rounded = Math.round(ratio * 100) / 100;
        return ([3, 4.5, 7].some(limit => ratio < limit && rounded >= limit) ? Math.floor(ratio * 100) / 100 : rounded).toFixed(2);
    };
    // Where a pair stands, in one word with its sign. The four checks behind it open under the pair.
    const verdict = pair => pair.aaaNormal ? { word: 'AAA', sign: '✓', tone: 'is-pass' }
        : pair.aaNormal ? { word: 'AA', sign: '✓', tone: 'is-pass' }
            : pair.aaLarge ? { word: 'Large only', sign: '△', tone: '' }
                : { word: 'Fails', sign: '✕', tone: 'is-fail' };
    const check = (label, pass) => `<span>${label}<b class="${pass ? '' : 'is-fail'}">${pass ? '✓ Pass' : '✕ Fail'}</b></span>`;
    // Pairs the person opened stay open while the colours change under them.
    const openPairs = new Set();
    function renderContrast() {
        const list = hexes(), pairs = core.contrastPairs(list), box = $('[data-contrast]');
        box.hidden = !pairs.length;
        $('[data-contrast-empty]').hidden = Boolean(pairs.length);
        box.innerHTML = pairs.map(pair => {
            const key = `${pair.first}-${pair.second}`, said = verdict(pair);
            return `<li><details class="ps-pair" data-pair="${key}"${openPairs.has(key) ? ' open' : ''}>
                <summary>
                    <span class="ps-aa" style="color:${list[pair.first]};background:${list[pair.second]}" aria-hidden="true">Aa</span>
                    <span class="ps-ratio">${ratioText(pair.ratio)}:1</span>
                    <span class="ps-verdict ${said.tone}"><span aria-hidden="true">${said.sign} </span>${said.word}</span>
                    <span class="ps-pair-name">Swatch ${pair.first + 1} on ${pair.second + 1}</span>
                </summary>
                <div class="ps-checks">${check('AA, normal text', pair.aaNormal)}${check('AA, large text', pair.aaLarge)}${check('AAA, normal text', pair.aaaNormal)}${check('AAA, large text', pair.aaaLarge)}</div>
            </details></li>`;
        }).join('');
        $('[data-contrast-summary]').textContent = pairs.length ? `${pairs.filter(pair => pair.aaNormal).length} of ${pairs.length} pass AA` : '';
    }

    function renderExport() {
        const box = $('[data-export-choices]');
        if (!box.querySelector('input')) box.insertAdjacentHTML('beforeend', EXPORTS.map(item => `<label class="tk-check"><input type="radio" name="export-as" value="${item.key}"><span>${esc(item.label)}</span></label>`).join(''));
        const chosen = EXPORTS.find(item => item.key === view.format);
        box.querySelectorAll('input').forEach(input => { input.checked = input.value === chosen.key; });
        $('[data-export-preview]').hidden = chosen.image;
        $('[data-export-image]').hidden = !chosen.image;
        if (chosen.image) {
            $('[data-export-strip]').innerHTML = hexes().map(hex => `<i style="background:${hex}"></i>`).join('');
            $('[data-png-name]').textContent = `${core.fileName(work.name, 'png')} · ${PNG.width} × ${PNG.height}`;
        } else $('[data-export-preview]').textContent = core.exportAs(chosen.key, work.name, hexes());
        $('[data-export-go]').textContent = chosen.image ? 'Download PNG' : 'Copy';
    }

    let renaming = null;
    function renderSaved() {
        const ordered = core.orderLibrary(library);
        $('[data-saved-count]').textContent = `${library.length} of ${core.MAX_LIBRARY}`;
        $('[data-library-empty]').hidden = Boolean(ordered.length);
        $('[data-library]').hidden = !ordered.length;
        $('[data-export-library]').disabled = !ordered.length;
        $('[data-library]').innerHTML = ordered.map(palette => {
            const group = ordered.filter(item => item.favourite === palette.favourite), place = group.indexOf(palette);
            const current = palette.id === work.savedId, label = esc(palette.name);
            const name = renaming === palette.id
                ? `<input class="tk-input ps-rename" data-rename-input="${palette.id}" value="${label}" maxlength="${core.MAX_NAME}" aria-label="New name for ${label}">`
                : `<button type="button" class="ps-saved-open" data-open="${palette.id}" aria-label="Open ${label}"><span class="ps-saved-name"><span>${label}</span><small>${plural(palette.colors.length, 'colour')}${current ? ' · open now' : ''}</small></span><span class="ps-mini" aria-hidden="true">${palette.colors.map(hex => `<i style="background:${shown(hex)}"></i>`).join('')}</span></button>`;
            const tool = (attributes, title, icon, disabled = false) => `<button type="button" class="tk-btn is-quiet" ${attributes} aria-label="${title} ${label}" title="${title}" ${disabled ? 'disabled' : ''}>${svg(icon)}</button>`;
            return `<li class="ps-saved-row${current ? ' is-current' : ''}">
                <button type="button" class="tk-btn is-quiet" data-favourite="${palette.id}" aria-pressed="${palette.favourite}" aria-label="Favourite ${label}" title="${palette.favourite ? 'Remove from favourites' : 'Add to favourites'}">${svg('star')}</button>
                ${name}
                <div class="ps-saved-tools">
                    ${tool(`data-rename="${palette.id}"`, 'Rename', 'rename')}
                    ${tool(`data-duplicate="${palette.id}"`, 'Duplicate', 'copy')}
                    ${tool(`data-shift="${palette.id}" data-by="-1"`, 'Move up', 'up', place === 0)}
                    ${tool(`data-shift="${palette.id}" data-by="1"`, 'Move down', 'down', place === group.length - 1)}
                    ${tool(`data-delete="${palette.id}"`, 'Delete', 'trash')}
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
        $('[data-save-state]').textContent = !saved ? 'Not saved yet' : same ? '✓ Saved' : 'Changes not saved';
    }

    // ---- The bar, and the tool's sheets ---------------------------------------------------------------------------

    // The page that frames the tool draws its bar: the palette's name with the saved palettes under it, Export and
    // Save to Library. It is told what to show whenever that changes, and it tells the tool what was pressed.
    let told = '';
    function tellBar() {
        if (!frame) return;
        const bar = {
            set: core.cleanName(work.name, 'Untitled palette'),
            sets: core.orderLibrary(library).map(item => ({ id: item.id, name: item.name, current: item.id === work.savedId })),
            setsLabel: 'Saved palettes', manage: 'Manage saved palettes…', export: true, library: true,
        };
        const next = JSON.stringify(bar);
        if (next === told) return;
        told = next;
        frame.chrome(bar);
    }

    function showSheet(name) {
        const sheet = $(`[data-sheet="${name}"]`);
        if (sheet.open) return;
        openSheet()?.close();
        const status = sheet.querySelector('.tk-status');
        status.replaceChildren();
        status.classList.remove('is-error');
        sheet.showModal();
        // The first control takes the focus, never a scrolling body. A text field is passed over: on a phone it would
        // raise the keyboard over a sheet that was only opened.
        const first = sheet.querySelector('.tk-sheet-body :is(button, select, input:not([type="text"], [type="file"])):not(:disabled)')
            ?? sheet.querySelector('.tk-sheet-foot button:not(:disabled)');
        first?.focus();
    }

    function showPane(name) {
        view.pane = name;
        $('.ps').dataset.view = name;
        document.querySelectorAll('[data-view-opt]').forEach(button => button.setAttribute('aria-pressed', String(button.dataset.viewOpt === name)));
    }

    // Save to Library: the picture, the colour tokens or both, into a folder the account may upload to.
    let folders = null;
    // A save holds the sheet until the Library answers: its choices stay as sent, also when the sheet is reopened.
    let saving = false;
    function setLibrary({ message = '', error = false } = {}) {
        const none = Array.isArray(folders) && !folders.length;
        const picked = $('[data-save-image]').checked || $('[data-save-tokens]').checked;
        $('[data-folder-field]').hidden = none;
        $('[data-folder-empty]').hidden = !none;
        ['[data-save-image]', '[data-save-tokens]', '[data-folder]'].forEach(selector => { $(selector).disabled = saving; });
        const go = $('[data-library-go]');
        go.disabled = saving || none || !folders || !picked;
        go.textContent = saving ? 'Saving…' : 'Save';
        const status = $('[data-sheet="library"] .tk-status');
        status.textContent = message || (saving ? 'Saving to the Library…' : picked || none ? '' : 'Choose at least one file to save.');
        status.classList.toggle('is-error', error);
    }
    async function openLibrary() {
        if (!frame?.library) return;
        $('[data-save-image-name]').textContent = `${core.fileName(work.name, 'png')} · ${PNG.width} × ${PNG.height}`;
        $('[data-save-tokens-name]').textContent = core.fileName(work.name, 'json');
        showSheet('library');
        if (folders) { setLibrary(); return; }
        setLibrary({ message: 'Finding your folders…' });
        try {
            folders = await frame.folders();
            const select = $('[data-folder]');
            select.innerHTML = folders.map(folder => `<option value="${esc(folder.id)}">${'  '.repeat(folder.depth)}${esc(folder.name)}</option>`).join('');
            if (folders.some(folder => folder.id === view.folder)) select.value = view.folder;
            setLibrary();
        } catch (error) {
            folders = null;
            setLibrary({ message: `${error.message || 'Your folders could not be read.'} Close this and try again.`, error: true });
        }
    }
    async function saveToLibrary() {
        if (saving) return;
        const folder = $('[data-folder]').value, files = [];
        saving = true;
        setLibrary();
        let failed = null;
        try {
            if ($('[data-save-image]').checked) files.push({ name: core.fileName(work.name, 'png'), blob: (await makePng()).blob });
            if ($('[data-save-tokens]').checked) files.push({ name: core.fileName(work.name, 'json'), blob: new Blob([core.exportAs('json', work.name, hexes())], { type: 'application/json' }) });
            const saved = await frame.save({ files, folder });
            view.folder = folder;
            saveView();
            const text = `Saved ${plural(saved.count, 'file')} to ${folders.find(item => item.id === folder)?.name || 'the Library'}.`;
            $('[data-sheet="library"]').close();
            frame.notice({ text, ...(saved.url ? { link: { label: 'Open in Library', url: saved.url } } : {}) });
            // Where the page draws a bar, its status line says it once, with the link. Elsewhere the tool says it.
            if (!frame.bar) say(text);
        } catch (error) {
            failed = error;
        } finally {
            saving = false;
        }
        if (failed) setLibrary({ message: failed.message || 'The files could not be saved. Try again.', error: true });
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
    /** The palette as a picture: one band per swatch with its value, and the palette's name. */
    async function makePng() {
        const canvas = document.createElement('canvas');
        canvas.width = PNG.width; canvas.height = PNG.height;
        const context = canvas.getContext('2d');
        const list = hexes(), width = PNG.width / list.length;
        list.forEach((hex, index) => {
            const x = Math.round(index * width), next = Math.round((index + 1) * width);
            context.fillStyle = hex;
            context.fillRect(x, 0, next - x, PNG.height);
            context.fillStyle = core.textOn(hex);
            context.font = `600 ${list.length > 6 ? 26 : 32}px "Martian Mono", ui-monospace, Menlo, monospace`;
            context.textBaseline = 'alphabetic';
            context.fillText(hex, x + 32, PNG.height - 48);
        });
        context.fillStyle = core.textOn(list[0]);
        context.font = '600 30px "Instrument Sans", -apple-system, "Segoe UI", sans-serif';
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
        if (!blob) throw new Error('The picture could not be made. Try again.');
        return { blob, mark };
    }
    async function downloadPng() {
        const button = $('[data-export-go]');
        button.disabled = true;
        try {
            const { blob } = await makePng();
            download(blob, core.fileName(work.name, 'png'));
            say(`Downloaded ${core.fileName(work.name, 'png')}`);
        } catch (error) {
            say(error.message, { error: true });
        } finally { button.disabled = false; }
    }
    function download(blob, name) {
        const url = URL.createObjectURL(blob), link = document.createElement('a');
        link.href = url; link.download = name;
        link.click(); // device-save.js takes this over in the installed iPhone app
        setTimeout(() => URL.revokeObjectURL(url), 60000);
    }

    // ---- Saved palettes -----------------------------------------------------------------------------------------

    function moveInLibrary(id, by) {
        const ordered = core.orderLibrary(library), index = ordered.findIndex(item => item.id === id), target = index + by;
        if (index < 0 || !ordered[target] || ordered[target].favourite !== ordered[index].favourite) return;
        [ordered[index], ordered[target]] = [ordered[target], ordered[index]];
        saveLibrary(ordered);
        render({ editor: false });
        const same = $(`[data-shift="${id}"][data-by="${by}"]`);
        (same.disabled ? $(`[data-shift="${id}"][data-by="${-by}"]`) : same)?.focus();
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
        if (file.size > 2 * 1024 * 1024) { say('That file is too large to be a set of palettes.', { error: true }); return; }
        try {
            const { palettes, skipped } = core.importLibrary(await file.text(), { room: core.MAX_LIBRARY - library.length });
            if (palettes.length && !saveLibrary([...library, ...palettes.map(palette => ({ id: newId(), ...palette }))])) return;
            render({ editor: false });
            const added = `Imported ${plural(palettes.length, 'palette')}`;
            say(skipped.length ? `${added}; skipped ${skipped.length}. ${skipped[0].reason}` : added, { error: !palettes.length });
        } catch (error) { say(error.message, { error: true }); }
    }

    // ---- Events -------------------------------------------------------------------------------------------------

    document.addEventListener('click', event => {
        // A press on the shade around a sheet closes it, as Cancel does.
        if (event.target instanceof HTMLDialogElement) { event.target.close(); return; }
        const target = event.target.closest('button, [data-pick]');
        if (!target || target.disabled) return;
        const data = target.dataset;
        if (data.copyHex != null) { copy(work.swatches[Number(data.copyHex)].hex, work.swatches[Number(data.copyHex)].hex); return; }
        if (data.lock != null) { const swatch = work.swatches[Number(data.lock)]; swatch.locked = !swatch.locked; commit({ editor: false }); $(`[data-lock="${data.lock}"]`)?.focus(); return; }
        if (data.pick != null) { select(Number(data.pick)); if (target.matches('button')) $(`.ps-chip[data-pick="${data.pick}"]`)?.focus(); return; }
        if (data.move != null) {
            const from = work.selected, by = Number(data.by), to = from + by;
            if (!work.swatches[to]) return;
            [work.swatches[from], work.swatches[to]] = [work.swatches[to], work.swatches[from]];
            work.selected = to;
            commit();
            const same = $(`[data-move][data-by="${by}"]`);
            (same.disabled ? $(`[data-move][data-by="${-by}"]`) : same).focus();
            return;
        }
        if (data.remove != null) {
            const index = work.selected, before = structuredClone(work);
            work.swatches.splice(index, 1);
            work.selected = Math.min(index, work.swatches.length - 1);
            commit();
            $(`.ps-chip[data-pick="${work.selected}"]`)?.focus();
            say(`Removed swatch ${index + 1}`, { action: 'Undo', onAction: () => { work = before; commit(); } });
            return;
        }
        if (data.copy) { copy(data.copy, data.copy); return; }
        if (data.viewOpt) { showPane(data.viewOpt); saveView(); return; }
        if (data.open) { openPalette(data.open); return; }
        if (data.favourite) { saveLibrary(library.map(item => item.id === data.favourite ? { ...item, favourite: !item.favourite } : item)); render({ editor: false }); $(`[data-favourite="${data.favourite}"]`)?.focus(); return; }
        if (data.rename) { renaming = data.rename; renderSaved(); return; }
        if (data.duplicate) {
            if (library.length >= core.MAX_LIBRARY) { say(`You can keep ${core.MAX_LIBRARY} saved palettes. Delete one first.`, { error: true }); return; }
            const index = library.findIndex(item => item.id === data.duplicate), original = library[index];
            const copyOf = { ...original, id: newId(), name: core.cleanName(`${original.name.slice(0, core.MAX_NAME - 5)} copy`, 'Palette copy') };
            if (saveLibrary([...library.slice(0, index + 1), copyOf, ...library.slice(index + 1)])) { render({ editor: false }); $(`[data-duplicate="${copyOf.id}"]`)?.focus(); say(`Duplicated as “${copyOf.name}”`); }
            return;
        }
        if (data.shift) { moveInLibrary(data.shift, Number(data.by)); return; }
        if (data.delete) {
            const index = library.findIndex(item => item.id === data.delete), removed = library[index];
            saveLibrary(library.filter(item => item.id !== data.delete));
            render({ editor: false });
            ($('[data-library] [data-open]') || $('[data-sheet="saved"] [data-sheet-close]')).focus();
            say(`Deleted “${removed.name}”`, { action: 'Undo', onAction: () => { saveLibrary([...library.slice(0, index), removed, ...library.slice(index)]); render({ editor: false }); } });
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
        if (target.matches('[data-sheet-close]')) target.closest('dialog').close();
        else if (target.matches('[data-open-saved]')) showSheet('saved');
        else if (target.matches('[data-open-export]')) showSheet('export');
        else if (target.matches('[data-open-library]')) openLibrary();
        else if (target.matches('[data-export-go]')) {
            const chosen = EXPORTS.find(item => item.key === view.format);
            if (chosen.image) downloadPng(); else copy(core.exportAs(chosen.key, work.name, hexes()), chosen.label);
        }
        else if (target.matches('[data-library-go]')) saveToLibrary();
        else if (target.matches('[data-generate]')) runGenerate();
        else if (target.matches('[data-surprise]')) runSurprise();
        else if (target.matches('[data-nudge]')) runNudge();
        else if (target.matches('[data-save]')) savePalette();
        else if (target.matches('[data-vision-off]')) { view.vision = 'none'; $('[data-vision]').value = 'none'; saveView(); render({ editor: false }); $('[data-vision]').focus(); }
        else if (target.matches('[data-export-library]')) {
            download(new Blob([core.exportLibrary(core.orderLibrary(library))], { type: 'application/json' }), 'Saved palettes.json');
            say(`Exported ${plural(library.length, 'palette')}`);
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
            tellBar();
        }
    });
    document.addEventListener('change', event => {
        const target = event.target;
        if (target.matches('[data-field]')) { renderEditor(null); }
        else if (target.matches('[data-name]')) { work.name = core.cleanName(target.value, 'Untitled palette'); commit({ editor: false }); }
        else if (target.matches('[data-harmony]')) { view.harmony = target.value; saveView(); }
        else if (target.matches('[data-vision]')) { view.vision = target.value; saveView(); render({ editor: false }); }
        else if (target.matches('[name="export-as"]')) { view.format = target.value; saveView(); renderExport(); $('[data-sheet="export"] .tk-status').replaceChildren(); }
        else if (target.matches('[data-save-image], [data-save-tokens]')) { setLibrary(); }
        else if (target.matches('[data-import]')) { importFile(target.files[0]); target.value = ''; }
    });
    document.addEventListener('focusout', event => {
        if (event.target.matches('[data-rename-input]') && renaming === event.target.dataset.renameInput) finishRename(renaming, event.target.value, true);
    });
    // <details> does not bubble its toggle, so it is caught on the way down.
    $('[data-contrast]').addEventListener('toggle', event => {
        const key = event.target.dataset?.pair;
        if (!key) return;
        if (event.target.open) openPairs.add(key); else openPairs.delete(key);
    }, true);

    // Escape in a rename field cancels the rename and nothing else: it must not close the sheet the field is in, nor
    // reach the frame's bridge, which hears Escape on the document as a wish to leave the tool. So it is taken on
    // the window, on the way down.
    window.addEventListener('keydown', event => {
        if (event.key !== 'Escape' || !event.target.matches?.('[data-rename-input]')) return;
        event.preventDefault();
        event.stopPropagation();
        finishRename(event.target.dataset.renameInput, '', false);
    }, true);
    $('[data-sheet="saved"]').addEventListener('cancel', event => { if (renaming) { event.preventDefault(); finishRename(renaming, '', false); } });

    // Shortcuts work only while this frame has focus, and never while the person types or a sheet is open.
    const TYPING_INPUTS = new Set(['text', 'search', 'email', 'number', 'password', 'tel', 'url', '']);
    const isTyping = element => element?.isContentEditable || ['TEXTAREA', 'SELECT'].includes(element?.tagName)
        || (element?.tagName === 'INPUT' && TYPING_INPUTS.has((element.getAttribute('type') || '').toLowerCase()));
    document.addEventListener('keydown', event => {
        const target = event.target;
        if (target.matches?.('[data-rename-input]')) {
            if (event.key === 'Enter') { event.preventDefault(); finishRename(target.dataset.renameInput, target.value, true); }
            return;
        }
        if (event.defaultPrevented || event.metaKey || event.ctrlKey || event.altKey || isTyping(target) || openSheet()) return;
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

    // What the bar's controls do. Where no bar is drawn, the pane's own row of buttons does the same.
    frame?.on('open-set', ({ id }) => openPalette(id));
    frame?.on('manage-sets', () => showSheet('saved'));
    frame?.on('export', () => showSheet('export'));
    frame?.on('library', () => openLibrary());
    $('[data-own-bar]').hidden = Boolean(frame?.bar);
    $('[data-open-library]').hidden = !frame?.library;

    document.querySelectorAll('[data-icon]').forEach(button => { button.innerHTML = svg(button.dataset.icon); button.setAttribute('aria-label', button.title); });
    $('[data-harmony]').value = view.harmony;
    $('[data-vision]').value = view.vision;
    showPane(view.pane);
    saveWork();
    render();
})();
