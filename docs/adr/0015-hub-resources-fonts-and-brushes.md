# ADR 0015: Fonts and brushes for the Creative Hub apps are owner-held resources on content-addressed blobs

Date: 2026-10-19 · Status: accepted and implemented (storage in PR1; the API and access control in PR2; the Fonts & brushes page and the hub-agent hand-over since; see the 2026-10-20 amendment) · Design: `evidence/2026-10-19-hub-visual-review/_cos/design-fonts-brushes-draft.md` (operator-approved 2026-10-19) · Builds on: ADR 0009, ADR 0010, ADR 0012

Numbering: when this record was written 0013 and 0014 were claimed by open pull requests #271 and #270, so it took 0015. Both merged since: [0013](0013-influencer-submission-review.md) and [0014](0014-share-links-hand-out-stacked-files.md).

## Context
People want their own fonts and brushes in the Creative Hub apps (WordCraft, PhotoCraft, VectorCraft and the rest).
Today a WordCraft font lives only in that browser's IndexedDB, and other apps have no way in. Bytes may cross into a
hub app only by `postMessage` (ADR 0012), so the fonts must live in The Mothership and be handed over by the workspace.

Storing them as Library assets was rejected: they would clutter the Library, run the thumbnail and AI pipelines, and
have nowhere to keep a family, weight or style. A Library asset can still be imported into a resource later.

