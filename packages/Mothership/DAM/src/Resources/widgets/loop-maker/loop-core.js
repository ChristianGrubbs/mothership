/**
 * Loop Maker's loop builder, kept free of the DOM and of the Web Audio API so the widget's
 * preview and its export run the identical arithmetic and so Node can test it
 * (packages/Mothership/DAM/tests/js/loop-maker-core.test.mjs).
 *
 * UMD on purpose: index.html loads this as a plain <script> (window.LoopCore) and the test
 * require()s it. Do not convert it to ESM without adding a build step for the widget.
 */
(function (root, factory) {
    const api = factory();
    if (typeof module === 'object' && module && module.exports) module.exports = api;
    else root.LoopCore = api;
})(typeof globalThis === 'object' ? globalThis : this, function () {
    'use strict';

    const MAX_CROSSFADE = 4;
    const NORMALIZE_PEAK = 0.891; // -1 dBFS.

    function positive(value) {
        const number = Number(value);
        return Number.isFinite(number) && number > 0 ? number : 0;
    }

    /**
     * Frame arithmetic shared by the preview, the export and the seam audition.
     *
     * The crossfade is capped by half the loop length (the tail cannot overlap past the head) and
     * by four seconds — never by `start`. An older design read pre-roll audio from before the loop
     * start and so capped the crossfade at `start`, which left a loop beginning 0.1 s into a file
     * with at most a 0.1 s crossfade. Nothing here reads before `startFrame`.
     *
     * `loopFrames` is the rounded *end* frame minus the rounded start frame, not `start` and
     * `length` rounded independently and summed: rounding each to its own nearest frame can push
     * their sum a whole frame past where a single rounding of the end would land, and buildLoop
     * reads that extra frame as silence past the end of the source. `availableFrames`, when given,
     * additionally caps the end at the actual length of the source data.
     */
    function loopPlan(options, sampleRate, availableFrames) {
        const rate = Math.max(1, Math.round(positive(sampleRate) || 1));
        const start = positive(options && options.start);
        const length = positive(options && options.length);
        const startFrame = Math.round(start * rate);
        const limit = Number.isFinite(availableFrames) ? Math.max(0, availableFrames) : Infinity;
        const endFrame = Math.min(limit, Math.round((start + length) * rate));
        const loopFrames = Math.max(1, endFrame - startFrame);
        const requested = Math.round(Math.min(positive(options && options.crossfade), MAX_CROSSFADE) * rate);
        const crossfadeFrames = Math.max(0, Math.min(requested, Math.floor(loopFrames / 2)));
        const periodFrames = Math.max(1, loopFrames - crossfadeFrames);
        return {
            sampleRate: rate,
            startFrame,
            loopFrames,
            crossfadeFrames,
            periodFrames,
            periodDuration: periodFrames / rate,
            /** Whole periods that come closest to `seconds`; a partial period would break the wrap. */
            repeatsFor(seconds) {
                return Math.max(1, Math.round(positive(seconds) * rate / periodFrames));
            },
        };
    }

    /**
     * Build the loop as raw channel data.
     *
     * The emitted period is `loopFrames - crossfadeFrames`: the region's last `crossfadeFrames`
     * are folded onto its first `crossfadeFrames` rather than being emitted a second time. That
     * is what makes the wrap seamless — the final emitted sample is the source frame immediately
     * before the one the period starts from, so playing the end into the start is a continuation
     * of the source, not a jump.
     *
     * @param {Array<Float32Array|number[]>} channelData one entry per channel, read-only.
     * @param {number} sampleRate frames per second.
     * @param {{start:number,length:number,crossfade:number,fadeIn?:number,fadeOut?:number,normalize?:boolean,repeats?:number}} options
     */
    function buildLoop(channelData, sampleRate, options) {
        const settings = options || {};
        // Cap the loop's end at the shortest channel's actual length, so a half-frame rounding
        // cannot ask for a sample the source never had.
        let availableFrames = Infinity;
        for (const input of channelData) availableFrames = Math.min(availableFrames, input.length);
        const plan = loopPlan(settings, sampleRate, availableFrames);
        const { startFrame, periodFrames, crossfadeFrames } = plan;
        const repeats = Math.max(1, Math.round(positive(settings.repeats) || 1));
        const frames = periodFrames * repeats;
        const fadeIn = positive(settings.fadeIn);
        const fadeOut = positive(settings.fadeOut);
        const channels = [];

        for (const input of channelData) {
            const period = new Float32Array(periodFrames);
            for (let frame = 0; frame < periodFrames; frame++) {
                const head = input[startFrame + frame] || 0;
                if (crossfadeFrames && frame < crossfadeFrames) {
                    // Equal power (cos/sin), so two correlated takes keep a constant energy sum
                    // across the blend instead of dipping the way a linear pair does.
                    const t = frame / crossfadeFrames;
                    const tail = input[startFrame + periodFrames + frame] || 0;
                    period[frame] = tail * Math.cos(t * Math.PI / 2) + head * Math.sin(t * Math.PI / 2);
                } else {
                    period[frame] = head;
                }
            }
            const result = new Float32Array(frames);
            for (let frame = 0; frame < frames; frame++) result[frame] = period[frame % periodFrames];
            channels.push(result);
        }

        // Fades envelope the finished file, not each pass: a repeat-to-fill export fades in once
        // at the top and out once at the end.
        if (fadeIn || fadeOut) {
            for (const result of channels) {
                for (let frame = 0; frame < frames; frame++) {
                    const time = frame / plan.sampleRate;
                    const remaining = (frames - frame) / plan.sampleRate;
                    if (fadeIn && time < fadeIn) result[frame] *= time / fadeIn;
                    if (fadeOut && remaining < fadeOut) result[frame] *= remaining / fadeOut;
                }
            }
        }

        if (settings.normalize) {
            let peak = 0;
            for (const result of channels) for (const value of result) peak = Math.max(peak, Math.abs(value));
            // True normalize: scale so the peak lands on -1 dBFS whichever direction that takes —
            // quiet material is lifted, hot material is attenuated. Silence has no peak to scale
            // by, so it stays silent rather than dividing by zero.
            const gain = peak ? NORMALIZE_PEAK / peak : 1;
            if (gain !== 1) for (const result of channels) for (let frame = 0; frame < frames; frame++) result[frame] *= gain;
        }

        return {
            channels,
            sampleRate: plan.sampleRate,
            frames,
            repeats,
            duration: frames / plan.sampleRate,
            periodFrames,
            periodDuration: plan.periodDuration,
            loopFrames: plan.loopFrames,
            crossfadeFrames,
        };
    }

    return { buildLoop, loopPlan, MAX_CROSSFADE, NORMALIZE_PEAK };
});
