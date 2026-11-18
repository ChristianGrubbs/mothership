# Mothership — domain context

The Mothership is a fully owned codebase (ADR 0001) whose first architectural
change is a typed compound-asset graph (ADR 0002). It is the production DAM, deployed on the
maintainer's Mac (`docs/ops/studio-runtime.md`).

## Terms
- **Asset** — the durable, human-facing record (title, type, ownership, lifecycle, metadata, canonical
  preview). One asset may own many files. In code this is the existing DAM `Asset` model, extended.
- **FileObject** — an immutable stored blob identified by sha256. Never edited in place. Its locator is
  always content-addressed, `assets/.objects/<sha[0:2]>/<sha>[.<ext>]`, and never names a folder, so no
  folder operation can stale it (ADR 0009). The model refuses to record any other path. A FileObject is
  **referenced** while an AssetFile, a Rendition output or an editor resource names it
  (`FileObject::referenced()`); deletion reclaims a blob only once nothing references it.
- **Editor resource** — an image a layered image editor document names: its base, an uploaded background, or
  the copy a save-as-new makes (`ImageEditorResource`). It names a FileObject and never a file of its own. A
  base is the asset's original blob, and a save-as-new shares its source's blobs.
- **Library path** — `dam_assets.path`, `assets/<folder tree>/<file_name>`: the name people see, rebuilt when
  folders move. It serves display, search, `?path=` URLs and authorization lookups. It is never a graph
  locator and, since slice 3, no writer creates a file there: it is UNIQUE in the database, and new names
  come from `Support/LibraryPaths`. Files still at older Library paths go with a **reclaim**; after one, a
  Library path names no file on disk.
- **ContentStore** — the one module that knows the blob layout (`packages/Mothership/DAM/src/Services/ContentStore.php`).
  Writers hand it bytes and get the FileObject back; they never build an object path. Uploads stage at
  `assets/.staging/` and `adoptStored()` moves the file into its blob; the writer then saves the asset with
  `withGraphContent($fileObject)`, so the graph observer links that blob and never reads the Library path.
  Folder create, rename, move, copy and delete touch only the database. `dam:storage:check`
  (hourly) and `dam:storage:repair` keep the invariant visible and repairable. It is also the one read
  seam: `current(asset)` names an asset's bytes, `open()` streams them, `localPath(object, ext)` stages them
  for tools that need a real path (the extension comes from the caller, never the blob name) and
  `deliver()` answers HTTP. A read with no bytes throws `ContentUnavailable` (404 on HTTP), recorded to
  `storage/logs/content-store.jsonl`. Image derivatives are keyed by content,
  `derivatives/<sha[0:2]>/<sha>/<recipe>.<ext>` (`Support/DerivativeImages`).
- **Blob lock** — `ContentStore::lockBlob($sha)`, the PostgreSQL advisory lock (namespace `74190002`) that
  orders writing a FileObject row against unlinking its blob. Writers commit the row together with their
  reference to it. Cleanup holds one blob lock at a time. The lock order is asset row
  (`Asset::lockedForGraph()`) before blob (ADR 0009).
- **Reclaim** — `dam:storage:reclaim` (ADR 0009 slice 4): the operator-run deletion of what the asset disk no
  longer needs. It removes Library copies and legacy `assets/.editor/` working files once a blob holds their
  bytes, clearing an editor resource's old `path` with its file, and removes the pre-slice-2 path-keyed
  derivatives. It then removes the directories they leave empty. A `--force` run needs a covering backup pair
  from `dam:storage:backup`. It never touches the blobs or a dot-folder other than the legacy `.models`, and it
  keeps every file whose bytes no blob holds. Afterwards `assets/` no longer mirrors the Library tree.
- **AssetFile** — a FileObject attached to an Asset under a **file role** (original, primary, alternate,
  proxy, thumbnail, texture, plate, sidecar, subtitle, transcript, source, output, mask, prompt, project,
  calibration, version, other). **Alternate** is the same asset in another file format (a PSD beside its
  PNG, a source ZIP); Optimize and Vectorize outputs use `output`.
- **Available versions** — the chips beside a Library card's type badge: `OPT` for an Optimize output,
  `SVG` for any SVG attachment, and the type of every other attachment unlike the asset's own. The
  original, proxy, thumbnail and version roles never count.
