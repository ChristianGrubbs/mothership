import test from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';

// The Plaque Maker loads plaque-core.js and plaque-encode.js as plain <script>s, so both are UMD: globals in the
// browser and module.exports here. These tests hold two contracts: every way into the tool goes through
// validateSet, which refuses bad input without side effects; and drawFrame, the one function preview and export
// both draw with, is a pure function of its arguments.
const require = createRequire(import.meta.url);
const Core = require('../../src/Resources/widgets/plaque/plaque-core.js');
const Encode = require('../../src/Resources/widgets/plaque/plaque-encode.js');

// A canvas context that records every call and property write. measureText is a fixed function of the font size so
// layout runs the same way it does in a browser, just with simpler metrics.
function recorder() {
    const log = [];
    const state = { font: '10px sans-serif' };
    const ctx = new Proxy({}, {
        get(_, key) {
            if (key === 'measureText') return value => ({ width: String(value).length * (parseFloat(state.font.split(' ')[1]) || 10) * 0.55 });
            if (key in state) return state[key];
            return (...args) => { log.push([key, ...args.map(arg => (typeof arg === 'number' ? Math.round(arg * 1e6) / 1e6 : arg))]); };
        },
        set(_, key, value) { state[key] = value; log.push(['=', key, value]); return true; },
    });
    return { ctx, log };
}
const sampleSet = () => {
    const set = Core.newSet('Test');
    const a = Core.fromPreset(Core.PRESETS.find(p => p.id === 'donation-tip'));
    const b = Core.fromPreset(Core.PRESETS.find(p => p.id === 'social-subscribe'));
    set.plaques.push(a, b);
    return set;
};

test('drawFrame is deterministic: the same set and time draw the same calls', () => {
    const set = sampleSet();
    const scene = { output: set.output, plaques: set.plaques, size: { width: 1920, height: 1080 }, background: null };
    const line = Core.timeline(set.plaques);
    for (const t of [0, 0.21, 1.4, 3.1, line.entries[1].start + 1.45, line.duration - 0.01, line.duration]) {
        const first = recorder(), second = recorder();
        Core.drawFrame(first.ctx, scene, t, () => null, d => d);
        Core.drawFrame(second.ctx, scene, t, () => null, d => d);
        assert.deepEqual(first.log, second.log, `frame at ${t}s`);
        assert.ok(first.log.length > 3, `frame at ${t}s draws something`);
    }
});

test('drawFrame reads nothing but its arguments: a frame drawn after others matches a fresh one', () => {
    const set = sampleSet();
    const scene = { output: set.output, plaques: set.plaques, size: { width: 1920, height: 1080 }, background: '#00ff00' };
    const warm = recorder();
    for (let t = 0; t < 4; t += 0.1) Core.drawFrame(warm.ctx, scene, t, () => null, d => d);
    const after = recorder(), fresh = recorder();
    Core.drawFrame(after.ctx, scene, 2.5, () => null, d => d);
    Core.drawFrame(fresh.ctx, scene, 2.5, () => null, d => d);
    assert.deepEqual(after.log, fresh.log);
});

test('the timeline lays plaques end to end and the last instant still has a plaque', () => {
    const set = sampleSet();
    const line = Core.timeline(set.plaques);
    assert.equal(line.entries[1].start, Core.plaqueLength(set.plaques[0]));
    assert.equal(line.duration, Core.plaqueLength(set.plaques[0]) + Core.plaqueLength(set.plaques[1]));
    assert.equal(Core.entryAt(line, line.duration).plaque.id, set.plaques[1].id);
    assert.equal(Core.entryAt(line, line.duration + 0.5), null);
    assert.equal(Core.frameCount(line, 30), Math.round(line.duration * 30));
});

