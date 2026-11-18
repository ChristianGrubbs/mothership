# PhotoEdit: deviations from upstream

`photoedit/` is vendored from the PhotoEdit sources (`src/core`, `src/ui` and `src/adapters/mothership.js` of
`photoedit-cleanshot`). Apart from the changes below it is byte-identical to upstream. Each change is marked
`UPSTREAM-DEVIATION (The Mothership)` in the source; carry it forward, or drop it once upstream has the same fix, whenever
`photoedit/` is refreshed. Everything else The Mothership needs is done outside `photoedit/` (`mount.mjs`,
`thumbnail-adapter.mjs`).

Operator-approved on 2026-10-13 as four minimal fixes:

| File | Change | Without it |
|---|---|---|
| `adapters/mothership.js` | 2026-10-13: displays persistent original/working dimensions for bounded server working copies and confirms reduced-base version saves. | Large originals would silently export at reduced dimensions. |
| `ui/dom.js` `popover()` | `close()` cancels the timer that registers the outside-click listener. | A popover closed before the timer fires (a menu opened and closed quickly) leaves a listener behind, and that listener closes the next popover as soon as it is clicked, so its choice is lost. |
| `ui/editor.js` `addImageFile()` | The `finally` block redraws the bars (`refreshChrome()`) after clearing `busy`. | After Add image, paste or drop of an image, Save new version, Save as new asset and Save thumbnail stay disabled (drawn while busy) until some other change redraws them. |
| `ui/editor.js` `openPalette()`, Add to My Colors | Reopens the palette under the Color button that is on screen when the original anchor was replaced. | A typed hex redraws the top bar, so the palette reopened by Add to My Colors is placed at the top-left of the editor with its first row clipped. |
| `ui/editor.js` `onKeyDown()`, Cmd+[ / ] | A shifted bracket key, which reports `{` / `}`, counts as `[` / `]`. | Shift+Cmd+[ (send to back) and Shift+Cmd+] (bring to front) do nothing on a US keyboard; only the context menu reaches those actions. |
| `core/geometry.js` `objectBounds()` | 2026-10-21: calculate curved-arrow bounds from the exact quadratic extrema. | Curves extending beyond their midpoint bounds are clipped out of marquee/resize bounds. |
| `core/geometry.js` stroke helpers, `ui/editor.js` stroke creation | 2026-10-21: retain both endpoints and axis extrema in the 5,000-point budget; compact live raw storage only at 10,000 points. | Prefix truncation loses the stroke tail; per-move resampling jitters the preview and unbounded raw storage keeps growing. |
| `ui/editor.js` Drag me preparation | 2026-10-21: invalidate publication on edits, retain retired URLs for 30 seconds for ongoing drops, cancel idle work and reject stale/post-destroy encodes; retries reuse current preparation. | Dragging shortly after an edit can supply an older image; early revocation can interrupt a drop, repeated retries can postpone preparation, and late encodes can publish an obsolete URL or leak one after teardown. |
| `core/render.js` background analysis | 2026-10-21: share one render-local ImageData readback between edge color and auto-balance without changing their sampling or thresholds. | Auto-balance reads and allocates the entire image twice during the same render. |

Regressions are in `tests/e2e-pw/tests/dam-image-editor.spec.js` (search for "UPSTREAM-DEVIATION").
Geometry regressions are in `packages/Mothership/DAM/tests/js/photoedit-geometry.test.mjs`.

To list the deviations against an upstream checkout:

```bash
diff -ru <upstream>/src/core photoedit/core; diff -ru <upstream>/src/ui photoedit/ui; diff -u <upstream>/src/adapters/mothership.js photoedit/adapters/mothership.js
```
