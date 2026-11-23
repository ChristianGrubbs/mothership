# The `mothership` library server (MCP)

The Mothership is the asset library and system of record for finished creative work. Local creative tools (Blender, the
image and video generators, ArtCraft through its own relay, plain scripts) reach it through one MCP server, `mothership`,
with ten `library_*` tools. The server runs no engines and keeps no sessions: tools make files on the agent's machine, and
the library keeps them.

It replaced the hosted Craft MCP (`/mcp/craft`, the `craft_*` tools, upload links and the Craft worker) in November 2026.

## Running it

The tools exist twice on the server, with one implementation behind both:

- **stdio:** `php artisan mcp:start mothership`. It speaks MCP over stdin and stdout, has no web route and no sign-in,
  and every call acts as one admin account, named in `.env`. It runs where the app runs.
- **HTTPS:** `POST /api/v1/rest/library/tools/<tool>` (route names `admin.api.dam.library_tools.<tool>`), one route per
  tool, behind the REST API's OAuth and scope checks. Each call runs `LibraryTool::perform` and `LibraryOperations`
  exactly as the stdio server does, as the API key's own admin.

Agent sessions use the HTTPS routes through the local launcher, which Skill Scope registers as the stdio server
`mothership`:

```bash
/usr/bin/python3 -I -S <checkout>/tools/mcp-launcher/launcher.py
```

The checkout needs only `tools/mcp-launcher/` (a sparse checkout is enough). The machine needs no PHP, no SSH and no
container runtime: the stock `/usr/bin/python3` (3.9 or later) and the Keychain items below are all it uses.

The container path is retired. The launcher no longer runs `container exec ... php artisan mcp:start mothership` on the
Mac, and none of its settings remain. Never register the PHP command itself as a global MCP server either: a global server
runs one copy per open agent session, and at about 100 MB of PHP per copy that is more memory than the app's host has.

### The launcher

`tools/mcp-launcher/launcher.py` and `bridge.py` use only the Python standard library.

- **No network until it is needed.** `initialize` and `tools/list` are answered from `tools_manifest.json`. The first
  `tools/call` loads `bridge.py`. After 15 idle seconds with every call answered, the launcher restarts itself in place to
  return to its idle size.
- **Idle cost.** One idle launcher holds 13.6 MB resident, 9.0 MB of it physical footprint, under `/usr/bin/python3`
  3.9.6 on Apple Silicon (14.0 MB and 6.7 MB under Python 3.15).
- **Sign-in.** The launcher signs in with the OAuth password grant at `/oauth/token`, using the agent API credentials in
  the Keychain. The token is kept in the Keychain until 60 seconds before it expires, and a refresh happens under a lock
  file in `MOTHERSHIP_MCP_STATE_DIR`, so concurrent sessions sign in once. A 401 signs in again once.
- **Retries.** A dropped connection, a 429, 502 or 504, and a 503 that is not the app's own refusal are tried up to three
  times in all, waiting for `Retry-After` (at most 30 seconds) or else 1 then 2 seconds. Every tool is safe to retry:
  `library_save` and `library_vectorize` replay their `request_id`. Redirects are never followed.
