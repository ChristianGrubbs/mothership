import test from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';

// Palette Studio loads palette-core.js as a plain <script>, so the module is UMD (see loop-maker-core.test.mjs).
const require = createRequire(import.meta.url);
const core = require('../../src/Resources/widgets/palette-studio/palette-core.js');

const close = (actual, expected, tolerance, label) => assert.ok(Math.abs(actual - expected) <= tolerance, `${label}: ${actual} is not within ${tolerance} of ${expected}`);
// A seeded generator so the random generators are repeatable.
const seeded = seed => () => { seed = (seed * 1664525 + 1013904223) % 4294967296; return seed / 4294967296; };
const sample = ['#000000', '#FFFFFF', '#FF0000', '#00FF00', '#0000FF', '#BC232C', '#7A5A00', '#2D5BE3', '#808080', '#12AB9C', '#F2B705', '#5C5C5C'];

test('contrast ratios match the WebAIM reference calculator on known pairs', () => {
    // Values as WebAIM's contrast checker prints them (two decimals).
    for (const [fg, bg, expected] of [['#000000', '#FFFFFF', 21], ['#777777', '#FFFFFF', 4.48], ['#767676', '#FFFFFF', 4.54], ['#0000FF', '#FFFFFF', 8.59], ['#FF0000', '#FFFFFF', 4], ['#808080', '#000000', 5.32], ['#FFFFFF', '#FFFFFF', 1]]) {
        close(core.contrastRatio(fg, bg), expected, 0.005, `${fg} on ${bg}`);
        close(core.contrastRatio(bg, fg), expected, 0.005, `${bg} on ${fg} (order must not matter)`);
    }
});

test('WCAG results use the unrounded ratio and separate normal from large text', () => {
    const grey = core.wcag(core.contrastRatio('#777777', '#FFFFFF'));
    assert.deepEqual(grey, { aaNormal: false, aaLarge: true, aaaNormal: false, aaaLarge: false });
    const darker = core.wcag(core.contrastRatio('#767676', '#FFFFFF'));
    assert.deepEqual(darker, { aaNormal: true, aaLarge: true, aaaNormal: false, aaaLarge: true });
    assert.deepEqual(core.wcag(7), { aaNormal: true, aaLarge: true, aaaNormal: true, aaaLarge: true });
    assert.deepEqual(core.wcag(2.99), { aaNormal: false, aaLarge: false, aaaNormal: false, aaaLarge: false });
});

test('every swatch is paired with every other swatch once', () => {
    const pairs = core.contrastPairs(['#000000', '#FFFFFF', '#FF0000', '#0000FF']);
    assert.equal(pairs.length, 6);
    assert.deepEqual(pairs.map(pair => `${pair.first}-${pair.second}`), ['0-1', '0-2', '0-3', '1-2', '1-3', '2-3']);
});

test('the label colour is whichever of black or white contrasts more', () => {
    assert.equal(core.textOn('#FFFFFF'), '#000000');
    assert.equal(core.textOn('#000000'), '#FFFFFF');
    assert.equal(core.textOn('#F2B705'), '#000000');
    assert.equal(core.textOn('#2D5BE3'), '#FFFFFF');
    for (const hex of sample) {
        const label = core.textOn(hex), other = label === '#000000' ? '#FFFFFF' : '#000000';
        assert.ok(core.contrastRatio(hex, label) >= core.contrastRatio(hex, other), hex);
    }
});

test('OKLCH matches published reference values', () => {
    // CSS Color 4 reference conversions (oklch.com agrees to the shown precision).
    const red = core.rgbToOklch(core.parseHex('#FF0000'));
    close(red.L, 0.62796, 0.0005, 'red L'); close(red.C, 0.25768, 0.0005, 'red C'); close(red.h, 29.23, 0.05, 'red h');
    const blue = core.rgbToOklch(core.parseHex('#0000FF'));
    close(blue.L, 0.45201, 0.0005, 'blue L'); close(blue.C, 0.31321, 0.0005, 'blue C'); close(blue.h, 264.05, 0.05, 'blue h');
    const white = core.rgbToOklch(core.parseHex('#FFFFFF'));
    close(white.L, 1, 0.0001, 'white L'); close(white.C, 0, 0.0001, 'white C');
});