- **Version** — a numbered point in an asset's lineage (`AssetVersion`).
- **Rendition** — a reproducible derivative produced by a recipe (FFmpeg/libvips/etc.) with recorded
  parameters, worker version, status, and output FileObject.
- **Preview proxy** — the rendition playback prefers over an oversized original: a 1080p H.264
  derivative attached under the `proxy` role (ADR 0003). It is a *delivery* choice only — frame
  capture still cuts from the master.
- **Relationship** — a typed, directed asset→asset edge (`AssetRelationship`); see ADR 0002 for the
  vocabulary.
- **Compound asset** — an asset whose meaning depends on several files or related assets (GLB +
  textures, OSV + sidecars, video + captions).
- **Directory** — the DAM folder tree (inherited DAM concept). Stored as a nested set whose
  `_lft`/`_rgt` intervals derive every Library path; see **Directory tree lock**. Folder names starting
  with `.` are reserved (`assets/.objects` holds every blob, `assets/.staging` holds uploads in flight).
  A folder is a database row only: it has no directory on disk.
- **Directory tree lock** — the one lock (`DirectoryTreeLock`, PostgreSQL advisory key `74190001`) every
  directory insert, move, copy, rename and delete runs under, so nested-set shifts never interleave
  (ADR 0004). `dam:directories:check` detects a broken tree hourly; `dam:directories:repair --force`
  rebuilds it from `parent_id` under the same lock.
- **Owner** — the admin in `created_by` of a file or folder. The column is null when that account no longer
  exists; full admins then manage the item (ADR 0010).
- **Access list** — the people and groups attached to one file or folder (`dam_{directory,asset}_{user,group}_access`).
  An empty list means **Only me**. A file has its own list; its folders never widen it.
- **Principal** — one entry in an access list: a user or a group.
- **Everyone** — the built-in group (`dam_groups.system_key = 'everyone'`) whose members are all active admins;
  it has no member rows. From sharing slice 4 it cannot be renamed, edited or deleted.
- **Full admin** — an admin whose role has `permission_type = all`.
- **Cascade** — writing a folder's access list onto every folder and file beneath it.
- **Share link** — a public token URL. Its behaviour is unchanged by access lists (spec D6).
- **Access model** — the `DAM_ACCESS_MODEL` row in `dam_configuration`. Its state is `grants` (or no row: today's
  folder grants), `pending:<run>` (a switch under way or interrupted, still read as grants) or `lists` (the access
  lists). Reads use the lists only for exactly `lists`, and `DirectoryPermissionService` answers through the reader
  for that model.

  `dam:access:switch lists` runs in maintenance mode (`php artisan down`, the switch, `php artisan up`), under
  the lock it shares with `dam:access:rebuild`:
  1. It drains the requests already admitted.
  2. In one transaction, and only on a clean comparison, it rebuilds the lists from the grants, compares the
     two for every active admin and item, fills in missing owners and writes `pending:<run>`.
  3. It settles and compares again. Only then does it move the state to `lists`, or back to grants if a change
     landed meanwhile.

  Every list rebuild and every change of state runs in a transaction that locks this row first, and every change
  of state is a compare-and-set against the value read under that lock. `dam:access:rebuild` runs only while the
  state is exactly `grants`. `dam:access:switch grants` is the rollback from any state (ADR 0010).
- **Generated role group** — a group the rebuild makes from a role that grants folders (`system_key = 'role:<id>'`),
  holding the role's admins. A machine-maintained preview until the switch: a rebuild resets it, or refuses when a
  folder grant uses it and its members would change.
- **PIM surface** — the inherited product/category/attribute/completeness UI and packages.
  Hidden behind `MOTHERSHIP_PIM_ENABLED=false` (default) and slated for removal.
- **Simple / Studio mode** — the two presentations of one system planned in the blueprint; not built yet.
- **Legacy ref** — a retained source identifier on records created by a completed historical import.
- **The Mothership** — the product display name people see (logo lockup, titles, help center), read from
  `config('documentation.name')` (`DOCUMENTATION_PRODUCT_NAME`, default `The Mothership`). The short name is
  **Mothership**, and the technical slug `mothership` (prefix `MOTHERSHIP_` for env vars) names namespaces, packages,
  storage keys and the widget bridge id.
