# Home

Home is the default authenticated landing page. Since 2026-11-10 it is one fixed page: nothing is arranged or saved
per person (design notes and the simulated walkthroughs behind it: [design/home-fixed](../design/home-fixed/direction.md)).
This file keeps its old name so existing links still resolve.

Top to bottom:

1. **Hero**: date, greeting, one sentence written from the person's calendar tasks and the next event, a search box
   that submits to the Library, and up to five start buttons (`HomeController::startActions`). A button the role
   cannot finish is left out.
2. **Your day** beside **Apps**. Your day is drawn by `home.js` from `/admin/home/calendar/mine` and
   `/calendar/events`; the month grid opens in a dialog. Apps lists the studio tools the role's catalog allows,
   each as an icon. The last tile, **iPhone app**, opens the Add to Home Screen steps for the installable web
   app; there is no native iOS app.
3. **Send files to a person**, under Apps, when Transfers is available to the account (`TransferSession::clientConfig`).
   It subscribes to `window.MothershipTransfers` and offers one Send button per connected device.
4. **Recent uploads**: eight newest listable files, starred files beneath.
5. **Studio admin**, rendered only for full-access roles (`permission_type` `all`): Studio load and who is online,
   the Signal quick feed, GitHub Trending and file storage. The Signal card has no launcher into the reader.
6. **Your saved links**: Quick links from a layout saved before the change, read-only.

