# ADR 0007: Web pages are served through dedicated path-resolving routes and rendered only in a sandboxed iframe

Date: 2026-09-22 · Status: accepted

## Context
Website concepts and HTML-enhanced guides have no home in the Library. `AssetHelper::isForbiddenFile()`
rejects `.html`, `.htm` and `.js` at every upload ingress (form, chunked, API, re-upload, import bundle),
so an HTML mock-up can only arrive as an opaque `.zip` classified `document`, with a placeholder card and
no way to open it.

The reason the deny-list exists still holds. Every asset is streamed from the admin origin, with the
session cookie in scope, by id-addressed routes (`admin.dam.file.*`, `dam.share.asset_view`). Those
routes carry `default-src 'none'` from `AssetHelper::assetResponseHeaders()` and force `attachment` for
anything outside `isInlineSafeMime()`. Simply allowing `text/html` there would run user-authored script
as the signed-in viewer, against the admin DOM, with the viewer's cookies. And even a "safe" page could
not work: its own `css/site.css` and `js/app.js` have no id, so nothing would resolve.

The operator decided on 2026-09-22 against a separate serving hostname for v1: one more Cloudflare
hostname, certificate and Nginx server block on the Studio for a feature whose first users are a handful
of concept pages. Bundles (a zip with `index.html`) and single HTML files are both in scope; any user a
Sandbox role grants upload may add one; page scripts run.

## Decision
A **web page** is a new asset kind (`file_type = 'web'`) whose uploaded original is unpacked once into an
immutable per-version **bundle** directory (`bundles/<asset_id>/<version_number>/`) with `index.html` as
its **entry**. Three rules make it safe to serve from the admin origin:

1. **Bundles are served only by two dedicated path-resolving routes**, `GET {admin}/dam/pages/{asset}/{path?}`
   and `GET /share/{token}/page/{path?}`. Every other delivery route (`file.*`, `share.asset_view`,
   downloads) keeps treating the original `.html`/`.zip` as an opaque attachment. The routes resolve
   `{path}` inside the bundle root with `realpath` containment, as `HomeToolController::file()` already
   does for built-in tools, serve a fixed extension→MIME map, and fall back to `application/octet-stream`
   as attachment for anything unlisted.
2. **The routes carry their own Content-Security-Policy**, not the asset default. It allows same-origin
   script, style, media and `connect-src 'self'` so a page works as authored, and forbids `frame-src`,
   `object-src`, `base-uri` and `form-action`, with `frame-ancestors 'self'`. No `Set-Cookie` is ever
   emitted on these responses. The S3 disk is unsupported in this release because a presigned redirect
   would discard the policy.
3. **A page is rendered only inside `<iframe sandbox="allow-scripts allow-popups allow-forms allow-modals">`,
   never with `allow-same-origin`.** That flag is the load-bearing control: without it the document runs in
   an opaque origin, so its scripts cannot read the admin session cookie, `localStorage`, or the embedding
   DOM, and `connect-src 'self'` requests go out without credentials. The preview modal, the public share
   viewer and the "Open full screen" view all embed the page this way; there is no direct-navigation
   rendering of the entry route by design.

Classification lives in one place (`AssetHelper::getFileType()`, which `AssetUploadPublisher::fileType()`
now calls) so the deny-list carve-out for `html`/`htm`/`text/html` applies exactly when the classifier
answers `web`. Inside a bundle the deny-list relaxes for `js`, `css`, `html`, fonts, images, `json` and
`wasm`, and still rejects `php`, `phtml`, `phar`, `sh`, `exe` and the other server/executable types with
a 422 naming the entry. `SafeZipExtractor` keeps rejecting symlinks, absolute paths and `..`.

## Consequences
- **No separate origin in v1.** A page that needs `allow-same-origin` (Service Workers, `SharedArrayBuffer`
  with COOP/COEP, cookies of its own) will not work and must not be given it here: adding
  `allow-same-origin` next to `allow-scripts` removes the sandbox entirely. The documented follow-up is a
  dedicated pages hostname, at which point these routes would move behind it unchanged.
- **The iframe attribute is part of the security boundary.** Any template that renders a web page must use
  the exact sandbox token set. A test asserts the preview and share blades contain it and do not contain
  `allow-same-origin`.
- **Bundles are immutable and per-version.** A re-upload creates a new `AssetVersion` and a new bundle;
  the old bundle is deleted with its version through the derivative cleanup path. There is no in-place
  editing of a served file.