The operator decided on 2026-10-19 (final decisions, which supersede the design's open questions):
- Anyone may upload. Anyone may share, with a license attestation.
- Visibility is Only me, Everyone, or chosen groups.
- Limits for v1: 20 MiB per font, 50 MiB per brush, and one byte quota of 5 GiB per person. There is no file-count
  limit.
- Font collections (`.ttc`, `.otc`) are refused in v1. Variable `.ttf` and `.otf` fonts are accepted.
- Brushes in v1 are PhotoCraft's `.abr` only.
- No WordCraft migration or notice is needed.

## Decision
- **Data.** There are two tables.
  - `dam_hub_resources` has one row per owner and blob. Its columns:
    - `uuid` (the public key), `owner_id`, `kind` (`font` or `brush`), `format`
    - the cleaned display `name`, and `family`, `subfamily`, `weight`, `style`, `postscript_name`
    - `file_object_id` and `size`
    - `meta` (JSON: parsed or not, variable, ABR version)
    - `visibility` (`private`, `everyone` or `groups`)
    - `license_note`, `attested_at` and `attested_by`
  - It is unique on `(owner_id, file_object_id)`.
  - `dam_hub_resource_group_access (resource_id, group_id)` holds the groups for `groups` visibility.
  - Its keys cascade.
  - The FileObject key is RESTRICT, which is NO ACTION on SQL Server.
  - `owner_id` cascades except on SQL Server, where it is NO ACTION, as in ADR 0010.
  - `attested_by` has no foreign key. It is an audit record, so it outlives the admin who attested.
- **Blobs (ADR 0009).** Bytes go through `ContentStore::putLocal`, and `retain()` runs under the owner lock.
  - The same bytes held by an asset or by another person are one blob.
  - `FileObject::scopeReferenced` names `hubResources`. A blob a resource holds is therefore never reaped.
- **Deletion.** Deleting a resource follows `AttachmentLedger::remove()`.
  - It locks the resource row, then the FileObject row.
  - When nothing else references the object, the row goes and its locator is tombstoned.
  - `AssetDeletionService::processPendingFileDeletions()` unlinks the blob after the commit. `dam:storage:reap` retries it.
  - `HubResourceStore::releaseOwnedBy()` does the same for every resource of an admin who is being deleted.
- **Inspection** (`HubResourceInspector`) goes by the bytes. The client MIME type is never read.
  - The magic bytes decide the font format:
    - `00010000` and `true` are TTF
    - `OTTO` is OTF
    - `wOFF` is WOFF and `wOF2` is WOFF2
  - A collection is refused with reason `collection`, whatever its face count. That covers `ttcf` magic, a file named
    `.ttc` or `.otc`, and a WOFF2 whose flavor is `ttcf`.
  - A variable font is one face. It is accepted, and `meta.variable` records the `fvar` table.
  - The vendored `dompdf/php-font-lib` parses the name, OS/2 and head tables in a child PHP process.
    - The child runs with `memory_limit` (128M) and `max_execution_time`, and the parent bounds it with a 12 s wall
      timeout.
    - A crash, a timeout or anything but one JSON answer is `unparsed`, so a hostile file exhausts the child, not the
      request.
    - php-font-lib inflates a WOFF into `tempnam(sys_get_temp_dir())` files it never removes. Each child therefore
      gets its own temp directory, through `sys_temp_dir` and `TMPDIR`. The parent removes that directory in a
      `finally` block, whether the child answered, crashed or timed out.
    - Family and subfamily prefer the typographic names (IDs 16 and 17) over the legacy ones (IDs 1 and 2).
    - Weight is `usWeightClass`. Style is `oblique` when `fsSelection` bit 9 is set, and `italic` when bit 0 or
      `macStyle` bit 1 is.
  - **WOFF2 is accepted unparsed.** php-font-lib cannot read it, and this PHP has no Brotli for its tables. The
    48-byte header is checked:
    - its length field equals the file size
    - it has at least one table
    - its reserved word is zero
    - its flavor is a single font, so a WOFF2 collection is refused
  - The family of a WOFF2 is its file name, and `meta.parsed` is false.
  - A brush is a PhotoCraft (Photoshop) `.abr`. It must be named `.abr`, because ABR has no magic of its own. Its version word must be 1, 2, 6, 7 or 10, and
    from version 6 on, `8BIM` must follow the version words.
  - `AssetHelper::isForbiddenFile` and `CreativeHubSaver::cleanName` apply as for Hub saves.
  - Every refusal is a `HubResourceRefused` with a stable reason code. The HTTP layer maps `not_owner` to 403 and
    every other code to 422.
- **Limits** are set in `config('dam.hub.resources')`.
  - `font_max_bytes` is 20 MiB and `brush_max_bytes` is 50 MiB.
  - `per_user_max_bytes` is 5 GiB. There is no file-count key.
  - Each resource counts at its full size, even when its blob is shared.
  - The quota is checked before any byte is written. It is checked again under a lock on the owner's `admins` row,
    so one person's concurrent uploads cannot both take the last bytes.
  - An upload can still fail after its bytes are written. The locked check may refuse it, the owner may be gone at the
    lock, or the attach may throw. Each time, the ingested FileObject is released unless something else references it,
    and the original failure is rethrown.
- **Reads.** `HubResource::scopeVisibleTo()` and `isVisibleTo()` are the one rule. An active admin reads a resource
  when any of these holds:
  - They are a full admin: `permission_type = all`, or no role, which is ADR 0010's parity case.
  - They own it.
  - It is shared with Everyone.
  - It is shared with groups, and they belong to one of them. The built-in Everyone group counts for every active
    admin.

  A disabled admin reads nothing.
- **Sharing.** Only the owner may share. Sharing with Everyone or groups needs the attestation flag and a non-empty
  license note.
  - The note, the time and the attesting admin are stored with the resource.
  - A full admin may also make a resource private, as a takedown.
  - Making a resource private keeps the last attestation as a record.

## Consequences
- PR1 added only storage, services, tests and this record, with no routes, ACL, UI or hub-agent changes. The design's
  PR2–PR5 added those: the `hub/resources` routes (`admin.dam.hub.resources*`, in `dam-routes.php`), the access control,
  the Fonts & brushes page and the hub-agent hand-over (`pick-request` carries `resources`).
- PR2 wired `HubResourceStore::releaseOwnedBy()` to the Admin model's `deleting` event on every driver
  (`ReleaseAdminHubResources`, registered in `EventServiceProvider`). Without it, deleting an admin who owns resources
  would have cascaded the rows on other drivers and left their FileObjects unreferenced, and on SQL Server the delete
  would have been refused. No resource could exist before PR2's upload route, so nothing was at risk in between.
- A WOFF2 has no weight or style until a Brotli decoder is available. The UI should let the owner correct the
  family. # TODO 2026-10-19: read WOFF2 name/OS2 tables once a Brotli decoder (ext-brotli) is on the Studio PHP
- Shared resources expose their bytes to every reader. The license attestation is the control, and it is recorded,
  not verified.
- Rollback: revert the PR. The migration's `down()` drops both tables. The FileObject rows and blobs those resources
  held stay behind, unreferenced; nothing reaps an unreferenced row that has no tombstone, so delete the resources
  through `HubResourceStore` first when there are any.

## Amendment 2026-10-20 — shared with Everyone by default
The operator directed that fonts and brushes be available to everyone by default, without sharing each one in turn.
- A new upload through `admin.dam.hub.resources.store` is shared with Everyone at once when the uploader holds
  `dam.hub.resources.share` (every non-API user). The upload is the license attestation: `attested_by` is the
  uploader, `attested_at` the upload time and `license_note` is `Shared on upload`. Without the key it stays private.
- A repeat upload of bytes the owner already holds returns their resource with its sharing unchanged.
- `HubResourceStore::store()` still creates a private row; the controller shares it, so other callers keep the old
  behaviour.
- The Fonts & brushes page gains **Share selected**: row checkboxes and **Share with everyone** / **Make private** for
  the viewer's own rows, sent as one owner PATCH per row. Sharing that way is also the attestation; a row keeps its
  own license note, or records `Shared from Fonts & brushes`.
- The single-resource Sharing dialog still asks for a note and the confirmation.
- The attestation stays the recorded control, now implied by the action rather than ticked. Making a resource private
  (owner, or a full admin as a takedown) is unchanged.
