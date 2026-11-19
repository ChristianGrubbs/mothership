(() => {
    'use strict';

    const $ = selector => document.querySelector(selector);
    const $$ = selector => [...document.querySelectorAll(selector)];
    const state = { buffer: null, fileName: '', start: 0, length: 1, crossfade: .18, fadeIn: 0, fadeOut: 0, score: 0, source: null, gain: null, loopBuffer: null, startedAt: 0, offset: 0, mode: null, timer: null, rebuild: null, playing: false };
    const { buildLoop, loopPlan } = window.LoopCore;
    const audio = new (window.AudioContext || window.webkitAudioContext)();
    // The page that frames the tool (home-frame.js): its bar, its notices and the Library. Absent from disk.
    const frame = window.MothershipFrame || null;
    const prefsKey = 'loop-maker:prefs';
    const durationError = 'Enter a duration as seconds or m:ss.';
    const nodes = {
        main: $('[data-lm]'), empty: $('[data-empty]'), drop: $('[data-drop-zone]'), editor: $('[data-editor]'), controls: $('[data-controls]'), wave: $('[data-wave]'), overlay: $('[data-overlay]'),
        start: $('[data-start]'), length: $('[data-length]'), crossfade: $('[data-crossfade]'), fadeIn: $('[data-fade-in]'), fadeOut: $('[data-fade-out]'),
        smart: $('[aria-label="Smart seam"]'), play: $('[data-play]'), playLabel: $('[data-play-label]'), clock: $('[data-clock]'), format: $('[data-format]'), mode: $('[data-export-mode]'), fill: $('[data-fill]'), normalize: $('[data-normalize]'), status: $('[data-status]'), uploadStatus: $('[data-upload-status]'), toolStatus: $('[data-tool-status]'),
        exportSheet: $('[data-sheet="export"]'), librarySheet: $('[data-sheet="library"]'),
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
    // The Export sheet's line. A failure carries the kit's ✕ as well as its colour.
    function setStatus(message, error = false) { nodes.status.textContent = message; nodes.status.classList.toggle('is-error', Boolean(message) && error); }
    // The tool's own line, used only where no bar is drawn to say it.
    function toolStatus(message, error = false) { nodes.toolStatus.textContent = message; nodes.toolStatus.classList.toggle('is-error', Boolean(message) && error); }
    function savedPrefs() { try { return JSON.parse(localStorage.getItem(prefsKey) || '{}'); } catch { return {}; } }
    function savePrefs() {
        try { localStorage.setItem(prefsKey, JSON.stringify({ format: nodes.format.value, mode: nodes.mode.value, fill: nodes.fill.value, normalize: nodes.normalize.checked, folder: state.folder || '' })); } catch {}
    }

    // What the bar offers: nothing until a track is open, then Export and Save to Library. No saved sets.
    function tellBar() { frame?.chrome(state.buffer ? { export: true, library: true } : {}); }

    // A refused file is said in the empty state's line; with a track already open, the stage's line says it too, since
    // the empty state is hidden.
    function refuse(message) {
        nodes.uploadStatus.textContent = message;
        if (state.buffer) toolStatus(`${message} The open track is unchanged.`, true);
    }
    async function loadFile(file) {
        if (!file) return;
        if (file.size > 250 * 1024 * 1024) { refuse('Choose an audio file smaller than 250 MB.'); return; }
        stop(); // Replacing the track mid-playback must not leave the previous loop running under it.
        $('[data-drop-title]').textContent = 'Reading the track…';
        nodes.drop.setAttribute('aria-busy', 'true');
        nodes.uploadStatus.textContent = '';
        setStatus('');
        toolStatus('');
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
            nodes.empty.hidden = true;
            nodes.editor.hidden = false;
            nodes.controls.hidden = false;
            nodes.main.dataset.loaded = 'true';
            const name = $('[data-file-name]');
            name.textContent = file.name;
            name.title = file.name;
            $('[data-file-meta]').textContent = `${fmt(buffer.duration)} · ${buffer.sampleRate.toLocaleString('en')} Hz · ${buffer.numberOfChannels === 1 ? 'mono' : 'stereo'}`;
            $('[data-duration]').textContent = fmt(buffer.duration);
            syncControls();
            draw();
            scoreSeam();
            tellBar();
            dirty();
        } catch {
            refuse("Couldn't read that file. Try an MP3, WAV, M4A, or OGG.");
        } finally {
            $('[data-drop-title]').textContent = 'Drop an audio file here';
            nodes.drop.removeAttribute('aria-busy');
        }
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
        if (!state.playing) restClock();
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
    // The figure is the readout's largest text; its word says how good the join is, so no colour has to.
    function scoreSeam() {
        state.score = seamCorrelation(state.start, state.length);
        const word = state.score >= 75 ? 'clean' : state.score >= 45 ? 'fair' : 'rough';
        const figure = document.createElement('b'), words = document.createElement('span');
        figure.textContent = `${state.score}%`;
        words.textContent = ` · ${word}`;
        $('[data-seam-score]').replaceChildren(figure, words);
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

    // The track is the person's content, so it takes no accent: all of it in the quiet ink, the region's part in full
    // ink on a faint band, with an edge and a grab mark at each end. The colours are read from the kit each time, so a
    // theme change only needs a redraw.
    function draw() {
        if (!state.buffer) return;
        const canvas = nodes.wave, overlay = nodes.overlay, ratio = devicePixelRatio || 1, width = canvas.clientWidth, height = canvas.clientHeight;
        if (!width || !height) return;
        for (const item of [canvas, overlay]) { item.width = Math.round(width * ratio); item.height = Math.round(height * ratio); }
        const styles = getComputedStyle(document.documentElement), ink = styles.getPropertyValue('--ink').trim(), quiet = styles.getPropertyValue('--ink-3').trim();
        const context = canvas.getContext('2d'); context.scale(ratio, ratio); context.clearRect(0, 0, width, height);
        const data = state.buffer.getChannelData(0), stride = Math.max(1, Math.floor(data.length / width)), middle = height / 2;
        const peaks = new Float32Array(width);
        for (let x = 0; x < width; x++) { let peak = 0; for (let sample = x * stride; sample < Math.min(data.length, (x + 1) * stride); sample++) peak = Math.max(peak, Math.abs(data[sample])); peaks[x] = peak; }
        const wave = colour => { context.strokeStyle = colour; context.lineWidth = 1; context.beginPath(); for (let x = 0; x < width; x++) { context.moveTo(x + .5, middle - peaks[x] * middle * .82); context.lineTo(x + .5, middle + peaks[x] * middle * .82); } context.stroke(); };
        const startX = state.start / state.buffer.duration * width, endX = (state.start + state.length) / state.buffer.duration * width;
        wave(quiet);
        context.save();
        context.beginPath(); context.rect(startX, 0, endX - startX, height); context.clip();
        context.globalAlpha = .08; context.fillStyle = ink; context.fillRect(startX, 0, endX - startX, height); context.globalAlpha = 1;
        wave(ink);
        context.restore();
        // Each edge: a 2 px line with a grab tab at the top and the bottom, clear of the wave, kept whole at the canvas ends.
        context.fillStyle = ink;
        for (const x of [startX, endX]) {
            const line = Math.min(width - 2, Math.max(0, Math.round(x) - 1)), tab = Math.min(width - 10, Math.max(0, Math.round(x) - 5));
            context.fillRect(line, 0, 2, height);
            context.beginPath(); context.roundRect(tab, 0, 10, 16, [0, 0, 4, 4]); context.roundRect(tab, height - 16, 10, 16, [4, 4, 0, 0]); context.fill();
        }
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
    function showPlaying(playing) {
        nodes.playLabel.textContent = playing ? 'Stop' : 'Play loop';
        nodes.play.dataset.playing = String(playing);
    }
    function stop() {
        releaseSource(state.source, state.gain);
        state.source = state.gain = state.loopBuffer = state.mode = null; state.playing = false;
        clearInterval(state.timer); state.timer = null;
        clearTimeout(state.rebuild); state.rebuild = null;
        showPlaying(false);
        restClock();
    }
    // At rest the clock shows one pass as Play hears it and Export writes it: the loop less its crossfade.
    function restClock() { nodes.clock.textContent = `0:00.00 / ${fmt(state.buffer ? plan().periodDuration : 0)}`; }
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
        showPlaying(true);
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
    // The one file both Download and Save to Library write, from the track as it is when pressed.
    function fileName() { return `${state.fileName}-loop.${nodes.format.value === 'wav' ? 'wav' : 'mp3'}`; }
    async function makeFile() {
        const loop = exportBuffer(), format = nodes.format.value;
        return { name: fileName(), blob: format === 'wav' ? wavBlob(loop) : await mp3Blob(loop, Number(format.split('-')[1])) };
    }
    async function exportAudio() {
        const button = $('[data-export]'); button.disabled = true; button.textContent = 'Encoding…'; setStatus('');
        try {
            const { name, blob } = await makeFile();
            const link = document.createElement('a'), url = URL.createObjectURL(blob); link.href = url; link.download = name; link.click(); setTimeout(() => URL.revokeObjectURL(url), 60_000); setStatus(`Downloaded ${name}`);
        } catch (error) { setStatus(error.message || 'Export failed.', true); }
        finally { button.disabled = false; updateExport(); }
    }
    // What will be written, in words; the Export and Library sheets show the same line.
    function summary() {
        const format = nodes.format.value, parsedDuration = parseDuration(nodes.fill.value);
        if (nodes.mode.value === 'fill' && !Number.isFinite(parsedDuration)) return null;
        // One pass is the loop minus its crossfade overlap, and a fill target is rounded to whole
        // passes, so the summary reports the frames actually written rather than the slider value.
        const shape = state.buffer ? plan() : null, period = shape ? shape.periodDuration : state.length || 1;
        const duration = nodes.mode.value === 'fill' && shape ? shape.repeatsFor(clamp(parsedDuration, period, 1800)) * period : period;
        return `${format === 'wav' ? 'WAV' : `MP3 ${format.split('-')[1]} kbps`} · ${fmt(duration)}${nodes.normalize.checked ? ' · normalized' : ''}`;
    }
    function updateExport() {
        const isWav = nodes.format.value === 'wav', written = summary(), button = $('[data-export]'), fill = nodes.fill;
        button.textContent = isWav ? 'Download WAV' : 'Download MP3'; $('[data-fill-wrap]').hidden = nodes.mode.value !== 'fill'; button.disabled = !written;
        if (!written) { fill.setAttribute('aria-invalid', 'true'); $('[data-export-summary]').textContent = 'Enter a valid duration'; setStatus(durationError, true); setLibrary(); return; }
        fill.removeAttribute('aria-invalid');
        if (nodes.status.textContent === durationError) setStatus('');
        $('[data-export-summary]').textContent = written;
        setLibrary();
    }

    /* ---------------------------------------------------------------- sheets */

    const openSheet = () => $('dialog[open]');
    function showSheet(sheet) {
        if (sheet.open) return;
        openSheet()?.close();
        sheet.showModal();
        // The first control takes the focus, never a text field, which on a phone would raise the keyboard.
        (sheet.querySelector('.tk-sheet-body :is(select, button, input:not([type="text"], [data-fill])):not(:disabled)') ?? sheet.querySelector('.tk-sheet-foot button:not(:disabled)'))?.focus();
    }
    function openExport() {
        if (!state.buffer) return;
        if (nodes.status.textContent !== durationError) setStatus('');
        updateExport();
        showSheet(nodes.exportSheet);
    }

    // Save to Library: the loop, as set in Export, into a folder the account may upload to.
    let folders = null;
    // A save holds the sheet until the Library answers.
    let saving = false;
    // A save that fails after its sheet was closed is said at once, and again in the sheet the next time it opens.
    let failure = '';
    function setLibrary({ message = '', error = false } = {}) {
        const none = Array.isArray(folders) && !folders.length, written = state.buffer ? summary() : null;
        // With no folder to save into there is nothing to choose: the sheet says so and offers nothing.
        $('[data-library-what]').hidden = none;
        $('[data-folder-field]').hidden = none;
        $('[data-folder-empty]').hidden = !none;
        if (!saving) {
            $('[data-library-file]').textContent = state.buffer ? fileName() : '';
            $('[data-library-summary]').textContent = written || 'Enter a valid duration in Export first.';
        }
        $('[data-folder]').disabled = saving;
        const go = $('[data-library-go]');
        go.disabled = saving || none || !folders || !written;
        go.textContent = saving ? 'Saving…' : 'Save';
        const line = $('[data-sheet="library"] .tk-status');
        line.textContent = message || (saving ? 'Saving to the Library…' : '');
        line.classList.toggle('is-error', Boolean(message) && error);
    }
    async function openLibrary() {
        if (!frame?.library || !state.buffer) return;
        showSheet(nodes.librarySheet);
        const kept = failure ? { message: failure, error: true } : {};
        failure = '';
        if (folders) { setLibrary(kept); $('[data-library-go]:not(:disabled)')?.focus(); return; }
        setLibrary({ message: 'Finding your folders…' });
        try {
            folders = await frame.folders();
            const select = $('[data-folder]');
            select.replaceChildren(...folders.map(folder => new Option(`${'  '.repeat(folder.depth)}${folder.name}`, folder.id)));
            if (folders.some(folder => folder.id === state.folder)) select.value = state.folder;
            setLibrary(kept);
        } catch (error) {
            folders = null;
            setLibrary({ message: `${error.message || 'Your folders could not be read.'} Close this and try again.`, error: true });
        }
    }
    async function saveToLibrary() {
        if (saving) return;
        const folder = $('[data-folder]').value;
        // Held from the press, so a second press while the file is made sends nothing more.
        saving = true;
        failure = '';
        setLibrary();
        let file = null, failed = null;
        try {
            // The file is made here, from the loop as it is when Save is pressed, before anything is sent.
            file = await makeFile();
            const saved = await frame.save({ files: [file], folder });
            state.folder = folder;
            savePrefs();
            const text = `Saved ${file.name} to ${folders.find(item => item.id === folder)?.name || 'the Library'}.`;
            nodes.librarySheet.close();
            frame.notice({ text, ...(saved.url ? { link: { label: 'Open in Library', url: saved.url } } : {}) });
            // Where the page draws a bar, its status line says it once, with the link. Elsewhere the tool says it.
            if (!frame.bar) toolStatus(text);
        } catch (error) {
            failed = error;
        } finally {
            saving = false;
        }
        if (!failed) { setLibrary(); return; }
        const message = failed.message || (file ? 'The file could not be saved. Try again.' : 'The loop could not be made.');
        if (nodes.librarySheet.open) {
            setLibrary({ message, error: true });
            // Save was disabled while it ran, which dropped the focus; give it back so Enter tries again.
            $('[data-library-go]').focus();
            return;
        }
        // The sheet was closed while it saved: the bar says so now (the tool's own line where there is no bar).
        failure = message;
        setLibrary();
        frame.notice({ text: `Not saved to the Library. ${message}`, tone: 'error' });
        if (!frame.bar) toolStatus(`Not saved to the Library. ${message}`, true);
    }

    function loadDemo() {
        const rate = 44_100, duration = 8, buffer = audio.createBuffer(2, rate * duration, rate);
        for (let channel = 0; channel < 2; channel++) { const values = buffer.getChannelData(channel); for (let index = 0; index < values.length; index++) { const time = index / rate; values[index] = (.18 * Math.sin(2 * Math.PI * 110 * time) + .08 * Math.sin(2 * Math.PI * 220 * time)) * (.75 + .25 * Math.sin(2 * Math.PI * 2 * time)); } }
        const blob = wavBlob(buffer); loadFile(new File([blob], 'demo.wav', { type: 'audio/wav' }));
    }

    /* ---------------------------------------------------------------- wiring */

    $$('input[type="file"]').forEach(input => input.addEventListener('change', event => { const file = event.target.files[0]; event.target.value = ''; loadFile(file); }));
    for (const type of ['dragenter', 'dragover']) nodes.drop.addEventListener(type, event => { event.preventDefault(); nodes.drop.classList.add('drag'); });
    for (const type of ['dragleave', 'drop']) nodes.drop.addEventListener(type, event => { event.preventDefault(); nodes.drop.classList.remove('drag'); });
    nodes.drop.addEventListener('drop', event => loadFile(event.dataTransfer.files[0]));
    $('[data-demo]').addEventListener('click', loadDemo); nodes.play.addEventListener('click', togglePlay); $('[data-hear-seam]').addEventListener('click', hearSeam); $('[data-find-best]').addEventListener('click', findBest); $('[data-export]').addEventListener('click', exportAudio);
    $('[data-open-export]').addEventListener('click', openExport);
    $('[data-open-library]').addEventListener('click', openLibrary);
    $('[data-library-go]').addEventListener('click', saveToLibrary);
    for (const button of $$('[data-sheet-close]')) button.addEventListener('click', () => button.closest('dialog').close());
    for (const [key, node] of [['start', nodes.start], ['length', nodes.length], ['crossfade', nodes.crossfade], ['fadeIn', nodes.fadeIn], ['fadeOut', nodes.fadeOut]]) node.addEventListener('input', () => { state[key] = Number(node.value); syncControls(); scoreSeam(); draw(); dirty(); });
    for (const node of [nodes.format, nodes.mode, nodes.fill, nodes.normalize]) node.addEventListener('change', () => { savePrefs(); updateExport(); refreshPlayback(); dirty(); });
    nodes.fill.addEventListener('input', () => { updateExport(); dirty(); });
    nodes.smart.addEventListener('change', () => { if (nodes.smart.checked) findBest(); dirty(); });
    const edgeAt = time => { const edge = Math.max(.15, state.buffer.duration * .012), end = state.start + state.length; return Math.abs(time - state.start) < edge ? 'start' : Math.abs(time - end) < edge ? 'end' : 'move'; };
    const timeAt = event => { const rect = nodes.overlay.getBoundingClientRect(); return (event.clientX - rect.left) / rect.width * state.buffer.duration; };
    nodes.overlay.addEventListener('pointerdown', event => { if (!state.buffer) return; const time = timeAt(event); state.drag = edgeAt(time); state.dragOffset = time - state.start; state.dragFrom = { start: state.start, length: state.length, crossfade: state.crossfade, fadeIn: state.fadeIn, fadeOut: state.fadeOut }; nodes.overlay.setPointerCapture(event.pointerId); });
    nodes.overlay.addEventListener('pointermove', event => {
        if (!state.buffer) return;
        // The pointer says what a press would do: resize at an edge, move inside or beside the region.
        if (!state.drag) { nodes.overlay.style.cursor = edgeAt(timeAt(event)) === 'move' ? 'grab' : 'ew-resize'; return; }
        const time = clamp(timeAt(event), 0, state.buffer.duration);
        if (state.drag === 'start') { const end = state.start + state.length; state.start = Math.min(time, end - .25); state.length = end - state.start; } else if (state.drag === 'end') state.length = clamp(time - state.start, .25, state.buffer.duration - state.start); else state.start = clamp(time - state.dragOffset, 0, state.buffer.duration - state.length);
        syncControls(); scoreSeam(); draw(); dirty();
    });
    nodes.overlay.addEventListener('pointerup', () => { state.drag = null; });
    // A finger that goes up or down the wave scrolls the page, and the browser then cancels the press: the region goes
    // back to where it was, with the crossfade and fades a shorter region may have capped, so the slight sideways drift
    // of a scroll edits nothing.
    nodes.overlay.addEventListener('pointercancel', () => {
        if (!state.drag) return;
        state.drag = null;
        Object.assign(state, state.dragFrom);
        syncControls(); scoreSeam(); draw();
    });
    // Space plays and the arrows nudge the start, but never in place of what a field, a button or an open sheet does.
    window.addEventListener('keydown', event => {
        const target = event.target;
        if (!state.buffer || openSheet() || event.defaultPrevented || event.metaKey || event.ctrlKey || event.altKey) return;
        if (target.closest?.('input, select, textarea, [contenteditable=""], [contenteditable="true"]')) return;
        if (event.code === 'Space' && !target.closest?.('button, a[href], label')) { event.preventDefault(); togglePlay(); }
        if (event.key === 'ArrowLeft' || event.key === 'ArrowRight') { event.preventDefault(); state.start += (event.shiftKey ? .1 : .01) * (event.key === 'ArrowLeft' ? -1 : 1); syncControls(); scoreSeam(); draw(); dirty(); }
    });
    window.addEventListener('message', event => { if (event.source !== window.parent || event.origin !== location.origin || event.data?.protocol !== 'mothership-widget/v1') return; if (event.data.type === 'theme') document.documentElement.dataset.theme = event.data.payload?.theme === 'dark' ? 'dark' : 'light'; if (event.data.type === 'active' && !event.data.payload?.active) stop(); });
    // Redraw in the new colours when the theme switches, and at the new size when the stage changes width.
    new MutationObserver(draw).observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });
    window.matchMedia('(prefers-color-scheme: dark)').addEventListener?.('change', draw);
    new ResizeObserver(() => draw()).observe(nodes.wave);

    // What the bar's buttons do. Where no bar is drawn, the stage's own row does the same.
    frame?.on('export', openExport);
    frame?.on('library', openLibrary);
    $('[data-own-bar]').hidden = Boolean(frame?.bar);
    $('[data-open-library]').hidden = !frame?.library;

    // WAV by default: LAME writes encoder delay and padding with no gapless tag, so a looped MP3
    // gains a 25-50 ms hole at the wrap in most players. WAV stays available for size-sensitive use.
    const prefs = savedPrefs(); nodes.format.value = prefs.format || 'wav'; nodes.mode.value = prefs.mode || 'once'; nodes.fill.value = prefs.fill || '3:00'; nodes.normalize.checked = !!prefs.normalize; state.folder = prefs.folder || ''; updateExport(); tellBar(); frameMessage('ready');
})();