`GET /admin/home/layout` returns `{widgets, catalog, links}`: the role's catalog with default settings, and the saved
Quick links. `PUT` and `DELETE /admin/home/layout` were removed. `dam_home_layouts` rows are kept and never rewritten.
Recent files and totals use Library's direct folder grants, including inherited grants, rather than navigation-only
ancestors. `home-state.js` still carries the grid-packing and layout-history helpers the old editor used; nothing on
the page calls them (# TODO 2026-11-10: remove the unused layout helpers and their unit tests in a follow-up).

## Adding a tool or card

1. Add a stable ID to `packages/Mothership/DAM/src/Config/home.php`, with title,
   description, icon, kind, allowed sizes, default size/settings and permission.
2. For a tool, place its browser distribution beneath `Resources/widgets/<id>` and
   name its entry file in the registry. Keep build sources outside that distribution.
   For a native card, add its data endpoint and renderer, and place it in `SECTIONS` in
   `home.js`. A tool gets an icon and one-line purpose in `appIcons` / `appPurposes`.
3. Test role access, account isolation, malformed settings and failure states. The
   shell mounts tools lazily; the popup offers Reload and Close tool only (no Collapse,
   operator directive 2026-09-13), and Close, Escape, reload and navigation guard
   unexported work. Test both initial load and SPA navigation.

The host bridge is `mothership-widget/v1`: tools may send `ready`, `dirty`, `escape`
and `preference-error`; the host sends `theme`. Messages must match both the frame
window and the host origin. Frame-local storage is mapped to per-admin,
per-widget server preferences. Large media stays in the mounted session or the tool's
temporary backend, not in preference JSON. Full page departure discards mounted work.

## Existing tool integrations

Distributions were imported from the operator's widget sandbox on 2026-09-05:
Signal RSS, the StylePress Mothership port ZIP, and Video Loop
Refiner's Looper2.0 frontend. Looper build sources and its backend are preserved under
`tools/widgets/looper`; runtime distributions are delivered through authenticated routes.

| Tool | Service configuration | Behavior |
| --- | --- | --- |
| Signal | `MOTHERSHIP_HOME_RSS_API` (no built-in address; unset shows the unavailable state); optional `MOTHERSHIP_HOME_RSS_ORIGIN` | Read-only feeds/stories; reading preferences belong to the Mothership account. Default upstream Origin is APP_URL. A preview may set an origin already approved by the adapter. Offline sample stories are explicitly labeled. |
| Video Loop Refiner | `MOTHERSHIP_HOME_LOOPER_API` | Upload, match, trim, preview and download through an authenticated proxy. UUID ownership gates every uploaded file and derived output. Runtime dependency: the proxy upstream is the Looper backend, which the Studio runtime starts as its private `looper` role ([runtime guide](../ops/studio-runtime.md)); the widget is unavailable while that backend is down. |
| StylePress | `STYLEPRESS_HIGGSFIELD_DRIVER=subscription` with the shared CLI session, or the existing API-key driver | 148-style catalog, favorites and reusable prompts. Permitted Mothership accounts share the configured work subscription credits; jobs and preferences remain account-owned. Restyle prepares a prompt to use with a source image. |
| SphereSeam | Same shared StylePress subscription (`stylepress.higgsfield.*`); optional `SPHERESEAM_HIGGSFIELD_MODEL`, `SPHERESEAM_HIGGSFIELD_VARIANT`, `SPHERESEAM_DAM_DIRECTORY_ID` | Native module `packages/Mothership/SphereSeam` (imported 2026-09-10 from the operator's widget sandbox). The Home popup mounts the full editor on the chrome-less admin layout; the same editor is also at `/admin/sphere-seam` (ACL `sphereseam`). The browser makes the panorama exact 2:1, rolls the wrap seam into a 1:1/3:4/9:16/16:27 bridge, blanks the central repair band and sends only that bridge as a reference image through `SharedHiggsfield` on the `stylepress` queue (GPT Image 2.5, variant flare, aspect auto). Quality and resolution offer Auto, derived from the bridge size. The generated bridge is proxied through `/generations/{id}/bridge` (result hosts allowlisted) so the browser can lock every pixel outside the band and un-roll the panorama; exports are exact 2:1 and can be saved to DAM. |

Provider credentials stay in deployment secrets and never enter iframe bootstrap data.

### Shared Higgsfield subscription

The subscription driver uses the official Higgsfield CLI 1.1.20, installed in the FPM
image with verified architecture-specific release checksums. Builds require access to
the pinned GitHub release download. It runs GPT Image 2 at high quality and 2k resolution.
Unsupported catalog ratios use the nearest supported
ratio, returned with the credit estimate. CLI usage consumes the existing subscription
credits; Higgsfield's unlimited/free web generation offers do not apply.

Set these non-secret deployment settings:

- `STYLEPRESS_HIGGSFIELD_DRIVER=subscription`
- `STYLEPRESS_HIGGSFIELD_ACCOUNT_EMAIL`: expected shared work account
- `STYLEPRESS_HIGGSFIELD_WORKSPACE_ID`: expected subscription workspace ID
- `STYLEPRESS_HIGGSFIELD_SESSION_DIR`: host directory containing the vendor-managed
  `credentials.json`, its lock file, and `config.json` with the selected workspace

The runtime mounts that directory at `/var/lib/mothership/.config/higgsfield`.
Only CLI child processes use `/var/lib/mothership` as their home. The saved login and
vendor-managed refresh state survive FPM rebuilds/recreation. Do not copy credentials
into the image, Git, frontend, or a user's Mothership preferences. The account email
and selected workspace are checked before each operation; a changed host login cannot
silently spend another account's credits. All users still need the DAM assets permission
and must satisfy any configured StylePress allowlist. No new Mothership accounts are
created by connecting this provider.

If Higgsfield revokes the session, an administrator signs into the work account again
with the host's official CLI and selects the expected workspace. Existing queued jobs
can then be checked again. CLI diagnostics and credentials are never returned to users.
Balance and estimates are read-only; submitting generation is the only credit-spending
operation. Provider submission is never automatically retried.

Sources verified 2026-09-06: [subscription CLI guide](https://higgsfield.ai/creator-hub/help-center/integrations/how-do-i-access-higgsfield-via-cli),
[pinned release](https://github.com/higgsfield-ai/cli/releases/tag/v1.1.20).

Rollback: set the driver back to `api` and restart the application containers, or restore
the preceding FPM image ([runtime guide](../ops/studio-runtime.md)). Keep the provider-session directory and
the existing generation records; their provider marker preserves polling routing.
The Looper service is separate from the web image; health
checks distinguish its availability from Home availability. This change does not
install or replace that service. Tool exports download to the browser; automatic
import of exports into DAM is not part of this release.

## Operations and verification

The additive migrations create `dam_home_layouts`, `dam_home_widget_state`,
`dam_home_tool_files` and `dam_home_generations`. StylePress submits once and returns
an account-owned job; short status requests resume after reload without holding a PHP
worker throughout generation. File downloads stream directly from the upstream body.
Ownership records remain while the existing tool backends retain their files; any future
cleanup must coordinate both lifetimes so retained files do not lose their owner.
Deploy with the normal image rebuild and migration flow ([runtime guide](../ops/studio-runtime.md)).
Rollback to the previous web image can leave these additive tables intact.

`MOTHERSHIP_HOME_LOG_ENABLED=true` writes structural JSONL events to
`storage/logs/home.jsonl` (off by default). Events contain request run IDs, action,
widget/service, account ID, status or revision; prompts, URLs and credentials are excluded.

Automated coverage lives in `packages/Mothership/DAM/tests/Feature/HomeTest.php`, the
login/SSO/PIM landing suites, and `tools/browser/tests/home.test.cjs`. Run them in the
self-hosted CI suites or against a local disposable database; never test against the live DB.
`HomeFixedPageTest.php` covers the fixed page. Browser checks must cover initial Vue mount,
SPA departure/return, responsive layout, service failure and actual tool exports.
