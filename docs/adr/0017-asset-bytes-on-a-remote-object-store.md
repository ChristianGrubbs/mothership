# ADR 0017: Asset bytes can live on a remote S3-compatible object store while the app runs elsewhere

Date: 2026-11-17 · Status: accepted (operator, 2026-11-17: storage on the Umbrel, the app on the MacBook; uploads
"stage briefly" on local disk) · Builds on: ADR 0009

## Context
The operator wants asset bytes on the home Umbrel and the app, its queue workers and its AI features on the
MacBook, which has the better hardware. The Umbrel can run an S3-compatible server (Garage, since the Umbrel MinIO app was withdrawn). ADR 0009 already
routes every byte through `ContentStore`, so one seam decides where blobs live. On 2026-11-17 that seam could
not reach a remote store:

- **No S3 driver.** `league/flysystem-aws-s3-v3` was not installed, so Laravel's `s3` disk could not be built.
- **Coupled to FILESYSTEM_DISK.** `Directory::getAssetDisk()` picked `s3` only when `FILESYSTEM_DISK=s3`. That
  setting is also the default disk for logos, avatars and `Storage::url()` in views (66 call sites), which must
  stay local.
- **Extra copies on a remote disk.** Every write went to a temp key and was then moved into place. A move on S3
  is a server-side copy plus a delete, so each blob was copied once more than needed, and an adopted upload
  twice.
- **Uploads crossed the network three times.** Chunk parts were written to the bucket, read back to assemble
  the file, and the assembly was written back.
- **No local copy for the first jobs.** Preview jobs that need a local file downloaded the blob again right
  after it had been uploaded.

## Decision
- **A setting of its own.** `MOTHERSHIP_ASSET_DISK` (`dam.asset_disk`) names the asset disk: `private` (local,
  the default) or `s3`. If it is unset, the old rule still applies, so existing installs are unchanged.
- **Remote writes go straight to the final key.** An S3 PUT, single or multipart, makes an object visible only
  once it is complete. A half-written blob therefore cannot appear at its content-addressed key, and the temp
  key adds nothing but a copy. After the write `ContentStore` checks the stored size. If the size is wrong, it
  throws, and no row names the object. The object is not deleted: a concurrent writer of the same bytes may
  already have replaced it with an intact copy. The next write of those bytes overwrites a bad key. Local disks
  keep the temp-then-rename path, because a crash can leave a short local file.
- **Adopting on a remote disk takes one server-side copy.** The source is deleted only after the copy is
  verified, so a failed adopt leaves it for the caller's retry. A move would delete the source before that
  check.
- **Uploads stage locally and briefly** when the asset disk is remote. Chunk parts and the assembled file
  live on the local `mothership-staging` disk (`MOTHERSHIP_STAGING_ROOT`). The assembly is streamed to the
  bucket once. It is then kept as a warm copy for `MOTHERSHIP_STAGING_KEEP_HOURS` (default 6), so the first
  preview jobs read it locally instead of downloading it. `dam:uploads:expire` (hourly) deletes warm copies
  past that age.
- **Local installs are untouched.** With a local asset disk, uploads, staging and `localPath()` run the same
  code as before.

## Consequences
- An install picks its storage with one env value plus the standard `AWS_*` block. `docs/ops/object-storage.md`
  documents both setups.
- Warm copies are evicted by age, not when the last job that needs them finishes. A job that runs after
  eviction downloads the blob, as before. The cost is a little local disk for a few hours.
- An interrupted large upload can leave hidden multipart parts in the bucket. On Garage, AWS S3 and R2 a
  bucket lifecycle rule aborts them after a day; MinIO expires them on its own after 24 hours by default.
  Either way this is set on the store, not in the app.
- Same-origin delivery from a remote store: with `MOTHERSHIP_REMOTE_ACCEL_PREFIX=/__dam_remote`, `ContentStore`
  hands nginx the blob's signed URL by `X-Accel-Redirect` and nginx streams it from the store, so the browser
  never needs to reach the store and the bytes skip PHP. Content that needs the app's CSP (inline SVG, PDF)
  still streams through PHP.
- Moving an existing library to the store, and pointing a running install at it, are operator steps outside
  this ADR (plan S4 and S5).