- **Files in.** For `library_save` and `library_vectorize` with `local_path`, the launcher applies the server's rules for
  `local_path` on this machine, copies the file to a private snapshot, checks the original did not change while it was
  read, and sends the snapshot as the multipart field `file` with the arguments as the JSON field `arguments`. The file's
  own name also goes as the JSON field `upload` (`{"name": ...}`), because PHP keeps only the part of an upload's file
  name after its last `\`; so the saved name is the one the stdio server would use.
- **Answers cut short.** A 200 whose body breaks off is treated like a dropped connection and sent again, so a save that
  was applied answers with its replay instead of an error.
- **Files out.** For `library_fetch` and `library_vectorization` with `format`, the server checks access and the
  arguments, then answers with the file's bytes and the result in the `X-Library-Result` header (base64 JSON). The
  launcher then checks `dest_dir` on this machine, streams into a hidden partial file there, verifies size and sha256,
  and names the copy under the server's rules (below). A mismatch writes nothing.
- **Size ceiling: 50 MiB.** That is the deployed stack's PHP `upload_max_filesize`, the most one upload carries. A larger
  file is refused before anything is sent; a file the server still finds too large is refused with HTTP 413. Fetches have
  no such limit.
- **The tool list cannot drift unnoticed.** `tests/Feature/MothershipMcpLibraryTest.php` compares the manifest with the
  server's code. After a change to the tools, regenerate it in a full checkout with `launcher.py --write-manifest`, which
  runs that checkout's stdio server; `launcher.py --check-manifest` exits 1 when the two differ.

| Launcher setting | Meaning |
| --- | --- |
| `MOTHERSHIP_URL` | The site the launcher calls (default `https://themothership.app`). |
| `MOTHERSHIP_MCP_MAX_FILE_BYTES` | The largest file `library_save` or `library_vectorize` sends (default 50 MiB). |
| `MOTHERSHIP_MCP_FETCH_DIR` | The folder copies go to when a call names no `dest_dir` (default `$TMPDIR/mothership-library`). |
| `MOTHERSHIP_MCP_IDLE_SECONDS` | Idle time before the launcher returns to its idle size (default 15). |
| `MOTHERSHIP_MCP_TIMEOUT_SECONDS` | How long one network read or write may stall before the attempt fails (default 120). |
| `MOTHERSHIP_MCP_STATE_DIR` | Where the token refresh lock lives (default `~/Library/Caches/mothership-mcp`). |
| `MOTHERSHIP_MCP_SECURITY_BIN` | The Keychain command (default `/usr/bin/security`). |
| `MOTHERSHIP_MCP_PHP` | The PHP binary `--write-manifest` and `--check-manifest` run (default `php`). Not used while serving. |
| `MOTHERSHIP_MCP_LAUNCHER_LOG` | `1` or `true` writes redacted JSONL: operation, outcome, HTTP status, size and timing, never a credential, token, path, name, title or byte content. |
| `MOTHERSHIP_MCP_LAUNCHER_LOG_PATH` | Where that log goes (default `~/Library/Logs/mothership-mcp-launcher.jsonl`). |

### Keychain items

Both are generic passwords whose account is the login user name.

| Item | Holds |
| --- | --- |
| `mothership-agent-api` | The agent API credentials, as JSON with `client_id`, `client_secret`, `username` and `password`: a password-grant client and the admin it signs in as, whose API key carries the scopes below. Written by the operator; the launcher only reads it. |
| `mothership-agent-api-token` | The current access token and its expiry. Written by the launcher. |

### API key scopes

Each route is checked against the scope of its REST twin, so an `all` key reaches every tool and a narrower key reaches
the tools its scopes name. A missing scope reads "This action is unauthorized" and names the tool.

| Tool | Scope |
| --- | --- |
| `library_search` | `api.dam.assets` |
| `library_get_asset` | `api.dam.assets.getById` |
| `library_list_stack` | `api.dam.assets.files.index` |
| `library_update` | `api.dam.assets.update` |
| `library_save` | `api.dam.assets.upload` |
| `library_fetch` | `api.dam.assets.download` |
| `library_list_folders` | `api.dam.directory` |
| `library_create_folder` | `api.dam.directory.create` |
| `library_vectorize` | `api.dam.vectorizations.create` |
| `library_vectorization` | `api.dam.vectorizations.show` |

The admin's own permissions and folder access apply on top, as listed under Tools.

### How HTTPS answers compare with stdio

The validation, the operations, the result JSON and the refusal sentences are the stdio server's own, so an agent reads
the same answer either way. The differences:

- Calls act as the API key's admin, not `MOTHERSHIP_MCP_USER_ID`.
- The launcher's settings, not the server's, decide the file size ceiling and the default fetch folder.
- `dest_dir` is on the agent's machine, so the server's rule that it lies outside The Mothership's `storage/` does not
  arise. It is checked after the server answers, as the stdio server checks access before the folder.
- An unknown tool name is answered by the launcher with the stdio server's words, without a network call.

### Server settings

These belong to the PHP server. The deployed stack caches its config when it starts, so a changed value needs a restart.

