# SphereSeam (native The Mothership module)

SphereSeam repairs the wrap seam of exact-2:1 equirectangular panoramas. It is a native
Mothership/The Mothership module, packaged like the DAM package, and an alternate front end on the
same backend StylePress uses: the shared Higgsfield work subscription driven through the
official CLI (`Mothership\DAM\Services\SharedHiggsfield`). It holds no credential of its own.

Imported 2026-09-10 from an earlier sandbox implementation. That implementation's own Node
worker and API-key gateway were dropped in favour of the subscription
driver, which already exposes every parameter the design needs (`gpt_image_2_5`,
`aspect_ratio auto`, `quality`, `variant`, `resolution`, `image_references`). The dated
design-review records and the paid proof runs are not kept in this repository.

## Architecture

- Mothership owns authentication, the `sphereseam` ACL, history, source/result storage,
  queueing, export and DAM import.
- The browser performs all panorama geometry (`Resources/assets/sphereseam/sphereseam.js`):
  non-destructive exact-2:1 normalization (squeeze by default, pad optional), a rolled seam
  crop at a supported ratio, band blanking with soft shoulders, locked-band reinsertion along
  a minimum-error cut, wrap previews and PNG/WebP scaling.
- Higgsfield receives only the blanked seam bridge as a reference image, never a 2:1
  panorama request. Neither GPT Image model exposes a mask on Higgsfield, so the repair band
  is a prompt instruction and the browser enforces the lock afterwards.
- `SphereSeamHiggsfield` adapts the module to `SharedHiggsfield`. `GenerateSphereSeamBridge`
  submits once and polls on the `stylepress` queue, the same cadence as StylePress jobs.
- The generated bridge is streamed through `/admin/sphere-seam/generations/{id}/bridge`
  (provider result hosts allowlisted, image-typed, size-capped) so the canvas is never tainted.
- Only `gpt_image_2` and `gpt_image_2_5` are accepted server-side. A live credit estimate and
  explicit confirmation precede every submission.

## Home screen

`sphereseam` is a `tool` widget in `packages/Mothership/DAM/src/Config/home.php`. Its Home frame
(`Resources/views/frame.blade.php`) mounts the full editor inside the Home workspace popup on
the chrome-less admin layout (`x-admin::layouts.anonymous`: same Vue bundle, asset picker and
styles, no sidebar or header) with the Home frame bootstrap the shell requires. The same
editor partial (`editor.blade.php`) renders on the full page at `/admin/sphere-seam`.

## Quality and resolution

Both selects offer **Auto** (default), resolved in the browser by `resolveGenerationSettings`
from the bridge that is actually sent:

| Bridge longest side | Auto resolution | Auto quality |
| --- | --- | --- |
| < 400 px | 1K | low |
| 400–512 px | 1K | medium |
| 513–599 px | 2K | medium |
| 600–1024 px | 2K | high |
| > 1024 px | 4K | high |

Resolution gives 2x headroom over the bridge because the result is scaled back down to the
local bridge; the proven 665 px foundry bridge therefore lands on 2K/high, the verified
default. Explicit values pass through untouched. The server accepts only concrete values.

## Configuration

Inherited from StylePress: `STYLEPRESS_HIGGSFIELD_DRIVER=subscription`, the account email,
workspace ID and mounted session directory. Optional overrides:

- `SPHERESEAM_ENABLED` (default true)
- `SPHERESEAM_HIGGSFIELD_MODEL` (`gpt_image_2_5`)
- `SPHERESEAM_HIGGSFIELD_VARIANT` (`flare`; `sunburst` stays opt-in until proven on a second scene)
- `SPHERESEAM_STORAGE_DISK` (`private`), `SPHERESEAM_DAM_DIRECTORY_ID`

Deploy with the normal image rebuild and migration flow; the additive migration
creates `sphereseam_generations`. Grant the `sphereseam` ACL to the intended roles.

## Workflow

1. Choose a local JPG/PNG/WebP or a DAM image.
2. SphereSeam makes it exact 2:1 locally without cropping (squeeze default, pad optional).
3. The seam bridge is the panorama rolled by half its width and cropped to the chosen ratio
   (1:1 default) at full height; the wrap seam is its centre column.
4. Choose the repair band (25/40/55 % or custom, clamped 10–70 %), model, quality,
   resolution and optional scene context, then confirm the credit estimate.
5. The band is blanked to a soft-shouldered tonal ramp before upload; the original bridge
   stays in the browser and is what reinsertion locks to.
6. After generation the browser restores every pixel outside the band, joins the generated
   band along a minimum-error cut and un-rolls the bridge into the panorama.
7. Review "Wrap check" and "Wrap before / after"; acceptance is visual, in a spherical viewer.
8. Export exact 2:1 PNG/WebP or save the composed panorama to DAM.

Tests: `node --test packages/Mothership/SphereSeam/tests/sphereseam.test.mjs` (geometry and the
Auto rule) and `packages/Mothership/DAM/tests/Feature/SphereSeamTest.php` (routes, ACL, shared
subscription arguments, job polling, result proxy, DAM import) in the self-hosted CI suites.