- **Thumbnails are captured inside the page**, not by the parent, because the sandbox blocks the parent
  from reading the iframe. The admin route (and only the admin route) injects a small capture script into
  `<head>` of `index.html`; it posts a JPEG to the parent with `postMessage`, which stores it through the
  existing custom-thumbnail endpoint (ADR 0006 convention). The share route never injects anything.
  (2026-09-23: when `index.html` is an exporter launcher that meta-refreshes to an HTML file inside the
  bundle, the bundler records that file as `capture_entry` and the script goes there instead, because the
  launcher navigates away before a capture could fire. That document is served `no-store` on the admin
  route, like the entry.)
- **The classifier decides what is a page.** A `.zip` without `index.html` at its root or inside exactly
  one top-level folder stays an ordinary `document`; nested zips are never expanded.
- **`dam_assets.file_type` gains an enum value.** PostgreSQL and SQLite migrations add `web`; the MySQL
  compose file is unsupported and the migration is a no-op there.
- **Cost.** One queued bundler step per upload, a bundle directory per version on the local disk, and the
  `dam.web_pages.max_bundle_size` / `max_entries` limits (512 MiB, 5 000 by default) bound it.

## Alternatives considered
- **Lift the deny-list on the existing id-addressed routes.** Rejected: runs user script on the admin
  origin with cookies, and relative resources cannot resolve.
- **A separate pages hostname (`pages.<domain>`) from day one.** The strongest isolation, and the
  eventual answer if any concept needs `allow-same-origin`. Deferred by operator decision 2026-09-22:
  infrastructure cost is not justified by the first pages, and the sandbox-without-same-origin design
  gives an opaque origin already.
- **Server-side rendering to a screenshot or PDF.** Rejected: the worker image has no browser, and the
  operator wants the pages to *run* (interactive concepts), not to be flattened.
- **`srcdoc` inlining of `index.html`.** Rejected: bundles have many files, and `srcdoc` with a sandbox
  cannot fetch siblings by relative path.

## Amendment 2026-09-22 — page grant and CSP sandbox
The plan review established that a sandboxed document without `allow-same-origin` is cross-site
for cookie purposes: the admin session cookie (`SameSite=Lax`) is not attached to the page's own
sibling requests, so a session-authenticated page route can serve `index.html` but none of its
css/js/images. The admin route is therefore **session-free** and authorises by a signed **page
grant** in the path (`{admin}/dam/pages/{id}/g/{grant}/{path?}`), issued by a session-authenticated
endpoint after the directory access check and bound to asset id, bundle version, admin id and a one
hour expiry. The public share route is likewise session-free. Both routes carry the policy on every
response through middleware, add `Access-Control-Allow-Origin: *` (opaque-origin fonts, module
scripts and `fetch()` are CORS requests), and include the CSP `sandbox` directive with the same
token set as the iframe, so a direct navigation to the entry URL is opaque-origin too. The entry is
`no-store`; siblings cache for five minutes. Rule 3 stands unchanged: the iframe attribute remains
required in every template.

## Amendment 2026-09-23 — exporter entry
Exported sites arrive as `Top/OPEN ME - X.html` (a meta refresh into `site/…`) beside `Top/site/` with no
`index.html` at the bundle level, and were misclassified as documents. The classifier now takes, at the
bundle level (root, or the single top-level folder), `index.html` when present, else the level's only
`.html`/`.htm` file. The bundler copies that file to `index.html` in the bundle root, so the entry the
routes serve is still `index.html` and relative links resolve from the same directory. Two or more
candidate files with no `index.html` stay a `document`. Upload here files every page into one root-level
Library folder (`dam.web_pages.upload_directory`, default `Web Pages`) instead of offering content folders.

## Amendment 2026-09-23 — Claude Design runtime hosts (operator decision)
Claude Design exports (`*.dc.html` plus `support.js`) render nothing under rule 2: the runtime injects
`<script>` tags for React 18.3.1, ReactDOM 18.3.1 and `@babel/standalone` 7.29.0 from `https://unpkg.com`
(with SRI) and links Google Fonts, and the same-origin `script-src` refused them, so the page stayed on its
background colour. The operator chose to widen the page CSP rather than vendor the runtime: `script-src`
adds `https://unpkg.com`, `style-src` adds `https://fonts.googleapis.com`, and `font-src` adds
`https://fonts.gstatic.com`. Accepted cost: any hosted page may load script from unpkg, and a view
contacts unpkg and Google with the viewer's IP (`Referrer-Policy: no-referrer` still keeps the grant or
share token out of those requests). `connect-src`, `img-src`, `frame-src`, the `sandbox` token set and
rule 3 are unchanged; the page is still an opaque origin with no cookies.

## Amendment 2026-10-24 — selected current-file pages
A Web Pages asset is a stack: several current attachment slots, each holding one current `AssetFile`. The page
used to be the representative's bundle only. A stack member now previews as its own page, bound to one identity:
owning asset + attachment slot + `AssetFile` + the FULL 64-hex content checksum.