| Setting | Meaning |
| --- | --- |
| `MOTHERSHIP_MCP_USER_ID` | The admin account every stdio call acts as. Its own permissions and folder access apply, and assets it saves are created by it. Unset, 0 or an inactive account: every stdio tool refuses with a message that names this setting. HTTPS calls act as the API key's admin instead. |
| `MOTHERSHIP_MCP_MAX_FILE_BYTES` | The largest local file a stdio `library_save` publishes (default 2 GiB). |
| `MOTHERSHIP_MCP_FETCH_DIR` | Where a stdio `library_fetch` writes copies when a call names no `dest_dir` (default `<system temp>/mothership-library`). |
| `MOTHERSHIP_MCP_SEARCH_EXCLUDED_FOLDERS` | Folder ids (comma-separated) that `library_search` leaves out unless asked for by `folder_id`, such as QA output. |
| `MOTHERSHIP_MCP_LOGGING` | `true` writes redacted JSONL to `storage/logs/mothership-mcp.jsonl`: operation, outcome, reason code, size and counts only, never a path, name, title, tag, note or byte content. One `run_id` per server process. |

Whoever can start the stdio process on the server acts as that account, so name an account whose access fits the tools
that use it.

## Tools

Results are JSON (`structuredContent`, also as text). A refusal is an MCP tool error with a sentence the agent can act on.
A hidden asset or folder answers `Not found.` exactly like a missing one.

| Tool | Does | Needs |
| --- | --- | --- |
| `library_search` | Finds assets by file name, title or tag name (`query`, either case), and by `extension`, `kind` (`file_type`: image, video, audio, document, 3d ...), exact `tag`, `folder_id` and `producer`. Pages by `after_id`/`next_after_id`, `limit` up to 100. | `dam.asset`; the Library's listing rule |
| `library_list_folders` | Folders the account can open; `parent_id`, `name`, pages of 500. | folder access |
| `library_create_folder` | A subfolder under `parent_id`, as the Library's New folder makes it (the creator keeps access to it). An existing visible folder of that name (either case) under the same parent is returned with `created: false`. | `dam.directory.store`; access to the parent |
| `library_get_asset` | Title, file name, kind, size, sha256 and pixel size of the main file, folders, tags, stack size, `has_proxy`, and the producer, provenance and notes recorded by `library_save`. | `dam.asset`; the listing rule |
| `library_list_stack` | The files stacked in one asset: `file_id`, `label`, `extension`, `size`, `sha256`, `is_cover`, `producer`. Filters `extension` and `name`, pages of 50. | `dam.asset.download`; download access |
| `library_fetch` | Copies the main file, one stack file (`file_id`) or a video's 1080p web proxy (`proxy: true`) into `dest_dir`. Returns `path`, `sha256`, `size` and pixel size. | `dam.asset.download`; download access |
| `library_save` | Saves a local file (`local_path`) as a new asset in `folder_id`, or as a new member of `stack_asset_id`'s stack (cover unchanged). Records `producer`, `provenance`, `notes` and `evidence`; sets `title` (new asset only) and `tags` (on the new asset, or on the stack asset). | new asset: `dam.asset.upload` and access to the folder; stack: `dam.asset.files.store`, `dam.asset.edit` and edit access; tags: `dam.asset.tag` |
| `library_update` | Changes `title`, adds `tags_add`, removes `tags_remove` (recorded as a removal, so automatic tagging does not restore it). | title: `dam.asset.update`; tags: `dam.asset.tag`; edit access |
| `library_vectorize` | Converts a raster image to SVG and PDF through Image Vectorization: `asset_id` plus `format` files the chosen format onto that asset as an output file; `local_path` alone is a one-off filed nowhere. `request_id` (UUID, required) replays. Returns the queued conversion (`id`, `status`, `kind`, `charged`). Limits and allowance as in `docs/ops/image-vectorization.md`. | the Image Vectorization Home tool; filed: `dam.asset.edit`, `dam.asset.files` and edit access |
| `library_vectorization` | Reads a conversion the account started (`id`): status, `formats`, `output`. With `format` (svg or pdf) copies a completed result into `dest_dir` like `library_fetch`. | the Image Vectorization Home tool; the account's own conversion |

No tool deletes or overwrites anything in the library.

### Files in and out

- **Out (`library_fetch`).** The bytes stream from `ContentStore` into a hidden partial file in `dest_dir` and are checked
  against the stored sha256 before the copy gets its name. A mismatch writes nothing and is reported. The copy is the
  agent's own: changing it never changes the library. A file already at that name with the same bytes is reused
  (`reused: true`); any other file of that name is never overwritten, and the copy is named `name-2`, `name-3` and so on.
  The name is taken with an exclusive create before the copy moves in, so two fetches at once never replace each other.
  `dest_dir` must be absolute. On stdio it must also lie outside The Mothership's `storage/`, so no answer ever points
  into the blob store.
