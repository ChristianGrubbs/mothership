# ADR 0006: 3D thumbnails are captured in the browser, not rendered on the server

Date: 2026-09-16 · Status: accepted

## Context
GLB and 3MF uploads reach the Library with no thumbnail at all. `ProcessAssetUpload::dispatchThumbnailJob()`
covers video, panoramas, PDF and DNG; `AssetHelper::getFileTypeUsingExtension()` calls a model
`unspecified`; the grid card falls through to the grey placeholder. A folder of 551 print files looks
like a folder of 551 identical grey squares, and the only way to tell two of them apart is to open
each one.

Nothing in the worker image can fix that. `dockerfiles/q.Dockerfile` is `php:8.4-cli` plus ffmpeg,
poppler, libvips and GD: no Node, no GPU, no GL, not even a software rasteriser. Rendering a glTF
scene server-side would mean a second container image carrying a headless renderer (three.js under
Node with `headless-gl` or SwiftShader, or Blender), a second queue, a second deployment surface, and
a second place where Draco and KTX2 decoding can go wrong — for an image that is 300 pixels wide in
the only place anyone sees it.

Meanwhile the browser already renders these files correctly. `Resources/assets/js/model/viewer.mjs`
loads GLB with Draco, KTX2 and Meshopt, parses Bambu 3MF in a worker, applies the same RoomEnvironment
lighting the preview shows, and honours a saved primary view. Every model in the library has already
been rendered correctly at least once — just never saved.

## Decision
The client that renders a model captures its thumbnail and posts it.

- `POST {admin}/dam/assets/{id}/model-thumbnail` accepts a PNG or JPEG under 2 MB and 1024×1024,
  re-encodes it through GD to a 512px JPEG at quality 85, and stores it at
  `thumbnails/models/{assetId}-{first12ofSha}.jpg` under the existing
  `meta_data['thumbnail_path']` convention, using `GenerateVideoThumbnail`'s locked-merge idiom.
- Every capture is bound to `checksum`, the sha256 of the asset's `original` role file. A capture of
  a superseded file is rejected 409 rather than stored against the wrong bytes.
- A user-set `custom_thumbnail` always wins (`200 {skipped:'custom'}`), and an existing generated
  thumbnail is not re-made without `replace=1` (`200 {skipped:'exists'}`), so the endpoint is
  idempotent and a backfill can be re-run at will.
- `GET {admin}/dam/model-thumbnails/missing` enumerates the models a scope still needs, cursor-paged,
  scoped to the directories the role is directly granted.
- `Resources/assets/js/model/thumbnail-capture.mjs` mounts the *same* `ModelViewer` into a hidden
  512px host, reads one frame out of the drawing buffer with `readPixels` immediately after
  `render()`, and disposes the viewer. `ModelThumbnailQueue` runs that at concurrency 2 with a
  per-item timeout, one retry for transport failures only, and a cancellable progress callback.
- Three triggers, no queue worker: after an upload batch, on the first preview of a model without a
  thumbnail (captured from the live renderer — never a second download), and a
  "Generate missing 3D thumbnails" backfill in the Library context menu.

## Consequences
- Thumbnails only ever appear for models a person or an upload has passed through a browser. A model
  imported by a script, or uploaded by someone who then closes the tab before the batch settles,
  stays grey until a backfill runs. This is the price of having no renderer, and the backfill action
  is the deliberate answer to it rather than an afterthought.
- The API is a second-class citizen here: an asset created through `AdminApi` gets no thumbnail,
  because no browser was involved. Any future headless-import path has to trigger the backfill or
  live without model thumbnails.
- Capture quality depends on the client's GPU and on `MAX_BYTES`/3M-triangle limits the viewer
  already enforces. A model too big to preview is also too big to thumbnail; it reports `skipped`
  rather than failing.
- `preserveDrawingBuffer` is deliberately **not** enabled. Turning it on would tax every interactive
  preview for the benefit of an occasional capture; reading the buffer in the same turn as the render
  costs nothing when no capture is running.
- A multi-plate 3MF is captured from its first plate, never the viewer's own "All plates" default.
  Bambu/Prusa multi-plate files keep every plate's build items at its own offset in one shared
  coordinate space so plates never overlap in the file, even though each prints separately; framing
  all of them for one still shrinks the actual model to a stray handful of pixels. `captureFromViewer`
  selects plate one, fits, renders, then restores whatever plate was selected before, exactly (see
  below) — a live preview is never left showing a different plate than the one the user had open.
- A live-view capture's cleanup restores the camera's exact prior position, near/far and the orbit
  controls' target/min/max distance, not a re-fit — a portrait preview (whose real-pane fit differs
  from the capture's square fit) or a camera a user had already orbited/zoomed away from the
  automatic fit is returned to precisely what it was, synchronously, before the async JPEG encode can
  hand even one frame in the temporary square/single-plate state to the compositor. Only
  `camera.aspect` is left to `viewer.resize()`'s live measurement of the pane, unchanged from before.
