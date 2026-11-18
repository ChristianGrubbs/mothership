(() => {
    'use strict';
    // The Bridge: everything on Home that reads the ship's summary. A source that cannot answer says so in words, and
    // a missing figure is never drawn as zero. home.js owns the tool workspace, the dialogs and the Tools shelf.
    const COLUMNS = [
        {key: 'time', width: 5, name: 'k1'},
        {key: 'from', width: 11, name: 'k2'},
        {key: 'cargo', width: 20, name: 'k3'},
        {key: 'quantity', width: 3, name: 'k4', align: 'right'},
        {key: 'status', width: 8, name: 'k5'},
    ];
    const SUMMARY_INTERVAL_MS = 60000;
    const SIGNAL_INTERVAL_MS = 300000;
    const TICK_MS = 15000;
    const STORY_LIMIT = 4;
    const TRENDING_LIMIT = 3;
    const FLAP_STEP_MS = 55;
    const FLAP_COLUMN_MS = 6;
    const GLYPHS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    const PHONE = '(max-width: 720px)';
    const ICONS = {
        arrow: 'M5 12h14M12 5l7 7-7 7',
        chevron: 'm9 18 6-6-6-6',
        out: 'M7 7h10v10M7 17 17 7',
        loader: 'M21 12a9 9 0 1 1-6.219-8.56',
    };
    const icon = name => `<svg class="hm-ic" viewBox="0 0 24 24" aria-hidden="true"><path d="${ICONS[name]}"/></svg>`;
    const esc = value => String(value ?? '').replace(/[&<>"']/g, character => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[character]));
    const plural = (count, one, many = `${one}s`) => `${Number(count).toLocaleString()} ${Number(count) === 1 ? one : many}`;

    function size(value) {
        if (value == null) return 'Size not known';
        const units = ['B', 'KB', 'MB', 'GB', 'TB'];
        let amount = Number(value), unit = 0;
        while (amount >= 1024 && unit < units.length - 1) { amount /= 1024; unit++; }
        return `${amount.toLocaleString(undefined, {maximumFractionDigits: unit > 1 ? 1 : 0})} ${units[unit]}`;
    }
    const clock = value => new Date(value).toLocaleTimeString([], {hour: '2-digit', minute: '2-digit', hour12: false});
    const sameDay = (a, b) => a.toDateString() === b.toDateString();
    const savedLine = (craft, now) => {
        const when = shortTime(craft.last_save_at, now);
        return `${craft.name} saved ${/^\d/.test(when) ? 'at ' : ''}${when}`;
    };
    function shortTime(value, now = new Date()) {
        const date = new Date(value);
        return sameDay(date, now) ? clock(date) : date.toLocaleDateString([], {weekday: 'short'});
    }
    function dayAndTime(value, now = new Date()) {
        const date = new Date(value), yesterday = new Date(now);
        yesterday.setDate(now.getDate() - 1);
        if (sameDay(date, now)) return clock(date);
        if (sameDay(date, yesterday)) return `yesterday ${clock(date)}`;
        return `${date.toLocaleDateString([], {weekday: 'short'})} ${clock(date)}`;
    }
    function keycap(name) {
        const known = {Codex: 'CX', Blender: 'BL'};
        const capitals = name.replace(/[^A-Z]/g, '');
        return known[name] || (capitals.length >= 2 ? capitals.slice(0, 2) : name.slice(0, 2).toUpperCase());
    }

    function create({root, request, signal, state}) {
        const $ = selector => root.querySelector(selector);
        const data = root.dataset;
        // Signal and Trending are the studio's own feeds: only a full-access account reads them.
        const admin = data.superuser === '1';
        const reducedMotion =() => window.matchMedia?.('(prefers-reduced-motion: reduce)').matches === true;
        let summary = null, flapped = false;
        const feed = {stories: [], waiting: [], trending: [], answeredAt: null, failedAt: null, empty: false, loaded: false};

        function cellsHtml(model) {
            return COLUMNS.map((column, index) => {
                const cells = state.boardCells({text: model[column.key], width: column.width, align: column.align});
                const gap = index < COLUMNS.length - 1 ? '<i class="gap"></i>' : '';
                return `<span class="hm-k ${column.name}" style="--n:${column.width + (gap ? 1 : 0)}">${cells.map(cell => `<i>${cell === ' ' ? '' : esc(cell)}</i>`).join('')}${gap}</span>`;
            }).join('');
        }
        const rowHtml = model => `<div class="hm-frow is-${model.tone}" role="listitem"><span class="hm-sr">${esc(model.sentence)}</span><span class="hm-cells" aria-hidden="true">${cellsHtml(model)}</span></div>`;

        function paintLoading() {
            const blank = {tone: 'seen', time: '', from: '', cargo: '', quantity: '', status: '', sentence: ''};
            $('[data-board-rows]').innerHTML = Array.from({length: 3}, () => rowHtml(blank)).join('');
            $('[data-board-state]').textContent = 'Reading the log';
            $('[data-deck]').innerHTML = '<div class="hm-fig is-loading" aria-hidden="true">–</div><div><h2>Checking what came aboard</h2><p>This takes a moment.</p></div>';
        }

        function paintBoard() {
            const board = summary.board, lead = summary.lead;
            const head = $('[data-board-state]'), rows = $('[data-board-rows]'), more = $('[data-board-more]');
            $('[data-board]').removeAttribute('aria-busy');
            const now = new Date(summary.generated_at);
            const time = clock(now);
            const lamp = {new: 'y', 'nothing-new': 'idle', 'first-run': 'off'}[lead.state];
            const word = {new: `New · ${time}`, 'nothing-new': `Quiet · ${time}`, 'first-run': 'No craft docked'}[lead.state];
            head.innerHTML = `<i class="hm-lamp ${lamp}"></i>${esc(word)}`;
            const models = board.rows.map(row => state.boardRow({row, time: shortTime(row.at, now)}));
            rows.innerHTML = models.length
                ? models.map(rowHtml).join('')
                : rowHtml({tone: 'seen', time: '--:--', from: 'No arrivals', cargo: 'Connect a craft', quantity: '--', status: '--', sentence: 'No arrivals yet. Connect a craft and what it makes lands here.'});
            more.hidden = board.more <= 0;
            if (board.more > 0) {
                const since = lead.since ? ` since ${dayAndTime(lead.since, now)}` : '';
                more.innerHTML = `<a href="#hm-cargo">+ ${board.more.toLocaleString()}${board.truncated ? ' or more' : ' more'}${esc(since)} · see Cargo</a>`;
            }
            if (!flapped) { flapped = true; flap(rows); }
        }

        // Cells flip in reading order, once, when Home opens. With reduced motion the final value is simply there.
        function flap(rows) {
            if (reducedMotion() || typeof Element.prototype.animate !== 'function') return;
            const cells = [...rows.querySelectorAll('.hm-k i:not(.gap)')].filter(cell => cell.textContent);
            const finals = cells.map(cell => cell.textContent);
            const restore = () => cells.forEach((cell, index) => { cell.textContent = finals[index]; });
            try {
                const animations = cells.map((cell, index) => {
                    const passing = 3 + ((index * 7 + 3) % 4), steps = passing + 1;
                    const strip = document.createElement('b');
                    strip.className = 'hm-strip';
                    strip.append(document.createElement('u'));
                    for (let step = 0; step < passing; step++) {
                        const glyph = document.createElement('u');
                        glyph.textContent = GLYPHS[(step * 11 + index * 5 + 1) % GLYPHS.length];
                        strip.append(glyph);
                    }
                    const landing = document.createElement('u');
                    landing.textContent = finals[index];
                    strip.append(landing);
                    cell.replaceChildren(strip);
                    return strip.animate([{transform: 'translateY(0)'}, {transform: `translateY(${-steps * 100}%)`}], {duration: steps * FLAP_STEP_MS, delay: 80 + index * FLAP_COLUMN_MS, easing: `steps(${steps}, jump-start)`, fill: 'both'});
                });
                Promise.allSettled(animations.map(animation => animation.finished)).then(restore);
            } catch { restore(); }
        }

        function paintDeck() {
            const deck = $('[data-deck]'), lead = summary.lead;
            deck.removeAttribute('aria-busy');
            deck.classList.toggle('is-calm', lead.state !== 'new');
            if (lead.state === 'new') {
                const files = lead.files, label = files === 1 ? 'new file came aboard' : 'new files came aboard';
                const sentence = state.deckSentence({rows: summary.board.rows, files, size: size(lead.bytes)});
                deck.innerHTML = `<div class="hm-fig" aria-hidden="true">${files.toLocaleString()}</div><div><h2><span class="hm-sr">${files.toLocaleString()} </span>${label}</h2><p>${esc(sentence)}</p></div><a class="hm-btn hm-act" href="${esc(data.library)}">Review ${plural(files, 'new file')}${icon('arrow')}</a>`;
                return;
            }
            if (lead.state === 'first-run') {
                deck.innerHTML = `<div class="hm-fig" aria-hidden="true">0</div><div><h2>no craft docked yet</h2><p>Connect ArtCraft, Blender or an agent, and what they make lands here.</p></div>${data.connect ? `<a class="hm-btn hm-act" href="${esc(data.connect)}">Connect a craft${icon('arrow')}</a>` : ''}`;
                return;
            }
            const newest = summary.board.rows.find(row => row.at);
            const quiet = newest ? `Your craft have been quiet since ${esc(dayAndTime(newest.at, new Date(summary.generated_at)))}.` : 'Your craft have been quiet.';
            deck.innerHTML = `<div class="hm-fig" aria-hidden="true">0</div><div><h2>nothing new came aboard</h2><p>${quiet}</p></div><a class="hm-btn" href="${esc(data.library)}">Open the Library${icon('arrow')}</a>`;
        }

        function paintNeeds() {
            const card = $('[data-needs]'), items = summary.needs_you.items || [];
            card.hidden = !items.length;
            const rows = {
                'failed-uploads': count => ({text: `<b>${plural(count, 'file')}</b> did not finish uploading.`, label: 'See new uploads', url: data.newUploads}),
                'expiring-shares': count => ({text: `<b>${plural(count, 'share link')}</b> ${count === 1 ? 'expires' : 'expire'} within a week.`, label: 'Open shared links', url: data.shares}),
                'expired-upload-requests': count => ({text: `<b>${plural(count, 'upload request')}</b> ${count === 1 ? 'has' : 'have'} expired.`, label: 'Open upload requests', url: data.requests}),
            };
            card.innerHTML = items.map(item => {
                const row = rows[item.kind]?.(item.count);
                return row ? `<div class="hm-need"><i class="hm-lamp r"></i><p>${row.text}</p>${row.url ? `<a class="hm-btn" href="${esc(row.url)}">${esc(row.label)}</a>` : ''}</div>` : '';
            }).join('');
        }

        const shelfSize = seen => {
            const phone = window.matchMedia?.(PHONE).matches;
            return {
                room: Math.max(0, $('[data-cargo]').clientWidth - (phone ? 22 : 74)),
                tile: seen ? (phone ? 110 : 150) : (phone ? 150 : 220),
                least: seen ? (phone ? 90 : 135) : (phone ? 112 : 198),
                moreTile: phone ? 56 : 64,
                gap: 10,
            };
        };
        function shelf(row, seen) {
            const previews = row.previews || [];
            if (!previews.length) return '';
            const size = shelfSize(seen);
            const plan = state.shelfPlan({files: row.files, previews: previews.length, ...state.shelfFit(size)});
            const pictures = previews.slice(0, plan.pictures).map(picture => `<a class="hm-pic" href="${esc(picture.url)}" title="${esc(picture.name)}"><img src="${esc(picture.thumbnail)}" alt="${esc(picture.name)}" loading="lazy" data-kind="${esc(String(picture.name).split('.').pop())}"></a>`).join('');
            const tile = plan.more > 0 ? `<a class="hm-pic hm-pic-more" href="${esc(data.library)}" aria-label="${esc(`${plural(plan.more, 'more file')} in this landing. Open the Library`)}"><span>+${plan.more.toLocaleString()}</span></a>` : '';
            const room = state.shelfFit(size).withMore;
            const width = Math.floor(Math.min(size.tile, (size.room - size.moreTile - size.gap * room) / room));
            const columns = `--tile:${width}px${plan.more > 0 ? `;--pics:${plan.pictures};--more-tile:${size.moreTile}px` : ''}`;
            return `<div class="hm-shelf${seen ? ' is-seen' : ''}${plan.more > 0 ? ' has-more' : ''}" style="${columns}">${pictures}${tile}</div>`;
        }
        function landing(row, now) {
            const seen = row.status === 'seen';
            const from = row.source === 'craft' ? state.craftName(row.producer) : (row.mine ? 'You' : 'Upload');
            const kinds = (row.kinds || []).map(kind => `${kind.count} .${kind.extension}`).join(' + ');
            const parts = [`<b>${esc(from)}</b>`, seen ? esc(clock(row.at)) : '', kinds ? esc(kinds) : '', row.bytes != null ? esc(size(row.bytes)) : ''].filter(Boolean);
            const count = seen ? '' : row.status === 'partial' ? `${row.files} of ${row.files + row.failed_files} saved` : `${row.files} new`;
            const issue = row.status === 'partial' ? `<div class="hm-issue"><p>${esc(plural(row.failed_files, 'file'))} did not save. The rest are aboard.</p>${data.newUploads ? `<a class="hm-btn" href="${esc(data.newUploads)}">See new uploads</a>` : ''}</div>` : '';
            return `<li class="hm-land${seen ? ' is-seen' : ''}"><span class="hm-t">${esc(shortTime(row.at, now))}</span><i class="hm-lamp ${seen ? 'seen' : row.status === 'partial' ? 'r' : 'y'}"></i><div class="hm-land-body"><div class="hm-lh"><h3 title="${esc(row.title)}">${esc(row.title)}</h3>${count ? `<span class="hm-cnt">${esc(count)}</span>` : ''}</div><p class="hm-meta">${parts.map(part => `<span>${part}</span>`).join('')}</p>${shelf(row, seen)}${issue}</div></li>`;
        }
        function journey(row, now) {
            const failed = row.status === 'failed', processing = row.phase === 'processing';
            const title = failed ? 'Upload failed' : processing ? 'Processing upload' : 'Uploading files';
            const word = failed ? `${row.failed_files} of ${row.files} failed` : processing ? 'Processing' : 'Uploading';
            const percent = row.bytes ? Math.min(100, Math.round((row.received_bytes / row.bytes) * 100)) : null;
            const meta = [`<b>You</b>`, 'upload', plural(row.files, 'file'), !failed && row.bytes ? `${size(row.received_bytes)} of ${size(row.bytes)}` : ''].filter(Boolean);
            const bar = !failed && !processing && percent !== null ? `<div class="hm-prog" role="progressbar" aria-valuenow="${percent}" aria-valuemin="0" aria-valuemax="100" aria-label="Upload progress"><i style="width:${percent}%"></i></div>` : '';
            const waiting = processing ? `<div class="hm-shelf">${Array.from({length: Math.min(3, Math.max(1, row.files), state.shelfFit(shelfSize(false)).full)}, () => `<span class="hm-pic is-wait"><span>${icon('loader')}Processing</span></span>`).join('')}</div>` : '';
            const issue = failed ? `<div class="hm-issue"><p>${esc(plural(row.failed_files, 'file'))} did not reach the ship.</p>${data.newUploads ? `<a class="hm-btn" href="${esc(data.newUploads)}">See new uploads</a>` : ''}</div>` : '';
            return `<li class="hm-land"><span class="hm-t">${esc(shortTime(row.at, now))}</span><i class="hm-lamp ${failed ? 'r' : 'y'}"></i><div class="hm-land-body"><div class="hm-lh"><h3>${esc(title)}</h3><span class="hm-cnt">${esc(word)}</span></div><p class="hm-meta">${meta.map(part => `<span>${part}</span>`).join('')}</p>${bar}${waiting}${issue}</div></li>`;
        }
        function paintCargo() {
            const list = $('[data-cargo]'), section = $('[data-cargo-section]'), now = new Date(summary.generated_at);
            const moving = summary.board.rows.filter(row => ['stowing', 'failed'].includes(row.status));
            const cargo = summary.board.cargo || [], fresh = cargo.filter(row => row.status !== 'seen'), seen = cargo.filter(row => row.status === 'seen');
            section.hidden = !moving.length && !cargo.length;
            list.hidden = section.hidden;
            list.innerHTML = [
                ...moving.map(row => journey(row, now)),
                ...fresh.map(row => landing(row, now)),
                seen.length && (fresh.length || moving.length) ? '<li class="hm-divider"><span>Before your last visit</span></li>' : '',
                ...seen.map(row => landing(row, now)),
            ].join('');
            list.querySelectorAll('img').forEach(image => image.addEventListener('error', () => {
                const label = document.createElement('span');
                label.className = 'hm-pic-kind';
                label.textContent = `.${image.dataset.kind || 'file'}`;
                image.replaceWith(label);
            }, {once: true}));
        }

        function paintDocked() {
            const card = $('[data-docked]'), docked = summary.board.docked || [], now = new Date(summary.generated_at);
            if (!docked.length) {
                card.innerHTML = `<div class="hm-ch"><h2>Docked</h2></div><p class="hm-empty-title">No craft docked</p><p class="hm-empty">Connect ArtCraft, Blender or an agent, and what they make lands here.</p>${data.connect ? `<a class="hm-btn hm-wide" href="${esc(data.connect)}">Set up</a>` : ''}`;
                return;
            }
            const rows = docked.map(craft => {
                const name = state.craftName(craft.producer), peak = Math.max(1, ...craft.hours);
                const bars = craft.hours.map(hours => `<i${hours ? ' class="on"' : ''} style="--h:${hours ? Math.max(4, Math.round((hours / peak) * 28)) : 2}px"></i>`).join('');
                return `<li><span class="hm-key">${esc(keycap(name))}</span><div><div class="hm-nm">${esc(name)}</div><div class="hm-st">Last save · ${esc(shortTime(craft.last_save_at, now))}</div></div><div class="hm-trace" role="img" aria-label="${esc(`${name} saved ${plural(craft.files_24h, 'file')} in the last 24 hours`)}">${bars}</div></li>`;
            }).join('');
            card.innerHTML = `<div class="hm-ch"><h2>Docked</h2><span class="hm-mono hm-r">${esc(plural(docked.length, 'craft', 'craft'))}</span></div><ul>${rows}</ul><p class="hm-axis">Saves, last 24 hours</p>`;
        }

        function paintAway() {
            const card = $('[data-away]'), away = summary.away;
            const figure = source => source.available ? Number(source.count).toLocaleString() : '–';
            const quiet = away.shares.available && away.upload_requests.available && away.shares.count === 0 && away.upload_requests.count === 0;
            if (quiet) {
                card.innerHTML = '<p class="hm-empty-title">Nothing away from ship</p><p class="hm-empty">No live links or open requests. Transfers are not counted here.</p>';
                return;
            }
            const expiring = (summary.needs_you.items || []).find(item => item.kind === 'expiring-shares');
            card.innerHTML = `<div class="hm-ch"><h2>Away from ship</h2></div><div class="hm-figs"><div><b>${figure(away.shares)}</b><span class="hm-mono">Links live</span></div><div><b>${figure(away.upload_requests)}</b><span class="hm-mono">Requests open</span></div><div><b aria-label="Not counted">–</b><span class="hm-mono">In flight</span></div></div><p class="hm-note">${expiring ? esc(`${plural(expiring.count, 'link')} ${expiring.count === 1 ? 'expires' : 'expire'} within a week.`) : 'Transfers run person to person, so they are not counted here.'}</p>`;
        }

        function paintGlance() {
            const nav = $('[data-glance]'), readable = Boolean(summary.board?.rows), docked = summary.board?.docked || [];
            const newest = docked[0], story = feed.stories[0];
            const away = summary.away, links = away.shares.available ? plural(away.shares.count, 'link') : 'Links not available';
            nav.innerHTML = `${readable ? `<button type="button" data-glance-open="docked"><span><b>${docked.length ? esc(plural(docked.length, 'craft', 'craft')) : 'No craft'}</b> docked<small>${newest ? esc(savedLine({name: state.craftName(newest.producer), last_save_at: newest.last_save_at}, new Date(summary.generated_at))) : 'Nothing has saved here yet'}</small></span>${icon('chevron')}</button>` : ''}${feed.loaded ? `<button type="button" data-glance-open="signal"><span class="hm-ell"><b>Signal</b><small class="hm-ell">${esc(story?.title || (feed.failedAt ? 'Not answering' : 'No stories yet'))}</small></span>${icon('chevron')}</button>` : ''}<button type="button" data-glance-open="away"><span><b>${esc(links)}</b> away from ship<small>Shared links and upload requests</small></span>${icon('chevron')}</button>`;
        }

        function paintSummary() {
            if (!summary.lead?.available || !summary.board?.rows) { paintLocked(); return; }
            paintBoard(); paintDeck(); paintNeeds(); paintCargo(); paintDocked(); paintAway(); paintGlance();
        }
        // A role without the library has no board to read.
        function paintLocked() {
            $('[data-board]').removeAttribute('aria-busy');
            $('[data-deck]').removeAttribute('aria-busy');
            $('[data-board-object]').hidden = true;
            $('[data-cargo-section]').hidden = true;
            $('[data-cargo]').hidden = true;
            $('[data-docked]').hidden = true;
            paintNeeds(); paintAway(); paintGlance();
        }
        function paintFailure(error) {
            $('[data-board]').removeAttribute('aria-busy');
            $('[data-board-state]').innerHTML = '<i class="hm-lamp r"></i>Not answering';
            const deck = $('[data-deck]');
            deck.removeAttribute('aria-busy');
            deck.classList.add('is-calm');
            deck.innerHTML = `<div class="hm-fig" aria-hidden="true">–</div><div><h2>the board did not load</h2><p role="alert">${esc(error?.message || 'The ship did not answer.')}</p></div><button type="button" class="hm-btn" data-summary-retry>Try again</button>`;
        }

        // A feed writes its own links, so only a web address becomes one.
        const storyRow = story => {
            const body = `<b>${esc(story.title)}</b><span>${esc(story.source || 'Signal')}${story.publishedAt ? ` · ${esc(dayAndTime(story.publishedAt))}` : ''}</span>`;
            return /^https?:\/\//i.test(String(story.url ?? '')) ? `<a class="hm-story" href="${esc(story.url)}" target="_blank" rel="noopener noreferrer">${body}</a>` : `<div class="hm-story">${body}</div>`;
        };
        function paintSignal() {
            const card = $('[data-signal]');
            if (!admin) { card.hidden = true; return; }
            const head = tag => `<div class="hm-ch"><h2>Signal</h2>${tag}</div>`;
            if (!feed.loaded) { card.innerHTML = `${head('')}<p class="hm-empty">Reading your feeds…</p>`; return; }
            const trending = feed.trending.length ? `<h3 class="hm-tr"><span>Trending on GitHub</span><span>Stars today</span></h3>${feed.trending.map(repo => `<a class="hm-repo" href="${esc(`https://github.com/${repo.repo}`)}" target="_blank" rel="noopener noreferrer"><span><b>${esc(repo.repo)}</b><span>${esc(repo.description || 'No description provided.')}</span></span>${repo.starsToday != null ? `<span class="hm-mono">+${new Intl.NumberFormat('en', {notation: 'compact', maximumFractionDigits: 1}).format(Number(repo.starsToday) || 0)}</span>` : ''}</a>`).join('')}` : '';
            if (feed.failedAt) {
                const had = feed.answeredAt ? ` These are the stories from ${esc(clock(feed.answeredAt))}.` : '';
                card.innerHTML = `${head('<span class="hm-tag hm-mono hm-r"><i class="hm-lamp r"></i>Not answering</span>')}<p class="hm-empty">Your Umbrel’s feed adapter did not answer at ${esc(clock(feed.failedAt))}.${had}</p>${feed.stories.map(storyRow).join('')}<button type="button" class="hm-btn hm-wide" data-signal-retry>Try again</button>${trending}`;
                return;
            }
            if (feed.empty) {
                card.innerHTML = `${head('')}<p class="hm-empty-title">No feeds yet</p><p class="hm-empty">Add the first feed in Settings.</p>${trending}`;
                return;
            }
            const waiting = feed.waiting.length ? `<button type="button" class="hm-newrow" data-signal-show><i class="hm-lamp y"></i><span>${esc(plural(feed.waiting.length, 'new story', 'new stories'))}</span><span class="hm-mono">Show</span></button>` : '';
            card.innerHTML = `${head(`<span class="hm-mono hm-r">${feed.answeredAt ? `Updated ${esc(clock(feed.answeredAt))}` : ''}</span>`)}${waiting}${feed.stories.map(storyRow).join('')}${trending}`;
        }
        async function loadSignal({reveal = false} = {}) {
            const stories = request(`/service/rss/v1/stories?limit=${STORY_LIMIT}`);
            const trending = request('/service/trending/v1/trending').then(result => (result.repos || []).slice(0, TRENDING_LIMIT)).catch(() => feed.trending);
            try {
                const result = await stories;
                const incoming = result.stories || [];
                const known = new Set(feed.stories.map(story => story.id));
                // Rows hold still while someone reads: new stories wait behind a row until asked for.
                if (feed.loaded && !reveal && feed.stories.length) feed.waiting = incoming.filter(story => !known.has(story.id));
                else { feed.stories = incoming; feed.waiting = []; }
                feed.empty = !incoming.length;
                feed.answeredAt = new Date();
                feed.failedAt = null;
            } catch { feed.failedAt = new Date(); }
            feed.trending = await trending;
            if (signal.aborted || !root.isConnected) return;
            feed.loaded = true;
            paintSignal();
            if (summary?.away) paintGlance();
        }

        async function loadSummary() {
            const result = await request('/summary');
            if (signal.aborted || !root.isConnected) return result;
            summary = result;
            paintSummary();
            return result;
        }
        const poller = state.poller({interval: SUMMARY_INTERVAL_MS, visible: () => document.visibilityState !== 'hidden', request: loadSummary});
        const refresh = () => poller.refresh().catch(error => { if (!summary) paintFailure(error); });
        const signalPoller = state.poller({interval: SIGNAL_INTERVAL_MS, visible: () => document.visibilityState !== 'hidden', request: () => loadSignal()});

        // On a phone the right column lives in a sheet, opened from the glance rows.
        const side = $('[data-side]'), layout = side.parentElement, sheet = $('[data-side-sheet]'), sheetBody = $('[data-sheet-body]');
        const sections = {docked: '[data-docked]', signal: '[data-signal]', away: '[data-away]'};
        function openGlance(name) {
            const target = $(sections[name]);
            if (window.matchMedia?.(PHONE).matches) {
                sheetBody.append(side);
                sheet.showModal();
            }
            target?.scrollIntoView({block: 'start', behavior: reducedMotion() ? 'auto' : 'smooth'});
        }
        sheet.addEventListener('close', () => layout.append(side), {signal});
        root.addEventListener('click', event => {
            const glance = event.target.closest('[data-glance-open]');
            if (glance) openGlance(glance.dataset.glanceOpen);
            if (event.target.closest('[data-sheet-close]')) sheet.close();
            if (event.target.closest('[data-summary-retry]')) { paintLoading(); refresh(); }
            if (event.target.closest('[data-signal-retry]')) loadSignal({reveal: true});
            if (event.target.closest('[data-signal-show]')) loadSignal({reveal: true});
        }, {signal});

        // Shelves show as many pictures as fit, so a change of width recounts the "+N" tile.
        let lastFit = null;
        const observer = typeof ResizeObserver === 'function' ? new ResizeObserver(() => {
            const fit = [false, true].map(seen => Object.values(state.shelfFit(shelfSize(seen))).join('/')).join(':');
            if (lastFit !== null && fit !== lastFit && summary?.board?.rows) paintCargo();
            lastFit = fit;
        }) : null;
        observer?.observe($('[data-cargo]'));

        const ticker = setInterval(() => {
            poller.tick().catch(() => {});
            if (admin) signalPoller.tick().catch(() => {});
        }, TICK_MS);
        signal.addEventListener('abort', () => { clearInterval(ticker); observer?.disconnect(); }, {once: true});

        return {
            start() {
                paintLoading();
                paintSignal();
                refresh();
                if (admin) signalPoller.refresh().catch(() => {});
            },
            refresh,
        };
    }

    window.MothershipHomeBridge = {create};
})();