test('the sound schedule places each plaque sound and each click at the time it is drawn', () => {
    const set = sampleSet();
    const { events } = Core.soundSchedule(set.plaques);
    const start = Core.plaqueLength(set.plaques[0]);
    assert.deepEqual(events.map(e => [e.source, Math.round(e.at * 1000)]), [
        ['builtin:coin', 0], ['builtin:chime', Math.round(start * 1000)],
        ['builtin:click', Math.round((start + 1.4) * 1000)], ['builtin:click', Math.round((start + 2.8) * 1000)],
    ]);
});

test('validateSet accepts its own export and round-trips it unchanged', () => {
    const set = sampleSet();
    set.assets.a_logo = { name: 'logo.png', type: 'image/png', data: 'data:image/png;base64,iVBORw0KGgo=' };
    set.plaques[0].visual = { ...set.plaques[0].visual, type: 'image', assetId: 'a_logo' };
    const json = JSON.stringify(Core.exportSet(set));
    const first = Core.validateSet(json);
    assert.equal(first.ok, true);
    assert.deepEqual(first.errors, []);
    assert.equal(JSON.stringify(Core.exportSet(first.set)), json);
});

test('validateSet refuses what is not a current plaque set, and says why', () => {
    const cases = [
        ['{not json', /not valid JSON/],
        ['[]', /JSON object/],
        [JSON.stringify({ format: 'something-else', version: 1, plaques: [] }), /not a plaque set/],
        [JSON.stringify({ format: Core.FORMAT, version: 99, plaques: [] }), /newer Plaque Maker/],
        [JSON.stringify({ format: Core.FORMAT, version: 0, plaques: [] }), /version/],
        [JSON.stringify({ format: Core.FORMAT, version: 1 }), /"plaques"/],
        [JSON.stringify({ format: Core.FORMAT, version: 1, plaques: Array(201).fill({}) }), /at most 200/],
    ];
    for (const [input, reason] of cases) {
        const result = Core.validateSet(input);
        assert.equal(result.ok, false, input.slice(0, 40));
        assert.equal(result.set, null);
        assert.match(result.errors.join(' '), reason);
    }
});

test('validateSet clamps values, drops unknown keys and unsafe assets, and keeps credentials out', () => {
    const tokenKey = 'api' + '_key';
    const raw = {
        format: Core.FORMAT, version: 1, name: 'x', provider: { model: 'm', [tokenKey]: 'nope' },
        output: { mode: 'frame', frame: 'custom', width: 99999, height: 5, fps: 7, anchor: 'nowhere' },
        assets: { a_bad: { name: 'evil', data: 'javascript:alert(1)' }, a_svg: { name: 's.svg', data: 'data:image/svg+xml;base64,PHN2Zy8+' } },
        plaques: [{ title: 'T', timing: { in: -4, hold: 999, out: 'x' }, style: { background: 'red', width: 10 }, visual: { type: 'image', assetId: 'a_bad' }, sound: { source: 'https://example.com/a.mp3' }, [tokenKey]: 'nope' }],
    };
    const { ok, set, errors } = Core.validateSet(raw);
    assert.equal(ok, true);
    assert.equal(JSON.stringify(set).includes('nope'), false);
    assert.equal('provider' in set, false);
    assert.deepEqual(Object.keys(set.assets), ['a_svg']);
    assert.deepEqual([set.output.width, set.output.height, set.output.fps, set.output.anchor], [4096, 16, 30, 'bottom-left']);
    const plaque = set.plaques[0];
    assert.deepEqual(plaque.timing, { in: 0, hold: 60, out: 0.5 });
    assert.equal(plaque.style.background, '#101114');
    assert.equal(plaque.style.width, 240);
    assert.equal(plaque.visual.type, 'icon');
    assert.equal(plaque.sound.source, 'none');
    assert.equal(errors.length, 3);
});

test('validateSet does not change the object it was given', () => {
    const raw = { format: Core.FORMAT, version: 1, plaques: [{ title: 'Keep', timing: { in: 99 } }] };
    const copy = JSON.parse(JSON.stringify(raw));
    Core.validateSet(raw);
    assert.deepEqual(raw, copy);
});

