# Loop Maker

Loop Maker turns a section of an audio track into a clean, repeatable bed for looping video. Processing stays in your browser; The Mothership does not upload the source track, only a finished loop you choose to save to the Library.

## Before you begin

Open **Loop Maker** from the **Tools** page. Have an MP3, WAV, M4A, or OGG file under 250 MB and at least half a second long, or use the demo track.

## Steps

1. Drop an MP3, WAV, M4A, or OGG file onto the drop zone, select **Choose audio file**, or select **Load a demo track**.
2. Drag the highlighted waveform region to move it. Drag either edge, by its line or the tabs at its top and bottom, to change its length. On a touch screen drag sideways: a swipe up or down the waveform scrolls the page. **Replace track** opens another file.
3. Select **Find best seam**, then use **Hear the seam** to audition the join. **Seam match** scores the join. Turning **Smart seam** on runs the same search.
4. Adjust **Crossfade** until the repeat sounds clean. Under **Fades and loudness**, set a fade in or out, and turn on **Normalize to −1 dB** to raise a quiet loop without clipping. **Play loop** plays it with these settings.
5. Select **Export** in the bar at the top. Choose one pass of the loop or **Repeat to fill a duration**, pick WAV or MP3, and select **Download**.
6. To keep the loop in the Library instead, select **Save to Library** in the bar, choose a folder, and select **Save**. The file is the loop as set in Export.

Arrow keys nudge the start by 0.01 seconds; hold Shift for 0.1-second steps. Space starts or stops loop playback when a form control is not focused.

## Expected result

You download one loop pass, or the loop repeated to your target duration, or save it to a Library folder. WAV preserves lossless PCM audio. MP3 supports 128, 192, and 320 kbps. The Export sheet shows what it writes before you download it.

MP3 encoding uses the open-source [LAME encoder](https://lame.sourceforge.net/) under the LGPL.

Export format, duration mode, target duration, normalization, and the Library folder are remembered in the current browser. Closing the tool discards the loaded audio and current loop region.

On a wide window the track and its waveform sit beside the loop settings; on a narrow one the settings follow under the track. On a folding phone the track stays on one side of the fold and the settings on the other.

## Troubleshooting

- **The file is not accepted.** The source file must be under 250 MB and at least half a second long. A refused file leaves the open track as it was.
- **Download or Save is unavailable.** The target duration in Export must be a time such as `2:30` or `150`, up to 30 minutes. The limit protects the browser from unbounded memory use.
- **Save to Library has no folder to choose.** Your account has no folder it may upload to. Ask an administrator for one.
- **The Library refused the file.** The sheet says why, and the loop is not saved. If you closed the sheet first, the bar says it was not saved, and the sheet says why when you open it again.
- **The seam still clicks or jumps.** Smart seam scoring is a local waveform comparison. Adjust the region or crossfade and always audition the result before publishing.
