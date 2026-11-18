# DAM (`packages/Mothership/DAM`)

The Digital Asset Management package of The Mothership. It began as an inherited DAM module (ADR 0001) and is now owned
source: every line here is ours to change. The Composer name is `mothership/dam` and the namespace `Mothership\DAM`.

Start with [CONTEXT.md](../../../CONTEXT.md) for the vocabulary (Asset, FileObject, ContentStore, stack, share token, ...)
and the package map, and [docs/README.md](../../../docs/README.md) for the design records. The history of this package
before the fork is in [Changelog.md](Changelog.md), which stops at 2026-09-21.

## What it does

- **Library.** A folder tree (nested set, `DirectoryTreeLock`) of assets; search (lexical plus AI vectors), saved
  searches, labels, bookmarks, trash with restore, and bulk actions.
- **Typed compound assets.** One asset owns several files under roles (original, proxy, alternate, ...), versions, renditions and
  directed relationships (ADR 0002). Content-addressed blob storage behind one module, `Services/ContentStore` (ADR 0009).
- **Stacks, capture groups, merge, similar images.** One Library entry for related files; reversible merge; near-duplicate review.
- **Previews.** Images, video proxies (ADR 0003), PDF posters, 360 panoramas, 3D models (ADR 0006), HEIC/HEIF and DNG
  derivatives, web pages in a sandbox (ADR 0007).
- **Access.** Per-file and per-folder access lists (ADR 0010), roles and ACL keys (`src/Config/acl.php`), public share
  links with tokens, passwords and expiry, collections with audiences (ADR 0011), guest upload requests.
- **Automation and AI.** If/then automation rules; an AI index (descriptions, tags, search vectors) behind
  `MOTHERSHIP_AI_INDEX_ENABLED`.
- **Home workspace.** The widget catalog (`src/Config/home.php`) and tools: Looper, SphereSeam, StylePress,
  vectorization, loop maker.
- **REST API.** `src/Routes/api.php` and `src/Routes/V1`; the generated reference is `docs/api/` and `docs/manual/developer/api-dam.md`.

## Layout

| Path | Holds |
| --- | --- |
| `src/Models`, `src/Repositories`, `src/Contracts` | Eloquent models (Concord proxies), repositories and contracts |
| `src/Services` | The domain logic: `ContentStore`, `Access/`, `Share/`, `Merge/`, `Related/`, `Similarity/`, `Automation/`, preview services |
| `src/AiIndex` | The AI index, bridge client, smart search and member descriptions |
| `src/Support` | Small helpers and the JSONL log classes (`CollectionLog`, `ShareLog`, ...) |
| `src/Http/Controllers` | Admin, API, `PublicShare/`, `PublicUpload/` and `Embed/` controllers |
| `src/Jobs`, `src/Console/Commands` | Queue jobs and the `dam:*` Artisan commands |
| `src/Routes` | Route files (admin, API, public shares, upload portal, embeds, web pages) |
| `src/Config` | `acl.php`, `dam.php`, `home.php`, `ai_index.php`, `related.php`, and more |
| `src/Resources` | Blade views (many carry inline Vue), JavaScript under `assets/js`, language files, widgets |
| `src/Database` | Migrations, seeders, factories and the demo assets |
| `tests` | Pest tests (`Feature`, `Unit`, `Stability`), JavaScript tests (`tests/js`), fixtures |

## Running and testing

From the repository root (setup: [README.md](../../../README.md)):

```bash
vendor/bin/pest packages/Mothership/DAM/tests/Feature/AssetTest.php
```

Run the DAM test files your change touches. The pull request SQLite leg and the nightly PostgreSQL leg are described
in [CONTRIBUTING.md](../../../CONTRIBUTING.md#checks); a new test file must be listed in `.github/workflows/pest_tests.yml`.
JavaScript tests live in `tests/js` (`*.test.mjs`, `*.test.cjs`); `js_tests.yml` ("DAM JavaScript tests") shows the exact `node --test` invocation, which passes files explicitly because Node 22 does not accept a directory.
Language files exist for `en_US` (canonical), `en_AU`, `en_GB` and `en_NZ`; add a key to all four.

## System tools

Previews shell out to host tools. A missing tool degrades to a generic icon and a logged warning, except where noted.
The Docker images (`dockerfiles/fpm.Dockerfile`, `q.Dockerfile`) install the first four and LibRaw; on a host, install them yourself.

| Tool | Used for |
| --- | --- |
| `ffmpeg` | Video thumbnails and the 1080p proxy (`Jobs/GenerateVideoThumbnail`, `GenerateVideoPreviewProxy`) |
| `poppler-utils` (`pdftoppm`) | PDF posters (`Jobs/GeneratePdfThumbnail`) |
| `exiftool` | Embedded metadata and DNG embedded JPEGs |
| `libvips-tools` (`vips`) | HEIC/HEIF, large raster and panorama derivatives. HEIC needs libheif's HEVC decoder plugin (Ubuntu: `libheif-plugin-libde265`); without it libvips writes a black image and the service refuses to publish it |
| LibRaw (`dcraw_emu`) | The DNG fallback render (`docs/ops/dng-previews.md`) |

Homebrew equivalents: `brew install ffmpeg poppler exiftool vips libraw`.

## Operations

- Storage and tree integrity: `dam:storage:check`, `dam:storage:repair`, `dam:storage:reclaim`, `dam:directories:check`,
  `dam:directories:repair` ([docs/ops/storage-integrity.md](../../../docs/ops/storage-integrity.md),
  [docs/ops/directory-tree-integrity.md](../../../docs/ops/directory-tree-integrity.md)).
- Access model: `dam:access:report`, `dam:access:rebuild`, `dam:access:switch` (ADR 0010).
- Demo data: `php artisan dam:demo-data` seeds sample folders and assets; `--force` wipes and re-seeds.
- Logging: each JSONL log is off unless its flag is set (`DAM_COLLECTION_LOGGING`, `DAM_ACCESS_LOGGING`,
  `DAM_TRASH_LOGGING`, ... in `src/Config/dam.php`) and writes under `storage/logs/`.
- Production runtime: [docs/ops/studio-runtime.md](../../../docs/ops/studio-runtime.md).

## License

MIT (see [LICENSE](LICENSE)).