- **In (`library_save`).** `local_path` must be an absolute path to a regular, non-empty file within the size limit. It is
  copied to a private staging file first, so a tool still writing the original cannot change what is published. The
  bytes must be what the name says (`CreativeHubSaver::contentMatches`), and an MP4 or MOV must hold its `moov` index
  and end on a whole box (a render cut short is refused). `name` overrides the saved file name and must carry an
  extension. Publication goes through `CreativeHubSaver` (`saveNew` / `addToStack`), the same create-only path as the
  Library's Creative Hub: a name already in the folder or stack is saved under the next free one and reported as
  `renamed_from`.
- **Retries.** `request_id` (a UUID) is claimed in `dam_creative_hub_saves` per account. The same id with the same file
  and destination returns the first result (`replayed: true`); with other bytes or another destination it is refused.
  A retry changes nothing: title and tags are set only by the first save, so later edits stay, and a retry for an
  asset the account can no longer see answers `Not found.`

### The library record

`producer` (`tool`, optional `app` and `version`), `provenance` (`prompt`, `model`, `backend`, `generation_id`,
`reference_asset_ids`, `vault_note`, `source_url`), `notes` and `evidence` are stored as JSON in the request's
`dam_creative_hub_saves.evidence` column. `library_get_asset` returns them for the asset the save created, and
`library_search` filters on `producer`. A stack save also records a Creative Hub rendition naming the producer
(`creative-hub:<tool>[:<app>]`), which `library_list_stack` reports.

### Links to references

Each id in `provenance.reference_asset_ids` also becomes a `derived-from` relationship (ADR 0002) from the saved asset to
that reference; in stack mode the link starts from the stack's asset, as its tags do. The answer lists them as
`derived_from`, and the Relationships tab and `GET assets/{id}/relationships` show them on both ends.

- **Checked first.** Every id must be an asset the account's Library lists, and never the stack the file joins. A
  missing, hidden, trashed or merged id fails the whole save with the same error naming the id, before any bytes are
  stored. Naming references needs `dam.asset.relationships.store`, the permission the Relationships tab asks for.
- **Same transaction.** A new file's links are written in the transaction that records its library record, title and
  tags; a stack file's links in the transaction that attaches the member. A failure leaves none of them.
- **Never twice.** An existing `derived-from` link for the same pair is kept, not duplicated; a retry with the same
  `request_id` answers the links already there.
- Links are only ever added here. Removing one is a Relationships tab action. Saves made before this change carry their
  ids in the library record only; nothing back-fills links for them.

## Code

- `packages/Mothership/DAM/src/Mcp/MothershipServer.php`: the server and its instructions.
- `packages/Mothership/DAM/src/Mcp/LibraryTool.php`: each tool's input schema and validation rules, from one table, and
  `perform`, the rules both the stdio server and the HTTPS routes run.
- `packages/Mothership/DAM/src/Mcp/LibraryOperations.php`: the operations; `transfer` hands a copy-out to the HTTPS
  route as a `LibraryTransfer`.
- `packages/Mothership/DAM/src/Http/Controllers/API/Library/LibraryToolController.php` and
  `packages/Mothership/DAM/src/Routes/V1/library-tool-routes.php`: the HTTPS routes; their scopes are in
  `packages/Mothership/DAM/src/Config/api-acl.php`.
- `packages/Mothership/DAM/src/Providers/MothershipMcpServiceProvider.php`: `Mcp::local('mothership', ...)`.
- `config/mothership-mcp.php`; tests in `tests/Feature/MothershipMcpLibraryTest.php` and
  `packages/Mothership/DAM/tests/Feature/Api/LibraryToolApiTest.php`.
- `tools/mcp-launcher/`: the launcher (`launcher.py`, `bridge.py`), its manifest and its tests, which run it over real
  stdio against a local HTTP stand-in for the site (`tests/fake_mothership.py`).

The OAuth classes in `packages/Mothership/AdminApi` (`Mcp*`, [mcp-oauth.md](mcp-oauth.md)) stay registered but inactive:
`mcp-oauth.enabled` is `false` in `config/mcp-oauth.php`, so that OAuth surface is switched off. A remote mode for this
server (`/mcp` behind that OAuth) is a later phase of the plan.
