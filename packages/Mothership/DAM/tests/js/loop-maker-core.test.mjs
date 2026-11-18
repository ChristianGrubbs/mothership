import test from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';

// The Loop Maker gadget loads loop-core.js as a plain <script>, so the module is UMD: a bare
// global in the browser and module.exports under Node. require() keeps that contract honest —
// if the file ever becomes ESM-only the widget page breaks and this import fails with it.
const require = createRequire(import.meta.url);
const { buildLoop, loopPlan } = require('../../src/Resources/widgets/loop-maker/loop-core.js');

const rate = 8_000;

// A strictly increasing ramp: every sample is its own index, so an assertion can name the exact
// source frame a given output frame must have come from.
const ramp = frames => Float32Array.from({ length: frames }, (_, index) => index / frames);
const sine = (frames, hz) => Float32Array.from({ length: frames }, (_, index) => Math.sin(2 * Math.PI * hz * index / rate));
const equalPower = (tail, head, t) => tail * Math.cos(t * Math.PI / 2) + head * Math.sin(t * Math.PI / 2);
const maxStep = values => { let peak = 0; for (let index = 1; index < values.length; index++) peak = Math.max(peak, Math.abs(values[index] - values[index - 1])); return peak; };

test('with no crossfade the output is exact, repeated copies of the selected region', () => {
    const input = ramp(rate * 4);
    const { channels, frames, periodFrames } = buildLoop([input], rate, { start: 0.5, length: 1.25, crossfade: 0, repeats: 3 });
    assert.equal(periodFrames, Math.round(1.25 * rate));
    assert.equal(frames, periodFrames * 3);
    const startFrame = Math.round(0.5 * rate);
    for (let frame = 0; frame < frames; frame++) {
        assert.equal(channels[0][frame], input[startFrame + frame % periodFrames], `frame ${frame} must copy the region verbatim`);
    }
});

test('a crossfade shortens the emitted period and blends the tail onto the head with equal power', () => {
    const input = ramp(rate * 4);
    const options = { start: 0.5, length: 2, crossfade: 0.25, repeats: 2 };
    const { channels, frames, periodFrames, crossfadeFrames, loopFrames } = buildLoop([input], rate, options);
    const cross = Math.round(0.25 * rate);
    assert.equal(crossfadeFrames, cross);
    assert.equal(loopFrames, Math.round(2 * rate));
    // The whole point of the fix: the period is the region minus the overlap, so the tail is
    // heard once per cycle instead of twice.
    assert.equal(periodFrames, loopFrames - cross);
    assert.equal(frames, periodFrames * 2);
    const startFrame = Math.round(0.5 * rate);
    for (let frame = 0; frame < cross; frame++) {
        const expected = equalPower(input[startFrame + periodFrames + frame], input[startFrame + frame], frame / cross);
        assert.ok(Math.abs(channels[0][frame] - expected) < 1e-6, `crossfaded frame ${frame} must be the equal-power blend`);
    }
    // Past the crossfade the period is the untouched region.
    for (let frame = cross; frame < periodFrames; frame++) {
        assert.equal(channels[0][frame], input[startFrame + frame]);
    }
    // Equal power means the blend never dips: two correlated signals keep a constant sum of squares.
    for (let frame = 0; frame < cross; frame++) {
        const t = frame / cross;
        assert.ok(Math.abs(Math.cos(t * Math.PI / 2) ** 2 + Math.sin(t * Math.PI / 2) ** 2 - 1) < 1e-12);
    }
});

test('the wrap from the last sample back to the first is as smooth as the audio itself', () => {
    // 110 Hz at 8 kHz: every period length used below is a whole number of cycles, so a correct
    // loop wraps onto a continuation of the same waveform rather than onto a step.
    const input = sine(rate * 4, 110);
    for (const crossfade of [0, 0.1, 0.4]) {
        const { channels, frames } = buildLoop([input], rate, { start: 0.5, length: 1, crossfade, repeats: 1 });
        const seam = Math.abs(channels[0][0] - channels[0][frames - 1]);
        const inside = maxStep(channels[0]);
        assert.ok(seam <= inside * 1.5 + 1e-6, `crossfade ${crossfade}: wrap step ${seam} must not exceed the waveform's own ${inside}`);
        assert.ok(seam < 0.2, `crossfade ${crossfade}: wrap step ${seam} must stay far below a click`);
    }
});

test('a loop that starts early in the file may still use a long crossfade', () => {
    // Regression: the crossfade used to be capped by the loop start, because an older design read
    // pre-roll audio. buildLoop never reads before the start frame, so only the loop length caps it.
    // 20 s so every region requested below, including the 12 s one, fits inside the source with
    // room to spare — the point of this test is the crossfade ceiling, not the EOF clamp that
    // buildLoop separately applies when a region does run off the end of the data.
    const input = ramp(rate * 20);
    const near = buildLoop([input], rate, { start: 0.1, length: 4, crossfade: 1.5, repeats: 1 });
    const far = buildLoop([input], rate, { start: 1.5, length: 4, crossfade: 1.5, repeats: 1 });
    assert.equal(near.crossfadeFrames, Math.round(1.5 * rate));
    assert.equal(near.crossfadeFrames, far.crossfadeFrames);
    assert.equal(loopPlan({ start: 0.1, length: 4, crossfade: 1.5 }, rate).crossfadeFrames, Math.round(1.5 * rate));
    // Half the loop length and four seconds are the only ceilings.
    assert.equal(buildLoop([input], rate, { start: 0.1, length: 1, crossfade: 0.9, repeats: 1 }).crossfadeFrames, Math.round(0.5 * rate));
    assert.equal(buildLoop([input], rate, { start: 0.1, length: 12, crossfade: 9, repeats: 1 }).crossfadeFrames, 4 * rate);
});

