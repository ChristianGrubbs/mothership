# Integration API usage

The integration API lives at `/api/v1/rest`. Send `Accept: application/json` and a Passport
access credential in the `Authorization: Bearer` header. JSON writes use `Content-Type:
application/json`; uploads use multipart form data. The API reference in Help lists every
integration method and its exact path, including the inherited PIM routes.

## Authentication and permissions

An administrator creates an integration in Settings → Integrations → API keys and assigns its
permissions. The integration permission record is not itself a bearer credential. Passport
uses the fork's Admin identity provider and a password-grant client. The installation's
`mothership:passport:keys` and `mothership:passport:client` commands provision signing keys and the client;
operators should follow their deployment procedure rather than regenerate keys during requests.

Obtain a credential by POSTing form fields to `/oauth/token`: `grant_type=password`, the
provisioned `client_id` and `client_secret`, `username` (admin email) and `password`.
Refresh with `grant_type=refresh_token`, the client credentials and `refresh_token`.
Read expiry from the OAuth response; the configured defaults are 3,600 seconds for access and
refresh credentials. Do not put these values into URLs, committed files or browser examples.

A successful OAuth exchange does not grant all route access. `api.scope` requires an active
user with a non-revoked API-key permission record. Route names map through `api-acl`; the
`.get` route falls back to `.index`. A missing mapping is denied. DAM folder grants and target
ownership checks still apply. A browser session alone does not authenticate the REST API.

## Pagination and filtering

ApiDataSource lists accept `limit`, `page`, `sort`, `filters`, `pagination_type` and
`search_after`. Offset responses contain `data`, `current_page`, `last_page`, `total` and
`links.first/last/next/prev`. Cursor mode (`pagination_type=search_after`) returns `data`,
`search_after` and `links.next`; it intentionally omits totals. Follow the returned next link
until null. Cursor ordering follows the data source's primary key, not an arbitrary client sort.

`filters` is a JSON object keyed by field, with an array of comparisons per field. For example:

```json
{"file_type":[{"operator":"=","value":"image"}]}
```

URL-encode that JSON when using it in a query string. The DAM asset list permits file type,
MIME type, extension, file size, file name, code, created and updated date filters. Its `code`
filter searches file names; MIME and extension matching use substring LIKE. Date handling is
source-specific. Unsupported fields/operators return validation errors rather than silently
filtering. The tags list instead uses `query`, `per_page` (1–100, default 25) and `page`.
Relationships have separate `outgoing_page` and `incoming_page`, each with 25 records.

## Errors and limits

Handle 401 as an authentication failure and 403 as a permission/feature-access failure.
Validation errors normally use 422 with a `message` and field `errors`; individual endpoints
also have their own documented error bodies. Do not assume every response has `success`.
Missing resources normally return 404. Check both status and body for partial upload failure:
a 422 upload can already contain successfully created files. Do not blindly retry mutations.

The AdminApi route group uses the configured REST limiter (default 120 per minute); OAuth
credential routes use a separate default of 10 per minute per username/IP. The DAM API group
does not attach the AdminApi REST throttle. Reverse proxies may impose additional limits.

PIM-gated routes remain registered with PIM disabled and are rejected by middleware. Registration
is not availability. The deprecated `configrable-products` spelling is retained as an alias;
new integrations should use `configurable-products`.

## Moving, copying and Trash

These routes run the same actions as the Library. Each needs its `api-acl` permission, and the
key's admin also needs the Library role permission and folder access, so a key never does more
than its admin could do by hand.

| Method and path under `/api/v1/rest` | Body | Success | API permission | Role permission |
| --- | --- | --- | --- | --- |
| POST `assets/{id}/move` | `target_directory_id` | 200 | `api.dam.assets.move` | `dam.asset.moved` |
| POST `assets/move` | `asset_ids`, `target_directory_id` | 202 | `api.dam.assets.move` | `dam.asset.moved` |
| POST `assets/{id}/copy` | `target_directory_id` | 201 | `api.dam.assets.copy` | `dam.asset.upload` |
| POST `directories/{id}/move` | `target_directory_id` | 202 | `api.dam.directory.move` | `dam.directory.moved` |
| POST `directories/{id}/copy` | `target_directory_id` | 202 | `api.dam.directory.copy` | `dam.directory.store`, `dam.asset.upload` |
| GET `trash` | | 200 | `api.dam.trash.get` | `dam.trash.index` |
| POST `trash/{id}/restore` | | 200 | `api.dam.trash.restore` | `dam.trash.restore` |
| DELETE `trash/{id}` | | 200 | `api.dam.trash.purge` | `dam.trash.delete` |

A 202 means the work is queued and carries `queued: true` but no job ID. Read the asset or folder
to confirm it finished. A bulk move leaves out any ID the admin cannot see without an error, so
check the result rather than the status alone. A destination the admin cannot see is 422 on
`target_directory_id`; one they can see but not open is 403. An asset or folder they cannot see is
404. `DELETE assets/{id}` moves an asset to the Trash for 30 days; `DELETE trash/{id}` removes it
permanently and cannot be undone.

## Files, shares and browser interfaces

The authenticated download operation returns `data.download_url`, valid for ten minutes. Fetch
the returned URL unchanged: local storage uses a Laravel signature and expiry, while S3 may
return a storage-signed URL. The signed byte response is not a JSON asset object.

Shares are created and managed through the integration API; their returned public URL is the
recipient workflow. Expiry and revocation are enforced there. Share delivery routes
are separately classified as public-link interfaces in the inventory.

`/admin/...` JSON endpoints are implementation interfaces for the authenticated browser.
They require the admin session, applicable ACL and CSRF on writes. They are listed separately,
not supported bearer API substitutes. In particular, graph file/thumbnail URLs may point at
admin-session routes; a REST bearer credential does not make those URLs public. Use the
integration download endpoint for integration transfers.

See [DAM API details](api-dam.md), [extension development](extensions.md),
[native widgets](native-widgets.md) and [iframe tools](iframe-tools.md).
