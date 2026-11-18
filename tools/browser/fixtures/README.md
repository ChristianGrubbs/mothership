# Video frame fixtures

Three synthetic H.264 videos (High profile, yuv420p, `+faststart`, no audio) with identical timing: one second of one solid color followed by one second of another, at four frames per second (8 frames, 2.000 s). They test native-resolution PNG capture independently of player display size and playback time; solid colors keep the files tiny and make the captured frame unambiguous from a single sampled pixel.

| File | Frame size | Colors | Served by `tests/video-frame.test.cjs` as |
| --- | --- | --- | --- |
| `video-frame-colors.mp4` | 1920×1080 | red → blue | `/fixture.mp4` — the ordinary (non-proxy) stream |
| `video-frame-colors-4k.mp4` | 3840×2160 | red → blue | `/master.mp4` — the master a frame must be cut from while the proxy plays (ADR 0003) |
| `video-frame-proxy-1080p.mp4` | 1920×1080 | green → yellow | `/proxy.mp4` — the 1080p preview proxy in proxy tests |
| `video-frame-alpha-vp9-1440p.webm`, `video-frame-alpha-vp8-1440p.webm` | 2560×1440 | fully transparent 0–1 s → opaque red 1–2 s (alpha-bearing, `alpha_mode=1`) | `/alpha-vp9.webm`, `/alpha-vp8.webm` — masters whose click-time frame is legitimately transparent |
| `video-frame-alpha-vp9-1fps.webm` | 2560×1440 at 1 fps | one transparent frame (0 s), one opaque red frame (1 s) | `/alpha-1fps.webm` — the current frame at 0.5 s is stamped 0.0, so frame-time evidence cannot assume a frame length |

The proxy deliberately shares the master's timing but not its colors. A frame drawn from the proxy — even one upscaled into a 3840×2160 canvas and labeled as master resolution — comes back green or yellow, so the tests can tell master pixels from proxy pixels, not just master dimensions from proxy dimensions. A proxy-fallback capture is expected to be green at 0.5 s.

In that harness `/stalled.mp4` never gets a response (a master whose load hangs until the capture resolver's 8 s timeout), and any other `.mp4` path is a 404, which simulates a master the browser cannot read.

Generated with FFmpeg color sources (FFmpeg 9.0.1, libx264 core 165):

```sh
ffmpeg -y -f lavfi -i 'color=c=red:s=1920x1080:r=4:d=1' -f lavfi -i 'color=c=blue:s=1920x1080:r=4:d=1' \
  -filter_complex '[0:v][1:v]concat=n=2:v=1:a=0,format=yuv420p[v]' -map '[v]' \
  -c:v libx264 -an -movflags +faststart video-frame-colors.mp4

ffmpeg -y -f lavfi -i 'color=c=red:s=3840x2160:r=4:d=1' -f lavfi -i 'color=c=blue:s=3840x2160:r=4:d=1' \
  -filter_complex '[0:v][1:v]concat=n=2:v=1:a=0,format=yuv420p[v]' -map '[v]' \
  -c:v libx264 -crf 40 -an -movflags +faststart video-frame-colors-4k.mp4

ffmpeg -y -f lavfi -i 'color=c=0x00FF00:s=1920x1080:r=4:d=1' -f lavfi -i 'color=c=yellow:s=1920x1080:r=4:d=1' \
  -filter_complex '[0:v][1:v]concat=n=2:v=1:a=0,format=yuv420p[v]' -map '[v]' \
  -c:v libx264 -an -movflags +faststart video-frame-proxy-1080p.mp4
```

With that build the first command reproduces the committed 1080p file byte for byte. The second differs only in frame size and a high CRF, which keeps the 4K file at about 6 KB; solid colors survive it, and the sampled pixels stay within the tests' red/blue thresholds. The third uses pure green (`0x00FF00`; FFmpeg's named `green` is the darker `#008000`) and yellow at the 1080p settings. Keep the `color=` arguments quoted: in zsh an unquoted `:r` is a path modifier.

Alpha masters: `ffmpeg -f lavfi -i "color=c=black@0.0:s=2560x1440:r=10:d=1,format=yuva420p[a];color=c=red:s=2560x1440:r=10:d=1,format=yuva420p[b];[a][b]concat=n=2:v=1" -c:v libvpx-vp9 -pix_fmt yuva420p -auto-alt-ref 0 -b:v 0 -crf 40 video-frame-alpha-vp9-1440p.webm` (`-c:v libvpx` for VP8). Chrome 151 decodes both with alpha.
