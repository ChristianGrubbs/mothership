# The `mothership` library server (MCP, stdio)

The Mothership is the asset library and system of record for finished creative work. Local creative tools (Blender, the
image and video generators, ArtCraft through its own relay, plain scripts) reach it through one MCP server, `mothership`,
with eight `library_*` tools. The server runs no engines and keeps no sessions: tools make files on this machine, and the
library keeps them.

It replaced the hosted Craft MCP (`/mcp/craft`, the `craft_*` tools, upload links and the Craft worker) in November 2026.

## Running it

The server is `php artisan mcp:start mothership`. It speaks MCP over stdin and stdout, has no web route and no sign-in,
and every call acts as one admin account, named in `.env`. In the deployed stack it lives inside the FPM container, where
the database and the asset storage are, and agents run on the Mac. Skill Scope therefore registers the Mac launcher as the
stdio server, never the PHP command:

```bash
/opt/homebrew/bin/python3 -I -S <deployed checkout>/tools/mcp-launcher/launcher.py
```

Do not register `container exec ... php artisan mcp:start mothership` directly. A global MCP server runs one copy per open
agent session. At about 100 MB of PHP per copy and more than 50 sessions, that is more memory than the 4 GB FPM container
has, and the site goes down with it.

### The Mac launcher

`tools/mcp-launcher/launcher.py` uses only the Python standard library.

- **No PHP until it is needed.** `initialize` and `tools/list` are answered from `tools_manifest.json`. The first
  `tools/call` starts PHP in the container. PHP stops after 15 idle seconds, and the launcher then restarts itself in place
  to return to its idle size.
- **Idle cost.** One idle launcher holds 14.2 MB resident, 7.0 MB of it physical footprint (Python 3.15 on Apple Silicon).
  It reaches about 24 MB while working.
- **A cap across the Mac.** At most two PHP servers run at once, across every agent session. Other calls wait their turn
  for up to two minutes, then get a "busy" refusal they can retry. A session holding a server gives it up after 60 seconds
  of steady use, so a queued session is not starved.
- **Worst-case memory in the container.** One server measures about 100 MB, so two typically use about 0.2 GB. The hard
  ceiling is 1 GB: two servers at PHP's 512 MB `memory_limit`.
- **Files cross the container boundary as checked copies.** `library_save` copies the Mac file into a private staging folder
  in the container and `library_fetch` copies the result back, with sha256 verified after each copy. A failed copy leaves no
  partial file. The rules below for `local_path` and `dest_dir` are enforced on the Mac as well, including `name-2` naming
  and refusing a `dest_dir` inside The Mothership's `storage/`.
- **The tool list cannot drift unnoticed.** `tests/Feature/MothershipMcpLibraryTest.php` compares the manifest with the
  server's code. After a change to the tools, regenerate it from the running server with `launcher.py --write-manifest`;
  `launcher.py --check-manifest` exits 1 when the two differ.

| Launcher setting | Meaning |
| --- | --- |
| `MOTHERSHIP_MCP_MAX_SERVERS` | PHP servers allowed at once across the Mac (default 2). |
| `MOTHERSHIP_MCP_IDLE_SECONDS` | Idle time before PHP stops (default 15). |
| `MOTHERSHIP_MCP_HOLD_SECONDS` | How long a busy session keeps one server before giving its slot up (default 60). |
| `MOTHERSHIP_MCP_QUEUE_SECONDS` | How long a call waits for a free slot (default 120). |
| `MOTHERSHIP_MCP_START_SECONDS` | How long PHP may take to answer when it starts (default 30). |
| `MOTHERSHIP_MCP_CALL_SECONDS` | How long one tool call may run (default 1800). |
| `MOTHERSHIP_MCP_MAX_FILE_BYTES` | The largest file `library_save` copies in (default 2 GiB). The server applies its own limit too. |
| `MOTHERSHIP_MCP_FETCH_DIR` | The Mac folder `library_fetch` uses when a call names no `dest_dir` (default `$TMPDIR/mothership-library`). |
| `MOTHERSHIP_MCP_LAUNCHER_LOG` | `1` or `true` writes redacted JSONL: operation, outcome, size and timing, never a path, name, title or byte content. |
| `MOTHERSHIP_MCP_LAUNCHER_LOG_PATH` | Where that log goes (default `~/Library/Logs/mothership-mcp-launcher.jsonl`). |
| `MOTHERSHIP_MCP_CONTAINER_BIN`, `MOTHERSHIP_MCP_CONTAINER`, `MOTHERSHIP_MCP_CONTAINER_USER`, `MOTHERSHIP_MCP_CONTAINER_WORKDIR` | How the container is reached (defaults `/opt/homebrew/bin/container`, `mothership-private-fpm`, `www-data`, `/var/www/html`). |
| `MOTHERSHIP_MCP_CONTAINER_STAGING` | The staging folder inside the container (default `/tmp/mothership-mcp`). |
| `MOTHERSHIP_MCP_STATE_DIR` | Where the Mac keeps the cap's lock files (default `~/Library/Caches/mothership-mcp`). |