test('topic suggestions match English keywords, including simple plurals', () => {
    assert.equal(Core.suggestTopics('Thanks for the donations and tips!')[0].group, 'donation');
    assert.ok(Core.suggestTopics('new videos every week').some(p => p.id === 'topic-new-video'));
    assert.deepEqual(Core.suggestTopics('zzz qqq'), []);
});

test('amounts format with prefix, separators, decimals and suffix', () => {
    const amount = { prefix: '$', suffix: '', decimals: 2, thousands: true };
    assert.equal(Core.formatAmount(amount, 1234567.5), '$1,234,567.50');
    assert.equal(Core.formatAmount({ ...amount, thousands: false, decimals: 0, prefix: '', suffix: ' followers' }, 10000), '10000 followers');
});

test('WAV output is 48 kHz 16-bit PCM with a correct header', () => {
    const rate = 48000, samples = Float32Array.from({ length: 480 }, (_, i) => Math.sin(i / 10));
    const bytes = Encode.wav([samples, samples], rate);
    const view = new DataView(bytes.buffer);
    assert.equal(String.fromCharCode(...bytes.subarray(0, 4)), 'RIFF');
    assert.equal(view.getUint16(20, true), 1);
    assert.equal(view.getUint16(22, true), 2);
    assert.equal(view.getUint32(24, true), 48000);
    assert.equal(view.getUint16(34, true), 16);
    assert.equal(view.getUint32(40, true), 480 * 4);
    assert.equal(bytes.length, 44 + 480 * 4);
});

test('GIF frames use one transparent index, and only for pixels under half alpha', () => {
    const rgba = new Uint8ClampedArray([255, 0, 0, 255, 255, 0, 0, 127, 0, 0, 255, 128, 0, 0, 0, 0]);
    const frame = Encode.gifFrame(rgba, 2, 2);
    assert.equal(frame.indices[1], frame.transparent);
    assert.equal(frame.indices[3], frame.transparent);
    assert.notEqual(frame.indices[0], frame.transparent);
    assert.notEqual(frame.indices[2], frame.transparent);
    assert.deepEqual(Encode.gifDelays(6, 30).reduce((a, b) => a + b, 0), 20);
});

test('zip entries carry their CRC and the archive ends with a central directory', () => {
    const data = new TextEncoder().encode('hello');
    const archive = Encode.zip([{ name: 'a.txt', data }]);
    const view = new DataView(archive.buffer);
    assert.equal(view.getUint32(0, true), 0x04034b50);
    assert.equal(view.getUint32(14, true), Encode.crc32(data));
    assert.equal(view.getUint32(archive.length - 22, true), 0x06054b50);
});

test('validateSet answers, without throwing, for asset references that name Object built-ins', () => {
    const set = Core.exportSet(Core.newSet());
    for (const name of ['constructor', '__proto__', 'toString', 'hasOwnProperty']) {
        const plaque = { ...Core.defaultPlaque('social'), visual: { type: 'image', assetId: name }, sound: { source: `asset:${name}` } };
        plaque.social = { ...plaque.social, pageAssetId: name, platformAssetId: name };
        const result = Core.validateSet({ ...set, plaques: [plaque] });
        assert.equal(result.ok, true, name);
        assert.equal(result.set.plaques[0].sound.source, 'none', name);
        assert.equal(Object.keys(result.set.assets).length, 0, name);
    }
});

test('a GIF keeps the animation length at every offered frame rate', () => {
    for (const fps of [24, 25, 30, 50, 60]) {
        const rate = Encode.gifFps(fps);
        assert.ok(rate <= 50, `${fps} fps renders at ${rate}`);
        const delays = Encode.gifDelays(rate * 3, rate);
        assert.equal(delays.reduce((a, b) => a + b, 0), 300, `${fps} fps lasts 3 s`);
        assert.ok(delays.every(d => d >= 2));
    }
});
