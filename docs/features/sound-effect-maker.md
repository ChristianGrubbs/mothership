# Sound Effect Maker

A tool (`sound-effects`, always available) that makes short game-style sound effects in the browser. Its folder,
`packages/Mothership/DAM/src/Resources/widgets/sound-effects/`, holds `index.html`, `app.js` and `style.css`, drawn with
the shared tool kit (`widgets/_kit/tool-kit.css`): the Web Audio API, no audio assets, no dependencies and no build
step. The same files work inside the tool page (`/admin/tools/sound-effects`) and opened directly from disk.

## Layout

- **Sound:** the sound's name and whether it is saved, the wave (press it to replay), its length and peak, Play and
  Save sound.
- **Effects:** Start from an effect, Surprise me and Nudge, then Playback (sample rate, master volume, play after each
  edit) and the shortcuts.
- **Shape:** Shape it, with Simple and Advanced.
- From 720 px wide, Sound sits over Effects in one scroller with Shape beside them (400 px, 360 px under 1100 px).
  Narrower, Sound stays on top and a segment under it picks Effects or Shape. On a folding phone the page that frames
  the tool says where the fold is: on a book fold Shape goes right of it; on a tabletop fold the sound stays above it,
  and the segment with Effects or Shape goes below.
- Inside the tool page the page's bar names the sound and lists the saved sounds, and holds Export and Save to Library.
  Opened from disk, the tool shows its own Saved sounds… and Export… buttons instead.

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
- **Saved sounds:** Save sound (or S) keeps the sound. The Saved sounds sheet lists them, with Rename, Duplicate, Move
  up, Move down and Delete (with Undo), and exports or imports the whole library as JSON. Kept in the frame's
  `localStorage`, which inside the tool page is the per-account widget preference store (one value per key, at most
  64 KB; a save that would outgrow it is refused, and the tool says so).
- **Export:** a sheet that downloads this sound as a WAV or every saved sound as a ZIP of WAV files (16-bit PCM mono at
  the chosen sample rate; ZIP entries are stored, uncompressed, with UTF-8 names). Its button says which.
- **Save to Library**, inside the tool page only: the sound as a WAV and its settings as a one-sound library JSON, which
  Import opens again, into a folder the account may upload to. Both files are made from the sound as it was when Save
  was pressed, and the folder is remembered.
- **Keyboard**, while focus is in the tool and not in a field or control that uses the key: Space plays, S saves,
  the arrow keys move through saved sounds. With a sheet open only the saved-sounds list takes keys: the arrows, Home
  and End move through it, F2 renames and Delete deletes.

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

The tool kit's colour tokens equal the shell's in both themes, and a test holds them equal. The page that
frames the tool tells it the theme (`data-theme` on `<html>`) and, on a folding phone, where the fold crosses it
(`data-fold`, `--fold-start` and `--fold-end`). Opened from disk the tool is light.
