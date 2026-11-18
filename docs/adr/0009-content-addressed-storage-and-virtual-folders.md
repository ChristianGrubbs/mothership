# ADR 0009: Bytes live in content-addressed blobs; the Library path is a name

Date: 2026-09-22 · Status: accepted (slices 1–4 implemented) · Spec: the maintainer's design note "Virtual folders and content-addressed storage - spec" (2026-09-22, kept outside this repository) · Supersedes PR #23

## Context
The Mothership manages large files, so moving or renaming a folder has to be safe and cheap. Every asset had two
addresses for the same bytes: `dam_assets.path` (the **Library path**, rebuilt from the folder tree
whenever a folder moves) and `dam_file_objects.path`, the graph locator (ADR 0002). On first ingest the
graph observer copied the bytes to `assets/.objects/<sha>` but recorded the Library path as the
locator. Only an Eloquent `updated` event kept that locator in step. Five raw-SQL writers never fire that
event: folder rename, `MoveDirectoryStructure`, `MassMove` (assets and folders) and `MoveDamAssetsToS3`.
Each of them left locators pointing at files that had moved. The visible failure was a 3D primary-view
save answering 422 "The model file is unavailable." after a folder move. PR #23 patched two of the five
writers.

Patching writers one by one depends on every future writer remembering to do it. Adding read-side
fallbacks everywhere only detects the problem after the fact; ADR 0004 already ranked that approach
below prevention.

## Decision
A FileObject locator is always content-addressed: `assets/.objects/<sha[0:2]>/<sha>[.<ext>]`. A blob is
written once through a verified temp path and is never moved or renamed. Blobs are deleted only through
reference-counted `AssetDeletionService` tombstones. The optional extension is a hint for tools that
check file suffixes; it is not part of the blob's identity. One blob can serve assets with different
extensions. Types therefore come from the attachment label, the asset's extension, or the MIME type
(`AssetFile::extension()`), never from the blob name.

- `ContentStore` (`packages/Mothership/DAM/src/Services/ContentStore.php`) is the only code that knows the
  layout. It exposes `objectPath`, `isObjectPath`, `putStored` and `putLocal`. Every FileObject writer
  ingests through it and gets a FileObject back; no caller builds an object path.
- The `FileObject` model rejects any save that would record a non-content-addressed locator
  (`NonContentAddressedLocator`). The factory emits object paths.
- The graph observer records the blob on first ingest. It no longer follows renames, because no locator
  names a folder.
- The Library path stays the name people see. It serves display, search, `?path=` URL compatibility and
  authorization lookups. Once every slice has landed it names no file on disk (slice 4).
- **Blob lock.** Content addressing lets one blob serve every asset with identical bytes. Deleting a blob
  therefore has to be ordered against a concurrent re-ingest of the same bytes. The mechanism is
  `ContentStore::lockBlob($sha)`, a PostgreSQL transaction-scoped advisory lock in namespace `74190002`
  (`DirectoryTreeLock` uses `74190001`). MySQL and SQLite take no lock.
  - Every writer commits its FileObject row in the same transaction as its reference to it, and does the
    lookup, write and row under the lock.
  - Tombstone cleanup takes the lock for one blob at a time. It unlinks the blob only when no row names
    the path. When a row names it but nothing references that row, cleanup keeps both the blob and the
    tombstone.
  - Locks are always taken asset row first, then blob. Graph writers take the asset row lock with
    `Asset::lockedForGraph()`, which is `FOR NO KEY UPDATE` on PostgreSQL, so another transaction's
    foreign-key check on that row never waits behind them.
  - `BlobLockRaceTest` covers these orderings with real two-process PostgreSQL races.
- `StorageIntegrity` separates detection from repair, following ADR 0004. `dam:storage:check` runs hourly
  and exits 1 on any finding. `dam:storage:repair --force` repoints or copies locators and backfills
  missing originals. `dam:storage:repair --verify` re-hashes Library files against their originals and
  can resume where it stopped.

## Consequences
The work ships as four pull requests. Each one runs local checks, CI on the self-hosted pool, a
cross-model review and a Studio release.

