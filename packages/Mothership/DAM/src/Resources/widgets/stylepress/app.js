// StylePress inside the tool frame. The page (views/home/stylepress.blade.php) hands every address to this script as a
// data attribute on #stylepress. The ids, requests, stored keys and states are the ones the page's inline script had.
(() => {
    const shell = document.getElementById('stylepress');
    const catalog = JSON.parse(document.getElementById('sp-catalog').textContent || '{}');
    const styles = catalog.presets || [];
    const appName = shell.dataset.appName || 'the Library';
    const $ = (id) => document.getElementById(id);
    const load = (key, fallback) => {
        try { return JSON.parse(localStorage.getItem(key) || JSON.stringify(fallback)); } catch { return fallback; }
    };
    const save = (key, value) => {
        try { localStorage.setItem(key, JSON.stringify(value)); } catch { /* storage refused: the choice lasts this visit */ }
    };
    const state = {
        q: '', cat: 'All', aspect: 'all', tab: 'all', sort: 'relevance', selected: styles[0] || null,
        favorites: load('sp:favorites', []), recent: load('sp:recent', []), density: load('sp:density', 'comfortable'),
        group: load('sp:group', true), limit: 72,
    };
    const CHUNK = 72;
    const stylesPane = $('sp-styles');
    const makePane = $('sp-make');
    const browse = $('sp-browse');
    const lightbox = $('sp-lightbox');
    const assetDialog = $('sp-asset-dialog');
    let rendered = 0;
    let cardObserver = null;
    let polling = false;
    let enhancing = false;
    let pendingGeneration = load('sp:job', null);
    let references = [];
    let acceptedEnhancement = false;
    let enhanceEpoch = 0;
    let reuseAssetId = null;
    let enhancementStale = false;
    let addedAssets = new Set();
    let assetPage = 1;
    let assetRequest = 0;
    let assetSelection = new Map();
    let assetScope = { kind: 'all', directory: null, name: '' };
    let assetTreeLoaded = false;
    let assetSearchTimer = null;
    let assetAdding = false;

    const esc = (v) => String(v ?? '').replace(/[&<>'"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;' }[c]));
    const thumb = (slug) => `${shell.dataset.thumbnailBase}/${encodeURIComponent(slug)}?v=${encodeURIComponent(catalog.version || 'unknown')}`;
    const score = (s) => {
        const haystack = [s.title, s.categoryLabel, s.look, (s.slotNames || []).join(' ')].join(' ').toLowerCase();
        return state.q ? (haystack.includes(state.q) ? 2 : 0) + (String(s.title).toLowerCase().includes(state.q) ? 3 : 0) : 1;
    };
    // Count and trim in Unicode code points to match the server's mb_strlen limits (5000 request, 4000 enhance); never split a surrogate pair.
    const chars = (v) => Array.from(String(v ?? ''));
    const charCount = (v) => chars(v).length;
    const trimChars = (v, n) => chars(v).slice(0, n).join('');

    // ---- Styles -------------------------------------------------------------------------------------------------
    function filtered() {
        let list = styles.filter((s) => (state.cat === 'All' || s.categoryLabel === state.cat)
            && (state.aspect === 'all' || s.aspectRatio === state.aspect || s.nativeFormat === state.aspect)
            && (!state.q || score(s) > 0));
        if (state.tab === 'favorites') list = list.filter((s) => state.favorites.includes(s.slug));
        if (state.tab === 'recent') list = list.filter((s) => state.recent.includes(s.slug));
        if (state.tab === 'needs-slots') list = list.filter((s) => (s.slotNames || []).length > 0);
        return list.sort((a, b) => (state.sort === 'title' ? a.title.localeCompare(b.title)
            : state.sort === 'recent' ? state.recent.indexOf(a.slug) - state.recent.indexOf(b.slug)
                : state.sort === 'favorites' ? (state.favorites.includes(b.slug) - state.favorites.includes(a.slug)) || score(b) - score(a)
                    : score(b) - score(a)));
    }

    function filtersOn() {
        return !!(state.q || state.cat !== 'All' || state.aspect !== 'all' || state.tab !== 'all');
    }

    function buildCategories() {
        const counts = new Map();
        styles.forEach((s) => counts.set(s.categoryLabel, (counts.get(s.categoryLabel) || 0) + 1));
        const select = $('sp-category');
        select.replaceChildren(...['All', ...(catalog.categories || [])].map((name) => {
            const option = document.createElement('option');
            option.value = name;
            option.textContent = name === 'All' ? 'All categories' : `${name} · ${counts.get(name) || 0}`;
            return option;
        }));
        select.value = state.cat;
    }

    function cardHtml(s) {
        const chosen = state.selected?.slug === s.slug;
        const favorite = state.favorites.includes(s.slug);
        const picture = s.image ? `<img src="${esc(thumb(s.slug))}" loading="lazy" decoding="async" alt="">` : '<span class="sp-thumb-none">Reference entry</span>';
        return `<div class="sp-card${chosen ? ' is-chosen' : ''}" data-slug="${esc(s.slug)}">`
            + `<button class="sp-pick" type="button" aria-pressed="${chosen}"><span class="sp-thumb">${picture}</span>`
            + `<span class="sp-card-text"><span class="sp-card-title">${esc(s.title)}</span>`
            + `<span class="sp-card-meta">${esc(s.categoryLabel)} · ${esc(s.aspectRatio)}${s.image ? '' : '<span class="sp-meta-ref" aria-hidden="true"> · reference entry</span>'}</span></span></button>`
            + '<span class="sp-check" aria-hidden="true">✓</span>'
            + `<button class="sp-fav" type="button" data-fav="${esc(s.slug)}" aria-pressed="${favorite}" aria-label="Favorite: ${esc(s.title)}">${favorite ? '★' : '☆'}</button></div>`;
    }

    function entries() {
        const list = filtered();
        const grouped = state.group && state.cat === 'All' && !state.q && state.sort === 'relevance' && state.tab === 'all';
        if (!grouped) return { list: list.map((s) => ({ style: s })), count: list.length };
        const order = new Map((catalog.categories || []).map((c, i) => [c, i]));
        const byCategory = new Map();
        list.forEach((s) => {
            if (!byCategory.has(s.categoryLabel)) byCategory.set(s.categoryLabel, []);
            byCategory.get(s.categoryLabel).push(s);
        });
        const out = [];
        [...byCategory.keys()].sort((x, y) => (order.get(x) ?? 999) - (order.get(y) ?? 999) || x.localeCompare(y)).forEach((c) => {
            out.push({ group: c, count: byCategory.get(c).length });
            byCategory.get(c).forEach((s) => out.push({ style: s }));
        });
        return { list: out, count: list.length };
    }

    function emptyText() {
        if (state.tab === 'favorites' && !state.favorites.length) return 'No favorites yet. Press ☆ on a style to keep it here.';
        if (state.tab === 'recent' && !state.recent.length) return 'No recent styles yet. The styles you choose show here.';
        if (state.q) return `No style matches “${state.q}”. Try a use, a slot name or a word from a look.`;
        return 'No style matches these filters.';
    }

    function cards(append = false) {
        const box = $('sp-cards');
        const { list, count } = entries();
        if (!append) {
            state.limit = CHUNK;
            rendered = 0;
            box.replaceChildren();
            // Bring the top of the cards back into view, never the page that frames this tool.
            const above = box.getBoundingClientRect().top - stylesPane.getBoundingClientRect().top;
            if (above < 0) stylesPane.scrollTop += above - 8;
        }
        $('sp-visible').textContent = filtersOn() ? `${count} of ${styles.length} styles` : `${count} styles`;
        $('sp-empty').hidden = count !== 0;
        if (count === 0) $('sp-empty').textContent = emptyText();
        $('sp-clear').hidden = !filtersOn();
        box.dataset.density = state.density;
        box.querySelector('.sp-sentinel')?.remove();
        const slice = list.slice(rendered, state.limit);
        box.insertAdjacentHTML('beforeend', slice.map((e) => (e.group !== undefined
            ? `<div class="sp-group" data-group="${esc(e.group)}"><span>${esc(e.group)} <span class="sp-group-count">${e.count}</span></span><button class="tk-link" type="button" data-cat="${esc(e.group)}">Show only</button></div>`
            : cardHtml(e.style))).join(''));
        rendered = Math.min(list.length, state.limit);
        if (rendered < list.length) {
            const sentinel = document.createElement('div');
            sentinel.className = 'sp-sentinel';
            box.append(sentinel);
            if (!cardObserver) {
                cardObserver = new IntersectionObserver((hits) => {
                    if (hits.some((h) => h.isIntersecting)) {
                        state.limit += CHUNK;
                        cards(true);
                    }
                }, { root: stylesPane, rootMargin: '400px 0px' });
            }
            cardObserver.observe(sentinel);
        }
    }

    function markSelected() {
        document.querySelectorAll('#sp-cards .sp-card').forEach((card) => {
            const on = card.dataset.slug === state.selected?.slug;
            card.classList.toggle('is-chosen', on);
            card.querySelector('.sp-pick').setAttribute('aria-pressed', String(on));
        });
    }

    function paintTabs() {
        document.querySelectorAll('.sp-tabs [data-tab]').forEach((b) => b.setAttribute('aria-pressed', String(b.dataset.tab === state.tab)));
    }

    function paintDensity() {
        document.querySelectorAll('.sp-density [data-density]').forEach((b) => b.setAttribute('aria-pressed', String(b.dataset.density === state.density)));
        $('sp-group').checked = !!state.group;
    }

    function showOnly(category) {
        state.cat = category;
        $('sp-category').value = category;
        cards();
    }

    $('sp-search').oninput = (e) => { state.q = e.target.value.trim().toLowerCase(); cards(); };
    $('sp-category').onchange = (e) => showOnly(e.target.value);
    $('sp-aspect').onchange = (e) => { state.aspect = e.target.value; cards(); };
    $('sp-sort').onchange = (e) => { state.sort = e.target.value; cards(); };
    document.querySelector('.sp-tabs').onclick = (e) => {
        const b = e.target.closest('[data-tab]');
        if (!b) return;
        state.tab = b.dataset.tab;
        paintTabs();
        cards();
    };
    $('sp-clear').onclick = () => {
        state.q = '';
        state.aspect = 'all';
        state.tab = 'all';
        $('sp-search').value = '';
        $('sp-aspect').value = 'all';
        paintTabs();
        showOnly('All');
    };
    document.querySelector('.sp-density').onclick = (e) => {
        const b = e.target.closest('button[data-density]');
        if (!b) return;
        state.density = b.dataset.density;
        save('sp:density', state.density);
        paintDensity();
        cards();
    };
    $('sp-group').onchange = (e) => { state.group = e.target.checked; save('sp:group', state.group); cards(); };
    $('sp-cards').onclick = (e) => {
        const only = e.target.closest('.sp-group [data-cat]');
        if (only) { showOnly(only.dataset.cat); return; }
        const fav = e.target.closest('[data-fav]');
        if (fav) {
            const slug = fav.dataset.fav;
            state.favorites = state.favorites.includes(slug) ? state.favorites.filter((x) => x !== slug) : [slug, ...state.favorites];
            save('sp:favorites', state.favorites);
            const on = state.favorites.includes(slug);
            fav.setAttribute('aria-pressed', String(on));
            fav.textContent = on ? '★' : '☆';
            if (state.tab === 'favorites' || state.sort === 'favorites') cards();
            return;
        }
        const pick = e.target.closest('.sp-pick');
        if (pick) select(pick.closest('[data-slug]').dataset.slug);
    };
    // Arrow keys walk the cards as they sit in the grid; Enter and Space are the button's own.
    $('sp-cards').onkeydown = (e) => {
        const pick = e.target.closest('.sp-pick');
        const keys = ['ArrowRight', 'ArrowLeft', 'ArrowDown', 'ArrowUp'];
        if (!pick || e.target !== pick || !keys.includes(e.key)) return;
        e.preventDefault();
        const all = [...document.querySelectorAll('#sp-cards .sp-pick')];
        const i = all.indexOf(pick);
        if (i < 0) return;
        const cols = state.density === 'list' ? 1 : Math.max(1, window.getComputedStyle($('sp-cards')).gridTemplateColumns.split(' ').length);
        const j = e.key === 'ArrowRight' ? i + 1 : e.key === 'ArrowLeft' ? i - 1 : e.key === 'ArrowDown' ? i + cols : i - cols;
        const next = all[Math.max(0, Math.min(all.length - 1, j))];
        next.focus();
        next.scrollIntoView({ block: 'nearest' });
    };

    // ---- Styles as a sheet, when the tool is too narrow for two panes ---------------------------------------------
    const wide = window.matchMedia('(min-width: 860px)');
    const stylesInline = () => wide.matches || document.documentElement.dataset.fold === 'rows';
    function openStyles() {
        $('sp-browse-body').append(stylesPane);
        if (!browse.open) browse.showModal();
        const chosen = stylesPane.querySelector('.sp-card.is-chosen');
        if (chosen) stylesPane.scrollTop += chosen.getBoundingClientRect().top - stylesPane.getBoundingClientRect().top - 120;
    }
    function closeStyles() {
        if (browse.open) browse.close();
    }
    browse.addEventListener('close', () => shell.insertBefore(stylesPane, makePane));
    $('sp-change-style').onclick = openStyles;
    $('sp-browse-done').onclick = closeStyles;
    wide.addEventListener('change', () => { if (stylesInline()) closeStyles(); });
    new MutationObserver(() => { if (stylesInline()) closeStyles(); }).observe(document.documentElement, { attributes: true, attributeFilter: ['data-fold'] });

    // ---- Make ---------------------------------------------------------------------------------------------------
    function lookOpen(open) {
        const look = $('sp-look');
        const more = $('sp-look-more');
        look.classList.toggle('is-open', open);
        more.textContent = open ? 'Less' : 'More';
        more.setAttribute('aria-expanded', String(open));
        more.hidden = false;
        requestAnimationFrame(() => { more.hidden = !open && look.scrollHeight <= look.clientHeight + 1; });
    }

    function paintSlots(all) {
        const names = state.selected?.slotNames || [];
        const box = $('sp-slots');
        if (!names.length) { box.innerHTML = '<span class="tk-note">No required slots detected</span>'; return; }
        const shown = all ? names : names.slice(0, 8);
        box.innerHTML = shown.map((x) => `<span class="sp-slot">{${esc(x)}}</span>`).join('')
            + (names.length > shown.length ? `<button class="tk-link sp-slots-more" type="button">+${names.length - shown.length} more</button>` : '');
    }

    function detail() {
        const s = state.selected;
        if (!s) return;
        availability();
        const sourceFiles = s.references || [];
        $('sp-sources').hidden = !sourceFiles.length;
        $('sp-source-files').innerHTML = sourceFiles.map((file, index) => `<a class="tk-link" href="${esc(shell.dataset.sourceBase)}/${encodeURIComponent(s.slug)}/${index}" target="_blank" rel="noopener">${esc(file.split('/').slice(2).join('/') || file)}</a>`).join('');
        $('sp-preview').hidden = !s.image;
        $('sp-preview-none').hidden = !!s.image;
        if (s.image) $('sp-preview').src = thumb(s.slug);
        $('sp-cat').textContent = `${s.categoryLabel} · ${s.aspectRatio}`;
        $('sp-title').textContent = s.title;
        $('sp-look').textContent = (s.generatable === false ? 'Reference-only entry. Browse and copy its source; generation is unavailable. ' : '') + (s.look || '');
        lookOpen(false);
        paintSlots(false);
    }

    function select(slug) {
        reuseAssetId = null;
        invalidateEnhancement();
        const s = styles.find((x) => x.slug === slug);
        if (!s) return;
        state.selected = s;
        state.recent = [slug, ...state.recent.filter((x) => x !== slug)].slice(0, 30);
        save('sp:recent', state.recent);
        if (state.tab === 'recent' || state.sort === 'recent') cards();
        else markSelected();
        detail();
        closeStyles();
    }

    $('sp-look-more').onclick = () => lookOpen(!$('sp-look').classList.contains('is-open'));
    $('sp-slots').onclick = (e) => { if (e.target.closest('.sp-slots-more')) paintSlots(true); };

    function compiled() {
        const s = state.selected;
        const request = (acceptedEnhancement ? $('sp-enhanced').value : $('sp-prompt').value).trim() || 'Create a finished image using this style.';
        return ['STYLEPRESS HOUSE STYLE — LOCKED PRODUCTION INSTRUCTION', `STYLE: ${s.title}`, `STYLE LOOK: ${s.look}`, 'STYLE TEMPLATE (VERBATIM):', s.template,
            s.slots ? 'INPUT SLOT CONTRACT (VERBATIM):' : '', s.slots || '',
            $('sp-mode').value === 'restyle' ? 'RESTYLE MODE: preserve source content and change only the style treatment.' : 'CREATE MODE: user request supplies content; style supplies art direction.',
            'USER REQUEST:', request, 'Produce exactly one finished image. No watermark, no prompt text, no implementation notes.'].filter(Boolean).join('\n\n');
    }

    function paintMode() {
        document.querySelectorAll('.sp-mode [data-mode]').forEach((b) => b.setAttribute('aria-pressed', String(b.dataset.mode === $('sp-mode').value)));
    }
    document.querySelector('.sp-mode').onclick = (e) => {
        const b = e.target.closest('[data-mode]');
        if (!b || b.dataset.mode === $('sp-mode').value) return;
        $('sp-mode').value = b.dataset.mode;
        paintMode();
        invalidateEnhancement();
        availability();
    };

    // The status line under the actions. A failure carries the kit's ✕ as well as its colour.
    function say(text) {
        const box = $('sp-result');
        box.hidden = false;
        box.classList.remove('is-error');
        box.textContent = text;
    }

    function showError(error) {
        const box = $('sp-result');
        box.hidden = false;
        box.classList.add('is-error');
        box.textContent = error.message || String(error);
    }

    function availability() {
        const connected = shell.dataset.connected === '1';
        const ready = connected && !!state.selected;
        const referenceOnly = state.selected?.generatable === false;
        const needsSource = $('sp-mode').value === 'restyle' && !references.length;
        $('sp-generate').disabled = !ready || polling || enhancing || !!pendingGeneration || referenceOnly || needsSource;
        $('sp-enhance').disabled = !ready || enhancing || referenceOnly;
        $('sp-estimate').disabled = !ready || enhancing || referenceOnly;
        // One filled button: Generate when this account can make the image, Copy prompt when it cannot.
        $('sp-generate').classList.toggle('is-act', ready && !referenceOnly);
        $('sp-copy').classList.toggle('is-act', !(ready && !referenceOnly));
        $('sp-mode-note').hidden = !needsSource;
        if (!connected) $('sp-balance').textContent = 'Generation is not connected yet. Browse styles, save favorites, or copy a prompt.';
    }

    async function post(url, body) {
        const form = body instanceof FormData;
        const response = await fetch(url, {
            method: 'POST',
            headers: { ...(!form ? { 'Content-Type': 'application/json' } : {}), 'X-CSRF-TOKEN': shell.dataset.csrf, Accept: 'application/json' },
            body: form ? body : JSON.stringify(body),
        });
        return response.json();
    }

    function modelChoice() {
        const [model, variant] = $('sp-model').value.split(':');
        return { model, variant: variant || null };
    }

    function payload() {
        const s = state.selected;
        return {
            style_slug: s.slug, reuse_asset: reuseAssetId, prompt: $('sp-prompt').value, aspect_ratio: $('sp-output-aspect').value || s.aspectRatio,
            quality: $('sp-quality').value, resolution: $('sp-resolution').value, ...modelChoice(), mode: $('sp-mode').value,
            references: references.map((r) => r.id), enhanced_prompt: acceptedEnhancement ? $('sp-enhanced').value : null,
        };
    }

    async function balance() {
        try {
            const response = await fetch(shell.dataset.balanceUrl, { headers: { Accept: 'application/json' } });
            const data = await response.json();
            $('sp-balance').textContent = data.ok ? `Shared Higgsfield balance: ${data.balance?.credits ?? 'Unavailable'} credits` : `Balance unavailable: ${data.message}`;
        } catch (error) {
            $('sp-balance').textContent = `Balance unavailable: ${String(error)}`;
        }
    }

    $('sp-copy').onclick = async () => {
        try {
            await navigator.clipboard.writeText(compiled());
            say('Compiled prompt copied.');
        } catch (error) {
            showError(Error('The prompt could not be copied. Allow clipboard access and try again.'));
        }
    };

    $('sp-estimate').onclick = async () => {
        say('Estimating credits…');
        try {
            const data = await post(shell.dataset.estimateUrl, payload());
            const e = data.estimate || {};
            if (data.ok) say(`Estimate: ${e.credits ?? JSON.stringify(e)} credits${e.usd ? ` / ${e.usd} USD` : ''}${e.aspect_ratio ? ` · ${e.aspect_ratio} output` : ''}`);
            else showError(Error(data.message || JSON.stringify(data)));
        } catch (error) {
            showError(error);
        } finally {
            availability();
        }
    };

    function showProgress(text, note) {
        const box = $('sp-result');
        box.hidden = false;
        box.classList.remove('is-error');
        box.replaceChildren();
        const wrap = document.createElement('div');
        wrap.className = 'sp-progress';
        const spin = document.createElement('span');
        spin.className = 'sp-spinner';
        spin.setAttribute('aria-hidden', 'true');
        const words = document.createElement('span');
        words.textContent = text;
        if (note) {
            const small = document.createElement('small');
            small.textContent = note;
            words.append(small);
        }
        wrap.append(spin, words);
        box.append(wrap);
    }

    function action(label, href) {
        const link = document.createElement('a');
        link.className = 'tk-btn is-small';
        link.href = href;
        link.textContent = label;
        return link;
    }

    function showOutput(generation) {
        const panel = $('sp-output');
        if (generation.status !== 'completed') { showError(Error(`Generation ended as ${generation.status}.`)); return; }
        let url;
        try {
            url = new URL(generation.url, location.href);
            if (!['http:', 'https:'].includes(url.protocol) || url.origin !== location.origin) throw Error();
        } catch {
            showError(Error('Generation completed without a downloadable image URL.'));
            return;
        }
        $('sp-result').hidden = true;
        panel.replaceChildren();
        panel.hidden = false;
        const image = document.createElement('img');
        image.className = 'sp-output-image';
        image.src = url.href;
        image.alt = 'Generated image';
        image.title = 'View full size';
        image.onclick = () => openLightbox(url.href);
        const body = document.createElement('div');
        body.className = 'sp-output-body';
        const title = document.createElement('div');
        title.className = 'sp-output-title';
        title.textContent = 'Your image is ready';
        const meta = document.createElement('div');
        meta.className = 'sp-output-meta';
        meta.textContent = generation.archive_status === 'archived' ? `Saved to ${appName} · Archived to the vault` : `Saved to ${appName} · Vault archive pending device sync`;
        const actions = document.createElement('div');
        actions.className = 'sp-output-actions';
        const view = document.createElement('button');
        view.type = 'button';
        view.className = 'tk-btn is-small';
        view.textContent = 'View full size';
        view.onclick = () => openLightbox(url.href);
        const open = action('Open in new tab', url.href);
        open.target = '_blank';
        open.rel = 'noopener noreferrer';
        actions.append(view, open);
        if (generation.asset_id) {
            const download = action('Download', `${shell.dataset.libraryBase}/${generation.asset_id}/generation/output?download=1`);
            download.download = '';
            const details = action('Generation details', `${shell.dataset.libraryBase}/${generation.asset_id}/generation/view`);
            details.target = '_blank';
            details.rel = 'noopener';
            actions.append(download, details);
        }
        body.append(title, meta, actions);
        panel.append(image, body);
        makePane.scrollTo({ top: 0, behavior: 'smooth' });
    }

    function openLightbox(href) {
        $('sp-lightbox-image').src = href;
        $('sp-lightbox-open').href = href;
        if (typeof lightbox.showModal === 'function') { if (!lightbox.open) lightbox.showModal(); } else lightbox.setAttribute('open', '');
    }
    function closeLightbox() {
        if (lightbox.open && typeof lightbox.close === 'function') lightbox.close();
        else lightbox.removeAttribute('open');
    }
    $('sp-lightbox-close').onclick = closeLightbox;
    lightbox.onclick = (e) => { if (e.target === lightbox || e.target.classList.contains('sp-lightbox-panel')) closeLightbox(); };

    async function pollGeneration(job) {
        polling = true;
        availability();
        showProgress('Checking your generation…');
        const deadline = Date.now() + 600000;
        try {
            const url = new URL(job.status_url, location.href);
            if (url.origin !== location.origin) throw Error('Invalid generation status address.');
            while (Date.now() < deadline) {
                const response = await fetch(url.href, { headers: { Accept: 'application/json' } });
                const data = await response.json();
                if (!response.ok) throw Error(data.message || 'Status is temporarily unavailable.');
                if (['completed', 'failed', 'nsfw', 'canceled', 'cancelled'].includes(data.generation.status)) {
                    pendingGeneration = null;
                    try { localStorage.removeItem('sp:job'); } catch { /* nothing stored */ }
                    showOutput(data.generation);
                    return;
                }
                const saving = data.generation.status === 'saving';
                showProgress(saving ? `Image completed. Saving to ${appName}…` : 'Generating your image…',
                    (saving ? '' : 'You can close this browser; the server keeps working. ') + (data.generation.message || ''));
                await new Promise((resolve) => setTimeout(resolve, 2500));
            }
            throw Error('Your generation is still processing.');
        } catch (error) {
            showError(error);
            const retry = document.createElement('button');
            retry.type = 'button';
            retry.className = 'tk-btn is-small sp-retry';
            retry.textContent = 'Check again';
            retry.onclick = () => pollGeneration(job);
            $('sp-result').append(' ', retry);
        } finally {
            polling = false;
            availability();
        }
    }

    $('sp-generate').onclick = async () => {
        if (polling || pendingGeneration) return;
        if (!$('sp-enhancement').hidden && $('sp-enhanced').value.trim() && !acceptedEnhancement) {
            if (confirm('Generate with the enhanced request shown below? Cancel to generate with your original request instead.')) {
                acceptedEnhancement = true;
                enhancementStale = false;
                $('sp-reenhance').hidden = true;
                $('sp-enhancement-state').textContent = 'Accepted for this generation.';
            } else {
                $('sp-enhancement-state').textContent = 'Generating with the original request. The enhanced text is kept here for later.';
            }
        }
        polling = true;
        availability();
        $('sp-output').hidden = true;
        showProgress('Estimating, then submitting to Higgsfield…');
        try {
            const data = await post(shell.dataset.generateUrl, payload());
            if (!data.ok) throw Error(data.message || 'Generation could not start.');
            pendingGeneration = data.generation;
            save('sp:job', pendingGeneration);
            await pollGeneration(pendingGeneration);
        } catch (error) {
            showError(error);
        } finally {
            polling = false;
            availability();
        }
    };

    // ---- Enhance ------------------------------------------------------------------------------------------------
    function invalidateEstimate() {
        if (!$('sp-result').hidden && !polling) say('Settings changed. Estimate again before generating.');
    }

    function discardEnhancement() {
        enhanceEpoch++;
        acceptedEnhancement = false;
        enhancementStale = false;
        $('sp-enhancement').hidden = true;
        $('sp-enhanced').value = '';
        $('sp-reenhance').hidden = true;
        invalidateEstimate();
    }

    function invalidateEnhancement() {
        enhanceEpoch++;
        acceptedEnhancement = false;
        invalidateEstimate();
        if ($('sp-enhancement').hidden || !$('sp-enhanced').value.trim()) { discardEnhancement(); return; }
        enhancementStale = true;
        $('sp-reenhance').hidden = false;
        $('sp-enhancement-state').textContent = 'Your request, style, mode, or references changed after this enhancement. Re-enhance with the current inputs, or accept this text to generate with it as is.';
    }

    const enhanceControls = ['sp-reenhance', 'sp-enhanced', 'sp-accept-enhanced', 'sp-discard-enhanced'];
    async function enhance() {
        if (!$('sp-prompt').value.trim()) { showError(Error('Describe what you want to create first, then enhance that request.')); $('sp-prompt').focus(); return; }
        if (charCount($('sp-prompt').value) > 4000) { showError(Error('Enhance accepts requests up to 4000 characters. Shorten the request, or generate without enhancement (up to 5000).')); $('sp-prompt').focus(); return; }
        if ($('sp-mode').value === 'restyle' && !references.length) { showError(Error('Restyle needs a source image. Add a reference image, then enhance.')); return; }
        const epoch = ++enhanceEpoch;
        enhancing = true;
        enhanceControls.forEach((id) => { $(id).disabled = true; });
        availability();
        say('Enhancing your request… Generate unlocks when it finishes.');
        try {
            const data = await post(shell.dataset.enhanceUrl, payload());
            if (!data.ok) throw Error(data.message || 'Enhancement unavailable.');
            if (epoch !== enhanceEpoch) return;
            acceptedEnhancement = true;
            enhancementStale = false;
            $('sp-reenhance').hidden = true;
            $('sp-enhanced').value = data.enhanced_prompt;
            $('sp-enhancement').hidden = false;
            $('sp-enhancement-state').textContent = 'Enhanced request accepted. Generate uses this text; edit it here if you want changes, or discard it to use your original request.';
            invalidateEstimate();
            say('Enhancement ready. Estimate or generate with the enhanced request.');
        } catch (error) {
            showError(error);
        } finally {
            enhancing = false;
            enhanceControls.forEach((id) => { $(id).disabled = false; });
            availability();
        }
    }
    $('sp-enhance').onclick = enhance;
    $('sp-reenhance').onclick = enhance;
    $('sp-accept-enhanced').onclick = () => {
        acceptedEnhancement = true;
        enhancementStale = false;
        $('sp-reenhance').hidden = true;
        $('sp-enhancement-state').textContent = 'Accepted. Generate will apply the complete style template to this edited request.';
        invalidateEstimate();
    };
    $('sp-enhanced').oninput = () => {
        enhanceEpoch++;
        acceptedEnhancement = false;
        $('sp-enhancement-state').textContent = 'Edited. Accept this version to use it, or Generate will ask which request to use.';
        invalidateEstimate();
    };
    $('sp-discard-enhanced').onclick = discardEnhancement;

    function promptCount() {
        const box = $('sp-prompt');
        if (charCount(box.value) > 5000) {
            const at = box.selectionStart;
            box.value = trimChars(box.value, 5000);
            box.setSelectionRange(Math.min(at, box.value.length), Math.min(at, box.value.length));
        }
        const n = charCount(box.value);
        const count = $('sp-prompt-count');
        // Past 4000 the words say so, with weight; the colour stays ink.
        count.textContent = n > 4000 ? `${n} / 5000 · too long for Enhance, which takes up to 4000` : `${n} / 5000 · Enhance takes up to 4000`;
        count.classList.toggle('is-over', n > 4000);
    }
    $('sp-prompt').oninput = () => { promptCount(); invalidateEnhancement(); };
    ['sp-output-aspect', 'sp-quality', 'sp-resolution', 'sp-model'].forEach((id) => { $(id).onchange = invalidateEstimate; });

    // ---- References ---------------------------------------------------------------------------------------------
    function renderReferences() {
        const box = $('sp-references');
        box.replaceChildren();
        references.forEach((ref) => {
            const card = document.createElement('div');
            card.className = 'sp-ref';
            const image = document.createElement('img');
            image.src = ref.url;
            image.alt = ref.name || 'Reference image';
            const remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'sp-ref-remove';
            remove.textContent = '✕';
            remove.setAttribute('aria-label', `Remove ${ref.name || 'this reference'}`);
            remove.onclick = () => {
                references = references.filter((r) => r.id !== ref.id);
                if (ref.asset_id) {
                    addedAssets.delete(ref.asset_id);
                    document.querySelectorAll('.sp-asset.added').forEach((b) => {
                        if (Number(b.dataset.assetId) === ref.asset_id) { b.classList.remove('added'); b.setAttribute('aria-selected', 'false'); }
                    });
                }
                invalidateEnhancement();
                renderReferences();
            };
            card.append(image, remove);
            box.append(card);
        });
        $('sp-ref-count').textContent = `${references.length}/8`;
        availability();
    }

    // An image picked from the Library fires no input event, so the Home bridge (home-frame.js) cannot see it.
    // Home closes a tool without asking while it has heard of no work, so an attached image says so itself.
    function reportWork() {
        if (window.parent !== window) window.parent.postMessage({ protocol: 'mothership-widget/v1', type: 'dirty', payload: {} }, location.origin);
    }

    async function addReference(input, assetId = null) {
        if (references.length >= 8) throw Error('Use at most eight reference images.');
        const data = await post(shell.dataset.referenceUrl, input);
        if (!data.id) throw Error(data.message || 'Image could not be attached.');
        references.push({ ...data, asset_id: assetId });
        reportWork();
        if (assetId) addedAssets.add(assetId);
        invalidateEnhancement();
        renderReferences();
    }

    // The file input is the upload path for references; the Library picker below is the other.
    $('sp-upload').onchange = async (e) => {
        try {
            for (const file of e.target.files) {
                const form = new FormData();
                form.append('image', file);
                await addReference(form);
            }
        } catch (error) {
            showError(error);
        } finally {
            e.target.value = '';
        }
    };

    // ---- The Library picker -------------------------------------------------------------------------------------
    function assetSelectionText() {
        const n = assetSelection.size;
        const room = 8 - references.length;
        $('sp-asset-selection').textContent = n ? `${n} selected · ${Math.max(room - n, 0)} more can be added`
            : room > 0 ? `Choose images to add (${room} of 8 reference slots free).` : 'All eight reference slots are in use. Remove a reference to add another.';
        $('sp-asset-add').disabled = !n;
        $('sp-asset-add').textContent = n ? `Add ${n} selected` : 'Add selected';
    }

    function toggleAsset(asset, card) {
        if (addedAssets.has(asset.id)) return;
        if (assetSelection.has(asset.id)) {
            assetSelection.delete(asset.id);
            card.classList.remove('selected');
            card.setAttribute('aria-selected', 'false');
        } else {
            if (references.length + assetSelection.size >= 8) { $('sp-asset-status').textContent = 'Use at most eight reference images. Remove one to add another.'; return; }
            assetSelection.set(asset.id, asset);
            card.classList.add('selected');
            card.setAttribute('aria-selected', 'true');
        }
        assetSelectionText();
    }

    function assetCard(asset) {
        const card = document.createElement('div');
        card.setAttribute('role', 'option');
        card.tabIndex = 0;
        card.dataset.assetId = asset.id;
        const added = addedAssets.has(asset.id);
        const selected = assetSelection.has(asset.id);
        card.className = `sp-asset${added ? ' added' : selected ? ' selected' : ''}`;
        card.setAttribute('aria-selected', added || selected ? 'true' : 'false');
        card.title = asset.title || asset.name;
        const box = document.createElement('div');
        box.className = 'sp-asset-thumb';
        const image = document.createElement('img');
        image.src = asset.url;
        image.alt = '';
        image.loading = 'lazy';
        image.decoding = 'async';
        image.draggable = false;
        box.append(image);
        const mark = document.createElement('span');
        mark.className = 'sp-asset-mark';
        mark.textContent = '✓';
        const tag = document.createElement('span');
        tag.className = 'sp-asset-tag';
        tag.textContent = 'Added';
        const label = document.createElement('span');
        label.className = 'sp-asset-name';
        label.textContent = asset.title || asset.name;
        card.append(box, mark, tag, label);
        card.onclick = () => toggleAsset(asset, card);
        card.onkeydown = (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); toggleAsset(asset, card); } };
        return card;
    }

    function scopeLabel() {
        return assetScope.kind === 'folder' ? assetScope.name : assetScope.kind === 'recent' ? 'Added in the last 30 days' : assetScope.kind === 'favorites' ? 'Favorites' : 'All images';
    }

    function paintCrumbs(q) {
        const box = $('sp-asset-crumbs');
        box.replaceChildren();
        if (q) {
            box.append('Search results for ');
            const b = document.createElement('b');
            b.textContent = `“${q}”`;
            box.append(b, assetScope.kind === 'favorites' ? ' in your favorites' : ' across all permitted images');
            return;
        }
        if (assetScope.kind === 'folder' && assetScope.path) {
            assetScope.path.forEach((name, i) => {
                if (i) box.append(' › ');
                const el = document.createElement(i === assetScope.path.length - 1 ? 'b' : 'span');
                el.textContent = name;
                box.append(el);
            });
            return;
        }
        const b = document.createElement('b');
        b.textContent = scopeLabel();
        box.append(b);
    }

    async function findAssets(append = false) {
        const box = $('sp-asset-results');
        const status = $('sp-asset-status');
        const q = $('sp-asset-search').value.trim();
        const scope = q ? (assetScope.kind === 'favorites' ? 'favorites' : 'all') : assetScope.kind;
        if (!append) { assetPage = 1; box.replaceChildren(); status.textContent = 'Loading…'; paintCrumbs(q); }
        const request = ++assetRequest;
        try {
            const params = new URLSearchParams({ scope, page: String(assetPage), q, sort: $('sp-asset-sort').value });
            if (scope === 'folder') params.set('directory', String(assetScope.directory));
            const response = await fetch(`${shell.dataset.assetsUrl}?${params.toString()}`, { headers: { Accept: 'application/json' } });
            const data = await response.json().catch(() => ({}));
            if (!response.ok) throw Error(data.message || `Image search is unavailable right now (${response.status}).`);
            if (request !== assetRequest) return;
            if (!append) box.replaceChildren();
            box.querySelector('.sp-asset-more')?.remove();
            const shown = box.querySelectorAll('.sp-asset').length + (data.assets || []).length;
            const more = data.has_more ? '+' : '';
            const plural = shown === 1 ? '' : 's';
            status.textContent = q ? `${shown}${more} image${plural} matching “${q}”`
                : scope === 'folder' ? `${shown}${more} image${plural} in this folder`
                    : scope === 'recent' ? `${shown} image${plural} added in the last 30 days`
                        : scope === 'favorites' ? `${shown} favorite image${plural}` : `${shown}${more} permitted image${plural}`;
            (data.assets || []).forEach((asset) => box.append(assetCard(asset)));
            if (data.has_more) {
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'tk-btn is-small sp-asset-more';
                btn.textContent = 'Show more';
                btn.onclick = () => { btn.disabled = true; btn.textContent = 'Loading…'; assetPage++; findAssets(true); };
                box.append(btn);
            }
            if (!box.childElementCount) {
                status.textContent = q ? `No permitted images match “${q}”. Try another word from the file name, title, or a tag.`
                    : scope === 'favorites' ? 'No favorite images yet. Star images in the library to see them here.'
                        : scope === 'recent' ? 'No permitted images from the last 30 days.'
                            : scope === 'folder' ? 'No images in this folder. Subfolders are listed with the folders.' : 'No permitted images yet.';
            }
        } catch (error) {
            if (request === assetRequest) status.textContent = error.message || String(error);
        }
    }

    function moreFolders(fetchMore) {
        const more = document.createElement('button');
        more.type = 'button';
        more.className = 'sp-lib-more tk-link';
        more.textContent = 'More folders…';
        more.onclick = () => fetchMore().catch((e) => { $('sp-asset-status').textContent = e.message; });
        return more;
    }

    function folderEntry(dir, depth, pathNames) {
        const wrap = document.createElement('div');
        const entry = document.createElement('button');
        entry.type = 'button';
        entry.className = 'sp-lib-entry';
        entry.dataset.kind = 'folder';
        entry.dataset.directory = dir.id;
        entry.style.setProperty('--depth', String(depth));
        const toggle = document.createElement('span');
        toggle.className = `sp-lib-toggle${dir.has_children ? '' : ' leaf'}`;
        toggle.textContent = '▸';
        const name = document.createElement('span');
        name.className = 'sp-lib-name';
        name.textContent = dir.name;
        entry.append(toggle, name);
        const children = document.createElement('div');
        children.className = 'sp-lib-children';
        children.hidden = true;
        let loaded = false;
        let offset = 0;
        const path = [...pathNames, dir.name];
        async function loadChildren() {
            const response = await fetch(`${shell.dataset.foldersUrl}?parent=${encodeURIComponent(dir.id)}&offset=${offset}&limit=100`, { headers: { Accept: 'application/json' } });
            const data = await response.json().catch(() => ({}));
            if (!response.ok) throw Error(data.message || 'Folders are unavailable.');
            children.querySelector('.sp-lib-more')?.remove();
            (data.data || []).forEach((child) => children.append(folderEntry(child, depth + 1, path)));
            offset += (data.data || []).length;
            if (data.has_more) children.append(moreFolders(loadChildren));
            loaded = true;
        }
        toggle.onclick = async (e) => {
            e.stopPropagation();
            if (!dir.has_children) return;
            children.hidden = !children.hidden;
            toggle.textContent = children.hidden ? '▸' : '▾';
            if (!children.hidden && !loaded) {
                toggle.textContent = '…';
                try { await loadChildren(); } catch (err) { $('sp-asset-status').textContent = err.message; }
                toggle.textContent = '▾';
            }
        };
        entry.onclick = () => {
            assetScope = { kind: 'folder', directory: dir.id, name: dir.name, path };
            document.querySelectorAll('#sp-lib-tree .sp-lib-entry').forEach((b) => b.classList.toggle('active', b === entry));
            $('sp-asset-search').value = '';
            findAssets();
            if (dir.has_children && children.hidden) toggle.onclick(new Event('click'));
        };
        if (Array.isArray(dir.children) && dir.children.length) {
            dir.children.forEach((child) => children.append(folderEntry(child, depth + 1, path)));
            loaded = !dir.children_has_more;
            offset = dir.children.length;
            if (dir.children_has_more) children.append(moreFolders(loadChildren));
        }
        wrap.append(entry, children);
        return wrap;
    }

    function folderNote(text) {
        const p = document.createElement('p');
        p.className = 'sp-asset-status sp-lib-note';
        p.textContent = text;
        return p;
    }

    async function loadFolderTree() {
        if (assetTreeLoaded) return;
        const host = $('sp-lib-folders');
        try {
            const response = await fetch(shell.dataset.foldersUrl, { headers: { Accept: 'application/json' } });
            const data = await response.json().catch(() => ({}));
            if (!response.ok) throw Error(data.message || 'Folders are unavailable.');
            host.replaceChildren();
            (data.data || []).forEach((dir) => host.append(folderEntry(dir, 0, [])));
            if (!host.childElementCount) host.append(folderNote('No folders are shared with you.'));
            assetTreeLoaded = true;
        } catch (error) {
            host.replaceChildren(folderNote(error.message || 'Folders are unavailable.'));
        }
    }

    $('sp-lib-tree').addEventListener('click', (e) => {
        const b = e.target.closest('.sp-lib-entry[data-kind]');
        if (!b || b.dataset.kind === 'folder') return;
        assetScope = { kind: b.dataset.kind, directory: null, name: '' };
        document.querySelectorAll('#sp-lib-tree .sp-lib-entry').forEach((x) => x.classList.toggle('active', x === b));
        $('sp-asset-search').value = '';
        findAssets();
    });
    $('sp-asset-sort').onchange = () => findAssets();

    function openAssetBrowser() {
        assetSelection.clear();
        assetSelectionText();
        if (typeof assetDialog.showModal === 'function') { if (!assetDialog.open) assetDialog.showModal(); } else assetDialog.setAttribute('open', '');
        loadFolderTree();
        findAssets();
        setTimeout(() => $('sp-asset-search').focus(), 50);
    }

    function closeAssetBrowser() {
        if (assetAdding) return;
        assetSelection.clear();
        assetRequest++;
        if (assetDialog.open && typeof assetDialog.close === 'function') assetDialog.close();
        else assetDialog.removeAttribute('open');
    }

    $('sp-browse-assets').onclick = openAssetBrowser;
    $('sp-asset-close').onclick = closeAssetBrowser;
    $('sp-asset-cancel').onclick = closeAssetBrowser;
    assetDialog.addEventListener('cancel', (e) => { e.preventDefault(); closeAssetBrowser(); });
    assetDialog.onclick = (e) => { if (e.target === assetDialog) closeAssetBrowser(); };
    $('sp-asset-add').onclick = async () => {
        const chosen = [...assetSelection.values()];
        if (!chosen.length || assetAdding) return;
        assetAdding = true;
        ['sp-asset-add', 'sp-asset-cancel', 'sp-asset-close'].forEach((id) => { $(id).disabled = true; });
        $('sp-asset-add').textContent = 'Adding…';
        let failed = null;
        try {
            for (const asset of chosen) {
                try { await addReference({ asset_id: asset.id }, asset.id); } catch (error) { failed = error; break; }
            }
        } finally {
            assetAdding = false;
            ['sp-asset-cancel', 'sp-asset-close'].forEach((id) => { $(id).disabled = false; });
        }
        if (failed) {
            $('sp-asset-status').textContent = failed.message || String(failed);
            assetSelection.clear();
            document.querySelectorAll('.sp-asset').forEach((b) => {
                const id = Number(b.dataset.assetId);
                b.classList.toggle('added', addedAssets.has(id));
                b.classList.remove('selected');
                b.setAttribute('aria-selected', addedAssets.has(id) ? 'true' : 'false');
            });
            assetSelectionText();
            return;
        }
        closeAssetBrowser();
    };
    $('sp-asset-search').onkeydown = (e) => { if (e.key === 'Enter') { e.preventDefault(); clearTimeout(assetSearchTimer); findAssets(); } };
    $('sp-asset-search').oninput = () => { clearTimeout(assetSearchTimer); assetSearchTimer = setTimeout(() => findAssets(), 350); };

    // ---- Reuse a saved generation (?reuse_asset=ID) -------------------------------------------------------------
    async function reuse() {
        const id = new URL(location.href).searchParams.get('reuse_asset');
        if (!id || !/^\d+$/.test(id)) return;
        try {
            const response = await fetch(`${shell.dataset.libraryBase}/${id}/generation`, { headers: { Accept: 'application/json' } });
            if (!response.ok) throw Error('This generation is unavailable.');
            const data = await response.json();
            const manifest = data.manifest || data.generation?.manifest || data.generation || data;
            if (!manifest.style?.slug || !manifest.style?.template) throw Error('No reusable style snapshot was recorded. Copy the saved prompt or choose this image as a reference.');
            const style = styles.find((s) => s.slug === manifest.style?.slug);
            if (style) select(style.slug);
            else if (manifest.style?.slug) {
                styles.push({ ...manifest.style, categoryLabel: manifest.style.categoryLabel || manifest.style.category || 'Historical style' });
                select(manifest.style.slug);
            }
            reuseAssetId = Number(id);
            if (manifest.style) {
                state.selected = { ...state.selected, ...manifest.style, categoryLabel: manifest.style.categoryLabel || manifest.style.category || 'Historical style' };
                detail();
            }
            if (manifest.style?.version !== catalog.version) showError(Error('Restored the original style snapshot. Selecting another style uses the current catalog.'));
            const restoredRequest = String(manifest.original_prompt || manifest.prompt || '');
            $('sp-prompt').value = trimChars(restoredRequest, 5000);
            promptCount();
            if (charCount(restoredRequest) > 5000) showError(Error('The saved request was longer than 5000 characters and was trimmed to fit.'));
            const settings = manifest.settings || {};
            ['aspect_ratio', 'quality', 'resolution'].forEach((key, i) => {
                const control = $(['sp-output-aspect', 'sp-quality', 'sp-resolution'][i]);
                if ([...control.options].some((o) => o.value === settings[key])) control.value = settings[key];
            });
            if (settings.model) {
                const choice = settings.model + (settings.variant ? `:${settings.variant}` : '');
                if ([...$('sp-model').options].some((o) => o.value === choice)) $('sp-model').value = choice;
            }
            $('sp-mode').value = settings.mode || 'create';
            paintMode();
            for (const ref of (data.reference_urls || []).filter((r) => typeof r === 'string' || r.mime?.startsWith('image/'))) {
                const address = typeof ref === 'string' ? ref : ref.url;
                const url = new URL(address, location.href);
                if (url.origin !== location.origin) throw Error('Reference address is unavailable.');
                const result = await fetch(url);
                if (!result.ok) throw Error('A saved reference is unavailable.');
                const form = new FormData();
                form.append('image', await result.blob(), ref.name || 'reference.png');
                await addReference(form);
            }
            if (manifest.enhanced_prompt) {
                $('sp-enhanced').value = manifest.enhanced_prompt;
                $('sp-enhancement').hidden = false;
                acceptedEnhancement = true;
                $('sp-enhancement-state').textContent = 'Restored enhanced request. Review before generating.';
            }
            availability();
        } catch (error) {
            showError(error);
        }
    }

    buildCategories();
    paintTabs();
    paintDensity();
    paintMode();
    promptCount();
    reuse();
    cards();
    detail();
    availability();
    if (shell.dataset.connected === '1') balance();
    if (pendingGeneration) pollGeneration(pendingGeneration);
})();
