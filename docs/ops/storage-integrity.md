# Storage integrity

The invariant (ADR 0009): every `FileObject` names its own content-addressed blob at
`assets/.objects/<sha[0:2]>/<sha256>[.<ext>]`, written once and never moved or renamed. Folder operations,
copies and moves can only touch the user-visible Library path (`Asset.path`); they can never make a
`FileObject` locator stale. A row that still names something else (a legacy per-asset path, a path a folder
move rewrote) is not yet corrupt data — it is a pointer this service repoints or copies once, the same
detection/repair split ADR 0004 uses for the directory tree.

## Detection

`dam:storage:check` runs hourly from the scheduler and prints one JSON line:

```json
{"checked_at":"…","file_objects":812,"assets":790,"remote_objects_skipped":0,"clean":false,"findings":{"non_object_locators":{"count":2,"sample":[41,58]},"missing_blobs":{"count":0,"sample":[]},"assets_without_original":{"count":1,"sample":[203]}}}
```

Exit code 1 means `clean` is false. The same record is appended to
`storage/logs/storage-integrity.jsonl` (`event: integrity.checked`, level `warning` when not clean). Each
finding's `sample` lists at most 20 ids; `count` is the true total. Blobs are stat-checked only on local
disks — a `FileObject` on a remote disk is counted in `remote_objects_skipped`, never probed.

What each finding means:

- `non_object_locators` — a `FileObject.path` that is not `objectPath(sha256)` (a legacy per-asset path, or a
  locator a folder move rewrote before ADR 0009). Repaired by repointing to an existing blob, or copying the
  bytes from wherever they still exist into the blob store.
- `missing_blobs` — the locator is a correct object path, but nothing exists there. Repaired the same way as
  above: an intact copy elsewhere is hashed and copied in.
- `assets_without_original` — an `Asset` with a Library path (`path`) but no `AssetFile` row with
  `role = original`: a row the pre-ADR-0009 copy jobs inserted with raw SQL, or an asset whose file was missing
  when it was created. Since slice 2 such an asset serves nothing (every read goes through its original), so
  this finding is user-visible: 404s and placeholder thumbnails. Since slice 3 it is reported, never
  backfilled from a Library file (see Repair).
- `editor_resources_without_blob` — a layered image editor resource (`dam_image_editor_resources`, sampled
  by its uuid) with no `file_object_id`. It is a pre-blob row whose `assets/.editor/` working file could not
  be ingested, because it is missing or its path lies outside the editor area. The image answers 404, so the
  document that names it cannot load that layer. Repair ingests any row whose file has come back; otherwise
  the row needs a human.

Since slice 3 (folders are metadata) no writer creates a Library-path file, so a Library path with nothing
on disk is the normal state and is not a finding. Files still at older Library paths are what
`dam:storage:reclaim` removes (see Reclaim (slice 4)).

`library_orphans` is informational and never part of `clean` or the exit code:
`{"count":…,"bytes":…,"sample":["assets/…"],"invalid_utf8":…,"complete":true}` counts files under `assets/`
that no row names (see Orphan Library files). `invalid_utf8` counts names that are not valid UTF-8: they are
never looked up or counted as orphans. `complete` is `false` when listing the tree or looking up its names
failed, so a partial or zero count is never read as the whole. It is computed on local disks only (`null` on
a remote disk), so the hourly check never fails on it; a count that grows after the one-off cleanup means
some writer still puts files in the Library tree.

The command never writes anything besides its own JSONL line.

## Deleting an item removes its files

Every user delete path (single, mass, API, folder delete, demo-data clear) goes through
`AssetDeletionService::delete()`. In one transaction it removes the asset's rows (versions, renditions,
attachments, share links, layered-editor documents and resources) and writes one deletion tombstone per file
it owned:

- each blob no other row still uses, and its sha-keyed derivatives;
- the custom thumbnail;
- audio cover art (`covers/<id>.*`) and 3D model captures (`thumbnails/models/<id>-<sha12>.jpg`). A copy
  inherits its source's `meta_data`, so it names the source's cover and capture. Each of these files is
  therefore kept while another asset's `cover_art_path` or `thumbnail_path` still names it, and goes with the
  last asset that does, including a copy that names another asset's file;