1. **Locator invariant (non-destructive).** Adds `ContentStore`, the model guard and blob locators on
   ingest. Every writer moves onto the store. The type-by-locator readers switch to
   `AssetFile::extension()`. The migration `2026_09_23_000002_content_address_file_objects` runs
   `repair()` once. It repoints metadata, copies only blobs that are missing, and never re-hashes the
   library. Raw copies attach their source's FileObject. Hazards fixed in the same slice:
   - `.objects` paths refused by the generic file endpoints
   - dot-folder names reserved, so a root `.objects` folder cannot be created
   - `REPLACE` prefixes guarded by a trailing slash and `LIKE`
   - the old in-place image edits now versioned
   - attachment deletion done through tombstones
   - client-supplied `path` ignored by the update endpoints
2. **One read seam.** Every byte read goes through `ContentStore` (`open`, `localPath`, `deliver`,
   `current`). Derivatives are keyed by sha. Precondition: `dam:storage:repair --verify --force` has run
   on the Studio and `dam:storage:check` is clean. Until then, assets that the old in-place editor
   rewrote still hold their pre-edit bytes in the blob.
3. **Folders are metadata.** Uploads write only blobs. Folder create, rename, move, copy and delete run
   only in the database, under `DirectoryTreeLock`, and a copy shares its source's FileObjects. Known
   gap: `dam_asset_directory` is many-to-many, but `path` records only one folder. Slice 3 keeps today's
   behavior.
4. **Reclaim (destructive, operator-gated).** `dam:storage:reclaim` removes Library-path copies once the
   asset's blob verifies. It also removes path-keyed derivatives and empty folders. It runs only with
   explicit operator approval, after a verified backup of the database dump and the blob store taken
   together.

After slice 4, `storage/app/private/assets` no longer mirrors the Library tree, so files cannot be found
by name in Finder. The database dump and the blob store must always be backed up and restored together.

## Amendment 2026-09-25 — slice 3: folders are metadata

**Write seam.** Writers never place an asset's content at a Library path or at a physical off-tree path
(`assets/.editor/results`, `.models`, `generated`, `SphereSeam`). They produce bytes in a local temp file or at
`ContentStore::stagingPath()` (`assets/.staging/`, a reserved dot-folder on the asset disk) and ingest them:
`putLocal()` for a local file, or `adoptStored()`, which **moves** a staged file into its blob (a rename on
a local disk, so finalizing an upload of any size costs no second copy) and always consumes the source.
The writer then saves the asset with `Asset::withGraphContent($fileObject)`. The graph observer links
that blob as the original, or versions it on an existing asset, without reading the Library path. The
observer still ingests a file it finds at the Library path when no blob was handed over, for rows
written before slice 3, and logs `legacy_library_ingest` when it does.

**Ingest before attach.** A writer ingests in its own transaction and attaches later, so a concurrent
delete of another asset with the same bytes can reap the FileObject row in between. The observer
therefore calls `ContentStore::retain()` inside the attach transaction, after the asset row lock and under
the blob lock. It reads the live row with a locking read (`FOR KEY SHARE` on PostgreSQL, a shared lock on
MySQL/MariaDB), so a concurrent `AssetDeletionService` transaction holding that row `FOR UPDATE` is ordered
against it. It returns the live row, or recreates it while the blob is intact. Tombstone cleanup unlinks
only when no row names the path under the same blob lock, so the blob survives. Once the blob is gone,
`retain()` fails loudly with `blob_missing` rather than attaching nothing. `BlobLockRaceTest` covers both
winners with real two-process PostgreSQL races.

**Physical cleanup waits for the outermost commit.** `AssetDeletionService::delete()` writes its tombstones
inside the caller's transaction, but purges bundles and unlinks blobs only after the outermost transaction
commits (`DB::afterCommit`); with no transaction open it runs at once. A folder delete that fails part-way
therefore rolls back its tombstones with its rows and never removes bytes those rows still name. A cleanup
failure after commit is logged and stays on its tombstone; `dam:storage:reap` retries every pending
tombstone hourly, each on its own. A delete owns every file named after its asset (blobs no other row uses,
derivatives, custom thumbnail, cover art, 3D captures, web-page bundle, editor-resource files, the legacy
Library file) and every row (share links and editor documents included), whichever user path deletes it.

**Names.** `dam_assets.path` is UNIQUE and is the only thing a new name collides with.
`Support/LibraryPaths` picks `name(1).ext` suffixes from the database. An upload that loses its free name to
a concurrent insert of the same path allocates again, up to five times, keeping the blob it already
ingested. Upload endpoints check only `ContentStore::canStage()`; folder operations make no filesystem check.

