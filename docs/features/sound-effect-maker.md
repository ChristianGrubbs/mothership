# Sound Effect Maker

A Home tool (`sound-effects`, always available) that makes short game-style sound effects in the browser. It is one
self-contained file, `packages/Mothership/DAM/src/Resources/widgets/sound-effects/index.html`: inline CSS and JavaScript,
the Web Audio API, no audio assets, no dependencies and no build step. The same file works served from Home and opened
directly from disk.

## What it does

- **Effects:** Coin pickup, Whoosh, Alarm and Explosion. Each click makes a fresh variation of that effect.
  **Surprise me** makes a random sound; **Nudge** moves the current one a little and keeps its seed.
- **Simple** mode has eight controls (Pitch, Length, Brightness, Punch, Wobble, Crunch, Echo, Volume), each with a short
  hint. **Advanced** shows every parameter. Both modes edit the same parameters, so switching keeps the sound.
  Simple controls map onto parameters: Brightness is `cutoff`, Punch is `1 - sustainVolume`, Wobble is `tremolo`,
  Crunch is `crush`, Echo is `delay`, and Length scales `attack`, `decay`, `sustain` and `release` together.
- **Render:** one function, `renderSamples(params, seed, sampleRate)`, computes the sound sample by sample into a
  `Float32Array`; noise comes from a seeded generator (mulberry32), so the same parameters, seed and sample rate give
  identical samples. Playback copies them into an `AudioBuffer` and plays it through a single `AudioBufferSourceNode`.
- **Library:** save, rename, duplicate, reorder and delete (with Undo). Kept in the frame's `localStorage`, which inside
  Home is the per-account widget preference store (one value per key, at most 64 KB; the tool warns before the library
  outgrows it). Export and import the whole library as JSON; download one sound as WAV or the whole library as a ZIP of
  WAV files (16-bit PCM mono at the chosen sample rate; ZIP entries are stored, uncompressed, with UTF-8 names).
- **Keyboard**, while focus is in the tool and not in a field or control that uses the key: Space plays, S saves,
  the arrow keys move through saved sounds. F2 renames and Delete deletes in the list.

## Library JSON format (version 1)

Other tools may read this file to replay the same sounds. Exported as `sound-library.json`:

```json
{
  "format": "mothership-sound-library",
  "version": 1,
  "sampleRate": 44100,
  "sounds": [
    {
      "name": "Coin pickup",
      "kind": "coin",
      "seed": 2840215371,
      "params": {
        "wave": "square", "pitch": 0.62, "attack": 0, "decay": 0.08, "sustain": 0.2, "release": 0.3,
        "sustainVolume": 0.45, "slide": 0, "deltaSlide": 0, "jump": 0.2917, "jumpTime": 0.29, "repeat": 0,
        "tremolo": 0, "noise": 0, "crush": 0, "delay": 0, "cutoff": 0.93, "volume": 0.6
      }
    }
  ]
}
```

- Array order is the library order. `seed` is an unsigned 32-bit integer. `kind` is one of `coin`, `whoosh`, `alarm`,
  `explosion`, `surprise`. `sampleRate` records the exporter's setting and is informational.
- `wave` is one of `square`, `sawtooth`, `triangle`, `sine`, `noise`. Every other parameter is a number rounded to four
  decimals: `slide`, `deltaSlide` and `jump` run from -1 to 1, the rest from 0 to 1. Import clamps out-of-range values,
  fills missing ones with defaults and ignores unknown keys. A bare array of sounds is also accepted.
- What each value means (the render function is the authority):

| Parameter | Mapping |
| --- | --- |
| `pitch` | start frequency `40 × 2^(8·pitch)` Hz |
| `attack`, `decay`, `sustain`, `release` | seconds `v² × 1.0`, `v² × 1.5`, `v² × 2.0`, `v² × 2.5` |
| `sustainVolume` | level the sound falls to after the first hit |
| `slide`, `deltaSlide` | `v·|v| × 8` octaves/s and `v·|v| × 16` octaves/s² |
| `jump`, `jumpTime` | step of `24·jump` semitones at `0.01 + jumpTime²` seconds |
| `repeat` | 0 is off; otherwise pitch movement restarts every `0.03 + 0.6·(1 − repeat)²` seconds |
| `tremolo` | depth `0.9·v` at `3 + 15·v` Hz |
| `noise` | share of white noise mixed into the tone |
| `crush` | `round(16 − 13·v)` bits, holding each sample for `v²/2000` seconds |
| `delay` | echo every `0.06 + 0.24·v` s, feedback `0.25 + 0.4·v`, wet `0.6·v` |
| `cutoff` | two-pole low-pass at `120 × 2^(7.5·cutoff)` Hz; open at or above 45% of the sample rate |
| `volume` | output gain `0.8·v` |

The page also exposes these pure functions as `window.MothershipSoundEffects` (`renderSamples`, `encodeWav`, `makeZip`,
`parseLibrary`, `libraryJson` and friends), which is how its browser test checks determinism and the file formats.

## Styles

The page carries fallback colour tokens equal to Home's current light and dark tokens. Home tells the frame its theme
(`data-theme` on `<html>`); opened from disk the system theme applies. A host may define `--host-paper`, `--host-wash`,
`--host-ink`, `--host-soft`, `--host-line`, `--host-line-strong`, `--host-accent`, `--host-accent-strong`,
`--host-on-accent` and `--host-tint` on the document to override the fallbacks; Home does not inject them today.