- every file in the asset's editor folder `assets/.editor/<id>/`, whether this asset's rows name it or not,
  and each other file under `assets/` that the asset's editor resources (their path or `details`) or
  documents (their content path) name. Each is kept while a remaining row in the five families the orphan
  check uses still names it: a save-as-new copies a resource's `details` unchanged, so a copy on another asset
  can name a legacy AI draft's input or mask, in this asset's editor folder or elsewhere. That file then goes
  with the last asset whose rows name it. The comparison ignores case and Unicode normalization. Blob-store
  and staging paths are never taken from editor rows. A resource's own image is a blob (ADR 0009, "editor
  resources are blobs"), counted in the first bullet, so these files are only pre-blob working copies;
- the legacy Library-path file.

The files are unlinked only after the outermost transaction commits, so a delete that fails part-way never
removes bytes a restored row still names. The web-page bundle (`bundles/<id>/`) is purged after the commit
too. If anything is still under `bundles/<id>/` after the purge, each remaining file gets a tombstone. The
reaper retries those tombstones and removes the bundle's empty folders once the last file is gone.

An unlink that fails after the commit stays on its tombstone (`attempts`, `last_error`) and is retried by
`dam:storage:reap`, which the scheduler runs hourly. It retries every pending tombstone on its own, so one file
that still cannot be removed never strands the others, prints `{"cleared":…,"pending":…}` and exits 1 while any
tombstone is still pending. A tombstone whose blob an unreferenced `FileObject` row still names stays pending
on purpose (`unreferenced_file_object`) until that row goes.

```
/opt/homebrew/bin/container exec mothership-private-fpm php artisan dam:storage:reap
```

## Orphan Library files

Before slice 3 some operations left files under `assets/` that no row names: a delete queued only an asset's
off-tree `assets/.models/…` copy after a 3D primary-view correction, and on pre-slice-3 main such a file also
forced `name(1).ext` onto a later re-upload of the same name. Since slice 3 names come only from
`dam_assets.path`, but renaming or moving an older asset still leaves its file at the old name.

`dam:storage:orphans` lists every file under `assets/` whose path has no segment starting with `.` (nothing in
`.objects`, `.staging`, `.editor`, `.models` or any other dot-folder, and no dot-file) and that no
`dam_assets.path`, `dam_file_objects.path`, `dam_image_editor_resources.path`,
`dam_image_editor_documents.content_path` or `dam_image_editor_resources.details` names. `details` is JSON: a
legacy AI draft kept its working files there (`input`, `mask`, `reference`, `source`, `guidance`), so every
string in it, at any key or depth, names each path it contains. Names are compared the way the Studio's APFS
asset disk compares them: case-insensitively and Unicode-normalization-insensitively (NFC, then lower case). A
row naming `Working.png`, or an NFD spelling, therefore protects the on-disk `working.png` or NFC file. This
only ever protects more, so it is also safe on a case-sensitive disk.

```
/opt/homebrew/bin/container exec mothership-private-fpm php artisan dam:storage:orphans
/opt/homebrew/bin/container exec mothership-private-fpm php artisan dam:storage:orphans --force
```

Without `--force` it changes nothing and prints one JSON line: `orphans`, `blob_backed`, `unbacked`,
`deleted`, `kept`, `failed` and `invalid_utf8` (each `count`, `bytes` and a `sample` of up to 20 paths), and
`files`, one entry per orphan with its `size`, `sha256`, `file_object_id`, `blob_backed` and `action`. A file is
hashed (streaming) only when some `FileObject` has its exact size; `blob_backed` is true when a `FileObject`
with that sha256 names an intact blob. Each orphan no blob holds is also printed as
`No blob holds these bytes; review by hand: <path>`.

A name that is not valid UTF-8 is set aside before any database query, because PostgreSQL refuses it as a
text parameter and no row can name it. It is never deleted, and the rest of the run goes on. It is listed
under `invalid_utf8` and in `files` with `action: kept_invalid_utf8` and an `encoded_path` (each segment
percent-encoded, byte for byte). It is also printed as
`Name is not valid UTF-8; never deleted, review by hand: <encoded path>`.

With `--force` it deletes only blob-backed orphans. It never writes a deletion tombstone for an orphan: a
tombstone is retried hourly by `dam:storage:reap`, which would unlink without re-checking any of the following.
Each deletion is decided under the orphan's blob lock (the lock tombstone cleanup takes before it unlinks a
blob), so the blob cannot be reclaimed mid-check. Everything the report found is checked again, in this
order, and any change keeps the file:

1. The orphan still exists with the same size and re-hashes to the same sha256. Otherwise `kept_changed`.
2. A `FileObject` with that sha256 names an intact blob that re-hashes to it, so no bytes are lost.
   Otherwise `kept_blob_unverified`.
3. No row in the five families above names the path, with the same case and normalization rule. Otherwise
   `kept_now_named`.

The file is then unlinked directly (`deleted`). If the unlink fails, the file is reported as
`kept_unlink_failed` and printed as `Could not delete; nothing was queued, rerun --force: <path>`. Nothing is
left queued, so rerunning `--force` repeats every check. `kept` counts the blob-backed orphans a `--force` run
kept for any of these reasons. The run then removes every empty directory under `assets/` outside the
dot-folders, because a folder is a database row only. An orphan no blob holds is never deleted
(`kept_no_blob`): a person decides whether to upload it again.

Exit code 1 means orphans or `invalid_utf8` names remain (a dry run: any; `--force`: the kept ones). Each run
appends `integrity.orphans_reported` or `integrity.orphans_reclaimed` to
`storage/logs/storage-integrity.jsonl`. A `--force` run also appends one `integrity.orphan_deleted` receipt
per deleted file (path, size, sha256, file_object_id). Every record of a run carries the same `run_id`.

A stale file at a path an asset does name (an old Library file under a re-uploaded asset's name) is not an
orphan: `dam:storage:repair --verify` reports it as `divergent`, and `dam:storage:reclaim` removes it once a
blob holds its bytes.

Known limit (cost, not optimized): the report streams each family once, but the fresh check in step 3 runs per
orphan. It is one `LOWER(column) LIKE` query for each of the four path columns, which no index serves (each
is a full scan), plus a read of every resource's `details`. Reclaiming M orphans against N rows therefore
costs O(M×N), and each check runs while that orphan's blob lock is held. An asset delete makes the same check
for each of its editor files. At the Studio's scale when this shipped (64 Library files, 22 orphans, a few
hundred rows) that is at most tens of thousands of row reads per `--force` run, so it is left as is. If the
orphan set or the row count grows by orders of magnitude, the fix is an indexed normalized-path lookup that
keeps the fresh per-file check; a cached report is not a substitute.

## Reads and derivatives (slice 2)

Every read of original or attachment bytes goes through `ContentStore` (`current()`, `open()`,
`localPath()`, `deliver()`, `temporaryUrl()`): the blob a `FileObject` names, never the Library path. A read
that finds no bytes (no original, a missing or wrong-size blob) answers 404 on HTTP, adds a `.MISSING.txt`
entry to a ZIP, or is caught by the job, and always appends one `content_unavailable` record (ids and
reason, no paths) to `storage/logs/content-store.jsonl`. Set `DAM_CONTENT_STORE_VERBOSE_LOG=true` to also
record every staged or delivered read.

Image derivatives are keyed by content: `derivatives/<sha[0:2]>/<sha256>/<recipe>.<ext>` on the asset disk,
with the recipes `thumbnail`, `preview-<size>` and `poster` (video, panorama and PDF stills, always `.jpg`).
A folder move never stales them, copies share them, and they are removed only with their blob. The older
path-keyed `thumbnails/assets/…` and `preview/<size>/assets/…` files are no longer read or written;
`dam:storage:reclaim` removes them.

## Repair (locators and originals)

The sequence below is for the Studio runtime, the same `container exec` / `container stop` / `container start`
calls `docs/ops/directory-tree-integrity.md` uses.