- **Shared work subscription** — the one Higgsfield work account The Mothership generates with, driven through
  the official CLI by `SharedHiggsfield` on the `stylepress` queue. StylePress and SphereSeam are two front
  ends on this same backend; neither holds a credential of its own.
- **Sandbox capability** — one checkbox in the custom-role editor's Sandbox panel that expands to a fixed
  set of ACL keys (`RoleBuilder::CAPABILITIES`). Every Home widget that declares a `permission` must be
  reachable through some capability, or the role editor can tick the widget while the Home catalog hides it.
- **SphereSeam** — the native module (`packages/Mothership/SphereSeam`) that repairs the wrap seam of an
  exact-2:1 equirectangular panorama. Mounted in the Home workspace popup or at `/admin/sphere-seam`.
- **Seam bridge** — the only image SphereSeam sends to the provider: the panorama rolled by half its width
  and cropped to a supported ratio so the wrap seam is its centre column, with the central **repair band**
  blanked. Every pixel outside the band is restored from the original in the browser after generation.
- **Model thumbnail capture** — the browser that already renders a GLB/3MF/STL preview also captures its grid
  thumbnail (no server renderer exists); stored under the ordinary `meta_data['thumbnail_path']` convention,
  never overwriting a `custom_thumbnail` (ADR 0006). A 3D file *inside* a stack is captured per file blob
  instead, `derivatives/<sha[0:2]>/<sha>/model-capture.jpg`, and never touches the stack's own thumbnail.
- **Web page** — an Asset with `file_type = 'web'`: one uploaded `.html` file or one `.zip` bundle with
  `index.html` at its root (or inside exactly one top-level folder). Served only through the dedicated
  path-resolving routes and rendered only inside the **page sandbox** (ADR 0007).
- **Bundle** — the immutable unpacked tree `bundles/<asset_id>/<version_number>/` on the asset disk that a
  web page is served from, produced once by `WebPageBundler` from the original; a single `.html` upload
  becomes a bundle containing only `index.html`. A re-upload creates a new version and a new bundle.
- **Entry** — the file `index.html` at the bundle root. In the zip it is `index.html` at the bundle level (the
  root, or the single top-level folder), else the one `.html`/`.htm` file at that level (an exporter's
  `OPEN ME.html` beside `site/`), which the bundler copies to `index.html`. A zip with neither is an
  ordinary `document`; two candidate files and no `index.html` is ambiguous and also a `document`.
- **Page sandbox** — the iframe attributes and response policy that keep a page's scripts in an opaque origin:
  `sandbox="allow-scripts allow-popups allow-forms allow-modals"` (never `allow-same-origin`) plus the
  page routes' own Content-Security-Policy. The sandbox attribute is part of the security boundary.
- **Page group** — a named, nestable folder in the Web Pages area (`dam_web_collections`; the code still says
  "collection"). Shared team structure visible to every admin with `dam.pages`; organizes web pages only and
  grants nothing (ADR 0008, renamed in the UI by ADR 0011).
- **Collection** — a hand-picked, ordered set of files from any folders (`dam_collections`,
  `dam_collection_assets`) with an **audience** and an optional expiry (ADR 0011). An internal audience names
  people and groups (`dam_collection_audience`), who read members only through the collection's own routes
  (`via = collection:<id>`, `CollectionGrant`); the grant never widens the Library, search, REST or zips. A public
  audience gets one share link (`share_type = collection`) with the usual link rules. Only members that are out
  for use are served or counted. Adding a member needs **redistribute**: seeing the file and owning it, being a
  full admin, or holding `dam.asset.share`.
- **Slug** — a collection's short address, `/c/{slug}`: unique, lowercase, never a reserved word. An old slug
  answers 301 to the current one (`dam_slug_redirects`). It leads to a public collection's active link, or, after
  sign-in and the audience check, to any other collection; it never grants anything.
- **Placement** — the single page group a web page is filed in (`dam_web_collection_assets`, one row per
  asset). A page without a placement is **unfiled** and appears at the browser root.
- **Organize** — the ACL `dam.pages.organize`: create, rename, move and delete page groups, move pages.

## Stacks, grouping, AI and sharing
Added 2026-10-16 from the code. Each entry names the class that owns the idea.