**Folder operations** run in the database only, under `DirectoryTreeLock`. A copy or move reads its
destination folder, and derives the new paths, inside that lock, so a concurrent rename cannot leave an
asset with a stale prefix; a multi-folder copy is one transaction, so a failure commits nothing. A copy
locks every source asset row, in ascending id order, before it inserts anything, and takes each copy's
descriptors and FileObject from those locked rows: lock order tree lock, then asset rows, then FileObjects
(through the attachment foreign keys), the same order a delete takes, so a copy and a delete never deadlock.
- Rename and move rewrite `dam_assets.path` prefixes, guarded by a trailing slash and `LIKE`.
- A copy inserts new asset rows that share the source's current original FileObject. As before this
  slice, versions and renditions are not copied.
- A delete removes each asset through `AssetDeletionService`, so its blobs are tombstoned by reference
  count. A blob that a copy elsewhere still uses stays.

**Integrity.** After slice 3 a Library file is never the truth about current content: an edit lands only
in a blob. `dam:storage:repair --verify` therefore only reports, and never ingests Library bytes, so it
cannot revert an edit. `dam:storage:check` no longer reports Library paths missing on disk. Files that
pre-slice-3 deletes left under `assets/` with no row naming them (they also forced `name(1)` onto re-uploads,
because the old namer counted files on disk) are reported by `dam:storage:orphans`; its operator-run `--force`
removes only those an intact blob already holds, and never touches a dot-folder. Each removal is re-checked
under that blob's lock just before the unlink (same bytes, the blob still re-hashes, no row names the path,
an editor resource's `details` included,
compared case- and normalization-insensitively because the Studio's disk is APFS) and is never queued as a
tombstone, so no later retry can act on stale evidence. Likewise a delete keeps a cover or 3D capture another
asset's metadata still names: copies inherit those paths.

**Known gaps, unchanged.** `dam_asset_directory` is many-to-many, but `path` records one folder. Assets
written before slice 3 keep their old off-tree paths as plain names. The already-run purge migrations
(`2026_09_21_000001_purge_mind_maps`) are the last code that builds physical paths by hand; migrations
are immutable, so they stay as written. The layered image editor's document resources
(`ImageEditorResource`, stored under `assets/.editor/<asset id>/`) were the one remaining physical store; the
amendment below moves them onto FileObjects.

## Amendment 2026-09-25 — editor resources are blobs

A layered image editor document reopens later, so the images it names (its base, an uploaded background,
the copies a save-as-new makes) are durable user data. They now live where every other durable byte does.

- **A resource names a FileObject.** `dam_image_editor_resources.file_object_id` references
  `dam_file_objects` with RESTRICT (NO ACTION on SQL Server). A document's base is the asset's current
  original blob, not a copy of its bytes. An upload is ingested with `putLocal()` and attached in the next
  transaction: asset row lock, then `retain()`. A save-as-new gives the new asset rows naming the same
  FileObjects, so nothing is copied. `path` now only names a pre-blob working file and is null on new rows.
- **Reads** go through `ContentStore::deliver(..., sameOrigin: true)`. The editor draws these images onto a
  canvas and exports it, and a cross-origin redirect to an S3 temporary URL would taint that canvas, so they
  always stream from the app's own origin.
- **One definition of "referenced".** `FileObject::referenced()` / `isReferenced()` means an attachment, a
  rendition output or an editor resource names the row. Every deletion site uses it: `AssetDeletionService`
  when it deletes an asset and when it reaps tombstones, and attachment removal. `delete()` reads the asset's
  resource blobs before its resource rows go. A blob that only a copy's editor document still names stays. The
  RESTRICT key is the backstop: a site that forgets fails loudly instead of orphaning a document's image. A
  new table that references FileObjects joins the scope, never a new hand-written check.
- **S3.** `MoveDamAssetsToS3` walks every FileObject, so resource blobs move with everything else.
- **Pre-blob rows.** Migration `2026_09_25_000501` and `dam:storage:repair --force` run
  `StorageIntegrity::repairEditorResources()`. Each working file is copied in with `putStored()`, which reuses
  an intact blob, so a base lands on its asset's original. The row is then attached under the asset row lock
  and `retain()`. Each row commits on its own, so the run can resume and is safe to repeat. Only paths under
  `assets/.editor/` are read. A row whose file is missing stays unresolved; `dam:storage:check` reports it as
  `editor_resources_without_blob`, and its image answers 404. The old files stay. Once no resource lacks a
  blob, slice 4's reclaim removes `assets/.editor/`.

## Amendment 2026-09-25 — slice 4: reclaim