- **Observational list.** `GET admin/dam/pages/{id}/members` (ACL `dam.asset.view`, `no-store`) returns the
  canonical `StackMembers` order with each file's id, checksum, name, size, candidate extension and cache state. It
  builds nothing, queues nothing and writes nothing. A merge source answers `[]`.
- **Optional file context.** The admin grant/open routes accept `file` + `checksum` together; a lone half, a
  malformed value or a file that is not a current member of that asset is 404, and a current file with another
  checksum is an explicit `stale` (409). A member grant is a distinct HMAC claim (`m.` prefix, domain
  `dam-page-member|`) over asset, attachment, file, checksum, admin and expiry; the legacy verifier rejects it and
  the member verifier rejects a legacy claim. Every request under it, the entry and each sibling, finds the same
  slot, file and checksum still current, else 404; the named admin is re-checked before anything is built and on the
  entry.
- **Isolated cache, nothing else written.** `WebPageMembers` + the queued `BuildWebMemberBundle` build the member's
  own `FileObject` through the bundler's existing `materialize()` (inspector, hardened extraction, forbidden-entry
  and size limits) into `bundles/{asset}/m/{kind}-{checksum}/`: a non-served `manifest.json` (schema, asset id,
  checksum, kind) and the served `root/`. The resolver serves only the exact canonical root and refuses a link or
  alias; another asset's cache is never reused for the same bytes. `Asset.meta_data.web_bundle`, the original bytes,
  rows, `updated_at`, tags, thumbnails and share rows are untouched. Member entries never get the capture script and
  never post a thumbnail. One atomic marker per key queues one job; a marker older than the job timeout is recovered;
  a job re-checks the current identity before work and again under the publish lock, and a stale one publishes
  nothing. A terminal manifest is written only for a refusal of the content itself (`too_large`, `entry_missing`,
  `forbidden_entry:…`, `unsafe_path`, `symlink_entry`, `entry_too_large`, `too_many_entries`, the extractor's
  `zip-…`/`invalid-zip` limits, `extraction_incomplete`, and a file with no object at all). `extraction_incomplete` is
  the extractor silently dropping an entry it cannot use (an encrypted or corrupt entry, real bytes past the cap
  despite the header, a path that cannot exist beside another): a property of the bytes the header preflight cannot
  see, so it is final for that checksum. Any other failure is an infrastructure fault of that run, not of the bytes:
  a real filesystem error (PHP raises it as an `ErrorException`), a blob that is missing or unreadable (storage can be
  briefly unmounted), a worker timeout or a killed job. It releases the marker and its staging, records no manifest,
  and the next authorized selection builds again. The existing asset purge removes `m/`.
- **Public member pages.** Two prefixes, `share/{token}/pf/{pin}/{path?}` and, behind a password,
  `share/{token}/pgf/{grant}/{pin}/{path?}`, with `pin = {asset}.{attachment}.{file}.{checksum64}` so every relative
  request keeps the whole pin. They sit behind the same throttle, `WebPageHeaders:public`, `EnforceShareGate`,
  active-share and asset-or-container membership checks as `page`, before any member lookup or build; a denied
  request queues nothing and creates no marker. Neither is a download route. `page`/`pg` and a merged source keep
  their historic behaviour, and these prefixes never alias them.
- **One name.** The member's display name (the stack's own name for its main file, else the file's, as in the list) is
  the heading and frame title of the preview strip and of the full-screen page opened for it. A pinned file that was
  revised or removed while open is reported once, by the stage. The server's 404 only says the old file id is not
  current, so the stage says "Checking this file…" until the one list refresh settles, then "changed" (the slot holds
  another file; "Show current file" is the viewer's click) or "no longer part of this page" (the slot is gone). If that
  refresh cannot be read, nothing is claimed: the pin and the list stay as they were, and the stage offers "Check
  again", one read per click. The strip keeps the pinned file's name, claims no position for it, and the viewer's own
  click is the only way to another file.
  A 404 with no preview list behind it (the standalone full-screen page) covers a revised, removed, moved or
  inaccessible file alike, so that frame says only "This file could not be shown here. It may have changed or is no
  longer available." and never asserts removal.
- **Sandbox unchanged.** The iframe token set stays `WebPagePolicy::SANDBOX`, never `allow-same-origin`. The shared
  viewer probes the pinned URL (HEAD, no credentials, bounded) and mounts the frame only when it answers 200.

## Accepted cost
A member build needs a queue worker. Without one the first selection of a file stays "preparing" and the UI stops
polling after about a minute. A content refusal is not retried until the file is revised or `bundles/{asset}/m` is
purged; an infrastructure fault is, on the next authorized selection, with no extra retry or TTL setting. While a
selected file is still being prepared, each bounded poll is such a selection, so a persistent fault ends at the
frame's poll limit.