1. Dry run, then repair:

   ```
   /opt/homebrew/bin/container exec mothership-private-fpm php artisan dam:storage:repair
   /opt/homebrew/bin/container exec mothership-private-fpm php artisan dam:storage:repair --force
   ```

   Without `--force` the command only prints the plan (`repointed`, `copied`, `unresolved`,
   `originals_unresolved` counts and samples) and warns that nothing changed. With `--force` it repoints or
   copies every resolvable `FileObject` locator onto its content-addressed blob. An asset with no original is
   counted under `originals_unresolved`: since slice 3 a Library file is never ingested as an asset's content,
   because an edit made since then lives only in a blob. It is idempotent: a second run repeats only the rows
   it could not resolve, listed under `unresolved_rows`.

   The same run also ingests pre-blob image editor resources, which migration `2026_09_25_000501` already
   ingested once on deploy. Its result is the `editor_resources` key, with `ingested` and `unresolved` counts and
   samples of resource uuids. Each `assets/.editor/` working file is copied into its blob, reusing an existing
   blob for the same bytes, and the row is attached to it. The old file stays until `dam:storage:reclaim`
   removes it and clears the row's `path`.

2. Exit code 1 means the result still has `unresolved`, `originals_unresolved` or
   `editor_resources.unresolved` rows after the run (or, in a dry run, that it would). Those need a human:
   `unresolved_rows` gives each FileObject row's disk, path and sha256 so the bytes can be traced by hand, and
   an unresolved editor resource's `path` names the working file that was expected.

3. Confirm with `dam:storage:check` through the same `container exec mothership-private-fpm php artisan` form.

This repair never touches `Asset.path`, a Library file or an `AssetFile` link — only `FileObject` rows and an
editor resource's `file_object_id`. It is
safe to run at any time; it makes no assumption about whether Library content still matches its original.

## Verify (Library content vs. original)

`dam:storage:repair --verify` re-hashes every asset's current Library file (where one still exists) against
its original `FileObject`, in id order, and is resumable: it writes a cursor to
`storage/app/storage-integrity-verify.json` after every chunk, and a run stopped by `--limit` or an
interruption picks back up from `after_id` on the next invocation. When the result's `complete` is `false`,
rerunning the same command resumes instead of starting over.

```
/opt/homebrew/bin/container exec mothership-private-fpm php artisan dam:storage:repair --verify
/opt/homebrew/bin/container exec mothership-private-fpm php artisan dam:storage:repair --verify --limit=5000
```

`--verify` alone only reports: `totals.divergent` counts Library files whose current bytes no longer match
their original (the old in-place editor rewrote a Library file without versioning it), and `totals.failed`
counts assets it could not check. Exit code 1 when either is non-zero — a dry `--verify` run that finds
divergence is a failing check, not a clean report.

`--verify` is report-only since slice 3, and `--verify --force` is refused with exit code 1. Before slice 3
it ingested each divergent Library file as a new version; it was the slice-2 deploy gate and ran clean on the
Studio on 2026-09-23. Since slice 3 an edit lands only in a blob, so a Library file is older than its asset's
current content and ingesting it would revert that edit. Divergence found now means an old Library file that
`dam:storage:reclaim` removes when a blob holds its bytes; it is never a newer truth.

The JSONL record for each chunk (`event: integrity.verify_chunk`) and for the final pass
(`integrity.verified` or `integrity.verify_paused`) is appended to `storage/logs/storage-integrity.jsonl`,
the same file `dam:storage:check` writes to.

## Reclaim (slice 4)

`dam:storage:reclaim` deletes what the asset disk no longer needs once every byte lives in a blob (ADR 0009,
slice 4). It is operator-run only and never scheduled. Without `--force` (or with `--dry-run`) it changes
nothing and reports what it would do.

### What it removes

Three sets, all on the asset disk:

1. **Library copies** (`library`): every file under `assets/` except under `assets/.objects/`,
   `assets/.staging/` and `assets/.editor/`, whether an asset still names it or not. This is a superset of what
   `dam:storage:orphans --force` removes. Dot-folders follow an allow-list of legacy write areas: only
   `assets/.models/` (3D primary-view corrections before slice 3) counts as Library copies. A file in any other
   dot-folder, at any depth, or with a name that starts with `.`, is never deleted. It is counted under
   `skipped_dot_folder` for a person to decide. `assets/.mindmaps/` is not on the list: the Mind Maps purge
   removed that tree, so anything there now is unexplained.
