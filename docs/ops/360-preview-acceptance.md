# Mothership 360 Preview Acceptance

This record captures the post-merge deployment and real-runtime acceptance on the deployed runtime
at the time.

## 2026-09-04 real-OSV acceptance

- Status: **PASS** on the deployed runtime at the time
- Asset id: 303 (DJI Avata 360, proto `02.00.11`)
- Original SHA-256: `a64a7cd314044d2400d7e1c5505837f17956a13dd412627f6e537309ed8ed76c`
- Existing proxy SHA-256: `5b2e8fab546eec6d5db9f684d0ab94ed5813379967e3ce57738bf4fb4edc10bb`
- Generated proxy SHA-256: `16a39d15c048c82b282bf0b4ff969b90e91e057d60b3780349d6e79d666e8490`
- Worker version: `panoforge-5bcfbe8-avata360.1`
- FFmpeg version: 7.1.5
- Output: 42,610,803 bytes; 3840×1920; H.264; yuv420p; spherical metadata present; faststart enabled
- Seam continuity, horizon/orientation, and field of view: PASS by operator visual acceptance; no defect reported
- Duration and audio: 15.081733 seconds; no audio stream
- Browser viewer: PASS; generated media loaded and rendered, drag direction was confirmed correct, and drag/wheel/seek/fullscreen controls were present. The operator also confirmed playback for an Avata source the previous platform could not preview.
- Byte range: PASS through the production media-serving path
- Queue restart during a separate attempt: PASS. Rendition 23 was running when `queue:restart` was signalled, published successfully after 56 seconds, and the dedicated panorama worker restarted cleanly.
- Failed rebuild preserved prior good proxy: PASS. Rendition 20 failed with `calibration_missing` without replacing the existing playable proxy.
- First-load observation: the successful initial conversion took 81.683 seconds, so the first viewer load was noticeably delayed; subsequent rebuilds completed in 54–56 seconds.
- Processing flag disabled after test preserved playback: PASS. After the final deploy, runtime state reported `processing_enabled=false` while asset 303 still resolved to its completed, non-stale generated 4K rendition.
- FFmpeg 8+ required: **No**. The accepted proxy was produced with FFmpeg 7.1.5.