test('every representation converts back to the same hex', () => {
    for (const hex of sample) {
        const described = core.describe(hex);
        assert.equal(core.toHex(core.hslToRgb(core.rgbToHsl(core.parseHex(hex)))), hex, `${hex} through HSL`);
        assert.equal(core.toHex(core.oklchToRgb(core.rgbToOklch(core.parseHex(hex)))), hex, `${hex} through OKLCH`);
        // The rounded text the editor shows must parse back to the same colour too.
        assert.equal(core.toHex(core.parseRgb(described.rgbText)), hex, `${hex} RGB text`);
        assert.equal(core.toHex(core.hslToRgb(core.parseHsl(described.hslText))), hex, `${hex} HSL text`);
        assert.equal(core.toHex(core.oklchToRgb(core.parseOklch(described.oklchText))), hex, `${hex} OKLCH text`);
    }
});

test('incomplete or malformed input parses to null so the last valid colour holds', () => {
    for (const text of ['', '#', '#12', '#12345', '#GGGGGG', '12345g']) assert.equal(core.parseHex(text), null, `hex ${JSON.stringify(text)}`);
    for (const text of ['', '12, 34', '12, 34,', '256, 0, 0', '-1, 0, 0', '1, 2, x']) assert.equal(core.parseRgb(text), null, `rgb ${JSON.stringify(text)}`);
    for (const text of ['10, 50%', '10, 120%, 50%', 'a, 1, 1']) assert.equal(core.parseHsl(text), null, `hsl ${JSON.stringify(text)}`);
    for (const text of ['62% 0.2', '120% 0.1 30', '0.5 0.9 30']) assert.equal(core.parseOklch(text), null, `oklch ${JSON.stringify(text)}`);
    assert.deepEqual(core.parseHex('#abc'), { r: 170, g: 187, b: 204 });
    assert.deepEqual(core.parseRgb('rgb(12 34 56)'), { r: 12, g: 34, b: 56 });
    assert.deepEqual(core.parseOklch('oklch(0.5 0.1 30)'), { L: 0.5, C: 0.1, h: 30 });
});

test('out-of-gamut OKLCH lands in sRGB within one JND of its lightness and hue, with less chroma', () => {
    const rgb = core.oklchToRgb({ L: 0.7, C: 0.4, h: 150 });
    for (const channel of [rgb.r, rgb.g, rgb.b]) assert.ok(channel >= 0 && channel <= 255);
    const back = core.rgbToOklch(rgb);
    close(back.L, 0.7, 0.02, 'L'); close(back.h, 150, 3, 'h'); assert.ok(back.C < 0.4);
});

test('scales have ten stops at perceptually even OKLCH lightness', () => {
    for (const hex of sample) {
        const stops = core.scale(hex);
        assert.deepEqual(stops.map(stop => stop.stop), [50, 100, 200, 300, 400, 500, 600, 700, 800, 900]);
        const lightness = stops.map(stop => core.rgbToOklch(core.parseHex(stop.hex)).L);
        const steps = lightness.slice(1).map((value, index) => lightness[index] - value);
        // 8-bit rounding moves lightness by a few thousandths at most.
        for (const step of steps) close(step, 0.08, 0.006, `${hex} step`);
    }
    const hue = core.rgbToOklch(core.parseHex('#2D5BE3')).h;
    for (const stop of core.scale('#2D5BE3').slice(2, 8)) close(core.rgbToOklch(core.parseHex(stop.hex)).h, hue, 2, `hue at ${stop.stop}`);
});

