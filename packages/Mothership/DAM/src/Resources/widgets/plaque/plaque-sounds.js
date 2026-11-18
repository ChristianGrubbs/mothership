// Plaque Maker built-in sounds, synthesized at 48 kHz with Web Audio so no audio file ships with the tool.
// Each recipe is deterministic (no Math.random): the same sound renders the same samples every time.
(function (root) {
    'use strict';
    const RATE = 48000;
    const recipes = {
        chime: { length: 1.4, build(ctx) { [880, 1318.5, 1760].forEach((hz, i) => tone(ctx, { hz, at: i * 0.07, decay: 1.1, gain: 0.22, type: 'sine' })); } },
        bell: { length: 1.8, build(ctx) { [523.25, 1046.5, 1567.98, 2637].forEach((hz, i) => tone(ctx, { hz, at: 0, decay: 1.6 - i * 0.3, gain: 0.25 / (i + 1), type: 'sine' })); } },
        pop: { length: 0.25, build(ctx) { tone(ctx, { hz: 520, to: 180, at: 0, decay: 0.16, gain: 0.5, type: 'sine', glide: 0.08 }); } },
        click: { length: 0.12, build(ctx) { tone(ctx, { hz: 2200, to: 1200, at: 0, decay: 0.05, gain: 0.35, type: 'triangle', glide: 0.03 }); noise(ctx, { at: 0, decay: 0.03, gain: 0.25, hz: 4000 }); } },
        coin: { length: 0.7, build(ctx) { tone(ctx, { hz: 987.77, at: 0, decay: 0.09, gain: 0.25, type: 'square', hold: 0.08 }); tone(ctx, { hz: 1318.51, at: 0.08, decay: 0.5, gain: 0.22, type: 'square' }); } },
        whoosh: { length: 0.7, build(ctx) { noise(ctx, { at: 0, decay: 0.6, gain: 0.5, hz: 600, to: 3200, attack: 0.25 }); } },
        rise: { length: 1.2, build(ctx) { [0, 0.09, 0.18, 0.27].forEach((at, i) => tone(ctx, { hz: [523.25, 659.25, 783.99, 1046.5][i], at, decay: 0.9 - i * 0.1, gain: 0.2, type: 'triangle' })); } },
    };
    function tone(ctx, { hz, to, at, decay, gain, type, glide = 0.1, hold = 0 }) {
        const osc = ctx.createOscillator(), amp = ctx.createGain();
        osc.type = type;
        osc.frequency.setValueAtTime(hz, at);
        if (to) osc.frequency.exponentialRampToValueAtTime(to, at + glide);
        amp.gain.setValueAtTime(0, at);
        amp.gain.linearRampToValueAtTime(gain, at + 0.005);
        if (hold) amp.gain.setValueAtTime(gain, at + hold);
        amp.gain.exponentialRampToValueAtTime(0.0001, at + hold + decay);
        osc.connect(amp).connect(ctx.destination);
        osc.start(at);
        osc.stop(at + hold + decay + 0.02);
    }
    function noise(ctx, { at, decay, gain, hz, to, attack = 0.002 }) {
        const length = Math.ceil((decay + attack + 0.05) * RATE), buffer = ctx.createBuffer(1, length, RATE), data = buffer.getChannelData(0);
        let seed = 1;
        for (let i = 0; i < length; i++) { seed = (seed * 16807) % 2147483647; data[i] = seed / 1073741823.5 - 1; }
        const src = ctx.createBufferSource(), filter = ctx.createBiquadFilter(), amp = ctx.createGain();
        src.buffer = buffer;
        filter.type = 'bandpass';
        filter.frequency.setValueAtTime(hz, at);
        if (to) filter.frequency.exponentialRampToValueAtTime(to, at + attack + decay);
        amp.gain.setValueAtTime(0, at);
        amp.gain.linearRampToValueAtTime(gain, at + attack);
        amp.gain.exponentialRampToValueAtTime(0.0001, at + attack + decay);
        src.connect(filter).connect(amp).connect(ctx.destination);
        src.start(at);
    }
    const cache = new Map();
    async function builtin(name) {
        if (!recipes[name]) return null;
        if (!cache.has(name)) {
            cache.set(name, (async () => {
                const recipe = recipes[name], ctx = new OfflineAudioContext(2, Math.ceil(recipe.length * RATE), RATE);
                recipe.build(ctx);
                return ctx.startRendering();
            })());
        }
        return cache.get(name);
    }
    const labels = { chime: 'Chime', bell: 'Bell', pop: 'Pop', click: 'Click', coin: 'Coin', whoosh: 'Whoosh', rise: 'Rising notes' };
    root.PlaqueSounds = { RATE, builtin, names: Object.keys(recipes), labels };
}(typeof self !== 'undefined' ? self : this));
