// Sound Effect Maker: the sound engine (render, WAV, ZIP and the saved-sounds format) and the tool around it. Opened
// from disk it works alone; inside the tool frame the page's bar speaks for it (home-frame.js).
(() => {
'use strict';

/* ---------------------------------------------------------------- model */

// Every parameter is stored as a plain number in a fixed range (the waveform as a name), so the library JSON is
// readable and the render below is the only place that maps a value to sound. Format: docs/features/sound-effect-maker.md
const WAVES = ['square', 'sawtooth', 'triangle', 'sine', 'noise'];
const PARAMS = {
    wave:          { def: 'square' },
    pitch:         { min: 0,  max: 1, def: 0.5 },
    attack:        { min: 0,  max: 1, def: 0 },
    decay:         { min: 0,  max: 1, def: 0.15 },
    sustain:       { min: 0,  max: 1, def: 0.2 },
    release:       { min: 0,  max: 1, def: 0.35 },
    sustainVolume: { min: 0,  max: 1, def: 0.5 },
    slide:         { min: -1, max: 1, def: 0 },
    deltaSlide:    { min: -1, max: 1, def: 0 },
    jump:          { min: -1, max: 1, def: 0 },
    jumpTime:      { min: 0,  max: 1, def: 0.3 },
    repeat:        { min: 0,  max: 1, def: 0 },
    tremolo:       { min: 0,  max: 1, def: 0 },
    noise:         { min: 0,  max: 1, def: 0 },
    crush:         { min: 0,  max: 1, def: 0 },
    delay:         { min: 0,  max: 1, def: 0 },
    cutoff:        { min: 0,  max: 1, def: 1 },
    volume:        { min: 0,  max: 1, def: 0.7 },
};
const KINDS = { coin: 'Coin pickup', whoosh: 'Whoosh', alarm: 'Alarm', explosion: 'Explosion', surprise: 'Surprise' };
const FORMAT = 'mothership-sound-library';
const MAX_SECONDS = 9;

const clamp = (v, lo, hi) => Math.min(hi, Math.max(lo, v));
const round4 = v => Math.round(v * 10000) / 10000;
const sq = v => v * v;
const signedSq = v => v * Math.abs(v);

function normalizeParams(input) {
    const out = {};
    const src = input && typeof input === 'object' ? input : {};
    for (const [key, spec] of Object.entries(PARAMS)) {
        if (key === 'wave') { out.wave = WAVES.includes(src.wave) ? src.wave : spec.def; continue; }
        const n = Number(src[key]);
        out[key] = Number.isFinite(n) ? round4(clamp(n, spec.min, spec.max)) : spec.def;
    }
    return out;
}
const normalizeSeed = seed => (Number.isFinite(Number(seed)) ? Number(seed) >>> 0 : 1);

// Seconds of each envelope stage. Squared so the low end of each slider has fine control.
function timing(p) {
    const attack = sq(p.attack) * 1.0, decay = sq(p.decay) * 1.5, sustain = sq(p.sustain) * 2.0, release = sq(p.release) * 2.5;
    return { attack, decay, sustain, release, total: Math.max(0.01, attack + decay + sustain + release) };
}
const delaySettings = p => ({ on: p.delay > 0.001, time: 0.06 + 0.24 * p.delay, feedback: 0.25 + 0.4 * p.delay, wet: 0.6 * p.delay });
function durationOf(p) {
    const d = delaySettings(p);
    return Math.min(MAX_SECONDS, timing(p).total + (d.on ? Math.min(2.5, d.time * 6) : 0));
}

// mulberry32: a small, fast, seedable generator, so noise is identical for the same seed.
function rng(seed) {
    let a = seed >>> 0;
    return () => {
        a = (a + 0x6D2B79F5) >>> 0;
        let t = a;
        t = Math.imul(t ^ (t >>> 15), t | 1);
        t ^= t + Math.imul(t ^ (t >>> 7), t | 61);
        return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
    };
}

/**
 * The one render function: parameters + seed + sample rate -> mono samples, computed sample by sample.
 * The same three inputs always give the same Float32Array (noise included), so a saved sound is reproducible.
 */
function renderSamples(params, seed, sampleRate) {
    const p = normalizeParams(params);
    const sr = Math.round(sampleRate);
    const env = timing(p);
    const dly = delaySettings(p);
    const length = Math.max(1, Math.ceil(durationOf(p) * sr));
    const out = new Float32Array(length);
    const waveRandom = rng(normalizeSeed(seed));
    const mixRandom = rng((normalizeSeed(seed) ^ 0x9E3779B9) >>> 0);

    const f0 = 40 * Math.pow(2, p.pitch * 8);                   // 40 Hz .. 10.2 kHz
    const slideOct = signedSq(p.slide) * 8;                      // octaves per second
    const deltaOct = signedSq(p.deltaSlide) * 16;                // octaves per second per second
    const jumpOct = (p.jump * 24) / 12;                          // up to two octaves either way
    const jumpAt = 0.01 + sq(p.jumpTime);
    const period = p.repeat >= 0.01 ? 0.03 + 0.6 * sq(1 - p.repeat) : 0;
    const tremDepth = p.tremolo * 0.9, tremRate = 3 + 15 * p.tremolo;
    const maxF = sr * 0.45;
    const cutoffHz = 120 * Math.pow(2, p.cutoff * 7.5);
    const filterOn = cutoffHz < maxF;
    const alpha = 1 - Math.exp(-2 * Math.PI * Math.min(cutoffHz, maxF) / sr);
    const crushOn = p.crush > 0.001;
    const levels = Math.pow(2, Math.round(16 - p.crush * 13) - 1);
    const hold = Math.max(1, Math.round(sq(p.crush) / 2000 * sr));
    const delayLen = Math.max(1, Math.round(dly.time * sr));
    const line = dly.on ? new Float32Array(delayLen) : null;
    const gain = p.volume * 0.8;
    const aEnd = env.attack, dEnd = aEnd + env.decay, sEnd = dEnd + env.sustain, rEnd = sEnd + env.release;

    let phase = 0, noiseStep = -1, noiseValue = 0, lp1 = 0, lp2 = 0, held = 0, di = 0;
    for (let i = 0; i < length; i++) {
        const t = i / sr;
        let s = 0;
        if (t < rEnd) {
            const tp = period ? t % period : t;
            let octaves = slideOct * tp + 0.5 * deltaOct * tp * tp;
            if (jumpOct !== 0 && tp >= jumpAt) octaves += jumpOct;
            const f = clamp(f0 * Math.pow(2, octaves), 10, maxF);
            phase += f / sr;
            phase -= Math.floor(phase);
            let v;
            switch (p.wave) {
                case 'square': v = phase < 0.5 ? 1 : -1; break;
                case 'sawtooth': v = 2 * phase - 1; break;
                case 'triangle': v = 1 - 4 * Math.abs(phase - 0.5); break;
                case 'sine': v = Math.sin(2 * Math.PI * phase); break;
                default: {
                    // Sample-and-hold noise, 16 steps per cycle, so pitch and slide still move it.
                    const step = Math.floor(phase * 16);
                    if (step !== noiseStep || f / sr >= 1 / 16) { noiseStep = step; noiseValue = waveRandom() * 2 - 1; }
                    v = noiseValue;
                }
            }
            if (p.noise > 0) v = v * (1 - p.noise) + (mixRandom() * 2 - 1) * p.noise;
            let e;
            if (t < aEnd) e = t / aEnd;
            else if (t < dEnd) e = 1 - (1 - p.sustainVolume) * ((t - aEnd) / env.decay);
            else if (t < sEnd) e = p.sustainVolume;
            else e = p.sustainVolume * (1 - (t - sEnd) / env.release);
            if (tremDepth > 0) e *= 1 - tremDepth * (0.5 + 0.5 * Math.sin(2 * Math.PI * tremRate * t));
            s = v * e;
        }
        if (filterOn) { lp1 += alpha * (s - lp1); lp2 += alpha * (lp1 - lp2); s = lp2; }
        if (crushOn) { if (i % hold === 0) held = Math.round(s * levels) / levels; s = held; }
        if (line) { const d = line[di]; line[di] = s + d * dly.feedback; s += d * dly.wet; di = (di + 1) % delayLen; }
        out[i] = clamp(s * gain, -1, 1);
    }
    return out;
}

function renderBuffer(params, seed, sampleRate) {
    const samples = renderSamples(params, seed, sampleRate);
    const buffer = new AudioBuffer({ length: samples.length, numberOfChannels: 1, sampleRate: Math.round(sampleRate) });
    buffer.copyToChannel(samples, 0);
    return { buffer, samples };
}

function peakOf(samples) {
    let peak = 0;
    for (let i = 0; i < samples.length; i++) { const a = Math.abs(samples[i]); if (a > peak) peak = a; }
    return peak;
}

/* --------------------------------------------------------- generators */

const rand = (lo, hi) => lo + Math.random() * (hi - lo);
const pick = list => list[Math.floor(Math.random() * list.length)];
const chance = p => Math.random() < p;
const newSeed = () => { const a = new Uint32Array(1); crypto.getRandomValues(a); return a[0]; };

const GENERATORS = {
    coin: () => ({
        wave: pick(['square', 'square', 'triangle', 'sine']), pitch: rand(0.56, 0.74),
        attack: 0, decay: rand(0.04, 0.12), sustain: rand(0.14, 0.3), release: rand(0.25, 0.42), sustainVolume: rand(0.35, 0.6),
        jump: pick([5, 7, 12]) / 24 + rand(-0.006, 0.006), jumpTime: rand(0.24, 0.34), slide: chance(0.25) ? rand(0.05, 0.15) : 0,
        cutoff: rand(0.85, 1), volume: 0.6,
    }),
    whoosh: () => ({
        wave: 'noise', pitch: rand(0.55, 0.78), attack: rand(0.42, 0.6), decay: rand(0.1, 0.25), sustain: rand(0.05, 0.2),
        release: rand(0.48, 0.62), sustainVolume: rand(0.6, 0.8), slide: pick([-1, 1]) * rand(0.15, 0.3), deltaSlide: rand(-0.1, 0.1),
        tremolo: chance(0.3) ? rand(0.05, 0.15) : 0, cutoff: rand(0.55, 0.75), volume: 0.75,
    }),
    alarm: () => {
        const siren = chance(0.5);
        return {
            wave: pick(['square', 'sawtooth', 'square']), pitch: rand(0.5, 0.62), attack: 0, decay: 0, sustain: rand(0.72, 0.85),
            release: rand(0.15, 0.25), sustainVolume: 0.8, repeat: rand(0.25, 0.42),
            slide: siren ? rand(0.5, 0.65) : 0, jump: siren ? 0 : pick([4, 5, 7]) / 24, jumpTime: siren ? 0.3 : rand(0.3, 0.36),
            cutoff: rand(0.72, 0.88), volume: 0.5,
        };
    },
    explosion: () => ({
        wave: 'noise', pitch: rand(0.18, 0.32), attack: 0, decay: rand(0.05, 0.15), sustain: rand(0.2, 0.35), release: rand(0.55, 0.7),
        sustainVolume: rand(0.45, 0.7), slide: -rand(0.15, 0.32), deltaSlide: rand(-0.05, 0.08), tremolo: chance(0.4) ? rand(0.05, 0.2) : 0,
        crush: chance(0.4) ? rand(0.05, 0.3) : 0, delay: chance(0.4) ? rand(0.1, 0.3) : 0, cutoff: rand(0.6, 0.85), volume: 0.8,
    }),
    surprise: () => ({
        wave: chance(0.2) ? 'noise' : pick(['square', 'sawtooth', 'triangle', 'sine']), pitch: rand(0.25, 0.8),
        attack: chance(0.7) ? 0 : rand(0.05, 0.4), decay: rand(0.05, 0.35), sustain: rand(0.08, 0.45), release: rand(0.15, 0.55),
        sustainVolume: rand(0.3, 0.85), slide: chance(0.5) ? rand(-0.45, 0.45) : 0, deltaSlide: chance(0.2) ? rand(-0.3, 0.3) : 0,
        jump: chance(0.4) ? rand(-0.4, 0.4) : 0, jumpTime: rand(0.1, 0.5), repeat: chance(0.2) ? rand(0.2, 0.6) : 0,
        tremolo: chance(0.2) ? rand(0.1, 0.5) : 0, noise: chance(0.15) ? rand(0.1, 0.3) : 0, crush: chance(0.2) ? rand(0.1, 0.4) : 0,
        delay: chance(0.25) ? rand(0.1, 0.4) : 0, cutoff: rand(0.55, 1), volume: 0.6,
    }),
};

function nudgeParams(p) {
    const next = { ...p };
    for (const [key, spec] of Object.entries(PARAMS)) {
        if (key === 'wave' || key === 'volume') continue;
        const span = spec.max - spec.min;
        // Leave switched-off effects off; move everything else a little.
        if (spec.def === 0 && Math.abs(p[key]) < 0.001 && !['attack'].includes(key)) continue;
        next[key] = clamp(p[key] + (Math.random() + Math.random() - 1) * 0.045 * span, spec.min, spec.max);
    }
    return normalizeParams(next);
}

/* ------------------------------------------------------- WAV and ZIP */

function encodeWav(samples, sampleRate) {
    const n = samples.length, data = n * 2;
    const view = new DataView(new ArrayBuffer(44 + data));
    const text = (offset, s) => { for (let i = 0; i < s.length; i++) view.setUint8(offset + i, s.charCodeAt(i)); };
    text(0, 'RIFF'); view.setUint32(4, 36 + data, true); text(8, 'WAVE');
    text(12, 'fmt '); view.setUint32(16, 16, true); view.setUint16(20, 1, true); view.setUint16(22, 1, true);
    view.setUint32(24, sampleRate, true); view.setUint32(28, sampleRate * 2, true); view.setUint16(32, 2, true); view.setUint16(34, 16, true);
    text(36, 'data'); view.setUint32(40, data, true);
    for (let i = 0; i < n; i++) {
        const s = clamp(samples[i], -1, 1);
        view.setInt16(44 + i * 2, Math.round(s < 0 ? s * 0x8000 : s * 0x7FFF), true);
    }
    return new Uint8Array(view.buffer);
}

const CRC_TABLE = (() => {
    const table = new Uint32Array(256);
    for (let n = 0; n < 256; n++) { let c = n; for (let k = 0; k < 8; k++) c = c & 1 ? 0xEDB88320 ^ (c >>> 1) : c >>> 1; table[n] = c >>> 0; }
    return table;
})();
function crc32(bytes) {
    let c = 0xFFFFFFFF;
    for (let i = 0; i < bytes.length; i++) c = CRC_TABLE[(c ^ bytes[i]) & 0xFF] ^ (c >>> 8);
    return (c ^ 0xFFFFFFFF) >>> 0;
}

// A ZIP of stored (uncompressed) entries: local headers, central directory, end record. Names are UTF-8 (flag bit 11).
function makeZip(files, when = new Date()) {
    const encoder = new TextEncoder();
    const dosTime = (when.getHours() << 11) | (when.getMinutes() << 5) | (when.getSeconds() >> 1);
    const dosDate = (Math.max(0, when.getFullYear() - 1980) << 9) | ((when.getMonth() + 1) << 5) | when.getDate();
    const parts = [], central = [];
    let offset = 0;
    for (const file of files) {
        const name = encoder.encode(file.name), data = file.data, crc = crc32(data);
        const local = new DataView(new ArrayBuffer(30));
        local.setUint32(0, 0x04034B50, true); local.setUint16(4, 20, true); local.setUint16(6, 0x0800, true); local.setUint16(8, 0, true);
        local.setUint16(10, dosTime, true); local.setUint16(12, dosDate, true); local.setUint32(14, crc, true);
        local.setUint32(18, data.length, true); local.setUint32(22, data.length, true); local.setUint16(26, name.length, true); local.setUint16(28, 0, true);
        const entry = new DataView(new ArrayBuffer(46));
        entry.setUint32(0, 0x02014B50, true); entry.setUint16(4, 20, true); entry.setUint16(6, 20, true); entry.setUint16(8, 0x0800, true);
        entry.setUint16(10, 0, true); entry.setUint16(12, dosTime, true); entry.setUint16(14, dosDate, true); entry.setUint32(16, crc, true);
        entry.setUint32(20, data.length, true); entry.setUint32(24, data.length, true); entry.setUint16(28, name.length, true);
        entry.setUint16(30, 0, true); entry.setUint16(32, 0, true); entry.setUint16(34, 0, true); entry.setUint16(36, 0, true);
        entry.setUint32(38, 0, true); entry.setUint32(42, offset, true);
        parts.push(new Uint8Array(local.buffer), name, data);
        central.push(new Uint8Array(entry.buffer), name);
        offset += 30 + name.length + data.length;
    }
    const centralSize = central.reduce((sum, part) => sum + part.length, 0);
    const end = new DataView(new ArrayBuffer(22));
    end.setUint32(0, 0x06054B50, true); end.setUint16(8, files.length, true); end.setUint16(10, files.length, true);
    end.setUint32(12, centralSize, true); end.setUint32(16, offset, true);
    const all = [...parts, ...central, new Uint8Array(end.buffer)];
    const out = new Uint8Array(all.reduce((sum, part) => sum + part.length, 0));
    let at = 0;
    for (const part of all) { out.set(part, at); at += part.length; }
    return out;
}

function fileName(name, extension) {
    const base = String(name || '').replace(/[\u0000-\u001f\u007f/\\:*?"<>|]+/g, '-').replace(/\s+/g, ' ').trim().replace(/^\.+/, '').slice(0, 80);
    return `${base || 'sound'}.${extension}`;
}

/* ---------------------------------------------------- library format */

function libraryJson(sounds, sampleRate) {
    return {
        format: FORMAT, version: 1, sampleRate,
        sounds: sounds.map(s => ({ name: s.name, kind: s.kind, seed: s.seed, params: normalizeParams(s.params) })),
    };
}
function parseLibrary(data) {
    const list = Array.isArray(data) ? data : data && data.format === FORMAT && Array.isArray(data.sounds) ? data.sounds : null;
    if (!list) throw new Error('That file is not a sound library from this tool.');
    if (!Array.isArray(data) && data.version !== 1) throw new Error('That library was made by a newer version of this tool.');
    return list.filter(s => s && typeof s === 'object' && s.params && typeof s.params === 'object').map(s => ({
        id: uid(), name: String(s.name || 'Imported sound').slice(0, 80), kind: KINDS[s.kind] ? s.kind : 'surprise',
        seed: normalizeSeed(s.seed), params: normalizeParams(s.params),
    }));
}
const uid = () => (crypto.randomUUID ? crypto.randomUUID() : `s${Date.now().toString(36)}${Math.random().toString(36).slice(2, 8)}`);

// Pure helpers for tests and for other Home tools that want to replay a saved library.
window.MothershipSoundEffects = Object.freeze({ FORMAT, PARAMS, WAVES, normalizeParams, renderSamples, renderBuffer, durationOf, encodeWav, makeZip, crc32, libraryJson, parseLibrary });

/* ----------------------------------------------------------- storage */

const KEY = { library: 'mothership.sound-effects.library', settings: 'mothership.sound-effects.settings', current: 'mothership.sound-effects.current' };
const MAX_STORED = 60000; // Home stores each key as one preference value of at most 64 KB.
function readStore(key) { try { const raw = window.localStorage.getItem(key); return raw ? JSON.parse(raw) : null; } catch { return null; } }
function writeStore(key, value) {
    const raw = JSON.stringify(value);
    if (raw.length > MAX_STORED) return false;
    try { window.localStorage.setItem(key, raw); return true; } catch { return false; }
}

/* ---------------------------------------------------------------- UI */

// The page that frames the tool (home-frame.js). Opened from disk there is none, and the tool stands alone.
const frame = window.MothershipFrame || null;
const root = document.querySelector('[data-sfx]');
const $ = sel => root.querySelector(sel);
const esc = value => String(value ?? '').replace(/[&<>"']/g, ch => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch]));
const plural = (count, word) => `${count} ${word}${count === 1 ? '' : 's'}`;

const openSheet = () => root.querySelector('dialog[open]');
// Says what just happened: in the open sheet's status line, or in the toast when no sheet is open.
const status = (() => {
    const toast = $('[data-status]');
    let timer;
    return (message, { tone = 'info', action = null, ms = 3200 } = {}) => {
        const sheet = openSheet();
        const box = sheet ? sheet.querySelector('.tk-status') : toast;
        const text = document.createElement('span');
        text.textContent = message;
        box.replaceChildren(text);
        if (action) {
            const btn = document.createElement('button');
            btn.type = 'button'; btn.className = 'tk-link'; btn.textContent = action.label;
            btn.addEventListener('click', () => { box.replaceChildren(); if (!sheet) box.hidden = true; action.run(); });
            box.append(' ', btn);
        }
        box.classList.toggle('is-error', tone === 'error');
        if (sheet) return;
        clearTimeout(timer);
        box.hidden = !message;
        if (message) timer = setTimeout(() => { box.hidden = true; }, action ? 8000 : ms);
    };
})();

const savedSettings = readStore(KEY.settings) || {};
const state = {
    library: [],
    current: null,          // { name, kind, seed, params, savedId }
    selectedId: null,
    settings: {
        sampleRate: [11025, 22050, 44100, 48000].includes(savedSettings.sampleRate) ? savedSettings.sampleRate : 44100,
        master: Number.isFinite(savedSettings.master) ? clamp(savedSettings.master, 0, 1) : 0.8,
        autoplay: savedSettings.autoplay !== false,
        mode: savedSettings.mode === 'advanced' ? 'advanced' : 'simple',
        view: savedSettings.view === 'shape' ? 'shape' : 'effects', // the part shown under the sound on a phone
        folder: typeof savedSettings.folder === 'string' ? savedSettings.folder : null, // the Library folder last saved into
    },
    render: null,           // { buffer, samples, peak, duration }
};
{
    const stored = readStore(KEY.library);
    if (stored) { try { state.library = parseLibrary(stored); } catch { state.library = []; } }
    const cur = readStore(KEY.current);
    if (cur && cur.params) {
        state.current = { name: String(cur.name || 'Sound').slice(0, 80), kind: KINDS[cur.kind] ? cur.kind : 'surprise', seed: normalizeSeed(cur.seed), params: normalizeParams(cur.params), savedId: null };
        const linked = Number.isInteger(cur.savedIndex) ? state.library[cur.savedIndex] : null;
        if (linked && linked.name === cur.savedName) { state.current.savedId = linked.id; state.selectedId = linked.id; }
    }
}

// Every library change is built as a new list and only becomes the library once it is stored.
function commitLibrary(next) {
    if (!writeStore(KEY.library, libraryJson(next, state.settings.sampleRate))) {
        status('The saved sounds could not be kept. If there are many, export them, then delete a few.', { tone: 'error', ms: 7000 });
        return false;
    }
    state.library = next;
    return true;
}
let currentTimer;
function persistCurrent() {
    clearTimeout(currentTimer);
    currentTimer = setTimeout(() => {
        const c = state.current, index = state.library.findIndex(s => s.id === c.savedId);
        writeStore(KEY.current, { name: c.name, kind: c.kind, seed: c.seed, params: c.params, savedIndex: index >= 0 ? index : null, savedName: index >= 0 ? state.library[index].name : null });
    }, 250);
}
const persistSettings = () => writeStore(KEY.settings, state.settings);

/* ---------------------------------------------------------- playback */

let audio = null, source = null, startedAt = 0, playingDuration = 0, frameId = 0;
function ensureAudio() {
    if (!audio) {
        const Ctx = window.AudioContext || window.webkitAudioContext;
        const ctx = new Ctx();
        const gain = ctx.createGain();
        gain.connect(ctx.destination);
        audio = { ctx, gain };
    }
    audio.gain.gain.value = state.settings.master;
    if (audio.ctx.state === 'suspended') audio.ctx.resume();
    return audio;
}
function stop() {
    if (source) { source.onended = null; try { source.stop(); } catch { /* already stopped */ } source.disconnect(); source = null; }
    cancelAnimationFrame(frameId);
    $('[data-play-label]').textContent = 'Play';
    $('[data-progress]').textContent = '';
    drawWave(null);
}
function play() {
    if (renderDirty) renderNow(); // an edit made in this same event must be heard, not the previous render
    if (!state.render) return;
    const { ctx, gain } = ensureAudio();
    stop();
    source = ctx.createBufferSource();
    source.buffer = state.render.buffer;
    source.connect(gain);
    source.onended = () => { source = null; stop(); };
    startedAt = ctx.currentTime;
    playingDuration = state.render.buffer.duration;
    source.start();
    $('[data-play-label]').textContent = 'Replay';
    const tick = () => {
        if (!source) return;
        const elapsed = clamp(audio.ctx.currentTime - startedAt, 0, playingDuration);
        drawWave(elapsed / playingDuration);
        $('[data-progress]').textContent = `${elapsed.toFixed(2)} / ${playingDuration.toFixed(2)} s`;
        frameId = requestAnimationFrame(tick);
    };
    frameId = requestAnimationFrame(tick);
}

/* ----------------------------------------------------------- drawing */

const canvas = $('[data-canvas]');
let columns = null;
function measure() {
    const rect = canvas.getBoundingClientRect(), dpr = window.devicePixelRatio || 1;
    canvas.width = Math.max(1, Math.round(rect.width * dpr));
    canvas.height = Math.max(1, Math.round(rect.height * dpr));
    columns = null;
}
function computeColumns() {
    const samples = state.render.samples, width = canvas.width, mins = new Float32Array(width), maxs = new Float32Array(width);
    const per = samples.length / width;
    for (let x = 0; x < width; x++) {
        let lo = 1, hi = -1;
        const a = Math.floor(x * per), b = Math.max(a + 1, Math.floor((x + 1) * per));
        for (let i = a; i < b && i < samples.length; i++) { const v = samples[i]; if (v < lo) lo = v; if (v > hi) hi = v; }
        if (lo > hi) { lo = 0; hi = 0; }
        mins[x] = lo; maxs[x] = hi;
    }
    columns = { mins, maxs };
}
// The wave in the kit's neutrals: the part already heard in ink, the rest a step quieter, the playhead a 2 px line.
function drawWave(progress) {
    const g = canvas.getContext('2d'), w = canvas.width, h = canvas.height, mid = h / 2, dpr = window.devicePixelRatio || 1;
    const css = getComputedStyle(document.documentElement);
    g.clearRect(0, 0, w, h);
    g.fillStyle = css.getPropertyValue('--ink-3').trim();
    g.fillRect(0, Math.round(mid), w, Math.max(1, Math.round(dpr)));
    if (!state.render) return;
    if (!columns || columns.mins.length !== w) computeColumns();
    const ink = css.getPropertyValue('--ink').trim(), rest = css.getPropertyValue('--ink-2').trim();
    const playedTo = progress == null ? -1 : Math.round(progress * w);
    const scale = mid * 0.92;
    for (let x = 0; x < w; x++) {
        g.fillStyle = x <= playedTo ? ink : rest;
        const top = mid - columns.maxs[x] * scale, bottom = mid - columns.mins[x] * scale;
        g.fillRect(x, top, 1, Math.max(1, bottom - top));
    }
    if (playedTo >= 0) { g.fillStyle = ink; g.fillRect(Math.min(w - 2, playedTo), 0, Math.max(2, Math.round(dpr) * 2), h); }
}

/* ---------------------------------------------------------- controls */

const fmt = {
    hz: f => (f < 1000 ? `${Math.round(f)} Hz` : `${(f / 1000).toFixed(1)} kHz`),
    secs: s => (s < 1 ? `${Math.round(s * 1000)} ms` : `${s.toFixed(2)} s`),
    pct: v => `${Math.round(v * 100)}%`,
    offPct: v => (v < 0.005 ? 'Off' : `${Math.round(v * 100)}%`),
};
const pitchHz = p => 40 * Math.pow(2, p * 8);
const ADVANCED = {
    pitch:         { label: 'Pitch', hint: 'Starting frequency', show: v => fmt.hz(pitchHz(v)) },
    slide:         { label: 'Slide', hint: 'Bends the pitch up or down over time', show: v => (Math.abs(v) < 0.005 ? 'None' : `${v > 0 ? '+' : '−'}${Math.abs(signedSq(v) * 8).toFixed(2)} oct/s`) },
    deltaSlide:    { label: 'Delta slide', hint: 'Speeds the slide up or slows it down', show: v => (Math.abs(v) < 0.005 ? 'None' : `${v > 0 ? '+' : '−'}${Math.abs(signedSq(v) * 16).toFixed(2)} oct/s²`) },
    jump:          { label: 'Pitch jump', hint: 'A sudden step in pitch partway through', show: v => (Math.abs(v) < 0.005 ? 'None' : `${v > 0 ? '+' : '−'}${Math.abs(v * 24).toFixed(1)} semitones`) },
    jumpTime:      { label: 'Pitch jump time', hint: 'When the jump happens', show: v => fmt.secs(0.01 + sq(v)) },
    repeat:        { label: 'Repeat time', hint: 'Restarts the pitch movement on a loop', show: v => (v < 0.01 ? 'Off' : `every ${fmt.secs(0.03 + 0.6 * sq(1 - v))}`) },
    attack:        { label: 'Attack', hint: 'Time to fade in', show: v => fmt.secs(sq(v) * 1.0) },
    decay:         { label: 'Decay', hint: 'Time to fall from the peak to the sustain volume', show: v => fmt.secs(sq(v) * 1.5) },
    sustain:       { label: 'Sustain', hint: 'How long the body of the sound holds', show: v => fmt.secs(sq(v) * 2.0) },
    release:       { label: 'Release', hint: 'Time to fade out at the end', show: v => fmt.secs(sq(v) * 2.5) },
    sustainVolume: { label: 'Sustain volume', hint: 'Loudness of the body after the first hit', show: fmt.pct },
    tremolo:       { label: 'Tremolo', hint: 'Pulses the volume; more is deeper and faster', show: fmt.offPct },
    noise:         { label: 'Noise', hint: 'Mixes hiss into the tone', show: fmt.offPct },
    crush:         { label: 'Bit crush', hint: 'Fewer bits and samples, for a lo-fi sound', show: v => (v < 0.001 ? 'Off' : `${Math.round(16 - v * 13)}-bit`) },
    delay:         { label: 'Delay', hint: 'Echo amount; more also spaces the echoes further apart', show: fmt.offPct },
    cutoff:        { label: 'Low-pass cutoff', hint: 'Removes the highs above this frequency', show: v => { const hz = 120 * Math.pow(2, v * 7.5); return hz >= state.settings.sampleRate * 0.45 ? 'Open' : fmt.hz(hz); } },
    volume:        { label: 'Volume', hint: 'Level of the sound in the file', show: fmt.pct },
};
const GROUPS = [
    ['Pitch and movement', ['pitch', 'slide', 'deltaSlide', 'jump', 'jumpTime', 'repeat']],
    ['Volume shape', ['attack', 'decay', 'sustain', 'release', 'sustainVolume']],
    ['Texture and effects', ['tremolo', 'noise', 'crush', 'delay', 'cutoff', 'volume']],
];

// Length scales the four envelope stages together; Punch is how far the body drops below the first hit.
const MACROS = {
    length: {
        get: p => Math.sqrt(clamp((timing(p).total - 0.03) / 4, 0, 1)),
        set: (p, v) => {
            const target = 0.03 + sq(v) * 4, now = timing(p);
            if (now.total < 0.012) return { ...p, sustain: clamp(Math.sqrt(target / 2), 0, 1) };
            const k = Math.sqrt(target / now.total);
            return { ...p, attack: clamp(p.attack * k, 0, 1), decay: clamp(p.decay * k, 0, 1), sustain: clamp(p.sustain * k, 0, 1), release: clamp(p.release * k, 0, 1) };
        },
        show: () => fmt.secs(durationOf(state.current.params)),
    },
    punch: {
        get: p => 1 - p.sustainVolume,
        set: (p, v) => ({ ...p, sustainVolume: 1 - v }),
        show: v => fmt.pct(v),
    },
};
const SIMPLE = [
    { key: 'pitch', label: 'Pitch', hint: 'How high or low it sounds' },
    { key: 'length', label: 'Length', hint: 'How long the sound lasts' },
    { key: 'cutoff', label: 'Brightness', hint: 'Muffled and dull, or sharp and bright' },
    { key: 'punch', label: 'Punch', hint: 'How hard it hits at the start' },
    { key: 'tremolo', label: 'Wobble', hint: 'A shaky, pulsing volume' },
    { key: 'crush', label: 'Crunch', hint: 'Gritty, old-game-console grain' },
    { key: 'delay', label: 'Echo', hint: 'Repeats that trail off after the sound' },
    { key: 'volume', label: 'Volume', hint: 'How loud the sound is in the file' },
];

const sliders = [];
let sliderId = 0;
function makeSlider({ label, hint, min, max, get, set, show }) {
    const id = `sfx-c${++sliderId}`;
    const wrap = document.createElement('div');
    wrap.className = 'sfx-slider';
    wrap.innerHTML = `<div class="sfx-slider-top"><label for="${id}"></label><output class="tk-mono" for="${id}"></output></div><input id="${id}" type="range" min="0" max="1000" step="1" aria-describedby="${id}-h"><span class="sfx-hint" id="${id}-h"></span>`;
    wrap.querySelector('label').textContent = label;
    wrap.querySelector('.sfx-hint').textContent = hint;
    const input = wrap.querySelector('input'), output = wrap.querySelector('output');
    const toValue = pos => min + (pos / 1000) * (max - min);
    const entry = {
        sync(skipInput) {
            const v = get(state.current.params);
            if (!skipInput) input.value = String(Math.round(((v - min) / (max - min)) * 1000));
            const text = show(v);
            output.textContent = text;
            input.setAttribute('aria-valuetext', text);
        },
    };
    input.addEventListener('input', () => { edit(p => set(p, toValue(Number(input.value))), entry); });
    input.addEventListener('change', () => { if (state.settings.autoplay) play(); });
    sliders.push(entry);
    return wrap;
}

const simpleBox = $('[data-simple]');
for (const item of SIMPLE) {
    const macro = MACROS[item.key];
    simpleBox.append(makeSlider({
        label: item.label, hint: item.hint, min: 0, max: 1,
        get: macro ? macro.get : p => p[item.key],
        set: macro ? macro.set : (p, v) => ({ ...p, [item.key]: v }),
        show: macro ? macro.show : ADVANCED[item.key].show,
    }));
}
const groupsBox = $('[data-groups]');
for (const [title, keys] of GROUPS) {
    const heading = document.createElement('h3');
    heading.className = 'tk-head';
    heading.textContent = title;
    const grid = document.createElement('div');
    grid.className = 'sfx-sliders';
    for (const key of keys) {
        const spec = ADVANCED[key], range = PARAMS[key];
        grid.append(makeSlider({ label: spec.label, hint: spec.hint, min: range.min, max: range.max, get: p => p[key], set: (p, v) => ({ ...p, [key]: v }), show: spec.show }));
    }
    groupsBox.append(heading, grid);
}

// Waveform shapes, each shown as a small picture of its outcome.
const SHAPE_ICONS = {
    square: 'M2 15V5h9v10h9V5h9v10h9',
    sawtooth: 'M2 15L11 5v10l9-10v10l9-10v10l9-10',
    triangle: 'M2 10l4.5-5 9 10 9-10 9 10 4.5-5',
    sine: 'M2 10c3-7 6-7 9 0s6 7 9 0 6-7 9 0 6 7 9 0',
    noise: 'M2 10l2-4 2 7 2-9 2 11 2-6 2 3 2-8 2 10 2-5 2 2 2-7 2 9 2-4 2 5 2-8 2 6 2-3 2 1',
};
const SHAPE_NAMES = { square: 'Square', sawtooth: 'Saw', triangle: 'Triangle', sine: 'Sine', noise: 'Noise' };
const shapesBox = $('[data-shapes]');
for (const wave of WAVES) {
    const b = document.createElement('button');
    b.type = 'button'; b.className = 'sfx-shape'; b.dataset.shape = wave; b.setAttribute('role', 'radio');
    b.innerHTML = `<svg viewBox="0 0 40 20" aria-hidden="true"><path d="${SHAPE_ICONS[wave]}"/></svg><span>${SHAPE_NAMES[wave]}</span>`;
    b.addEventListener('click', () => { edit(p => ({ ...p, wave })); if (state.settings.autoplay) play(); });
    shapesBox.append(b);
}
radioKeys(shapesBox, '[role="radio"]');

function syncShapes() {
    for (const b of shapesBox.querySelectorAll('[data-shape]')) {
        const on = b.dataset.shape === state.current.params.wave;
        b.setAttribute('aria-checked', String(on));
        b.tabIndex = on ? 0 : -1;
    }
}

// Arrow keys move between options of a radio group, as native radios do.
function radioKeys(group, selector) {
    group.addEventListener('keydown', event => {
        if (!['ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown'].includes(event.key)) return;
        const items = [...group.querySelectorAll(selector)];
        const i = items.indexOf(document.activeElement);
        if (i < 0) return;
        event.preventDefault();
        const next = items[(i + (['ArrowRight', 'ArrowDown'].includes(event.key) ? 1 : items.length - 1)) % items.length];
        next.focus(); next.click();
    });
}

/* ------------------------------------------------------------- state */

let renderQueued = false, renderDirty = false;
function rerender() {
    renderDirty = true;
    if (renderQueued) return;
    renderQueued = true;
    requestAnimationFrame(() => {
        renderQueued = false;
        if (renderDirty) renderNow();
    });
}
function renderNow() {
    renderDirty = false;
    const c = state.current;
    const { buffer, samples } = renderBuffer(c.params, c.seed, state.settings.sampleRate);
    const peak = peakOf(samples);
    state.render = { buffer, samples, peak, duration: buffer.duration };
    columns = null;
    $('[data-duration]').textContent = buffer.duration.toFixed(2);
    $('[data-peak]').textContent = peak > 0 ? (20 * Math.log10(peak)).toFixed(1).replace('-', '−') : '−∞';
    $('[data-peak-wrap]').dataset.clip = String(peak >= 0.999);
    if (!source) drawWave(null);
}

function sameSound(a, b) { return a && b && a.seed === b.seed && JSON.stringify(normalizeParams(a.params)) === JSON.stringify(normalizeParams(b.params)); }
function syncHeader() {
    const c = state.current, saved = state.library.find(s => s.id === c.savedId);
    if (saved) c.name = saved.name;
    $('[data-name]').textContent = c.name;
    const el = $('[data-sound-state]');
    const st = !saved ? 'new' : sameSound(saved, c) ? 'saved' : 'changed';
    el.dataset.state = st;
    el.textContent = st === 'new' ? 'Not saved' : st === 'saved' ? '✓ Saved' : 'Changed since you saved it';
    $('[data-save-label]').textContent = st === 'changed' ? 'Save changes' : 'Save sound';
    $('[data-save]').disabled = st === 'saved';
    for (const b of root.querySelectorAll('[data-gen]')) b.setAttribute('aria-pressed', String(b.dataset.gen === c.kind));
    tellBar();
}
function syncControls(active) {
    for (const s of sliders) s.sync(s === active);
    syncShapes();
}
function edit(change, active) {
    state.current.params = normalizeParams(change(state.current.params));
    syncControls(active);
    syncHeader();
    rerender();
    persistCurrent();
}
function setCurrent(sound, { autoplay = state.settings.autoplay } = {}) {
    state.current = { name: sound.name, kind: sound.kind, seed: sound.seed, params: normalizeParams(sound.params), savedId: sound.savedId ?? null };
    syncControls(null);
    syncHeader();
    renderNow();
    persistCurrent();
    if (autoplay) play();
}
function generate(kind) {
    const params = normalizeParams({ ...Object.fromEntries(Object.entries(PARAMS).map(([k, s]) => [k, s.def])), ...GENERATORS[kind]() });
    state.selectedId = null;
    setCurrent({ name: KINDS[kind], kind, seed: newSeed(), params, savedId: null }, { autoplay: true });
    renderList();
}

/* ------------------------------------------------------ saved sounds */

const list = $('[data-list]');
function uniqueName(base, within = state.library) {
    const names = new Set(within.map(s => s.name));
    if (!names.has(base)) return base;
    let n = 2;
    while (names.has(`${base} ${n}`)) n++;
    return `${base} ${n}`;
}
function renderList() {
    list.textContent = '';
    for (const sound of state.library) {
        const li = document.createElement('li');
        li.className = 'sfx-item';
        li.id = `sfx-item-${sound.id}`;
        li.dataset.id = sound.id;
        li.setAttribute('role', 'option');
        li.setAttribute('aria-selected', String(sound.id === state.selectedId));
        const name = document.createElement('span');
        name.className = 'sfx-item-name';
        name.textContent = sound.name;
        const dur = document.createElement('span');
        dur.className = 'sfx-item-dur tk-mono';
        dur.textContent = `${durationOf(sound.params).toFixed(2)} s`;
        li.append(name, dur);
        list.append(li);
    }
    const count = state.library.length;
    $('[data-count]').textContent = count ? String(count) : '';
    $('[data-empty]').hidden = count > 0;
    list.hidden = count === 0;
    if (state.selectedId) list.setAttribute('aria-activedescendant', `sfx-item-${state.selectedId}`);
    else list.removeAttribute('aria-activedescendant');
    const index = state.library.findIndex(s => s.id === state.selectedId);
    $('[data-tools]').hidden = count === 0;
    for (const b of root.querySelectorAll('[data-rename],[data-duplicate],[data-delete]')) b.disabled = index < 0;
    $('[data-up]').disabled = index <= 0;
    $('[data-down]').disabled = index < 0 || index >= count - 1;
    $('[data-export]').disabled = count === 0;
    const selected = index >= 0 ? list.children[index] : null;
    if (selected) selected.scrollIntoView({ block: 'nearest' });
    if ($('[data-sheet="export"]').open) renderExport();
    tellBar();
}
function select(id, { autoplay = state.settings.autoplay } = {}) {
    const sound = state.library.find(s => s.id === id);
    if (!sound) return;
    state.selectedId = id;
    setCurrent({ ...sound, savedId: sound.id }, { autoplay });
    renderList();
}
function step(delta) {
    if (!state.library.length) return;
    const i = state.library.findIndex(s => s.id === state.selectedId);
    const next = i < 0 ? (delta > 0 ? 0 : state.library.length - 1) : clamp(i + delta, 0, state.library.length - 1);
    if (next !== i) select(state.library[next].id);
}
function save() {
    const c = state.current;
    const existing = state.library.find(s => s.id === c.savedId);
    if (existing) {
        const updated = { ...existing, kind: c.kind, seed: c.seed, params: normalizeParams(c.params) };
        if (!commitLibrary(state.library.map(s => (s.id === existing.id ? updated : s)))) return;
        status(`Saved changes to ${existing.name}`);
    } else {
        const sound = { id: uid(), name: uniqueName(c.name), kind: c.kind, seed: c.seed, params: normalizeParams(c.params) };
        if (!commitLibrary([...state.library, sound])) return;
        c.savedId = sound.id;
        state.selectedId = sound.id;
        status(`Saved ${sound.name}`);
    }
    renderList();
    syncHeader();
    persistCurrent();
}
let renaming = null; // the finish function of a rename in progress
function rename() {
    const index = state.library.findIndex(s => s.id === state.selectedId);
    if (index < 0) return;
    if (!$('[data-sheet="saved"]').open) showSheet('saved');
    const sound = state.library[index], li = list.children[index], nameEl = li.querySelector('.sfx-item-name');
    const input = document.createElement('input');
    input.value = sound.name; input.maxLength = 80; input.setAttribute('aria-label', 'Sound name'); input.dataset.renameInput = '';
    nameEl.replaceWith(input);
    input.focus(); input.select();
    let done = false;
    const finish = commit => {
        if (done) return;
        done = true;
        renaming = null;
        const value = input.value.trim().slice(0, 80);
        if (commit && value && value !== sound.name && commitLibrary(state.library.map(s => (s.id === sound.id ? { ...s, name: value } : s)))) status(`Renamed to ${value}`);
        renderList();
        syncHeader();
        persistCurrent();
        list.focus();
    };
    renaming = finish;
    input.addEventListener('keydown', event => { if (event.key === 'Enter') { event.preventDefault(); finish(true); } });
    input.addEventListener('blur', () => finish(true));
    input.addEventListener('click', event => event.stopPropagation());
}
function duplicate() {
    const index = state.library.findIndex(s => s.id === state.selectedId);
    if (index < 0) return;
    const src = state.library[index];
    const copy = { id: uid(), name: uniqueName(`${src.name} copy`), kind: src.kind, seed: src.seed, params: { ...src.params } };
    const next = [...state.library];
    next.splice(index + 1, 0, copy);
    if (!commitLibrary(next)) return;
    select(copy.id, { autoplay: false });
    status(`Made ${copy.name}`);
}
function move(delta) {
    const i = state.library.findIndex(s => s.id === state.selectedId), j = i + delta;
    if (i < 0 || j < 0 || j >= state.library.length) return;
    const next = [...state.library];
    [next[i], next[j]] = [next[j], next[i]];
    if (!commitLibrary(next)) return;
    renderList();
    persistCurrent();
}
// The deleted sound stays in the editor as an unsaved draft and nothing is selected, so what you hear, what
// the list highlights and what the toolbar acts on never disagree. Undo puts it back where it was.
function remove() {
    const index = state.library.findIndex(s => s.id === state.selectedId);
    if (index < 0) return;
    const gone = state.library[index];
    if (!commitLibrary(state.library.filter(s => s.id !== gone.id))) return;
    if (state.current.savedId === gone.id) state.current.savedId = null;
    state.selectedId = null;
    renderList();
    syncHeader();
    persistCurrent();
    status(`Deleted ${gone.name}`, { action: { label: 'Undo', run: () => {
        const next = [...state.library];
        next.splice(Math.min(index, next.length), 0, { ...gone, name: uniqueName(gone.name) });
        if (!commitLibrary(next)) return;
        select(gone.id, { autoplay: false });
        status(`Restored ${gone.name}`);
    } } });
}

/* ------------------------------------------------- the bar and sheets */

// The page that frames the tool draws its bar: the sound's name with the saved sounds under it, Export and Save to
// Library. It is told what to show whenever that changes, and it tells the tool what was pressed.
let told = '';
function tellBar() {
    if (!frame || !state.current) return;
    const bar = {
        set: state.current.name,
        sets: state.library.map(s => ({ id: s.id, name: s.name, current: s.id === state.current.savedId })),
        setsLabel: 'Saved sounds', manage: 'Manage saved sounds…', export: true, library: true,
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
    const line = sheet.querySelector('.tk-status');
    line.replaceChildren();
    line.classList.remove('is-error');
    $('[data-status]').hidden = true; // the toast would sit under the sheet's dimmed ground; the sheet has its own line
    sheet.showModal();
    // The list of saved sounds takes the focus, so the arrow keys step through it; elsewhere the first control does,
    // never a scrolling body, and never a text field, which on a phone would raise the keyboard over the sheet.
    // The list scrolls by itself; a closed sheet cannot scroll it, so the open sound is brought into view here.
    if (name === 'saved' && state.library.length) { list.focus(); list.querySelector('[aria-selected="true"]')?.scrollIntoView({ block: 'nearest' }); return; }
    const first = sheet.querySelector('.tk-sheet-body :is(button, select, input:not([type="text"], [type="file"])):not(:disabled)')
        ?? sheet.querySelector('.tk-sheet-foot button:not(:disabled)');
    first?.focus();
}

function showPane(name) {
    state.settings.view = name;
    root.dataset.view = name;
    for (const b of root.querySelectorAll('[data-view-opt]')) b.setAttribute('aria-pressed', String(b.dataset.viewOpt === name));
}

/* ---------------------------------------------------------- exports */

function download(bytes, name, type) {
    const url = URL.createObjectURL(new Blob([bytes], { type }));
    const link = document.createElement('a');
    link.href = url; link.download = name;
    link.click();
    setTimeout(() => URL.revokeObjectURL(url), 60000);
}
function downloadWav() {
    const rate = state.settings.sampleRate, name = fileName(state.current.name, 'wav');
    download(encodeWav(renderSamples(state.current.params, state.current.seed, rate), rate), name, 'audio/wav');
    status(`Downloaded ${name}`);
}
function zipFiles(sounds, rate) {
    const used = new Set(), width = String(sounds.length).length < 2 ? 2 : String(sounds.length).length;
    return sounds.map((s, i) => {
        let name = fileName(`${String(i + 1).padStart(width, '0')} ${s.name}`, 'wav');
        let n = 2;
        while (used.has(name.toLowerCase())) name = fileName(`${String(i + 1).padStart(width, '0')} ${s.name} (${n++})`, 'wav');
        used.add(name.toLowerCase());
        return { name, data: encodeWav(renderSamples(s.params, s.seed, rate), rate) };
    });
}
function downloadZip() {
    if (!state.library.length) return;
    const rate = state.settings.sampleRate;
    download(makeZip(zipFiles(state.library, rate)), 'sound-library.zip', 'application/zip');
    status(`Downloaded ${plural(state.library.length, 'sound')} as sound-library.zip`);
}
function exportJson() {
    const text = JSON.stringify(libraryJson(state.library, state.settings.sampleRate), null, 2);
    download(new TextEncoder().encode(text), 'sound-library.json', 'application/json');
    status('Exported sound-library.json');
}
async function importJson(file) {
    try {
        const sounds = parseLibrary(JSON.parse(await file.text()));
        if (!sounds.length) throw new Error('That file has no sounds in it.');
        const next = [...state.library];
        for (const sound of sounds) { sound.name = uniqueName(sound.name, next); next.push(sound); }
        if (!commitLibrary(next)) return;
        select(sounds[0].id, { autoplay: false });
        status(`Imported ${plural(sounds.length, 'sound')}`);
    } catch (error) {
        status(error instanceof SyntaxError ? 'That file is not valid JSON.' : error.message, { tone: 'error', ms: 6000 });
    }
}

// Export: this sound as a WAV, or every saved sound as one ZIP. The button says which.
const exportChoice = () => root.querySelector('[name="export-as"]:checked')?.value || 'wav';
function renderExport() {
    const rate = state.settings.sampleRate, count = state.library.length;
    const zip = root.querySelector('[name="export-as"][value="zip"]');
    zip.disabled = count === 0;
    if (!count && zip.checked) root.querySelector('[name="export-as"][value="wav"]').checked = true;
    $('[data-export-wav-name]').textContent = `${fileName(state.current.name, 'wav')} · ${durationOf(state.current.params).toFixed(2)} s · ${rate.toLocaleString('en')} Hz`;
    $('[data-export-zip-name]').textContent = count ? `sound-library.zip · ${plural(count, 'WAV file')}` : 'Nothing is saved yet. Save a sound first.';
    $('[data-export-go]').textContent = exportChoice() === 'zip' ? 'Download all as ZIP' : 'Download WAV';
}
function openExport() {
    renderExport();
    showSheet('export');
}

// Save to Library: the sound as a WAV, its settings as a file Import opens again, or both, into a folder the
// account may upload to.
let folders = null;
// A save holds the sheet until the Library answers: its choices stay as sent, also when the sheet is reopened.
let saving = false;
// A save that fails after its sheet was closed is said at once, and again in the sheet the next time it opens.
let failure = '';
function setLibrary({ message = '', error = false } = {}) {
    const none = Array.isArray(folders) && !folders.length;
    const picked = $('[data-save-wav]').checked || $('[data-save-settings]').checked;
    // With no folder to save into there is nothing to choose: the sheet says so and offers no ticks.
    $('[data-save-wav]').closest('fieldset').hidden = none;
    $('[data-folder-field]').hidden = none;
    $('[data-folder-empty]').hidden = !none;
    for (const sel of ['[data-save-wav]', '[data-save-settings]', '[data-folder]']) $(sel).disabled = saving;
    const go = $('[data-library-go]');
    go.disabled = saving || none || !folders || !picked;
    go.textContent = saving ? 'Saving…' : 'Save';
    const line = $('[data-sheet="library"] .tk-status');
    line.textContent = message || (saving ? 'Saving to the Library…' : picked || none ? '' : 'Choose at least one file to save.');
    line.classList.toggle('is-error', error);
}
async function openLibrary() {
    if (!frame?.library) return;
    if (!saving) {
        $('[data-save-wav-name]').textContent = `${fileName(state.current.name, 'wav')} · ${state.settings.sampleRate.toLocaleString('en')} Hz`;
        $('[data-save-settings-name]').textContent = `${fileName(state.current.name, 'json')} · opens again with Import`;
    }
    showSheet('library');
    const kept = failure ? { message: failure, error: true } : {};
    failure = '';
    if (folders) { setLibrary(kept); return; }
    setLibrary({ message: 'Finding your folders…' });
    try {
        folders = await frame.folders();
        const select = $('[data-folder]');
        select.innerHTML = folders.map(f => `<option value="${esc(f.id)}">${'  '.repeat(f.depth)}${esc(f.name)}</option>`).join('');
        if (folders.some(f => f.id === state.settings.folder)) select.value = state.settings.folder;
        setLibrary(kept);
    } catch (error) {
        folders = null;
        setLibrary({ message: `${error.message || 'Your folders could not be read.'} Close this and try again.`, error: true });
    }
}
async function saveToLibrary() {
    if (saving) return;
    // Both files are made here, from the sound as it is when Save is pressed, before anything is sent.
    const folder = $('[data-folder]').value, rate = state.settings.sampleRate;
    const sound = { name: state.current.name, kind: state.current.kind, seed: state.current.seed, params: normalizeParams(state.current.params) };
    const files = [];
    if ($('[data-save-wav]').checked) files.push({ name: fileName(sound.name, 'wav'), blob: new Blob([encodeWav(renderSamples(sound.params, sound.seed, rate), rate)], { type: 'audio/wav' }) });
    if ($('[data-save-settings]').checked) files.push({ name: fileName(sound.name, 'json'), blob: new Blob([JSON.stringify(libraryJson([sound], rate), null, 2)], { type: 'application/json' }) });
    saving = true;
    failure = '';
    setLibrary();
    let failed = null;
    try {
        const saved = await frame.save({ files, folder });
        state.settings.folder = folder;
        persistSettings();
        const text = `Saved ${plural(saved.count, 'file')} to ${folders.find(f => f.id === folder)?.name || 'the Library'}.`;
        $('[data-sheet="library"]').close();
        frame.notice({ text, ...(saved.url ? { link: { label: 'Open in Library', url: saved.url } } : {}) });
        // Where the page draws a bar, its status line says it once, with the link. Elsewhere the tool says it.
        if (!frame.bar) status(text);
    } catch (error) {
        failed = error;
    } finally {
        saving = false;
    }
    if (!failed) return;
    const message = failed.message || 'The files could not be saved. Try again.';
    if ($('[data-sheet="library"]').open) {
        setLibrary({ message, error: true });
        // Save was disabled while it ran, which dropped the focus; give it back so Enter tries again.
        $('[data-library-go]').focus();
        return;
    }
    // The sheet was closed while it saved: the bar says so now (the tool's own line where there is no bar).
    failure = message;
    setLibrary();
    frame.notice({ text: `Not saved to the Library. ${message}`, tone: 'error' });
    if (!frame.bar) status(`Not saved to the Library. ${message}`, { tone: 'error', ms: 8000 });
}

/* ------------------------------------------------------------ wiring */

root.addEventListener('click', event => {
    const t = event.target.closest('button, li[data-id]');
    if (!t || !root.contains(t) || t.disabled) return;
    const d = t.dataset;
    if (d.gen) generate(d.gen);
    else if ('surprise' in d) generate('surprise');
    else if ('nudge' in d) { const c = state.current; setCurrent({ ...c, params: nudgeParams(c.params) }, { autoplay: true }); }
    else if ('play' in d || 'wave' in d) play();
    else if ('save' in d) save();
    else if ('rename' in d) rename();
    else if ('duplicate' in d) duplicate();
    else if ('up' in d) move(-1);
    else if ('down' in d) move(1);
    else if ('delete' in d) remove();
    else if ('export' in d) exportJson();
    else if ('exportGo' in d) { if (exportChoice() === 'zip') downloadZip(); else downloadWav(); }
    else if ('libraryGo' in d) saveToLibrary();
    else if ('openSaved' in d) showSheet('saved');
    else if ('openExport' in d) openExport();
    else if ('openLibrary' in d) openLibrary();
    else if ('sheetClose' in d) t.closest('dialog').close();
    else if (d.viewOpt) { showPane(d.viewOpt); persistSettings(); }
    else if (d.mode) setMode(d.mode);
    else if (t.matches('li[data-id]')) { if (d.id !== state.selectedId) select(d.id); else play(); }
});
root.addEventListener('change', event => {
    const t = event.target;
    if (t.name === 'export-as') { renderExport(); $('[data-sheet="export"] .tk-status').replaceChildren(); }
    else if (t.matches('[data-save-wav], [data-save-settings]')) setLibrary();
});
$('[data-import-file]').addEventListener('change', event => {
    const file = event.target.files[0];
    event.target.value = '';
    if (file) importJson(file);
});

function setMode(mode) {
    state.settings.mode = mode;
    for (const b of root.querySelectorAll('[data-mode]')) { const on = b.dataset.mode === mode; b.setAttribute('aria-checked', String(on)); b.tabIndex = on ? 0 : -1; }
    $('[data-simple]').hidden = mode !== 'simple';
    $('[data-advanced]').hidden = mode !== 'advanced';
    $('[data-mode-note]').textContent = mode === 'simple' ? 'Eight broad controls. Advanced shows every setting.' : 'Every setting. Simple edits these same settings.';
    persistSettings();
}
radioKeys(root.querySelector('.sfx-mode'), '[data-mode]');

const rateSelect = $('[data-rate]'), master = $('[data-master]'), autoplay = $('[data-autoplay]');
rateSelect.value = String(state.settings.sampleRate);
rateSelect.addEventListener('change', () => {
    state.settings.sampleRate = Number(rateSelect.value);
    persistSettings();
    stop();
    renderNow();
    syncControls(null);
    if (state.settings.autoplay) play();
});
const showMaster = () => { master.value = String(Math.round(state.settings.master * 100)); $('[data-master-out]').textContent = fmt.pct(state.settings.master); };
master.addEventListener('input', () => {
    state.settings.master = Number(master.value) / 100;
    $('[data-master-out]').textContent = fmt.pct(state.settings.master);
    if (audio) audio.gain.gain.setTargetAtTime(state.settings.master, audio.ctx.currentTime, 0.01);
    persistSettings();
});
autoplay.checked = state.settings.autoplay;
autoplay.addEventListener('change', () => { state.settings.autoplay = autoplay.checked; persistSettings(); });

// Escape in a rename field cancels the rename and nothing else: it must not close the sheet the field is in, nor
// reach the frame's bridge, which hears Escape on the document as a wish to leave the tool. So it is taken on the
// window, on the way down.
window.addEventListener('keydown', event => {
    if (event.key !== 'Escape' || !event.target.matches?.('[data-rename-input]')) return;
    event.preventDefault();
    event.stopPropagation();
    renaming?.(false);
}, true);
$('[data-sheet="saved"]').addEventListener('cancel', event => { if (renaming) { event.preventDefault(); renaming(false); } });

// Keyboard: only while focus is inside this tool, and never in place of what a field or control does itself. While a
// sheet is open, only its list of saved sounds takes the tool's keys.
const EDITABLE = 'input, select, textarea, [contenteditable=""], [contenteditable="true"], [role="radio"], [role="slider"]';
const onKey = event => {
    const t = event.target;
    if (!(t === document.body || root.contains(t))) return;
    if (event.defaultPrevented || event.metaKey || event.ctrlKey || event.altKey) return;
    if (t.closest && t.closest(EDITABLE)) return;
    const inSheet = Boolean(openSheet());
    if (inSheet && t !== list) return;
    const onButton = t.closest && t.closest('button, a[href]');
    if (event.key === ' ' && !onButton) { event.preventDefault(); play(); }
    else if ((event.key === 's' || event.key === 'S') && !event.repeat && !inSheet) { event.preventDefault(); if (!$('[data-save]').disabled) save(); else status(`${state.current.name} is already saved`); }
    else if (event.key === 'ArrowDown' || event.key === 'ArrowRight') { event.preventDefault(); step(1); }
    else if (event.key === 'ArrowUp' || event.key === 'ArrowLeft') { event.preventDefault(); step(-1); }
    else if (event.key === 'Home' && t === list) { event.preventDefault(); if (state.library.length) select(state.library[0].id); }
    else if (event.key === 'End' && t === list) { event.preventDefault(); if (state.library.length) select(state.library[state.library.length - 1].id); }
    else if (event.key === 'F2' && state.selectedId) { event.preventDefault(); rename(); }
    else if (event.key === 'Delete' && t === list && state.selectedId) { event.preventDefault(); remove(); }
};
document.addEventListener('keydown', onKey);
// Pressing empty space keeps keyboard focus in the tool, so the shortcuts keep working.
root.addEventListener('pointerdown', event => {
    if (openSheet()) return;
    if (!event.target.closest('button, input, select, textarea, label, a, [role="listbox"], [tabindex]:not([data-sfx])')) requestAnimationFrame(() => root.focus({ preventScroll: true }));
});

new ResizeObserver(() => { measure(); drawWave(null); }).observe(canvas);
// Redraw in the new colours when the host or the system switches themes.
new MutationObserver(() => drawWave(null)).observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });
window.matchMedia('(prefers-color-scheme: dark)').addEventListener?.('change', () => drawWave(null));

// What the bar's controls do. Where no bar is drawn, the sound pane's own row of buttons does the same.
frame?.on('open-set', ({ id }) => select(id));
frame?.on('manage-sets', () => showSheet('saved'));
frame?.on('export', () => openExport());
frame?.on('library', () => openLibrary());
$('[data-own-bar]').hidden = Boolean(frame?.bar);
$('[data-open-library]').hidden = !frame?.library;

/* ------------------------------------------------------------- start */

setMode(state.settings.mode);
showPane(state.settings.view);
showMaster();
if (!state.current) {
    const params = normalizeParams({ ...Object.fromEntries(Object.entries(PARAMS).map(([k, s]) => [k, s.def])), ...GENERATORS.coin() });
    state.current = { name: KINDS.coin, kind: 'coin', seed: newSeed(), params, savedId: null };
}
measure();
syncControls(null);
syncHeader();
renderNow();
renderList();
root.focus({ preventScroll: true });
})();