2. **Legacy editor working files** (`editor`): every file under `assets/.editor/`.
3. **Path-keyed derivatives** (`derivatives`): every file under `thumbnails/assets/` and
   `preview/<size>/assets/`, the pre-slice-2 mirrors `thumbnails/<asset path>[.webp|.jpg]` and
   `preview/<size>/<asset path>[.webp]`. Nothing has read or written them since slice 2. The mirrors follow the
   asset tree's dot-folder policy, read as the asset path they mirror. A dot segment is allowed only where it
   mirrors one of the two legacy areas this reclaim covers:
   - `assets/.models/`, mirrored at `thumbnails/assets/.models/…` and `preview/<size>/assets/.models/…`;
   - `assets/.editor/`, mirrored at `thumbnails/assets/.editor/…` and `preview/<size>/assets/.editor/…`. A
     pre-slice-3 edited asset's path was `assets/.editor/results/<uuid>.png`, so its thumbnail sits under
     `thumbnails/assets/.editor/results/`.

   Any other dot-folder or dot-file in a mirror, including a dot segment inside one of those two areas, is counted
   under `skipped_dot_folder` and never deleted.

It then removes every directory those roots are left with empty, deepest first, on a local disk only. The same
dot-folder policy applies to directories: one in a skipped dot-folder is never removed, even when empty. The roots
themselves (`thumbnails/assets`, `preview/<size>/assets`) stay. A Finder `.DS_Store` goes only when it is the
last thing left in a directory being removed. A directory whose name is not valid UTF-8 is never removed, and
neither is the `.DS_Store` in it. One that holds nothing else is reported under
`pruned_directories.retained_invalid_utf8` for a person.

It never touches `assets/.objects/**` (the blobs), `assets/.staging/**`, `derivatives/**`,
`thumbnails/models/**`, `covers/**`, `custom-thumbnails/**`, `bundles/**`, or anything outside those roots.

### When a file stays

A Library copy or an editor working file is reclaimable when all of these hold, in this order:

- Its name is valid UTF-8. Otherwise it is `kept_invalid_utf8`, printed percent-encoded as the orphan report
  prints it.
- No protecting row names it. Otherwise it is `kept_referenced`, and its `named_by` lists the families that
  name it. Names are compared the way PathReferences compares them: case-insensitively and
  Unicode-normalization-insensitively. One rule decides which rows protect, the same for Library copies and
  editor working files:
  - The protecting rows are a `dam_file_objects.path` on the asset disk (an unrepaired legacy locator still reads
    the file), a `dam_image_editor_resources.path`, and any string in a resource's `details`.
  - `dam_assets.path` and `dam_image_editor_documents.content_path` are names only, wherever they point, including
    a pre-slice-3 edited asset's `assets/.editor/results/…` path: every read goes through the blob, and the editor
    uses the content path as a lookup key. They appear in `named_by` but never keep a file. The blob check below
    still keeps any file whose bytes no blob holds.
  - For an editor working file, the resource rows whose `path` names it are cleared together with it, but only
    when every one of them has a `file_object_id` whose FileObject has the file's size and sha256 and an intact
    blob. Only those rows' `path` is set aside: their `details` still protect. A row with no `file_object_id`
    keeps its file (`kept_referenced`), because that file is its only copy. A Library copy that a resource's
    `path` names always stays.
- A FileObject with its sha256 names an intact blob. The file is hashed (streaming) only when some FileObject
  has its exact size. Otherwise it is `kept_no_blob`: no blob holds those bytes, so the file is never deleted,
  and it is listed for a person.

A derivative is derived data, so there is no blob check and no hash. It stays (`kept_referenced`) while any
string in any asset's `meta_data` names it, for example a pre-slice-2 video poster's `thumbnail_path`, or while
any of the five families names it. Every other derivative is reclaimable.

With `--force`, each Library copy and editor working file is decided again under its blob lock, just before a
direct unlink. It is never queued as a deletion tombstone, so no hourly retry acts on stale evidence. The
checks run in the order the orphan command uses, and any change keeps the file:

1. The file still has its size and re-hashes to its sha256. Otherwise `kept_changed`.
2. A FileObject with that sha256 names an intact blob that re-hashes to it. Otherwise `kept_blob_unverified`.
3. No protecting row names it now. Otherwise `kept_now_named`.
4. The unlink. If it fails, the file is `kept_unlink_failed` and nothing is queued.

For an editor working file with rows to clear, one transaction first locks those rows' assets in ascending id
order (`Asset::lockedForGraph()`) and then the blob: the ADR 0009 lock order. Inside it, the same checks run,
with only the path of the rows being cleared left out of the naming check. Those rows must still be exactly the
ones naming the file and must still hold that blob. Each row's `path` is set to null only where it still holds
the path that was read. The file is unlinked inside the transaction, which then commits. If the unlink fails,
everything rolls back, the rows keep their path, and the file is `kept_unlink_failed`.

