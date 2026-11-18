(() => {
    'use strict';

    const $ = selector => document.querySelector(selector);
    const $$ = selector => [...document.querySelectorAll(selector)];
    const state = { buffer: null, fileName: '', start: 0, length: 1, crossfade: .18, fadeIn: 0, fadeOut: 0, score: 0, source: null, gain: null, loopBuffer: null, startedAt: 0, offset: 0, mode: null, timer: null, rebuild: null, playing: false };
    const { buildLoop, loopPlan } = window.LoopCore;
    const audio = new (window.AudioContext || window.webkitAudioContext)();
    const prefsKey = 'loop-maker:prefs';
    const durationError = 'Enter a duration as seconds or m:ss.';
    const nodes = {
        drop: $('[data-drop-zone]'), editor: $('[data-editor]'), wave: $('[data-wave]'), overlay: $('[data-overlay]'),
        start: $('[data-start]'), length: $('[data-length]'), crossfade: $('[data-crossfade]'), fadeIn: $('[data-fade-in]'), fadeOut: $('[data-fade-out]'),
        smart: $('[aria-label="Smart seam"]'), play: $('[data-play]'), clock: $('[data-clock]'), format: $('[data-format]'), mode: $('[data-export-mode]'), fill: $('[data-fill]'), normalize: $('[data-normalize]'), status: $('[data-status]'), uploadStatus: $('[data-upload-status]'),
    };

    function frameMessage(type, payload = {}) {
        if (window.parent !== window) window.parent.postMessage({ protocol: 'mothership-widget/v1', type, payload }, location.origin);
    }
    function dirty() { frameMessage('dirty'); }
    function fmt(value) {
        const seconds = Math.max(0, Number(value) || 0), minutes = Math.floor(seconds / 60), rest = Math.floor(seconds % 60), centis = Math.floor(seconds * 100) % 100;
        return `${minutes}:${String(rest).padStart(2, '0')}.${String(centis).padStart(2, '0')}`;
    }
    function parseDuration(value) {
        const match = String(value).trim().match(/^(?:(\d+):)?(\d+(?:\.\d+)?)$/);
        return match ? (Number(match[1] || 0) * 60 + Number(match[2])) : NaN;
    }
    function clamp(value, min, max) { const number = Number(value); return Math.min(max, Math.max(min, Number.isFinite(number) ? number : min)); }
    function setStatus(message) { nodes.status.textContent = message; }
    function savedPrefs() { try { return JSON.parse(localStorage.getItem(prefsKey) || '{}'); } catch { return {}; } }
    function savePrefs() {
        localStorage.setItem(prefsKey, JSON.stringify({ format: nodes.format.value, mode: nodes.mode.value, fill: nodes.fill.value, normalize: nodes.normalize.checked }));
    }

    async function loadFile(file) {
        if (!file || file.size > 250 * 1024 * 1024) { nodes.uploadStatus.textContent = 'Choose an audio file smaller than 250 MB.'; return; }
        stop(); // Replacing the track mid-playback must not leave the previous loop running under it.
        $('[data-drop-title]').textContent = 'Reading the track…';
        nodes.drop.setAttribute('aria-busy', 'true');
        nodes.uploadStatus.textContent = '';
        setStatus('');
        try {
            const buffer = await audio.decodeAudioData(await file.arrayBuffer());
            if (buffer.duration < .5) throw new Error('short');
            state.buffer = buffer;
            state.fileName = file.name.replace(/\.[^.]+$/, '') || 'audio';
            state.start = Math.min(.5, buffer.duration / 8);
            state.length = Math.max(.25, Math.min(30, buffer.duration - state.start));
            state.crossfade = Math.min(1, state.length / 5);
            nodes.start.max = Math.max(0, buffer.duration - .25);
            nodes.length.max = buffer.duration;
            // syncControls() below sets the real ceiling from state.length; this is just the range
            // input's initial attribute before that first call.
            nodes.crossfade.max = Math.min(4, state.length / 2);
            nodes.fadeIn.max = nodes.fadeOut.max = Math.min(10, buffer.duration / 2);
            nodes.drop.hidden = true;
            $('[data-demo]').hidden = true;
            nodes.editor.hidden = false;
            $('[data-file-name]').textContent = file.name;
            $('[data-file-meta]').textContent = `${fmt(buffer.duration)} · ${buffer.sampleRate.toLocaleString()} Hz · ${buffer.numberOfChannels === 1 ? 'mono' : 'stereo'}`;
            $('[data-duration]').textContent = fmt(buffer.duration);
            syncControls();
            draw();
            scoreSeam();
            dirty();
        } catch {
            $('[data-drop-title]').textContent = 'Drop an audio file here';
            nodes.uploadStatus.textContent = "Couldn't read that file. Try an MP3, WAV, M4A, or OGG.";
        } finally { nodes.drop.removeAttribute('aria-busy'); }
    }

    function syncControls() {
        state.start = clamp(state.start, 0, Math.max(0, state.buffer.duration - .25));
        nodes.length.max = Math.max(.25, state.buffer.duration - state.start);
        state.length = clamp(state.length, .25, state.buffer.duration - state.start);
        // Only the loop itself caps the crossfade: half its length, and four seconds. It used to be
        // capped by `state.start` as well, a leftover from a design that blended in pre-roll audio
        // — buildLoop never reads before the start frame, so a loop beginning 0.1 s into a file is
        // no longer stuck with a 0.1 s crossfade. The slider's own max is refreshed here too: it
        // used to stay fixed at a quarter of the whole file, so a long loop in a short file (an 8 s
        // file holding a 6 s loop) was stuck at a 2 s ceiling instead of the intended 3 s.
        const crossfadeCeiling = Math.min(4, state.length / 2);
        nodes.crossfade.max = crossfadeCeiling;
        state.crossfade = clamp(state.crossfade, 0, crossfadeCeiling);
        state.fadeIn = clamp(state.fadeIn, 0, state.length / 2);
        state.fadeOut = clamp(state.fadeOut, 0, state.length / 2);
        for (const [key, node] of [['start', nodes.start], ['length', nodes.length], ['crossfade', nodes.crossfade], ['fadeIn', nodes.fadeIn], ['fadeOut', nodes.fadeOut]]) node.value = state[key];
        $('[data-start-output]').textContent = `${state.start.toFixed(2)} s`;
        $('[data-length-output]').textContent = `${state.length.toFixed(2)} s`;
        $('[data-crossfade-output]').textContent = `${state.crossfade.toFixed(2)} s`;
        $('[data-fade-in-output]').textContent = `${state.fadeIn.toFixed(2)} s`;
        $('[data-fade-out-output]').textContent = `${state.fadeOut.toFixed(2)} s`;
        if (!state.playing) nodes.clock.textContent = `0:00.00 / ${fmt(state.length)}`;
        updateExport();
        refreshPlayback();
    }

    function seamCorrelation(start, length) {
        const data = state.buffer.getChannelData(0), rate = state.buffer.sampleRate;
        const edge = Math.max(64, Math.min(Math.floor(rate * .12), Math.floor(length * rate / 3)));
        const a = Math.floor(start * rate), b = Math.min(data.length - edge, Math.floor((start + length) * rate) - edge);
        let dot = 0, aa = 0, bb = 0, energyA = 0, energyB = 0;
        for (let index = 0; index < edge; index++) { const x = data[a + index] || 0, y = data[b + index] || 0; dot += x * y; aa += x * x; bb += y * y; energyA += Math.abs(x); energyB += Math.abs(y); }
        const correlation = aa && bb ? dot / Math.sqrt(aa * bb) : 0;
        const level = Math.max(energyA, energyB) ? Math.min(energyA, energyB) / Math.max(energyA, energyB) : 0;
        return Math.round(clamp((correlation + 1) * 35 + level * 30, 0, 100));
    }
    function scoreSeam() {
        state.score = seamCorrelation(state.start, state.length);
        const word = state.score >= 75 ? 'clean' : state.score >= 45 ? 'fair' : 'rough';
        $('[data-seam-score]').textContent = `${state.score}% · ${word}`;
        $('[data-score-dot]').style.background = state.score >= 75 ? 'var(--success)' : state.score >= 45 ? 'var(--warning)' : 'var(--crimson)';
        $('[data-seam-hint]').textContent = state.score >= 75 ? 'This boundary is closely matched and should repeat cleanly.' : state.score >= 45 ? 'This boundary is usable; increase the crossfade if you hear the join.' : 'The boundary is audible. Try Find best seam or move the region.';
    }
    function findBest() {
        const button = $('[data-find-best]'); button.disabled = true; button.textContent = 'Searching…';
        setTimeout(() => {
            let best = { start: state.start, score: seamCorrelation(state.start, state.length) };
            const radius = Math.min(4, state.buffer.duration / 4), step = Math.max(.02, radius / 100);
            for (let offset = -radius; offset <= radius; offset += step) {
                const start = clamp(state.start + offset, 0, state.buffer.duration - state.length);
                const score = seamCorrelation(start, state.length);
                if (score > best.score) best = { start, score };
            }
            state.start = best.start; syncControls(); scoreSeam(); draw(); dirty(); button.disabled = false; button.textContent = 'Find best seam';
        }, 20);
    }

    function draw() {
        if (!state.buffer) return;
        const canvas = nodes.wave, overlay = nodes.overlay, ratio = devicePixelRatio || 1, width = canvas.clientWidth, height = canvas.clientHeight;
        for (const item of [canvas, overlay]) { item.width = width * ratio; item.height = height * ratio; }
        const context = canvas.getContext('2d'); context.scale(ratio, ratio); context.clearRect(0, 0, width, height); context.fillStyle = getComputedStyle(document.documentElement).getPropertyValue('--paper'); context.fillRect(0, 0, width, height);
        const data = state.buffer.getChannelData(0), stride = Math.max(1, Math.floor(data.length / width)), middle = height / 2;
        context.strokeStyle = '#949ca1'; context.lineWidth = 1; context.beginPath();
        for (let x = 0; x < width; x++) { let peak = 0; for (let sample = x * stride; sample < Math.min(data.length, (x + 1) * stride); sample++) peak = Math.max(peak, Math.abs(data[sample])); context.moveTo(x, middle - peak * middle * .85); context.lineTo(x, middle + peak * middle * .85); } context.stroke();
        const startX = state.start / state.buffer.duration * width, endX = (state.start + state.length) / state.buffer.duration * width;
        context.fillStyle = 'rgba(188,35,44,.12)'; context.fillRect(startX, 0, endX - startX, height); context.fillStyle = '#bc232c'; context.fillRect(startX - 1, 0, 2, height); context.fillRect(endX - 1, 0, 2, height);
    }

    const RAMP = .01;
    function channelData(buffer) { const data = []; for (let channel = 0; channel < Math.min(2, buffer.numberOfChannels); channel++) data.push(buffer.getChannelData(channel)); return data; }
    function toAudioBuffer(built) {
        const output = audio.createBuffer(built.channels.length, built.frames, built.sampleRate);
        for (let channel = 0; channel < built.channels.length; channel++) output.getChannelData(channel).set(built.channels[channel]);
        return output;
    }
    function plan() { return loopPlan({ start: state.start, length: state.length, crossfade: state.crossfade }, state.buffer.sampleRate); }
    function loopOptions(extra) { return Object.assign({ start: state.start, length: state.length, crossfade: state.crossfade, normalize: nodes.normalize.checked, repeats: 1 }, extra || {}); }
    // What Play and Hear the seam both audition: one period of exactly what Download writes. The
    // fades are deliberately left off — they envelope the saved file once, so applying them here
    // would pump the preview on every repeat.
    function previewBuffer() { return toAudioBuffer(buildLoop(channelData(state.buffer), state.buffer.sampleRate, loopOptions())); }
    function exportBuffer() {
        const parsedDuration = parseDuration(nodes.fill.value);
        if (nodes.mode.value === 'fill' && !Number.isFinite(parsedDuration)) throw new Error(durationError);
        const shape = plan(), repeats = nodes.mode.value === 'fill' ? shape.repeatsFor(clamp(parsedDuration, shape.periodDuration, 1800)) : 1;
        return toAudioBuffer(buildLoop(channelData(state.buffer), state.buffer.sampleRate, loopOptions({ fadeIn: state.fadeIn, fadeOut: state.fadeOut, repeats })));
    }

    // Detaching onended before stopping is what keeps a retired source from reaching back into the
    // widget: the old handler used to fire after a replacement had already started and tear it down.
    function releaseSource(source, gain) {
        if (!source) return;
        source.onended = null;
        const now = audio.currentTime;
        try { gain.gain.cancelScheduledValues(now); gain.gain.setValueAtTime(gain.gain.value, now); gain.gain.linearRampToValueAtTime(0, now + RAMP); } catch {}
        try { source.stop(now + RAMP); } catch { try { source.stop(); } catch {} }
        setTimeout(() => { try { source.disconnect(); gain.disconnect(); } catch {} }, RAMP * 1000 + 120);
    }
    function stop() {
        releaseSource(state.source, state.gain);
        state.source = state.gain = state.loopBuffer = state.mode = null; state.playing = false;
        clearInterval(state.timer); state.timer = null;
        clearTimeout(state.rebuild); state.rebuild = null;
        nodes.play.textContent = 'Play loop';
        nodes.clock.textContent = `0:00.00 / ${fmt(state.length)}`;
    }
    function tick() {
        const span = state.loopBuffer ? state.loopBuffer.duration : 0;
        if (!span) return;
        // Position comes from the audio clock modulo the loop, not from a per-pass wall timer:
        // the source repeats itself now, so there is no per-pass restart left to time.
        const position = ((audio.currentTime - state.startedAt + state.offset) % span + span) % span;
        nodes.clock.textContent = `${fmt(position)} / ${fmt(span)}`;
    }
    // A native looping source: the wrap happens inside the audio thread, sample-accurately, rather
    // than in an onended callback an event loop away from the end of the last pass.
    function swapTo(buffer, mode, options) {
        const settings = options || {};
        releaseSource(state.source, state.gain);
        const gain = audio.createGain(), source = audio.createBufferSource(), now = audio.currentTime;
        source.buffer = buffer; source.loop = true; source.loopStart = 0; source.loopEnd = buffer.duration;
        source.connect(gain); gain.connect(audio.destination);
        // A 10 ms ramp, so swapping the buffer under a running loop is inaudible rather than a click.
        gain.gain.setValueAtTime(0, now); gain.gain.linearRampToValueAtTime(1, now + RAMP);
        const offset = clamp(settings.offset || 0, 0, Math.max(0, buffer.duration - 1 / buffer.sampleRate));
        source.start(now, offset);
        if (settings.duration) source.stop(now + settings.duration);
        Object.assign(state, { source, gain, loopBuffer: buffer, startedAt: now, offset, mode, playing: true });
        nodes.play.textContent = 'Stop';
        if (!state.timer) state.timer = setInterval(tick, 35);
        tick();
        return source;
    }
    function playLoop() { if (!state.buffer) return; audio.resume(); swapTo(previewBuffer(), 'loop'); }
    function togglePlay() { state.playing ? stop() : playLoop(); }
    function hearSeam() {
        if (!state.buffer) return;
        audio.resume();
        // Three seconds of the built loop starting 1.5 s before its end: the looping source carries
        // the audition through the wrap, so what plays is the join itself rather than the original
        // file's contiguous audio either side of it. The three seconds is fixed, not scaled by the
        // buffer's own duration — that used to cap it at four periods, so a short loop (a 0.125 s
        // period) auditioned for half a second instead of three.
        const buffer = previewBuffer(), source = swapTo(buffer, 'seam', { offset: Math.max(0, buffer.duration - 1.5), duration: 3 });
        source.onended = () => { if (state.source === source) stop(); };
    }
    // Controls move while a loop is running, so the preview is rebuilt and swapped in rather than
    // stopped. Debounced: a slider drag fires `input` per pixel and each rebuild walks the region.
    function refreshPlayback() {
        if (!state.playing || state.mode !== 'loop') return;
        clearTimeout(state.rebuild);
        state.rebuild = setTimeout(() => { state.rebuild = null; if (state.playing && state.mode === 'loop' && state.buffer) swapTo(previewBuffer(), 'loop'); }, 120);
    }
    function wavBlob(buffer) {
        const channels = buffer.numberOfChannels, frames = buffer.length, bytes = 44 + frames * channels * 2, view = new DataView(new ArrayBuffer(bytes));
        const text = (offset, value) => { for (let index = 0; index < value.length; index++) view.setUint8(offset + index, value.charCodeAt(index)); };
        text(0, 'RIFF'); view.setUint32(4, bytes - 8, true); text(8, 'WAVE'); text(12, 'fmt '); view.setUint32(16, 16, true); view.setUint16(20, 1, true); view.setUint16(22, channels, true); view.setUint32(24, buffer.sampleRate, true); view.setUint32(28, buffer.sampleRate * channels * 2, true); view.setUint16(32, channels * 2, true); view.setUint16(34, 16, true); text(36, 'data'); view.setUint32(40, bytes - 44, true);
        let offset = 44; for (let frame = 0; frame < frames; frame++) for (let channel = 0; channel < channels; channel++) { const value = clamp(buffer.getChannelData(channel)[frame], -1, 1); view.setInt16(offset, value < 0 ? value * 32768 : value * 32767, true); offset += 2; }
        return new Blob([view], { type: 'audio/wav' });
    }
    async function mp3Blob(buffer, kbps) {
        if (!window.lamejs) throw new Error('MP3 encoder unavailable');
        const channels = buffer.numberOfChannels, encoder = new lamejs.Mp3Encoder(channels, buffer.sampleRate, kbps), block = 1152, parts = [];
        const pcm = channel => Int16Array.from(buffer.getChannelData(channel), value => Math.round(clamp(value, -1, 1) * (value < 0 ? 32768 : 32767)));
        const left = pcm(0), right = channels > 1 ? pcm(1) : null;
        for (let index = 0; index < left.length; index += block) { const bytes = channels > 1 ? encoder.encodeBuffer(left.subarray(index, index + block), right.subarray(index, index + block)) : encoder.encodeBuffer(left.subarray(index, index + block)); if (bytes.length) parts.push(bytes); if (index % (block * 300) === 0) await new Promise(resolve => setTimeout(resolve)); }
        const end = encoder.flush(); if (end.length) parts.push(end); return new Blob(parts, { type: 'audio/mpeg' });
    }
    async function exportAudio() {
        const button = $('[data-export]'); button.disabled = true; button.textContent = 'Encoding…'; setStatus('');
        try {
            const loop = exportBuffer(), format = nodes.format.value, extension = format === 'wav' ? 'wav' : 'mp3', blob = format === 'wav' ? wavBlob(loop) : await mp3Blob(loop, Number(format.split('-')[1]));
            const link = document.createElement('a'), url = URL.createObjectURL(blob); link.href = url; link.download = `${state.fileName}-loop.${extension}`; link.click(); setTimeout(() => URL.revokeObjectURL(url), 60_000); setStatus(`Saved ${link.download}`);
        } catch (error) { setStatus(error.message || 'Export failed.'); }
        finally { button.disabled = false; updateExport(); }
    }
    function updateExport() {
        const format = nodes.format.value, isWav = format === 'wav', parsedDuration = parseDuration(nodes.fill.value), invalidDuration = nodes.mode.value === 'fill' && !Number.isFinite(parsedDuration), button = $('[data-export]');
        button.textContent = isWav ? 'Download WAV' : 'Download MP3'; $('[data-fill-wrap]').hidden = nodes.mode.value !== 'fill'; button.disabled = invalidDuration;
        if (invalidDuration) { $('[data-export-summary]').textContent = 'Enter a valid duration'; setStatus(durationError); return; }
        if (nodes.status.textContent === durationError) setStatus('');
        // One pass is the loop minus its crossfade overlap, and a fill target is rounded to whole
        // passes, so the summary reports the frames actually written rather than the slider value.
        const shape = state.buffer ? plan() : null, period = shape ? shape.periodDuration : state.length || 1;
        const duration = nodes.mode.value === 'fill' && shape ? shape.repeatsFor(clamp(parsedDuration, period, 1800)) * period : period;
        $('[data-export-summary]').textContent = `${isWav ? 'WAV' : `MP3 ${format.split('-')[1]} kbps`} · ${fmt(duration)}${nodes.normalize.checked ? ' · normalized' : ''}`;
    }

    function loadDemo() {
        const rate = 44_100, duration = 8, buffer = audio.createBuffer(2, rate * duration, rate);
        for (let channel = 0; channel < 2; channel++) { const values = buffer.getChannelData(channel); for (let index = 0; index < values.length; index++) { const time = index / rate; values[index] = (.18 * Math.sin(2 * Math.PI * 110 * time) + .08 * Math.sin(2 * Math.PI * 220 * time)) * (.75 + .25 * Math.sin(2 * Math.PI * 2 * time)); } }
        const blob = wavBlob(buffer); loadFile(new File([blob], 'demo.wav', { type: 'audio/wav' }));
    }

    $$('input[type="file"]').forEach(input => input.addEventListener('change', event => loadFile(event.target.files[0])));
    for (const type of ['dragenter', 'dragover']) nodes.drop.addEventListener(type, event => { event.preventDefault(); nodes.drop.classList.add('drag'); });
    for (const type of ['dragleave', 'drop']) nodes.drop.addEventListener(type, event => { event.preventDefault(); nodes.drop.classList.remove('drag'); });
    nodes.drop.addEventListener('drop', event => loadFile(event.dataTransfer.files[0]));
    $('[data-demo]').addEventListener('click', loadDemo); nodes.play.addEventListener('click', togglePlay); $('[data-hear-seam]').addEventListener('click', hearSeam); $('[data-find-best]').addEventListener('click', findBest); $('[data-export]').addEventListener('click', exportAudio);
    for (const [key, node] of [['start', nodes.start], ['length', nodes.length], ['crossfade', nodes.crossfade], ['fadeIn', nodes.fadeIn], ['fadeOut', nodes.fadeOut]]) node.addEventListener('input', () => { state[key] = Number(node.value); syncControls(); scoreSeam(); draw(); dirty(); });
    for (const node of [nodes.format, nodes.mode, nodes.fill, nodes.normalize]) node.addEventListener('change', () => { savePrefs(); updateExport(); refreshPlayback(); dirty(); });
    nodes.fill.addEventListener('input', () => { updateExport(); dirty(); });
    nodes.smart.addEventListener('change', () => { if (nodes.smart.checked) findBest(); dirty(); });
    nodes.overlay.addEventListener('pointerdown', event => { if (!state.buffer) return; const rect = nodes.overlay.getBoundingClientRect(), time = (event.clientX - rect.left) / rect.width * state.buffer.duration, edge = Math.max(.15, state.buffer.duration * .012); const end = state.start + state.length; state.drag = Math.abs(time - state.start) < edge ? 'start' : Math.abs(time - end) < edge ? 'end' : 'move'; state.dragOffset = time - state.start; nodes.overlay.setPointerCapture(event.pointerId); });
    nodes.overlay.addEventListener('pointermove', event => { if (!state.drag) return; const rect = nodes.overlay.getBoundingClientRect(), time = clamp((event.clientX - rect.left) / rect.width * state.buffer.duration, 0, state.buffer.duration); if (state.drag === 'start') { const end = state.start + state.length; state.start = Math.min(time, end - .25); state.length = end - state.start; } else if (state.drag === 'end') state.length = clamp(time - state.start, .25, state.buffer.duration - state.start); else state.start = clamp(time - state.dragOffset, 0, state.buffer.duration - state.length); syncControls(); scoreSeam(); draw(); dirty(); });
    for (const type of ['pointerup', 'pointercancel']) nodes.overlay.addEventListener(type, () => { state.drag = null; });
    window.addEventListener('keydown', event => { if (!state.buffer || ['INPUT', 'SELECT'].includes(event.target.tagName)) return; if (event.code === 'Space') { event.preventDefault(); togglePlay(); } if (event.key === 'ArrowLeft' || event.key === 'ArrowRight') { event.preventDefault(); state.start += (event.shiftKey ? .1 : .01) * (event.key === 'ArrowLeft' ? -1 : 1); syncControls(); scoreSeam(); draw(); dirty(); } });
    window.addEventListener('message', event => { if (event.source !== window.parent || event.origin !== location.origin || event.data?.protocol !== 'mothership-widget/v1') return; if (event.data.type === 'theme') { document.documentElement.dataset.theme = event.data.payload?.theme === 'dark' ? 'dark' : 'light'; draw(); } if (event.data.type === 'active' && !event.data.payload?.active) stop(); });
    // WAV by default: LAME writes encoder delay and padding with no gapless tag, so a looped MP3
    // gains a 25-50 ms hole at the wrap in most players. WAV stays available for size-sensitive use.
    const prefs = savedPrefs(); nodes.format.value = prefs.format || 'wav'; nodes.mode.value = prefs.mode || 'once'; nodes.fill.value = prefs.fill || '3:00'; nodes.normalize.checked = !!prefs.normalize; updateExport(); frameMessage('ready');
})();
