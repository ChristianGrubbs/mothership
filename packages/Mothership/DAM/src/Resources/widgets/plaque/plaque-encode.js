// Plaque Maker encoders. Each takes frames that PlaqueCore.drawFrame drew and returns a Blob. Byte-level formats
// (APNG, GIF, zip, WAV) are written here so the tool works offline with no dependency; WebM uses the browser's
// MediaRecorder. UMD like plaque-core.js so the pure parts (CRC, zip, WAV, GIF, APNG assembly) run under Node.
(function (root, factory) {
    const api = factory();
    if (typeof module === 'object' && module.exports) module.exports = api;
    else root.PlaqueEncode = api;
}(typeof self !== 'undefined' ? self : this, () => {
    'use strict';

    const CRC_TABLE = (() => {
        const table = new Uint32Array(256);
        for (let n = 0; n < 256; n++) { let c = n; for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1; table[n] = c >>> 0; }
        return table;
    })();
    function crc32(bytes, start = 0, end = bytes.length, seed = 0) {
        let c = (seed ^ 0xffffffff) >>> 0;
        for (let i = start; i < end; i++) c = CRC_TABLE[(c ^ bytes[i]) & 0xff] ^ (c >>> 8);
        return (c ^ 0xffffffff) >>> 0;
    }
    const concat = parts => {
        const out = new Uint8Array(parts.reduce((sum, part) => sum + part.length, 0));
        let offset = 0;
        for (const part of parts) { out.set(part, offset); offset += part.length; }
        return out;
    };
    const u32 = value => new Uint8Array([(value >>> 24) & 255, (value >>> 16) & 255, (value >>> 8) & 255, value & 255]);
    const u16 = value => new Uint8Array([(value >>> 8) & 255, value & 255]);
    const ascii = value => Uint8Array.from(value, ch => ch.charCodeAt(0));

    // ---- PNG chunks and APNG ---------------------------------------------------------------------------------------
    function pngChunks(bytes) {
        const sig = [137, 80, 78, 71, 13, 10, 26, 10];
        if (!sig.every((b, i) => bytes[i] === b)) throw new Error('not a PNG');
        const chunks = [];
        for (let pos = 8; pos < bytes.length;) {
            const length = ((bytes[pos] << 24) | (bytes[pos + 1] << 16) | (bytes[pos + 2] << 8) | bytes[pos + 3]) >>> 0;
            const type = String.fromCharCode(...bytes.subarray(pos + 4, pos + 8));
            chunks.push({ type, data: bytes.subarray(pos + 8, pos + 8 + length) });
            pos += 12 + length;
        }
        return chunks;
    }
    function chunk(type, data) {
        const head = ascii(type), body = concat([head, data]);
        return concat([u32(data.length), body, u32(crc32(body))]);
    }
    // frames: Uint8Array PNG files of identical size. delays: per frame [numerator, denominator] seconds.
    function apng(frames, delays, loops = 0) {
        const first = pngChunks(frames[0]);
        const ihdr = first.find(c => c.type === 'IHDR').data;
        const width = (ihdr[0] << 24 | ihdr[1] << 16 | ihdr[2] << 8 | ihdr[3]) >>> 0, height = (ihdr[4] << 24 | ihdr[5] << 16 | ihdr[6] << 8 | ihdr[7]) >>> 0;
        const parts = [new Uint8Array([137, 80, 78, 71, 13, 10, 26, 10]), chunk('IHDR', ihdr), chunk('acTL', concat([u32(frames.length), u32(loops)]))];
        let seq = 0;
        frames.forEach((file, index) => {
            const [num, den] = delays[index];
            // Full-frame regions, blend SOURCE: each frame replaces the canvas, so alpha never accumulates.
            parts.push(chunk('fcTL', concat([u32(seq++), u32(width), u32(height), u32(0), u32(0), u16(num), u16(den), new Uint8Array([0, 0])])));
            for (const c of pngChunks(file)) {
                if (c.type !== 'IDAT') continue;
                parts.push(index === 0 ? chunk('IDAT', c.data) : chunk('fdAT', concat([u32(seq++), c.data])));
            }
        });
        parts.push(chunk('IEND', new Uint8Array(0)));
        return concat(parts);
    }

    // ---- zip (STORE) ------------------------------------------------------------------------------------------------
    // files: [{ name, data: Uint8Array }]. A fixed timestamp keeps the archive identical for identical input.
    function zip(files) {
        const local = [], central = [];
        let offset = 0;
        const le16 = v => new Uint8Array([v & 255, (v >>> 8) & 255]);
        const le32 = v => new Uint8Array([v & 255, (v >>> 8) & 255, (v >>> 16) & 255, (v >>> 24) & 255]);
        const time = le16(0), date = le16(((2026 - 1980) << 9) | (1 << 5) | 1);
        for (const file of files) {
            const name = new TextEncoder().encode(file.name), crc = crc32(file.data), size = file.data.length;
            const header = concat([le32(0x04034b50), le16(20), le16(0x0800), le16(0), time, date, le32(crc), le32(size), le32(size), le16(name.length), le16(0), name]);
            local.push(header, file.data);
            central.push(concat([le32(0x02014b50), le16(20), le16(20), le16(0x0800), le16(0), time, date, le32(crc), le32(size), le32(size), le16(name.length), le16(0), le16(0), le16(0), le16(0), le32(0), le32(offset), name]));
            offset += header.length + size;
        }
        const dir = concat(central);
        return concat([...local, dir, concat([le32(0x06054b50), le16(0), le16(0), le16(files.length), le16(files.length), le32(dir.length), le32(offset), le16(0)])]);
    }

    // ---- WAV ---------------------------------------------------------------------------------------------------------
    // channels: Float32Array per channel at `rate`. 16-bit PCM, little endian.
    function wav(channels, rate) {
        const frames = channels[0]?.length || 0, count = channels.length, block = count * 2;
        const out = new DataView(new ArrayBuffer(44 + frames * block));
        const str = (offset, value) => { for (let i = 0; i < value.length; i++) out.setUint8(offset + i, value.charCodeAt(i)); };
        str(0, 'RIFF'); out.setUint32(4, 36 + frames * block, true); str(8, 'WAVE');
        str(12, 'fmt '); out.setUint32(16, 16, true); out.setUint16(20, 1, true); out.setUint16(22, count, true);
        out.setUint32(24, rate, true); out.setUint32(28, rate * block, true); out.setUint16(32, block, true); out.setUint16(34, 16, true);
        str(36, 'data'); out.setUint32(40, frames * block, true);
        let pos = 44;
        for (let i = 0; i < frames; i++) for (let c = 0; c < count; c++) {
            const s = Math.max(-1, Math.min(1, channels[c][i]));
            out.setInt16(pos, s < 0 ? Math.round(s * 0x8000) : Math.round(s * 0x7fff), true);
            pos += 2;
        }
        return new Uint8Array(out.buffer);
    }

    // ---- GIF ---------------------------------------------------------------------------------------------------------
    // Binary transparency: a pixel with alpha < 128 is the transparent index, anything else is opaque in its colour.
    // Each frame gets its own palette from a median cut over 15-bit colour, and disposal 2 clears it before the next.
    function medianCut(hist, maxColors) {
        const colors = [];
        for (let key = 0; key < 32768; key++) if (hist[key]) colors.push(key);
        if (!colors.length) return [[0, 0, 0]];
        const boxes = [colors];
        const channel = (key, c) => (key >> (10 - c * 5)) & 31;
        while (boxes.length < maxColors) {
            let best = -1, bestRange = 0, bestChannel = 0;
            boxes.forEach((box, index) => {
                if (box.length < 2) return;
                for (let c = 0; c < 3; c++) {
                    let lo = 31, hi = 0;
                    for (const key of box) { const v = channel(key, c); if (v < lo) lo = v; if (v > hi) hi = v; }
                    if (hi - lo > bestRange) { bestRange = hi - lo; best = index; bestChannel = c; }
                }
            });
            if (best < 0) break;
            const box = boxes[best].sort((a, b) => channel(a, bestChannel) - channel(b, bestChannel));
            let total = 0; for (const key of box) total += hist[key];
            let acc = 0, cut = 1;
            for (let i = 0; i < box.length - 1; i++) { acc += hist[box[i]]; if (acc >= total / 2) { cut = i + 1; break; } }
            boxes.splice(best, 1, box.slice(0, cut), box.slice(cut));
        }
        return boxes.map(box => {
            let r = 0, g = 0, b = 0, n = 0;
            for (const key of box) { const w = hist[key]; r += ((key >> 10) & 31) * w; g += ((key >> 5) & 31) * w; b += (key & 31) * w; n += w; }
            return [Math.round(r / n * 255 / 31), Math.round(g / n * 255 / 31), Math.round(b / n * 255 / 31)];
        });
    }
    function lzw(indices, minCode) {
        const out = [];
        let bitBuf = 0, bitCount = 0;
        const clear = 1 << minCode, end = clear + 1;
        let codeSize = minCode + 1, next = end + 1;
        let dict = new Map();
        const emit = code => { bitBuf |= code << bitCount; bitCount += codeSize; while (bitCount >= 8) { out.push(bitBuf & 255); bitBuf >>>= 8; bitCount -= 8; } };
        emit(clear);
        let prefix = indices[0];
        for (let i = 1; i < indices.length; i++) {
            const k = indices[i], key = prefix * 4096 + k;
            if (dict.has(key)) { prefix = dict.get(key); continue; }
            emit(prefix);
            if (next < 4096) { dict.set(key, next++); if (next > (1 << codeSize) && codeSize < 12) codeSize++; }
            else { emit(clear); dict = new Map(); codeSize = minCode + 1; next = end + 1; }
            prefix = k;
        }
        emit(prefix);
        emit(end);
        if (bitCount > 0) out.push(bitBuf & 255);
        const blocks = [minCode];
        for (let i = 0; i < out.length; i += 255) { const part = out.slice(i, i + 255); blocks.push(part.length, ...part); }
        blocks.push(0);
        return Uint8Array.from(blocks);
    }
    function gifFrame(rgba, width, height) {
        const hist = new Uint32Array(32768);
        for (let i = 0; i < rgba.length; i += 4) if (rgba[i + 3] >= 128) hist[((rgba[i] >> 3) << 10) | ((rgba[i + 1] >> 3) << 5) | (rgba[i + 2] >> 3)]++;
        const palette = medianCut(hist, 255);
        const lookup = new Int16Array(32768).fill(-1);
        const nearest = key => {
            if (lookup[key] >= 0) return lookup[key];
            const r = ((key >> 10) & 31) * 255 / 31, g = ((key >> 5) & 31) * 255 / 31, b = (key & 31) * 255 / 31;
            let best = 0, dist = Infinity;
            palette.forEach(([pr, pg, pb], index) => { const d = (pr - r) ** 2 + (pg - g) ** 2 + (pb - b) ** 2; if (d < dist) { dist = d; best = index; } });
            return (lookup[key] = best);
        };
        const transparent = palette.length;
        const indices = new Uint8Array(width * height);
        for (let p = 0, i = 0; p < indices.length; p++, i += 4) indices[p] = rgba[i + 3] < 128 ? transparent : nearest(((rgba[i] >> 3) << 10) | ((rgba[i + 1] >> 3) << 5) | (rgba[i + 2] >> 3));
        let bits = 1; while ((1 << bits) < palette.length + 1) bits++;
        const table = new Uint8Array((1 << bits) * 3);
        palette.forEach(([r, g, b], index) => table.set([r, g, b], index * 3));
        return { indices, table, bits, transparent };
    }
    // frames: [{ rgba: Uint8ClampedArray, delay: centiseconds }]
    function gif(frames, width, height) {
        const parts = [ascii('GIF89a'), concat([new Uint8Array([width & 255, width >> 8, height & 255, height >> 8]), new Uint8Array([0x00, 0, 0])]),
            new Uint8Array([0x21, 0xff, 0x0b, ...ascii('NETSCAPE2.0'), 0x03, 0x01, 0, 0, 0])];
        for (const frame of frames) {
            const { indices, table, bits, transparent } = gifFrame(frame.rgba, width, height);
            const delay = Math.max(2, Math.round(frame.delay));
            parts.push(new Uint8Array([0x21, 0xf9, 0x04, (2 << 2) | 1, delay & 255, delay >> 8, transparent, 0]));
            parts.push(new Uint8Array([0x2c, 0, 0, 0, 0, width & 255, width >> 8, height & 255, height >> 8, 0x80 | (bits - 1)]), table);
            parts.push(lzw(indices, Math.max(2, bits)));
        }
        parts.push(new Uint8Array([0x3b]));
        return concat(parts);
    }
    // GIF delays are whole centiseconds and browsers treat anything under 2 as slow, so a GIF holds at most 50 fps: the
    // exporter renders GIF frames at gifFps(fps) and the animation keeps its length.
    const gifFps = fps => Math.min(Number(fps) || 30, 50);
    // Carry the remainder so a 30 fps animation lasts exactly as long as it should.
    function gifDelays(count, fps) {
        const delays = [];
        let carried = 0;
        for (let i = 0; i < count; i++) { const exact = 100 / fps + carried, whole = Math.max(2, Math.round(exact)); carried = exact - whole; delays.push(whole); }
        return delays;
    }

    return { crc32, concat, pngChunks, apng, zip, wav, gif, gifFrame, gifDelays, gifFps, lzw };
}));