A derivative takes no lock. Under `--force`, just before its unlink, every naming family is read again for that
one path, including every string in every asset's `meta_data`. A reference added since the plan keeps the file
(`kept_now_named`). A check that throws keeps its file as `kept_error` and logs why.

### The gate and the sequence

Before any change the command refuses, exits 1 and changes nothing (no file, no row, no directory) when:

- `--dry-run` and `--force` are both given;
- `--force` comes without `--backup`. The message names `dam:storage:backup` as the way to take a backup;
- `--force` runs on an asset disk that is not local, because the backup pair covers only a local tree;
- `--backup=<name>` is not a plain directory name (letters, digits, `_` and `-`, which is what
  `dam:update:restore` accepts, so `/`, `..` and an empty name are refused), or no such directory exists under
  `storage/dam-backups/`;
- the pair lacks a non-empty `dam-tables.sql` or a `dam-files.tgz`;
- the archive fails verification. `TarListing` decompresses it once through zlib's gzip decoder, extracting
  nothing, and accepts it only when all of these hold:
  - it is one gzip stream ending in its trailer, whose CRC-32 and length zlib checks against every decompressed
    byte, entry data included;
  - nothing but zero bytes follows that stream;
  - every tar header's checksum matches;
  - the tar reaches its end-of-archive block.

  Otherwise the refusal is `backup_unreadable`, naming `archive_truncated`, `archive_corrupt` or
  `archive_unreadable`. Headers are read in all three formats: ustar names, GNU long names and PAX records.
- the archive does not list every Library copy and editor working file the run would delete, as a regular file
  at its current size. Names are compared exactly, then in composed (NFC) form, because a tar can store another
  Unicode normalization than the disk lists. Entry data is not compared with the disk. `refused.missing` and
  `refused.wrong_size` sample what was not covered. Derivatives are exempt, because the archive does not hold
  them and the sha-keyed derivatives are made again from the blobs.

A dry run given `--backup` checks the pair the same way, so the backup can be proved before the approval.

Run it on the Studio in this order:

1. Put the app in maintenance mode. Web requests stop, and the queue workers and the scheduler pause after the
   job they are running:

   ```
   /opt/homebrew/bin/container exec mothership-private-fpm php artisan down
   ```

2. Back up the DAM tables and `assets/` together. The command prints one JSON line with the pair's `name`, both
   file paths and their byte sizes, and exits 1 on any failure. The archive needs about as much free space as
   `assets/` holds, because media barely compresses:

   ```
   /opt/homebrew/bin/container exec mothership-private-fpm php artisan dam:storage:backup
   ```

3. Dry run against that backup:

   ```
   /opt/homebrew/bin/container exec mothership-private-fpm php artisan dam:storage:reclaim --dry-run --backup=<name>
   ```

4. The operator reviews the report (`sets`, the `kept_*` lists, `skipped_dot_folder`, `backup.covered`) and
   approves the deletion.

5. Reclaim:

   ```
   /opt/homebrew/bin/container exec mothership-private-fpm php artisan dam:storage:reclaim --force --backup=<name>
   ```

6. Confirm the invariant:

   ```
   /opt/homebrew/bin/container exec mothership-private-fpm php artisan dam:storage:check
   ```

7. Leave maintenance mode:

   ```
   /opt/homebrew/bin/container exec mothership-private-fpm php artisan up
   ```

### Output and exit codes

stdout is one JSON line, then human warnings:

- `run_id`, `dry_run`, `disk`.
- `refused`: null, or the `reason` and `message`, and `missing` and `wrong_size` samples for an incomplete backup.
- `backup`: null, or its `name`, `dump_bytes`, `archive_entries` and `covered`.
- `clean`.
- `sets`: for `library`, `editor` and `derivatives`, the reclaimable `count`, `bytes` and `sample` (at most 20
  paths). `reclaimable` totals them.
- `deleted`, and each of `kept_invalid_utf8`, `kept_referenced`, `kept_no_blob`, `kept_changed`,
  `kept_blob_unverified`, `kept_now_named`, `kept_unlink_failed` and `kept_error`, with its `count`, `bytes` and
  `sample`.