- Every upload's ids are folded into one deduplicated pending set rather than being dropped when a
  capture run is already active, and drained the moment that run finishes; a set over 200 ids is
  partitioned into requests the missing endpoint's `max:200` validation actually accepts. A capture's
  own thumbnail-upload request is bound end to end (connect, response headers and body together) by
  a single `AbortController`/timer, so a stalled request can no longer park one of the queue's two
  workers forever, something `cancel()` alone could not free. Only a `SyntaxError` from the response
  body — a reply that arrived and parsed badly — degrades to an empty body; every other rejection (an
  abort, a network failure) is a real failure and propagates, never silently becomes a "successful"
  `done` with no thumbnail URL.
- The bundle grows by one lazily-imported 7.7 kB chunk. `thumbnail-capture.mjs` imports `viewer.mjs`
  dynamically, so three.js stays out of any page that never captures — and the queue stays loadable
  in a plain Node test process, which is how its policy is tested.
- `FileController::thumbnail()` gains a model branch with no lazy-generation fallback: unlike video
  and PDF there is no job to dispatch, so either the stored capture is on disk or the card keeps its
  placeholder.
- The explorer card payload now carries `checksum` and `has_thumbnail`, resolved with one correlated
  subquery per page rather than a query per card.

## Alternatives considered
- **A headless renderer container.** A Node + three.js (or Blender) sidecar consuming a `model-thumbnail`
  queue. Rejected for now: it doubles the deployment surface and adds a GPU/SwiftShader dependency to
  a homelab host, to reproduce rendering the browser already does correctly. It stays open as the
  answer if headless imports ever become a real source of models — this decision's endpoint and
  storage convention would be reused unchanged, with the container becoming a fourth trigger.
- **A generic "first frame" placeholder per format.** Cheap and useless: every GLB would look alike,
  which is the problem being solved.
- **Rendering on the client but storing nothing**, re-capturing on every grid paint. Rejected: a grid
  of 50 models would download and decode 50 models to draw 50 cards.

## Amendment 2026-09-21 — triggers are Library-mode-independent
The after-upload trigger and the backfill runner originally lived only in the explorer tab
(`components/explorer/tab.blade.php`), while production runs the classic datagrid Library
(`DAM_EXPLORER_ENABLED` unset), so uploads there never produced a thumbnail until a preview was opened.
The runner is now one page-level component, `components/model-thumbnails/runner.blade.php`
(`v-dam-model-thumbnail-runner`), mounted once by `asset/index.blade.php` in both modes. It owns the
`dam:uploads-refresh` hook, the dedup pending set, the 200-id partitioning and the progress toast;
backfills arrive as `dam:generate-model-thumbnails { directoryId }` from the explorer context menu
(via the tab) or the classic toolbar's "Generate missing 3D thumbnails". It emits
`dam:model-thumbnails-finished` per run, which the classic datagrid uses to reload. Being page-level
also means an explorer with several tabs runs one queue, not one per tab.

## Amendment 2026-10-09 — a stacked file is captured per blob, never against the stack
A stack's opened fan and the preview's file strip draw every stacked file as a small picture, and a GLB, 3MF or
STL had only a cube badge. The capture above is per *asset* and bound to the asset's `original`, and the asset's
thumbnail is the stack's cover, so a stacked file opened in the viewer must not use it: the preview passes the
viewer no asset id for a stacked file precisely so it cannot overwrite the cover.

- A stacked file's capture is a content-keyed derivative, `DerivativeImages::modelCapture($sha)`:
  `derivatives/<sha[0:2]>/<sha>/model-capture.jpg` (ADR 0009). The same bytes attached to a second stack share it,
  it is reclaimed with the blob, and no asset row is written.
- `GET|POST {admin}/dam/assets/files/{assetFile}/model-thumbnail` (`admin.dam.assets.file_model_thumbnail.*`)
  mirror the asset routes: the file's owning asset is resolved server-side and must be visible (a hidden one
  answers like a missing file), writing needs `dam.asset.edit` on it, the image rules and the 512px JPEG
  re-encode are `ModelThumbnailService`'s, and the capture is bound to the file's `sha256` (409 otherwise).
  `skipped: 'exists'` makes it idempotent; `replace=1` re-makes it. There is no `custom` case: a stacked file has
  no custom thumbnail.
- The picture is served by the existing `?thumb=1` branch of `admin.dam.file.asset-file`
  (`StackFileThumbnail::KIND_MODEL`), so it inherits that route's revalidated-bytes cache policy and access
  check. The files payload names `thumbnail_url` for such a file only once the capture exists, so no tile asks
  for a picture that would answer 404.
- A capture belongs to the blob, so everyone who holds those exact bytes shares it, and the right to write it is
  `dam.asset.edit` on any asset that holds them. That is the same trust ADR 0006 already places in an editor's
  upload, widened from one asset to one blob.
- The viewer's `thumbnailTarget()` (`model/load-policy.mjs`) picks the record: a file id wins over an asset id.
  A finished capture emits `dam:file-thumbnail-updated`, which the stack's fan and the strip apply in place.
- Only the formats the viewer renders are captured (`ModelThumbnailService::EXTENSIONS`). glTF, OBJ, FBX and
  USDZ have no renderer, so they keep their type tile.
- The trigger is the same as for an asset: opening the file in the viewer, by someone who can edit the owning
  asset, on a device without a coarse pointer. There is no upload-time or backfill trigger for stacked files, so
  a stack's 3D file stays a badge until someone opens it once.

