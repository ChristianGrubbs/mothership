# ADR 0005: Loop Maker previews with a native looping source and exports WAV by default

Date: 2026-09-16 · Status: accepted

## Context
The Loop Maker gadget (`packages/Mothership/DAM/src/Resources/widgets/loop-maker/`) produced loops that did
not loop. Four separate mechanisms contributed, and they were only visible together:

- **Playback was a main-thread restart.** `playSegment()` created an `AudioBufferSourceNode`, played the
  region once, and created the next pass from inside `source.onended`. The wrap therefore cost an event
  loop turn, which is audible as a gap on every single repeat. `AudioBufferSourceNode.loop` was never set.
- **The preview played the raw file.** `source.buffer = state.buffer`, so the crossfade, the normalize
  and the fades — the whole reason the tool exists — were only ever heard after downloading. The
  Crossfade slider changed nothing audible.
- **Any control touched mid-play stopped the audio.** The `onended` handler repeated only when
  `duration === state.length`, an exact float compare; the length slider, an edge drag and the re-clamp
  inside `syncControls()` all mutate `state.length`, so the else branch stopped playback.
- **The export crossfade double-counted the tail.** Each period copied the full
  `[startFrame, startFrame + loopFrames)` — tail included — and then the next period blended that same
  tail into its head without shortening the period. The last `crossfade` seconds played twice per cycle,
  and the file's own first and last samples did not line up, so external players could not loop it either.

Two smaller faults rode along: the crossfade was capped by `state.start` (a leftover from an older design
that blended in pre-roll audio, which left a loop starting 0.1 s into a file with at most a 0.1 s
crossfade), and "Hear the seam" played three seconds of contiguous *original* audio straddling the loop
end without ever wrapping to the start — it auditioned the one thing that is not the seam.

## Decision
1. **One builder, shared by the preview and the export.** `loop-core.js` is a DOM-free, Web-Audio-free
   UMD module exporting `buildLoop(channelData, sampleRate, options)` and `loopPlan(options, sampleRate)`.
   The widget loads it as a plain `<script>` (`window.LoopCore`); `packages/Mothership/DAM/tests/js/loop-maker-core.test.mjs`
   `require()`s it. It stays UMD until the widgets gain a build step.
2. **The emitted period is `loopFrames - crossfadeFrames`.** The region's tail is folded onto its head
   with an equal-power (cos/sin) pair instead of being emitted a second time, so the last emitted sample
   is the source frame immediately before the one the period starts from and the wrap is a continuation
   of the source rather than a jump. A repeat-to-fill export is rounded to whole periods for the same
   reason. The crossfade is capped by half the loop length and by four seconds — never by the loop start.
3. **Playback is a native looping source.** Play builds the loop buffer and plays it with `loop = true`,
   `loopStart = 0`, `loopEnd = buffer.duration`, so the wrap happens on the audio thread. Control changes
   rebuild the preview on a 120 ms debounce and swap sources under a 10 ms gain ramp; playback continues
   across every control change until Stop. Position for the readout comes from `audio.currentTime` modulo
   the loop length. Retiring a source detaches its `onended` first, which is what stops a replaced source
   from tearing down its own replacement.
4. **WAV is the default export format.** LAME writes encoder delay and padding and the widget emits no
   gapless tag, so a looped MP3 opens a 25–50 ms hole at the wrap in most players. MP3 stays available at
   all three bitrates with a one-line note in the UI saying why it is second choice.

Fades are the one thing the preview does not apply: they envelope the saved file once, so applying them
per pass would pump the preview on every repeat. They remain in the export.

## Consequences
- A "one pass" export is now `length - crossfade` long rather than `length`. The export summary reports
  the frames actually written, so the number the user sees is the number they get.
- The preview costs a buffer rebuild per control change (debounced). A 30 s stereo loop at 48 kHz is a
  few tens of milliseconds of main-thread work; if that ever becomes visible, the builder is pure and
  moves to a worker without touching its callers.
- Existing saved preferences keep whatever format the user last chose; only the default changed.
- `loop-core.js` is the first widget module with Node-level unit tests. The same UMD shape is the cheap
  route for the other gadgets' pure arithmetic.