### Server settings

These belong to the PHP server. The deployed stack caches its config when the container starts, so a changed value needs a
restart.

| Setting | Meaning |
| --- | --- |
| `MOTHERSHIP_MCP_USER_ID` | The admin account every call acts as. Its own permissions and folder access apply, and assets it saves are created by it. Unset, 0 or an inactive account: every tool refuses with a message that names this setting. |
| `MOTHERSHIP_MCP_MAX_FILE_BYTES` | The largest local file `library_save` publishes (default 2 GiB). |
| `MOTHERSHIP_MCP_FETCH_DIR` | Where `library_fetch` writes copies when a call names no `dest_dir` (default `<system temp>/mothership-library`). |
| `MOTHERSHIP_MCP_SEARCH_EXCLUDED_FOLDERS` | Folder ids (comma-separated) that `library_search` leaves out unless asked for by `folder_id`, such as QA output. |
| `MOTHERSHIP_MCP_LOGGING` | `true` writes redacted JSONL to `storage/logs/mothership-mcp.jsonl`: operation, outcome, reason code, size and counts only, never a path, name, title, tag, note or byte content. One `run_id` per server process. |

Whoever can start the process on this machine acts as that account, so name an account whose access fits the tools that
use it.

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
  `dest_dir` must be absolute and outside The Mothership's `storage/`, so no answer ever points into the blob store, which
  may live off this machine.
- **In (`library_save`).** `local_path` must be an absolute path to a regular, non-empty file within the size limit. It is
  copied to a private staging file first, so a tool still writing the original cannot change what is published. The
  bytes must be what the name says (`CreativeHubSaver::contentMatches`), and an MP4 or MOV must hold its `moov` index
  and end on a whole box (a render cut short is refused). `name` overrides the saved file name and must carry an extension. Publication goes
  through `CreativeHubSaver` (`saveNew` / `addToStack`), the same create-only path as the Library's Creative Hub: a name
  already in the folder or stack is saved under the next free one and reported as `renamed_from`.
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

## Code

- `packages/Mothership/DAM/src/Mcp/MothershipServer.php`: the server and its instructions.
- `packages/Mothership/DAM/src/Mcp/LibraryTool.php`: each tool's input schema and validation rules, from one table.
- `packages/Mothership/DAM/src/Mcp/LibraryOperations.php`: the operations.
- `packages/Mothership/DAM/src/Providers/MothershipMcpServiceProvider.php`: `Mcp::local('mothership', ...)`.
- `config/mothership-mcp.php`; tests in `tests/Feature/MothershipMcpLibraryTest.php`.
- `tools/mcp-launcher/`: the Mac launcher (`launcher.py`, `bridge.py`), its manifest and its tests.

The OAuth classes in `packages/Mothership/AdminApi` (`Mcp*`, [mcp-oauth.md](mcp-oauth.md)) stay registered but inactive:
`mcp-oauth.enabled` is `false` in `config/mcp-oauth.php`, so that OAuth surface is switched off. A remote mode for this
server (`/mcp` behind that OAuth) is a later phase of the plan.