- `skipped_dot_folder`.
- `editor_paths_cleared`: the count and a sample of resource uuids.
- `pruned_directories`: the count, a sample, and how many `finder_files` went with them. Also
  `retained_invalid_utf8`: the count and a percent-encoded sample of directories whose names are not valid UTF-8.
  Each held nothing but, at most, a `.DS_Store`, yet was left in place.
- `files`: one entry per Library copy, editor working file and derivative, with `path` (plus `encoded_path` for
  a name that is not valid UTF-8), `set`, `size`, `sha256`, `file_object_id`, `named_by`, `action` (`reclaimable`
  in a dry run, `deleted` or a `kept_*` reason) and, for an editor file, the `editor_rows` it clears.

These are also printed as warnings: each file kept for a person, a sample of the `skipped_dot_folder` files, each
retained directory, and the dry-run footer: `Dry run: nothing was changed. N files (B bytes) are reclaimable;
rerun with --force --backup=<name>.`

Exit code 0 means nothing is reclaimable and nothing is kept for a person, so `clean` is true. Exit code 1 means
something remains: a dry run with work, a `--force` run that left something for a person, or a refused gate.
Left for a person means a kept file, a skipped dot-folder file or a retained directory. `kept_referenced` never
makes the exit code 1 on its own: such a file is still in use and needs nobody. Every other `kept_*` reason,
`skipped_dot_folder` and `pruned_directories.retained_invalid_utf8` does. A second `--force` deletes nothing new.

### Receipts

Every record of a run in `storage/logs/storage-integrity.jsonl` carries the same `run_id`:

- `integrity.reclaim_deleted`, one per deleted file, before the summary: `set`, `path`, `size`, `sha256`,
  `file_object_id`, and `asset_ids`, the assets whose rows named the file (a Library path, a document's content
  path, or the cleared editor rows). An editor file also carries `editor_rows_cleared`. A `.DS_Store` removed
  with its directory is recorded with `set: finder`.
- `integrity.reclaimed` after a `--force` run, or `integrity.reclaim_planned` after a dry run: the summary,
  without `files`.
- `integrity.reclaim_refused` for a refused gate, with the flags and the refusal.

### Rollback

`dam:update:restore <name>` restores the pair: it replays the dump and swaps the archived `assets/` tree in. The
replaced tree is kept aside, and every FileObject's blob is re-verified. Run it in maintenance mode, from an
interactive Studio terminal, because it prompts for confirmation (`-it` keeps the prompt attached):

```
/opt/homebrew/bin/container exec -it mothership-private-fpm php artisan dam:update:restore <name>
```

Derivatives are not in the archive. The sha-keyed ones are made again on demand, and the path-keyed ones were
no longer read.

### Consequence

After a reclaim, `assets/` no longer mirrors the Library tree in Finder, so files cannot be found there by name.
Apart from the files it kept, a Library path names no file on disk: the bytes are in `assets/.objects/`, found
through each asset's FileObject. This is accepted (ADR 0009). The database dump and the blob store must always
be backed up and restored together.

### Cost at the Studio's scale

Measured on 2026-09-25, before slice 3: 160 files and 3.7 GB under `assets/`, of which 75 blobs and 1.9 GB are
in `.objects/`; 104 files in `thumbnails/` and one in `preview/`. The listing and the name lookups are linear:
one stream of each row family per set. Each fresh naming check is one query per path column plus a read of every
resource's `details`, as for the orphan command. A derivative's check also reads every asset's `meta_data`: about
100 derivatives against 58 assets on the Studio. At scale the fresh checks cost files × rows, the same known limit
as the orphan command's.

Hashing dominates the cost. A dry run hashes the Library copies once, about 1.8 GB. A `--force` run hashes them
again under the lock, and re-hashes each backing blob too, about 5.4 GB in all. PHP streams sha256 at about
400 MB/s on Apple silicon, so this is seconds to about a minute, depending on how fast the container reads the
volume. `dam:storage:backup` gzips all of `assets/`, which is about a minute at the 65 MB/s `tar -czf` managed
locally. Verifying the archive costs one pass of gzip decompression, 16 KiB of compressed input at a time. That
is fast for media that barely compressed.
