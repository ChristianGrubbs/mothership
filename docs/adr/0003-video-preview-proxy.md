# ADR 0003: Video playback uses a preview proxy; frame capture uses the master

Date: 2026-09-10 · Status: accepted

## Context
`Asset::playbackFile()` prefers a browser-playable original over every proxy, so previewing a video
streams its full-resolution master — a 4K or 8K file delivered into a preview pane under 1000px wide.
Delivery itself is not the bottleneck: authorized files already reach the browser through nginx
`X-Accel-Redirect` with sendfile and native byte ranges, so range seeking works. The cost is raw
bitrate.

The complication is that "Copy frame" and "Download frame" draw from the playing `<video>` element via
canvas. Simply switching playback to a lower-resolution proxy would silently downgrade those features
from master resolution to proxy resolution — a quiet loss of a capability people rely on.

An adaptive HLS ladder was considered and deferred. Its remaining advantage over a single proxy is
adaptivity for viewers on poor connections, while its cost here is a segment fan-out against a model
that stores one content-addressed blob per rendition.

## Decision
Generate one `preview-h264-1080p` rendition per oversized video — H.264, capped at 1920px wide, capped
bitrate, `+faststart` — published through the existing `RenditionPublisher` as a content-addressed
`FileObject` and an `AssetFile` with role `proxy`. Videos already 1080p or smaller get no proxy.

Playback prefers that proxy over the original. Frame capture does not: while a proxy is playing,
captures are drawn from a hidden `<video>` pointed at the master, seeked to the same timestamp, at the
master's own dimensions. A quality menu lets a viewer choose the master explicitly.

Panorama assets are excluded; their existing playback precedence is unchanged.

## Consequences
- The two playback concerns are now separate: what streams is a delivery decision, what a frame is cut
  from is a fidelity decision. Future delivery changes (an HLS ladder, a different codec) must not
  re-couple them.
- `playbackSource()` grows additively (`original_url`, `is_proxy`); existing consumers are unmodified.
- Thumbnails source from the proxy once one exists — faster, and invisible at 300px.
- Storage grows by roughly 5–10% of each 4K master.
- The queue worker must consume a new `video-preview` queue.
- Where the master is a codec the browser cannot decode at all (ProRes, some HEVC), no full-resolution
  capture is possible. That limit predates this decision; capture falls back to the playing source and
  says so rather than misreporting resolution.
- An HLS ladder stays open as Phase 2 and reuses this phase's recipe framework, quality menu, and
  capture-from-master path.
