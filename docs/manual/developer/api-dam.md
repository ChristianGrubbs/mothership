# DAM API details

All paths below are relative to `/api/v1/rest`. The Help API reference contains the complete
method/path list and machine-readable download. Numeric IDs refer to existing authorized
records. JSON examples are illustrative; use IDs returned by your own installation.

An asset is accessible when any directory it belongs to is granted to the caller's role. A role
with full access, or with all directories enabled, reaches every asset. The relationship
operations also check the asset in the path through the directory it was attached to first.

## Assets and compound files

| Operation | Request | Result |
|---|---|---|
| GET `assets` | ApiDataSource pagination/filter parameters, including `field.<code>` custom field filters (below) | Paged normalized asset records, filtered by directory grants |
| GET `assets/{id}` | Asset ID | `success`, `message`, `data.asset`, `data.tags`, `data.property`, `data.fields` (custom field values by code) and `data.missing_required`; asset includes preview, resources and comments |
| PUT `assets/edit/{id}` | Asset ID, no write body | Edit metadata including asset, comments and all tags; despite PUT, this reads edit data |
| GET `assets/{id}/metadata` | Asset ID | `data.asset_id`, file name/type/MIME and `meta_data`; image extraction is a fallback when stored metadata is empty |
| PUT `assets/{id}` | Optional `file_name` (a rename under the Library's rule: only the base name counts, the file keeps its extension and its path follows), `tags` array of existing tag IDs, `fields` object of custom field values by code (`null` clears one); `extension`, `mime_type`, `file_size`, `file_type` and `path` describe the stored bytes and are ignored | Updated asset in `data` and its `fields`; an invalid field value returns 422 under `fields.<code>` and a name another asset holds returns 422 under `file_name`, before anything changes; invalid tags return 400 after the rename may already have happened |
| POST `assets` | Multipart `directory_id`, `files[]`; alternatively `files` is a URL-list array | 201 `files`, `success`, `message`; 422 may contain successful files plus `errors` |
| POST `assets/reupload` | Multipart `asset_id`, `file` | 201 `file`; replaces the stored file, not a new version operation |
| DELETE `assets/{id}` | Optional boolean `force` | `analysis` on success or blocked deletion; 422 for graph dependencies, 404 when linked product resources prevent deletion |
| GET `assets/download/{id}` | Asset ID | `data.download_url`, ten-minute expiry |
| GET `assets/signUrlDownload/{id}` | Returned signature and expiry | Downloaded bytes; 403 for invalid/expired signature, 404 for missing file |
| GET `assets/{asset}/relationships` | `outgoing_page`, `incoming_page` | `data.outgoing/incoming` grouped by type, `types`, separate pagination metadata; each row's `url` is the related asset's admin page |
| POST `assets/{asset}/relationships` | `to_asset_id`, nonblank `type` up to 64 characters, optional `metadata` object | 201 `data.relationship`; self-links and duplicate directed/type pairs return 422 |
| DELETE `assets/{asset}/relationships/{relationship}` | Relationship touching the given asset | `data.deleted=true`; requires access to both ends |
| GET `assets/{asset}/files` | Asset ID | `data.files`; includes accessible capture-group members, role, label, sort, size, sha256, MIME type and session-protected URLs |
| PUT `assets/{asset}/attachments/order` | `order`: every owned attachment ID exactly once; optional `expected_files`: current owned file IDs in their displayed order | `data.files`; moving a different attachment into the first position makes its current file the representative and cover, retaining saved member metadata/artwork. Non-first moves leave the representative unchanged. Existing stacks with a non-first representative are not silently normalized. Mismatched `expected_files`, a missing current first revision, or a revision changed during discovery returns 409; invalid membership or an unsupported first-file role returns 422. Requires `api.dam.assets.files.reorder` and asset edit access. |

Multipart size limits follow `AssetHelper::getMaxUploadSizeKb()` and the request server limits.
The browser's chunked upload protocol is an internal session interface, not this multipart API.
URL imports accept HTTP(S), block private/reserved destinations and do not follow redirects.
Forbidden file types produce per-file errors. Thumbnails can be generated asynchronously after
upload; upload completion does not guarantee preview readiness.

While Web Pages is on (`DAM_WEB_PAGES_ENABLED`, the default), an `.html`/`.htm` file or a page
`.zip` (entry rule under Web Pages and collections below) is stored as a
page (`file_type` `web`), and its bundle is built in the background. A page `.zip` with a server
or executable entry, an unsafe entry name or more content than the bundle limits is a forbidden
file. A re-upload whose new file is a page turns the asset into a page and queues a new bundle;
any other replacement queues none. With Web Pages off, `.html`/`.htm` files are forbidden and a
`.zip` is stored as an ordinary file.

Relationship example:

```json
{"to_asset_id":2,"type":"related-to","metadata":{"purpose":"reference"}}
```

## Directories

GET `directories` returns the visible directory tree; `with_assets=true` includes assets.
GET `directories/{id}` checks visibility and lists only the children you can view. POST requires
a filesystem-safe `name` of at most 255 characters that does not start with a period (reserved;
422), and optional existing `parent_id` (default 1); names are unique under that parent.
Creation checks parent access and grants the new directory to the creator as needed.
PUT `directories/{id}` renames using `name`; to move a folder use POST `directories/{id}/move`. DirectoryRequest's
uniqueness exclusion reads request `id`, so include the same ID when renaming an existing folder.
DELETE checks access and deletability, queues deletion, and returns 202 with the parent directory.
A queued response is not proof the background job has completed.

## Comments, tags, properties and linked resources

| Operation | ID meaning and request | Result |
|---|---|---|
| GET/PUT/DELETE `comments/{id}` | **Comment ID**; PUT accepts `comments` | Read/update return `data`; update/delete require the comment author plus asset access |
| POST `comments` | `comments` 2–1000 characters, `dam_asset_id`, optional existing `parent_id` | 201 `message` and `comment` (no `success` field) |
| GET `tags` | `query`, `per_page`, `page` | `data` id/name array with page counts |
| GET `tags/{id}` | **Tag record ID**, not asset ID | Tag in `data` |
| POST `tags` | `tag` up to 100 characters, `asset_id` | 201 `file`; duplicate assignment returns 404 |
| DELETE `tags` | `tag`, `asset_id` in JSON body | Detaches the named tag, 201 on success |
| POST `tags/bulk` | `asset_ids` 1–500 positive IDs, nonempty `tags` string array, each up to 100 characters | Attaches without detaching existing tags; skips inaccessible/missing assets; `count` reports updated assets |
| DELETE `tags/{id}` | **Tag ID** | Deletes the tag itself |
| GET/PATCH/DELETE `properties/{id}` | **Property ID**; PATCH requires `name` 3–100 characters and `value` | Read uses `data`; update uses `property` |
| POST `properties/{id}` | **Asset ID**; `name` 3–100 characters, `type`, active locale code `language`, `value` up to 1000 characters | 200 `property`; name unique per asset/locale; unknown active locale returns 400 |
| GET `linked-resource/{id}` | **Mapping record ID**, not asset ID | Mapping in `data`, or 404 |

A property whose name is a custom field's label or code holds that field's typed value: POST and
PATCH on it are validated as the field (422 under `value`), and a multi-select takes
comma-separated option values. Other properties stay free text.

## Custom fields

Typed fields every asset can carry: `text`, `number`, `date` (YYYY-MM-DD), `select`,
`multi_select`, `url` (http or https) and `boolean`. Up to five have a `card_priority` (1–5)
and show on Library cards. A required field is enforced on every edit that sends it, and never
blocks an upload; `missing_required` lists the ones still empty.

| Operation | Request | Result |
|---|---|---|
| GET `metadata-fields` | Optional `include_archived=1`; any key that may list assets | `data`: definitions in order (`id`, `code`, `label`, `type`, `options`, `required`, `card_priority`, `position`, `is_filterable`, `archived`) |
| POST `metadata-fields` | `code` (lower-case, fixed once saved), `label`, `type`, `options` for select types, optional `required`, `card_priority`, `is_filterable` | 201 definition in `data` |
| PUT `metadata-fields/{id}` | Any of `label`, `options`, `required`, `card_priority`, `is_filterable` | Definition in `data`; the type, and options in use, are fixed once values exist (422) |
| DELETE `metadata-fields/{id}` | Optional `confirm` | Deleted when it has no values; otherwise 409 with `values` (assets this key may list), or with `confirm=true` archived: values kept, field out of cards, filters and validation |

`field.<code>` filters on GET `assets` (filterable fields only; others answer 422):

| Type | Operators | Value |
|---|---|---|
| text, url | `=` (exact, any case), `LIKE` (contains) | string |
| number | `=`, `>`, `<`, `>=`, `<=`, `BETWEEN` | number, or `[min, max]` |
| date | `=`, `>`, `<`, `>=`, `<=`, `BETWEEN` | YYYY-MM-DD, or `[from, to]` |
| select | `=`, `IN` | option value, or a list |
| multi_select | `=` (has it), `IN` (has any) | option value, or a list |
| boolean | `=` | `true` or `false` |

Example: `filters={"field.price":[{"operator":"BETWEEN","value":[10,50]}]}`.

## Share management

GET `shares` uses the shared paginator and supports share type, target ID, name and date filters.
GET `shares/{id}` returns `data` with id, token, name, share_type, target_id, public_url, expiry,
revocation, view/download counts, status and creation time.

POST `shares` requires `share_type` (`asset` or `directory`) and the matching `asset_id` or
`directory_id`. Optional `name` is at most 255 characters; `expiry_days` is 1–365 (default 7),
while `no_expiry=true` requests no expiry. It requires access to the target and returns 201.
PUT accepts `name`, `no_expiry`, and `expiry_days`; it does not change the target. POST
`shares/{id}/revoke` disables a link; POST `shares/{id}/reauthorize` clears revocation. Check
`success` for an already-revoked/not-revoked state. DELETE permanently removes the share record.
Every management operation checks target access. Reauthorization does not itself extend expiry.

POST and PUT `shares` also take an optional `slug`, the link's custom address `/s/{slug}` (P4 D23):
3–64 lowercase letters, digits and single hyphens, never a reserved word, and unique across shares
and collections; a refused slug answers 422 and creates or changes nothing. `slug`, `slug_url` and
`share_url` (the URL to copy) appear only beside the token, and only the link's creator, the target's
owner or a full admin may set the slug (else 403). A renamed slug answers 301 to the current one;
`slug: null` clears it, and reissuing the link retires it. `/s/{slug}` redirects to the token URL
while the link opens, so the token path's checks all apply; an unknown, revoked or expired slug
answers 404 alike. GET `shares/slug-check?slug=…&share_id=…` reports `available` and the `problem`
(`format`, `reserved` or `taken`) without saying what holds a taken slug.

## Collections

A collection (ADR 0011) is an ordered set of assets with an audience: `internal` (named users and
groups) or `public` (one share link), an optional `expires_at`, a `status` (`active` or `archived`)
and an optional `slug` (`/c/{slug}`). Paths are under `/api/v1/rest/collections`.

| Operation | Input | Result |
|---|---|---|
| GET `collections` | | `data.managed` (all for a full admin, else the caller's own) and `data.shared` (live internal collections whose audience names the caller) |
| POST `collections` | `name` (required, max 191), `description`, `audience`, `expires_at`, `slug`, `user_ids`, `group_ids` | 201 `data` |
| GET/PUT/DELETE `collections/{id}` | PUT takes the create fields except the audience lists, plus `status` | The manager also gets `total_members`, `audience_principals` and `link` |
| GET `collections/{id}/assets` | | Members in position order: the audience gets only servable ones; the manager gets every row with `servable` |
| POST `collections/{id}/assets` | `asset_ids` (1–500) | `data.added`, `data.already`; appended after the last member |
| PUT `collections/{id}/assets/order` | `asset_ids`: every member exactly once | 422 for a partial, duplicated or stale list |
| DELETE `collections/{id}/assets/{assetId}` | | Removes one member; 404 if it is not one |
| PUT `collections/{id}/audience` | `user_ids`, `group_ids` (each at most 100) | Replaces the internal audience |
| POST `collections/{id}/link` | `expiry_days` (default 30) or `expires_at` or `no_expiry`, `password`, `allow_download`, `requires_creator_access`, `name` | 201 `data` link; 422 unless the collection is public and has no active link |

A collection the caller does not open (owner, full admin or audience member) answers 404 like a
missing one; changing it needs its owner or a full admin (403 otherwise); after it expires or is
archived its audience gets 410. Adding an asset the caller cannot see answers 404; adding one they
neither own nor may share (`dam.asset.share`, unless a full admin) answers 403, and nothing is
added. Only members that are out for use (not withdrawn, pending, outside their dates or trashed)
are listed or counted for the audience. Member file URLs need an admin session. Audience
membership never makes an asset visible through any other operation. A collection's link is
changed, reissued or revoked through the `shares/{id}` operations, which only its manager reaches;
`GET shares` leaves collection links out. The link `token` is returned only to the link's creator,
the collection's owner or a full admin. A slug error is a 422 with `errors.slug`.

## Web Pages and page groups

Web Pages has no `/api/v1/rest` operations of its own; the REST upload and re-upload above can
create or replace a page. The routes below serve the admin browser: the admin
routes need a signed-in admin session and, for writes, a CSRF token, and the route inventory
classifies them as internal. Paths are relative to the admin URL (`/admin` by default) plus
`/dam`. A page is an asset whose `file_type` is `web`. The UI calls the Web Pages organizer **page
groups**; these routes and their JSON keep its code name, `collection` (ADR 0011). They only organize pages in the Web
Pages area; they never move an asset's Library folder or change its grants. Every listing still
applies directory grants.

| Operation | Request | Result |
|---|---|---|
| GET `pages` | Optional `collection`, `q`, `owner` (`everyone` or `me`), `directory_id` | The Web Pages browser (HTML); ACL `dam.pages` |
| GET `pages/listing` | Same parameters, plus `tree=1` to nest every descendant collection | JSON `collection`, `missing_collection`, `breadcrumb`, `children`, `search_mode`, `pages` (24 per page: `data`, `current_page`, `last_page`, `total`) and `can`; ACL `dam.pages` |
| GET `pages/{id}/grant` | **Asset ID**; optional `file` (AssetFile ID) and `checksum` (its full 64 hex), always together | 200 `url`, a signed base URL for the current bundle version (or, with a file, for that stack member: also `file_id`, `attachment_id`, `checksum`); 409 `state` (`preparing`, `failed` or `stale`) and `error`; 404 for a malformed, foreign or non-current file; 422 for a file that is not a page; needs view access to the asset |
| GET `pages/{id}/open` | **Asset ID**; optional `file` and `checksum` as above | Full-screen page view (HTML); needs view access to the asset |
| GET `pages/{id}/members` | **Asset ID** | 200 `members`: the stack's current files in order (`attachment_id`, `file_id`, `checksum`, `extension`, `candidate`, `name`, `label`, `role`, `size`, `is_main`, `state`, `error`); reads only; ACL `dam.asset.view` |
| POST `pages/collections` | `name` (required), optional `parent_id` | 201 `collection` (`id`, `name`, `parent_id`, `path`); ACL `dam.pages.organize` |
| PATCH `pages/collections/{id}` | **Collection ID**; `name`, `parent_id`, or both | 200 `collection`; ACL `dam.pages.organize` |
| DELETE `pages/collections/{id}` | **Collection ID** | 200 `children` and `pages`: the counts moved up to the parent; ACL `dam.pages.organize` |
| POST `pages/move` | `asset_ids` (1–500 IDs) and `collection_id` (present; `null` means unfiled) | `placed` IDs and `rejected` entries (`id`, `reason`: `missing`, `not-web` or `forbidden`); 403 when nothing was placed; ACL `dam.pages.organize` |

### Share pages in folder

A folder's **Share pages in folder** action is one read and then the ordinary share route, once
per page; nothing is created by the read.

| Operation | Request | Result |
|---|---|---|
| GET `shared-links/web-collections/{id}/candidates` | **Collection ID** | 200 `collection`, `cap`, `total`, `omitted` and `pages` (`id`, `display_name`, `revision`, `reuse`: `null` or the usable link's `expires_at` and presented options); `Cache-Control: no-store`. 403 without `dam.asset.share` (or lists mode); 404 for an unknown folder |
| POST `shared-links` | The usual asset share body plus `web_collection_id` and `page_revision`, always together; `expiry_days` is 30 or omitted; no `slug`, `password`, `name`, `no_expiry`, `expires_at`, `allow_download` or `requires_creator_access` | 200 with the ordinary share body, plus `reused` (`true` when a usable link was kept instead of a new one). 409 `reason: page-changed` when the page is no longer a direct, unchanged member of that folder; hidden and missing pages answer 404 |

The candidates are the visible, shareable `web` pages placed directly in the folder (not its
descendants), ordered by name and capped at `dam.web_pages.share_candidate_cap` (100); `omitted`
counts the rest, which all sort after the listed pages by name and then ID (a second run lists the same first pages, so the omitted ones are shared one by one through the ordinary page share). `revision` is a SHA-256 over the page's stack members (file ID and full
checksum), not a timestamp. At creation the server locks the page's asset and placement rows,
rechecks that the page is still placed directly in `web_collection_id` and that its revision
still matches, then applies the same visibility and share authorization as any share. It makes
no group, metadata or placement write. The route adds no share type. An existing link is kept only while it opens today (not revoked or expired, and a
`requires_creator_access` link only while its creator can still see the page) and is shown with its options
(`has_password`, `allow_download`, `requires_creator_access`) unchanged; an unusable one is never reused.

Collection names are 1–120 characters, contain no slashes, and are unique among siblings,
ignoring case. Collections nest at most 10 levels (`dam.web_pages.max_collection_depth`), and a
collection cannot move into its own subtree. A rule violation returns 422 with `message` and
`errors` keyed by `name` or `parent_id`. Deleting a collection moves its child collections and
page placements to its parent; no asset is deleted.

Pages are uploaded through the Library upload route, `POST assets/upload`, with `directory_id`
and `files[]`, or through REST `POST /api/v1/rest/assets`. A single `.html`/`.htm` file becomes a
page. So does a `.zip` whose entry, `index.html` or the only `.html`/`.htm` file at that level,
sits at the root or inside one top-level folder. A `.zip` may hold at most 5,000 raw entries,
directory records included, and 512 MiB uncompressed.

Two session-free routes serve page files. Neither uses a session, because an opaque-origin page
cannot send the admin cookie, and their responses never set one.

| Operation | Request | Result |
|---|---|---|
| GET `{admin}/dam/pages/{id}/g/{grant}/{path?}` (`admin.dam.pages.file`) | **Asset ID**, the grant from `pages/{id}/grant`, and a path inside the bundle | The file; throttle `dam-page-file` |
| GET `/share/{token}/page/{path?}` (`dam.share.page`) | Share token (20–64 letters and digits) and a bundle path; for a folder share, the first path segment is the asset ID | The file; throttle `dam-share-page` |

A grant is an HMAC-signed value bound to the asset, its bundle version and the admin, and it
expires after one hour. The entry HTML is `no-store`. On the admin route, so is the
thumbnail-capture target, which is the page an exporter's launcher entry refreshes to. Other
bundle files are cached for 300 seconds. Every response carries the page CSP from ADR 0007 (`WebPagePolicy`), a `sandbox`
directive without `allow-same-origin`, and `Referrer-Policy: no-referrer`. A missing path returns
404, a bundle that is still preparing returns 409, and a folder path without a trailing slash
redirects. Both throttles allow 1,200 requests a minute per grant or token and IP, then return
429.

Source: DAM API controllers, `AssetRelationshipService`, `DirectoryRequest`, `AssetDataSource`
and `ShareDataSource` in the fork. Web Pages: `dam-routes.php`, `web-pages.php`,
`WebPageController`, `WebCollectionController`, `WebCollectionService`, `PageGrant` and
`WebPagePolicy`. [Return to API usage](api-usage.md).

## Headless 3D thumbnail capture

Use the existing OAuth bearer integration; no browser session is required. The routes are under
`/api/v1/rest`, not `/api/v1/dam`. Rendering remains client-side (ADR 0006); these routes do not
change model files, saved views, tags or organization.

| Resource | Purpose |
| --- | --- |
| `/assets/{asset}/model-thumbnail` | Capture for the representative model and Library card |
| `/assets/{asset}/files/{file}/model-thumbnail` | Capture for one current compound-file revision; leaves the cover alone |

Both resources support `GET` (capture metadata) and multipart `POST` (save a capture).
`GET {resource}/source?checksum={sha256}` delivers the exact model bytes.
`GET {resource}/image?checksum={sha256}` delivers the generated capture, or 404 if absent.
Send `Authorization: Bearer …` and `Accept: application/json` on every request, including byte reads.

The integration key needs `api.dam.assets.model-thumbnails.read` for metadata and image readback,
`api.dam.assets.model-thumbnails.write` for capture writes, and the existing
`api.dam.assets.download` for model bytes. These scopes do not grant item access: the key's owner
must see the asset, and writes also require edit permission and satisfy the own-items rule.
Missing, hidden, trashed and foreign compound-file identifiers answer 404. A visible asset that
the caller cannot edit answers 403. Keep an existing integration's configuration and credentials;
add these permissions to its key rather than replacing its setup. A fresh integration uses the
ordinary OAuth/API-key setup documented in [API usage](api-usage.md).

The metadata response contains `asset_id`, `file_id`, `checksum` (SHA-256), `size` (bytes),
`revision` (file revision number), `is_representative`, `extension`, `has_thumbnail`,
`can_capture`, `can_replace`, `source_url` and `thumbnail_url`. The two URLs carry the checksum
query and re-check authorization on every use. `primary_view` is the full saved
`glb_primary_view` object only when its SHA-256 matches the selected file; otherwise it is null.
Pass this object unchanged as the renderer's `primaryView` option, including its nested `view`.
Saved orientation is already in the selected GLB bytes; do not apply its transform again.

1. Read the metadata and download `source_url`; verify downloaded SHA-256 equals `checksum`.
2. Render those bytes with `primary_view`. POST `image` (PNG/JPEG, at most 2 MiB and 1024×1024)
   and `checksum`. Omit `replace` for an idempotent backfill; use `replace=1` only for an intentional repair.
3. Read `thumbnail_url` with the same bearer integration and visually verify the stored JPEG.

The service re-encodes at a maximum longest side of 512 pixels. POST returns `stored`, `skipped`,
`checksum` and `thumbnail_url`; the asset resource also returns `captured`, `thumbnail_path` and
`revision`. Existing usable captures return `skipped: "exists"`. Custom cards remain untouched:
the asset resource can return `stored: false`, `skipped: "custom"`, `captured: true` while saving a
separate generated image for readback and indexing. Compound writes never update the asset's card.
Invalid images/checksums answer 422; checksum changes answer 409. Re-read metadata and re-render
after 409. A superseded compound revision answers 404; resolve its current revision through the
asset's existing files API. Capture-lock contention answers 503 with `Retry-After`.
