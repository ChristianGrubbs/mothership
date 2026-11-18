# Coding standards

Short rules that a reviewer or a test can check. Each cites the place that already enforces or shows it. When a rule
and the code disagree, fix one of them in the same pull request. Formatting is Pint's job (`pint.json`: Laravel
preset, `=>` aligned); static analysis is in [Static analysis and lint guardrails](reference/guardrails.md).

## Process

1. One change per pull request; branch from `main`; never push to `main` ([CONTRIBUTING.md](../CONTRIBUTING.md)).
2. Run the test files your change touches, not the full suite. A new DAM test file must be named on a `vendor/bin/pest`
   line of the `pest` job in `.github/workflows/pest_tests.yml`, or the DAM test-file gate fails the pull request.
3. Merged is not deployed. A change that only holds on PostgreSQL (raw SQL, JSON columns, advisory locks) is proven by
   the nightly PostgreSQL leg, not by the pull request's SQLite leg.
4. After adding, renaming or removing a route, refresh `docs/api/routes.json`
   (`ROUTE_INVENTORY_WRITE=1 vendor/bin/pest tests/Feature/DocumentationRoutesTest.php`) and commit it.
5. After changing a Blade template, stylesheet or bundled JavaScript, run `tools/frontend/build-bundles.sh` and commit
   what it changes. Nothing rebuilds the bundles on deploy.

## Comments, TODOs and names

6. A TODO carries its date and an action: `# TODO 2026-10-16: <action>` (`// TODO 2026-10-16: ...` in PHP/JS).
   Example: `EffectiveAccessService.php`. Undated markers left in inherited code are not a pattern to copy.
7. Use the glossary's words ([CONTEXT.md](../CONTEXT.md)) in code, commits and pull requests. Do not add new
   phase/slice codes ("P4 D2") to docblocks; name the thing.
8. Name parameters at production call sites. Use an object or array parameter when a function takes more than one
   parameter of the same type.
9. New services take their dependencies through the constructor. Do not add new `app(X::class)` service location in
   a class that can inject (`AssetAccessDecision` in `RelatedAssets` is the shape).

## Storage and concurrency

10. Bytes go through `ContentStore` only; never build a path under `assets/.objects` or call the disk for a blob
    yourself. Derivatives use `Support/DerivativeImages` paths keyed by content sha (ADR 0009).
11. Lock the asset row (`Asset::lockedForGraph()`) before the blob (`ContentStore::lockBlob()`), and hold one blob lock
    at a time. Commit a FileObject row together with its reference to it (ADR 0009). Every directory insert, move,
    copy, rename and delete runs under `DirectoryTreeLock` (ADR 0004).

## Access and entitlement (ACL)

12. Every admin route is gated by an ACL key. Map it in the package's `Config/acl.php` (route name to key; use
    `also_authorizes` for a shared read). A route that is deliberately open to every signed-in admin says so in a
    comment next to the route, as the analytics notice does in `Routes/dam-routes.php`.
13. A route key is not item access. After the key, ask the one seam, `AssetAccessDecision` (or
    `DirectoryPermissionService` for folders). A hidden asset answers **404**, exactly like a missing one, on every
    route, including REST, shares and exports (`AssetHiddenEqualsMissingTest`).
14. Own-only roles are enforced in the service that writes, with `RoleBuilder::abortUnlessOwn()`, not only in the UI.
15. A Home widget that declares a `permission` must be reachable through some `RoleBuilder::CAPABILITIES` entry, or the
    role editor can tick a widget the catalog then hides.
16. Tokens, passwords and slugs are secrets: store hashes (`Share::tokenColumns()`), list them in the model's
    `$auditExclude` and `$hidden` (as `Share` does) and keep them out of logs. Pasted credentials go to the macOS
    Keychain, never to the repository.

## Blade and Vue

17. In a Blade template, write Vue events with the long form `v-on:error`, `v-on:click`, not `@error`: Blade reads
    `@error`, `@if`, `@php` and the like as its own directives before Vue sees them (fix `1096e385`; DOM test
    `tools/browser/tests/dam-files-tab-preview-dom.test.cjs`). A literal `@` that must reach the browser is `@@`.
18. A new `<script>` in a Blade view keeps its state transitions in a small function that a DOM-less test can call
    (see `packages/Mothership/DAM/src/Resources/assets/js/`); add a `tools/browser/tests` DOM test for any control whose
    wiring you change.
19. Every new user-facing string has a translation key in **every** locale directory: `en_US` (canonical), `en_AU`,
    `en_GB` and `en_NZ`. `php artisan mothership:translations:check` runs on every pull request.

## Previews and derivatives

20. A browser-unfriendly original (HEIC/HEIF, DNG, large raster, panorama) is shown through a derivative, never the
    original. Handle it on **every** surface that shows a preview: the authenticated `FileController`, the public
    `PublicShare\SharedViewerController`, stack-member thumbnails (`Support/StackFileThumbnail`), cards and the details
    payload (`Asset::playbackSource()`). The HEIC fix wave (PR #236 and its predecessors) was one fix per surface.
21. A derivative generator must fail closed. If the tool reports a decode error while exiting 0 (libvips `error in tile`),
    throw; never publish and cache a blank image. Downloads always serve the original.
22. Recipes go in `DerivativeImages`; a derivative is purged only when its blob is reclaimed.

## Logging

23. Non-trivial logic logs JSONL with the shape `ts`, `run_id`, `level`, `event`, `loc`, `ctx`
    (`Support/CollectionLog.php` is the model; `ShareLog`, `MergeLog`, `TrashLog` are siblings).
24. Logging is off unless its env flag is set (`DAM_COLLECTION_LOGGING` and friends in `Config/dam.php`).
25. `ctx` carries ints, bools and short strings only (events and outcomes), never names, slugs, tokens, paths or file
    contents. Redact before the sink.
26. Write to a fixed path under `storage/logs/` so a later reader reconstructs a run from the log, not a rerun.

## Tests

27. Match the check to the risk: a regression test for a concrete failure or contract, not a test per method.
    Security boundaries, data loss, concurrency and migrations get failure-path tests.
28. A test that needs a real decoder or database (HEIC via libvips, PostgreSQL locks) fails, not skips, on CI
    (`--fail-on-skipped` in `pest_tests.yml`).
29. No real assets and no secrets in fixtures.