test('simulations keep greys, merge red and green for deuteranopia and remove hue for achromatopsia', () => {
    for (const type of core.VISION_TYPES) for (const grey of ['#000000', '#808080', '#FFFFFF']) close(core.parseHex(core.simulate(grey, type)).r, core.parseHex(grey).r, 1, `${type} ${grey}`);
    const distance = (a, b) => { const x = core.parseHex(a), y = core.parseHex(b); return Math.hypot(x.r - y.r, x.g - y.g, x.b - y.b); };
    assert.ok(distance(core.simulate('#D62728', 'deuteranopia'), core.simulate('#2CA02C', 'deuteranopia')) < distance('#D62728', '#2CA02C') / 2);
    assert.equal(core.simulate('#FF0000', 'deuteranopia'), '#A39000');
    const grey = core.parseHex(core.simulate('#FF0000', 'achromatopsia'));
    assert.ok(grey.r === grey.g && grey.g === grey.b);
    close(core.relativeLuminance(core.toHex(grey)), core.relativeLuminance('#FF0000'), 0.005, 'luminance kept');
    assert.equal(core.simulate('#123456', 'none'), '#123456');
});

test('every generator keeps locked swatches and the selected base', () => {
    const swatches = ['#BC232C', '#2D5BE3', '#F2B705', '#12AB9C', '#5C5C5C', '#7A5A00', '#FFFFFF', '#000000'].map((hex, index) => ({ hex, locked: index === 2 || index === 6 }));
    const random = seeded(7);
    for (const kind of Object.keys(core.HARMONIES)) {
        for (const selected of [0, 3, 7]) {
            const out = core.generate({ swatches, selected, kind });
            assert.equal(out.length, 8);
            assert.equal(out[2], '#F2B705', `${kind} lock 2`); assert.equal(out[6], '#FFFFFF', `${kind} lock 6`);
            assert.equal(out[selected], swatches[selected].hex, `${kind} base`);
            out.forEach(hex => assert.match(hex, /^#[0-9A-F]{6}$/));
        }
    }
    for (let run = 0; run < 20; run++) {
        const surprise = core.surprise({ swatches, selected: 2, random });
        assert.equal(surprise.hexes[2], '#F2B705'); assert.equal(surprise.hexes[6], '#FFFFFF');
        const nudged = core.nudge({ swatches, random });
        assert.equal(nudged[2], '#F2B705'); assert.equal(nudged[6], '#FFFFFF');
    }
});

test('complementary sits opposite the base hue and nudge stays small', () => {
    const swatches = [{ hex: '#2D5BE3', locked: false }, { hex: '#000000', locked: false }];
    const [base, opposite] = core.generate({ swatches, selected: 0, kind: 'complementary' }).map(hex => core.rgbToOklch(core.parseHex(hex)));
    const gap = Math.abs(((opposite.h - base.h + 360) % 360) - 180);
    assert.ok(gap < 3, `complement is ${gap}° off`);
    const random = seeded(3);
    const before = core.rgbToOklch(core.parseHex('#2D5BE3'));
    const after = core.rgbToOklch(core.parseHex(core.nudge({ swatches: [{ hex: '#2D5BE3', locked: false }], random })[0]));
    assert.ok(Math.abs(after.L - before.L) <= 0.035 && Math.abs(after.h - before.h) <= 7);
    // Black and near-black move by the same small step, not up to the generators' lightness floor.
    for (const hex of ['#000000', '#050505', '#0A0F14']) {
        const start = core.rgbToOklch(core.parseHex(hex));
        for (const value of [0, 0.5, 1]) {
            const moved = core.rgbToOklch(core.parseHex(core.nudge({ swatches: [{ hex, locked: false }], random: () => value })[0]));
            assert.ok(Math.abs(moved.L - start.L) <= 0.035, `${hex} moved ${moved.L - start.L} at ${value}`);
        }
    }
    assert.equal(core.nudge({ swatches: [{ hex: '#000000', locked: false }], random: () => 0.5 })[0], '#000000');
});

test('export formats name every swatch from the palette name', () => {
    const hexes = ['#bc232c', '#2D5BE3'];
    assert.equal(core.exportAs('css', 'Late Night', hexes), ':root {\n  --late-night-1: #BC232C;\n  --late-night-2: #2D5BE3;\n}\n');
    assert.equal(core.exportAs('scss', 'Late Night', hexes), '$late-night-1: #BC232C;\n$late-night-2: #2D5BE3;\n');
    assert.equal(core.exportAs('tailwind', 'Late Night', hexes), '@theme {\n  --color-late-night-1: #BC232C;\n  --color-late-night-2: #2D5BE3;\n}\n');
    assert.deepEqual(JSON.parse(core.exportAs('json', 'Late Night', hexes)), { name: 'Late Night', colors: ['#BC232C', '#2D5BE3'] });
    assert.equal(core.exportAs('hex', 'x', hexes), '#BC232C\n#2D5BE3\n');
    assert.equal(core.slug('  Café / Ünïcode!! '), 'cafe-unicode');
    assert.equal(core.fileName('Sun: rise / set', 'png'), 'Sun rise set.png');
    assert.equal(core.fileName('   ', 'png'), 'Palette.png');
});

test('the library round-trips through its JSON export', () => {
    const library = [
        { id: 'a', name: 'Harbour', colors: ['#0B3954', '#BFD7EA'], favourite: true },
        { id: 'b', name: 'Ember', colors: ['#FF5A5F', '#C81D25', '#2D2A32'], favourite: false },
    ];
    const { palettes, skipped } = core.importLibrary(core.exportLibrary(library));
    assert.deepEqual(skipped, []);
    assert.deepEqual(palettes, library.map(({ name, colors, favourite }) => ({ name, colors, favourite })));
});

test('the importer accepts a bare list or one palette and skips bad palettes with a reason', () => {
    assert.equal(core.importLibrary(JSON.stringify([{ name: 'One', colors: ['#abc'] }])).palettes[0].colors[0], '#AABBCC');
    assert.equal(core.importLibrary(JSON.stringify({ name: 'Solo', colors: ['#123456'], favorite: true })).palettes[0].favourite, true);
    const result = core.importLibrary(JSON.stringify({ palettes: [
        { name: 'Good', colors: ['#111111'] },
        { name: 'No colours' },
        { name: 'Too many', colors: Array(9).fill('#000000') },
        { name: 'Bad hex', colors: ['#12345'] },
        'not an object',
        { name: '  ' + 'x'.repeat(80), colors: ['#222222'] },
        { colors: ['#333333'] },
    ] }));
    assert.deepEqual(result.palettes.map(palette => palette.name), ['Good', 'x'.repeat(60), 'Imported palette']);
    assert.equal(result.skipped.length, 4);
    assert.deepEqual(result.skipped.map(item => item.index), [1, 2, 3, 4]);
});

test('the importer refuses non-JSON and files without palettes, and respects the room left', () => {
    assert.throws(() => core.importLibrary('{not json'), /not valid JSON/);
    assert.throws(() => core.importLibrary('{"hello":1}'), /No palettes/);
    assert.throws(() => core.importLibrary('[]'), /No palettes/);
    const many = JSON.stringify(Array.from({ length: 5 }, (_, index) => ({ name: `P${index}`, colors: ['#000000'] })));
    const limited = core.importLibrary(many, { room: 2 });
    assert.equal(limited.palettes.length, 2);
    assert.equal(limited.skipped.length, 3);
});

test('favourites come first and each group keeps its order', () => {
    const order = core.orderLibrary([{ name: 'a' }, { name: 'b', favourite: true }, { name: 'c' }, { name: 'd', favourite: true }]);
    assert.deepEqual(order.map(item => item.name), ['b', 'd', 'a', 'c']);
});
