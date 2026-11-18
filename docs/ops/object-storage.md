# Asset bytes on an S3-compatible object store

The decision is [ADR 0017](../adr/0017-asset-bytes-on-a-remote-object-store.md). Every asset byte goes through
`ContentStore` (ADR 0009). The asset disk decides where those bytes live.

## Choosing the asset disk

| `MOTHERSHIP_ASSET_DISK` | Bytes live in | Use it when |
| --- | --- | --- |
| unset | `s3` if `FILESYSTEM_DISK=s3`, else `private` | existing installs: nothing changes |
| `private` | `storage/app/private/assets/.objects` | the app and its storage are on one machine |
| `s3` | the bucket configured on the `s3` disk | the bytes live on another machine (Garage on the Umbrel, R2, AWS) |

Leave `FILESYSTEM_DISK` alone. It is the default disk for logos, avatars and `Storage::url()` in views, and
those stay local.

## A Garage bucket (or any S3-compatible store)

The live store is Garage on the Umbrel, reached through the Cloudflare tunnel. MinIO, R2 and AWS take the same
settings.

```dotenv
MOTHERSHIP_ASSET_DISK=s3
AWS_ENDPOINT=https://<storage-host>
AWS_USE_PATH_STYLE_ENDPOINT=true
AWS_DEFAULT_REGION=us-east-1
AWS_BUCKET=<bucket>
AWS_ACCESS_KEY_ID=<from the Keychain>
AWS_SECRET_ACCESS_KEY=<from the Keychain>
```

- **Endpoint.** Use the tunnel's HTTPS hostname, or the store's LAN address (`http://<host>:<port>`) when the
  app and the store share a network. Both point at the same bucket.
- **Path-style addressing.** Garage and MinIO need `AWS_USE_PATH_STYLE_ENDPOINT=true`. AWS S3 and R2 do not.
  Presigned URLs then have the shape `<endpoint>/<bucket>/<key>?X-Amz-...`.
- **Region.** Garage checks the region in every signature against its own `s3_region`, so
  `AWS_DEFAULT_REGION` must match it exactly. The Umbrel store uses `us-east-1`. MinIO accepts any region.
- **Keys.** Keep the access key and secret in the Keychain. Write them into the runtime `.env` at deploy time;
  never commit them. Give the app a key limited to its buckets, without the right to create buckets.
- **Request size behind Cloudflare.** Cloudflare's Free plan refuses a request body over 100 MB. The S3 SDK
  sends anything over 16 MiB as a multipart upload in parts well under that, so large assets still upload; a
  single PUT over 100 MB from another client would fail.
- **No edge caching.** Turn off Cloudflare caching for the storage hostname, so HEAD size checks and reads
  always reach the store.
- **Incomplete multipart uploads.** An interrupted large upload leaves hidden parts that use space and that
  no listing shows. Garage does not remove them by itself: give each bucket a lifecycle rule that aborts
  incomplete multipart uploads after a day (the Umbrel buckets have one), or run
  `garage bucket cleanup-incomplete-uploads <bucket>`. AWS S3 and R2 take the same lifecycle rule. MinIO
  removes them after 24 hours by default (`stale_uploads_expiry`).

## Delivery stays same-origin

Browsers never fetch from the store directly. Set `MOTHERSHIP_REMOTE_ACCEL_PREFIX=/__dam_remote` (the studio
runtime sets it), and a request for a remote blob gets an `X-Accel-Redirect` to its signed URL. nginx's internal
`/__dam_remote/` location then streams it from the store:

- **Store addresses:** hostnames (resolved at request time through the container network's DNS), IPv4 and
  bracketed IPv6, with or without a port.
- **HTTPS:** nginx sends the store's hostname by SNI.
- **Signature:** the Host header keeps the port, as the signature expects.
- **What stays in the app:** cookies and `Authorization` are stripped before the request reaches the store.

Content that needs the app's CSP (inline SVG, PDF) still streams through PHP. With the prefix unset, a remote
blob is streamed through PHP, or redirected to a temporary URL where the disk provides one.

## Uploads stage briefly on local disk

When the asset disk is remote, chunked uploads stage on the local `mothership-staging` disk, not in the bucket:

- `MOTHERSHIP_STAGING_ROOT` sets the folder. The default is `storage/app/staging`. On a laptop, use a
  folder on an external disk.
- Chunk parts and the assembled file are kept there. The assembly is streamed to the bucket once.
- After that, the file stays as a warm copy (`warm/<sha256>`) for `MOTHERSHIP_STAGING_KEEP_HOURS` hours (the
  default is 6). Preview jobs that need a local file use it instead of downloading the blob again.
- `php artisan dam:uploads:expire` (hourly) deletes warm copies past that age, together with stale uploads.

The staging folder needs room for the largest upload in flight plus a few hours of recent uploads. The
library itself lives in the bucket.

With a local asset disk, none of this applies: uploads stage on the asset disk and are renamed into place.

Change `MOTHERSHIP_ASSET_DISK` only when no uploads are in flight. An upload started before the change has its
parts on the other disk, so it fails and must be sent again.

Moving an existing library into the bucket is a separate migration step, not covered by this setting.

## Checking an install

- `php artisan tinker --execute="echo Mothership\DAM\Models\Directory::getAssetDisk();"` prints `s3` or `private`.
- A failed remote write throws with its reason as the message: `object_write_failed` (the store refused the
  write) or `object_write_incomplete` (the stored size did not match). The upload or job that hit it shows that
  message, and the next attempt overwrites the bad key.