- **Stack** — one Library entry (an `Asset`) that stands for several related files. An asset is a stack when it has
  a **stack basis** (`Asset::STACK_BASES`: `product`, `campaign`, `use_case`, `other`) and a **stack key** (the binding
  product name, SKU or campaign, 1 to 191 characters). Both null is a plain asset. `AssetStackService` sets and clears
  them; clearing the basis clears the key.
- **Attachment** — one file slot inside a stack (`Attachment`: role, label, position). Its `AssetFile` rows are its
  **revisions**, exactly one current; restoring an old revision appends a new one. Revisions never appear as sibling files.
- **Representative** (also the cover) — the attachment whose current revision is the entry's thumbnail, preview and
  default download. It is the entry itself, so it keeps the Library name (`Support/StackFileName`). Any file in the
  stack can be promoted to it.
- **Stack member** — a file of a stack that is not the representative. It has no asset row of its own: it is an
  attachment's current `AssetFile`, named by its label (`Support/StackFileName`), renamed by `StackMemberRename`, and
  described by `AiIndex/MemberDescriptions`. `RelatedAssets` also says "stack members" for the companions of a
  **capture group**; those are separate assets, a different thing.
- **Suggested stack** — a bounded, per-viewer, access-filtered group of entries that look like they belong together
  (shared stack key, capture group, filename stem, or near-duplicate pair). Suggestion only: `StackSuggestions` never
  stacks or merges by itself, and a dismissal hides the group on every surface.
- **Merge** — folding separate assets into one stack as a reversible, recorded operation (`AssetMergeService`,
  `AssetMerge`). Active until split (the source returns) or finalized (irreversible).
- **Similar group** — a review set of 2 to `SimilarImages::GROUP_MAX_MEMBERS` (50) still images that are all within
  the perceptual-hash threshold of each other (`DHash`, `PerceptualHashes`, `SimilarImages`), each listable by the
  viewer. A chain (A-B, B-C, not A-C) is never one group. A group action names the exact reviewed members (`groupKey`)
  and answers HTTP 409 if the set has changed. Byte-identical files are exact duplicates, not similar.
- **Capture group** — separate assets that are one shot in several formats, grouped by filename stem inside one folder
  by `CaptureGroupingService` under a `CaptureProfile` (allowed extensions and a priority order). The group
  (`CaptureGroup`) has a primary asset; the other members' cards are hidden while the primary is live. Not the same as a
  stack: a stack's files are attachments of one asset.
- **AI index** — the per-asset record (`dam_ai_index`) of a lexical search document, an AI caption with vocabulary-tag suggestions, and vectors, built by the
  `AiIndexAsset` job through the bridge (`AiBridgeClient`) and held by `AiIndexStore`. `AiIndexDispatcher` is the one
  place that decides whether indexing runs (`MOTHERSHIP_AI_INDEX_ENABLED`, off by default). Tag suggestions are open until a person
  accepts or dismisses them (`AiTagReview`); re-indexing replaces only open ones.
- **Describe** — one bounded, synchronous bridge call that captions a single picture. `MemberDescriptions` describes a
  stack member (served only while that revision is still current and its sha256 matches); `AiNameSuggester` proposes a
  `YYYY-MM-DD_<event-or-subject>_<place>` name that the Library's rename applies.
