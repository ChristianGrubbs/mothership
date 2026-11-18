# Loop Maker

Loop Maker turns a section of an audio track into a clean, repeatable bed for looping video. Processing stays in your browser; The Mothership does not upload the source track.

## Before you begin

Add **Loop Maker** from **Home → Customize → Add widget**, then select **Open**. Have an MP3, WAV, M4A, or OGG file under 250 MB and at least half a second long, or use the demo track.

## Steps

1. Drop an MP3, WAV, M4A, or OGG file into the workspace, or use the demo track.
2. Drag the highlighted waveform region to move it. Drag either edge to change its length.
3. Select **Find best seam**, then use **Hear the seam** to audition the join.
4. Adjust the crossfade until the repeat sounds clean.
5. Choose one loop pass or repeat the loop to a target duration, pick WAV or MP3, and export.

Arrow keys nudge the start by 0.01 seconds; hold Shift for 0.1-second steps. Space starts or stops loop playback when a form control is not focused.

## Expected result

You download one loop pass, or the loop repeated to your target duration. WAV preserves lossless PCM audio. MP3 supports 128, 192, and 320 kbps. **Normalize to −1 dB** raises quiet output without clipping.

MP3 encoding uses the open-source [LAME encoder](https://lame.sourceforge.net/) under the LGPL.

Export format, duration mode, target duration, and normalization preferences are remembered in the current browser. Closing the workspace discards the loaded audio and current loop region.

## Troubleshooting

- **The file is not accepted.** The source file must be under 250 MB and at least half a second long.
- **The target duration will not go higher.** Target duration is limited to 30 minutes to protect the browser from unbounded memory use.
- **The seam still clicks or jumps.** Smart seam scoring is a local waveform comparison. Adjust the region or crossfade and always audition the result before publishing.