`dam:storage:reclaim` is `StorageIntegrity::reclaim()`, built on the orphan machinery, and
`dam:storage:backup` takes the pair it needs. Both are operator-run and never scheduled. The runbook is
`docs/ops/storage-integrity.md`, "Reclaim (slice 4)".

- **Three sets.** Library copies: every file under `assets/` outside `.objects`, `.staging` and `.editor`,
  whether an asset names it or not. Legacy editor working files: everything under `assets/.editor/`. Path-keyed
  derivatives: `thumbnails/assets/` and `preview/<size>/assets/`. Then the directories left empty inside those
  roots. The blob store, staging, the sha-keyed derivatives, model captures, covers, custom thumbnails and
  bundles are never touched.
- **Blob-verified deletion.** A Library copy or editor working file is deleted only when a FileObject with its
  sha256 names an intact blob. A file whose bytes no blob holds is the only copy of them and stays for a person
  (`kept_no_blob`). Under `--force` each deletion is decided again under that blob's lock, just before the
  unlink: the same size and sha256, a blob that re-hashes to it, no protecting row. This is the orphan command's
  order.
- **Protecting and logical families: one rule for Library copies and editor working files.** A FileObject
  locator on the asset disk, an editor resource's `path` and any string in a resource's `details` protect a file:
  each can still lead a reader to it. `dam_assets.path` and `dam_image_editor_documents.content_path` are logical
  names wherever they point, including a pre-slice-3 edited asset's `assets/.editor/results/` path. A Library
  path names no file on disk. Since slice 2 every read goes through the blob, and the editor sets the content
  path to the asset's Library path and uses it only as a lookup key; no reader opens that file. The blob check
  still keeps any file whose bytes no blob holds.
- **`.editor` path clearing.** An editor working file that resource rows name goes only when every one of those
  rows already names a blob with the file's bytes. The rows' `path` is cleared in the transaction that unlinks
  the file, under the ADR lock order: the rows' assets in ascending id order (`Asset::lockedForGraph()`), then
  the blob. Only those rows' `path` is left out of the naming check; their `details` still protect. Each update is
  guarded by the old path.
  If the unlink fails, the transaction rolls back and the rows keep their path. A row with no blob keeps its
  file, because that file is its only copy.
- **Derivatives are exempt from the blob and backup checks.** They are derived data: no hash and no blob check.
  One stays only while a row, or any string in an asset's `meta_data` such as a pre-slice-2 poster's
  `thumbnail_path`, names it. That is read again for the one path just before its unlink (`kept_now_named`).
- **The backup-coverage gate.** `--force` needs `--backup=<name>`, a pair `dam:storage:backup` took: a plain name
  under `storage/dam-backups/`, a non-empty dump and an archive, on a local asset disk.
  - The archive must list every Library copy and editor working file the run would delete, as a regular file at
    its current size.
  - It is read header by header, whichever tar wrote it (`TarListing`: ustar, GNU long names, PAX), never parsed
    from `tar -tv` text.
  - It is decompressed once through zlib's gzip decoder. It must be one gzip stream ending in a trailer whose
    CRC-32 and length match, with only zero bytes after it, and a tar that reaches its end-of-archive block with
    every header checksum right. `gzread()` alone accepted an archive cut in its trailer.
  - Names are compared exactly, then NFC-composed, because macOS bsdtar can store another normalization than the
    disk lists.

  Any failure refuses the run before any change. The rollback is `dam:update:restore <name>`.
- **Direct unlinks, never tombstones.** A deletion is never queued for `dam:storage:reap`, whose retries would
  unlink without these checks. A failed unlink is reported and nothing is left queued, so a rerun repeats every
  check.
- **Unknown dot-folders are never deleted.** Dot-folders follow an allow-list of legacy write areas verified from
  code and git history. It holds only `assets/.models/`, where ModelPrimaryViewController wrote corrections until
  slice 3.
  - A file in any other dot-folder at any depth, or named with a leading dot, is counted under
    `skipped_dot_folder` for a person, and its directories are never pruned.
  - The derivative mirrors follow the same policy, read as the asset path they mirror. So only mirrors of the two
    legacy areas the reclaim covers go: `.models`, and `.editor`, whose `results/` held pre-slice-3 edited assets.
  - `.DS_Store` goes only with a directory it is the last thing in.
  - A directory whose name is not valid UTF-8 is never removed, and neither is its `.DS_Store`.

As the Consequences section foresaw, after a reclaim `assets/` no longer mirrors the Library tree and a Library
path names no file on disk.
