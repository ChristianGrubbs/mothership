/**
 * Palette Studio's colour maths and library importer, kept free of the DOM so the widget and Node run the same
 * arithmetic (packages/Mothership/DAM/tests/js/palette-studio-core.test.mjs).
 *
 * UMD on purpose: index.html loads this as a plain <script> (window.PaletteCore) and the test require()s it. Do not
 * convert it to ESM without adding a build step for the widget.
 *
 * Sources: OKLab and its matrices, Björn Ottosson (https://bottosson.github.io/posts/oklab/); relative luminance and
 * contrast ratio, WCAG 2.2 (1.4.3, 1.4.6); colour-vision simulation, Machado, Oliveira and Fernandes 2009 at severity
 * 1.0, applied in linear sRGB; achromatopsia, the colour's own relative luminance as a grey.
 */
(function (root, factory) {
    const api = factory();
    if (typeof module === 'object' && module && module.exports) module.exports = api;
    else root.PaletteCore = api;
})(typeof self !== 'undefined' ? self : this, function () {
    'use strict';

    const MAX_SWATCHES = 8;
    const MAX_LIBRARY = 150;
    const MAX_NAME = 60;
    const STOPS = [50, 100, 200, 300, 400, 500, 600, 700, 800, 900];
    // OKLCH lightness of the lightest and darkest scale stops; the eight stops between are evenly spaced.
    const SCALE_LIGHTEST = 0.97;
    const SCALE_DARKEST = 0.25;
    const HARMONIES = {
        complementary: [0, 180],
        analogous: [0, -30, 30, -60, 60],
        triadic: [0, 120, 240],
        'split-complementary': [0, 150, 210],
        tetradic: [0, 90, 180, 270],
        monochromatic: [0],
    };

    const clamp = (value, min, max) => Math.min(max, Math.max(min, value));
    const wrapHue = hue => ((hue % 360) + 360) % 360;
    const round = (value, places) => { const factor = 10 ** places; return Math.round(value * factor) / factor; };

    // ---- sRGB and hex --------------------------------------------------------------------------------------------

    function parseHex(input) {
        const match = String(input ?? '').trim().match(/^#?([0-9a-f]{3}|[0-9a-f]{6})$/i);
        if (!match) return null;
        let hex = match[1];
        if (hex.length === 3) hex = hex.split('').map(char => char + char).join('');
        return { r: parseInt(hex.slice(0, 2), 16), g: parseInt(hex.slice(2, 4), 16), b: parseInt(hex.slice(4, 6), 16) };
    }
    function toHex({ r, g, b }) {
        return '#' + [r, g, b].map(value => clamp(Math.round(value), 0, 255).toString(16).padStart(2, '0')).join('').toUpperCase();
    }
    function normalizeHex(input) { const rgb = parseHex(input); return rgb ? toHex(rgb) : null; }
    const toLinear = channel => { const c = channel / 255; return c <= 0.04045 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4; };
    const fromLinear = value => 255 * (value <= 0.0031308 ? 12.92 * value : 1.055 * value ** (1 / 2.4) - 0.055);

    // ---- HSL ----------------------------------------------------------------------------------------------------

    function rgbToHsl({ r, g, b }) {
        const rn = r / 255, gn = g / 255, bn = b / 255;
        const max = Math.max(rn, gn, bn), min = Math.min(rn, gn, bn), l = (max + min) / 2, d = max - min;
        if (d === 0) return { h: 0, s: 0, l: l * 100 };
        const s = d / (1 - Math.abs(2 * l - 1));
        let h;
        if (max === rn) h = ((gn - bn) / d) % 6;
        else if (max === gn) h = (bn - rn) / d + 2;
        else h = (rn - gn) / d + 4;
        return { h: wrapHue(h * 60), s: s * 100, l: l * 100 };
    }
    function hslToRgb({ h, s, l }) {
        const sn = clamp(s, 0, 100) / 100, ln = clamp(l, 0, 100) / 100, hue = wrapHue(h);
        const c = (1 - Math.abs(2 * ln - 1)) * sn, x = c * (1 - Math.abs(((hue / 60) % 2) - 1)), m = ln - c / 2;
        const [r, g, b] = hue < 60 ? [c, x, 0] : hue < 120 ? [x, c, 0] : hue < 180 ? [0, c, x] : hue < 240 ? [0, x, c] : hue < 300 ? [x, 0, c] : [c, 0, x];
        return { r: (r + m) * 255, g: (g + m) * 255, b: (b + m) * 255 };
    }

    // ---- OKLab / OKLCH ------------------------------------------------------------------------------------------

    function linearToOklab([r, g, b]) {
        const l = Math.cbrt(0.4122214708 * r + 0.5363325363 * g + 0.0514459929 * b);
        const m = Math.cbrt(0.2119034982 * r + 0.6806995451 * g + 0.1073969566 * b);
        const s = Math.cbrt(0.0883024619 * r + 0.2817188376 * g + 0.6299787005 * b);
        return {
            L: 0.2104542553 * l + 0.7936177850 * m - 0.0040720468 * s,
            a: 1.9779984951 * l - 2.4285922050 * m + 0.4505937099 * s,
            b: 0.0259040371 * l + 0.7827717662 * m - 0.8086757660 * s,
        };
    }
    function oklabToLinear({ L, a, b }) {
        const l = (L + 0.3963377774 * a + 0.2158037573 * b) ** 3;
        const m = (L - 0.1055613458 * a - 0.0638541728 * b) ** 3;
        const s = (L - 0.0894841775 * a - 1.2914855480 * b) ** 3;
        return [
            4.0767416621 * l - 3.3077115913 * m + 0.2309699292 * s,
            -1.2684380046 * l + 2.6097574011 * m - 0.3413193965 * s,
            -0.0041960863 * l - 0.7034186147 * m + 1.7076147010 * s,
        ];
    }
    function rgbToOklch(rgb) {
        const { L, a, b } = linearToOklab([toLinear(rgb.r), toLinear(rgb.g), toLinear(rgb.b)]);
        const C = Math.hypot(a, b);
        // An achromatic colour has no hue; 0 keeps the number stable instead of noise from rounding.
        return { L, C, h: C < 1e-4 ? 0 : wrapHue(Math.atan2(b, a) * 180 / Math.PI) };
    }
    const inGamut = linear => linear.every(channel => channel >= -1e-6 && channel <= 1 + 1e-6);
    function oklchToLinear({ L, C, h }) {
        const radians = h * Math.PI / 180;
        return oklabToLinear({ L, a: C * Math.cos(radians), b: C * Math.sin(radians) });
    }
    const linearToRgb = linear => { const [r, g, b] = linear.map(channel => fromLinear(clamp(channel, 0, 1))); return { r, g, b }; };
    const deltaOk = (first, second) => { const a = linearToOklab(first), b = linearToOklab(second); return Math.hypot(a.L - b.L, a.a - b.a, a.b - b.b); };
    /**
     * OKLCH to sRGB with the CSS Color 4 gamut-mapping algorithm: a colour within one just-noticeable difference
     * (ΔEOK 0.02) of sRGB is clipped, so a rounded value typed back in lands on the same colour; one further out
     * loses chroma until its clip is within that difference.
     */
    function oklchToRgb({ L, C, h }) {
        const lightness = clamp(L, 0, 1), JND = 0.02, EPSILON = 0.0001;
        if (lightness >= 1) return { r: 255, g: 255, b: 255 };
        if (lightness <= 0) return { r: 0, g: 0, b: 0 };
        const origin = oklchToLinear({ L: lightness, C: Math.max(0, C), h });
        if (inGamut(origin)) return linearToRgb(origin);
        const clipOf = linear => linear.map(channel => clamp(channel, 0, 1));
        let clipped = clipOf(origin);
        if (deltaOk(clipped, origin) < JND) return linearToRgb(clipped);
        let min = 0, max = Math.max(0, C), minInGamut = true;
        while (max - min > EPSILON) {
            const chroma = (min + max) / 2, current = oklchToLinear({ L: lightness, C: chroma, h });
            if (minInGamut && inGamut(current)) { min = chroma; continue; }
            clipped = clipOf(current);
            const error = deltaOk(clipped, current);
            if (error < JND) {
                if (JND - error < EPSILON) break;
                minInGamut = false;
                min = chroma;
            } else max = chroma;
        }
        return linearToRgb(clipped);
    }
    /** OKLCH to sRGB lowering chroma only, so lightness is exact: the scales need even steps. */
    function oklchToRgbKeepLightness({ L, C, h }) {
        const lightness = clamp(L, 0, 1);
        let chroma = Math.max(0, C), linear = oklchToLinear({ L: lightness, C: chroma, h });
        if (!inGamut(linear)) {
            let low = 0, high = chroma;
            for (let step = 0; step < 24; step++) {
                const mid = (low + high) / 2;
                if (inGamut(oklchToLinear({ L: lightness, C: mid, h }))) low = mid; else high = mid;
            }
            chroma = low;
            linear = oklchToLinear({ L: lightness, C: chroma, h });
        }
        const [r, g, b] = linear.map(channel => fromLinear(clamp(channel, 0, 1)));
        return { r, g, b };
    }

    // ---- Parsing what a person types ----------------------------------------------------------------------------

    const numbers = input => {
        const text = String(input ?? '').trim().replace(/^[a-z]+\(/i, '').replace(/\)$/, '');
        const parts = text.split(/[\s,/]+/).filter(Boolean);
        return parts.length === 3 ? parts : null;
    };
    const plainNumber = text => /^-?(\d+\.?\d*|\.\d+)$/.test(text) ? Number(text) : NaN;
    function parseRgb(input) {
        const parts = numbers(input);
        if (!parts) return null;
        const values = parts.map(plainNumber);
        if (values.some(value => !Number.isFinite(value) || value < 0 || value > 255)) return null;
        return { r: values[0], g: values[1], b: values[2] };
    }
    function parseHsl(input) {
        const parts = numbers(input);
        if (!parts) return null;
        const [h, s, l] = parts.map((part, index) => plainNumber(index === 0 ? part.replace(/deg$/i, '') : part.replace(/%$/, '')));
        if (![h, s, l].every(Number.isFinite) || s < 0 || s > 100 || l < 0 || l > 100) return null;
        return { h: wrapHue(h), s, l };
    }
    function parseOklch(input) {
        const parts = numbers(input);
        if (!parts) return null;
        const percent = parts[0].endsWith('%');
        let L = plainNumber(parts[0].replace(/%$/, ''));
        const C = plainNumber(parts[1]), h = plainNumber(parts[2].replace(/deg$/i, ''));
        if (percent) L /= 100;
        if (![L, C, h].every(Number.isFinite) || L < 0 || L > 1 || C < 0 || C > 0.5) return null;
        return { L, C, h: wrapHue(h) };
    }

    /** Every representation of one colour, rounded the way the editor shows them. */
    function describe(hex) {
        const rgb = parseHex(hex);
        if (!rgb) return null;
        const hsl = rgbToHsl(rgb), oklch = rgbToOklch(rgb);
        return {
            hex: toHex(rgb), rgb,
            hsl: { h: round(hsl.h, 1), s: round(hsl.s, 1), l: round(hsl.l, 1) },
            oklch: { L: round(oklch.L, 4), C: round(oklch.C, 4), h: round(oklch.h, 1) },
            rgbText: `${rgb.r}, ${rgb.g}, ${rgb.b}`,
            hslText: `${round(hsl.h, 1)}, ${round(hsl.s, 1)}%, ${round(hsl.l, 1)}%`,
            oklchText: `${round(oklch.L * 100, 3)}% ${round(oklch.C, 5)} ${round(oklch.h, 2)}`,
        };
    }

    // ---- WCAG contrast ------------------------------------------------------------------------------------------

    function relativeLuminance(hex) {
        const rgb = typeof hex === 'string' ? parseHex(hex) : hex;
        return 0.2126 * toLinear(rgb.r) + 0.7152 * toLinear(rgb.g) + 0.0722 * toLinear(rgb.b);
    }
    function contrastRatio(first, second) {
        const a = relativeLuminance(first), b = relativeLuminance(second);
        return (Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05);
    }
    /** WCAG 2.2 thresholds. Compared unrounded: 4.499 is a fail even though it would print as 4.50. */
    function wcag(ratio) {
        return { aaNormal: ratio >= 4.5, aaLarge: ratio >= 3, aaaNormal: ratio >= 7, aaaLarge: ratio >= 4.5 };
    }
    /** Black or white, whichever has the higher contrast against the colour. Ties go to black. */
    function textOn(hex) {
        return contrastRatio(hex, '#000000') >= contrastRatio(hex, '#FFFFFF') ? '#000000' : '#FFFFFF';
    }
    /** Every unordered pair of swatches with its ratio and four results. */
    function contrastPairs(hexes) {
        const pairs = [];
        for (let first = 0; first < hexes.length; first++) {
            for (let second = first + 1; second < hexes.length; second++) {
                const ratio = contrastRatio(hexes[first], hexes[second]);
                pairs.push({ first, second, ratio, ...wcag(ratio) });
            }
        }
        return pairs;
    }

    // ---- Colour-vision simulation -------------------------------------------------------------------------------

    const VISION = {
        protanopia: [[0.152286, 1.052583, -0.204868], [0.114503, 0.786281, 0.099216], [-0.003882, -0.048116, 1.051998]],
        deuteranopia: [[0.367322, 0.860646, -0.227968], [0.280085, 0.672501, 0.047413], [-0.011820, 0.042940, 0.968881]],
        tritanopia: [[1.255528, -0.076749, -0.178779], [-0.078411, 0.930809, 0.147602], [0.004733, 0.691367, 0.303900]],
    };
    const VISION_TYPES = ['deuteranopia', 'protanopia', 'tritanopia', 'achromatopsia'];
    /** The colour as the given vision type would see it. Unknown or 'none' returns the colour unchanged. */
    function simulate(hex, type) {
        const rgb = parseHex(hex);
        if (!rgb) return null;
        const linear = [toLinear(rgb.r), toLinear(rgb.g), toLinear(rgb.b)];
        if (type === 'achromatopsia') {
            const grey = fromLinear(0.2126 * linear[0] + 0.7152 * linear[1] + 0.0722 * linear[2]);
            return toHex({ r: grey, g: grey, b: grey });
        }
        const matrix = VISION[type];
        if (!matrix) return toHex(rgb);
        const [r, g, b] = matrix.map(row => fromLinear(clamp(row[0] * linear[0] + row[1] * linear[1] + row[2] * linear[2], 0, 1)));
        return toHex({ r, g, b });
    }

    // ---- Scales -------------------------------------------------------------------------------------------------

    /** Ten stops, 50 to 900, at evenly spaced OKLCH lightness; hue and chroma follow the swatch, inside sRGB. */
    function scale(hex) {
        const base = rgbToOklch(parseHex(hex));
        const step = (SCALE_LIGHTEST - SCALE_DARKEST) / (STOPS.length - 1);
        return STOPS.map((stop, index) => ({ stop, hex: toHex(oklchToRgbKeepLightness({ L: SCALE_LIGHTEST - index * step, C: base.C, h: base.h })) }));
    }

    // ---- Generators ---------------------------------------------------------------------------------------------

    const fromOklch = colour => toHex(oklchToRgbKeepLightness({ L: clamp(colour.L, 0.15, 0.97), C: Math.max(0, colour.C), h: wrapHue(colour.h) }));
    // Extra slots beyond a harmony's hue count step the lightness out from the base: +, -, ++, --, ...
    const lightShift = round => round === 0 ? 0 : (round % 2 ? 1 : -1) * 0.12 * Math.ceil(round / 2);

    /**
     * Fills every unlocked swatch from a harmony of the selected swatch. Locked swatches and the selected swatch keep
     * their colours. Returns the new hex list in swatch order.
     */
    function generate({ swatches, selected = 0, kind }) {
        const offsets = HARMONIES[kind];
        if (!offsets) throw new Error(`Unknown harmony: ${kind}`);
        const index = clamp(selected, 0, swatches.length - 1);
        const base = rgbToOklch(parseHex(swatches[index].hex));
        const out = swatches.map(swatch => normalizeHex(swatch.hex));
        for (let t = 1; t < swatches.length; t++) {
            const slot = (index + t) % swatches.length;
            if (swatches[slot].locked) continue;
            const turn = Math.floor(t / offsets.length);
            const hueOffset = offsets[t % offsets.length];
            const shift = kind === 'monochromatic' ? lightShift(t) * 0.75 : lightShift(turn);
            out[slot] = fromOklch({ L: base.L + shift, C: kind === 'monochromatic' ? base.C * (1 - Math.abs(shift)) : base.C, h: base.h + hueOffset });
        }
        return out;
    }

    const KINDS = Object.keys(HARMONIES);
    /** A new palette from a random base and harmony. A locked selected swatch is kept as the base. */
    function surprise({ swatches, selected = 0, random = Math.random }) {
        const index = clamp(selected, 0, swatches.length - 1);
        const kind = KINDS[Math.floor(random() * (KINDS.length - 1))]; // monochromatic only by choice, never by chance
        const seed = swatches[index].locked ? swatches[index].hex
            : fromOklch({ L: 0.45 + random() * 0.3, C: 0.07 + random() * 0.11, h: random() * 360 });
        const seeded = swatches.map((swatch, slot) => slot === index ? { ...swatch, hex: seed } : swatch);
        return { kind, hexes: generate({ swatches: seeded, selected: index, kind }) };
    }
    /** Small changes to every unlocked swatch: hue ±6°, lightness ±0.03, chroma ±10%. */
    function nudge({ swatches, random = Math.random }) {
        return swatches.map(swatch => {
            if (swatch.locked) return normalizeHex(swatch.hex);
            const colour = rgbToOklch(parseHex(swatch.hex));
            // Full 0–1 lightness: the generators' 0.15 floor would lift a near-black far past ±0.03.
            return toHex(oklchToRgbKeepLightness({ L: clamp(colour.L + (random() - 0.5) * 0.06, 0, 1), C: Math.max(0, colour.C * (1 + (random() - 0.5) * 0.2)), h: wrapHue(colour.h + (random() - 0.5) * 12) }));
        });
    }

    // ---- Export formats -----------------------------------------------------------------------------------------

    function slug(name) {
        return String(name ?? '').toLowerCase().normalize('NFKD').replace(/[̀-ͯ]/g, '').replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '') || 'palette';
    }
    const FORMATS = {
        css: { label: 'CSS custom properties', render: (name, hexes) => `:root {\n${hexes.map((hex, i) => `  --${slug(name)}-${i + 1}: ${hex};`).join('\n')}\n}\n` },
        scss: { label: 'SCSS variables', render: (name, hexes) => hexes.map((hex, i) => `$${slug(name)}-${i + 1}: ${hex};`).join('\n') + '\n' },
        tailwind: { label: 'Tailwind theme colours', render: (name, hexes) => `@theme {\n${hexes.map((hex, i) => `  --color-${slug(name)}-${i + 1}: ${hex};`).join('\n')}\n}\n` },
        json: { label: 'JSON', render: (name, hexes) => JSON.stringify({ name: String(name ?? ''), colors: hexes }, null, 2) + '\n' },
        hex: { label: 'Hex list', render: (name, hexes) => hexes.join('\n') + '\n' },
    };
    function exportAs(format, name, hexes) {
        if (!FORMATS[format]) throw new Error(`Unknown format: ${format}`);
        return FORMATS[format].render(name, hexes.map(normalizeHex));
    }
    /** The PNG's file name: the palette name, made safe for a file system. */
    function fileName(name, extension) {
        const base = String(name ?? '').replace(/[\\/:*?"<>|\u0000-\u001f]+/g, ' ').replace(/\s+/g, ' ').trim().slice(0, MAX_NAME) || 'Palette';
        return `${base}.${extension}`;
    }

    // ---- Library ------------------------------------------------------------------------------------------------

    const LIBRARY_FORMAT = 'mothership-palettes';
    function cleanName(name, fallback) {
        const text = typeof name === 'string' ? name.replace(/\s+/g, ' ').trim().slice(0, MAX_NAME) : '';
        return text || fallback;
    }
    /** The whole library as a JSON document the importer reads back. */
    function exportLibrary(palettes) {
        return JSON.stringify({
            format: LIBRARY_FORMAT, version: 1,
            palettes: palettes.map(palette => ({ name: palette.name, colors: palette.colors.map(normalizeHex), favourite: Boolean(palette.favourite) })),
        }, null, 2) + '\n';
    }
    /**
     * Reads a library file: the exported document, a bare array of palettes, or one palette. Bad palettes are skipped
     * with a reason; the rest are returned. Throws only when the text is not JSON or holds no palette at all.
     */
    function importLibrary(text, { room = MAX_LIBRARY } = {}) {
        let data;
        try { data = JSON.parse(String(text)); } catch { throw new Error('That file is not valid JSON.'); }
        let list;
        if (Array.isArray(data)) list = data;
        else if (data && typeof data === 'object' && Array.isArray(data.palettes)) list = data.palettes;
        else if (data && typeof data === 'object' && Array.isArray(data.colors)) list = [data];
        else throw new Error('No palettes were found in that file.');
        const palettes = [], skipped = [];
        list.forEach((item, index) => {
            const label = `Palette ${index + 1}`;
            if (!item || typeof item !== 'object' || !Array.isArray(item.colors)) { skipped.push({ index, reason: `${label} has no colour list.` }); return; }
            if (item.colors.length < 1 || item.colors.length > MAX_SWATCHES) { skipped.push({ index, reason: `${label} needs 1 to ${MAX_SWATCHES} colours.` }); return; }
            const colors = item.colors.map(normalizeHex);
            if (colors.some(hex => !hex)) { skipped.push({ index, reason: `${label} has a colour that is not a hex value.` }); return; }
            if (palettes.length >= room) { skipped.push({ index, reason: `${label} would pass the ${MAX_LIBRARY}-palette limit.` }); return; }
            palettes.push({ name: cleanName(item.name, 'Imported palette'), colors, favourite: item.favourite === true || item.favorite === true });
        });
        if (!palettes.length && !skipped.length) throw new Error('No palettes were found in that file.');
        return { palettes, skipped };
    }
    /** Favourites first; each group keeps its saved order. */
    function orderLibrary(palettes) {
        return [...palettes.filter(palette => palette.favourite), ...palettes.filter(palette => !palette.favourite)];
    }

    return {
        MAX_SWATCHES, MAX_LIBRARY, MAX_NAME, STOPS, HARMONIES, VISION_TYPES, FORMATS, LIBRARY_FORMAT,
        parseHex, toHex, normalizeHex, rgbToHsl, hslToRgb, rgbToOklch, oklchToRgb, parseRgb, parseHsl, parseOklch, describe,
        relativeLuminance, contrastRatio, wcag, textOn, contrastPairs, simulate, scale, generate, surprise, nudge,
        slug, exportAs, fileName, exportLibrary, importLibrary, orderLibrary, cleanName,
    };
});
