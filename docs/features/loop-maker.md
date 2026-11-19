# Loop Maker

A tool (`loop-maker`) that turns a section of an audio track into a seamless loop, in the browser. Its folder,
`packages/Mothership/DAM/src/Resources/widgets/loop-maker/`, holds `index.html`, `app.js` and `style.css`, drawn with the
shared tool kit (`widgets/_kit/tool-kit.css`), plus `loop-core.js` (the loop build) and
`lame.min.js` (MP3 encoding, LGPL). There is no build step. The same files work inside the tool page
(`/admin/tools/loop-maker`), in Home's panel and opened directly from disk. The source track never leaves the browser.

## Layout

- **No track:** one pane, the drop zone, with Choose audio file and Load a demo track.
- **Stage:** the track's name and facts, Replace track, the wave with the loop region, the ruler, then Play loop, Hear
  the seam, Find best seam and the two figures (Playing, Seam match).
- **Controls:** Loop region (Smart seam, Start, Loop length, Crossfade) and Fades and loudness (Fade in, Fade out,
  Normalize to −1 dB).
- From 960 px of the tool's own width the stage sits beside the controls (360 px), each scrolling by itself, and the
  wave takes the stage's spare height. Narrower, they scroll as one. A stage under 520 px puts Play loop on a row of its
  own and drops the help line. On a folding phone the page that frames the tool says where the fold is: on a book fold
  the controls go right of it; on a tabletop fold the stage stays above it and the controls go below.
- Inside the tool page the page's bar names the tool and, once a track is open, holds Export and Save to Library.
  Where no bar is drawn, the tool shows its own Export… and Save to Library… buttons above the track.

## What it does

- **Loop:** `loopPlan` and `buildLoop` in `loop-core.js` cut the region and blend its tail into its head over the
  crossfade, so one pass is the region less the crossfade. Play loops that pass on a native looping source; the clock
  at rest and the Export summary show the same pass length.
- **Export:** a sheet that downloads one pass, or whole passes repeated to fill a target duration (up to 30 minutes),
  as WAV (16-bit PCM) or MP3 (128, 192 or 320 kbps). Fades shape the file once; Normalize applies to Play and Export.
  It shows what it writes before the download.
- **Save to Library**, where the frame offers it: the loop as set in Export, into a folder the account may upload to.
  The folder is remembered. An account with no such folder is told so, with nothing to save. A refused save says why
  in the sheet, with the focus back on Save; one refused after the sheet was closed is said by the bar, and by the
  sheet when it next opens. Sound Effect Maker and Palette Studio do the same.
- **Keyboard**, while focus is not in a field or a sheet: Space plays or stops, the arrow keys nudge the start by
  0.01 s (0.1 s with Shift).
- Export format, duration mode, target duration, Normalize and the folder are kept in the frame's `localStorage`.

## Styles

The track takes no accent. The wave is drawn in the quietest ink, the region in full ink on a light band, each edge a
2 px line with grab tabs at its top and bottom. The tool kit's colour tokens equal the shell's in both themes. The page
that frames the tool tells it the theme (`data-theme` on `<html>`) and, on a folding phone, where the fold crosses it
(`data-fold`, `--fold-start` and `--fold-end`); the wave redraws on either change. Opened from disk the tool is light.
