(() => {
    'use strict';
    // Signal: the studio's stories and what is trending on GitHub, in one card beside Home's board.
    // Rows hold still while someone reads them. What arrives docks, swaps or fades, and stays quieter than the board.
    const STORY_LIMIT = 4;
    const TRENDING_LIMIT = 3;
    const SIGNAL_INTERVAL_MS = 300000;
    const LABEL_HOLD_MS = 1800;
    const NOTE_HOLD_MS = 6000;
    const QUIET_ARRIVALS = 3;
    const MOTION = {
        dock: 560, base: 240, quick: 140, exit: 160, beat: 90, lag: 90, stagger: 32, staggerCap: 6, travel: 16, step: 8, nudge: 4,
        spring: 'linear(0, 0.03, 0.1037, 0.2018, 0.3101, 0.4191, 0.5223, 0.6161, 0.6985, 0.7689, 0.8275, 0.8752, 0.9132, 0.9427, 0.9651, 0.9816, 0.9933, 1.0013, 1.0064, 1.0094, 1.0108, 1.0111, 1.0107, 1.0098, 1.0087, 1.0075, 1.0063, 1.0051, 1.0041, 1.0032, 1.0025, 1.0018, 1)',
        glide: 'cubic-bezier(.16, 1, .3, 1)',
        leave: 'cubic-bezier(.4, 0, 1, 1)',
    };
    const ICONS = {
        chevron: 'm9 18 6-6-6-6',
        out: 'M7 7h10v10M7 17 17 7',
        close: 'M18 6 6 18M6 6l12 12',
    };
    const icon = name => `<svg class="hm-ic" viewBox="0 0 24 24" aria-hidden="true"><path d="${ICONS[name]}"/></svg>`;
    const compact = new Intl.NumberFormat('en', {notation: 'compact', maximumFractionDigits: 1});
    const dayLabel = value => {
        const [year, month, day] = value.split('-').map(Number);
        return new Date(year, month - 1, day).toLocaleDateString(undefined, {month: 'short', day: 'numeric'});
    };

    const reducedMotion = () => window.matchMedia?.('(prefers-reduced-motion: reduce)').matches === true;
    const canAnimate = () => typeof Element.prototype.animate === 'function' && document.visibilityState !== 'hidden';
    const play = (element, frames, options) => (canAnimate() ? element.animate(frames, {fill: 'backwards', ...options}) : null);
    const settled = animation => (animation ? animation.finished.catch(() => {}) : Promise.resolve());
    const staggered = index => Math.min(index, MOTION.staggerCap - 1) * MOTION.stagger;
    const fade = (element, {delay = 0} = {}) => play(element, [{opacity: 0}, {opacity: 1}], {duration: MOTION.base, delay, easing: MOTION.glide});
    function dock(element, {delay = 0} = {}) {
        if (reducedMotion()) return fade(element, {delay});
        return play(element, [{transform: `translateX(${MOTION.travel}px)`, opacity: 0, offset: 0}, {opacity: 1, offset: .35}, {transform: 'none', opacity: 1}], {duration: MOTION.dock, delay, easing: MOTION.spring});
    }
    function rise(element, {delay = 0, distance = MOTION.nudge} = {}) {
        if (reducedMotion()) return fade(element, {delay});
        return play(element, [{transform: `translateY(${distance}px)`, opacity: 0}, {transform: 'none', opacity: 1}], {duration: MOTION.base, delay, easing: MOTION.glide});
    }
    const fadeOut = element => play(element, [{opacity: 1}, {opacity: 0}], {duration: MOTION.exit, easing: MOTION.leave, fill: 'forwards'});

    // A value changes only the characters that differ, so 14:06 to 14:11 moves two digits.
    function swapValue(element, next) {
        const before = element.textContent;
        if (before === next) return;
        if (!canAnimate() || before.length !== next.length) {
            element.textContent = next;
            if (before) fade(element);
            return;
        }
        element.replaceChildren(...[...next].map((character, index) => {
            if (character === before[index]) return document.createTextNode(character);
            const changed = document.createElement('span');
            changed.className = 'hm-sw';
            changed.textContent = character;
            return changed;
        }));
        element.querySelectorAll('.hm-sw').forEach((changed, index) => rise(changed, {delay: staggered(index)}));
    }

    const unsettle = new WeakMap();
    // Rows that stay keep their element, so a row never loses focus. They travel from where they were, a new row
    // docks in, and a row that left goes out through the bottom of the list.
    function placeRows({list, rows, animate}) {
        unsettle.get(list)?.();
        const current = new Map([...list.children].map(element => [element.dataset.key, element]));
        const moving = animate && canAnimate();
        const tops = new Map(moving ? [...current].map(([key, element]) => [key, element.getBoundingClientRect().top]) : []);
        const before = moving ? list.getBoundingClientRect().height : 0;
        const template = document.createElement('template');
        const nodes = rows.map(row => {
            template.innerHTML = row.html;
            const element = template.content.firstElementChild;
            element.dataset.key = row.key;
            const old = current.get(row.key);
            return old?.outerHTML === element.outerHTML ? old : element;
        });
        const keys = new Set(rows.map(row => row.key));
        const leaving = [...current].filter(([key]) => !keys.has(key)).map(([, element]) => element);
        const focusedKey = list.contains(document.activeElement) ? document.activeElement.closest('[data-key]')?.dataset.key : undefined;
        // A row already in its place is left alone, because taking a focused row out of the page drops the reader's focus.
        nodes.forEach((element, index) => { if (list.children[index] !== element) list.insertBefore(element, list.children[index] ?? null); });
        while (list.children.length > nodes.length) list.lastElementChild.remove();
        const focused = focusedKey === undefined ? null : nodes[rows.findIndex(row => row.key === focusedKey)];
        if (focused && document.activeElement !== focused) focused.focus({preventScroll: true});
        const fresh = rows.filter(row => !current.has(row.key));
        if (!moving || fresh.length > QUIET_ARRIVALS) return;

        const after = list.getBoundingClientRect().height;
        const travelling = nodes.map((element, index) => ({element, key: rows[index].key})).filter(({key}) => tops.has(key)).map(({element, key}) => ({element, from: tops.get(key) - element.getBoundingClientRect().top}));
        if (leaving.length && !reducedMotion()) {
            list.append(...leaving);
            list.style.height = `${Math.max(before, after)}px`;
            list.style.overflow = 'hidden';
            leaving.forEach(element => travelling.push({element, from: tops.get(element.dataset.key) - element.getBoundingClientRect().top}));
        }
        const animations = reducedMotion() ? [] : travelling.filter(({from}) => from).map(({element, from}) => play(element, [{transform: `translateY(${from}px)`}, {transform: 'none'}], {duration: MOTION.dock, easing: MOTION.spring}));
        nodes.filter((element, index) => !current.has(rows[index].key)).forEach((element, index) => animations.push(dock(element, {delay: (reducedMotion() ? 0 : MOTION.lag) + staggered(index)})));
        let done = false;
        const finish = () => {
            if (done) return;
            done = true;
            unsettle.delete(list);
            leaving.forEach(element => element.remove());
            list.style.height = '';
            list.style.overflow = '';
        };
        unsettle.set(list, finish);
        Promise.all(animations.map(settled)).then(finish);
    }

    function create({root, request, signal, state, format, onChange}) {
        const {esc, plural, clock, dayAndTime} = format;
        const card = root.querySelector('[data-signal]');
        const $ = selector => card.querySelector(selector);
        const admin = root.dataset.superuser === '1';
        const feed = {stories: [], waiting: [], answeredAt: null, failedAt: null, empty: false, loaded: false};
        const trend = {today: null, pending: null, error: null, days: new Map(), dayErrors: new Map(), selected: null, loadingDay: null, all: false, busy: false, swap: 0, run: 0};
        let noteTimer = null, labelTimer = null, dialogRun = 0;

        let dialog = root.querySelector('[data-trend-dialog]');
        if (!dialog) {
            dialog = document.createElement('dialog');
            dialog.className = 'hm-trend';
            dialog.dataset.trendDialog = '';
            dialog.setAttribute('aria-labelledby', 'hm-trend-title');
            root.append(dialog);
        }

        const reading = ({ignoreHover = false} = {}) => (!ignoreHover && card.matches(':hover')) || card.contains(document.activeElement) || dialog.open || Boolean(card.closest('dialog[open]'));

        // A feed writes its own links, so only a web address becomes one.
        const storyRow = story => {
            const body = `<b>${esc(story.title)}</b><span>${esc(story.source || 'Signal')}${story.publishedAt ? ` · ${esc(dayAndTime(story.publishedAt))}` : ''}</span>`;
            return /^https?:\/\//i.test(String(story.url ?? '')) ? `<a class="hm-story" href="${esc(story.url)}" target="_blank" rel="noopener noreferrer">${body}</a>` : `<div class="hm-story">${body}</div>`;
        };
        const storyRows = () => feed.stories.map(story => ({key: String(story.id), html: storyRow(story)}));

        function paintSlot() {
            const slot = $('[data-signal-slot]');
            let kind = 'none';
            if (feed.loaded && feed.failedAt) kind = 'failed';
            else if (feed.loaded && !feed.empty && feed.waiting.length) kind = `waiting-${feed.waiting.length}`;
            else if (feed.loaded && !feed.empty && feed.answeredAt) kind = 'updated';
            const changed = slot.dataset.kind !== kind;
            slot.dataset.kind = kind;
            if (kind === 'updated') {
                const time = `Updated ${clock(feed.answeredAt)}`;
                if (changed) { slot.innerHTML = `<span class="hm-mono" data-signal-updated>${esc(time)}</span>`; }
                else swapValue($('[data-signal-updated]'), time);
            } else if (changed) {
                slot.innerHTML = {
                    none: '',
                    failed: '<span class="hm-tag hm-mono"><i class="hm-lamp r"></i>Not answering</span>',
                }[kind] ?? `<button type="button" class="hm-newrow" data-signal-show><i class="hm-lamp y"></i><span>${esc(plural(feed.waiting.length, 'new story', 'new stories'))}</span><span class="hm-mono">Show</span></button>`;
            }
            if (changed && kind !== 'none' && feed.answeredAt) fade(slot);
        }

        function paintBody({first = false, arrivals = false} = {}) {
            const body = $('[data-signal-body]');
            if (!feed.loaded) { body.innerHTML = '<p class="hm-empty">Reading your feeds…</p>'; return; }
            if (feed.empty && !feed.failedAt) { body.innerHTML = '<p class="hm-empty-title">No feeds yet</p><p class="hm-empty">Add the first feed in Settings.</p>'; return; }
            if (!$('[data-signal-list]')) body.innerHTML = '<p class="hm-empty" data-signal-note hidden></p><div class="hm-stories" data-signal-list></div><div data-signal-again></div>';
            const note = $('[data-signal-note]'), again = $('[data-signal-again]');
            note.hidden = !feed.failedAt;
            note.textContent = feed.failedAt ? `Your Umbrel’s feed adapter did not answer at ${clock(feed.failedAt)}.${feed.answeredAt ? ` These are the stories from ${clock(feed.answeredAt)}.` : ''}` : '';
            again.innerHTML = feed.failedAt ? '<button type="button" class="hm-btn hm-wide" data-signal-retry>Try again</button>' : '';
            const list = $('[data-signal-list]');
            placeRows({list, rows: storyRows(), animate: arrivals});
            if (first) [...list.children].forEach((row, index) => rise(row, {delay: staggered(index)}));
        }

        const trendView = () => (trend.selected ? trend.days.get(trend.selected) : trend.today);
        const latest = () => trend.pending || trend.today;
        const outOfDate = () => Boolean(trend.error) || ['error', 'stale'].includes(latest()?.status);

        function repoRow({repo, index, live}) {
            const off = live && repo.onList === false;
            const stars = repo.starsToday != null ? `<span class="hm-mono">+${esc(compact.format(Number(repo.starsToday) || 0))}</span>` : '';
            return `<button type="button" class="hm-repo${off ? ' is-off' : ''}" data-trend-repo="${index}" aria-haspopup="dialog"><span><b>${esc(repo.repo)}</b><span>${off ? 'No longer on the list · ' : ''}${esc(repo.description || 'No description provided.')}</span></span>${stars}</button>`;
        }

        function writeTrendBody({animate = false, first = false} = {}) {
            const body = $('[data-trend-body]'), view = trendView();
            const say = text => { body.innerHTML = `<p class="hm-tr-note">${esc(text)}</p>`; };
            const all = $('[data-trend-all]');
            all.hidden = true;
            if (!trend.today && !trend.selected) { say(trend.error ? 'Trending is offline. No saved repositories are available yet.' : 'Reading what is trending…'); return; }
            if (trend.selected && trend.loadingDay === trend.selected) { say('Reading that day…'); return; }
            if (trend.selected && !view) { say(trend.dayErrors.get(trend.selected) || 'This day is not available.'); return; }
            const repos = view.repos || [];
            if (!repos.length) { say(trend.selected ? 'No repositories were recorded that day.' : 'No repositories yet today. The list updates every two hours.'); return; }
            if (!$('[data-trend-list]')) body.innerHTML = '<div class="hm-repos" data-trend-list></div>';
            const list = $('[data-trend-list]'), shown = trend.all ? repos : repos.slice(0, TRENDING_LIMIT);
            placeRows({list, rows: shown.map((repo, index) => ({key: repo.repo, html: repoRow({repo, index, live: !trend.selected})})), animate});
            if (first) [...list.children].forEach((row, index) => rise(row, {delay: staggered(index + STORY_LIMIT)}));
            all.hidden = repos.length <= TRENDING_LIMIT;
            all.textContent = trend.all ? 'Show fewer' : `Show all ${repos.length.toLocaleString()}`;
        }

        function paintTrendControls() {
            const today = trend.today;
            const word = trend.selected ? 'Stars that day' : !today ? '' : outOfDate() ? 'Out of date' : 'Stars today';
            const label = $('[data-trend-word]');
            if (label.textContent !== word) { label.textContent = word; fade(label); }

            const days = (today?.days || []).filter(day => day.date !== today.date);
            const picker = $('[data-trend-days]'), select = $('[data-trend-day]'), soon = $('[data-trend-soon]');
            picker.hidden = !days.length;
            soon.hidden = Boolean(days.length) || !today;
            const options = days.map(day => day.date).join(',');
            if (select.dataset.days !== options) {
                select.dataset.days = options;
                select.innerHTML = `<option value="">Today</option>${days.map(day => `<option value="${esc(day.date)}">${esc(dayLabel(day.date))}</option>`).join('')}`;
            }
            select.value = trend.selected || '';

            const button = $('[data-trending-refresh]');
            button.hidden = Boolean(trend.selected);
            button.querySelector('[data-l="rest"]').textContent = today ? 'Refresh' : 'Retry';
            button.querySelector('[data-l="busy"]').textContent = today ? 'Refreshing…' : 'Retrying…';
        }

        function paintTrend(options = {}) {
            $('[data-trend]').hidden = !feed.loaded;
            if (!feed.loaded) return;
            paintTrendControls();
            writeTrendBody(options);
        }

        // Another day: the list goes out, a beat, the day comes in, and the card keeps its height meanwhile.
        async function swapTrendBody() {
            const body = $('[data-trend-body]'), run = ++trend.swap;
            paintTrendControls();
            if (!canAnimate()) { body.style.minHeight = ''; writeTrendBody(); return; }
            body.style.minHeight = `${body.offsetHeight}px`;
            await settled(fadeOut(body));
            if (run !== trend.swap || signal.aborted) return;
            body.getAnimations().forEach(animation => animation.cancel());
            writeTrendBody();
            rise(body, {delay: MOTION.beat});
            if (!trend.selected || trend.loadingDay !== trend.selected) body.style.minHeight = '';
        }

        async function showDay(date) {
            trend.selected = date || null;
            trend.all = false;
            if (date && !trend.days.has(date)) {
                trend.dayErrors.delete(date);
                trend.loadingDay = date;
                swapTrendBody();
                try { trend.days.set(date, await request(`/service/trending/v1/trending/${encodeURIComponent(date)}`)); }
                catch (error) { trend.dayErrors.set(date, error.message); }
                if (trend.loadingDay === date) trend.loadingDay = null;
                if (signal.aborted || trend.selected !== date) return;
            }
            swapTrendBody();
        }

        function setNote({text = '', error = false} = {}) {
            clearTimeout(noteTimer);
            const note = $('[data-refresh-status]');
            note.textContent = text;
            note.classList.toggle('is-error', error);
            if (text) fade(note);
            if (text && !error) noteTimer = setTimeout(() => setNote(), NOTE_HOLD_MS);
        }

        // Feedback stays in the button: the old label goes out, a beat, the new one comes in, and the width never changes.
        function setRefreshState(next) {
            const button = $('[data-trending-refresh]'), before = button.dataset.state;
            clearTimeout(labelTimer);
            if (next === 'done') labelTimer = setTimeout(() => setRefreshState('rest'), LABEL_HOLD_MS);
            if (before === next) return;
            button.dataset.state = next;
            button.setAttribute('aria-busy', String(next === 'busy'));
            button.setAttribute('aria-disabled', String(next === 'busy'));
            const leaving = button.querySelector(`[data-l="${before}"]`), coming = button.querySelector(`[data-l="${next}"]`);
            play(leaving, [{visibility: 'visible', opacity: 1}, {visibility: 'visible', opacity: 0}], {duration: MOTION.quick, easing: MOTION.leave});
            play(coming, [{opacity: 0}, {opacity: 1}], {duration: MOTION.base, delay: MOTION.quick + MOTION.beat, easing: MOTION.glide});
        }

        // A plain read only returns the adapter's cache, so the button asks the adapter to fetch GitHub now.
        async function forceRefresh() {
            if (trend.busy) return;
            trend.busy = true;
            trend.run++;
            setNote();
            setRefreshState('busy');
            let finalState = 'rest';
            try {
                const data = await request('/service/trending/v1/trending?refresh=1');
                trend.today = data;
                trend.pending = null;
                // A throttled answer rereads the cache, and that cache can itself be a failed snapshot.
                if (data.status === 'error') setNote({text: 'GitHub is still unreachable.', error: true});
                else {
                    trend.error = null;
                    if (data.forced) finalState = 'done';
                    else {
                        const minutes = Math.max(1, Math.round((Number(data.throttledUntil) - Date.now()) / 60000));
                        setNote({text: `Fresh${data.refreshedAt ? ` as of ${clock(data.refreshedAt)}` : ''}${Number.isFinite(minutes) ? `, next check in ${minutes} min` : ''}`});
                    }
                }
            } catch (error) {
                trend.error = error.message;
                setNote({text: error.message, error: true});
            }
            trend.busy = false;
            trend.run++;
            if (signal.aborted || !root.isConnected) return;
            setRefreshState(finalState);
            paintTrend({animate: true});
        }

        // A browser that cannot animate the backdrop still opens the popup.
        function backdrop(frames, options) {
            try { return play(dialog, frames, {...options, pseudoElement: '::backdrop'}); } catch { return null; }
        }

        function openRepo(button) {
            const view = trendView(), repo = view?.repos?.[Number(button.dataset.trendRepo)];
            if (!repo) return;
            const live = !trend.selected;
            const url = /^https:\/\/github\.com\/[\w.-]+\/[\w.-]+$/.test(String(repo.url ?? '')) ? repo.url : `https://github.com/${repo.repo}`;
            const fact = (name, value) => `<div><dt class="hm-mono">${esc(name)}</dt><dd>${esc(value)}</dd></div>`;
            const facts = [repo.language ? fact('Language', repo.language) : '', repo.stars != null ? fact('Stars', Number(repo.stars).toLocaleString()) : '', repo.starsToday != null ? fact(live ? 'Today' : 'That day', `+${Number(repo.starsToday).toLocaleString()}`) : ''].join('');
            const rank = repo.bestRank ?? repo.rank;
            const seenFrom = repo.firstSeenAt ? clock(repo.firstSeenAt) : '';
            const seenTo = repo.lastSeenAt && repo.lastSeenAt !== repo.firstSeenAt ? clock(repo.lastSeenAt) : '';
            const seen = [rank != null ? `Best rank #${rank}` : '', seenFrom ? `Seen trending ${seenFrom}${seenTo ? `–${seenTo}` : ''}` : '', live && repo.onList === false ? 'No longer on the list' : ''].filter(Boolean).join(' · ');
            dialog.innerHTML = `<form method="dialog"><div class="hm-trend-head"><div><p class="hm-mono">Trending on GitHub</p><h2 id="hm-trend-title">${esc(repo.repo)}</h2></div><button class="hm-btn hm-trend-x" value="close" aria-label="Close repository details">${icon('close')}</button></div><p class="hm-trend-desc">${esc(repo.description || 'No description provided.')}</p>${facts ? `<dl class="hm-trend-facts">${facts}</dl>` : ''}${seen ? `<p class="hm-trend-seen">${esc(seen)}</p>` : ''}<div class="hm-trend-actions"><button class="hm-btn" value="close">Close</button><a class="hm-btn hm-act" href="${esc(url)}" target="_blank" rel="noopener noreferrer">Open on GitHub${icon('out')}</a></div></form>`;
            dialog.opener = button;
            dialogRun++;
            dialog.getAnimations().forEach(animation => animation.cancel());
            if (!dialog.open) dialog.showModal();
            rise(dialog, {distance: MOTION.step});
            backdrop([{opacity: 0}, {opacity: 1}], {duration: MOTION.base, easing: MOTION.glide});
        }
        async function closeRepo() {
            if (!dialog.open) return;
            const run = ++dialogRun;
            await Promise.all([settled(fadeOut(dialog)), settled(backdrop([{opacity: 1}, {opacity: 0}], {duration: MOTION.exit, easing: MOTION.leave, fill: 'forwards'}))]);
            if (run !== dialogRun) return;
            dialog.close();
            dialog.getAnimations().forEach(animation => animation.cancel());
        }

        // What waited while someone read lands once they have left.
        function settleHeld(options) {
            if (!trend.pending || reading(options)) return;
            trend.today = trend.pending;
            trend.pending = null;
            if (!trend.selected) paintTrend({animate: true});
            else paintTrendControls();
        }

        const repoKeys = data => (data?.repos || []).map(repo => `${repo.repo}:${repo.starsToday}:${repo.onList}`).join('|');
        function receiveTrending(answer) {
            if (answer.error) { trend.error = answer.error.message; return false; }
            trend.error = null;
            const moved = repoKeys(answer.result) !== repoKeys(trend.today);
            if (trend.today && moved && reading()) { trend.pending = answer.result; return false; }
            trend.today = answer.result;
            trend.pending = null;
            return moved;
        }

        async function load({reveal = false} = {}) {
            const stories = request(`/service/rss/v1/stories?limit=${STORY_LIMIT}`);
            // A read that was already under way when Refresh ran is older than Refresh's answer, so it is dropped.
            const trendRun = trend.run;
            const trending = request('/service/trending/v1/trending').then(result => ({result})).catch(error => ({error}));
            const first = !feed.loaded;
            let arrivals = false;
            try {
                const result = await stories;
                const incoming = result.stories || [];
                const known = new Set(feed.stories.map(story => story.id));
                const fresh = incoming.filter(story => !known.has(story.id));
                // Rows hold still while someone reads: new stories wait behind a line until asked for.
                if (!first && !reveal && fresh.length && feed.stories.length && reading()) feed.waiting = fresh;
                else if (first || reveal || !feed.stories.length || fresh.length) {
                    arrivals = !first && fresh.length > 0;
                    feed.stories = incoming;
                    feed.waiting = [];
                } else feed.waiting = [];
                feed.empty = !incoming.length;
                feed.answeredAt = new Date();
                feed.failedAt = null;
            } catch { feed.failedAt = new Date(); }
            const answer = await trending;
            if (signal.aborted || !root.isConnected) return;
            feed.loaded = true;
            const moved = trendRun === trend.run && receiveTrending(answer);
            paintSlot();
            paintBody({first, arrivals});
            if (first) paintTrend({first: true});
            else if (trend.selected) paintTrendControls();
            else paintTrend({animate: moved});
            onChange();
        }

        const poller = state.poller({interval: SIGNAL_INTERVAL_MS, visible: () => document.visibilityState !== 'hidden', request: () => load()});

        function listen() {
            card.addEventListener('click', event => {
                const repo = event.target.closest('[data-trend-repo]');
                if (repo) openRepo(repo);
                if (event.target.closest('[data-trending-refresh]')) forceRefresh();
                if (event.target.closest('[data-trend-all]')) { trend.all = !trend.all; writeTrendBody({animate: true}); }
                if (event.target.closest('[data-signal-retry]')) load({reveal: true});
                if (event.target.closest('[data-signal-show]')) load({reveal: true}).then(() => $('[data-signal-list] a')?.focus({preventScroll: true}));
            }, {signal});
            card.addEventListener('change', event => {
                if (event.target.matches('[data-trend-day]')) showDay(event.target.value);
            }, {signal});
            card.addEventListener('pointerleave', () => settleHeld({ignoreHover: true}), {signal});
            card.addEventListener('focusout', event => { if (!card.contains(event.relatedTarget)) settleHeld(); }, {signal});
            dialog.addEventListener('submit', event => { event.preventDefault(); closeRepo(); }, {signal});
            dialog.addEventListener('cancel', event => { event.preventDefault(); closeRepo(); }, {signal});
            // The popup's own padding is also the dialog element, so only a click outside its box closes it.
            dialog.addEventListener('click', event => {
                const box = dialog.getBoundingClientRect();
                if (event.target === dialog && (event.clientX < box.left || event.clientX > box.right || event.clientY < box.top || event.clientY > box.bottom)) closeRepo();
            }, {signal});
            dialog.addEventListener('close', () => {
                if (dialog.opener?.isConnected) dialog.opener.focus();
                settleHeld();
            }, {signal});
            signal.addEventListener('abort', () => { clearTimeout(noteTimer); clearTimeout(labelTimer); }, {once: true});
        }

        return {
            start() {
                if (!admin) { card.hidden = true; return; }
                card.innerHTML = `<div class="hm-ch hm-sig-head"><h2>Signal</h2><div class="hm-r hm-sig-slot" data-signal-slot></div></div><div data-signal-body></div><div class="hm-tr-group" data-trend hidden><h3 class="hm-tr"><span>Trending on GitHub</span><span data-trend-word></span></h3><div data-trend-body></div><button type="button" class="hm-tr-all" data-trend-all hidden></button><div class="hm-tr-foot"><label class="hm-day" data-trend-days hidden><span class="hm-sr">Trending day</span><select data-trend-day></select>${icon('chevron')}</label><span class="hm-tr-soon" data-trend-soon hidden>Earlier days appear after midnight</span><button type="button" class="hm-tr-btn" data-trending-refresh data-state="rest" aria-busy="false" aria-disabled="false"><span data-l="rest">Refresh</span><span data-l="busy">Refreshing…</span><span data-l="done">Refreshed</span></button></div><p class="hm-tr-status" data-refresh-status role="status" aria-live="polite"></p></div>`;
                listen();
                paintBody();
                poller.refresh().catch(() => {});
            },
            tick() {
                if (admin) poller.tick().catch(() => {});
            },
            snapshot: () => ({loaded: feed.loaded, failed: Boolean(feed.failedAt), story: feed.stories[0] ?? null}),
        };
    }

    window.MothershipHomeSignal = {create};
})();