- **Automation** — an if/then rule (`AutomationRule`: trigger, conditions, actions, a `version` that goes up on every
  edit, an `owner_id` whose rights the actions run with). `AutomationEngine` evaluates a rule once per (rule, version,
  trigger event) (`AutomationRun`, the loop guard's key), in chunks (`RunAutomationChunk`). Writes carry the source
  `rule:<id>@v<n>`.
- **Preview derivative** — a browser-safe image made from an original that a browser cannot show, at the content-keyed
  path `derivatives/<sha[0:2]>/<sha>/<recipe>.<ext>` (`Support/DerivativeImages`, ADR 0009). The originals are never
  rewritten; downloads serve the original. See **HEIC preview** and **DNG preview**.
- **HEIC preview** — `HeifPreviewService` decodes a HEIC/HEIF still with libvips (`vips thumbnail`; no PHP decoder reads
  HEIF) into a WebP of at most 1920 px, rotated to its declared orientation, metadata stripped. It detects the file by its
  `ftyp` brand, never its name: `heic`, `heix` (HEVC Main 10), `heim`, `heis` and `mif1` are accepted; the sequence
  brands (`hevc`, `hevx`, `hevm`, `hevs`, `msf1`) and AVIF (`mif1` that also lists `avif`) are not. `Asset::playbackSource()`
  describes a HEIC to the details view as an image served through the size-1920 preview route, and `AssetHelper::getPreviewUrl()`
  never takes the S3 shortcut for it. Shared inline previews serve the WebP after the usual share checks.
  A decode failure falls back to the original. **Decoder requirement:** libheif needs its HEVC decoder plugin
  (Ubuntu: `libheif-plugin-libde265`, a Recommends). Without it vips warns `error in tile`, exits 0 and writes a black
  image, so the service refuses to publish when stderr says so (PR #236). CI probes a real decode
  (`.github/scripts/heif-hevc-probe.sh`) and runs `HeifPreviewTest` with `--fail-on-skipped`.
- **DNG preview** — `DngPreviewService` extracts the DNG's embedded JPEG (ExifTool), or develops one with LibRaw, as the
  `dng-preview` and `dng-thumbnail` recipes; `GenerateDngPreview` is the queued fallback. See `docs/ops/dng-previews.md`.
- **Share token** — the secret in a public share URL (`Share`, table `dam_shares`). It is a bearer capability: only its
  SHA-256 (`Share::hashToken()`, `token_hash`) and an `APP_KEY`-encrypted copy (`token_encrypted`) are stored
  (`Share::tokenColumns()`), never the plaintext, and no form of it enters the audit trail. `ShareGate` decides access
  (password, expiry, revoke). A custom address (`slug`, `ShareSlugs`) is a second way to reach a link and is equally
  secret. An `UploadRequest` token is stored the same way.

### Upload requests
- **Upload request** — a no-account upload link (`UploadRequest`, table `dam_upload_requests`, `/u/{token}`) a staff
  member sends to someone outside the DAM. It names one intake folder and may carry a title, instructions, an expiry,
  file-count, size and type limits, and a password. The guest uploads through the portal (`UploadPortalController`,
  `PortalGate`) without signing in and never sees the Library.
- **Upload submission** — one guest visit's uploads to a request (`UploadSubmission`, `dam_upload_submissions`).
- **Guest intake** — files a guest sent land `pending_review` in the intake folder, private to staff who may decide
  them. A staff member accepts each file (in place or into another folder) or rejects it to the trash
  (`IntakeReview`). This is a per-file decision, not an approval workflow.

### Phase and slice codes in docblocks
Docblocks and specs cite codes such as "P4 D2", "P2 S5a" or "P3b-1". They are not defined in this repository's
glossary; they come from the operator's roadmap note, kept outside this repository ("Enterprise DAM capability audit and
roadmap", 2026-10-06). Reading them: **P<n>** is a roadmap phase
(P2 is "Access and lifecycle"; P3a/P3b stacking and merge; P5a/P5b/P5c
guest intake, automation and near-duplicates), **D<n>** is a numbered roadmap decision (D21 availability and trash, D19
automation), **S<n>** is a slice inside that spec's PR sequence. In new code, name the thing and drop the code.

## Home widget shapes (operator vocabulary, 2026-09-11)
The operator names a Home widget by its visible shape. Build to the shape named; these terms are not the
`kind` value in `packages/Mothership/DAM/src/Config/home.php` (Signal is `kind: 'tool'` yet a Quick View).
- **Gizmo** — a compact stat card: icon + title, one large number, a caption, optionally a meter or a row
  of secondary stats. Read-only; no list, no Refresh, no Open. Today: `storage` (Storage), `totals`
  (Your library).
- **Gadget** — a creative-tool launcher: icon + title, a hero art panel, and a footer carrying the tool's
  one-line description (its `home.php` `description`) with **Open ↗** that expands the tool into the Home workspace
  popup. The popup's only controls are **Reload** and **Close tool** — never Collapse (operator
  directive 2026-09-13); a workspace lives only while its popup is open. Today: `looper`, `sphereseam`, `stylepress`,
  `vectorization`, `loop-maker`, `palette-studio`, `sound-effects`, `plaque`.
- **Quick View** — a live list: a meta row (status, "Updated …", **Refresh**), then a list of linked
  items. Today: `rss` (Signal), `trending` (Trending on GitHub).
- Not yet named: `links` (Quick links), `recent` (Recent files), `favorites` (Favorites).

## Package map
The packages below say which code is native and which was inherited. Everything lives under
`packages/Mothership/` except `app/`. Autoloading is in `composer.json`.

| Group | Packages | Notes |
| --- | --- | --- |
| **Mothership-owned** | `DAM`, `SphereSeam`, `AppUrlGuard`, and `app/Documentation` | `DAM` began as an inherited DAM module and is now ours throughout. `SphereSeam` and `AppUrlGuard` are native modules. `app/Documentation` is the Help center and route inventory. |
| **Upstream, shared chrome** (kept; DAM uses them) | `Admin`, `AdminApi`, `Core`, `DataGrid`, `HistoryControl`, `Installer`, `Notification`, `Theme`, `User` | Inherited and modified freely. `Admin` holds the shell, ACL editor and roles; `AdminApi` holds the REST/OAuth layer; `Installer` the installer and seeders. |
| **Upstream, PIM surface** (hidden, slated for removal) | `Product`, `Attribute`, `Category`, `Completeness`, `DataTransfer`, `Measurement`, `ElasticSearch`, `Webhook`, `MagicAI`, `AiAgent` | Gated by `MOTHERSHIP_PIM_ENABLED` (`config/mothership.php`, default `false`): menu items are hidden and PIM routes answer 403 (`EnsurePimEnabled`). Their tests still run in CI. Verify DAM does not call a package (for example `MagicAI`/`AiAgent`) before removing it. |
| **Dev only** | `DebugBar` | |

The packages the initial import carried that no longer exist: `Publication`, `ProductPassport`, `Resource`, `MindMap`.
Laravel Passport itself is **not** gone: `config/auth.php` still uses the `passport` driver for the REST API and
`AdminApi` ships the `mothership:passport:*` commands.

## Hosts and surfaces
- Runtime: the maintainer's Mac. `tools/studio/runtime.py` runs the stack on Apple container and publishes
  Nginx on loopback only (`docs/ops/studio-runtime.md`).
  "Studio" in tool, file and screen names is the name of this runtime, not of a machine.
  Any public origin is the operator's `APP_URL` in the private `app.env`, never recorded in this repository.
- CI: GitHub Actions on the operator's self-hosted runner pool (`.github/workflows/`). Pull requests run only the fast
  checks, among them the SQLite Pest leg ("Pest Core (SQLite)"). The PostgreSQL legs (production's database), the
  PostgreSQL + Elasticsearch job, the advisory MySQL/MariaDB/SQL Server legs and Playwright run nightly (cron 06:23 UTC,
  window 00:00-05:55 America/Chicago) and on `workflow_dispatch`. `member_description_tests.yml` is manual only.
  Pushes never trigger a workflow. Deploy Studio from a nightly-green `main` commit. The table of required checks is in
  `CONTRIBUTING.md`; the workflows are the authority.
- Worktrees: keep hand-made worktrees under one root. The Claude and Codex desktop apps name their root after the
  main checkout's directory, so their worktrees may land in a sibling root. That is the same repository. Stack tooling (`worktree-sweep`, the agent coordinator) resolves the repository with Git, not
  from the directory name, so both roots are cleaned and guarded alike.
- Mac: day-to-day development uses host
  PHP, not Docker: Homebrew PHP 8.5 + Composer since 2026-09-16, `composer install --ignore-platform-reqs` (the lock
  pins 8.4), a `.env` with `DB_CONNECTION=sqlite` pointing at a migrated+seeded SQLite file, then
  `vendor/bin/pest <files>` runs the DAM suites in seconds. Run the files a PR touches locally before pushing; one CI
  round-trip is 10-25 minutes. Docker Compose (`compose.dev.yaml`) remains an optional full-stack path, described in
  `README.md`; the maintainer's Mac does not run it. The Studio itself runs on Apple `container`, not Compose.
- Docs index: `docs/README.md`. Code rules: `docs/CODING_STANDARDS.md`. Agent entrypoint: `AGENTS.md`.
- Design records: `docs/adr/` (decisions). The maintainer's project record, kept outside this repository, links here.
