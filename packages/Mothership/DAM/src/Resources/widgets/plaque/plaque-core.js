// Plaque Maker core: the set schema and its importer, presets, topic matching, the timeline and the one frame
// function that both the preview and every exporter draw with. No DOM and no clock: the page loads this file as a
// plain <script> (window.PlaqueCore) and the Node tests load it with require().
(function (root, factory) {
    const api = factory();
    if (typeof module === 'object' && module.exports) module.exports = api;
    else root.PlaqueCore = api;
}(typeof self !== 'undefined' ? self : this, () => {
    'use strict';

    const FORMAT = 'mothership-plaque-set';
    const VERSION = 1;
    const LIMITS = { plaques: 200, assetBytes: 25 * 1024 * 1024, text: 400, actions: 6 };
    const ENTER = ['fade', 'slide-up', 'slide-down', 'slide-left', 'slide-right', 'pop', 'none'];
    const EASING = ['ease-out', 'ease-in-out', 'linear', 'back'];
    const LAYOUTS = ['row', 'column'];
    const FITS = ['wrap', 'shrink'];
    const FONTS = {
        system: 'Inter, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif',
        rounded: 'ui-rounded, "SF Pro Rounded", "Arial Rounded MT Bold", system-ui, sans-serif',
        serif: 'Georgia, "Times New Roman", serif',
        condensed: '"Arial Narrow", "Avenir Next Condensed", "Helvetica Neue", sans-serif',
        mono: 'ui-monospace, SFMono-Regular, Menlo, monospace',
    };
    const ANCHORS = ['top-left', 'top', 'top-right', 'left', 'center', 'right', 'bottom-left', 'bottom', 'bottom-right'];
    const FRAMES = { landscape: [1920, 1080], portrait: [1080, 1920], square: [1080, 1080] };
    const BUILTIN_SOUNDS = ['chime', 'pop', 'whoosh', 'coin', 'click', 'bell', 'rise'];

    // 24-unit stroke icons, drawn with Path2D. Only straight lines, arcs and curves that Path2D parses everywhere.
    const ICONS = {
        star: 'M12 3l2.8 5.7 6.2.9-4.5 4.4 1.1 6.2-5.6-2.9-5.6 2.9 1.1-6.2-4.5-4.4 6.2-.9z',
        heart: 'M12 20s-7-4.4-7-10a4 4 0 0 1 7-2.6A4 4 0 0 1 19 10c0 5.6-7 10-7 10z',
        bell: 'M6 16V11a6 6 0 0 1 12 0v5l2 2H4z M10 20a2 2 0 0 0 4 0',
        play: 'M4 5h16v14H4z M10 9v6l5-3z',
        trophy: 'M7 4h10v5a5 5 0 0 1-10 0z M7 6H4a3 3 0 0 0 3 4 M17 6h3a3 3 0 0 1-3 4 M12 14v4 M8 20h8',
        gift: 'M4 10h16v10H4z M3 7h18v3H3z M12 7v13 M12 7c-2-4-6-3-5 0 M12 7c2-4 6-3 5 0',
        coin: 'M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18z M15 9a3 2 0 0 0-3-1c-2 0-3 1-3 2s1 1.6 3 2 3 1 3 2-1 2-3 2a3 2 0 0 1-3-1 M12 6v2 M12 16v2',
        megaphone: 'M3 10v4h4l8 5V5L7 10z M18 9a4 4 0 0 1 0 6',
        fire: 'M12 21a6 6 0 0 0 6-6c0-4-3-6-4-10-2 2-3 4-3 6-1-1-2-2-2-4-2 2-3 5-3 8a6 6 0 0 0 6 6z',
        check: 'M4 12l5 5L20 6',
        info: 'M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18z M12 11v6 M12 7.5v.5',
        calendar: 'M4 6h16v14H4z M4 10h16 M8 3v5 M16 3v5',
        'user-plus': 'M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8z M2 21a7 7 0 0 1 14 0 M19 8v6 M16 11h6',
        'thumbs-up': 'M7 11v9H4v-9z M7 11l4-8a2 2 0 0 1 3 2l-1 5h6a2 2 0 0 1 2 2l-2 7a2 2 0 0 1-2 1H7',
        share: 'M18 8a3 3 0 1 0 0-6 3 3 0 0 0 0 6z M6 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6z M18 22a3 3 0 1 0 0-6 3 3 0 0 0 0 6z M8.6 13.5l6.8 4 M15.4 6.5l-6.8 4',
        music: 'M9 18V5l11-2v13 M9 18a3 3 0 1 1-6 0 3 3 0 0 1 6 0z M20 16a3 3 0 1 1-6 0 3 3 0 0 1 6 0z',
        sparkle: 'M12 3l1.8 5.2L19 10l-5.2 1.8L12 17l-1.8-5.2L5 10l5.2-1.8z M19 15l.8 2.2L22 18l-2.2.8L19 21l-.8-2.2L16 18l2.2-.8z',
        target: 'M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18z M12 7a5 5 0 1 0 0 10 5 5 0 0 0 0-10z M12 11v2',
        chat: 'M4 5h16v11H9l-5 4z',
        cart: 'M3 4h2l2.5 11h10L20 7H6.5 M9 20h.01 M17 20h.01',
        rocket: 'M12 3c4 2 6 6 6 10l-3 3H9l-3-3c0-4 2-8 6-10z M12 9.5v.5 M9 16l-3 4 M15 16l3 4',
        mic: 'M12 3a3 3 0 0 0-3 3v6a3 3 0 0 0 6 0V6a3 3 0 0 0-3-3z M5 11a7 7 0 0 0 14 0 M12 18v3',
        cursor: 'M5 3l14 8-6 2-3 6z',
    };

    // ---- small helpers --------------------------------------------------------------------------------------------
    const clamp = (value, min, max, fallback = min) => {
        const number = Number(value);
        return Number.isFinite(number) ? Math.min(max, Math.max(min, number)) : fallback;
    };
    const pick = (value, list, fallback) => (list.includes(value) ? value : fallback);
    const text = (value, max = LIMITS.text) => String(value ?? '').replace(/\r\n?/g, '\n').replace(/[\u0000-\u0009\u000b-\u001f\u007f]/g, '').slice(0, max);
    const color = (value, fallback) => (/^#[0-9a-f]{6}$/i.test(String(value)) ? String(value).toLowerCase() : fallback);
    let counter = 0;
    const uid = prefix => `${prefix}_${Date.now().toString(36)}${(counter++).toString(36)}${Math.random().toString(36).slice(2, 6)}`;
    const isId = value => /^[a-z]_[a-z0-9]{1,40}$/i.test(String(value));

    // ---- defaults -------------------------------------------------------------------------------------------------
    function defaultOutput() {
        return { mode: 'frame', frame: 'landscape', width: 1920, height: 1080, fps: 30, anchor: 'bottom-left', margin: 64, scale: 1, padSound: true };
    }
    function defaultPlaque(kind = 'standard') {
        return {
            id: uid('p'), kind,
            title: kind === 'social' ? 'Your channel' : 'New plaque', subtitle: kind === 'social' ? '@yourhandle' : 'Add a subtitle',
            amount: { enabled: false, value: 0, prefix: '$', suffix: '', decimals: 0, thousands: true, countUp: true },
            visual: { type: 'icon', icon: 'star', symbol: '', assetId: null, shape: 'rounded' },
            style: { layout: 'row', background: '#101114', text: '#ffffff', accent: '#bc232c', radius: 18, font: 'system', titleWeight: 700, uppercase: false, align: 'left', titleSize: 34, subtitleSize: 22, width: 640, fit: 'wrap', shadow: true, border: false },
            timing: { in: 0.5, hold: 3, out: 0.5 },
            animation: { enter: 'slide-up', exit: 'fade', easing: 'ease-out', distance: 60 },
            sound: { source: 'builtin:chime', volume: 0.8, offset: 0 },
            social: kind === 'social' ? { pageAssetId: null, platformAssetId: null, actions: [{ label: 'Follow', doneLabel: 'Following', glyph: 'user-plus', at: 1.6, sound: 'builtin:click' }] } : null,
        };
    }
    function newSet(name = 'Untitled set') {
        return { format: FORMAT, version: VERSION, name, output: defaultOutput(), plaques: [], assets: {} };
    }

    // ---- presets --------------------------------------------------------------------------------------------------
    // A preset is a partial plaque, merged over defaultPlaque(kind). Groups: starter, topic, social, donation.
    const PRESETS = [
        { id: 'starter-dark', group: 'starter', name: 'Dark card', kind: 'standard', patch: { title: 'Title goes here', subtitle: 'A short supporting line', visual: { type: 'icon', icon: 'star' } } },
        { id: 'starter-light', group: 'starter', name: 'Light card', kind: 'standard', patch: { title: 'Title goes here', subtitle: 'A short supporting line', style: { background: '#ffffff', text: '#101114', accent: '#bc232c' }, visual: { type: 'icon', icon: 'info' } } },
        { id: 'starter-lower-third', group: 'starter', name: 'Lower third', kind: 'standard', patch: { title: 'Speaker name', subtitle: 'Role or organization', visual: { type: 'none' }, style: { width: 720, radius: 6 }, animation: { enter: 'slide-right', exit: 'slide-left' } } },
        { id: 'starter-badge', group: 'starter', name: 'Centered badge', kind: 'standard', patch: { title: 'Achievement unlocked', subtitle: 'You did the thing', visual: { type: 'icon', icon: 'trophy' }, style: { layout: 'column', align: 'center', width: 520 }, animation: { enter: 'pop', exit: 'fade', easing: 'back' }, sound: { source: 'builtin:rise' } } },
        { id: 'topic-new-video', group: 'topic', name: 'New video', kind: 'standard', keywords: ['video', 'upload', 'episode', 'watch', 'premiere', 'youtube', 'clip', 'stream'], patch: { title: 'New video out now', subtitle: 'Watch the full episode', visual: { type: 'icon', icon: 'play' } } },
        { id: 'topic-event', group: 'topic', name: 'Event', kind: 'standard', keywords: ['event', 'date', 'meetup', 'conference', 'live', 'show', 'tour', 'concert', 'schedule'], patch: { title: 'Join us live', subtitle: 'Friday at 7 PM', visual: { type: 'icon', icon: 'calendar' } } },
        { id: 'topic-sale', group: 'topic', name: 'Sale', kind: 'standard', keywords: ['sale', 'discount', 'shop', 'store', 'deal', 'offer', 'merch', 'buy', 'price'], patch: { title: 'Limited-time offer', subtitle: 'Shop the collection', visual: { type: 'icon', icon: 'cart' }, amount: { enabled: true, value: 20, prefix: '', suffix: '% off', decimals: 0 } } },
        { id: 'topic-announcement', group: 'topic', name: 'Announcement', kind: 'standard', keywords: ['announce', 'news', 'update', 'launch', 'release', 'new', 'coming'], patch: { title: 'Big news', subtitle: 'Something new is here', visual: { type: 'icon', icon: 'megaphone' }, sound: { source: 'builtin:bell' } } },
        { id: 'topic-milestone', group: 'topic', name: 'Milestone', kind: 'standard', keywords: ['milestone', 'subscribers', 'followers', 'thank', 'celebrate', 'anniversary', 'record', 'goal'], patch: { title: 'Thank you!', subtitle: 'We reached a new milestone', visual: { type: 'icon', icon: 'trophy' }, amount: { enabled: true, value: 10000, prefix: '', suffix: ' followers', decimals: 0, countUp: true }, sound: { source: 'builtin:rise' } } },
        { id: 'topic-music', group: 'topic', name: 'Now playing', kind: 'standard', keywords: ['music', 'song', 'track', 'playing', 'album', 'artist', 'playlist', 'beat'], patch: { title: 'Now playing', subtitle: 'Artist – Track name', visual: { type: 'icon', icon: 'music' }, sound: { source: 'none' } } },
        { id: 'topic-podcast', group: 'topic', name: 'Podcast', kind: 'standard', keywords: ['podcast', 'interview', 'guest', 'talk', 'conversation', 'mic'], patch: { title: 'On today’s episode', subtitle: 'Guest name', visual: { type: 'icon', icon: 'mic' } } },
        { id: 'topic-tip', group: 'topic', name: 'Tip', kind: 'standard', keywords: ['tip', 'how', 'tutorial', 'learn', 'guide', 'step', 'hint', 'idea'], patch: { title: 'Quick tip', subtitle: 'Save time with this shortcut', visual: { type: 'icon', icon: 'sparkle' } } },
        { id: 'social-follow', group: 'social', name: 'Follow', kind: 'social', keywords: ['follow', 'social', 'instagram', 'tiktok', 'twitter'], patch: { social: { actions: [{ label: 'Follow', doneLabel: 'Following', glyph: 'user-plus', at: 1.6, sound: 'builtin:click' }] } } },
        { id: 'social-subscribe', group: 'social', name: 'Subscribe and bell', kind: 'social', keywords: ['subscribe', 'channel', 'bell', 'notifications'], patch: { timing: { hold: 4 }, social: { actions: [{ label: 'Subscribe', doneLabel: 'Subscribed', glyph: 'play', at: 1.4, sound: 'builtin:click' }, { label: 'Notify me', doneLabel: 'Notifying', glyph: 'bell', at: 2.8, sound: 'builtin:click' }] } } },
        { id: 'social-like-share', group: 'social', name: 'Like and share', kind: 'social', keywords: ['like', 'share', 'comment', 'engage'], patch: { timing: { hold: 4 }, social: { actions: [{ label: 'Like', doneLabel: 'Liked', glyph: 'thumbs-up', at: 1.3, sound: 'builtin:pop' }, { label: 'Share', doneLabel: 'Shared', glyph: 'share', at: 2.6, sound: 'builtin:click' }] } } },
        { id: 'donation-tip', group: 'donation', name: 'Tip received', kind: 'standard', keywords: ['donation', 'donate', 'tip', 'support', 'thanks', 'patron'], patch: { title: 'Alex sent a tip', subtitle: 'Thank you for the support!', visual: { type: 'icon', icon: 'heart' }, style: { accent: '#be185d' }, amount: { enabled: true, value: 5, prefix: '$', decimals: 2, countUp: true }, sound: { source: 'builtin:coin' } } },
        { id: 'donation-goal', group: 'donation', name: 'Goal reached', kind: 'standard', keywords: ['goal', 'fundraiser', 'charity', 'raised', 'campaign'], patch: { title: 'Goal reached!', subtitle: 'Raised for the community fund', visual: { type: 'icon', icon: 'target' }, amount: { enabled: true, value: 2500, prefix: '$', decimals: 0, countUp: true }, sound: { source: 'builtin:rise' }, timing: { hold: 4 } } },
        { id: 'donation-gift', group: 'donation', name: 'Gift', kind: 'standard', keywords: ['gift', 'gifted', 'present', 'membership', 'sub'], patch: { title: 'Sam gifted 5 memberships', subtitle: 'Welcome to the club', visual: { type: 'icon', icon: 'gift' }, sound: { source: 'builtin:chime' } } },
    ];

    function merge(target, patch) {
        for (const [key, value] of Object.entries(patch || {})) {
            if (value && typeof value === 'object' && !Array.isArray(value) && target[key] && typeof target[key] === 'object') merge(target[key], value);
            else target[key] = Array.isArray(value) ? JSON.parse(JSON.stringify(value)) : value;
        }
        return target;
    }
    function fromPreset(preset) {
        const plaque = merge(defaultPlaque(preset.kind || 'standard'), preset.patch || {});
        return normalizePlaque(plaque, {}, []) || defaultPlaque();
    }

    // English keyword matching for topic suggestions. Whole words, with a light plural/verb strip ("videos", "donated").
    function suggestTopics(description, limit = 4) {
        const words = new Set(String(description || '').toLowerCase().match(/[a-z]+/g) || []);
        const stems = new Set([...words].flatMap(word => [word, word.replace(/(ing|ed|es|s)$/, '')]));
        return PRESETS.filter(preset => preset.keywords)
            .map(preset => ({ preset, score: preset.keywords.filter(keyword => stems.has(keyword) || stems.has(keyword.replace(/(ing|ed|es|s)$/, ''))).length }))
            .filter(row => row.score > 0)
            .sort((a, b) => b.score - a.score || PRESETS.indexOf(a.preset) - PRESETS.indexOf(b.preset))
            .slice(0, limit)
            .map(row => row.preset);
    }

    // ---- the importer ---------------------------------------------------------------------------------------------
    // The only way a set gets in: file import, paste, library open, autosave restore and AI output. It never mutates
    // its input and never touches the current set; the caller swaps only when ok is true.
    function sound(value, assets, errors, where) {
        const source = String(value ?? 'none');
        if (source === 'none') return 'none';
        const builtin = source.match(/^builtin:([a-z-]+)$/);
        if (builtin && BUILTIN_SOUNDS.includes(builtin[1])) return source;
        const asset = source.match(/^asset:(.+)$/);
        if (asset && Object.hasOwn(assets, asset[1]) && /^audio\//.test(assets[asset[1]].type)) return source;
        errors.push(`${where}: unknown sound "${source.slice(0, 40)}", set to none.`);
        return 'none';
    }
    function assetRef(value, assets, kind) {
        const id = value == null ? '' : String(value);
        return id !== '' && Object.hasOwn(assets, id) && assets[id].type.startsWith(kind) ? id : null;
    }
    function normalizePlaque(raw, assets, errors, index = 0) {
        if (!raw || typeof raw !== 'object' || Array.isArray(raw)) { errors.push(`Plaque ${index + 1} is not an object.`); return null; }
        const where = `Plaque ${index + 1}`;
        const kind = raw.kind === 'social' ? 'social' : 'standard';
        const base = defaultPlaque(kind);
        const amount = raw.amount || {}, visual = raw.visual || {}, style = raw.style || {}, timing = raw.timing || {}, animation = raw.animation || {}, snd = raw.sound || {};
        const plaque = {
            id: isId(raw.id) ? String(raw.id) : uid('p'), kind,
            title: text(raw.title ?? ''), subtitle: text(raw.subtitle ?? ''),
            amount: {
                enabled: amount.enabled === true, value: clamp(amount.value, -1e12, 1e12, 0),
                prefix: text(amount.prefix ?? base.amount.prefix, 12), suffix: text(amount.suffix ?? '', 24),
                decimals: Math.round(clamp(amount.decimals, 0, 4, 0)), thousands: amount.thousands !== false, countUp: amount.countUp !== false,
            },
            visual: {
                type: pick(visual.type, ['icon', 'symbol', 'image', 'none'], 'icon'),
                icon: pick(visual.icon, Object.keys(ICONS), 'star'),
                symbol: text(visual.symbol ?? '', 8),
                assetId: assetRef(visual.assetId, assets, 'image/'),
                shape: pick(visual.shape, ['rounded', 'circle', 'square'], 'rounded'),
            },
            style: {
                layout: pick(style.layout, LAYOUTS, 'row'), background: color(style.background, base.style.background),
                text: color(style.text, base.style.text), accent: color(style.accent, base.style.accent),
                radius: clamp(style.radius, 0, 80, 18), font: pick(style.font, Object.keys(FONTS), 'system'),
                titleWeight: pick(Number(style.titleWeight), [400, 500, 600, 700, 800, 900], 700), uppercase: style.uppercase === true,
                align: pick(style.align, ['left', 'center'], 'left'),
                titleSize: clamp(style.titleSize, 12, 120, 34), subtitleSize: clamp(style.subtitleSize, 10, 90, 22),
                width: clamp(style.width, 240, 1600, 640), fit: pick(style.fit, FITS, 'wrap'), shadow: style.shadow !== false, border: style.border === true,
            },
            timing: { in: clamp(timing.in, 0, 5, 0.5), hold: clamp(timing.hold, 0, 60, 3), out: clamp(timing.out, 0, 5, 0.5) },
            animation: {
                enter: pick(animation.enter, ENTER, 'slide-up'), exit: pick(animation.exit, ENTER, 'fade'),
                easing: pick(animation.easing, EASING, 'ease-out'), distance: clamp(animation.distance, 0, 600, 60),
            },
            sound: { source: sound(snd.source ?? 'none', assets, errors, where), volume: clamp(snd.volume, 0, 1, 0.8), offset: clamp(snd.offset, -5, 60, 0) },
            social: null,
        };
        if (visual.type === 'image' && !plaque.visual.assetId) { plaque.visual.type = 'icon'; errors.push(`${where}: its image is missing, so it shows an icon.`); }
        if (kind === 'social') {
            const social = raw.social || {};
            const actions = Array.isArray(social.actions) ? social.actions.slice(0, LIMITS.actions) : [];
            plaque.social = {
                pageAssetId: assetRef(social.pageAssetId, assets, 'image/'),
                platformAssetId: assetRef(social.platformAssetId, assets, 'image/'),
                actions: actions.map(action => ({
                    label: text(action?.label ?? 'Follow', 24) || 'Follow', doneLabel: text(action?.doneLabel ?? '', 24),
                    glyph: pick(action?.glyph, Object.keys(ICONS), 'check'),
                    at: clamp(action?.at, 0, 65, 1.5), sound: sound(action?.sound ?? 'none', assets, errors, `${where} action`),
                })).sort((a, b) => a.at - b.at),
            };
        }
        return plaque;
    }
    const DATA_URL = /^data:((?:image\/(?:png|jpeg|gif|webp|svg\+xml))|(?:audio\/(?:wav|x-wav|wave|mpeg|mp3|ogg|webm|mp4|aac|x-m4a|flac)));base64,[A-Za-z0-9+/]+=*$/;
    function validateSet(input) {
        const errors = [];
        let raw = input;
        if (typeof raw === 'string') {
            try { raw = JSON.parse(raw); } catch { return { ok: false, set: null, errors: ['This is not valid JSON.'] }; }
        }
        if (!raw || typeof raw !== 'object' || Array.isArray(raw)) return { ok: false, set: null, errors: ['A plaque set must be a JSON object.'] };
        if (raw.format !== FORMAT) return { ok: false, set: null, errors: [`This file is not a plaque set (expected "format": "${FORMAT}").`] };
        if (!Number.isInteger(raw.version) || raw.version < 1) return { ok: false, set: null, errors: ['The set has no valid version number.'] };
        if (raw.version > VERSION) return { ok: false, set: null, errors: [`This set was made by a newer Plaque Maker (version ${raw.version}). This one reads version ${VERSION}.`] };
        if (!Array.isArray(raw.plaques)) return { ok: false, set: null, errors: ['The set has no "plaques" list.'] };
        if (raw.plaques.length > LIMITS.plaques) return { ok: false, set: null, errors: [`A set holds at most ${LIMITS.plaques} plaques; this one has ${raw.plaques.length}.`] };

        // A null prototype, so an asset named "__proto__" or "constructor" is only ever an ordinary key.
        const assets = Object.create(null);
        let bytes = 0;
        for (const [id, asset] of Object.entries(raw.assets && typeof raw.assets === 'object' ? raw.assets : {})) {
            const data = String(asset?.data ?? '');
            const match = data.match(DATA_URL);
            if (!isId(id) || !match) { errors.push(`Asset "${String(asset?.name ?? id).slice(0, 40)}" was dropped: only PNG, JPEG, GIF, WebP, SVG images and common audio files are accepted.`); continue; }
            bytes += data.length * 0.75;
            if (bytes > LIMITS.assetBytes) return { ok: false, set: null, errors: ['The images and sounds in this set add up to more than 25 MB.'] };
            assets[id] = { name: text(asset.name ?? id, 120) || id, type: match[1].replace('audio/mp3', 'audio/mpeg'), data };
        }
        const plaques = [];
        const seen = new Set();
        raw.plaques.forEach((item, index) => {
            const plaque = normalizePlaque(item, assets, errors, index);
            if (!plaque) return;
            if (seen.has(plaque.id)) plaque.id = uid('p');
            seen.add(plaque.id);
            plaques.push(plaque);
        });
        const out = raw.output || {};
        const frame = pick(out.frame, [...Object.keys(FRAMES), 'custom'], 'landscape');
        const output = {
            mode: pick(out.mode, ['tight', 'frame'], 'frame'), frame,
            width: Math.round(clamp(out.width, 16, 4096, 1920)), height: Math.round(clamp(out.height, 16, 4096, 1080)),
            fps: pick(Number(out.fps), [12, 15, 24, 25, 30, 50, 60], 30), anchor: pick(out.anchor, ANCHORS, 'bottom-left'),
            margin: clamp(out.margin, 0, 1000, 64), scale: clamp(out.scale, 0.25, 4, 1), padSound: out.padSound !== false,
        };
        if (frame !== 'custom') [output.width, output.height] = FRAMES[frame];
        // Unknown keys (provider settings, credentials, anything else) are simply not copied.
        return { ok: true, set: { format: FORMAT, version: VERSION, name: text(raw.name ?? 'Untitled set', 120) || 'Untitled set', output, plaques, assets }, errors };
    }
    // A set as written to a file: only the assets its plaques still use.
    function exportSet(set) {
        const used = new Set();
        for (const plaque of set.plaques) {
            if (plaque.visual.assetId) used.add(plaque.visual.assetId);
            if (plaque.social) { used.add(plaque.social.pageAssetId); used.add(plaque.social.platformAssetId); }
            for (const source of [plaque.sound.source, ...(plaque.social?.actions || []).map(action => action.sound)]) if (source.startsWith('asset:')) used.add(source.slice(6));
        }
        const assets = Object.fromEntries(Object.entries(set.assets).filter(([id]) => used.has(id)));
        return { format: FORMAT, version: VERSION, name: set.name, output: set.output, plaques: set.plaques, assets };
    }

    // ---- time -----------------------------------------------------------------------------------------------------
    const plaqueLength = plaque => plaque.timing.in + plaque.timing.hold + plaque.timing.out;
    function timeline(plaques) {
        let start = 0;
        const entries = plaques.map(plaque => { const entry = { plaque, start, end: start + plaqueLength(plaque) }; start = entry.end; return entry; });
        return { entries, duration: start };
    }
    function entryAt(line, t) {
        // A plaque owns [start, end); the very last instant belongs to the last plaque so its final frame exists.
        for (const entry of line.entries) if (t >= entry.start && t < entry.end) return entry;
        const last = line.entries[line.entries.length - 1];
        return last && t >= last.end - 1e-9 && t <= last.end + 1e-9 ? last : null;
    }
    const frameCount = (line, fps) => Math.max(1, Math.round(line.duration * fps));
    // When a still is wanted: the first moment the plaque is fully in, and any click effects have settled.
    function stillTime(plaque) {
        const lastClick = plaque.social?.actions?.length ? Math.max(...plaque.social.actions.map(action => action.at)) + 0.6 : 0;
        return Math.min(plaqueLength(plaque) - plaque.timing.out, Math.max(plaque.timing.in, lastClick));
    }
    function soundSchedule(plaques) {
        const line = timeline(plaques), events = [];
        for (const { plaque, start } of line.entries) {
            if (plaque.sound.source !== 'none' && plaque.sound.volume > 0) events.push({ source: plaque.sound.source, at: Math.max(0, start + plaque.sound.offset), volume: plaque.sound.volume });
            for (const action of plaque.social?.actions || []) if (action.sound !== 'none') events.push({ source: action.sound, at: start + action.at, volume: plaque.sound.volume || 0.8 });
        }
        return { events: events.sort((a, b) => a.at - b.at), duration: line.duration };
    }

    const EASE = {
        linear: x => x,
        'ease-out': x => 1 - (1 - x) ** 3,
        'ease-in-out': x => (x < 0.5 ? 4 * x * x * x : 1 - (-2 * x + 2) ** 3 / 2),
        back: x => { const c = 1.70158; return 1 + (c + 1) * (x - 1) ** 3 + c * (x - 1) ** 2; },
    };
    const unit = value => Math.min(1, Math.max(0, value));

    function formatAmount(amount, value) {
        const fixed = Math.abs(value).toFixed(amount.decimals);
        let [whole, frac] = fixed.split('.');
        if (amount.thousands) whole = whole.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        return `${value < 0 ? '−' : ''}${amount.prefix}${whole}${frac ? `.${frac}` : ''}${amount.suffix}`;
    }

    // ---- layout ---------------------------------------------------------------------------------------------------
    function fontFor(style, size, weight) { return `${weight} ${Math.round(size * 100) / 100}px ${FONTS[style.font] || FONTS.system}`; }
    function wrapLines(ctx, value, maxWidth, maxLines) {
        const lines = [];
        for (const paragraph of String(value).split('\n')) {
            const words = paragraph.split(/\s+/).filter(Boolean);
            let line = '';
            if (!words.length) { lines.push(''); continue; }
            for (const word of words) {
                const next = line ? `${line} ${word}` : word;
                if (ctx.measureText(next).width <= maxWidth || !line) line = next;
                else { lines.push(line); line = word; }
            }
            lines.push(line);
        }
        if (lines.length > maxLines) {
            const kept = lines.slice(0, maxLines);
            let last = kept[maxLines - 1];
            while (last.length > 1 && ctx.measureText(`${last}…`).width > maxWidth) last = last.slice(0, -1);
            kept[maxLines - 1] = `${last.replace(/\s+$/, '')}…`;
            return kept;
        }
        return lines;
    }
    // Text block: in "wrap" mode lines wrap (title 3, subtitle 3 lines max); in "shrink" mode each manual line keeps one
    // line and the size steps down until the longest fits, to half the set size at most, then wraps.
    function fitText(ctx, style, value, size, weight, maxWidth, maxLines) {
        if (!value) return { lines: [], size, height: 0 };
        let current = size;
        ctx.font = fontFor(style, current, weight);
        if (style.fit === 'shrink') {
            const longest = () => Math.max(...String(value).split('\n').map(line => ctx.measureText(line).width));
            while (current > size * 0.5 && longest() > maxWidth) { current -= Math.max(0.5, size * 0.04); ctx.font = fontFor(style, current, weight); }
        }
        const lines = wrapLines(ctx, value, maxWidth, maxLines);
        return { lines, size: current, height: lines.length * current * 1.18 };
    }
    // Every measurement in plaque units (1 unit = 1 output pixel at scale 1).
    function layout(ctx, plaque) {
        const style = plaque.style, pad = 24, gap = 18;
        const social = plaque.kind === 'social';
        const hasVisual = social || plaque.visual.type !== 'none';
        const visualSize = social ? Math.round(style.titleSize * 2.1) : Math.round(Math.max(style.titleSize * 1.9, 56));
        const column = style.layout === 'column' && !social;
        const textWidth = column || !hasVisual ? style.width - pad * 2 : style.width - pad * 2 - visualSize - gap;
        const title = fitText(ctx, style, style.uppercase ? plaque.title.toUpperCase() : plaque.title, style.titleSize, style.titleWeight, textWidth, 3);
        const subtitle = fitText(ctx, style, plaque.subtitle, style.subtitleSize, 400, textWidth, 3);
        const amountSize = style.titleSize * 1.25;
        let amount = null;
        if (plaque.amount.enabled) {
            ctx.font = fontFor(style, amountSize, 800);
            let size = amountSize;
            const sample = formatAmount(plaque.amount, plaque.amount.value);
            while (size > amountSize * 0.5 && ctx.measureText(sample).width > textWidth) { size -= 1; ctx.font = fontFor(style, size, 800); }
            amount = { size, height: size * 1.15 };
        }
        const between = style.subtitleSize * 0.3;
        let textHeight = title.height + (subtitle.height ? between + subtitle.height : 0) + (amount ? between + amount.height : 0);
        const buttonHeight = social && plaque.social.actions.length ? Math.round(style.subtitleSize * 2) : 0;
        const buttons = [];
        if (buttonHeight) {
            ctx.font = fontFor(style, style.subtitleSize * 0.9, 700);
            const bpad = style.subtitleSize * 0.8, glyph = style.subtitleSize * 0.9;
            let x = 0;
            for (const action of plaque.social.actions) {
                const label = Math.max(ctx.measureText(action.label).width, ctx.measureText(action.doneLabel || action.label).width);
                const w = Math.round(bpad * 2 + glyph + 8 + label);
                buttons.push({ x, w, h: buttonHeight });
                x += w + 10;
            }
            textHeight += 14 + buttonHeight;
        }
        let width = style.width, height;
        const box = {};
        if (column) {
            height = pad + (hasVisual ? visualSize + gap : 0) + textHeight + pad;
            box.visual = hasVisual ? { x: (width - visualSize) / 2, y: pad, s: visualSize } : null;
            box.text = { x: pad, y: pad + (hasVisual ? visualSize + gap : 0), w: width - pad * 2 };
        } else {
            height = pad * 2 + Math.max(hasVisual ? visualSize : 0, textHeight);
            box.visual = hasVisual ? { x: pad, y: (height - visualSize) / 2, s: visualSize } : null;
            box.text = { x: hasVisual ? pad + visualSize + gap : pad, y: (height - textHeight) / 2, w: textWidth };
        }
        // Buttons may need more width than the card in a narrow plaque: they wrap onto the text column's width.
        return { width, height, pad, title, subtitle, amount, between, buttons, buttonHeight, box, column, social };
    }

    // ---- the frame function ---------------------------------------------------------------------------------------
    // Placement of one plaque inside the output, before animation. Tight mode: the canvas is the largest plaque plus a
    // margin for the shadow; frame mode: the anchor and margin inside the chosen frame.
    const SHADOW_PAD = 40;
    function sceneScale(output) {
        return output.mode === 'frame' ? output.scale * Math.min(output.width, output.height) / 1080 : output.scale;
    }
    function canvasSize(ctx, output, plaques) {
        if (output.mode === 'frame') return { width: output.width, height: output.height };
        const scale = sceneScale(output);
        let w = 64, h = 64;
        for (const plaque of plaques) { const box = layout(ctx, plaque); w = Math.max(w, box.width); h = Math.max(h, box.height); }
        // Even pixel sizes keep video encoders happy.
        return { width: Math.ceil((w + SHADOW_PAD * 2) * scale / 2) * 2, height: Math.ceil((h + SHADOW_PAD * 2) * scale / 2) * 2 };
    }
    function placement(output, size, box) {
        const scale = sceneScale(output), w = box.width * scale, h = box.height * scale;
        if (output.mode !== 'frame') return { x: (size.width - w) / 2, y: (size.height - h) / 2, scale };
        const m = output.margin * scale, [v, hz] = output.anchor.includes('-') ? output.anchor.split('-') : (['top', 'bottom'].includes(output.anchor) ? [output.anchor, 'center'] : ['middle', output.anchor === 'center' ? 'center' : output.anchor]);
        const x = hz === 'left' ? m : hz === 'right' ? size.width - w - m : (size.width - w) / 2;
        const y = v === 'top' ? m : v === 'bottom' ? size.height - h - m : (size.height - h) / 2;
        return { x, y, scale };
    }
    // Animation state of a plaque at its local time lt: opacity, offset (plaque units) and scale about its centre.
    function motion(plaque, lt) {
        const { timing, animation } = plaque, ease = EASE[animation.easing] || EASE['ease-out'], d = animation.distance;
        const total = plaqueLength(plaque);
        let phase = 'hold', k = 1, style = 'none';
        if (lt < timing.in) { phase = 'in'; k = timing.in ? ease(unit(lt / timing.in)) : 1; style = animation.enter; }
        else if (lt > total - timing.out) { phase = 'out'; k = timing.out ? 1 - EASE['ease-in-out'](unit((lt - (total - timing.out)) / timing.out)) : 0; style = animation.exit; }
        const rest = 1 - k, sign = phase === 'out' ? -1 : 1;
        const m = { alpha: 1, dx: 0, dy: 0, scale: 1 };
        if (style === 'fade') m.alpha = unit(k);
        else if (style === 'slide-up') { m.alpha = unit(k * 1.6); m.dy = rest * d * sign; }
        else if (style === 'slide-down') { m.alpha = unit(k * 1.6); m.dy = -rest * d * sign; }
        else if (style === 'slide-left') { m.alpha = unit(k * 1.6); m.dx = rest * d * sign; }
        else if (style === 'slide-right') { m.alpha = unit(k * 1.6); m.dx = -rest * d * sign; }
        else if (style === 'pop') { m.alpha = unit(k * 2); m.scale = 0.6 + 0.4 * k; }
        else if (style === 'none' && phase === 'out' && k <= 0) m.alpha = 0;
        if (phase === 'out' && style === 'pop') m.scale = 0.85 + 0.15 * k;
        return m;
    }
    function roundRect(ctx, x, y, w, h, r) {
        const radius = Math.max(0, Math.min(r, w / 2, h / 2));
        ctx.beginPath();
        ctx.moveTo(x + radius, y);
        ctx.arcTo(x + w, y, x + w, y + h, radius);
        ctx.arcTo(x + w, y + h, x, y + h, radius);
        ctx.arcTo(x, y + h, x, y, radius);
        ctx.arcTo(x, y, x + w, y, radius);
        ctx.closePath();
    }
    function drawIcon(ctx, name, x, y, size, strokeColor, makePath) {
        const path = makePath ? makePath(ICONS[name] || ICONS.star) : null;
        ctx.save();
        ctx.translate(x, y);
        ctx.scale(size / 24, size / 24);
        ctx.strokeStyle = strokeColor;
        ctx.lineWidth = 2;
        ctx.lineCap = 'round';
        ctx.lineJoin = 'round';
        if (path) ctx.stroke(path);
        ctx.restore();
    }
    function drawImage(ctx, image, x, y, s, shape, radius) {
        ctx.save();
        if (shape === 'circle') { ctx.beginPath(); ctx.arc(x + s / 2, y + s / 2, s / 2, 0, Math.PI * 2); ctx.closePath(); }
        else roundRect(ctx, x, y, s, s, shape === 'square' ? 0 : radius);
        ctx.clip();
        const iw = image.width || s, ih = image.height || s, cover = Math.max(s / iw, s / ih);
        ctx.drawImage(image, x + (s - iw * cover) / 2, y + (s - ih * cover) / 2, iw * cover, ih * cover);
        ctx.restore();
    }
    // Draws one plaque, already transformed so 1 unit = 1 plaque unit with the plaque's top-left at 0,0.
    function drawPlaque(ctx, plaque, box, lt, resolve, makePath) {
        const style = plaque.style;
        if (style.shadow) { ctx.shadowColor = 'rgba(0,0,0,0.35)'; ctx.shadowBlur = 28; ctx.shadowOffsetY = 10; }
        roundRect(ctx, 0, 0, box.width, box.height, style.radius);
        ctx.fillStyle = style.background;
        ctx.fill();
        ctx.shadowColor = 'transparent'; ctx.shadowBlur = 0; ctx.shadowOffsetY = 0;
        if (style.border) { ctx.lineWidth = 2; ctx.strokeStyle = style.accent; ctx.stroke(); }

        const v = box.box.visual;
        if (v && box.social) {
            const page = resolve(plaque.social.pageAssetId);
            if (page) drawImage(ctx, page, v.x, v.y, v.s, 'circle', 0);
            else { ctx.beginPath(); ctx.arc(v.x + v.s / 2, v.y + v.s / 2, v.s / 2, 0, Math.PI * 2); ctx.fillStyle = style.accent; ctx.fill(); drawIcon(ctx, 'user-plus', v.x + v.s * 0.25, v.y + v.s * 0.25, v.s * 0.5, '#ffffff', makePath); }
            const badge = v.s * 0.42, bx = v.x + v.s - badge * 0.8, by = v.y + v.s - badge * 0.8;
            const platform = resolve(plaque.social.platformAssetId);
            if (platform) {
                ctx.beginPath(); ctx.arc(bx + badge / 2, by + badge / 2, badge / 2 + 3, 0, Math.PI * 2); ctx.fillStyle = style.background; ctx.fill();
                drawImage(ctx, platform, bx, by, badge, 'circle', 0);
            }
        } else if (v) {
            const image = plaque.visual.type === 'image' ? resolve(plaque.visual.assetId) : null;
            if (image) drawImage(ctx, image, v.x, v.y, v.s, plaque.visual.shape, Math.min(style.radius, v.s / 4));
            else {
                if (plaque.visual.shape === 'circle') { ctx.beginPath(); ctx.arc(v.x + v.s / 2, v.y + v.s / 2, v.s / 2, 0, Math.PI * 2); ctx.closePath(); }
                else roundRect(ctx, v.x, v.y, v.s, v.s, plaque.visual.shape === 'square' ? 0 : Math.min(style.radius, v.s / 4));
                ctx.fillStyle = style.accent;
                ctx.fill();
                if (plaque.visual.type === 'symbol' && plaque.visual.symbol) {
                    ctx.font = `${Math.round(v.s * 0.56)}px "Apple Color Emoji", "Segoe UI Emoji", "Noto Color Emoji", ${FONTS[style.font]}`;
                    ctx.textAlign = 'center'; ctx.textBaseline = 'middle'; ctx.fillStyle = '#ffffff';
                    ctx.fillText(plaque.visual.symbol, v.x + v.s / 2, v.y + v.s / 2 + v.s * 0.03);
                } else drawIcon(ctx, plaque.visual.icon, v.x + v.s * 0.22, v.y + v.s * 0.22, v.s * 0.56, '#ffffff', makePath);
            }
        }

        const t = box.box.text, center = style.align === 'center' || box.column;
        const tx = center ? t.x + t.w / 2 : t.x;
        ctx.textAlign = center ? 'center' : 'left';
        ctx.textBaseline = 'top';
        let y = t.y;
        ctx.fillStyle = style.text;
        ctx.font = fontFor(style, box.title.size, style.titleWeight);
        for (const line of box.title.lines) { ctx.fillText(line, tx, y + box.title.size * 0.06); y += box.title.size * 1.18; }
        if (box.subtitle.lines.length) {
            y += box.between;
            ctx.globalAlpha *= 0.78;
            ctx.font = fontFor(style, box.subtitle.size, 400);
            for (const line of box.subtitle.lines) { ctx.fillText(line, tx, y + box.subtitle.size * 0.06); y += box.subtitle.size * 1.18; }
            ctx.globalAlpha /= 0.78;
        }
        if (box.amount) {
            y += box.between;
            const a = plaque.amount, grow = a.countUp ? EASE['ease-out'](unit(lt / Math.max(0.01, plaque.timing.in + Math.min(plaque.timing.hold, 1.2)))) : 1;
            const value = a.countUp ? Number((a.value * grow).toFixed(a.decimals)) : a.value;
            ctx.font = fontFor(style, box.amount.size, 800);
            ctx.fillStyle = style.accent === style.background ? style.text : style.accent;
            ctx.fillText(formatAmount(a, value), tx, y + box.amount.size * 0.04);
            y += box.amount.height;
        }
        if (box.buttons.length) drawActions(ctx, plaque, box, t, y + 14, lt, makePath);
    }
    function drawActions(ctx, plaque, box, t, top, lt, makePath) {
        const style = plaque.style, size = style.subtitleSize * 0.9, glyph = style.subtitleSize * 0.9;
        const offset = box.column ? t.x + (t.w - (box.buttons.at(-1).x + box.buttons.at(-1).w)) / 2 : t.x;
        let pointer = null;
        plaque.social.actions.forEach((action, index) => {
            const b = box.buttons[index], x = offset + b.x, done = lt >= action.at;
            const press = lt >= action.at && lt < action.at + 0.14 ? 0.92 : 1;
            ctx.save();
            ctx.translate(x + b.w / 2, top + b.h / 2);
            ctx.scale(press, press);
            ctx.translate(-b.w / 2, -b.h / 2);
            roundRect(ctx, 0, 0, b.w, b.h, b.h / 2);
            if (done) { ctx.fillStyle = 'rgba(255,255,255,0.14)'; ctx.fill(); ctx.lineWidth = 1.5; ctx.strokeStyle = style.text; ctx.globalAlpha *= 0.5; ctx.stroke(); ctx.globalAlpha /= 0.5; }
            else { ctx.fillStyle = style.accent; ctx.fill(); }
            const label = done && action.doneLabel ? action.doneLabel : action.label;
            drawIcon(ctx, done ? 'check' : action.glyph, b.h * 0.42, (b.h - glyph) / 2, glyph, done ? style.text : '#ffffff', makePath);
            ctx.font = fontFor(style, size, 700);
            ctx.textAlign = 'left'; ctx.textBaseline = 'middle';
            ctx.fillStyle = done ? style.text : '#ffffff';
            ctx.fillText(label, b.h * 0.42 + glyph + 8, b.h / 2 + 1);
            ctx.restore();
            // A ripple for 0.45 s after the click.
            const since = lt - action.at;
            if (since >= 0 && since < 0.45) {
                ctx.save();
                ctx.globalAlpha *= 0.45 * (1 - since / 0.45);
                ctx.beginPath(); ctx.arc(x + b.w * 0.55, top + b.h * 0.55, b.h * (0.3 + since * 2.4), 0, Math.PI * 2);
                ctx.fillStyle = '#ffffff'; ctx.fill();
                ctx.restore();
            }
            // The pointer travels from the previous button (or below the card) to this one, arriving at the click.
            const travel = 0.55, arrive = action.at, leave = arrive - travel;
            if (lt >= leave - 0.25 && lt <= arrive + 0.5) {
                const prev = index ? { x: offset + box.buttons[index - 1].x + box.buttons[index - 1].w * 0.55, y: top + b.h * 0.55 } : { x: x + b.w * 0.55 + 40, y: top + b.h * 0.55 + 70 };
                const k = EASE['ease-in-out'](unit((lt - leave) / travel));
                pointer = { x: prev.x + (x + b.w * 0.55 - prev.x) * k, y: prev.y + (top + b.h * 0.55 - prev.y) * k, alpha: lt > arrive + 0.3 ? unit(1 - (lt - arrive - 0.3) / 0.2) : unit((lt - leave + 0.25) / 0.25) };
            }
        });
        if (pointer && pointer.alpha > 0 && makePath) {
            const s = style.subtitleSize * 1.15;
            ctx.save();
            ctx.globalAlpha *= pointer.alpha;
            ctx.translate(pointer.x - s * 0.2, pointer.y - s * 0.12);
            ctx.scale(s / 24, s / 24);
            const path = makePath(ICONS.cursor);
            ctx.fillStyle = '#ffffff'; ctx.fill(path);
            ctx.lineWidth = 1.5; ctx.lineJoin = 'round'; ctx.strokeStyle = '#101114'; ctx.stroke(path);
            ctx.restore();
        }
    }
    // THE frame function. Preview and every exporter call this and nothing else to draw.
    //   scene: { output, plaques, size: {width, height}, background: null | '#rrggbb' }
    //   t: seconds from the start of scene.plaques laid end to end
    //   resolve(assetId): a decoded image or null.  makePath(d): a Path2D for an SVG path string (injected so Node can test).
    function drawFrame(ctx, scene, t, resolve = () => null, makePath = null) {
        const { size } = scene;
        ctx.setTransform(1, 0, 0, 1, 0, 0);
        ctx.globalAlpha = 1;
        ctx.clearRect(0, 0, size.width, size.height);
        if (scene.background) { ctx.fillStyle = scene.background; ctx.fillRect(0, 0, size.width, size.height); }
        const entry = entryAt(timeline(scene.plaques), t);
        if (!entry) return null;
        const plaque = entry.plaque, lt = t - entry.start, box = layout(ctx, plaque), m = motion(plaque, lt);
        if (m.alpha <= 0) return { id: plaque.id, lt };
        const place = placement(scene.output, size, box);
        ctx.save();
        ctx.globalAlpha = m.alpha;
        ctx.translate(place.x + m.dx * place.scale, place.y + m.dy * place.scale);
        ctx.scale(place.scale, place.scale);
        if (m.scale !== 1) { ctx.translate(box.width / 2, box.height / 2); ctx.scale(m.scale, m.scale); ctx.translate(-box.width / 2, -box.height / 2); }
        drawPlaque(ctx, plaque, box, lt, resolve, makePath);
        ctx.restore();
        return { id: plaque.id, lt };
    }

    return {
        FORMAT, VERSION, LIMITS, ENTER, EASING, FONTS, ANCHORS, FRAMES, ICONS, PRESETS, BUILTIN_SOUNDS,
        uid, clamp, defaultPlaque, defaultOutput, newSet, fromPreset, merge, suggestTopics,
        validateSet, exportSet, normalizePlaque, plaqueLength, timeline, entryAt, frameCount, stillTime, soundSchedule,
        formatAmount, layout, canvasSize, sceneScale, placement, motion, drawFrame,
    };
}));