test('fades shape the whole file and normalize lifts the peak to -1 dBFS', () => {
    const input = Float32Array.from({ length: rate * 3 }, () => 0.25);
    const faded = buildLoop([input], rate, { start: 0.25, length: 1, crossfade: 0, repeats: 2, fadeIn: 0.5, fadeOut: 0.5 });
    assert.equal(faded.channels[0][0], 0);
    assert.ok(Math.abs(faded.channels[0][Math.round(0.25 * rate)] - 0.125) < 1e-6, 'halfway through the fade in is half level');
    assert.ok(Math.abs(faded.channels[0][Math.round(0.5 * rate)] - 0.25) < 1e-6, 'past the fade in the level is untouched');
    assert.ok(faded.channels[0][faded.frames - 1] < 0.01, 'the file ends at the bottom of the fade out');
    // Fades are an envelope over the whole file, not over each repeat.
    assert.ok(Math.abs(faded.channels[0][faded.frames / 2] - 0.25) < 1e-6);

    const hot = Float32Array.from({ length: rate * 3 }, (_, index) => (index % 2 ? 1 : -1));
    const normalized = buildLoop([hot], rate, { start: 0.25, length: 1, crossfade: 0, repeats: 1, normalize: true });
    let peak = 0;
    for (const value of normalized.channels[0]) peak = Math.max(peak, Math.abs(value));
    assert.ok(Math.abs(peak - 0.891) < 1e-4, `normalized peak ${peak} must sit at -1 dBFS`);
    // True normalize: a quiet take is lifted to -1 dBFS too, not just a hot one attenuated down to
    // it. The old `Math.min(1, ...)` gain could only ever turn material down.
    const quiet = buildLoop([input], rate, { start: 0.25, length: 1, crossfade: 0, repeats: 1, normalize: true });
    let quietPeak = 0;
    for (const value of quiet.channels[0]) quietPeak = Math.max(quietPeak, Math.abs(value));
    assert.ok(Math.abs(quietPeak - 0.891) < 1e-4, `quiet input's normalized peak ${quietPeak} must also sit at -1 dBFS`);
    // Silence must stay silent rather than being divided by a zero peak.
    const silent = buildLoop([new Float32Array(rate * 3)], rate, { start: 0.25, length: 1, crossfade: 0.2, repeats: 1, normalize: true });
    assert.ok(silent.channels[0].every(value => value === 0));
});

test('every channel is built independently and stereo is preserved', () => {
    const left = ramp(rate * 3);
    const right = Float32Array.from(left, value => -value);
    const { channels } = buildLoop([left, right], rate, { start: 0.5, length: 1, crossfade: 0.2, repeats: 1 });
    assert.equal(channels.length, 2);
    for (let frame = 0; frame < channels[0].length; frame++) {
        assert.ok(Math.abs(channels[0][frame] + channels[1][frame]) < 1e-6, `channel ${frame} must mirror`);
    }
});

test('rounding start and length independently must not read a sample past the end of the source', () => {
    // Regression: start=0.15 and length=0.85 at 10 Hz round to frame 2 and 9 frames independently
    // (1.5 -> 2, 8.5 -> 9), so the old `startFrame + loopFrames` arithmetic asked for frame 11 of a
    // 10-frame input. buildLoop's `|| 0` read turned that missing sample into an injected zero at
    // the very end of the loop. None of these ten values is ever zero, so a zero anywhere in the
    // output can only be that out-of-range read.
    const sr = 10;
    const input = Float32Array.from({ length: 10 }, (_, index) => index + 1);
    for (const crossfade of [0, 0.3]) {
        const { channels, loopFrames } = buildLoop([input], sr, { start: 0.15, length: 0.85, crossfade, repeats: 1 });
        assert.ok(loopFrames <= 8, `loopFrames ${loopFrames} must not overshoot the 10-frame source from a start frame of 2`);
        assert.ok(channels[0].every(value => value !== 0), `crossfade ${crossfade}: every emitted sample must be real source data, not an out-of-range read`);
    }
});

test('a fill target is rounded to whole periods so the exported file still loops', () => {
    const input = ramp(rate * 6);
    const plan = loopPlan({ start: 0.5, length: 2, crossfade: 0.25 }, rate);
    assert.equal(plan.periodFrames, Math.round(2 * rate) - Math.round(0.25 * rate));
    assert.equal(plan.repeatsFor(10), Math.max(1, Math.round(10 * rate / plan.periodFrames)));
    assert.equal(plan.repeatsFor(0.1), 1, 'a target shorter than one period still emits one whole period');
    const built = buildLoop([input], rate, { start: 0.5, length: 2, crossfade: 0.25, repeats: plan.repeatsFor(10) });
    assert.equal(built.frames % built.periodFrames, 0);
});
